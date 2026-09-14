<?php
/*
 * store.fulfil.php - drain the supplier queue and collect tracking numbers.
 *
 * CLI only. Run it from cron every few minutes - a crontab line of
 * "every 5 minutes" calling:
 *
 *   php /path/to/ghoti/mod/store/store.fulfil.php >/dev/null
 *
 * The browser nudges the queue right after a receipt renders, which covers the
 * ordinary case within seconds. This exists for everything else: a supplier that
 * was down at that moment, a buyer who closed the tab, and the tracking numbers
 * that only appear hours later. Without it, a queued order waits for an admin to
 * notice, and no order ever moves itself to shipped.
 *
 * Safe to run concurrently with itself and with the site: every submission is
 * claimed with a conditional update first, so two runs cannot send the same
 * order twice.
 */
if(PHP_SAPI !== 'cli'){
	http_response_code(404);
	exit;
}

chdir(__DIR__.'/../..');
require_once __DIR__.'/../../ghoti.php';

ghoti::loadSettings();
if(!ghoti::$enableStore){
	fwrite(STDERR, "The store module is disabled; nothing to do.\n");
	exit(0);
}

require_once __DIR__.'/../login/login.db.php';
require_once __DIR__.'/store.php';

//The endpoints read their handles out of $_SESSION, the way every module does
//when the browser is driving. There is no session here, so build the same shape.
$_SESSION = array();
$_SESSION['storeObj'] = new store();

$options = getopt('', array('limit::', 'submit-only', 'sync-only', 'quiet'));
$limit = isset($options['limit']) ? max(1, min(200, (int)$options['limit'])) : 25;
$quiet = isset($options['quiet']);
$doSubmit = !isset($options['sync-only']);
$doSync = !isset($options['submit-only']);

function storeFulfilSay($message){
	global $quiet;
	if(!$quiet){ echo $message."\n"; }
}

$settings = $_SESSION['storeObj']->storedb->getSettings();
if(empty($settings['dropshipEnabled'])){
	storeFulfilSay('Dropshipping is switched off in Store settings; nothing to do.');
	exit(0);
}

$exitCode = 0;

if($doSubmit){
	$result = storeDrainFulfilments($limit);
	storeFulfilSay('Submitted: '.$result['sent'].' sent, '.$result['failed'].' left for another run or for review.');
	//A failure is reported through the exit code so a cron wrapper can alert on
	//it, but it is never fatal: the rows stay queued and the next run tries again.
	if($result['failed'] > 0){ $exitCode = 1; }
}

if($doSync){
	$synced = storeSyncOpenFulfilments($limit);
	storeFulfilSay('Tracking: '.$synced.' supplier order(s) refreshed.');
}

exit($exitCode);
