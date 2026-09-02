<?php
/*
 * mail.async.php - mail module async layer.
 *
 * Combines endpoints (admin-only "Mail Settings" panel: view/save/test) and
 * the UI renderer (class mailui) into one file, in the same pattern as
 * banners.async.php / login.async.php. Every privileged endpoint enforces
 * ghoti_require_admin() server-side - the admin menu only shows these to
 * admins, but that is not enforcement on its own (see the note in
 * ghoti.async.php's Authorization helpers section).
 */

/* ---------------------------------------------------------------- *
 *  Endpoints
 * ---------------------------------------------------------------- */

function mailRemoteAddr(){
	return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
}

function mailRequireAdmin(){
	if(!ghoti_require_admin()){
		ghoti::logWarn("mail.async.php", "Unauthorized mail settings access attempt from ".mailRemoteAddr());
		return false;
	}
	return true;
}

//Renders the admin "Mail Settings" panel (Admin Menu -> Mail Settings).
function printMailSettingsForm(){
	if(!mailRequireAdmin()){ return "<h1>Mail Settings</h1><p>Admin access required.</p>"; }
	$settings = $_SESSION["mailObj"]->maildb->getSettings();
	return $_SESSION["mailObj"]->mailui->printMailSettingsForm($settings);
}

//Persists admin-submitted SMTP settings. $settings is the decoded object the
//client sends. Returns true, or an error message string.
function saveMailSettings($settings){
	if(!mailRequireAdmin()){ return "Admin access required."; }
	if(!is_array($settings)){ return "Invalid settings."; }
	$v = ghoti_validate();
	try{
		$clean = array();
		$clean['smtpHost'] = $v->text($settings['smtpHost'] ?? '', 255, true, "SMTP host");
		$clean['smtpPort'] = $v->intInRange($settings['smtpPort'] ?? 25, 1, 65535, "SMTP port");

		$encryption = strtolower(trim((string)($settings['encryption'] ?? 'none')));
		if(!in_array($encryption, array('none','tls','ssl'), true)){
			return "Invalid encryption mode.";
		}
		$clean['encryption'] = $encryption;

		$clean['smtpUsername'] = $v->text($settings['smtpUsername'] ?? '', 255, false, "SMTP username");
		//Password is credential material, not display text - preserve it as-is
		//(only capped for sanity) rather than running it through text()'s
		//tag-stripping/whitespace-collapsing, which could silently mangle it.
		$password = (string)($settings['smtpPassword'] ?? '');
		$clean['smtpPassword'] = substr($password, 0, 255);

		$clean['fromAddress'] = ($settings['fromAddress'] ?? '') !== '' ? $v->email($settings['fromAddress']) : '';
		$clean['fromName'] = $v->text($settings['fromName'] ?? '', 120, false, "From name");
		$clean['enabled'] = (bool)$v->boolInt($settings['enabled'] ?? 0);

		if($clean['enabled'] && $clean['fromAddress'] === ''){
			return "A \"from\" address is required to enable mail sending.";
		}
	}catch(Exception $e){
		return $e->getMessage();
	}
	if($_SESSION["mailObj"]->maildb->saveSettings($clean)){
		ghoti::logInfo("mail.async.php:saveMailSettings", "Mail settings updated by UID:".($_SESSION['userId'] ?? '?')." from ".mailRemoteAddr());
		return true;
	}
	return "Could not save mail settings. Check the log.";
}

//Sends a short test message to the given address using the settings that are
//CURRENTLY SAVED (not the possibly-unsaved form contents), so admins should
//press Save first. Kept intentionally simple - one recipient, fixed subject.
function sendTestMail($toAddress){
	if(!mailRequireAdmin()){ return "Admin access required."; }
	try{
		$toAddress = ghoti_validate()->email($toAddress);
	}catch(Exception $e){
		return $e->getMessage();
	}
	$siteTitle = ghoti::$siteTitle;
	$subject = "Test message from ".$siteTitle;
	$body = "This is a test message sent from the Mail Settings panel of ".$siteTitle.".\n\n"
		."If you received this, outbound mail is configured correctly.\n\n"
		."Sent: ".date('r')."\n";
	return $_SESSION["mailObj"]->send($toAddress, '', $subject, $body);
}

ghoti_async_register(
	"printMailSettingsForm",
	"saveMailSettings",
	"sendTestMail"
);

/* ---------------------------------------------------------------- *
 *  UI renderer (class mailui)
 * ---------------------------------------------------------------- */

class mailui{
	public $output;

	public function printMailSettingsForm($settings){
		$esc = function($v){ return htmlspecialchars((string)$v, ENT_QUOTES); };
		$chk = function($b){ return $b ? " checked=\"checked\"" : ""; };
		$selEnc = function($value, $current){ return $value === $current ? " selected=\"selected\"" : ""; };

		$o  = "<div id=\"ghotiMailSettings\">\n<h1>Mail Settings</h1>\n";
		$docs = ghoti_docs_panel("How to use mail settings", "SMTP host, port, encryption, credentials", array(
			array('heading' => 'Local Arch Linux mail server (recommended)',
				'list' => array('Host <code>127.0.0.1</code>, port <b>25</b>, encryption <b>None</b>, and no username/password work for a local Postfix/Exim relay that trusts connections from localhost.', 'Confirm the server is listening: <code>ss -tlnp | grep :25</code> and that it relays for your domain.')),
			array('heading' => 'Remote or authenticated relay',
				'list' => array('Use port <b>587</b> with <b>STARTTLS</b>, or port <b>465</b> with <b>Implicit TLS (SSL)</b>.', 'Fill in a username/password only if the server requires <code>AUTH</code>.')),
			array('heading' => 'From address',
				'list' => array('Must be an address your mail server is allowed to send as, or delivery will be rejected/spam-filtered by the receiving side (SPF/DMARC).')),
			array('heading' => 'Enable + test',
				'list' => array('Mail sending stays off until <b>Enabled</b> is checked and settings are saved.', 'Use <b>Send test message</b> after saving to confirm delivery end-to-end.'))
		));
		$o .= "<form id=\"mailSettingsForm\" class=\"ghotiForm\" action=\"#\" onsubmit=\"saveMailSettings(); return false;\">\n";
		$o .= "<div class=\"ghotiFormGrid\">\n";
		$o .= "<label class=\"ghotiField\"><span>SMTP host</span><input type=\"text\" id=\"mail-smtpHost\" size=\"30\" maxlength=\"255\" value=\"".$esc($settings['smtpHost'])."\" /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>SMTP port</span><input type=\"number\" id=\"mail-smtpPort\" min=\"1\" max=\"65535\" value=\"".$esc($settings['smtpPort'])."\" /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>Encryption</span><select id=\"mail-encryption\">\n";
		$o .= "<option value=\"none\"".$selEnc('none',$settings['encryption']).">None (plain, local server)</option>\n";
		$o .= "<option value=\"tls\"".$selEnc('tls',$settings['encryption']).">STARTTLS</option>\n";
		$o .= "<option value=\"ssl\"".$selEnc('ssl',$settings['encryption']).">Implicit TLS (SSL)</option>\n";
		$o .= "</select></label>\n";
		$o .= "<label class=\"ghotiField\"><span>SMTP username <i>(optional)</i></span><input type=\"text\" id=\"mail-smtpUsername\" size=\"30\" maxlength=\"255\" autocomplete=\"off\" value=\"".$esc($settings['smtpUsername'])."\" /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>SMTP password <i>(optional)</i></span><input type=\"password\" id=\"mail-smtpPassword\" size=\"30\" maxlength=\"255\" autocomplete=\"new-password\" value=\"".$esc($settings['smtpPassword'])."\" /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>From address</span><input type=\"email\" id=\"mail-fromAddress\" size=\"30\" maxlength=\"190\" value=\"".$esc($settings['fromAddress'])."\" /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>From name <i>(optional)</i></span><input type=\"text\" id=\"mail-fromName\" size=\"30\" maxlength=\"120\" value=\"".$esc($settings['fromName'])."\" /></label>\n";
		$o .= "</div>\n";
		$o .= "<label class=\"ghotiInlineChoice\"><input type=\"checkbox\" id=\"mail-enabled\"".$chk($settings['enabled'])." /> Enabled &mdash; allow this site to send mail</label>\n";
		$o .= "<div class=\"ghotiFormActions\"><button type=\"button\" class=\"ghotiButton\" onclick=\"saveMailSettings();\">Save Settings</button></div>\n";
		$o .= "</form>\n";
		$o .= "<div class=\"ghotiFormActions ghotiMailTest\"><label class=\"ghotiField\"><span>Send a test message to</span><input type=\"email\" id=\"mail-testAddress\" size=\"30\" maxlength=\"190\" placeholder=\"you@example.com\" /></label>";
		$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonSecondary\" onclick=\"sendTestMail();\">Send test message</button></div>\n";
		$o .= "<span id=\"mailSettingsFeedback\"></span>\n";
		$o .= $docs;
		$o .= "</div>\n";
		return $o;
	}
}
?>
