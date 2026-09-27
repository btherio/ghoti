<?php
/*
 * login.async.php - login module async layer.
 *
 * Combines the former login.ajax.php (browser-callable endpoints) and
 * login.ui.php (class loginui) into one file, registered through the ghoti
 * async wrapper (ghoti_async_register) instead of the old sajax_export().
 */

/* ---------------------------------------------------------------- *
 *  Endpoints (formerly login.ajax.php)
 * ---------------------------------------------------------------- */

function checkGetLogin(){
	return false;
}

function loginRemoteAddr(){
	return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
}

function loginSecureRandomInt($min,$max){
	if(function_exists('random_int')){
		return random_int($min,$max);
	}
	if(function_exists('openssl_random_pseudo_bytes')){
		$range = $max - $min + 1;
		$maxRandom = 0xFFFFFFFF;
		$limit = $maxRandom - ($maxRandom % $range);
		do{
			$strong = false;
			$bytes = openssl_random_pseudo_bytes(4,$strong);
			if($bytes === false || !$strong){
				throw new Exception("Secure random source unavailable.");
			}
			$unpacked = unpack('Nvalue',$bytes);
			$value = $unpacked['value'];
		}while($value >= $limit);
		return $min + ($value % $range);
	}
	throw new Exception("Secure random source unavailable.");
}

function loginHashEquals($known,$user){
	if(function_exists('hash_equals')){
		return hash_equals((string)$known,(string)$user);
	}
	$known = (string)$known;
	$user = (string)$user;
	if(strlen($known) !== strlen($user)){ return false; }
	$result = 0;
	for($i = 0; $i < strlen($known); $i++){
		$result |= ord($known[$i]) ^ ord($user[$i]);
	}
	return $result === 0;
}

/* ---------------------------------------------------------------- *
 *  Server-side login throttling (per-IP + per-username)
 *
 *  The old session-only counter could be bypassed by simply rotating the
 *  session cookie. This store persists failure timestamps in a small JSON
 *  file (login.throttle.json, gitignored + web-denied) keyed by IP and by
 *  username, so a brute-force attempt is throttled even across sessions.
 *  Unavailable or corrupt storage fails closed instead of silently disabling
 *  the cross-session throttle.
 * ---------------------------------------------------------------- */
class login_throttle{
	private $file;
	public function __construct($file){ $this->file = $file; }

	// Lock the same inode for the whole read/modify/write operation. Atomic
	// rename alone loses updates when concurrent requests read stale data.
	private function access($callback, $write = false){
		$handle = @fopen($this->file, 'c+');
		if($handle === false){ throw new RuntimeException('Login protection is unavailable. Please try again later.'); }
		try{
			if(!flock($handle, LOCK_EX)){ throw new RuntimeException('Login protection is unavailable. Please try again later.'); }
			$raw = stream_get_contents($handle);
			$data = $raw === '' ? array() : json_decode($raw, true);
			if(!is_array($data)){ throw new RuntimeException('Login protection is unavailable. Please contact the site operator.'); }
			$result = $callback($data);
			if($write){
				$cutoff = time() - 86400;
				foreach($data as $key => $times){
					$data[$key] = array_values(array_filter((array)$times, function($ts) use ($cutoff){ return (int)$ts >= $cutoff; }));
					if(!$data[$key]){ unset($data[$key]); }
				}
				if(count($data) > 5000){
					uasort($data, function($a,$b){ return max($b) <=> max($a); });
					$data = array_slice($data, 0, 5000, true);
				}
				$json = json_encode($data, JSON_THROW_ON_ERROR);
				rewind($handle);
				if(fwrite($handle, $json) !== strlen($json) || !ftruncate($handle, strlen($json)) || !fflush($handle)){
					throw new RuntimeException('Login protection is unavailable. Please try again later.');
				}
			}
			return $result;
		}finally{ fclose($handle); }
	}

	public function isBlocked($key, $limit = 5, $window = 600){
		return $this->access(function($data) use ($key,$limit,$window){
			$recent = array_filter((array)($data[$key] ?? array()), function($ts) use ($window){ return (int)$ts >= time() - $window; });
			return count($recent) >= $limit ? max(0, min($recent) + $window - time()) : 0;
		});
	}
	public function recordFailure($key, $max = 200){
		$this->access(function(&$data) use ($key,$max){
			$data[$key][] = time();
			$data[$key] = array_slice($data[$key], -$max);
		}, true);
	}
	public function clear($key){
		$this->access(function(&$data) use ($key){ unset($data[$key]); }, true);
	}
	public function countWindow($key, $window){
		return $this->access(function($data) use ($key,$window){
			return count(array_filter((array)($data[$key] ?? array()), function($ts) use ($window){ return (int)$ts >= time() - $window; }));
		});
	}
}

/*
 * The shared throttle. $override exists so a test can point the login and
 * two-factor flows at a scratch file: the default path is the live
 * login.throttle.json beside index.php, and a test run must not record
 * failures - or trip the automatic blacklist - in the running site's state.
 * Production never passes it.
 */
function login_throttle_store($override = null){
	static $instance = null;
	if($override !== null){ $instance = $override; }
	if($instance === null){
		$instance = new login_throttle(dirname(__DIR__, 2).'/login.throttle.json');
	}
	return $instance;
}

/*
 * Throttle keys for one login attempt: always the client IP, plus the
 * username bucket when one was supplied. The username is lowercased on
 * purpose: user lookup is case-insensitive under the default MySQL collation,
 * so "Bryan" and "bryan" are the same account and must share one bucket -
 * otherwise an attacker could rotate letter-case to dodge the throttle.
 */
function login_throttle_keys($username){
	$keys = array('ip:'.loginRemoteAddr());
	if($username !== ''){
		$keys[] = 'user:'.strtolower(trim($username));
	}
	return $keys;
}

function loginCaptchaCreate($purpose){
	$left = loginSecureRandomInt(2,12);
	$right = loginSecureRandomInt(2,12);
	if(!isset($_SESSION['loginCaptcha']) || !is_array($_SESSION['loginCaptcha'])){
		$_SESSION['loginCaptcha'] = array();
	}
	$_SESSION['loginCaptcha'][$purpose] = array(
		'answer' => (string)($left + $right),
		'expires' => time() + 600
	);
	return "What is ".$left." + ".$right."?";
}

function loginCaptchaHtml($purpose,$inputId){
	try{
		$question = loginCaptchaCreate($purpose);
	}catch (Exception $e){
		ghoti::logException("login.async.php:loginCaptchaHtml", $e);
		return "<p class=\"captchaBlock\">Security check unavailable. Please try again later.</p>\n";
	}
	$question = htmlspecialchars($question, ENT_QUOTES);
	$purpose = htmlspecialchars($purpose, ENT_QUOTES);
	$inputId = htmlspecialchars($inputId, ENT_QUOTES);
	return "<div id=\"loginCaptcha-".$purpose."\" class=\"captchaBlock\"><label class=\"ghotiField\"><span>Security check</span><strong class=\"captchaQuestion\">".$question."</strong><input type=\"text\" id=\"".$inputId."\" size=\"10\" autocomplete=\"off\" inputmode=\"numeric\" /></label></div>\n";
}

//Returns a new challenge after each registration attempt. Replacing only this
//block preserves the visitor's username, email, and password entries.
function refreshRegisterCaptcha(){
	return loginCaptchaHtml('register','registerForm-captcha');
}

function loginCaptchaVerify($purpose,$answer){
	$answer = trim((string)$answer);
	if($answer === ''){
		return "Security check answer required.";
	}
	if(!isset($_SESSION['loginCaptcha'][$purpose]) || !is_array($_SESSION['loginCaptcha'][$purpose])){
		return "Security check expired. Please reopen the form and try again.";
	}
	$challenge = $_SESSION['loginCaptcha'][$purpose];
	if(!isset($challenge['expires'], $challenge['answer']) || (int)$challenge['expires'] < time()){
		unset($_SESSION['loginCaptcha'][$purpose]);
		return "Security check expired. Please reopen the form and try again.";
	}
	if(!loginHashEquals((string)$challenge['answer'],$answer)){
		return "Security check answer incorrect.";
	}
	unset($_SESSION['loginCaptcha'][$purpose]);
	return true;
}

/* ================================================================== *
 *  Two-factor authentication (emailed codes, or an authenticator app)
 *
 *  Each account uses one of two second factors. By default the code is
 *  e-mailed. An account that has enrolled an authenticator app under Your
 *  account -> Sign-in security (a row in user_totp) is asked for the app's
 *  code instead and is sent no e-mail at all.
 *
 *  Applies to ADMINISTRATORS only, and only while ghoti::$enableTwoFactor is
 *  on - which Site Settings refuses to switch on until a test message has
 *  actually reached an administrator (ghoti_mail_verified()).
 *
 *  The shape of the flow matters. A correct password no longer signs anybody
 *  in on its own: it parks a challenge on the session and returns a marker.
 *  Only verifyTwoFactor() sets loggedIn, and only against a challenge that is
 *  present, unexpired, unspent and matching. There is deliberately no fallback
 *  when the code cannot be sent - a bypass an attacker can trigger by breaking
 *  mail is not a second factor. If mail breaks, set enableTwoFactor to false in
 *  ghoti.settings.json on the server to get back in.
 * ================================================================== */

const LOGIN_2FA_TTL       = 600; //ten minutes
const LOGIN_2FA_MAX_TRIES = 5;

//The marker login() returns instead of a user id. Deliberately not an int (the
//client tests `id > 0`) and not a bare string (that path prints the value as an
//error message), so neither existing branch can mistake it for something else.
//'method' tells the browser which prompt to show: 'email' or 'app'.
function login_2fa_pending_marker($method = 'email'){
	return array('twoFactor' => 'required', 'method' => $method === 'app' ? 'app' : 'email');
}

function login_2fa_is_pending($result){
	return is_array($result) && isset($result['twoFactor']) && $result['twoFactor'] === 'required';
}

/*
 * Does this account have to pass a second factor?
 *
 * Administrators always do while the feature is on - they are the accounts that
 * can publish, manage users and change server configuration. Ordinary members
 * do only when twoFactorAllUsers is also on, which puts the mail server in
 * front of every sign-in on the site.
 */
function login_2fa_required_for($userId){
	if(!ghoti::$enableTwoFactor){ return false; }
	if(isAdmin((int)$userId) === true){ return true; }
	return ghoti::$twoFactorAllUsers === true;
}

//Codes are compared as hashes so a readable one never sits in session storage.
function login_2fa_hash($code){
	return hash('sha256', 'ghoti-2fa|'.$code);
}

function login_2fa_generate_code(){
	//Six digits, uniform, from a CSPRNG. Kept as a string so a leading zero
	//survives - "042931" must not become 42931.
	return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function login_2fa_clear(){
	unset($_SESSION['pending2fa']);
}

/*
 * Put the challenge on the session and mail the code. Returns true, or an error
 * string the caller shows the person trying to sign in.
 */
function login_2fa_begin($userId, $username, $fingerprint){
	$db = $_SESSION["loginObj"]->logindb;
	//An enrolled authenticator app replaces the e-mail entirely. A read error
	//refuses rather than falling back to e-mail: an attacker who can make that
	//read fail must not be able to pick the weaker factor.
	$totp = $db->getTotp($userId);
	if($totp === false){
		ghoti::logError("login.async.php:login_2fa_begin", "could not read authenticator enrollment for uid $userId; sign-in refused");
		return "Sign-in is temporarily unavailable. Try again in a moment.";
	}
	if($totp !== null){
		session_regenerate_id(true);
		$_SESSION['pending2fa'] = array(
			'userId'      => (int)$userId,
			'username'    => (string)$username,
			'method'      => 'app',
			'expiresAt'   => time() + LOGIN_2FA_TTL,
			'attempts'    => 0,
			'fingerprint' => $fingerprint,
		);
		ghoti::logInfo("login.async.php:login_2fa_begin", "authenticator code requested for uid $userId from ".loginRemoteAddr());
		return true;
	}

	$email = $db->getUserEmailById($userId);
	if(!is_string($email) || trim($email) === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)){
		ghoti::logWarn("login.async.php:login_2fa_begin", "admin uid $userId has no usable e-mail address; sign-in refused");
		return "This account needs a valid e-mail address before it can sign in. Ask another administrator to add one.";
	}
	$mailer = ghoti_mail_mailer();
	if($mailer === null || !ghoti_mail_verified()){
		ghoti::logError("login.async.php:login_2fa_begin", "two-factor is on but outbound mail is not verified; uid $userId cannot sign in");
		return "Sign-in codes cannot be sent because outbound mail is not working. Contact the site operator.";
	}

	$code = login_2fa_generate_code();
	$siteTitle = ghoti::$siteTitle;
	$body = "Your sign-in code for ".$siteTitle." is:\n\n"
		.$code."\n\n"
		."Type it into the page you are signing in from. The code is good for ten minutes and can only be used once.\n\n"
		."If you did not just try to sign in, someone else has your password. Change it as soon as you can.\n\n"
		."Requested from: ".loginRemoteAddr()."\n";
	//The code leads the subject so it can be read from a notification or the
	//inbox list without opening the message.
	$sent = ghoti_mail_send_themed($mailer, $email, $username, $code." is your ".$siteTitle." sign-in code", $body, 'member');
	if($sent !== true){
		ghoti::logError("login.async.php:login_2fa_begin", "could not send sign-in code to uid $userId: ".(is_string($sent) ? $sent : 'unknown error'));
		return "Your sign-in code could not be sent. Try again in a moment, or contact the site operator.";
	}

	//Regenerate here too: the id that existed before the password was accepted
	//must not be the one the session is finally authenticated on.
	session_regenerate_id(true);
	$_SESSION['pending2fa'] = array(
		'userId'      => (int)$userId,
		'username'    => (string)$username,
		'method'      => 'email',
		'codeHash'    => login_2fa_hash($code),
		'expiresAt'   => time() + LOGIN_2FA_TTL,
		'attempts'    => 0,
		'fingerprint' => $fingerprint,
	);
	ghoti::logInfo("login.async.php:login_2fa_begin", "sign-in code issued for uid $userId from ".loginRemoteAddr());
	return true;
}

/* Does $code satisfy this challenge? For an app challenge the matched time
 * step is spent here, so the same code cannot sign in twice. */
function login_2fa_code_matches($pending, $code){
	if(($pending['method'] ?? 'email') !== 'app'){
		return hash_equals((string)($pending['codeHash'] ?? ''), login_2fa_hash($code));
	}
	$db = $_SESSION["loginObj"]->logindb;
	$totp = $db->getTotp((int)$pending['userId']);
	if(!is_array($totp)){ return false; } //removed meanwhile, or unreadable: fail closed
	$step = ghoti_totp_verify($totp['secret'], $code, null, $totp['lastStep']);
	return $step !== false && $db->spendTotpStep((int)$pending['userId'], $step) === true;
}

/* True when every administrator has an authenticator app enrolled - the
 * other way two-factor can be switched on without anyone being locked out,
 * since no administrator would then need e-mail to sign in. */
function login_2fa_all_admins_enrolled(){
	$db = $_SESSION["loginObj"]->logindb ?? null;
	if(!is_object($db) || !method_exists($db, 'adminsWithoutTotp')){ return false; }
	$missing = $db->adminsWithoutTotp();
	return is_array($missing) && count($missing) === 0;
}

/*
 * Second step of signing in. Returns the user id on success, 0 for a wrong
 * code, or a string explaining why the attempt cannot continue.
 */
function verifyTwoFactor($code){
	//Fails closed: no challenge means there is nothing to verify, and this must
	//never fall through to establishing a session.
	if(empty($_SESSION['pending2fa']) || !is_array($_SESSION['pending2fa'])){
		ghoti::logWarn("login.async.php:verifyTwoFactor", "code submitted with no challenge in session from ".loginRemoteAddr());
		return "Your sign-in attempt has expired. Start again.";
	}
	//An already-authenticated session has no business here.
	if(!empty($_SESSION['loggedIn'])){
		login_2fa_clear();
		return "You are already signed in.";
	}
	$pending = $_SESSION['pending2fa'];

	if(time() > (int)$pending['expiresAt']){
		login_2fa_clear();
		ghoti::logInfo("login.async.php:verifyTwoFactor", "expired code for uid ".$pending['userId']);
		return "That code has expired. Start again.";
	}

	$throttle = login_throttle_store();
	$throttleKeys = login_throttle_keys($pending['username']);

	$code = preg_replace('/\D+/', '', (string)$code); //people paste "042 931"
	if($code === ''){ return 0; }

	if(!login_2fa_code_matches($pending, $code)){
		$_SESSION['pending2fa']['attempts'] = (int)$pending['attempts'] + 1;
		//A six-digit code is a small space, so a wrong one counts against the
		//same throttle a wrong password does - otherwise it could be guessed at
		//whatever rate the network allows.
		foreach($throttleKeys as $key){ $throttle->recordFailure($key); }
		ghoti_security_record_failed_login($throttle);
		ghoti::logWarn("login.async.php:verifyTwoFactor", "wrong sign-in code for uid ".$pending['userId']." (attempt ".$_SESSION['pending2fa']['attempts'].") from ".loginRemoteAddr());
		if($_SESSION['pending2fa']['attempts'] >= LOGIN_2FA_MAX_TRIES){
			login_2fa_clear();
			return "Too many incorrect codes. Start again.";
		}
		return 0;
	}

	//Correct. Spend the challenge before establishing anything, so a replay of
	//the same request cannot produce a second session.
	$userId = (int)$pending['userId'];
	$fingerprint = $pending['fingerprint'];
	login_2fa_clear();
	$throttle->clear('user:'.strtolower($pending['username']));

	session_regenerate_id(true);
	$_SESSION['loggedIn'] = true;
	$_SESSION['credentialFingerprint'] = $fingerprint;
	$_SESSION['userId'] = $userId;
	$_SESSION['admin'] = isAdmin($userId);
	$_SESSION['last_activity'] = time();
	$_SESSION['login_attempts'] = 0;
	$_SESSION['login_last_attempt'] = 0;
	ghoti::logInfo("login.async.php:verifyTwoFactor", "sign-in completed for uid $userId from ".loginRemoteAddr());
	return $userId;
}

/* ================================================================== *
 *  Your account -> Sign-in security: enrolling an authenticator app
 *
 *  Enrolling is two steps so a mistyped scan cannot lock anyone out: the new
 *  secret waits on the session until the app has produced one correct code
 *  for it, and only then replaces e-mail as this account's second factor.
 *  Both enrolling and removing need the account password again, so a session
 *  left open on a shared computer cannot quietly change how the account signs
 *  in.
 * ================================================================== */

const LOGIN_TOTP_ENROLL_TTL = 900; //fifteen minutes to scan and confirm

//Re-check the signed-in account's password, feeding the same throttle a
//failed sign-in does. Returns true or a message.
function login_confirm_password($password){
	$password = (string)$password;
	if($password === '' || strlen($password) > validate::MAX_PASSWORD){ return "Enter your current password."; }
	$db = $_SESSION["loginObj"]->logindb;
	$userId = ghoti_current_user_id();
	$username = (string)$db->getUserNameById($userId);
	$throttle = login_throttle_store();
	foreach(login_throttle_keys($username) as $key){
		if($throttle->isBlocked($key)){ return "Too many attempts. Wait a few minutes and try again."; }
	}
	if((int)$db->authenticate($username, $password) === $userId && $userId > 0){ return true; }
	foreach(login_throttle_keys($username) as $key){ $throttle->recordFailure($key); }
	ghoti_security_record_failed_login($throttle);
	ghoti::logWarn("login.async.php:login_confirm_password", "wrong password re-entered by uid $userId from ".loginRemoteAddr());
	return "That password is not correct.";
}

//The Sign-in security dialog.
function printSignInSecurity(){
	if(!ghoti_require_login()){ return "<p>You must be signed in.</p>"; }
	$userId = ghoti_current_user_id();
	$totp = $_SESSION["loginObj"]->logindb->getTotp($userId);
	if($totp === false){ return "<p>Your sign-in settings could not be loaded. Try again in a moment.</p>"; }
	return $_SESSION["loginObj"]->loginui->printSignInSecurity($totp !== null, login_2fa_required_for($userId));
}

//Step one: a fresh secret, held on the session. Returns the secret and the
//otpauth:// URI the page turns into a QR code.
function totpBeginEnroll(){
	if(!ghoti_require_login()){ return array('success' => false, 'error' => "You must be signed in."); }
	$userId = ghoti_current_user_id();
	$secret = ghoti_totp_new_secret();
	$_SESSION['totpEnroll'] = array('userId' => $userId, 'secret' => $secret, 'expiresAt' => time() + LOGIN_TOTP_ENROLL_TTL);
	$account = (string)$_SESSION["loginObj"]->logindb->getUserNameById($userId);
	return array('success' => true, 'secret' => $secret, 'uri' => ghoti_totp_uri($secret, $account, ghoti::$siteTitle));
}

//Step two: one correct code from the app, plus the password. Returns true or
//a message.
function totpConfirmEnroll($code, $password){
	if(!ghoti_require_login()){ return "You must be signed in."; }
	$userId = ghoti_current_user_id();
	$pending = $_SESSION['totpEnroll'] ?? null;
	if(!is_array($pending) || (int)$pending['userId'] !== $userId || time() > (int)$pending['expiresAt']){
		unset($_SESSION['totpEnroll']);
		return "This setup has expired. Start again to get a new QR code.";
	}
	$checked = login_confirm_password($password);
	if($checked !== true){ return $checked; }
	$step = ghoti_totp_verify($pending['secret'], $code);
	if($step === false){
		return "That code does not match. Check the time on your phone is set automatically, and enter the code the app shows now.";
	}
	if(!$_SESSION["loginObj"]->logindb->saveTotp($userId, $pending['secret'], $step)){
		return "The authenticator could not be saved. Try again in a moment.";
	}
	unset($_SESSION['totpEnroll']);
	ghoti::logInfo("login.async.php:totpConfirmEnroll", "uid $userId enrolled an authenticator app from ".loginRemoteAddr());
	return true;
}

/* Back to e-mailed codes. Refused while this account must pass two-factor and
 * mail has not been proven - that would leave it no way to sign in. */
function totpRemove($password){
	if(!ghoti_require_login()){ return "You must be signed in."; }
	$userId = ghoti_current_user_id();
	if(login_2fa_required_for($userId) && !ghoti_mail_verified()){
		return "Outbound mail has not been tested, so e-mailed codes cannot replace your app yet. Test mail under Site Settings -> Mail first.";
	}
	$checked = login_confirm_password($password);
	if($checked !== true){ return $checked; }
	if(!$_SESSION["loginObj"]->logindb->deleteTotp($userId)){ return "The authenticator could not be removed. Try again in a moment."; }
	ghoti::logInfo("login.async.php:totpRemove", "uid $userId removed their authenticator app from ".loginRemoteAddr());
	return true;
}

//Manage Users: clear another account's authenticator (a lost phone). That
//account falls back to e-mailed codes.
function adminResetTotp($userId){
	if(!ghoti_require_admin()){ return "Admin access required."; }
	try{
		$userId = ghoti_validate()->id($userId, "user id");
	}catch(Exception $e){
		return "Invalid user.";
	}
	//Same rule as totpRemove(): an account that must pass two-factor cannot be
	//moved to e-mailed codes that have never been shown to arrive. Switching
	//two-factor off in Site Settings is the way through in that case.
	if(login_2fa_required_for($userId) && !ghoti_mail_verified()){
		return "Outbound mail has not been tested, so this account could not receive e-mailed codes and would be locked out. Test mail under Site Settings -> Mail first, or switch two-factor off.";
	}
	if(!$_SESSION["loginObj"]->logindb->deleteTotp($userId)){ return "The authenticator could not be reset. Check the log."; }
	ghoti::logWarn("login.async.php:adminResetTotp", "authenticator app for uid $userId reset by admin uid ".ghoti_current_user_id()." from ".loginRemoteAddr());
	return true;
}

//Abandoning the form should not leave a live challenge behind.
function cancelTwoFactor(){
	login_2fa_clear();
	return true;
}

function login($username,$password){
	$username = trim((string) $username);
	$password = (string) $password;
	$now = time();
	$attempts = isset($_SESSION['login_attempts']) ? (int) $_SESSION['login_attempts'] : 0;
	$lastAttempt = isset($_SESSION['login_last_attempt']) ? (int) $_SESSION['login_last_attempt'] : 0;

	//A manual or automatic IP block applies before database work. The same
	//check also runs at request bootstrap; keeping it here protects direct unit
	//and module use that does not pass through index.php.
	$ipBlock = ghoti_security_block_status(loginRemoteAddr());
	if($ipBlock !== null){
		ghoti::logWarn("login.async.php:login", "Login denied for blacklisted IP ".loginRemoteAddr());
		return "Access from this network address is blocked.";
	}

	//Server-side throttle: per-IP AND per-username, persistent across sessions.
	//The session counter below still applies too; this one survives cookie
	//rotation and is the actual brute-force control.
	$throttle = login_throttle_store();
	$throttleKeys = login_throttle_keys($username);
	$blocked = false;
	foreach($throttleKeys as $throttleKey){
		if($throttle->isBlocked($throttleKey)){ $blocked = true; break; }
	}
	if($blocked){
		//Count repeated requests during the short throttle too. This lets ten
		//continued failures graduate into the longer automatic IP blacklist.
		$throttle->recordFailure('ip:'.loginRemoteAddr());
		ghoti_security_record_failed_login($throttle);
		ghoti::logWarn("login.async.php:login", "Login blocked (server throttle) for '$username' from ".loginRemoteAddr());
		return "Too many login attempts. Please try again later.";
	}

	ghoti::logDebug("login.async.php:login", "Login flow start for user '$username' from ".loginRemoteAddr());

	if ($attempts >= 5 && ($now - $lastAttempt) < 600) {
		ghoti::logWarn("login.async.php:login", "Blocked login attempt for $username from ".loginRemoteAddr());
		return "Too many login attempts. Please try again later.";
	}

	if ($username === '' || $password === '') {
		ghoti::logDebug("login.async.php:login", "Login rejected: missing username or password for '$username'");
		return false;
	}

	// Reject absurdly long credentials before they reach the database or the
	// (deliberately slow) password hasher - an unbounded password is a cheap
	// slow-hash DoS. No valid username/password can exceed these.
	if (strlen($username) > validate::MAX_USERNAME || strlen($password) > validate::MAX_PASSWORD) {
		ghoti::logWarn("login.async.php:login", "Login rejected: over-length credentials for '".substr($username,0,20)."' from ".loginRemoteAddr());
		return false;
	}

	$_SESSION['login_last_attempt'] = $now;
	$_SESSION['login_attempts'] = $attempts + 1;
	ghoti::logInfo("login.async.php:login", "Login attempt($username) from ".loginRemoteAddr());
	$fingerprint = null;
	$id = $_SESSION["loginObj"]->logindb->authenticate($username,$password,$fingerprint);
	ghoti::logDebug("login.async.php:login", "Login authentication result for '$username': ".var_export($id, true));
	if ($id && $id > 0) {
		$_SESSION['login_attempts'] = 0;
		$_SESSION['login_last_attempt'] = 0;
		//Clear only this username; a valid login must not reset IP failures.
		$throttle->clear('user:'.strtolower($username)); // A valid account must not clear the IP bucket used to attack others.
		// Establish the authenticated session HERE, immediately after we have
		// verified the password. Previously the browser called setSessionVars()
		// with an id of its choosing to elevate the session - which meant anyone
		// could POST setSessionVars with id=1 and become that user (typically the
		// admin) with no password at all. Doing it here, keyed to the id we just
		// authenticated, closes that bypass. session_regenerate_id prevents
		// session fixation.
		//A correct password is the FIRST factor. When a second one is required
		//this returns without setting loggedIn at all - see verifyTwoFactor().
		login_2fa_clear();
		if(login_2fa_required_for($id)){
			$started = login_2fa_begin((int)$id, $username, $fingerprint);
			if($started !== true){
				ghoti::logWarn("login.async.php:login", "two-factor could not start for '$username': ".$started);
				return $started; //a string: the client prints it as the reason
			}
			ghoti::logInfo("login.async.php:login", "Password accepted for '$username' (uid $id); awaiting sign-in code");
			return login_2fa_pending_marker($_SESSION['pending2fa']['method'] ?? 'email');
		}

		session_regenerate_id(true);
		$_SESSION['loggedIn'] = true;
		$_SESSION['credentialFingerprint'] = $fingerprint;
		$_SESSION['userId'] = (int) $id;
		$_SESSION['admin'] = isAdmin((int) $id);
		$_SESSION['last_activity'] = time();
		ghoti::logInfo("login.async.php:login", "Login succeeded for '$username' with userId $id");
	} else {
		//Record the failure in the server-side throttle (best-effort).
		foreach($throttleKeys as $throttleKey){ $throttle->recordFailure($throttleKey); }
		ghoti_security_record_failed_login($throttle);
		ghoti::logWarn("login.async.php:login", "Login failed for '$username'");
	}
	return $id;
}

/* ================================================================== *
 *  E-mail verification for new registrations
 *
 *  Same shape as the two-factor flow: a code is mailed and the thing being
 *  asked for does not happen until it comes back. The difference is WHAT is
 *  withheld - here it is the account itself. Nothing is written to `users`
 *  until the code is confirmed, so there are no half-made accounts to reap and
 *  no "verified" column to gate every later login on.
 *
 *  It applies only when outbound mail has been proven to work. With no working
 *  mail there is nobody to send a code to, and refusing every registration
 *  would be worse than the behaviour this replaces, so registration then works
 *  exactly as it did before.
 *
 *  This is the first sender in the app that mails an address a STRANGER typed.
 *  The captcha and duplicate check already sit in front of it, and sends are
 *  rate limited per address below, so it cannot be used to post mail at anyone.
 * ================================================================== */

const LOGIN_REG_TTL        = 900; //fifteen minutes
const LOGIN_REG_MAX_TRIES  = 5;
const LOGIN_REG_MAX_PER_IP = 5;   //verification e-mails per hour, per address

//Its own domain prefix: a code minted for one flow must not hash equal to the
//same digits minted for the other.
function login_reg_hash($code){
	return hash('sha256', 'ghoti-register|'.$code);
}

function login_reg_pending_marker($email){
	return array('verifyEmail' => 'required', 'email' => (string)$email);
}

function login_reg_is_pending($result){
	return is_array($result) && isset($result['verifyEmail']) && $result['verifyEmail'] === 'required';
}

function login_reg_clear(){
	unset($_SESSION['pendingRegistration']);
}

//Should registration ask for a code at all?
function login_reg_verification_available(){
	return function_exists('ghoti_mail_verified') && ghoti_mail_verified();
}

/*
 * How many verification e-mails this address has already caused in the last
 * hour. A separate throttle key from the login buckets on purpose: 'ip:' feeds
 * ghoti_security_record_failed_login(), and a few abandoned registrations must
 * not add up to an automatic IP blacklist.
 */
function login_reg_send_allowed($throttle){
	$ip = loginRemoteAddr();
	if($ip === ''){ return true; }
	try{
		return $throttle->countWindow('regmail:'.$ip, 3600) < LOGIN_REG_MAX_PER_IP;
	}catch(Throwable $e){
		ghoti::logWarn("login.async.php:login_reg_send_allowed", "throttle unavailable: ".$e->getMessage());
		return false; //fail closed: unknown is not permission to send mail
	}
}

/*
 * Hold the registration and mail the code. $passwordHash is already hashed -
 * a pending registration never parks a plaintext password in session storage.
 * Returns true, or an error string for the person registering.
 */
function login_reg_begin($username, $email, $passwordHash){
	$mailer = ghoti_mail_mailer();
	if($mailer === null || !ghoti_mail_verified()){
		return "Registration is temporarily unavailable. Try again later.";
	}
	$throttle = login_throttle_store();
	if(!login_reg_send_allowed($throttle)){
		ghoti::logWarn("login.async.php:login_reg_begin", "verification e-mail rate limit hit from ".loginRemoteAddr());
		return "Too many registration attempts from here. Try again later.";
	}

	$code = login_2fa_generate_code(); //the generator has no domain; the hash does
	$siteTitle = ghoti::$siteTitle;
	$body = "Someone asked to create an account on ".$siteTitle." with this e-mail address.\n\n"
		."Your confirmation code is:\n\n"
		.$code."\n\n"
		."Type it into the page you are registering from. The code is good for fifteen minutes and can only be used once.\n\n"
		."If this was not you, nothing has been created and you can ignore this message. No account exists with this address unless the code is entered.\n\n"
		."Requested from: ".loginRemoteAddr()."\n";
	$sent = ghoti_mail_send_themed($mailer, $email, $username, "Confirm your ".$siteTitle." account", $body, 'none');
	if($sent !== true){
		ghoti::logError("login.async.php:login_reg_begin", "could not send the confirmation code to a registrant: ".(is_string($sent) ? $sent : 'unknown error'));
		return "Your confirmation code could not be sent. Check the address and try again.";
	}
	//Only counted once a message actually went out.
	try{ $throttle->recordFailure('regmail:'.loginRemoteAddr()); }catch(Throwable $e){ /* best effort */ }

	$_SESSION['pendingRegistration'] = array(
		'username'     => (string)$username,
		'email'        => (string)$email,
		'passwordHash' => (string)$passwordHash,
		'codeHash'     => login_reg_hash($code),
		'expiresAt'    => time() + LOGIN_REG_TTL,
		'attempts'     => 0,
	);
	ghoti::logInfo("login.async.php:login_reg_begin", "confirmation code issued for a new registration from ".loginRemoteAddr());
	return true;
}

/*
 * Second step of registering: the account is created HERE and nowhere else in
 * the verified flow. Returns true, 0 for a wrong code, or a string explaining
 * why the attempt cannot continue.
 */
function verifyRegistration($code){
	if(empty($_SESSION['pendingRegistration']) || !is_array($_SESSION['pendingRegistration'])){
		return "Your registration has expired. Start again.";
	}
	$pending = $_SESSION['pendingRegistration'];

	if(time() > (int)$pending['expiresAt']){
		login_reg_clear();
		return "That code has expired. Start again.";
	}

	$code = preg_replace('/\D+/', '', (string)$code);
	if($code === ''){ return 0; }

	if(!hash_equals((string)$pending['codeHash'], login_reg_hash($code))){
		$_SESSION['pendingRegistration']['attempts'] = (int)$pending['attempts'] + 1;
		ghoti::logWarn("login.async.php:verifyRegistration", "wrong confirmation code (attempt ".$_SESSION['pendingRegistration']['attempts'].") from ".loginRemoteAddr());
		if($_SESSION['pendingRegistration']['attempts'] >= LOGIN_REG_MAX_TRIES){
			login_reg_clear();
			return "Too many incorrect codes. Start again.";
		}
		return 0;
	}

	//Spend the pending record before creating anything, so a replayed request
	//cannot make a second account.
	login_reg_clear();

	//The duplicate check inside addUser() runs again here, which is what closes
	//the window between the code being sent and being entered.
	$result = $_SESSION["loginObj"]->logindb->addUser($pending['username'], $pending['passwordHash'], $pending['email'], true);
	if(!$result){
		ghoti::logWarn("login.async.php:verifyRegistration", "account creation failed after a correct code for '".$pending['username']."'");
		return "That username or e-mail was taken while you were confirming. Start again.";
	}
	ghoti::logInfo("login.async.php:verifyRegistration", "Registered ".$pending['username']." (e-mail confirmed) from ".loginRemoteAddr());
	//Deliberately NOT signing them in: registering is not authenticating, and
	//an automatic session here would hand a brand-new account a way past the
	//sign-in code that twoFactorAllUsers may require.
	return true;
}

function cancelRegistration(){
	login_reg_clear();
	return true;
}

function addUser($username,$email,$password,$captchaAnswer=''){
	//Honour the server-side registration switch instead of relying on the
	//client hiding the Register button.
	if(ghoti::$allowRegister !== true){
		ghoti::logWarn("login.async.php:addUser", "Registration attempt while disabled from ".loginRemoteAddr());
		return "Registration is disabled.";
	}
	$username = trim((string) $username);
	$email = trim((string) $email);
	$password = (string) $password;

	ghoti::logDebug("login.async.php:addUser", "Registration flow start for '$username' from ".loginRemoteAddr());

	//Validate + normalize every field up front. username() enforces the safe
	//charset (the username is echoed into several admin views), email() actually
	//rejects a bad address (the old checkEmail silently passed everything), and
	//password() enforces the min/max length window (max guards the slow hasher).
	try{
		$v = ghoti_validate();
		$username = $v->username($username);
		$email    = $v->email($email);
		$password = $v->password($password);
		ghoti::logDebug("login.async.php:addUser", "Registration validation passed for '$username'");
	}catch (Exception $e) {
		ghoti::logWarn("login.async.php:addUser", "Registration validation failed for '$username': ".$e->getMessage());
		return $e->getMessage();
	}

	$captchaResult = loginCaptchaVerify('register',$captchaAnswer);
	if($captchaResult !== true){
		ghoti::logWarn("login.async.php:addUser", "Registration rejected for '$username': captcha failed from ".loginRemoteAddr());
		return $captchaResult;
	}

	$duplicate = $_SESSION["loginObj"]->logindb->checkDuplicate($username,$email);
	ghoti::logDebug("login.async.php:addUser", "Registration duplicate check for '$username': ".var_export($duplicate, true));
	if($duplicate){
		ghoti::logWarn("login.async.php:addUser", "Registration rejected for '$username': duplicate user/email");
		return "Username or Email is already registered!";
	}

	//With working mail, the address is confirmed before the account exists.
	//Without it there is nobody to send a code to, so registration behaves as
	//it always has.
	login_reg_clear();
	if(login_reg_verification_available()){
		$hash = $_SESSION["loginObj"]->logindb->hashPendingPassword($password);
		$started = login_reg_begin($username, $email, $hash);
		if($started !== true){ return $started; }
		ghoti::logInfo("login.async.php:addUser", "Registration for '$username' is awaiting e-mail confirmation");
		return login_reg_pending_marker($email);
	}

	ghoti::logDebug("login.async.php:addUser", "Calling addUser for '$username'");
	$result = $_SESSION["loginObj"]->logindb->addUser($username,$password,$email);
	ghoti::logDebug("login.async.php:addUser", "Registration database result for '$username': ".var_export($result, true));
	if($result){
		ghoti::logInfo("login.async.php:addUser", "Registered $username from ".loginRemoteAddr());
		return true;
	}
	ghoti::logWarn("login.async.php:addUser", "Registration failed for '$username' with no exception details");
	return "Error!";
}

function saveUser($name,$email,$id){
	if(!ghoti_require_admin()){ return "Admin access required."; }
	try{
		$v = ghoti_validate();
		$id    = $v->id($id, "user id");
		$name  = $v->username($name); // same safe charset as registration
		$email = $v->email($email);
	}catch (Exception $e) {
		return $e->getMessage();
	}
	//Reject a username/email already used by a DIFFERENT account (registration
	//checks this too; the admin edit path previously skipped the check).
	if($_SESSION["loginObj"]->logindb->checkDuplicateExcluding($name,$email,$id)){
		return "Username or Email is already registered!";
	}
	ghoti::logInfo("login.async.php:saveUserInfo", "Attempting to save user info for $name from ".loginRemoteAddr());
	return $_SESSION["loginObj"]->logindb->updateUser($id,$name,$email);
}

function deleteUser($id){
	$id = (int) $id;
	$selfId = ghoti_current_user_id();
	if($selfId <= 0){ return false; } //must be logged in
	if($id === 0){ $id = $selfId; }   //0 means "delete my own account"
	//Deleting anyone other than yourself is an admin-only action.
	if($id !== $selfId && !ghoti_require_admin()){ return false; }
	ghoti::logInfo("login.async.php:deleteUser", "Attempting to delete userID: $id from ".loginRemoteAddr());
	return $_SESSION["loginObj"]->logindb->deleteUser($id);
}

function setSessionVars($id){
	$id = (int) $id;
	// Hardened: this endpoint previously elevated the session to ANY
	// client-supplied id with no authentication - a trivial account-takeover
	// (POST setSessionVars id=1 => you are the admin). login() now establishes
	// the session itself, so this only CONFIRMS the session already
	// authenticated for the same id. It never grants access.
	if($id <= 0 || ghoti_current_user_id() !== $id){
		ghoti::logWarn("login.async.php:setSessionVars", "refused id ".$id." (session uid ".ghoti_current_user_id().") from ".loginRemoteAddr());
		return false;
	}
	$_SESSION['last_activity'] = time();
	return true;
}

function changePassword($password,$newPassword,$captchaAnswer=''){
	if(!ghoti_require_login()){ return false; }
	$userName = $_SESSION["loginObj"]->logindb->getUserNameById($_SESSION["userId"]);
	$password = (string) $password;
	try{
		$v = ghoti_validate();
		$v->checkExists($userName);
		$v->checkExists($password);
		//The new password must satisfy the full length window (min 8, and a max so
		//it can't be used to hammer the slow hasher).
		$newPassword = $v->password($newPassword);
	}catch (Exception $e) {
		return $e->getMessage();
	}
	//Guard the current-password field against slow-hash abuse too.
	if(strlen($password) > validate::MAX_PASSWORD){
		return "Password is too long.";
	}

	$captchaResult = loginCaptchaVerify('changePassword',$captchaAnswer);
	if($captchaResult !== true){
		ghoti::logWarn("login.async.php:changePassword", "captcha failed for $userName from ".loginRemoteAddr());
		return $captchaResult;
	}

	ghoti::logInfo("login.async.php:changePassword", "Change password for $userName from ".loginRemoteAddr().".");
	$id = $_SESSION["loginObj"]->logindb->authenticate($userName,$password);
	if ($id > 0){
		return $_SESSION["loginObj"]->logindb->changePassword($id,$newPassword);
	}
	ghoti::logWarn("login.async.php:changePassword", "Auth failed for user ".$id."(".loginRemoteAddr().") trying to change password");
	return false;
}

function logout(){
	try{
		ghoti::logDebug("login.async.php:logout", "Trying logout...");
		$_SESSION['login_attempts'] = 0;
		$_SESSION['login_last_attempt'] = 0;
		$_SESSION = array();
		if (ini_get('session.use_cookies')) {
			$params = session_get_cookie_params();
			setcookie(session_name(), '', time() - 42000,
				$params['path'], $params['domain'],
				$params['secure'], $params['httponly']
			);
		}
		session_unset();
		session_destroy();
	}catch (Exception $e) {
		ghoti::logException("login.async.php:logout", $e);
		return $e->getMessage();
	}
	ghoti::logInfo("login.async.php:logout", "Logout finished.");
	return true;
}

function isAdmin($id){
	return $_SESSION["loginObj"]->logindb->isAdmin($id);
}

function printAdminMenu(){
	//Defense in depth: the client only asks for this menu when isAdmin() is
	//true, but don't hand the admin menu to anyone who calls the endpoint.
	if(!ghoti_require_admin()){ return ""; }
	return $_SESSION["loginObj"]->loginui->printAdminMenu();
}

function printManageUserForm(){
	if(!isset($_SESSION['userId']) || !isAdmin($_SESSION['userId'])){
		ghoti::logWarn("login.async.php:printManageUserForm", "Unauthorized attempt from ".loginRemoteAddr());
		return "<h1>Users</h1><p>Admin access required.</p>";
	}
	$userList = $_SESSION["loginObj"]->logindb->getUserList();
	//Boards contribute two columns here when the module is on. They are fetched
	//through function_exists() so this screen is unchanged when it is off.
	$postCounts = function_exists('boards_user_post_counts') ? boards_user_post_counts() : array();
	$moderates  = function_exists('boards_user_moderation_summary') ? boards_user_moderation_summary() : array();
	$totpEnrolled = $_SESSION["loginObj"]->logindb->totpEnrolledMap();
	return $_SESSION["loginObj"]->loginui->printManageUserForm($userList, $postCounts, $moderates, $totpEnrolled);
}

function printLoginForm(){
	return $_SESSION["loginObj"]->loginui->printLoginForm();
}

function printChangePasswordForm(){
	if(!ghoti_require_login()){ return "<p>You must be logged in to change your password.</p>"; }
	return $_SESSION["loginObj"]->loginui->printChangePasswordForm();
}

function toggleAdmin($id){
	//Critical: without this an anonymous caller could grant themselves admin.
	if(!ghoti_require_admin()){ return "Admin access required."; }
	try{
		$id = ghoti_validate()->id($id, "user id");
	}catch (Exception $e) {
		return "Invalid user.";
	}
	ghoti::logInfo("login.async.php:toggleAdmin", "Toggling admin status for userID: $id from ".loginRemoteAddr());
	return $_SESSION["loginObj"]->logindb->toggleAdmin($id, ghoti_current_user_id());
}

function checkLogin(){
	if(isset($_SESSION["loggedIn"]) && $_SESSION["loggedIn"] == true && isset($_SESSION["userId"]) && $_SESSION["userId"] > 0){
		ghoti::logDebug("login.async.php:checkLogin", "Found uid ".$_SESSION["userId"]);
		return $_SESSION["userId"];
	}
	ghoti::logDebug("login.async.php:checkLogin", "No active session");
	return false;
}

function getLoggedInId(){
	return $_SESSION['userId'];
}

function printSystemMenu(){
	return $_SESSION["loginObj"]->loginui->printSystemMenu();
}

function printRegisterForm(){
	return $_SESSION["loginObj"]->loginui->printRegisterForm();
}

ghoti_async_register(
	"checkGetLogin",
	"addUser",
	"refreshRegisterCaptcha",
	"changePassword",
	"checkLogin",
	"deleteUser",
	"getLoggedInId",
	"isAdmin",
	"login",
	"logout",
	"printAdminMenu",
	"printManageUserForm",
	"printLoginForm",
	"printChangePasswordForm",
	"printSystemMenu",
	"printRegisterForm",
	"setSessionVars",
	"saveUser",
	"toggleAdmin",
	"verifyTwoFactor",
	"cancelTwoFactor",
	"printSignInSecurity",
	"totpBeginEnroll",
	"totpConfirmEnroll",
	"totpRemove",
	"adminResetTotp",
	"verifyRegistration",
	"cancelRegistration"
);

/* ---------------------------------------------------------------- *
 *  UI renderer (formerly login.ui.php / class loginui)
 * ---------------------------------------------------------------- */

class loginui{
	public $output;

	public function printSystemMenu(){
		$this->output .= "<ul>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\" class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"logout();\">&nbsp;Log Out</a></li>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\" class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"printChangePasswordForm();\">&nbsp;Change Password</a></li>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\" class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"printSignInSecurity();\">&nbsp;Sign-in security</a></li>\n";
		//Only while boards are on: the setting is about board replies, and the
		//dialog's script (boards.js) is not loaded otherwise.
		if(ghoti::$enableBoards){
			$this->output .= "<li class=\"dropdown-item\"><a href=\"#\" class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"ghotiModuleAction('boardsShowNotifyPrefs');\">&nbsp;Notifications</a></li>\n";
		}
		$this->output .= "</ul>\n";
		return $this->output;
	}

	public function printAdminMenu(){
		$this->output = "<ul>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\" class=\"dropdown-item ghotiMenu\" onclick=\"showPageManager();\">Pages</a></li>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\"class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"ghotiModuleAction('manageBanners');\">Banners</a></li>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\"class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"ghotiModuleAction('editLinkForm');\">Links</a></li>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\" class=\"dropdown-item ghotiMenu\" onclick=\"ghotiModuleAction('showAnalytics');\">Analytics</a></li>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\"class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"ghotiModuleAction('galleryManager');\">Galleries</a></li>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\"class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"ghotiModuleAction('fileManager');\">Files</a></li>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\"class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"ghotiModuleAction('printManageUserForm');\">Users</a></li>\n";
		if(ghoti::$enableBoards){
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\"class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"ghotiModuleAction('showBoardManager');\">Boards</a></li>\n";
		}
		if(ghoti::$enableVhosts){
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\"class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"ghotiModuleAction('showVhosts');\">Apache Vhosts</a></li>\n";
		}
		if(ghoti::$enableStore){
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\"class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"ghotiModuleAction('showStoreManager');\">Store</a></li>\n";
		}
		if(ghoti::$enableBpong){
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\"class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"ghotiModuleAction('showBpongManager');\">Pong</a></li>\n";
		}
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\" class=\"dropdown-item ghotiMenu\" onclick=\"showDocumentation();\">Documentation</a></li>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\" class=\"dropdown-item ghotiMenu\" onclick=\"showBackupRestore();\">Backup / Restore</a></li>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\"class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"showSiteSettings();\">Site Settings</a></li>\n";
		$this->output .= "</ul>\n";
		return $this->output;
	}

	public function printLoginForm(){
		$this->output = "<div id=\"ghotiLogin\"><form id=\"loginForm\" class=\"ghotiForm\" action=\"#\" onsubmit=\"login(); return false;\">\n";
		$this->output .= "<label class=\"ghotiField\"><span>Username</span><input type=\"text\" name=\"userName\" id=\"userName\" size=\"20\" autocomplete=\"username\" autocapitalize=\"none\" spellcheck=\"false\" /></label>\n";
		$this->output .= "<label class=\"ghotiField\"><span>Password</span><span class=\"ghotiPasswordInput\"><input type=\"password\" name=\"password\" id=\"password\" size=\"20\" autocomplete=\"current-password\" /><button type=\"button\" class=\"ghotiPasswordToggle\" onclick=\"ghotiTogglePassword(this);\" aria-label=\"Show password\" title=\"Show password\">&#128065;</button></span></label>\n";
		$this->output .= "<div class=\"ghotiFormActions\"><button type=\"submit\" class=\"ghotiButton\">Login</button>\n";
		if(ghoti::$allowRegister == true){
			$this->output .= "<button type=\"button\" class=\"ghotiButton ghotiButtonSecondary\" onclick=\"printRegisterForm();\">Register</button>\n";
		}
		$this->output .= "</div><p><a href=\"password-reset.php\">Forgot your password?</a></p></form><span id=\"loginFeedback\"></span></div>\n";
		return $this->output;
	}

	public function printPopupLogin(){
		if(!ghoti::showLoginButton()){ return "<div id=\"ghotiLogin\"></div>\n"; }
		$this->output = "<div id=\"ghotiLogin\"><a class=\"dropdown-item\" href=\"#\" onclick=\"popupLogin();\">Login</a></div>\n";
		return $this->output;
	}

	public function printRegisterForm(){
		$this->output = "<div id=\"ghotiLogin\"><form id=\"registerForm\" class=\"ghotiForm\" action=\"#\" onsubmit=\"register(); return false;\">\n";
		$this->output .= "<label class=\"ghotiField\"><span>Username</span><input type=\"text\" name=\"userName\" id=\"registerForm-userName\" size=\"20\" autocomplete=\"username\" autocapitalize=\"none\" spellcheck=\"false\" /></label>\n";
		$this->output .= "<label class=\"ghotiField\"><span>E-mail</span><input type=\"email\" name=\"email\" id=\"registerForm-email\" size=\"20\" autocomplete=\"email\" /></label>\n";
		$this->output .= "<label class=\"ghotiField\"><span>Password</span><span class=\"ghotiPasswordInput\"><input type=\"password\" name=\"password\" id=\"registerForm-password\" size=\"20\" autocomplete=\"new-password\" /><button type=\"button\" class=\"ghotiPasswordToggle\" onclick=\"ghotiTogglePassword(this);\" aria-label=\"Show password\" title=\"Show password\">&#128065;</button></span></label>\n";
		$this->output .= "<label class=\"ghotiField\"><span>Password again</span><span class=\"ghotiPasswordInput\"><input type=\"password\" name=\"password1\" id=\"registerForm-password1\" size=\"20\" autocomplete=\"new-password\" /><button type=\"button\" class=\"ghotiPasswordToggle\" onclick=\"ghotiTogglePassword(this);\" aria-label=\"Show password\" title=\"Show password\">&#128065;</button></span></label>\n";
		$this->output .= '<p>We use your username and email to provide your account. Read the <a href="?view=privacy">privacy policy</a> before registering. Optional analytics is a separate choice.</p>';
		$this->output .= loginCaptchaHtml('register','registerForm-captcha');
		$this->output .= "<div class=\"ghotiFormActions\"><button type=\"submit\" class=\"ghotiButton\">Register</button></div>\n";
		$this->output .= "</form><span id=\"loginFeedback\"></span></div>\n";
		return $this->output;
	}

	public function printChangePasswordForm(){
		$this->output = "<form id=\"changePasswordForm\" class=\"ghotiForm\" action=\"#\" onsubmit=\"changePassword(); return false;\">";
		$this->output .= "<label class=\"ghotiField\"><span>Old password</span><span class=\"ghotiPasswordInput\"><input type=\"password\" id=\"chpw-password\" size=\"20\" autocomplete=\"current-password\" /><button type=\"button\" class=\"ghotiPasswordToggle\" onclick=\"ghotiTogglePassword(this);\" aria-label=\"Show password\" title=\"Show password\">&#128065;</button></span></label>";
		$this->output .= "<label class=\"ghotiField\"><span>New password</span><span class=\"ghotiPasswordInput\"><input type=\"password\" id=\"chpw-newPassword1\" size=\"20\" autocomplete=\"new-password\" /><button type=\"button\" class=\"ghotiPasswordToggle\" onclick=\"ghotiTogglePassword(this);\" aria-label=\"Show password\" title=\"Show password\">&#128065;</button></span></label>";
		$this->output .= "<label class=\"ghotiField\"><span>New password again</span><span class=\"ghotiPasswordInput\"><input type=\"password\" id=\"chpw-newPassword2\" size=\"20\" autocomplete=\"new-password\" /><button type=\"button\" class=\"ghotiPasswordToggle\" onclick=\"ghotiTogglePassword(this);\" aria-label=\"Show password\" title=\"Show password\">&#128065;</button></span></label>";
		$this->output .= loginCaptchaHtml('changePassword','chpw-captcha');
		$this->output .= "<div class=\"ghotiFormActions\"><button type=\"submit\" class=\"ghotiButton\">Change Password</button>";
		$this->output .= "<button type=\"button\" class=\"ghotiButton ghotiButtonDanger ghotiMenu\" onclick=\"printDeleteUserDialog();\">Remove Account</button></div></form>";
		return $this->output;
	}

	/* The Sign-in security dialog. $enrolled: this account uses an app now.
	 * $required: two-factor currently applies to this account. */
	public function printSignInSecurity($enrolled, $required){
		$pw = function($id){
			return "<label class=\"ghotiField\"><span>Current password</span><span class=\"ghotiPasswordInput\"><input type=\"password\" id=\"".$id."\" autocomplete=\"current-password\" /><button type=\"button\" class=\"ghotiPasswordToggle\" onclick=\"ghotiTogglePassword(this);\" aria-label=\"Show password\" title=\"Show password\">&#128065;</button></span></label>";
		};
		$o = "<div id=\"ghotiSignInSecurity\" class=\"ghotiForm\">";
		$o .= "<p class=\"ghotiHelpText\">".($required
			? "This site asks for a six-digit code after your password when you sign in."
			: "This site does not currently ask your account for a sign-in code. You can still set up an app now, and it will be used if codes are switched on.")."</p>";
		if($enrolled){
			$o .= "<p><b>You are using an authenticator app.</b> Sign-in codes come from the app, not by e-mail.</p>";
			$o .= "<form action=\"#\" onsubmit=\"totpRemove(); return false;\">".$pw('totpRemovePassword');
			$o .= "<div class=\"ghotiFormActions\"><button type=\"submit\" class=\"ghotiButton ghotiButtonSecondary\">Switch to e-mailed codes</button></div></form>";
		}else{
			$o .= "<p><b>Codes are e-mailed to you.</b> An authenticator app such as Google Authenticator, Microsoft Authenticator, Authy or 1Password works without e-mail and keeps working if mail is down.</p>";
			$o .= "<div class=\"ghotiFormActions\"><button type=\"button\" id=\"totpStartButton\" class=\"ghotiButton\" onclick=\"totpStartEnroll(this);\">Set up an authenticator app</button></div>";
			$o .= "<form id=\"totpEnroll\" action=\"#\" onsubmit=\"totpConfirmEnroll(); return false;\" hidden>";
			$o .= "<p class=\"ghotiHelpText\">1. In your app, add an account and scan this code.</p>";
			$o .= "<div id=\"totpQr\" style=\"width:200px;max-width:100%;background:#fff;padding:4px\"></div>";
			$o .= "<p class=\"ghotiHelpText\">Can&rsquo;t scan? Enter this key instead: <code id=\"totpSecret\" style=\"user-select:all\"></code></p>";
			$o .= "<p class=\"ghotiHelpText\">2. Enter the code the app shows now, and your password, to confirm.</p>";
			$o .= "<label class=\"ghotiField\"><span>Code from the app</span><input type=\"text\" id=\"totpCode\" inputmode=\"numeric\" autocomplete=\"one-time-code\" maxlength=\"7\" spellcheck=\"false\" /></label>";
			$o .= $pw('totpPassword');
			$o .= "<div class=\"ghotiFormActions\"><button type=\"submit\" class=\"ghotiButton\">Confirm and switch to the app</button></div></form>";
		}
		$o .= "<p id=\"totpFeedback\" class=\"ghotiFormError\" role=\"status\" aria-live=\"polite\"></p>";
		$o .= "</div>";
		return $o;
	}

	/*
	 * $postCounts is array(userId => posts) and $moderates array(userId =>
	 * array(board name, ...)); both are empty when the boards module is off,
	 * which hides the board details on each user card.
	 */
	//$totpEnrolled is array(userId => true) for accounts using an authenticator app.
	function printManageUserForm($userList, $postCounts = array(), $moderates = array(), $totpEnrolled = array()){
		$showBoards = class_exists('ghoti') && ghoti::$enableBoards;
		$this->output = "<section id=\"ghotiManageUsers\" class=\"ghotiAdminPanel\"><div class=\"ghotiCrudHeader\"><h1>Manage Users</h1></div>\n";
		$docs = ghoti_docs_panel("How to manage users", "roles, edits, removal, email", array(
			array('heading' => 'Roles',
				'list' => array('The <b>Admin</b> / <b>User</b> button toggles admin rights.', 'The last remaining admin cannot be demoted or deleted.')),
			array('heading' => 'Edit an account',
				'list' => array('Change the username or email and press <b>Save</b>.', 'Usernames and emails must be unique &mdash; an address in use by another account is rejected.')),
			array('heading' => 'Email users',
				'list' => array('Press <b>email</b> beside a user to select them in the composer below the list.', 'For group email, choose <b>All users</b>, <b>Administrators only</b>, or <b>Selected users</b> below the list. Save address changes before composing.')),
			array('heading' => 'Boards',
				'list' => array('<b>Posts</b> counts everything the account has written across every board.', 'Press <b>Moderates</b> to choose which boards the account moderates. A moderator can edit or remove any post on their board, and lock, pin or delete its topics.', 'Administrators moderate every board without being listed.')),
			array('heading' => 'Authenticator apps',
				'list' => array('An account marked <b>Authenticator app</b> signs in with codes from an app instead of e-mailed codes.', 'If its phone is lost, press <b>Reset app</b>: the account gets e-mailed codes until it sets up an app again under <b>Your account &rarr; Sign-in security</b>.')),
			array('heading' => 'Delete an account',
				'list' => array('<b>Delete</b> removes the account and everything it posted. This cannot be undone.'))
		));
		$this->output .= '<div class="ghotiUserList" aria-label="User accounts">';
		$mailDirectory = array();
		foreach($userList as $records => $row){
			$userId = (int)$row[0];
			$mailDirectory[] = array('userId'=>$userId, 'userName'=>$row[1], 'email'=>$row[2], 'admin'=>$row[3]);
			$userName = htmlspecialchars((string)$row[1], ENT_QUOTES);
			$userEmail = htmlspecialchars((string)$row[2], ENT_QUOTES);
			$nameField = "user-".$userId."-name";
			$emailField = "user-".$userId."-email";
			$this->output .= '<article class="ghotiUserCard" aria-label="Account: '.$userName.'"><div class="ghotiUserFields">';
			$this->output .= "<label class=\"ghotiField\"><span>Username</span><input type=\"text\" id=\"".$nameField."\" value=\"".$userName."\" /></label>\n";
			$this->output .= "<label class=\"ghotiField\"><span>Email</span><input type=\"email\" id=\"".$emailField."\" value=\"".$userEmail."\" /></label></div><div class=\"ghotiUserDetails\">\n";
			if($row[3] == 1)
				$this->output .= "<div class=\"ghotiUserRole\"><span class=\"ghotiUserDetailLabel\">Role</span><button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" onclick=\"toggleAdmin('".$userId."');\"><img src=\"gfx/green-check.gif\" alt=\"\" />Admin</button></div>\n";
			else
				$this->output .= "<div class=\"ghotiUserRole\"><span class=\"ghotiUserDetailLabel\">Role</span><button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" onclick=\"toggleAdmin('".$userId."');\"><img src=\"gfx/red-x.gif\" alt=\"\" />User</button></div>\n";
			if($showBoards){
				$posts = isset($postCounts[$userId]) ? (int)$postCounts[$userId] : 0;
				//An admin moderates everything, so listing named boards beside
				//one would suggest the others are out of their reach.
				if($row[3] == 1){
					$moderatesLabel = "All boards";
				}else{
					$boardNames = isset($moderates[$userId]) ? $moderates[$userId] : array();
					$moderatesLabel = $boardNames ? implode(', ', $boardNames) : 'None';
				}
				$this->output .= "<div class=\"ghotiUserPosts\"><span class=\"ghotiUserDetailLabel\">Posts</span><strong>".$posts."</strong></div>\n";
				$this->output .= "<div class=\"ghotiUserBoards\"><span class=\"ghotiUserDetailLabel\">Boards</span><span class=\"ghotiModeratesList\">".htmlspecialchars($moderatesLabel, ENT_QUOTES)."</span> ";
				$this->output .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" onclick=\"ghotiModuleAction('boardsModeratorDialog',".$userId.");\">Moderates</button></div>\n";
			}
			if(!empty($totpEnrolled[$userId])){
				$nameJs = htmlspecialchars(json_encode((string)$row[1]), ENT_QUOTES, 'UTF-8');
				$this->output .= "<div class=\"ghotiUserTwoFactor\"><span class=\"ghotiUserDetailLabel\">Sign-in</span><span>Authenticator app</span> ";
				$this->output .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" onclick=\"adminResetTotp(".$userId.", ".$nameJs.");\">Reset app</button></div>\n";
			}
			$this->output .= "</div>";
			$this->output .= "<div class=\"ghotiFormActions ghotiUserActions\"><button type=\"button\" class=\"ghotiButton ghotiButtonCompact\" onclick=\"saveUser('".$nameField."','".$emailField."','".$userId."');\"><img src=\"gfx/save.png\" alt=\"\" />Save</button>\n";
			$this->output .= '<button type="button" class="ghotiButton ghotiButtonCompact ghotiButtonSecondary" onclick="composeMailToUser('.$userId.');">email</button>';
			$this->output .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonDanger\" onclick=\"deleteUser('".$userId."');\"><img src=\"gfx/delete.png\" alt=\"\" />Delete</button></div>\n";
			$this->output .= "</article>\n";
		}
		$this->output .= "</div>\n";
		$this->output .= ghoti_mail_render_compose($mailDirectory, ghoti_mail_is_enabled(ghoti_mail_mailer()));
		$this->output .= $docs;
		$this->output .= "</section>\n";
		return $this->output;
	}
}
