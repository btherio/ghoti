<?php
/*
 * vhosts.certwatch.php - notices certificate changes that never touch the panel.
 *
 * certbot renews from its own systemd timer. Those renewals happen with no web
 * request involved, so nothing in the module would ever see them. This script
 * closes that gap: it asks the helper for certbot's current state, compares it
 * against the snapshot stored in the `vhosts` table, and e-mails the admin when
 * a certificate's serial number changes (a renewal) or when one is running out
 * without having renewed (something a person has to fix).
 *
 * Run it AS THE WEB SERVER USER (`http`), not as root. The sudoers rule that
 * reaches the helper is granted to `http`, and running as root risks leaving a
 * root-owned ghoti.log behind after a rotation, which the web server could then
 * no longer write. From root's crontab, daily is plenty:
 *
 *   0 7 * * * sudo -u http /usr/bin/php /path/to/ghoti/mod/vhosts/vhosts.certwatch.php --quiet
 *
 * Flags:
 *   --dry-run   report what would be sent, send nothing, save no snapshot
 *   --quiet     no stdout (cron mails you its own output otherwise)
 *
 * CLI only - refuses to run over the web, where it would be an unauthenticated
 * endpoint that sends mail. It never writes Apache config.
 */

if(PHP_SAPI !== 'cli'){
	http_response_code(404);
	exit(1);
}

$root = dirname(__DIR__, 2); //.../ghoti
chdir($root);

require_once $root.'/ghoti.php';        //ghoti class + logging + async layer
ghoti::loadSettings();
if(!ghoti::$enableVhosts){
	if(!in_array('--quiet', $argv, true)){ fwrite(STDOUT, "Apache Vhosts module is disabled; certificate monitoring skipped.\n"); }
	exit(0);
}
require_once $root.'/mod/mail/mail.php';
require_once $root.'/mod/vhosts/vhosts.db.php';
require_once $root.'/mod/vhosts/vhosts.helper.php';
require_once $root.'/mod/vhosts/vhosts.notify.php';

$dryRun = in_array('--dry-run', $argv, true);
$quiet  = in_array('--quiet', $argv, true);

function certwatchSay($line, $quiet){
	if(!$quiet){ fwrite(STDOUT, $line."\n"); }
}

//Running as root would work - root needs no sudo - but log rotation would then
//leave a root-owned ghoti.log that the web server cannot write. Warn rather
//than refuse: an admin who knows what they are doing may have reasons.
if(function_exists('posix_geteuid') && posix_geteuid() === 0){
	certwatchSay("warning: running as root. Prefer 'sudo -u http php ".basename(__FILE__)."' so log files stay writable by the web server.", $quiet);
}

$db = new vhostsdb();
$settings = $db->getSettings();
$helper = new VhostsHelper($settings);

if(!$helper->isAvailable()){
	$message = "vhosts certwatch: the privileged helper is not installed or sudo refuses it; cannot read certificate state.";
	ghoti::logWarn("vhosts.certwatch.php", $message);
	certwatchSay($message, $quiet);
	exit(2);
}

$result = $helper->listCertificates();
if(!$result['ok']){
	ghoti::logError("vhosts.certwatch.php", "certbot query failed: ".$result['error']);
	certwatchSay("vhosts certwatch: certbot could not be queried.", $quiet);
	//Worth an alert: if this keeps failing, nobody is watching the certificates.
	$notifier = new VhostsNotifier($settings, new mail());
	if(!$dryRun){ $notifier->needsIntervention("certificate monitoring is failing", $result['error']); }
	exit(2);
}

$mailer = new mail();
$notifier = new VhostsNotifier($settings, $mailer);
$diff = VhostsCertDiff::compare($db->getCertState(), $result['certs']);
$events = 0;

foreach($diff['events'] as $event){
	$name = $event['name'];
	switch($event['type']){
		case VhostsCertDiff::NEW_CERT:
			//First sighting is not an event - otherwise the first run mails one
			//alert per certificate already on the server.
			certwatchSay("new to certwatch: ".$name." (".$event['cert']['expiry'].")", $quiet);
			break;

		case VhostsCertDiff::RENEWED:
			$events++;
			$cert = $event['cert'];
			certwatchSay("RENEWED: ".$name." -> ".$cert['expiry'], $quiet);
			ghoti::logInfo("vhosts.certwatch.php", "certificate '".$name."' renewed outside the panel, now expires ".$cert['expiry']);
			if(!$dryRun){
				$notifier->certChangedExternally($name, $cert['expiry'],
					"Domains: ".implode(', ', $cert['domains'])."\n"
					."Serial:  ".$event['was']['serial']." -> ".$cert['serial']."\n"
					."Path:    ".$cert['certPath']."\n\n"
					."This renewal was performed by certbot itself, not from the admin panel.\n"
					."Apache keeps serving the old certificate until it is reloaded; certbot's\n"
					."deploy hook normally handles that. If browsers still see the old dates,\n"
					."reload Apache from the vhosts panel.");
			}
			break;

		case VhostsCertDiff::EXPIRING:
			$events++;
			$cert = $event['cert'];
			$days = $cert['daysLeft'];
			certwatchSay("EXPIRING: ".$name." (".$days." days)", $quiet);
			ghoti::logWarn("vhosts.certwatch.php", "certificate '".$name."' has ".$days." days left and has not renewed");
			if(!$dryRun){
				$notifier->certExpiring($name, $days,
					"Domains: ".implode(', ', $cert['domains'])."\n"
					."Expires: ".$cert['expiry']."\n\n"
					."certbot normally renews at 30 days remaining. Still being below that\n"
					."means renewal is failing. Usual causes: the domain no longer resolves\n"
					."to this server, port 80 is blocked so the ACME challenge cannot be\n"
					."fetched, the webroot moved, or a Let's Encrypt rate limit.\n\n"
					."Check with:  certbot renew --cert-name ".$name." --dry-run");
			}
			break;

		case VhostsCertDiff::GONE:
			$events++;
			certwatchSay("GONE: ".$name, $quiet);
			ghoti::logWarn("vhosts.certwatch.php", "certificate '".$name."' is no longer managed by certbot");
			if(!$dryRun){
				$notifier->needsIntervention("certificate ".$name." is no longer managed by certbot",
					"It was present on the last check (expiring ".$event['was']['expiry'].") and is now gone.\n"
					."If that was deliberate, no action is needed. If not, any vhost still\n"
					."pointing at its files will fail to start Apache on the next restart.");
			}
			break;
	}
}

if($dryRun){
	certwatchSay("dry run: ".$events." event(s) would have been reported; snapshot not saved.", $quiet);
	exit(0);
}

$db->saveCertState($diff['state']);
certwatchSay("certwatch: ".count($diff['state'])." certificate(s) checked, ".$events." event(s) reported.", $quiet);
exit(0);
