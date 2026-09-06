<?php
/*
 * mail.php - mail module entry point.
 *
 * Sends outbound email (transactional notices, password-reset links, etc.)
 * through a local/LAN SMTP server such as Postfix on this Arch Linux host.
 * Mirrors the shape of every other module: this file wires the db + async
 * (endpoints/UI) classes together into one object stored in $_SESSION.
 *
 *   mail.db.php    - `mail` settings table (single row, admin-edited)
 *   mail.smtp.php  - dependency-free SMTP client (class MailSmtpClient)
 *   mail.async.php - admin "Mail Settings" endpoints/UI + class mailui
 *                    + sendMail() convenience wrapper for other modules/PHP
 */
include_once('mail.db.php');
include_once('mail.smtp.php');
include_once('mail.async.php'); //endpoints + class mailui
class mail{
	public $maildb,$mailui;
	public function __construct(){
		$this->maildb = new maildb();
		$this->mailui = new mailui();
	}

	//Convenience entry point for other PHP code in this app (other modules,
	//password-reset.php, etc.) that wants to send a message without going
	//through the async/JS layer. Returns true on success, or an error
	//message string (never throws) - callers should log the string and show
	//the user a generic failure, since it can contain SMTP diagnostics.
	public function send($toAddress, $toName, $subject, $body){
		$settings = $this->maildb->getSettings();
		if(!$settings['enabled']){
			return "Mail sending is disabled. Configure it under Admin Menu -> Mail Settings.";
		}
		$client = new MailSmtpClient($settings);
		if($client->send($toAddress, $toName, $subject, $body)){
			return true;
		}
		ghoti::logError("mail.php:send", "delivery to ".$toAddress." failed: ".$client->lastError);
		return "The message could not be sent. Check the mail server settings and log.";
	}
}
?>
