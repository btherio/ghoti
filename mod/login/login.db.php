<?php
/*
 * Created on Apr 3, 2009
 */

class logindb extends ghotidb{
	/**
	 * Storage for method results used across the class.
	 * Declared to avoid dynamic property issues on newer PHP versions.
	 */
	protected $result = array();
	protected $passwordAlgo;
	private static $passwordResetsSchemaReady = false;

	public function __construct(){
		parent::__construct();
		parent::loadModuleSql("login");
		// password_resets was added to login.sql after `login` may already be
		// marked provisioned on an existing install (loadModuleSql() then
		// skips re-running login.sql entirely) - so it needs its own
		// idempotent bootstrap, same pattern as ghotidb::ensurePageSchema().
		$this->ensurePasswordResetsSchema();
		// NOTE: do NOT probe the password algorithm here. This constructor runs
		// on every request (index.php always does `new login()`), and probing
		// used to call password_hash() with Argon2id - a deliberately slow KDF
		// (tens to hundreds of ms) - on every single request, including plain
		// page views that never touch a password. The algorithm is now resolved
		// lazily, only when we actually hash/verify (see algo()).
	}

	public function __destruct(){
		parent::__destruct();
	}

	//Creates the password_resets table if it is missing. CREATE TABLE IF NOT
	//EXISTS is itself idempotent and cheap (MySQL/MariaDB just does a catalog
	//lookup), so this runs once per request rather than needing its own
	//provisioned-marker cache. Kept inline (not parsed from login.sql) so it
	//can't silently pick up an unrelated edit to that file - same approach as
	//ghotidb::ensurePageSchema()'s inline ALTER TABLE statements.
	private function ensurePasswordResetsSchema(){
		if(self::$passwordResetsSchemaReady){ return true; }
		try{
			$this->db()->exec("CREATE TABLE IF NOT EXISTS `password_resets` (
				`resetId` int(11) NOT NULL auto_increment,
				`userId` int(11) NOT NULL,
				`tokenHash` char(64) NOT NULL,
				`createdAt` int(11) NOT NULL default 0,
				`expiresAt` int(11) NOT NULL default 0,
				`usedAt` int(11) default NULL,
				`requestIp` varchar(45) NOT NULL default '',
				PRIMARY KEY  (`resetId`),
				UNIQUE KEY `uq_password_resets_token` (`tokenHash`),
				KEY `idx_password_resets_user` (`userId`),
				KEY `idx_password_resets_expires` (`expiresAt`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
			self::$passwordResetsSchemaReady = true;
			return true;
		}catch(Throwable $e){
			ghoti::logException("login.db.php:ensurePasswordResetsSchema", $e);
			return false;
		}
	}

	//Resolve (and memoize for this request) the password hashing algorithm.
	protected function algo(){
		if ($this->passwordAlgo === null) {
			$this->passwordAlgo = $this->getPasswordHashAlgo();
		}
		return $this->passwordAlgo;
	}

	protected function getPasswordHashAlgo(){
		if (defined('PASSWORD_ARGON2ID') && PASSWORD_ARGON2ID !== 0) {
			$testHash = @password_hash('ghoti-test-password', PASSWORD_ARGON2ID);
			if ($testHash !== false) {
				return PASSWORD_ARGON2ID;
			}
		}
		return PASSWORD_DEFAULT;
	}

	protected function hashPassword($password){
		try {
			$hash = password_hash((string) $password, $this->algo());
			if ($hash !== false) {
				return $hash;
			}
		} catch (Throwable $e) {
			ghoti::logException("login.db.php:hashPassword", $e, "falling back to PASSWORD_DEFAULT");
		}
		return password_hash((string) $password, PASSWORD_DEFAULT);
	}

	protected function normalizeValue($value){
		return trim((string) $value);
	}

	protected function validatePassword($password){
		if (strlen((string) $password) < 8) {
			throw new Exception("Password must be at least 8 characters.");
		}
	}

	public function addUser($userName,$password,$email){
		$userName = $this->normalizeValue($userName);
		$email = $this->normalizeValue($email);
		$password = (string) $password;

		ghoti::logDebug("login.db.php:addUser", "start for '$userName'");

		if ($userName === '' || $email === '' || $password === '') {
			ghoti::logWarn("login.db.php:addUser", "rejected for '$userName': empty required field");
			return false;
		}

		if(!$this->checkDuplicate($userName,$email)){
			try{
				$this->validatePassword($password);
				ghoti::logDebug("login.db.php:addUser", "password validated for '$userName'");
				$hashedPassword = $this->hashPassword($password);
				ghoti::logDebug("login.db.php:addUser", "hashing succeeded for '$userName'");
				$this->query("insert into users(userName,password,email,admin) values(?,?,?,?)",array($userName,$hashedPassword,$email,0));
				ghoti::logInfo("login.db.php:addUser", "insert succeeded for '$userName'");

				$query = $this->query("select count(userId) from users;");
				ghoti::logDebug("login.db.php:addUser", "count query returned ".var_export($query->fields, true));

				if((int) $query->fields[0] === 1){
					$this->query("update users set admin = ? where admin = 0",array(1));
					ghoti::logInfo("login.db.php:addUser", "promoted first user to admin");
				}
			}catch (Throwable $e){
				ghoti::logException("login.db.php:addUser", $e, "userName='$userName'");
				return false;
			}

			return true;
		}
		ghoti::logWarn("login.db.php:addUser", "rejected for '$userName': duplicate detected");
		return false;
	}

	public function checkDuplicate($userName,$email){
		$userName = $this->normalizeValue($userName);
		$email = $this->normalizeValue($email);
		try{
			$records = $this->query("select userId from users where userName = ? or email = ?",array($userName,$email));
		}catch (Throwable $e){
			ghoti::logException("login.db.php:checkDuplicate", $e);
			return false;
		}
		$found = false;
		foreach ($records as $row){
			$found = true;
			break;
		}
		return $found;
	}

	/* True when a DIFFERENT account already uses $userName or $email. Used by
	 * the admin user-edit path so an edit can't collide with another account. */
	public function checkDuplicateExcluding($userName,$email,$excludeId){
		$userName = $this->normalizeValue($userName);
		$email = $this->normalizeValue($email);
		try{
			$records = $this->queryArray(
				"select userId from users where (userName = ? or email = ?) and userId <> ?",
				array($userName,$email,(int)$excludeId)
			);
		}catch (Throwable $e){
			ghoti::logException("login.db.php:checkDuplicateExcluding", $e);
			return false;
		}
		return !empty($records);
	}

	public function isAdmin($id){
		$this->result = array();
		$this->result[0] = "";
		try{
			$records = $this->query("select admin from users where userId = ?",array((int) $id));
		}catch (Throwable $e){
			ghoti::logException("login.db.php:isAdmin", $e);
			return false;
		}
		foreach ($records as $row){
			$this->result[0] .= $row[0];
		}
		if($this->result[0] === '1' || $this->result[0] === 1){
			ghoti::logDebug("login.db.php:isAdmin", "result=true");
			return true;
		}
		ghoti::logDebug("login.db.php:isAdmin", "result=false");
		return false;
	}

	public function authenticate($userName,$password){
		$userName = $this->normalizeValue($userName);
		$password = (string) $password;
		ghoti::logDebug("login.db.php:authenticate", "start for '$userName'");
		try{
			$auth = $this->query("select userId,password from users where userName = ? limit 1",array($userName));
		}catch (Throwable $e){
			ghoti::logException("login.db.php:authenticate", $e, "userName='$userName'");
			return false;
		}
		foreach ($auth as $row){
			$storedHash = (string) $row[1];
			ghoti::logDebug("login.db.php:authenticate", "found user '$userName' with stored hash length ".strlen($storedHash));
			if (is_string($storedHash) && $storedHash !== '' && password_verify($password, $storedHash)) {
				ghoti::logInfo("login.db.php:authenticate", "verified password hash for '$userName'");
				if (password_needs_rehash($storedHash, $this->algo())) {
					$this->query("update users set password = ? where userId = ?", array($this->hashPassword($password), $row[0]));
				}
				return (string) $row[0];
			}
			//A stored value that is not a hash (legacy plaintext) must NOT
			//authenticate: accepting it makes any leaked DB dump instantly
			//usable. Flag it so the operator knows to reset that account.
			//Recovery for such an account (the only way back in - note that
			//changePassword() verifies the current password via authenticate(),
			//so a plaintext account cannot change its own password):
			//   UPDATE users SET password = '<hash>' WHERE userId = <id>;
			//generate <hash> with PHP: password_hash('newpass', PASSWORD_ARGON2ID).
			if (is_string($storedHash) && $storedHash !== '' && $storedHash === $password) {
				ghoti::logError("login.db.php:authenticate", "SECURITY: '$userName' has a legacy plaintext password - refusing plaintext login, reset the password");
			}
		}
		ghoti::logWarn("login.db.php:authenticate", "failed for '$userName': no matching password hash");
		return false;
	}

	public function getUserList(){
		try{
			$userList = $this->query("select userId,userName,email,admin from users");
		}catch (Throwable $e){
			ghoti::logException("login.db.php:getUserList", $e);
			return false;
		}
		return $userList;
	}

	public function updateUser($userId,$userName,$email){
		try{
			$this->query("update users set userName = ?, email = ? where userId = ?",array($userName,$email,$userId));
		}catch (Throwable $e){
			ghoti::logException("login.db.php:updateUser", $e);
			return false;
		}
		return true;
	}

	public function countAdmins(){
		try{
			$numberOfAdmins = $this->query("select count(userId) from users where admin = 1;");
		}catch (Throwable $e){
			ghoti::logException("login.db.php:countAdmins", $e);
			return 0;
		}
		return isset($numberOfAdmins->fields[0]) ? (int) $numberOfAdmins->fields[0] : 0;
	}

	public function deleteUser($userId){
		try{
			if($this->countAdmins() <= 1 && $this->isAdmin($userId)){
				Throw new Exception("Can't delete only admin.");
			}else{
				$this->query("delete from users where userId = ?;",array($userId));
				$this->query("delete from comments where userId = ?;",array($userId));
			}
		}catch (Throwable $e){
			ghoti::logException("login.db.php:deleteUser", $e);
			return $e->getMessage();
		}
		return true;
	}

	public function toggleAdmin($userId,$actorUserId = 0){
		$userId = (int) $userId;
		$actorUserId = (int) $actorUserId;
		try{
			if($this->isAdmin($userId)){
				$numberOfAdmins = $this->countAdmins();
				if($numberOfAdmins <= 1){
					if($actorUserId === $userId){
						Throw new Exception("You can't remove admin rights from the only admin account.");
					}
					Throw new Exception("Can't revoke admin rights from only admin.");
				}else{
					$this->query("update users set admin = 0 where userId = ?;",array($userId));
				}
			}else{
				$this->query("update users set admin = 1 where userId = ?;",array($userId));
			}
		}catch (Throwable $e){
			ghoti::logException("login.db.php:toggleAdmin", $e);
			return $e->getMessage();
		}
		return true;
	}

	public function changePassword($userId,$password){
		try{
			$hashedPassword = $this->hashPassword($password);
			$this->query("update users set password = ? where userId = ?",array($hashedPassword,$userId));
		}catch (Throwable $e){
			ghoti::logException("login.db.php:changePassword", $e);
			return false;
		}
		return true;
	}

	public function getUserNameById($userId){
		try{
			$query = $this->query("select userName from users where userId = ?",array($userId));
		}catch (Throwable $e){
			ghoti::logException("login.db.php:getUserNameById", $e);
			return false;
		}
		return $query->fields[0];
	}

	/* ---------------------------------------------------------------- *
	 *  Mail-based password recovery (password_resets table)
	 *
	 *  Design notes:
	 *   - Only a SHA-256 hash of the token is ever stored; the raw token
	 *     exists only in the emailed link and the requester's browser, the
	 *     same principle used for session ids.
	 *   - Tokens are single-use (usedAt) and short-lived (expiresAt).
	 *   - Rate limiting lives here (per user AND per IP) rather than in
	 *     password-reset.php, so it applies uniformly regardless of caller.
	 *   - Every public-facing method here returns a normalized result and
	 *     never reveals whether a given email address has an account -
	 *     that information leak is exactly what the old password-reset.php
	 *     (email + new password, no proof of mailbox ownership) was replaced
	 *     to close.
	 * ---------------------------------------------------------------- */

	const RESET_TOKEN_TTL_SECONDS = 1800;      // 30 minutes
	const RESET_MAX_PER_HOUR_PER_USER = 5;
	const RESET_MAX_PER_HOUR_PER_IP = 10;

	//Returns the userId for a (trimmed, case-insensitive) email address, or
	//null if none/more-than-one account uses it. A duplicate is treated the
	//same as "not found" - it never happens in normal operation (email is
	//meant to be unique) but if it ever does, guessing which one to reset
	//would be worse than refusing.
	public function findUserIdByEmail($email){
		$email = trim((string)$email);
		if($email === ''){ return null; }
		try{
			$rows = $this->queryArray("select userId from users where LOWER(TRIM(email)) = LOWER(TRIM(?))", array($email));
		}catch(Throwable $e){
			ghoti::logException("login.db.php:findUserIdByEmail", $e);
			return null;
		}
		return (count($rows) === 1) ? (int)$rows[0][0] : null;
	}

	public function getUserEmailById($userId){
		try{
			$rows = $this->queryArray("select email from users where userId = ?", array((int)$userId));
		}catch(Throwable $e){
			ghoti::logException("login.db.php:getUserEmailById", $e);
			return null;
		}
		return isset($rows[0][0]) ? (string)$rows[0][0] : null;
	}

	//True if $userId or $ipAddress has requested "too many" resets in the
	//past hour. Fails OPEN (returns false) on a DB error so a transient
	//error never permanently locks a legitimate user out - the per-attempt
	//uniqueness/expiry of tokens is the real security boundary; this is just
	//abuse mitigation.
	private function passwordResetIsRateLimited($userId, $ipAddress){
		try{
			$byUser = $this->queryArray(
				"select count(*) from password_resets where userId = ? and createdAt >= ?",
				array((int)$userId, time() - 3600)
			);
			if((int)($byUser[0][0] ?? 0) >= self::RESET_MAX_PER_HOUR_PER_USER){ return true; }

			$byIp = $this->queryArray(
				"select count(*) from password_resets where requestIp = ? and createdAt >= ?",
				array((string)$ipAddress, time() - 3600)
			);
			if((int)($byIp[0][0] ?? 0) >= self::RESET_MAX_PER_HOUR_PER_IP){ return true; }
		}catch(Throwable $e){
			ghoti::logException("login.db.php:passwordResetIsRateLimited", $e);
			return false;
		}
		return false;
	}

	//Issues a fresh reset token for $userId, unless rate-limited. Returns the
	//RAW token string (to be placed in the emailed link and never stored),
	//or null if the request should be silently dropped (rate limit hit -
	//caller still reports success to the visitor either way).
	public function createPasswordResetToken($userId, $ipAddress){
		$userId = (int)$userId;
		if($userId <= 0){ return null; }
		if($this->passwordResetIsRateLimited($userId, $ipAddress)){
			ghoti::logWarn("login.db.php:createPasswordResetToken", "rate-limited for userId $userId from $ipAddress");
			return null;
		}
		try{
			$token = bin2hex(random_bytes(32)); // 256 bits, hex so it's URL-safe as-is
			$tokenHash = hash('sha256', $token);
			$now = time();
			$this->query(
				"insert into password_resets (userId,tokenHash,createdAt,expiresAt,requestIp) values (?,?,?,?,?)",
				array($userId, $tokenHash, $now, $now + self::RESET_TOKEN_TTL_SECONDS, (string)$ipAddress)
			);
			$this->pruneOldPasswordResets();
			return $token;
		}catch(Throwable $e){
			ghoti::logException("login.db.php:createPasswordResetToken", $e);
			return null;
		}
	}

	//Validates a raw token from the emailed link. Returns the associated
	//userId on success, or null if the token is missing/expired/already used.
	//Does NOT consume the token - call consumePasswordResetToken() once the
	//new password has actually been set, so a token stays usable if the
	//user's first submission fails validation (e.g. password too short).
	public function validatePasswordResetToken($token){
		$token = (string)$token;
		if($token === '' || !preg_match('/^[0-9a-f]{64}$/', $token)){ return null; }
		$tokenHash = hash('sha256', $token);
		try{
			$rows = $this->queryArray(
				"select userId,expiresAt,usedAt from password_resets where tokenHash = ? limit 1",
				array($tokenHash)
			);
		}catch(Throwable $e){
			ghoti::logException("login.db.php:validatePasswordResetToken", $e);
			return null;
		}
		if(empty($rows)){ return null; }
		$row = $rows[0];
		if($row[2] !== null){ return null; }              // already used
		if((int)$row[1] < time()){ return null; }          // expired
		return (int)$row[0];
	}

	//Sets a new password for the account tied to $token and marks the token
	//used (single-use). Returns true, or an error message string.
	public function resetPasswordWithToken($token, $newPassword){
		$userId = $this->validatePasswordResetToken($token);
		if($userId === null){
			return "This password reset link is invalid or has expired. Request a new one.";
		}
		try{
			$tokenHash = hash('sha256', (string)$token);
			$hashedPassword = $this->hashPassword($newPassword);
			$this->query("update users set password = ? where userId = ?", array($hashedPassword, $userId));
			$this->query("update password_resets set usedAt = ? where tokenHash = ?", array(time(), $tokenHash));
			// Invalidate any other outstanding tokens for this account - a
			// successful reset should retire every link that was emailed.
			$this->query("update password_resets set usedAt = ? where userId = ? and usedAt is null", array(time(), $userId));
			ghoti::logInfo("login.db.php:resetPasswordWithToken", "password reset via token for userId $userId");
			return true;
		}catch(Throwable $e){
			ghoti::logException("login.db.php:resetPasswordWithToken", $e);
			return "The password could not be reset. Try again.";
		}
	}

	//Best-effort cleanup of rows older than 30 days (used tokens and expired-
	//but-never-consumed ones alike). Runs probabilistically like the app's
	//existing throttling table, so it stays cheap on the common request path.
	private function pruneOldPasswordResets(){
		if(random_int(1,50) !== 1){ return; }
		try{
			$this->db()->exec("delete from password_resets where createdAt < ".(time() - 30*86400));
		}catch(Throwable $e){
			ghoti::logException("login.db.php:pruneOldPasswordResets", $e);
		}
	}
}
?>
