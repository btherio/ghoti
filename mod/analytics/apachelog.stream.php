<?php
/*
 * Live Apache log stream (Server-Sent Events).
 *
 * Standalone rather than an async RPC endpoint for two reasons: EventSource
 * issues a plain GET, and this request is held open for as long as the admin
 * watches the file (up to APACHE_LOG_STREAM_MAX_SECONDS, one hour by default).
 *
 * The session is closed before streaming begins. PHP holds an exclusive lock on
 * the session file for the life of a request, so without this the rest of the
 * admin UI - every async call shares that session - would block behind the
 * stream for the whole hour.
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

function apacheStreamDeny($message, $status){
    http_response_code($status);
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    apache_sse_event('server-error', array('ok' => false, 'error' => $message));
    exit;
}

if(!apacheLogEndpointAdmin()){
    apacheStreamDeny('Admin access required.', 403);
}

$config = apache_log_config();
try{
    list($path, $name) = apache_safe_log_path(isset($_GET['file']) ? (string)$_GET['file'] : '', $config);
}catch(ApacheLogHttpException $e){
    apacheStreamDeny($e->getMessage(), $e->getStatus());
}catch(Throwable $e){
    apacheStreamDeny('The selected log could not be opened.', 500);
}

ghoti::logInfo("apachelog.stream.php", "Apache log stream opened by userId ".$_SESSION['userId']." from ".($_SERVER['REMOTE_ADDR'] ?? ''));

//Release the session lock before the long-lived read loop (see the note above).
//Free the per-request service objects first, exactly as index.php and the async
//dispatcher do: whatever this closes is what stays in the session file, and a
//serialized ghoti/logindb comes back as __PHP_Incomplete_Class on the next request.
ghoti_free_request_objects();
session_write_close();

try{
    apache_stream_file($path, $name, $config);
}catch(ApacheLogHttpException $e){
    apache_sse_event('server-error', array('ok' => false, 'error' => $e->getMessage()));
}catch(Throwable $e){
    error_log('Ghoti Apache log stream failed: '.$e->getMessage());
    apache_sse_event('server-error', array('ok' => false, 'error' => 'The live stream stopped unexpectedly.'));
}
exit;
