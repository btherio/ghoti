<?php
/*
 * PDF export of one Apache log analysis - a plain navigable URL, because a
 * file download cannot be an async response (the same reason
 * analytics.export.php exists alongside the RPC layer).
 */
/* Apache (and PHP's built-in server) run a script with the working directory set
 * to the script's own directory, not the application root. ghoti::$ghotiLog and
 * the other runtime paths are relative, so without this a log line written from
 * here would create a second ghoti.log inside mod/analytics/. index.php's
 * endpoints never hit this because the request starts at the application root. */
chdir(__DIR__.'/../..');
require_once __DIR__.'/../../ghoti.php';
require_once __DIR__.'/../login/login.db.php';
require_once __DIR__.'/apachelog.php';

$secure = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
@ini_set('session.cookie_httponly', 1);
@ini_set('session.cookie_secure', $secure ? 1 : 0);
@ini_set('session.use_only_cookies', 1);
@ini_set('session.use_strict_mode', 1);
if (defined('PHP_VERSION_ID') && PHP_VERSION_ID >= 70300) {
    @ini_set('session.cookie_samesite', 'Strict');
}
@session_name(ghoti::$sessionName);
@session_set_cookie_params(0, '/', '', $secure, true);
@session_start();

/* Admin status is re-checked against the database rather than trusting a
 * session object that may not be populated on a request that skipped
 * index.php - the same rule analytics.export.php follows. */
function apacheLogEndpointAdmin(){
    if(!isset($_SESSION['loggedIn']) || $_SESSION['loggedIn'] !== true || !isset($_SESSION['userId'])){ return false; }
    if(!ghoti_csrf_verify(isset($_GET['token']) ? (string)$_GET['token'] : '')){ return false; }
    $logindb = new logindb();
    ghoti_validate_session($logindb);
    return ghoti_require_login() && $logindb->isAdmin($_SESSION['userId']);
}

function apacheExportDeny($message, $status){
    http_response_code($status);
    header('Content-Type: text/plain');
    echo $message;
    exit;
}

if(!apacheLogEndpointAdmin()){
    apacheExportDeny('Forbidden', 403);
}

$config = apache_log_config();
try{
    list($path, $name) = apache_safe_log_path(isset($_GET['file']) ? (string)$_GET['file'] : '', $config);
    $report = apache_analyze_file($path, $name, $config, $config['pdf_entry_limit']);
    $pdf = apache_build_pdf($report);
}catch(ApacheLogHttpException $e){
    apacheExportDeny($e->getMessage(), $e->getStatus());
}catch(Throwable $e){
    ghoti::logException("apachelog.export.php", $e);
    apacheExportDeny('The analysis could not be exported.', 500);
}

ghoti::logInfo("apachelog.export.php", "Apache log analysis exported by userId ".$_SESSION['userId']." from ".($_SERVER['REMOTE_ADDR'] ?? ''));

$safeName = preg_replace('/[^a-z0-9_-]+/i', '-', (string)preg_replace('/\.[^.]+$/', '', $name));
$safeName = trim((string)$safeName, '-');
if($safeName === ''){ $safeName = 'apache-log'; }

header('Cache-Control: no-store');
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="'.$safeName.'-analysis.pdf"');
header('Content-Length: '.strlen($pdf));
header('X-Content-Type-Options: nosniff');
echo $pdf;
exit;
