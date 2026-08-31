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

	public function __construct(){
		parent::__construct();
		parent::loadModuleSql("login");
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
			ghoti::log("login.db.php password_hash fallback: ".$e->getMessage());
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

		ghoti::log("login.db.php addUser start for '$userName'");

		if ($userName === '' || $email === '' || $password === '') {
			ghoti::log("login.db.php addUser rejected for '$userName': empty required field");
			return false;
		}

		if(!$this->checkDuplicate($userName,$email)){
			try{
				$this->validatePassword($password);
				ghoti::log("login.db.php addUser password validated for '$userName'");
				$hashedPassword = $this->hashPassword($password);
				ghoti::log("login.db.php addUser hashing succeeded for '$userName'");
				$this->query("insert into users(userName,password,email,admin) values(?,?,?,?)",array($userName,$hashedPassword,$email,0));
				ghoti::log("login.db.php addUser insert succeeded for '$userName'");

				$query = $this->query("select count(userId) from users;");
				ghoti::log("login.db.php addUser count query returned ".var_export($query->fields, true));

				if((int) $query->fields[0] === 1){
					$this->query("update users set admin = ? where admin = 0",array(1));
					ghoti::log("login.db.php addUser promoted first user to admin");
				}
			}catch (Throwable $e){
				ghoti::log("login.db.php addUser failed for '$userName': ".$e->getMessage());
				return false;
			}

			return true;
		}
		ghoti::log("login.db.php addUser rejected for '$userName': duplicate detected");
		return false;
	}

	public function checkDuplicate($userName,$email){
		$userName = $this->normalizeValue($userName);
		$email = $this->normalizeValue($email);
		try{
			$records = $this->query("select userId from users where userName = ? or email = ?",array($userName,$email));
		}catch (Throwable $e){
			ghoti::log("login.db.php ".$e->getMessage());
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
			ghoti::log("login.db.php ".$e->getMessage());
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
			ghoti::log("login.db.php ".$e->getMessage());
			return false;
		}
		foreach ($records as $row){
			$this->result[0] .= $row[0];
		}
		if($this->result[0] === '1' || $this->result[0] === 1){
			ghoti::debug("isAdmin == true");
			return true;
		}
		ghoti::debug("isAdmin == false");
		return false;
	}

	public function authenticate($userName,$password){
		$userName = $this->normalizeValue($userName);
		$password = (string) $password;
		ghoti::log("login.db.php authenticate start for '$userName'");
		try{
			$auth = $this->query("select userId,password from users where userName = ? limit 1",array($userName));
		}catch (Throwable $e){
			ghoti::log("login.db.php authenticate query failed for '$userName': ".$e->getMessage());
			return false;
		}
		foreach ($auth as $row){
			$storedHash = (string) $row[1];
			ghoti::log("login.db.php authenticate found user '$userName' with stored hash length ".strlen($storedHash));
			if (is_string($storedHash) && $storedHash !== '' && password_verify($password, $storedHash)) {
				ghoti::log("login.db.php authenticate verified password hash for '$userName'");
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
				ghoti::log("login.db.php SECURITY: '$userName' has a legacy plaintext password - refusing plaintext login, reset the password");
			}
		}
		ghoti::log("login.db.php authenticate failed for '$userName': no matching password hash");
		return false;
	}

	public function getUserList(){
		try{
			$userList = $this->query("select userId,userName,email,admin from users");
		}catch (Throwable $e){
			ghoti::log("login.db.php ".$e->getMessage());
			return false;
		}
		return $userList;
	}

	public function updateUser($userId,$userName,$email){
		try{
			$this->query("update users set userName = ?, email = ? where userId = ?",array($userName,$email,$userId));
		}catch (Throwable $e){
			ghoti::log("login.db.php ".$e->getMessage());
			return false;
		}
		return true;
	}

	public function countAdmins(){
		try{
			$numberOfAdmins = $this->query("select count(userId) from users where admin = 1;");
		}catch (Throwable $e){
			ghoti::log("login.db.php ".$e->getMessage());
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
			ghoti::log("login.db.php ".$e->getMessage());
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
			ghoti::log("login.db.php ".$e->getMessage());
			return $e->getMessage();
		}
		return true;
	}

	public function changePassword($userId,$password){
		try{
			$hashedPassword = $this->hashPassword($password);
			$this->query("update users set password = ? where userId = ?",array($hashedPassword,$userId));
		}catch (Throwable $e){
			ghoti::log("login.db.php ".$e->getMessage());
			return false;
		}
		return true;
	}

	public function getUserNameById($userId){
		try{
			$query = $this->query("select userName from users where userId = ?",array($userId));
		}catch (Throwable $e){
			ghoti::log("login.db.php ".$e->getMessage());
			return false;
		}
		return $query->fields[0];
	}
}
?>
