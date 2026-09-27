<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
/*
 * Run: php tests/two-factor-app.php - no database, no browser, no real mail.
 *
 * Authenticator-app sign-in codes (TOTP). What must hold:
 *   - the codes are the RFC 6238 ones every authenticator app produces,
 *   - an enrolled account is asked for the app code and sent NO e-mail,
 *   - a code signs in once: the same code cannot be replayed,
 *   - an unreadable enrollment refuses sign-in rather than falling back to
 *     e-mail (an attacker must not be able to choose the weaker factor),
 *   - enrolling needs a correct app code AND the account password,
 *   - leaving the app is refused when e-mailed codes could not reach the user,
 *   - two-factor can be switched on without tested mail only when every
 *     administrator already uses an app.
 */
chdir(dirname(__DIR__));
if(session_status() !== PHP_SESSION_ACTIVE){ @session_start(); }
require_once 'ghoti.php';
require_once 'mod/login/login.php';

$checks = 0;
function appCheck($ok, $label){
	global $checks; $checks++;
	if(!$ok){ throw new RuntimeException($label); }
}

ghoti::$ghotiLog = sys_get_temp_dir().'/ghoti-2fa-app-test-'.getmypid().'.log';
unset($_SERVER['REMOTE_ADDR']);
$throttleFile = sys_get_temp_dir().'/ghoti-2fa-app-throttle-'.getmypid().'.json';
file_put_contents($throttleFile, '{}');
login_throttle_store(new login_throttle($throttleFile));
register_shutdown_function(function() use ($throttleFile){ @unlink($throttleFile); @unlink(ghoti::$ghotiLog); });

/* ---------------- RFC 6238 test vectors (SHA-1, last six digits) ---------------- */

$rfcSecret = ghoti_base32_encode('12345678901234567890');
appCheck($rfcSecret === 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'base32 encoding is wrong');
appCheck(ghoti_base32_decode($rfcSecret) === '12345678901234567890', 'base32 does not round-trip');
foreach(array(59 => '287082', 1111111109 => '081804', 1234567890 => '005924', 2000000000 => '279037') as $time => $expected){
	appCheck(ghoti_totp_code($rfcSecret, ghoti_totp_step($time)) === $expected, "RFC 6238 vector at $time failed");
}
appCheck(ghoti_totp_verify($rfcSecret, '287082', 59) === 1, 'a current code was refused');
appCheck(ghoti_totp_verify($rfcSecret, '287 082', 59) === 1, 'a spaced code was refused');
appCheck(ghoti_totp_verify($rfcSecret, '287082', 59 + 30) === 1, 'one step of clock drift was refused');
appCheck(ghoti_totp_verify($rfcSecret, '287082', 59 + 90) === false, 'a code three steps old was accepted');
appCheck(ghoti_totp_verify($rfcSecret, '287082', 59, 1) === false, 'a spent step was accepted again');
appCheck(ghoti_totp_verify($rfcSecret, '000000', 59) === false, 'a wrong code was accepted');
appCheck(strlen(ghoti_totp_new_secret()) === 32 && ghoti_totp_new_secret() !== ghoti_totp_new_secret(), 'new secrets are not 160-bit and random');
$uri = ghoti_totp_uri('ABC', 'the admin', 'My: Site');
appCheck(strpos($uri, 'otpauth://totp/My%20Site:the%20admin?secret=ABC&issuer=My%20Site') === 0, 'the otpauth URI is malformed: '.$uri);

/* ---------------- fakes ---------------- */

class AppLoginDb{
	public $admins = array(7 => true, 8 => true, 9 => false);
	public $names = array(7 => 'theadmin', 8 => 'otheradmin', 9 => 'themember');
	public $totp = array();       //userId => array(secret, lastStep)
	public $totpFails = false;
	public function authenticate($user, $pass, &$fingerprint = null){
		$fingerprint = 'fp-'.$user;
		if($pass !== 'correct-horse'){ return false; }
		$id = array_search($user, $this->names, true);
		return $id === false ? false : $id;
	}
	public function getUserEmailById($id){ return $this->names[$id].'@example.test'; }
	public function getUserNameById($id){ return $this->names[$id] ?? ''; }
	public function isAdmin($id){ return !empty($this->admins[$id]); }
	public function getTotp($id){
		if($this->totpFails){ return false; }
		return isset($this->totp[$id]) ? array('secret' => $this->totp[$id][0], 'lastStep' => $this->totp[$id][1]) : null;
	}
	public function saveTotp($id, $secret, $step){ $this->totp[$id] = array($secret, $step); return true; }
	public function spendTotpStep($id, $step){
		if(!isset($this->totp[$id]) || $this->totp[$id][1] >= $step){ return false; }
		$this->totp[$id][1] = $step;
		return true;
	}
	public function deleteTotp($id){ unset($this->totp[$id]); return true; }
	public function adminsWithoutTotp(){
		$out = array();
		foreach($this->admins as $id => $admin){ if($admin && !isset($this->totp[$id])){ $out[] = $id; } }
		return $out;
	}
}
class AppMailer{
	public $sent = array();
	public $maildb;
	public function __construct($verified){ $this->maildb = new AppMailDb($verified); }
	public function send($to, $name, $subject, $body, array $attachments = array(), $htmlBody = null){
		$this->sent[] = $subject;
		return true;
	}
}
class AppMailDb{
	public $verified;
	public function __construct($verified){ $this->verified = $verified; }
	public function getSettings(){ return array('enabled' => true, 'fromAddress' => 'site@example.test', 'successfulTestAt' => $this->verified ? time() - 60 : 0); }
}

$db = new AppLoginDb();
$mailer = new AppMailer(true);
function appReset(){
	global $db, $mailer;
	$login = (new ReflectionClass(login::class))->newInstanceWithoutConstructor();
	$login->logindb = $db;
	$login->loginui = new loginui();
	$_SESSION = array('loginObj' => $login, 'mailObj' => $mailer);
}
function appSignIn($id){ $_SESSION['loggedIn'] = true; $_SESSION['userId'] = $id; }

foreach(array('printSignInSecurity','totpBeginEnroll','totpConfirmEnroll','totpRemove','adminResetTotp') as $fn){
	appCheck(ghoti_async_is_registered($fn), "$fn is not callable from the browser");
}

/* ---------------- sign-in with an enrolled app ---------------- */

$restore = ghoti::currentSettings();
ghoti::$enableTwoFactor = true;
ghoti::$twoFactorAllUsers = false;
$secret = ghoti_totp_new_secret();
$db->totp[7] = array($secret, 0);

appReset();
$marker = login('theadmin', 'correct-horse');
appCheck(is_array($marker) && $marker['twoFactor'] === 'required' && $marker['method'] === 'app', 'an enrolled admin was not asked for an app code');
appCheck(!$mailer->sent, 'an e-mail was sent to an account that uses an app');
appCheck(empty($_SESSION['loggedIn']), 'the password alone signed an app user in');
appCheck(!isset($_SESSION['pending2fa']['codeHash']), 'an app challenge carries an e-mail code');

appCheck(verifyTwoFactor('000000') === 0 && empty($_SESSION['loggedIn']), 'a wrong app code signed in');
$code = ghoti_totp_code($secret, ghoti_totp_step());
appCheck(verifyTwoFactor($code) === 7 && !empty($_SESSION['loggedIn']), 'the right app code did not sign in');

//Replay: sign in again with the same code.
appReset();
file_put_contents($throttleFile, '{}');
login_throttle_store(new login_throttle($throttleFile));
login('theadmin', 'correct-horse');
appCheck(verifyTwoFactor($code) === 0 && empty($_SESSION['loggedIn']), 'the same app code signed in twice');

//An unreadable enrollment refuses; it does not fall back to e-mail.
appReset();
$db->totpFails = true;
$refused = login('theadmin', 'correct-horse');
appCheck(is_string($refused) && empty($_SESSION['pending2fa']) && !$mailer->sent, 'an unreadable enrollment fell back to e-mail');
$db->totpFails = false;

//Removed between password and code: fail closed.
appReset();
login('theadmin', 'correct-horse');
$saved = $db->totp[7];
unset($db->totp[7]);
appCheck(verifyTwoFactor(ghoti_totp_code($secret, ghoti_totp_step())) === 0, 'a code was accepted after the app was removed');
$db->totp[7] = $saved;

//An un-enrolled admin still gets an e-mail, with the code first in the subject.
appReset();
$mailer->sent = array();
$marker = login('otheradmin', 'correct-horse');
appCheck(is_array($marker) && $marker['method'] === 'email' && count($mailer->sent) === 1, 'an un-enrolled admin was not e-mailed');
appCheck(preg_match('/^\d{6} is your /', $mailer->sent[0]) === 1, 'the e-mailed code is not in the subject: '.$mailer->sent[0]);

/* ---------------- enrolling ---------------- */

appReset();
appCheck(totpBeginEnroll()['success'] === false, 'enrollment began signed out');
appCheck(is_string(totpConfirmEnroll('123456', 'correct-horse')), 'enrollment confirmed signed out');
appSignIn(8);
$begin = totpBeginEnroll();
appCheck($begin['success'] === true && strpos($begin['uri'], 'otpauth://totp/') === 0, 'enrollment did not start');
appCheck(!isset($db->totp[8]), 'enrollment saved before it was confirmed');
$enrollStep = ghoti_totp_step();
$now = ghoti_totp_code($begin['secret'], $enrollStep);
appCheck(is_string(totpConfirmEnroll($now, 'wrong-password')) && !isset($db->totp[8]), 'a wrong password confirmed enrollment');
appCheck(is_string(totpConfirmEnroll('000000', 'correct-horse')) && !isset($db->totp[8]), 'a wrong code confirmed enrollment');
appCheck(totpConfirmEnroll($now, 'correct-horse') === true && $db->totp[8][0] === $begin['secret'], 'a correct code and password did not enroll');
appCheck($db->totp[8][1] === $enrollStep, 'the enrolling code was not marked spent');
appCheck(strpos(printSignInSecurity(), 'You are using an authenticator app') !== false, 'the dialog does not show the enrollment');

/* ---------------- leaving the app ---------------- */

tfLikeMailer(false);
function tfLikeMailer($verified){ global $mailer; $mailer = new AppMailer($verified); $_SESSION['mailObj'] = $mailer; }
$blocked = totpRemove('correct-horse');
appCheck(is_string($blocked) && isset($db->totp[8]), 'an admin left the app for e-mail that has never been tested');
tfLikeMailer(true);
appCheck(is_string(totpRemove('wrong-password')) && isset($db->totp[8]), 'the app was removed with a wrong password');
appCheck(totpRemove('correct-horse') === true && !isset($db->totp[8]), 'the app could not be removed');

/* ---------------- admin reset ---------------- */

$db->totp[9] = array(ghoti_totp_new_secret(), 0);
appReset();
appSignIn(9);
appCheck(is_string(adminResetTotp(9)) && isset($db->totp[9]), 'a member reset an authenticator');
appSignIn(7);
ghoti::$twoFactorAllUsers = true;
tfLikeMailer(false);
appCheck(is_string(adminResetTotp(9)) && isset($db->totp[9]), 'a reset stranded an account on untested mail');
tfLikeMailer(true);
ghoti::$twoFactorAllUsers = false;
appCheck(adminResetTotp(9) === true && !isset($db->totp[9]), 'an admin could not reset an authenticator');
$db->totp[9] = array(ghoti_totp_new_secret(), 0);
$html = $_SESSION['loginObj']->loginui->printManageUserForm(array(array(9, 'themember', 'm@example.test', 0)), array(), array(), array(9 => true));
appCheck(strpos($html, 'adminResetTotp(9') !== false, 'Manage Users has no reset for an enrolled account');

/* ---------------- enabling without tested mail ---------------- */

$realSettingsFile = ghoti::$settingsFile;
ghoti::$settingsFile = 'ghoti.settings.2fa-app-test-'.getmypid().'.json';
try{
	ghoti::$enableTwoFactor = false;
	ghoti::$twoFactorAllUsers = false;
	appReset();
	tfLikeMailer(false);
	$db->totp = array(7 => array('A', 0)); //admin 8 has no app
	appCheck(is_string(ghoti::saveSettings(array('enableTwoFactor' => 1))), 'two-factor was enabled with untested mail while an admin had no app');
	$db->totp[8] = array('B', 0);
	appCheck(ghoti::saveSettings(array('enableTwoFactor' => 1)) === true, 'two-factor could not be enabled with every admin on an app');
	appCheck(is_string(ghoti::saveSettings(array('enableTwoFactor' => 1, 'twoFactorAllUsers' => 1))), 'member codes were enabled with untested mail');
}finally{
	if(is_file(ghoti::settingsPath())){ unlink(ghoti::settingsPath()); }
	ghoti::$settingsFile = $realSettingsFile;
	foreach($restore as $k => $v){ ghoti::$$k = $v; }
}

/* ---------------- the browser side ---------------- */

$js = file_get_contents('mod/login/login.js');
appCheck(strpos($js, 'loginShowTwoFactorForm(id.method)') !== false, 'the code prompt ignores the method');
appCheck(strpos($js, 'lib/vendor/qrcode.js') !== false && is_file('lib/vendor/qrcode.js'), 'the QR code is not drawn locally');
appCheck(stripos($js, 'chart.googleapis') === false && stripos($js, 'api.qrserver') === false, 'the secret is sent to a QR image service');
appCheck(strpos($_SESSION['loginObj']->loginui->printSystemMenu(), 'printSignInSecurity()') !== false, 'the account menu has no Sign-in security item');

echo "PASS: $checks authenticator-app assertions\n";
