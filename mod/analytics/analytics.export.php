<?php
/*
 * Standalone CSV download - not part of the ghoti async RPC layer since a file
 * download needs a plain navigable URL, not an XHR response. Admin status is
 * re-checked here directly against the DB rather than trusting a session
 * object that may not be populated on a request that skipped index.php.
 */
require_once __DIR__.'/../../ghoti.php';
require_once __DIR__.'/../login/login.db.php';
require_once __DIR__.'/analytics.db.php';

$secure = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
@ini_set('session.cookie_httponly', 1);
@ini_set('session.cookie_secure', $secure ? 1 : 0);
if (defined('PHP_VERSION_ID') && PHP_VERSION_ID >= 70300) {
    @ini_set('session.cookie_samesite', 'Strict');
}
@session_name(ghoti::$sessionName);
@session_set_cookie_params(0, '/', '', $secure, true);
@session_start();

function analyticsExportDeny(){
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "Forbidden";
    exit;
}

if(!isset($_SESSION['loggedIn']) || $_SESSION['loggedIn'] !== true || !isset($_SESSION['userId'])){
    analyticsExportDeny();
}

$logindb = new logindb();
if(!$logindb->isAdmin($_SESSION['userId'])){
    analyticsExportDeny();
}

$days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
if($days < 1) $days = 1;
if($days > 3650) $days = 3650;

ghoti::log("Analytics CSV exported by userId ".$_SESSION['userId']." from ".($_SERVER['REMOTE_ADDR'] ?? ''));

$analyticsdb = new analyticsdb();
$rows = $analyticsdb->getExportRows($days);

$filename = "ghoti-analytics-".date('Y-m-d').".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');
fputcsv($out, array('id','createdAt','pageId','pageTitle','sessionId','userId','ipAddress','userAgent','browser','os','deviceType','referrer','requestUri','isAdminView'));
foreach($rows as $row){
    fputcsv($out, $row);
}
fclose($out);
exit;
?>
