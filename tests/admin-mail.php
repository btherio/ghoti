<?php
/*
 * tests/admin-mail.php - Admin Menu -> Send Email.
 *
 * Covers the parts that can go wrong silently: theme-palette extraction for
 * every installed theme, escaping and structure of the rendered message,
 * recipient resolution, and the per-recipient delivery loop. No database and
 * no real mail - the mailer and the user directory are both fixtures.
 */
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
require __DIR__.'/../ghoti.php';
$checks = 0;
function mailCheck($ok, $label){ global $checks; if(!$ok){ throw new RuntimeException($label); } $checks++; }
function isAdmin($userId){ return !empty($GLOBALS['adminMailTestAdmin']); }
$log = tempnam(sys_get_temp_dir(), 'ghoti-admin-mail-log-');
ghoti::$ghotiLog = $log;
ghoti::$enableCriticalAlerts = false;
register_shutdown_function(function() use ($log){ @unlink($log); });
$_SESSION = array('loggedIn'=>true, 'userId'=>1);
$GLOBALS['adminMailTestAdmin'] = true;

/* ---- Colour parsing ------------------------------------------------ */
mailCheck(ghoti_mail_parse_color('#78ffc8') === array(120,255,200,1.0), 'Six-digit hex misparsed');
mailCheck(ghoti_mail_parse_color('#abc') === array(170,187,204,1.0), 'Shorthand hex misparsed');
$rgba = ghoti_mail_parse_color('rgba(24, 10, 52, .9)');
mailCheck($rgba[0] === 24 && $rgba[1] === 10 && $rgba[2] === 52 && abs($rgba[3] - 0.9) < 0.001, 'rgba() misparsed');
mailCheck(ghoti_mail_parse_color('rgb(0 128 255)')[1] === 128, 'Space-separated rgb() misparsed');
mailCheck(ghoti_mail_parse_color('var(--accent)', array('accent'=>'#ff0000')) === array(255,0,0,1.0), 'var() not followed');
mailCheck(ghoti_mail_parse_color('var(--missing, #00ff00)', array()) === array(0,255,0,1.0), 'var() fallback ignored');
mailCheck(ghoti_mail_parse_color('var(--a)', array('a'=>'var(--b)','b'=>'var(--a)')) === null, 'Circular var() did not terminate');
mailCheck(ghoti_mail_parse_color('linear-gradient(135deg, #78ffc8 0%, #6be8b3 100%)') === null, 'Gradient accepted as a colour');
mailCheck(ghoti_mail_parse_color('') === null && ghoti_mail_parse_color('none') === null, 'Empty/none accepted as a colour');
// Translucent values are flattened onto their backdrop, never emitted as rgba.
$flat = ghoti_mail_flatten(array(255,255,255,0.5), array(0,0,0,1.0));
mailCheck($flat === array(128,128,128,1.0), 'Alpha compositing is wrong');
mailCheck(ghoti_mail_hex(array(120,255,200,1.0)) === '#78ffc8', 'Hex formatting is wrong');

/* ---- Token extraction ---------------------------------------------- */
$tokens = ghoti_mail_parse_tokens(":root {\n  --accent: #112233;\n  --text: white; /* c */\n}\n@media x { :root { --accent: #999999; } }");
mailCheck($tokens['accent'] === '#112233', 'A later override replaced the base token');
mailCheck($tokens['text'] === 'white', 'Keyword token lost');
mailCheck(ghoti_mail_parse_tokens('body { color: red; }') === array(), 'Tokens found outside :root');

/* ---- Every installed theme yields a complete, readable palette ------ */
$themes = array();
foreach(glob(__DIR__.'/../css/*', GLOB_ONLYDIR) as $dir){
    $name = basename($dir);
    if(is_file($dir.'/'.$name.'.php')){ $themes[] = $name; }
}
mailCheck(count($themes) > 0, 'No themes found to check');
foreach($themes as $theme){
    $palette = ghoti_mail_palette($theme);
    foreach(array('bg','surface','inset','text','muted','border','accent','onAccent') as $role){
        mailCheck(preg_match('/^#[0-9a-f]{6}$/', $palette[$role]) === 1, 'Theme '.$theme.' role '.$role.' is not an opaque hex colour');
    }
    $hex = function($value){ return array(hexdec(substr($value,1,2)), hexdec(substr($value,3,2)), hexdec(substr($value,5,2))); };
    $contrast = function($a, $b) use ($hex){ return ghoti_mail_contrast($hex($a), $hex($b)); };
    // Body text is normal size (AA: 4.5:1); the header is large bold type (AA: 3:1).
    mailCheck($contrast($palette['text'], $palette['surface']) >= 4.5, 'Theme '.$theme.' body text fails contrast on its panel');
    mailCheck($contrast($palette['muted'], $palette['inset']) >= 3.0, 'Theme '.$theme.' footer text fails contrast on its band');
    mailCheck($contrast($palette['onAccent'], $palette['accent']) >= 3.0, 'Theme '.$theme.' header text fails contrast on the accent');
    mailCheck(strpos($palette['font'], 'Arial') !== false, 'Theme '.$theme.' font stack has no portable fallback');
}
// A theme whose stylesheet cannot be read still produces a usable palette.
$fallback = ghoti_mail_palette('no-such-theme-'.bin2hex(random_bytes(4)));
mailCheck(preg_match('/^#[0-9a-f]{6}$/', $fallback['accent']) === 1 && $fallback['text'] !== $fallback['surface'], 'Unknown theme did not fall back safely');
// Stylesheet selection skips print styles and files without tokens.
$prosimii = ghoti_mail_theme_stylesheet('prosimii');
mailCheck($prosimii !== '' && basename($prosimii) === 'prosimii-modern.css', 'Wrong prosimii stylesheet chosen: '.basename((string)$prosimii));
mailCheck(ghoti_mail_theme_stylesheet('../../etc') === '' && ghoti_mail_theme_stylesheet('a/b') === '', 'Theme name traversal not rejected');

/* ---- Rendering ------------------------------------------------------ */
$palette = ghoti_mail_palette('ghoticms');
$html = ghoti_mail_render_html('Ghoti & Co', 'Scheduled <maintenance>', "First line\nsecond line\n\nNew paragraph.", $palette, 'https://example.test');
mailCheck(str_contains($html, 'Ghoti &amp; Co') && !str_contains($html, 'Ghoti & Co'), 'Site title not escaped');
mailCheck(str_contains($html, 'Scheduled &lt;maintenance&gt;') && !str_contains($html, '<maintenance>'), 'Subject not escaped');
mailCheck(substr_count($html, '<p style="margin:0 0 16px 0;') === 2, 'Blank line did not start a new paragraph');
mailCheck(str_contains($html, "First line<br />\nsecond line"), 'Single newline did not become a break');
mailCheck(str_contains($html, 'bgcolor="'.$palette['accent'].'"') && str_contains($html, 'background-color:'.$palette['surface']), 'Theme colours missing from the message');
mailCheck(!str_contains($html, 'display:flex') && !str_contains($html, 'rgba('), 'Message uses layout or colour syntax mail clients drop');
mailCheck(substr_count($html, 'color:'.$palette['text']) >= 3, 'Text nodes do not carry their own colour');
mailCheck(str_contains($html, 'https://example.test'), 'Site link missing from the footer');
// Admin-typed markup is content, never markup.
$injected = ghoti_mail_render_html('Site', 'Hi', '<script>alert(1)</script>', $palette, '');
mailCheck(!str_contains($injected, '<script>') && str_contains($injected, '&lt;script&gt;'), 'HTML in the message body was not escaped');
mailCheck(!str_contains($injected, '<a href'), 'Empty site URL still emitted a link');
// The palette actually follows the theme, rather than being hardcoded.
$a = ghoti_mail_palette('ghoticms'); $b = ghoti_mail_palette('prosimii');
mailCheck($a['bg'] !== $b['bg'] && $a['accent'] !== $b['accent'], 'Two different themes produced the same palette');
mailCheck($a['accent'] === '#78ffc8', 'ghoticms accent token not picked up');
mailCheck($b['accent'] === '#f45b35', 'prosimii accent token not picked up');
// The plain-text part is the message as typed, not a stripped copy of the HTML.
$text = ghoti_mail_render_text('Ghoti & Co', 'Subject', "Body line\n\nSecond.", 'https://example.test');
mailCheck(str_contains($text, 'Ghoti & Co') && !str_contains($text, '&amp;'), 'Plain-text part was HTML-escaped');
mailCheck(str_contains($text, "Body line\n\nSecond.") && !str_contains($text, '<'), 'Plain-text part lost the message or carries markup');

/* ---- Recipients ----------------------------------------------------- */
$directory = array(
    array('userId'=>1, 'userName'=>'root',  'email'=>'root@example.test',  'admin'=>true),
    array('userId'=>2, 'userName'=>'ann',   'email'=>'ann@example.test',   'admin'=>false),
    array('userId'=>3, 'userName'=>'bob',   'email'=>'',                   'admin'=>false),
    array('userId'=>4, 'userName'=>'carol', 'email'=>'not-an-address',     'admin'=>false),
    array('userId'=>5, 'userName'=>'dup',   'email'=>'ANN@example.test',   'admin'=>false),
);
$all = ghoti_mail_resolve_recipients('all', array(), $directory);
mailCheck(count($all['recipients']) === 2 && $all['skipped'] === 2, 'All-users selection did not skip or dedupe correctly');
mailCheck($all['recipients'][1]['email'] === 'ann@example.test', 'Case-different duplicate address was mailed twice');
$adminsOnly = ghoti_mail_resolve_recipients('admins', array(), $directory);
mailCheck(count($adminsOnly['recipients']) === 1 && $adminsOnly['recipients'][0]['email'] === 'root@example.test', 'Administrators-only selection is wrong');
$one = ghoti_mail_resolve_recipients('selected', array(2), $directory);
mailCheck(count($one['recipients']) === 1 && $one['recipients'][0]['userName'] === 'ann', 'Single-user selection is wrong');
$some = ghoti_mail_resolve_recipients('selected', array(1,2,3), $directory);
mailCheck(count($some['recipients']) === 2 && $some['skipped'] === 1, 'Multi-user selection is wrong');
mailCheck(ghoti_mail_resolve_recipients('selected', array(99), $directory)['recipients'] === array(), 'Unknown user id produced a recipient');

/* ---- Delivery ------------------------------------------------------- */
class AdminMailFake{
    public $calls = array();
    public $failFor = '';
    public $throwFor = '';
    public $maildb;
    public function __construct(){ $this->maildb = new AdminMailSettingsFake(); }
    public function send($to, $name, $subject, $body, array $attachments = array(), $htmlBody = null){
        $this->calls[] = array('to'=>$to, 'name'=>$name, 'subject'=>$subject, 'body'=>$body, 'html'=>$htmlBody);
        if($to === $this->throwFor){ throw new RuntimeException('simulated SMTP outage'); }
        return $to === $this->failFor ? 'rejected' : true;
    }
}
class AdminMailSettingsFake{
    public $settings = array('enabled'=>true, 'fromAddress'=>'site@example.test');
    public function getSettings($throwOnError = false){ return $this->settings; }
}
$recipients = $all['recipients'];
$mailer = new AdminMailFake();
$result = ghoti_mail_send_bulk($mailer, $recipients, 'Subject', 'text', '<html></html>');
mailCheck($result['sent'] === 2 && $result['failed'] === 0, 'Bulk send miscounted');
mailCheck(count($mailer->calls) === 2, 'Recipients did not each get their own message');
mailCheck($mailer->calls[0]['to'] === 'root@example.test' && $mailer->calls[1]['to'] === 'ann@example.test', 'Wrong addresses used');
mailCheck($mailer->calls[0]['html'] === '<html></html>' && $mailer->calls[0]['body'] === 'text', 'HTML and text parts not both delivered');
$mailer = new AdminMailFake();
$mailer->failFor = 'root@example.test';
$result = ghoti_mail_send_bulk($mailer, $recipients, 'Subject', 'text', '<html></html>');
mailCheck($result['sent'] === 1 && $result['failed'] === 1 && $result['failedAddresses'] === array('root@example.test'), 'Rejection not reported');
mailCheck(count($mailer->calls) === 2, 'One rejection stopped the remaining deliveries');
$mailer = new AdminMailFake();
$mailer->throwFor = 'root@example.test';
$result = ghoti_mail_send_bulk($mailer, $recipients, 'Subject', 'text', '<html></html>');
mailCheck($result['sent'] === 1 && $result['failed'] === 1, 'A throwing mailer aborted the run');

/* ---- Endpoint gating ------------------------------------------------ */
mailCheck(ghoti_mail_is_enabled(new AdminMailFake()) === true, 'Configured mail reported as off');
$off = new AdminMailFake(); $off->maildb->settings['enabled'] = false;
mailCheck(ghoti_mail_is_enabled($off) === false, 'Disabled mail reported as on');
$noFrom = new AdminMailFake(); $noFrom->maildb->settings['fromAddress'] = '';
mailCheck(ghoti_mail_is_enabled($noFrom) === false, 'Mail without a from address reported as ready');
mailCheck(ghoti_mail_is_enabled(null) === false, 'Missing mailer reported as ready');

$_SESSION['mailObj'] = new AdminMailFake();
mailCheck(sendComposedMail(array('mode'=>'all','subject'=>'','message'=>'body')) === 'Subject is required.', 'Empty subject accepted');
mailCheck(sendComposedMail(array('mode'=>'all','subject'=>'Hi','message'=>'')) === 'Message is required.', 'Empty message accepted');
mailCheck(sendComposedMail(array('mode'=>'nonsense','subject'=>'Hi','message'=>'body')) === 'Choose who the message goes to.', 'Unknown audience accepted');
mailCheck(sendComposedMail('not-an-array') === 'Invalid request.', 'Non-object payload accepted');
mailCheck(sendComposedMail(array('mode'=>'selected','subject'=>'Hi','message'=>'body','userIds'=>array())) === 'Select at least one user.', 'Empty selection accepted');
$_SESSION['mailObj'] = $off;
mailCheck(str_contains(sendComposedMail(array('mode'=>'all','subject'=>'Hi','message'=>'body')), 'Mail sending is off'), 'Composed mail sent while mail is off');

$GLOBALS['adminMailTestAdmin'] = false;
mailCheck(sendComposedMail(array('mode'=>'all','subject'=>'Hi','message'=>'body')) === 'Admin access required.', 'Non-admin sent mail to users');
mailCheck(str_contains(printComposeMail(), 'Admin access required'), 'Non-admin can open the compose panel');
$GLOBALS['adminMailTestAdmin'] = true;

/* ---- Panel ---------------------------------------------------------- */
$panel = ghoti_mail_render_compose($directory, true);
mailCheck(str_contains($panel, 'value="all"') && str_contains($panel, 'value="admins"') && str_contains($panel, 'value="selected"'), 'Audience choices missing');
mailCheck(substr_count($panel, 'class="composeMailUser"') === 5, 'User list incomplete');
mailCheck(str_contains($panel, 'value="3" disabled="disabled"') || str_contains($panel, 'value="4" disabled="disabled"'), 'Users without an address are selectable');
mailCheck(str_contains($panel, '(2 with an address)'), 'Usable-address count wrong');
$offPanel = ghoti_mail_render_compose($directory, false);
mailCheck(str_contains($offPanel, 'Mail sending is off') && str_contains($offPanel, 'disabled="disabled"'), 'Compose panel usable while mail is off');
$hostile = array(array('userId'=>1,'userName'=>'<script>x</script>','email'=>'a@b.test','admin'=>false));
mailCheck(!str_contains(ghoti_mail_render_compose($hostile, true), '<script>x</script>'), 'User name not escaped in the panel');

echo "PASS: $checks admin mail assertions; no database or real mail\n";
