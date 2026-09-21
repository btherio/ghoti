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

		$clean['tlsVerify'] = (bool)$v->boolInt($settings['tlsVerify'] ?? 1);
		//A filesystem path, not display text: text() would mangle it. Cap the
		//length, reject anything that isn't a plausible absolute path, and make
		//sure PHP can actually read it - a silently-wrong CA path would surface
		//later as an opaque "certificate verify failed" mid-send.
		$caFile = trim((string)($settings['tlsCaFile'] ?? ''));
		if($caFile !== ''){
			if(strlen($caFile) > 255 || !preg_match('#^/[A-Za-z0-9._/-]+$#', $caFile)){
				return "The CA certificate path must be an absolute path (letters, digits, . _ - / only).";
			}
			if(!is_file($caFile) || !is_readable($caFile)){
				return "The CA certificate file \"".$caFile."\" does not exist or is not readable by the web server.";
			}
		}
		$clean['tlsCaFile'] = $caFile;

		$peerName = strtolower(trim((string)($settings['tlsPeerName'] ?? '')));
		if($peerName !== '' && !preg_match('/^[a-z0-9]([a-z0-9.-]{0,253}[a-z0-9])?$/', $peerName)){
			return "The expected certificate name must be a hostname, not an IP address or URL.";
		}
		$clean['tlsPeerName'] = $peerName;

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
		if($clean['encryption'] !== 'none' && !$clean['tlsVerify']){
			ghoti::logWarn("mail.async.php:saveMailSettings", "TLS certificate verification DISABLED for ".$clean['smtpHost'].":".$clean['smtpPort']." by UID:".($_SESSION['userId'] ?? '?'));
		}
		return true;
	}
	return "Could not save mail settings. Check the log.";
}

//Sends a short test message to every administrator account, using the
//settings that are CURRENTLY SAVED (not the possibly-unsaved form contents),
//so admins should press Save first.
//
//The recipient is not typed in: the addresses that matter are the ones the
//app actually mails - critical alerts, emailed backups and password resets all
//go to administrator accounts - so a test that proves delivery to some other
//inbox proves the wrong thing. It also removes a form field that let an admin
//send mail from this server to an arbitrary address.
//
//One message per admin, as everywhere else, so nobody sees anyone else's
//address. The test counts as successful when at least one admin received it;
//a partial failure is reported with the addresses that did not.
function sendTestMail(){
	if(!mailRequireAdmin()){ return "Admin access required."; }
	//Refresh rather than reuse the request memo: an admin who just corrected an
	//address in Manage Users expects the retry to use it.
	return mailDeliverTestMessage($_SESSION["mailObj"], ghoti_admin_emails(true));
}

//The delivery half of sendTestMail(), with the mailer and recipient list
//passed in so it can be exercised without SMTP or a database.
function mailDeliverTestMessage($mailer, array $recipients){
	if(!$recipients){
		return "No administrator account has a valid e-mail address. Add one in Manage Users, then test again.";
	}
	$siteTitle = ghoti::$siteTitle;
	$subject = "Test message from ".$siteTitle;
	$body = "This is a test message sent from the Mail Settings panel of ".$siteTitle.".\n\n"
		."If you received this, outbound mail is configured correctly.\n\n"
		."It was sent to every administrator account on this site.\n\n"
		."Sent: ".date('r')."\n";

	$failures = array();
	$lastError = '';
	$sent = 0;
	foreach($recipients as $address){
		$result = $mailer->send($address, '', $subject, $body);
		if($result === true){ $sent++; continue; }
		$failures[] = $address;
		if(is_string($result)){ $lastError = $result; }
	}
	if($sent === 0){
		return $lastError !== '' ? $lastError : "The test message could not be sent to any administrator. Check the mail server settings and log.";
	}
	if($mailer->maildb->recordSuccessfulTest() !== true){
		return 'The test was sent, but its success could not be recorded. Check the log and send another test before using emailed backups.';
	}
	if($failures){
		return "Sent to ".$sent." of ".count($recipients)." administrators. Delivery failed for: ".implode(', ', $failures).". Check the log.";
	}
	return true;
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

		$o  = "<div id=\"ghotiMailSettings\" class=\"ghotiAdminPanel\">\n<h1>Mail Settings</h1>\n";
		$docs = ghoti_docs_panel("How to use mail settings", "SMTP host, port, encryption, credentials", array(
			array('heading' => 'Local Arch Linux mail server (recommended)',
				'list' => array('Host <code>127.0.0.1</code>, port <b>25</b>, encryption <b>None</b>, and no username/password work for a local Postfix/Exim relay that trusts connections from localhost.', 'Confirm the server is listening: <code>ss -tlnp | grep :25</code> and that it relays for your domain.')),
			array('heading' => 'Remote or authenticated relay',
				'list' => array('Use port <b>587</b> with <b>STARTTLS</b>, or port <b>465</b> with <b>Implicit TLS (SSL)</b>.', 'Fill in a username/password only if the server requires <code>AUTH</code>.')),
			array('heading' => 'TLS certificates',
				'list' => array('Certificates are verified by default. Leave <b>Verify certificate</b> checked.',
					'A LAN mail server usually presents a <i>self-signed</i> certificate that the system CA store does not know, so verification fails with <code>certificate verify failed</code>. Fix it by trusting that one server, not by switching verification off: put its certificate somewhere readable outside the web root and give the path as <b>CA certificate file</b>.',
					'If you dial the server by IP, its certificate name will not match. Put the name from the certificate (e.g. <code>mail.example.com</code>) in <b>Expected certificate name</b> and verification will check against that.',
					'Unchecking <b>Verify certificate</b> accepts any certificate, including an attacker\'s, and logs a warning. Use it only to confirm a diagnosis, never as the final setting.')),
			array('heading' => 'From address',
				'list' => array('Must be an address your mail server is allowed to send as, or delivery will be rejected/spam-filtered by the receiving side (SPF/DMARC).')),
			array('heading' => 'Enable + test',
				'list' => array('Mail sending stays off until <b>Enabled</b> is checked and settings are saved.', 'Use <b>Send test message</b> after saving: it mails every administrator account, which is exactly who alerts, emailed backups and password resets go to.'))
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
		$o .= "<label class=\"ghotiField\"><span>SMTP password <i>(optional)</i></span><span class=\"ghotiPasswordInput\"><input type=\"password\" id=\"mail-smtpPassword\" size=\"30\" maxlength=\"255\" autocomplete=\"new-password\" value=\"".$esc($settings['smtpPassword'])."\" /><button type=\"button\" class=\"ghotiPasswordToggle\" onclick=\"ghotiTogglePassword(this);\" aria-label=\"Show password\" title=\"Show password\">&#128065;</button></span></label>\n";
		$o .= "<label class=\"ghotiField\"><span>CA certificate file <i>(optional)</i></span><input type=\"text\" id=\"mail-tlsCaFile\" size=\"30\" maxlength=\"255\" placeholder=\"/etc/ssl/ghoti/mailserver.pem\" value=\"".$esc($settings['tlsCaFile'])."\" /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>Expected certificate name <i>(optional)</i></span><input type=\"text\" id=\"mail-tlsPeerName\" size=\"30\" maxlength=\"255\" placeholder=\"mail.example.com\" value=\"".$esc($settings['tlsPeerName'])."\" /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>From address</span><input type=\"email\" id=\"mail-fromAddress\" size=\"30\" maxlength=\"190\" value=\"".$esc($settings['fromAddress'])."\" /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>From name <i>(optional)</i></span><input type=\"text\" id=\"mail-fromName\" size=\"30\" maxlength=\"120\" value=\"".$esc($settings['fromName'])."\" /></label>\n";
		$o .= "</div>\n";
		$o .= "<label class=\"ghotiInlineChoice\"><input type=\"checkbox\" id=\"mail-tlsVerify\"".$chk($settings['tlsVerify'])." /> Verify certificate &mdash; leave on; use the CA file / name fields above for a self-signed server</label>\n";
		$o .= "<label class=\"ghotiInlineChoice\"><input type=\"checkbox\" id=\"mail-enabled\"".$chk($settings['enabled'])." /> Enabled &mdash; allow this site to send mail</label>\n";
		$o .= "<div class=\"ghotiFormActions\"><button type=\"button\" class=\"ghotiButton\" onclick=\"saveMailSettings();\">Save Settings</button></div>\n";
		$o .= "</form>\n";
		//No address field: the test goes to the administrator accounts, which
		//are the addresses this site actually mails. Show who that is, so the
		//admin knows which inboxes to check before pressing the button.
		$admins = ghoti_admin_emails();
		$o .= "<div class=\"ghotiFormActions ghotiMailTest\">";
		$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonSecondary\" onclick=\"sendTestMail();\"".($admins ? "" : " disabled=\"disabled\"").">Send test message to all administrators</button></div>\n";
		if($admins){
			$parts = array();
			foreach($admins as $address){ $parts[] = "<code>".$esc($address)."</code>"; }
			$o .= "<p class=\"ghotiHelpText\">The test is sent to every administrator account, one message each: ".implode(", ", $parts)."</p>\n";
		}else{
			$o .= "<p class=\"ghotiHelpText\">No administrator account has a valid e-mail address yet, so there is nobody to test with. Add one in <b>Manage Users</b>.</p>\n";
		}
		$o .= '<p class="ghotiHelpText">'.(!empty($settings['successfulTestAt'])
			? 'Last successful test: '.$esc(date('Y-m-d H:i', (int)$settings['successfulTestAt'])).'. While mail is enabled, backups are emailed to all administrators instead of downloaded.'
			: 'No successful test has been recorded. Backups remain downloadable until a test message succeeds.').'</p>';
		$o .= "<span id=\"mailSettingsFeedback\" role=\"status\" aria-live=\"polite\"></span>\n";
		$o .= $docs;
		$o .= "</div>\n";
		return $o;
	}
}
?>
