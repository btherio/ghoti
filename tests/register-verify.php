<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
/*
 * Run: php tests/register-verify.php - no database, no browser, no real mail.
 *
 * E-mail confirmation for new registrations. The claim this whole file exists
 * to protect is one sentence:
 *
 *   With working mail, NO account row is created until the code comes back.
 *
 * Not "created and flagged unverified" - not created at all. That is what makes
 * an unconfirmed address cost nothing and leaves nothing to reap. Around it:
 * the flow falls back to plain registration when mail is not working, it fails
 * closed when a code cannot be sent, it never parks a plaintext password in the
 * session, and it cannot be used to post mail at a stranger's address.
 */
chdir(dirname(__DIR__));
if(session_status() !== PHP_SESSION_ACTIVE){ @session_start(); }
require_once 'ghoti.php';
require_once 'mod/login/login.php';

$checks = 0;
function regCheck($ok, $label){
	global $checks; $checks++;
	if(!$ok){ throw new RuntimeException($label); }
}
ghoti::$ghotiLog = sys_get_temp_dir().'/ghoti-reg-test-'.getmypid().'.log';
ghoti::$allowRegister = true;
unset($_SERVER['REMOTE_ADDR']);

$throttleFile = sys_get_temp_dir().'/ghoti-reg-throttle-'.getmypid().'.json';
function regResetThrottle(){
	global $throttleFile;
	file_put_contents($throttleFile, '{}');
	login_throttle_store(new login_throttle($throttleFile));
}
regResetThrottle();

/* ---------------- fakes ---------------- */

class RegDb{
	public $created = array();     //every account actually written
	public $taken = false;         //checkDuplicate's answer
	public $failInsert = false;
	public $hashCalls = 0;
	public function checkDuplicate($u, $e){ return $this->taken; }
	public function hashPendingPassword($p){ $this->hashCalls++; return '$fake$'.sha1($p); }
	public function addUser($u, $p, $e, $isHash = false){
		if($this->taken || $this->failInsert){ return false; }
		$this->created[] = array('userName'=>$u, 'password'=>$p, 'email'=>$e, 'isHash'=>$isHash);
		return true;
	}
}
class RegMailer{
	public $sent = array();
	public $fail = false;
	public $maildb;
	public function __construct($verified = true){ $this->maildb = new RegMailDb($verified); }
	public function send($to,$n,$s,$b,array $a = array(),$h = null){
		if($this->fail){ return 'SMTP refused'; }
		$this->sent[] = array('to'=>$to,'subject'=>$s,'body'=>$b,'html'=>$h);
		return true;
	}
	public function last(){ return $this->sent ? $this->sent[count($this->sent)-1] : null; }
}
class RegMailDb{
	public $verified;
	public function __construct($v){ $this->verified = $v; }
	public function getSettings(){ return array('enabled'=>true, 'successfulTestAt'=>$this->verified ? time()-60 : 0); }
}

$db = new RegDb();
$_SESSION = array();
$_SESSION['loginObj'] = (new ReflectionClass(login::class))->newInstanceWithoutConstructor();
$_SESSION['loginObj']->logindb = $db;

function regSetMailer($m){ $_SESSION['mailObj'] = $m; $GLOBALS['regMailer'] = $m; return $m; }
function regReset(){
	$keep = $_SESSION['loginObj']; $mail = $_SESSION['mailObj'] ?? null;
	$_SESSION = array('loginObj'=>$keep);
	if($mail){ $_SESSION['mailObj'] = $mail; }
}
function regCode($mailer){
	regCheck(preg_match('/\b(\d{6})\b/', $mailer->last()['body'], $m) === 1, 'No six-digit code in the confirmation e-mail');
	return $m[1];
}

/*
 * Mint a real captcha and return the answer. The registration endpoint verifies
 * one, and it is deliberately checked BEFORE any mail could be sent, so the
 * tests have to satisfy it rather than stub it out - otherwise the "a bot does
 * not get to send mail" assertion below would be testing nothing.
 */
function regFreshCaptcha(){
	loginCaptchaCreate('register');
	return (string)$_SESSION['loginCaptcha']['register']['answer'];
}

foreach(array('verifyRegistration','cancelRegistration') as $fn){
	regCheck(ghoti_async_is_registered($fn), "$fn is not callable from the browser");
}

/* ================= mail not working: unchanged behaviour ================= */

regReset();
regSetMailer(new RegMailer(false)); //configured, never tested
regCheck(login_reg_verification_available() === false, 'Unverified mail offered a verification step');
$answer = regFreshCaptcha();
$r = addUser('plainuser', 'plain@example.test', 'a-good-password', $answer);
regCheck($r === true, 'Registration without working mail did not just work: '.var_export($r, true));
regCheck(count($db->created) === 1, 'The account was not created in the no-mail path');
regCheck($db->created[0]['isHash'] === false, 'The no-mail path passed a pre-hashed password');
regCheck(empty($_SESSION['pendingRegistration']), 'The no-mail path left a pending registration');

/* ================= mail working: nothing is created yet ================= */

$db->created = array();
regReset();
$mailer = regSetMailer(new RegMailer(true));
regCheck(login_reg_verification_available() === true, 'Verified mail did not offer a verification step');
$answer = regFreshCaptcha();
$r = addUser('newcomer', 'newcomer@example.test', 'a-good-password', $answer);

regCheck(login_reg_is_pending($r), 'Registration with working mail did not ask for a code: '.var_export($r, true));
//THE assertion this file exists for.
regCheck($db->created === array(), 'AN ACCOUNT WAS CREATED BEFORE THE CODE WAS CONFIRMED');
regCheck(!empty($_SESSION['pendingRegistration']), 'No pending registration was held');
regCheck($r['email'] === 'newcomer@example.test', 'The marker does not carry the address for the form to show');
//The marker must not read as success or as an error string to the client.
regCheck($r !== true && !is_string($r), 'The pending marker could be mistaken for a result');

//A code went out, themed, to the address that was typed.
regCheck(count($mailer->sent) === 1, 'No confirmation e-mail was sent');
regCheck($mailer->last()['to'] === 'newcomer@example.test', 'The code went to the wrong address');
regCheck(is_string($mailer->last()['html']) && stripos($mailer->last()['html'], '<html') !== false, 'The confirmation e-mail was not themed');
//It must not claim the reader has an account - they do not, that is the point.
regCheck(stripos($mailer->last()['html'], 'you have an account') === false, 'The confirmation e-mail claims the reader has an account');
regCheck(stripos($mailer->last()['body'], 'was not you') !== false, 'The confirmation e-mail does not tell an unexpected recipient they can ignore it');

//No plaintext password anywhere in the pending record.
$pendingJson = json_encode($_SESSION['pendingRegistration']);
regCheck(strpos($pendingJson, 'a-good-password') === false, 'THE PLAINTEXT PASSWORD IS SITTING IN THE SESSION');
regCheck($db->hashCalls === 1, 'The password was not hashed before being held');
$code = regCode($mailer);
regCheck(strpos($pendingJson, $code) === false, 'The code is stored in the session in the clear');

/* ---------------- a wrong code creates nothing ---------------- */

$wrong = $code === '000000' ? '111111' : '000000';
regCheck(verifyRegistration($wrong) === 0, 'A wrong code was not rejected');
regCheck($db->created === array(), 'A wrong code created an account');
regCheck((int)$_SESSION['pendingRegistration']['attempts'] === 1, 'A wrong code was not counted');

/* ---------------- the right code creates it, once ---------------- */

regCheck(verifyRegistration($code) === true, 'The correct code did not create the account');
regCheck(count($db->created) === 1, 'The account was not created exactly once');
regCheck($db->created[0]['userName'] === 'newcomer', 'The wrong username was created');
regCheck($db->created[0]['email'] === 'newcomer@example.test', 'The wrong address was created');
regCheck($db->created[0]['isHash'] === true, 'The verified path did not pass the pre-hashed password');
regCheck($db->created[0]['password'] !== 'a-good-password', 'The password was stored without hashing');
regCheck(empty($_SESSION['pendingRegistration']), 'The pending registration outlived its use');
//Registering is not signing in.
regCheck(empty($_SESSION['loggedIn']), 'Confirming a registration signed the new account in');
//And the code cannot be replayed into a second account.
regCheck(is_string(verifyRegistration($code)), 'A spent code was accepted again');
regCheck(count($db->created) === 1, 'A replayed code created a second account');

/* ---------------- verifying with no pending registration ---------------- */

regReset();
$db->created = array();
$none = verifyRegistration('123456');
regCheck($none === 'Your registration has expired. Start again.', 'A code with no pending registration did not fail closed: '.var_export($none, true));
regCheck($db->created === array(), 'A code with no pending registration created an account');

/* ---------------- expiry ---------------- */

regReset(); regResetThrottle();
$mailer = regSetMailer(new RegMailer(true));
addUser('slowpoke', 'slowpoke@example.test', 'a-good-password', regFreshCaptcha());
$code = regCode($mailer);
$_SESSION['pendingRegistration']['expiresAt'] = time() - 1;
$db->created = array();
regCheck(is_string(verifyRegistration($code)), 'An expired code was accepted');
regCheck($db->created === array(), 'An expired code created an account');
regCheck(empty($_SESSION['pendingRegistration']), 'An expired pending registration was left behind');

/* ---------------- the attempt limit ---------------- */

regReset(); regResetThrottle();
$mailer = regSetMailer(new RegMailer(true));
addUser('fumbler', 'fumbler@example.test', 'a-good-password', regFreshCaptcha());
$code = regCode($mailer);
$wrong = $code === '000000' ? '111111' : '000000';
for($i = 0; $i < LOGIN_REG_MAX_TRIES - 1; $i++){
	regCheck(verifyRegistration($wrong) === 0, "Attempt $i was not a plain rejection");
}
regCheck(is_string(verifyRegistration($wrong)), 'The attempt limit did not end the registration');
regCheck(empty($_SESSION['pendingRegistration']), 'The pending registration survived the attempt limit');
regCheck(is_string(verifyRegistration($code)), 'The real code still worked after the attempt limit');
regCheck($db->created === array(), 'The attempt limit path created an account');

/* ---------------- fail closed when the code cannot be sent ---------------- */

regReset(); regResetThrottle();
$m = regSetMailer(new RegMailer(true)); $m->fail = true;
$db->created = array();
$r = addUser('unsendable', 'unsendable@example.test', 'a-good-password', regFreshCaptcha());
regCheck(is_string($r), 'A failed send did not refuse the registration');
regCheck($db->created === array(), 'A failed send created the account anyway');
regCheck(empty($_SESSION['pendingRegistration']), 'A failed send left a pending registration');

/* ---------------- a duplicate is refused BEFORE any mail goes out ---------------- */

regReset(); regResetThrottle();
$mailer = regSetMailer(new RegMailer(true));
$db->taken = true;
$r = addUser('existing', 'existing@example.test', 'a-good-password', regFreshCaptcha());
regCheck(is_string($r) && stripos($r, 'already registered') !== false, 'A duplicate was not refused');
regCheck($mailer->sent === array(), 'A code was mailed to an address that already has an account');
regCheck(empty($_SESSION['pendingRegistration']), 'A duplicate left a pending registration');
$db->taken = false;

/* ---------------- a bad captcha sends no mail either ---------------- */

regReset(); regResetThrottle();
$mailer = regSetMailer(new RegMailer(true));
regFreshCaptcha(); //a real, live challenge - the wrong ANSWER is what is being tested
$r = addUser('botlike', 'botlike@example.test', 'a-good-password', 'definitely-wrong');
regCheck($r !== true && !login_reg_is_pending($r), 'A wrong captcha was accepted');
regCheck($mailer->sent === array(), 'A wrong captcha still sent a confirmation e-mail');

/* ---------------- registration stays switched off when it is off ---------------- */

regReset(); regResetThrottle();
$mailer = regSetMailer(new RegMailer(true));
ghoti::$allowRegister = false;
$r = addUser('sneaky', 'sneaky@example.test', 'a-good-password', regFreshCaptcha());
regCheck($r === 'Registration is disabled.', 'Registration ran while disabled');
regCheck($mailer->sent === array(), 'A disabled registration still sent mail');
ghoti::$allowRegister = true;

/* ---------------- it cannot be used to post mail at somebody ---------------- */

regReset(); regResetThrottle();
$_SERVER['REMOTE_ADDR'] = '203.0.113.77'; //TEST-NET-3
$mailer = regSetMailer(new RegMailer(true));
$allowed = 0;
for($i = 0; $i < LOGIN_REG_MAX_PER_IP + 3; $i++){
	regReset();
	$r = addUser('flood'.$i, 'victim@example.test', 'a-good-password', regFreshCaptcha());
	if(login_reg_is_pending($r)){ $allowed++; }
}
regCheck($allowed === LOGIN_REG_MAX_PER_IP, "The rate limit let through $allowed sends, expected ".LOGIN_REG_MAX_PER_IP);
regCheck(count($mailer->sent) === LOGIN_REG_MAX_PER_IP, 'More e-mails went out than the limit allows');
//The limiter must not have poisoned the bucket the automatic IP blacklist reads.
regCheck(login_throttle_store()->countWindow('ip:203.0.113.77', 3600) === 0,
	'Registration sends were recorded in the login failure bucket and could trip an IP blacklist');
unset($_SERVER['REMOTE_ADDR']);

/* ---------------- cancelling ---------------- */

regReset(); regResetThrottle();
$mailer = regSetMailer(new RegMailer(true));
addUser('quitter', 'quitter@example.test', 'a-good-password', regFreshCaptcha());
regCheck(!empty($_SESSION['pendingRegistration']), 'Nothing to cancel');
regCheck(cancelRegistration() === true, 'Cancel did not report success');
regCheck(empty($_SESSION['pendingRegistration']), 'Cancel left the pending registration in place');

foreach(array($throttleFile, ghoti::$ghotiLog) as $f){ if(is_file($f)){ @unlink($f); } }
echo "PASS: $checks registration verification assertions\n";
