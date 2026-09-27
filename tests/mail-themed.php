<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
/*
 * Run: php tests/mail-themed.php - no database, no browser, no real mail.
 *
 * Every message this site sends must be themed: an HTML part drawn in the
 * ACTIVE theme's colours, with a plain-text alternative beside it. Before this
 * was true, most senders emitted bare plain text and the one HTML template that
 * existed had the ghoticms accent (#78ffc8) hardcoded, so it came out wrong on
 * every other theme.
 *
 * Two halves, because neither alone is enough:
 *
 *   PART A - structural. Every `->send(` call site in the tree is accounted
 *            for. A NEW sender that forgets the HTML part fails this test
 *            rather than quietly shipping plain text, which is the whole
 *            failure mode being guarded against.
 *
 *   PART B - behavioural. Each sender that can be driven is actually driven
 *            through a recording mailer, and what it produced is inspected:
 *            a real HTML part, in the theme's colours, with matching text.
 */
chdir(dirname(__DIR__));
//A real session, so the two-factor path's session_regenerate_id() behaves as it
//does in a request rather than warning there is nothing to regenerate.
if(session_status() !== PHP_SESSION_ACTIVE){ @session_start(); }
require_once 'ghoti.php';

$checks = 0;
function themeCheck($ok, $label){
	global $checks; $checks++;
	if(!$ok){ throw new RuntimeException($label); }
}

/* ================================================================== *
 *  PART A - every send() call site in the codebase is accounted for
 * ================================================================== */

/*
 * Each entry: 'file:line-ish marker' => why it is allowed to be what it is.
 *   'themed'    - passes an HTML part (directly or via the shared helper)
 *   'plumbing'  - the transport itself, or the shared helper; not a sender
 *   'fallback'  - the else-branch of a function_exists() guard on the helper,
 *                 reached only if ghoti.mail.php failed to load
 *   'not-email' - an HTTP client that happens to have a send() method
 */
$expected = array(
	'ghoti.mail.php'             => array('plumbing' => 2),  //the helper, and the bulk sender
	'mod/mail/mail.php'          => array('plumbing' => 1),  //mail::send -> MailSmtpClient
	'ghoti.alerts.php'           => array('fallback' => 1),
	'mod/vhosts/vhosts.notify.php' => array('fallback' => 1),
	'mod/store/store.paypal.php' => array('not-email' => 2),
	'mod/store/store.dropship.php' => array('not-email' => 1),
);

$sendSites = array();
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.', FilesystemIterator::SKIP_DOTS));
foreach($iterator as $file){
	$path = ltrim(str_replace('\\', '/', $file->getPathname()), './');
	if(substr($path, -4) !== '.php'){ continue; }
	if(strpos($path, '.git/') === 0 || strpos($path, 'tests/') === 0 || strpos($path, 'lib/') === 0){ continue; }
	$lines = @file($path);
	if(!is_array($lines)){ continue; } //unreadable, or a directory named *.php
	foreach($lines as $n => $line){
		if(preg_match('/->send\s*\(/', $line)){
			$sendSites[] = array('file' => $path, 'line' => $n + 1, 'code' => trim($line));
		}
	}
}
themeCheck(count($sendSites) > 0, 'Found no send() call sites at all; the scan is broken');

$byFile = array();
foreach($sendSites as $site){ $byFile[$site['file']][] = $site; }

foreach($byFile as $path => $sites){
	themeCheck(isset($expected[$path]),
		"Unreviewed sender: $path line ".$sites[0]['line']." calls send() and is not in this test's list. "
		."If it sends e-mail it must go through ghoti_mail_send_themed(); then add it here.");
	$allowed = array_sum($expected[$path]);
	themeCheck(count($sites) === $allowed,
		"$path has ".count($sites)." send() calls but ".$allowed." are accounted for - a new one needs reviewing");
}
//And nothing in the expectation list has silently disappeared.
foreach($expected as $path => $_){
	themeCheck(isset($byFile[$path]), "$path no longer calls send(); update this test");
}

/* The senders that must be routed through the shared themed helper. */
$themedSenders = array(
	'mod/mail/mail.async.php'      => 'the Mail Settings test message',
	'ghoti.alerts.php'             => 'critical log alerts',
	'mod/vhosts/vhosts.notify.php' => 'vhost and certificate notices',
	'password-reset.php'           => 'the password reset link',
	'mod/store/store.async.php'    => 'order receipts and the admin order notice',
	'ghoti.backup.delivery.php'    => 'emailed backups',
	'mod/login/login.async.php'    => 'two-factor sign-in codes and registration confirmations',
);
foreach($themedSenders as $path => $what){
	$src = file_get_contents($path);
	themeCheck(strpos($src, 'ghoti_mail_send_themed') !== false,
		"$path ($what) does not use ghoti_mail_send_themed()");
}
//The admin composer builds its pair directly (it renders the admin's own text).
$composer = file_get_contents('ghoti.mail.php');
themeCheck(strpos($composer, 'ghoti_mail_render_html($siteTitle, $subject, $message, ghoti_mail_palette()') !== false,
	'The admin composer no longer renders a themed HTML part');

/* The template with the hardcoded accent must stay gone. */
themeCheck(!function_exists('ghoti_backup_email_html'), 'The hardcoded-accent backup template is back');
foreach(array('ghoti.backup.delivery.php','ghoti.mail.php','mod/mail/mail.async.php','mod/login/login.async.php') as $path){
	themeCheck(stripos(file_get_contents($path), '78ffc8') === false,
		"$path hardcodes the ghoticms accent; the palette must come from the active theme");
}

/* ================================================================== *
 *  PART B - drive each sender and inspect what it produced
 * ================================================================== */

class ThemedRecorder{
	public $sent = array();
	public $maildb;
	public function __construct(){ $this->maildb = new ThemedRecorderDb(); }
	public function send($to, $name, $subject, $body, array $attachments = array(), $htmlBody = null){
		$this->sent[] = array('to'=>$to,'name'=>$name,'subject'=>$subject,'body'=>$body,'html'=>$htmlBody,'attachments'=>$attachments);
		return true;
	}
	public function last(){ return $this->sent ? $this->sent[count($this->sent)-1] : null; }
}
class ThemedRecorderDb{
	public function getSettings(){ return array('enabled'=>true, 'successfulTestAt'=>time()-60, 'fromAddress'=>'site@example.test'); }
	public function recordSuccessfulTest(){ return true; }
}

//Pin a known theme so the assertions can name real colours. ghoticms ships
//with the tree, and tests/admin-mail.php already asserts its accent is #78ffc8.
ghoti::$defaultTheme = 'ghoticms';
ghoti::$siteTitle = 'Ghoti Test Site';
$palette = ghoti_mail_palette();
themeCheck($palette['accent'] === '#78ffc8', 'The ghoticms palette did not resolve; the theme assertions below would be vacuous');

/*
 * What "themed" means, asserted the same way for every sender: a real HTML
 * document, carrying the theme's own colours, with a plain-text part that is
 * not merely a copy of the markup.
 */
function assertThemed($message, $label, array $palette){
	themeCheck(is_array($message), "$label: nothing was sent");
	themeCheck(is_string($message['html']) && $message['html'] !== '', "$label: no HTML part");
	themeCheck(stripos($message['html'], '<html') !== false, "$label: the HTML part is not a document");
	themeCheck(strpos($message['html'], $palette['accent']) !== false,
		"$label: the HTML part does not carry the theme accent ".$palette['accent']);
	themeCheck(strpos($message['html'], $palette['surface']) !== false,
		"$label: the HTML part does not carry the theme surface colour");
	themeCheck(is_string($message['body']) && trim($message['body']) !== '', "$label: no plain-text part");
	themeCheck(stripos($message['body'], '<html') === false, "$label: the plain-text part contains markup");
	themeCheck(trim($message['subject']) !== '', "$label: no subject");
}

/* ---- 1. the mail test message (Site Settings -> Mail) ---- */
require_once 'mod/mail/mail.async.php';
$rec = new ThemedRecorder();
themeCheck(mailDeliverTestMessage($rec, array('admin@example.test')) === true, 'The test message did not send');
assertThemed($rec->last(), 'Mail Settings test message', $palette);
themeCheck(stripos($rec->last()['body'], 'test message') !== false, 'The test message lost its wording');

/* ---- 2. vhost and certificate notices ---- */
require_once 'mod/vhosts/vhosts.notify.php';
$rec = new ThemedRecorder();
$notifier = new VhostsNotifier(array('notifyEnabled'=>true,'notifyEmail'=>'ops@example.test','certbotEmail'=>'ops@example.test'), $rec);
$notifier->needsIntervention('certificate monitoring is failing', 'certbot exited 1');
assertThemed($rec->last(), 'vhosts notice', $palette);

/* ---- 3. emailed backups (with an attachment) ---- */
require_once 'ghoti.backup.delivery.php';
$rec = new ThemedRecorder();
$tmp = tempnam(sys_get_temp_dir(), 'ghoti-backup-themed');
file_put_contents($tmp, "-- a very small dump\n");
$result = ghoti_backup_email_export('database', $rec, array('admin@example.test'), function() use ($tmp){
	return array('path'=>$tmp, 'filename'=>'ghoti-test.sql', 'mime'=>'application/sql');
});
@unlink($tmp);
assertThemed($rec->last(), 'emailed backup', $palette);
themeCheck(count($rec->last()['attachments']) === 1, 'The backup was sent without its attachment');
//The plain-text part is the notice the sender wrote, kept verbatim rather than
//re-wrapped by the renderer. The filename in it is the one the exporter
//generates (ghoti-<kind>-<timestamp>.sql), not the one the caller suggested.
themeCheck(preg_match('/ghoti-database-\d{8}-\d{6}\.sql/', $rec->last()['body']) === 1,
	'The backup text part lost the generated filename: '.$rec->last()['body']);

/* ---- 4. two-factor sign-in codes ---- */
require_once 'mod/login/login.php';
class ThemedLoginDb{
	public function getUserEmailById($id){ return 'admin@example.test'; }
	public function isAdmin($id){ return true; }
	public function getTotp($id){ return null; } //e-mailed codes, not an app
}
$rec = new ThemedRecorder();
$_SESSION = array('mailObj' => $rec);
$_SESSION['loginObj'] = (new ReflectionClass(login::class))->newInstanceWithoutConstructor();
$_SESSION['loginObj']->logindb = new ThemedLoginDb();
themeCheck(login_2fa_begin(7, 'theadmin', 'fp') === true, 'The sign-in code did not send');
assertThemed($rec->last(), 'two-factor sign-in code', $palette);
themeCheck(preg_match('/\b\d{6}\b/', $rec->last()['body']) === 1, 'The code is missing from the plain-text part');
themeCheck(preg_match('/\b\d{6}\b/', strip_tags($rec->last()['html'])) === 1, 'The code is missing from the HTML part');
//The code leads the subject, so it can be read from a lock-screen notification.
themeCheck(preg_match('/^(\d{6}) is your /', $rec->last()['subject'], $subjectCode) === 1, 'The code is not at the start of the subject: '.$rec->last()['subject']);
themeCheck(strpos($rec->last()['body'], $subjectCode[1]) !== false, 'The subject code differs from the body code');

/* ---- 4b. the registration confirmation code ---- */
//A new sender, and the only one that mails an address a stranger typed. It goes
//through the shared helper rather than a bare send(), so Part A's scan stays
//quiet about it - this is the half that would notice it going out unthemed.
class ThemedRegDb{
	public function checkDuplicate($u,$e){ return false; }
	public function hashPendingPassword($p){ return '$fake$'.sha1($p); }
	public function addUser($u,$p,$e,$h=false){ return true; }
}
$rec = new ThemedRecorder();
$_SESSION = array('mailObj' => $rec);
$_SESSION['loginObj'] = (new ReflectionClass(login::class))->newInstanceWithoutConstructor();
$_SESSION['loginObj']->logindb = new ThemedRegDb();
themeCheck(login_reg_begin('newcomer', 'newcomer@example.test', '$fake$hash') === true, 'The confirmation code did not send');
assertThemed($rec->last(), 'registration confirmation code', $palette);
themeCheck(preg_match('/\b\d{6}\b/', $rec->last()['body']) === 1, 'The code is missing from the plain-text part');
themeCheck(preg_match('/\b\d{6}\b/', strip_tags($rec->last()['html'])) === 1, 'The code is missing from the HTML part');
//The recipient may not have asked for this, and has no account here.
themeCheck(stripos($rec->last()['html'], 'you have an account') === false,
	'The confirmation e-mail tells a stranger they have an account here');

/* ---- 5. the admin composer (bulk) ---- */
$rec = new ThemedRecorder();
$html = ghoti_mail_render_html(ghoti::$siteTitle, 'Notice', "Line one.\n\nLine two.", $palette, 'https://example.test');
$text = ghoti_mail_render_text(ghoti::$siteTitle, 'Notice', "Line one.\n\nLine two.", 'https://example.test');
ghoti_mail_send_bulk($rec, array(array('userId'=>1,'userName'=>'mara','email'=>'mara@example.test')), 'Notice', $text, $html);
assertThemed($rec->last(), 'admin composer', $palette);

/* ---- 6. the store receipt, whose footer must NOT claim an account ---- */
$rec = new ThemedRecorder();
themeCheck(ghoti_mail_send_themed($rec, 'buyer@example.test', 'A Buyer', 'Your order GH-1', "Thanks for your order.", 'customer') === true,
	'The receipt did not send');
assertThemed($rec->last(), 'store receipt', $palette);
themeCheck(stripos($rec->last()['html'], 'you have an account') === false,
	'The receipt tells a customer they have an account here');
themeCheck(stripos($rec->last()['body'], 'you have an account') === false,
	'The receipt plain-text tells a customer they have an account here');
themeCheck(stripos($rec->last()['html'], 'about your order') !== false, 'The receipt footer does not explain why it arrived');
//...while a member message still says what it always said.
$rec = new ThemedRecorder();
ghoti_mail_send_themed($rec, 'mara@example.test', 'mara', 'Notice', 'Hello.', 'member');
themeCheck(stripos($rec->last()['html'], 'you have an account') !== false, 'The member footer line was lost');

/* ---- 7. the theme actually drives the colours ---- */
//The real proof that nothing is hardcoded: change the theme, and every part of
//the message changes with it.
$before = ghoti_mail_compose('Subject', 'Body', 'member');
ghoti::$defaultTheme = 'mahogany';
$mahogany = ghoti_mail_palette();
themeCheck($mahogany['accent'] !== $palette['accent'], 'Two themes resolved to the same accent; this check proves nothing');
$after = ghoti_mail_compose('Subject', 'Body', 'member');
themeCheck($before['html'] !== $after['html'], 'Changing the theme did not change the message');
themeCheck(strpos($after['html'], $mahogany['accent']) !== false, 'The message did not follow the theme change');
themeCheck(strpos($after['html'], $palette['accent']) === false, 'The old theme accent is still in the message');
ghoti::$defaultTheme = 'ghoticms';

/* ---- 8. escaping survives theming ---- */
$rec = new ThemedRecorder();
ghoti_mail_send_themed($rec, 'x@example.test', '', 'Subject', "<script>alert(1)</script>\n\nplain", 'member');
themeCheck(strpos($rec->last()['html'], '<script>') === false, 'A themed message passed script markup through');
themeCheck(strpos($rec->last()['html'], '&lt;script&gt;') !== false, 'The escaped form is missing');

echo "PASS: $checks themed-mail assertions across ".count($sendSites)." send() call sites\n";
