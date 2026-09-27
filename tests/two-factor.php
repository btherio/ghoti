<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
/*
 * Run: php tests/two-factor.php - no database, no browser, no real mail.
 *
 * Two-factor sign-in codes for administrators. The claims worth protecting:
 *
 *   1. A correct password alone does NOT sign an administrator in while this
 *      is on. Only verifyTwoFactor() sets loggedIn, and only against a
 *      challenge that is present, unexpired, unspent and matching.
 *   2. It cannot be switched on unless outbound mail has been PROVEN to work,
 *      because the code is delivered by e-mail and there is no fallback -
 *      enabling it against an untested server locks everyone out.
 *   3. It fails CLOSED. A code that cannot be sent refuses the login rather
 *      than waving it through; a bypass an attacker can trigger by breaking
 *      mail is not a second factor.
 */
chdir(dirname(__DIR__));
//A real session, so session_regenerate_id() in the sign-in paths behaves as it
//does in a request instead of warning that there is nothing to regenerate.
if(session_status() !== PHP_SESSION_ACTIVE){ @session_start(); }
require_once 'ghoti.php';
require_once 'mod/login/login.php';

$checks = 0;
function tfCheck($ok, $label){
	global $checks; $checks++;
	if(!$ok){ throw new RuntimeException($label); }
}

ghoti::$ghotiLog = sys_get_temp_dir().'/ghoti-2fa-test-'.getmypid().'.log';
//No REMOTE_ADDR in CLI, so the automatic blacklist declines to record anything
//(it requires a valid IP) and the live security state is untouched.
unset($_SERVER['REMOTE_ADDR']);

/* A throttle on a scratch file: a test must not write to the running site's. */
$throttleFile = sys_get_temp_dir().'/ghoti-2fa-throttle-'.getmypid().'.json';
file_put_contents($throttleFile, '{}');
login_throttle_store(new login_throttle($throttleFile));

/* ---------------- fakes ---------------- */

class TfLoginDb{
	public $admins = array(7 => true, 9 => false);
	public $emails = array(7 => 'admin@example.test', 9 => 'member@example.test');
	public function authenticate($user, $pass, &$fingerprint = null){
		$fingerprint = 'fp-'.$user;
		if($pass !== 'correct-horse'){ return false; }
		if($user === 'theadmin'){ return 7; }
		if($user === 'themember'){ return 9; }
		return false;
	}
	public function getUserEmailById($id){ return isset($this->emails[$id]) ? $this->emails[$id] : null; }
	public function getUserNameById($id){ return $id === 7 ? 'theadmin' : 'themember'; }
	public function isAdmin($id){ return !empty($this->admins[$id]); }
	//Authenticator enrollment: none here; tests/two-factor-app.php covers it.
	public function getTotp($id){ return null; }
}
class TfMailer{
	public $sent = array();
	public $fail = false;
	public $maildb;
	public function __construct($verified = true){
		$this->maildb = new TfMailDb($verified);
	}
	public function send($to, $name, $subject, $body, array $attachments = array(), $htmlBody = null){
		if($this->fail){ return 'SMTP refused the message'; }
		$this->sent[] = array('to'=>$to, 'subject'=>$subject, 'body'=>$body, 'html'=>$htmlBody);
		return true;
	}
}
class TfMailDb{
	public $verified;
	public function __construct($verified){ $this->verified = $verified; }
	public function getSettings(){
		return array('enabled' => true, 'successfulTestAt' => $this->verified ? time() - 60 : 0);
	}
}

$logindb = new TfLoginDb();
$_SESSION = array();
$_SESSION['loginObj'] = (new ReflectionClass(login::class))->newInstanceWithoutConstructor();
$_SESSION['loginObj']->logindb = $logindb;

/*
 * No stubs are declared for isAdmin(), ghoti_mail_mailer() or
 * ghoti_password_reset_url() - all three are real functions here, and
 * redeclaring them is a fatal. They are steered instead:
 *   isAdmin()           reads $_SESSION['loginObj']->logindb, which is the fake
 *   ghoti_mail_mailer() returns $_SESSION['mailObj'] when one is set
 *   the site URL        is whatever the real resolver says; unset is fine, the
 *                       renderer treats it as "no link in the footer"
 */
function tfSetMailer($mailer){ $_SESSION['mailObj'] = $mailer; $GLOBALS['tfMailer'] = $mailer; return $mailer; }

function tfReset(){
	$keep = $_SESSION['loginObj'];
	$mail = isset($_SESSION['mailObj']) ? $_SESSION['mailObj'] : null;
	$_SESSION = array('loginObj' => $keep);
	if($mail !== null){ $_SESSION['mailObj'] = $mail; }
}
function tfSignedIn(){ return !empty($_SESSION['loggedIn']) && !empty($_SESSION['userId']); }

//Wrong codes feed the same throttle wrong passwords do (asserted below), so a
//section that has just exhausted the attempt limit would otherwise find the
//NEXT sign-in blocked. Start each section from a clean slate on purpose.
function tfResetThrottle(){
	global $throttleFile;
	file_put_contents($throttleFile, '{}');
	login_throttle_store(new login_throttle($throttleFile));
}

/* ---------------- endpoints are registered ---------------- */

foreach(array('verifyTwoFactor','cancelTwoFactor') as $fn){
	tfCheck(ghoti_async_is_registered($fn), "$fn is not callable from the browser");
}

/* ---------------- the switch cannot be turned on blind ---------------- */

$realSettingsFile = ghoti::$settingsFile;
ghoti::$settingsFile = 'ghoti.settings.2fa-test-'.getmypid().'.json';
$restore = ghoti::currentSettings();
try{
	ghoti::$enableTwoFactor = false;
	tfSetMailer(new TfMailer(false)); //mail configured but never tested
	tfCheck(ghoti_mail_verified() === false, 'An untested mail server reported itself as verified');
	$refusal = ghoti::saveSettings(array('enableTwoFactor' => 1));
	tfCheck(is_string($refusal), 'Two-factor was enabled without a successful mail test');
	tfCheck(stripos($refusal, 'test') !== false, 'The refusal does not say a mail test is needed: '.$refusal);
	tfCheck(ghoti::$enableTwoFactor === false, 'The setting changed despite the refusal');

	tfSetMailer(new TfMailer(true)); //a test message has now landed
	tfCheck(ghoti_mail_verified() === true, 'A tested mail server did not report itself as verified');
	tfCheck(ghoti::saveSettings(array('enableTwoFactor' => 1)) === true, 'Two-factor could not be enabled with verified mail');
	ghoti::$enableTwoFactor = false;
	ghoti::loadSettings();
	tfCheck(ghoti::$enableTwoFactor === true, 'The setting did not survive a save and reload');
	//Turning it back OFF is never gated - that is the way out.
	tfCheck(ghoti::saveSettings(array('enableTwoFactor' => 0)) === true, 'Two-factor could not be switched back off');

	//Widening to members is gated the same way, and is refused outright while
	//the feature itself is off - a setting that silently does nothing is worse
	//than one that says no.
	ghoti::$enableTwoFactor = false;
	ghoti::$twoFactorAllUsers = false;
	$orphan = ghoti::saveSettings(array('twoFactorAllUsers' => 1));
	tfCheck(is_string($orphan), 'Member codes were enabled while two-factor itself was off');
	tfCheck(ghoti::$twoFactorAllUsers === false, 'The member scope changed despite the refusal');
	//Both together is the supported way to turn it on.
	tfCheck(ghoti::saveSettings(array('enableTwoFactor' => 1, 'twoFactorAllUsers' => 1)) === true, 'Both switches could not be enabled together');
	tfCheck(ghoti::$twoFactorAllUsers === true, 'The member scope did not stick');
	//Unverified mail blocks widening, just as it blocks enabling.
	ghoti::saveSettings(array('enableTwoFactor' => 1, 'twoFactorAllUsers' => 0));
	tfSetMailer(new TfMailer(false));
	$blocked = ghoti::saveSettings(array('enableTwoFactor' => 1, 'twoFactorAllUsers' => 1));
	tfCheck(is_string($blocked), 'The member scope was widened with unverified mail');
	tfSetMailer(new TfMailer(true));
	ghoti::saveSettings(array('enableTwoFactor' => 0, 'twoFactorAllUsers' => 0));
}finally{
	if(is_file(ghoti::settingsPath())){ unlink(ghoti::settingsPath()); }
	ghoti::$settingsFile = $realSettingsFile;
	foreach($restore as $k => $v){ ghoti::$$k = $v; }
}

/* ---------------- off: nothing changes ---------------- */

ghoti::$enableTwoFactor = false;
tfReset();
tfCheck(login('theadmin','correct-horse') === 7, 'An admin could not sign in with two-factor off');
tfCheck(tfSignedIn(), 'The session was not established with two-factor off');
tfCheck(empty($_SESSION['pending2fa']), 'A challenge was created with two-factor off');

/* ---------------- on: a password is no longer enough ---------------- */

ghoti::$enableTwoFactor = true;
tfReset();
tfSetMailer(new TfMailer(true));
$result = login('theadmin','correct-horse');
tfCheck(login_2fa_is_pending($result), 'A correct admin password did not ask for a code: '.var_export($result, true));
tfCheck(!tfSignedIn(), 'THE session was established before the code was entered');
tfCheck(!empty($_SESSION['pending2fa']), 'No challenge was stored');
tfCheck($_SESSION['pending2fa']['userId'] === 7, 'The challenge is for the wrong account');
//The marker must not be mistaken by the client for a user id or a message.
tfCheck(!is_int($result) && !is_string($result), 'The pending marker could be read as an id or an error string');

//The code was mailed, themed, and is not sitting in the session in the clear.
$sent = $GLOBALS['tfMailer']->sent;
tfCheck(count($sent) === 1, 'The sign-in code was not e-mailed');
tfCheck($sent[0]['to'] === 'admin@example.test', 'The code went to the wrong address');
tfCheck($sent[0]['html'] !== null && strpos($sent[0]['html'], '<html') !== false, 'The code e-mail was not themed HTML');
tfCheck(preg_match('/\b(\d{6})\b/', $sent[0]['body'], $m) === 1, 'No six-digit code in the message');
$code = $m[1];
tfCheck(strpos(json_encode($_SESSION['pending2fa']), $code) === false, 'The code is stored in the session in the clear');

/* ---------------- a wrong code ---------------- */

tfCheck(verifyTwoFactor('000000') === 0 || verifyTwoFactor($code === '000000' ? '111111' : '000000') === 0, 'A wrong code was not rejected');
tfCheck(!tfSignedIn(), 'A wrong code signed the account in');
tfCheck((int)$_SESSION['pending2fa']['attempts'] > 0, 'A wrong code was not counted');
//A six-digit code is a small space, so a wrong one must cost the same as a
//wrong password - otherwise it can be guessed at network speed.
tfCheck(login_throttle_store()->countWindow('user:theadmin', 600) > 0, 'A wrong code was not recorded in the login throttle');

/* ---------------- the right code ---------------- */

tfCheck(verifyTwoFactor($code) === 7, 'The correct code did not complete the sign-in');
tfCheck(tfSignedIn() && (int)$_SESSION['userId'] === 7, 'The session was not established after a correct code');
tfCheck($_SESSION['admin'] === true, 'The admin flag was not set');
tfCheck(empty($_SESSION['pending2fa']), 'The challenge outlived its use');
//...and it cannot be replayed.
$replay = verifyTwoFactor($code);
tfCheck(is_string($replay), 'A spent code was accepted a second time');

/* ---------------- verifying with no challenge fails closed ---------------- */

tfReset();
$noChallenge = verifyTwoFactor('123456');
tfCheck(is_string($noChallenge), 'A code with no challenge did not fail closed');
tfCheck(!tfSignedIn(), 'A code with no challenge established a session');
//Pin the SPECIFIC refusal, not just "some string". Without this the assertion
//above is satisfied by the expiry check further down catching a null challenge
//by accident, and removing the no-challenge guard entirely would go unnoticed.
tfCheck($noChallenge === 'Your sign-in attempt has expired. Start again.',
	'The no-challenge refusal is not the one the guard raises: '.$noChallenge);
tfCheck(empty($_SESSION['pending2fa']), 'Verifying with no challenge created one');

/* ---------------- too many wrong codes end the attempt ---------------- */

tfReset();
login('theadmin','correct-horse');
$real = null;
preg_match('/\b(\d{6})\b/', end($GLOBALS['tfMailer']->sent)['body'], $m2); $real = $m2[1];
$wrong = $real === '000000' ? '111111' : '000000';
for($i = 0; $i < LOGIN_2FA_MAX_TRIES - 1; $i++){
	tfCheck(verifyTwoFactor($wrong) === 0, "Attempt $i was not counted as a plain wrong code");
}
$final = verifyTwoFactor($wrong);
tfCheck(is_string($final), 'The attempt did not end after the limit');
tfCheck(empty($_SESSION['pending2fa']), 'The challenge survived the attempt limit');
tfCheck(!tfSignedIn(), 'Exhausting the attempts signed the account in');
//...and the real code is now worthless too.
tfCheck(is_string(verifyTwoFactor($real)), 'The real code still worked after the attempt limit');

/* ---------------- an expired code ---------------- */

tfResetThrottle();
tfReset();
login('theadmin','correct-horse');
$_SESSION['pending2fa']['expiresAt'] = time() - 1;
preg_match('/\b(\d{6})\b/', end($GLOBALS['tfMailer']->sent)['body'], $m3);
tfCheck(is_string(verifyTwoFactor($m3[1])), 'An expired code was accepted');
tfCheck(!tfSignedIn(), 'An expired code established a session');
tfCheck(empty($_SESSION['pending2fa']), 'An expired challenge was left behind');

/* ---------------- scope: administrators only (the default) ---------------- */

ghoti::$twoFactorAllUsers = false;
tfResetThrottle();
tfReset();
tfCheck(login('themember','correct-horse') === 9, 'A member could not sign in');
tfCheck(tfSignedIn(), 'A member was asked for a code while the scope is administrators only');
tfCheck(empty($_SESSION['pending2fa']), 'A challenge was created for a member');

/* ---------------- scope: every member too ---------------- */

ghoti::$twoFactorAllUsers = true;
tfResetThrottle();
tfReset();
$memberResult = login('themember','correct-horse');
tfCheck(login_2fa_is_pending($memberResult), 'A member was not asked for a code with the wider scope on');
tfCheck(!tfSignedIn(), 'A member was signed in before entering a code');
tfCheck($_SESSION['pending2fa']['userId'] === 9, 'The member challenge is for the wrong account');
$memberMail = end($GLOBALS['tfMailer']->sent);
tfCheck($memberMail['to'] === 'member@example.test', 'The member code went to the wrong address');
preg_match('/\b(\d{6})\b/', $memberMail['body'], $mm);
tfCheck(verifyTwoFactor($mm[1]) === 9, 'A member could not complete the second factor');
tfCheck(tfSignedIn() && (int)$_SESSION['userId'] === 9, 'The member session was not established');
tfCheck($_SESSION['admin'] === false, 'A member was signed in as an administrator');

//A member with no address is refused, exactly as an administrator would be -
//the wider scope must not quietly exempt whoever it cannot reach.
tfResetThrottle();
tfReset();
$logindb->emails[9] = '';
$refusedMember = login('themember','correct-horse');
tfCheck(is_string($refusedMember), 'A member with no e-mail address bypassed the second factor');
tfCheck(!tfSignedIn(), 'A member with no e-mail address was signed in anyway');
$logindb->emails[9] = 'member@example.test';

//Administrators are still in scope regardless of this switch.
ghoti::$twoFactorAllUsers = false;
tfResetThrottle();
tfReset();
tfCheck(login_2fa_is_pending(login('theadmin','correct-horse')), 'Narrowing the scope let an administrator skip the code');
ghoti::$twoFactorAllUsers = false;

/* ---------------- fail closed ---------------- */

tfResetThrottle();

//The code cannot be sent: the login is refused, not waved through.
tfReset();
tfSetMailer(new TfMailer(true))->fail = true;
$refused = login('theadmin','correct-horse');
tfCheck(is_string($refused), 'A failed code send did not refuse the login');
tfCheck(!tfSignedIn(), 'A failed code send signed the administrator in anyway');
tfCheck(empty($_SESSION['pending2fa']), 'A challenge was left behind after a failed send');

//Mail is no longer verified at all: same answer.
tfReset();
tfSetMailer(new TfMailer(false));
$refused = login('theadmin','correct-horse');
tfCheck(is_string($refused), 'Unverified mail did not refuse the login');
tfCheck(!tfSignedIn(), 'Unverified mail signed the administrator in anyway');

//An administrator with no address on file cannot receive a code, and is not
//silently exempted from the second factor either.
tfReset();
tfSetMailer(new TfMailer(true));
$logindb->emails[7] = '';
$refused = login('theadmin','correct-horse');
tfCheck(is_string($refused), 'An admin with no e-mail address was not refused');
tfCheck(!tfSignedIn(), 'An admin with no e-mail address bypassed the second factor');
$logindb->emails[7] = 'admin@example.test';

/* ---------------- a wrong password never reaches any of this ---------------- */

tfResetThrottle();
tfReset();
tfCheck(login('theadmin','wrong') === false, 'A wrong password was accepted');
tfCheck(empty($_SESSION['pending2fa']), 'A wrong password created a challenge');
tfCheck(!tfSignedIn(), 'A wrong password established a session');

/* ---------------- cancelling clears the challenge ---------------- */

tfResetThrottle();
tfReset();
login('theadmin','correct-horse');
tfCheck(!empty($_SESSION['pending2fa']), 'No challenge to cancel');
tfCheck(cancelTwoFactor() === true, 'Cancel did not report success');
tfCheck(empty($_SESSION['pending2fa']), 'Cancel left the challenge in place');

/* ---------------- the session cannot be elevated while pending ---------------- */

tfResetThrottle();
tfReset();
login('theadmin','correct-horse');
tfCheck(setSessionVars(7) === false, 'setSessionVars elevated a session that had not passed the second factor');
tfCheck(!tfSignedIn(), 'setSessionVars established a session mid-challenge');

/* ---------------- codes keep a leading zero ---------------- */

$sawPadded = false;
for($i = 0; $i < 400; $i++){
	$c = login_2fa_generate_code();
	tfCheck(strlen($c) === 6 && ctype_digit($c), "Generated code is not six digits: $c");
	if($c[0] === '0'){ $sawPadded = true; }
}
tfCheck($sawPadded, 'No code with a leading zero in 400 draws; padding is probably being dropped');

foreach(array($throttleFile, ghoti::$ghotiLog) as $f){ if(is_file($f)){ unlink($f); } }
echo "PASS: $checks two-factor assertions\n";
