<?php
/*
 * vhosts.notify.php - admin e-mail alerts for certificate and config events.
 *
 * Sends through the mail module (Admin Menu -> Mail Settings), so there is one
 * SMTP configuration for the whole app rather than a second one here.
 *
 * Dependencies are passed IN rather than read from $_SESSION, because half the
 * callers are not web requests: vhosts.certwatch.php runs from cron, where
 * there is no session at all. Everything here must work identically in both.
 *
 * Every method fails soft. A notification is a courtesy: an unreachable mail
 * server must never turn a successful certificate renewal into an error, so
 * failures are logged and swallowed.
 */

class VhostsNotifier{
	//Kinds of event, and whether each one is asking the admin to go do
	//something. The distinction drives the subject-line prefix, which is what
	//someone triaging a full inbox actually reads.
	const EVENT_OK      = 'ok';      //something worked; informational
	const EVENT_FAIL    = 'fail';    //an operation failed and was rolled back
	const EVENT_ACTION  = 'action';  //needs a human on the server

	private $settings;
	private $mailer;

	/*
	 * $mailer is anything exposing send($toAddress,$toName,$subject,$body)
	 * returning true or an error string - i.e. the mail module's `mail` class.
	 * Pass null to disable sending (the caller still gets its log lines).
	 */
	public function __construct($settings, $mailer){
		$this->settings = $settings;
		$this->mailer = $mailer;
	}

	//Where alerts go: the dedicated notify address, else the certbot contact.
	public function recipient(){
		$to = trim((string)($this->settings['notifyEmail'] ?? ''));
		if($to === ''){ $to = trim((string)($this->settings['certbotEmail'] ?? '')); }
		return $to;
	}

	public function isEnabled(){
		return !empty($this->settings['notifyEnabled']) && $this->recipient() !== '' && $this->mailer !== null;
	}

	/*
	 * Send one alert. $detail is raw command output (certbot/apachectl) and is
	 * included verbatim - it is the whole point of the mail - but capped, since
	 * a runaway certbot log should not become a megabyte-long e-mail.
	 *
	 * Returns true if sent, false otherwise. Callers ignore the result; it
	 * exists for the tests.
	 */
	public function notify($event, $summary, $detail = ''){
		if(!$this->isEnabled()){
			ghoti::logDebug("vhosts.notify.php", "notification suppressed (disabled or no recipient): ".$summary);
			return false;
		}
		$site = ghoti::$siteTitle;
		$prefix = $event === self::EVENT_FAIL ? '[FAILED] '
			: ($event === self::EVENT_ACTION ? '[ACTION NEEDED] ' : '');
		$subject = $prefix.$site.' vhosts: '.$summary;

		$body  = $summary."\n\n";
		$body .= "Host:  ".php_uname('n')."\n";
		$body .= "Site:  ".$site."\n";
		$body .= "When:  ".date('r')."\n";
		if($event === self::EVENT_ACTION){
			$body .= "\nThis one needs a person: the change was not applied, or it was applied\n"
				."but something downstream still has to be done by hand on the server.\n";
		}
		if(trim((string)$detail) !== ''){
			$detail = (string)$detail;
			if(strlen($detail) > 8000){
				$detail = substr($detail, 0, 8000)."\n[... truncated; see the server log for the rest ...]";
			}
			$body .= "\n---------------- command output ----------------\n".$detail."\n";
		}
		$body .= "\n-- \nSent by the vhosts module. Turn these off under Admin Menu -> Apache Vhosts -> Settings.\n";

		$result = $this->mailer->send($this->recipient(), '', $subject, $body);
		if($result !== true){
			//Log, never rethrow: see the fail-soft note at the top of the file.
			ghoti::logWarn("vhosts.notify.php", "could not send '".$summary."': ".(is_string($result) ? $result : 'unknown error'));
			return false;
		}
		ghoti::logInfo("vhosts.notify.php", "alert sent to ".$this->recipient().": ".$summary);
		return true;
	}

	/* ---------------- Named events ----------------
	 * Thin wrappers so call sites read as what happened, and so the wording of
	 * any given alert lives in exactly one place. */

	public function certIssued($name, $detail){
		return $this->notify(self::EVENT_OK, "certificate issued for ".$name, $detail);
	}

	public function certRenewed($name, $detail){
		return $this->notify(self::EVENT_OK, "certificate renewed for ".$name, $detail);
	}

	//A renewal certbot performed on its own timer, noticed later by certwatch.
	public function certChangedExternally($name, $expiry, $detail = ''){
		return $this->notify(self::EVENT_OK,
			"certificate for ".$name." was renewed (expires ".$expiry.")", $detail);
	}

	public function certFailed($name, $action, $detail){
		return $this->notify(self::EVENT_FAIL, "could not ".$action." the certificate for ".$name, $detail);
	}

	//Certbot could not renew and the certificate is running out: a human has to
	//look at DNS, the webroot, or rate limits before it actually expires.
	public function certExpiring($name, $daysLeft, $detail = ''){
		return $this->notify(self::EVENT_ACTION,
			$daysLeft <= 0
				? "certificate for ".$name." HAS EXPIRED"
				: "certificate for ".$name." expires in ".$daysLeft." days and has not renewed",
			$detail);
	}

	//A write was rejected by apachectl and rolled back. Nothing is broken, but
	//the change the admin wanted did not happen.
	public function configRolledBack($name, $detail){
		return $this->notify(self::EVENT_FAIL,
			"Apache rejected the configuration for ".$name.", change rolled back", $detail);
	}

	//The config is on disk but the server is still running the old one.
	public function reloadFailed($detail){
		return $this->notify(self::EVENT_ACTION,
			"configuration saved but Apache would not reload", $detail);
	}

	public function vhostChanged($name, $what){
		return $this->notify(self::EVENT_OK, "vhost ".$name." ".$what, '');
	}

	public function needsIntervention($summary, $detail = ''){
		return $this->notify(self::EVENT_ACTION, $summary, $detail);
	}
}

/*
 * Deciding what changed between two certbot snapshots.
 *
 * Pure and side-effect free on purpose: this is the part of certwatch worth
 * testing, and it has no business touching mail, the database, or the clock.
 * compare() takes the previous snapshot and the current certificate list and
 * returns a flat list of events for the caller to act on.
 */
class VhostsCertDiff{
	//certbot renews at 30 days remaining, so a certificate still under this has
	//failed to renew - that is a person's problem, not a notification.
	const WARN_DAYS = 21;
	const URGENT_DAYS = 7;

	const RENEWED  = 'renewed';
	const EXPIRING = 'expiring';
	const GONE     = 'gone';
	const NEW_CERT = 'new';

	/*
	 * $previous: name => array('serial','expiry','daysLeft') from the last run.
	 * $certs:    VhostsHelper::listCertificates()'s 'certs' list.
	 *
	 * Returns a list of array('type','name','cert'|'was',...). A first run
	 * (empty $previous) yields only NEW_CERT events, so enabling this on a
	 * server with 20 certificates does not mail 20 alerts.
	 */
	public static function compare($previous, $certs){
		$events = array();
		$current = array();

		foreach($certs as $cert){
			$name = $cert['name'];
			$current[$name] = array(
				'serial'   => $cert['serial'],
				'expiry'   => $cert['expiry'],
				'daysLeft' => $cert['daysLeft'],
			);
			if(!isset($previous[$name])){
				$events[] = array('type' => self::NEW_CERT, 'name' => $name, 'cert' => $cert);
				continue;
			}
			$was = $previous[$name];

			//Serial, not expiry: a reissue is a new certificate even when it
			//lands in the same expiry window, and that is what we report.
			if($cert['serial'] !== '' && ($was['serial'] ?? '') !== ''
				&& $cert['serial'] !== $was['serial']){
				$events[] = array('type' => self::RENEWED, 'name' => $name, 'cert' => $cert, 'was' => $was);
				continue;
			}

			//Running out without having renewed. Only report when the situation
			//got worse since the last run, so this does not mail every day for
			//three weeks about the same certificate.
			$days = $cert['daysLeft'];
			if($days === null || $days > self::WARN_DAYS){ continue; }
			$wasDays = $was['daysLeft'] ?? null;
			$worse = $wasDays === null
				|| $wasDays > self::WARN_DAYS
				|| ($days <= 0 && $wasDays > 0)
				|| ($days <= self::URGENT_DAYS && $wasDays > self::URGENT_DAYS);
			if($worse){
				$events[] = array('type' => self::EXPIRING, 'name' => $name, 'cert' => $cert, 'was' => $was);
			}
		}

		//Certificates that were there last time and are not now.
		foreach($previous as $name => $was){
			if(!isset($current[$name])){
				$events[] = array('type' => self::GONE, 'name' => $name, 'was' => $was);
			}
		}
		return array('events' => $events, 'state' => $current);
	}
}

/*
 * Build a notifier for a web request: settings from the module, mail from the
 * session-held mail module. Returns a notifier whose sends are no-ops when the
 * mail module is not loaded, so call sites never have to null-check.
 */
function vhostsNotifier($settings){
	$mailer = isset($_SESSION['mailObj']) ? $_SESSION['mailObj'] : null;
	return new VhostsNotifier($settings, $mailer);
}
?>
