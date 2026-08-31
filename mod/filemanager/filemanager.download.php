<?php
/*
 * Standalone file download for the file-manager module - not part of the ghoti
 * async RPC layer because a download needs a plain navigable URL. Admin status
 * is re-checked here directly against the DB (same pattern as
 * analytics.export.php) and the request must carry the session CSRF token, so
 * a cross-site GET cannot trigger a download. Files are always served as an
 * attachment (never inline) and are confined to the web root with the same
 * deny list as the rest of the module.
 */
require_once __DIR__.'/../../ghoti.php';
require_once __DIR__.'/../login/login.db.php';

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

function filemanagerDownloadDeny(){
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "Forbidden";
    exit;
}

if(!isset($_SESSION['loggedIn']) || $_SESSION['loggedIn'] !== true || !isset($_SESSION['userId'])){
    filemanagerDownloadDeny();
}

//CSRF: the download links are generated server-side with the session token
//(filemanagerui::printManager), so a cross-site GET can't trigger a download.
if(!ghoti_csrf_verify(isset($_GET['token']) ? (string)$_GET['token'] : '')){
    filemanagerDownloadDeny();
}

$logindb = new logindb();
if(!$logindb->isAdmin($_SESSION['userId'])){
    filemanagerDownloadDeny();
}

//Resolve + confine the requested file (mirrors fm_resolve_dir in
//filemanager.async.php - the module file is included below for the helpers).
require_once __DIR__.'/filemanager.async.php';

$dir  = isset($_GET['dir']) ? (string)$_GET['dir'] : '';
$name = isset($_GET['name']) ? (string)$_GET['name'] : '';
$resolved = fm_resolve_dir($dir);
if($resolved === false || fm_clean_name($name) === false){
    filemanagerDownloadDeny();
}

//Realpath the final path too, so a symlinked file can't leak outside the root
//or reach a denied file through a benign link name. Folders are refused.
$path = fm_resolve_target($resolved, $name);
if($path === false || !is_file($path) || !is_readable($path)){
    filemanagerDownloadDeny();
}

ghoti::log("File manager: downloaded '".$resolved[1].'/'.$name."' by userId ".$_SESSION['userId']);

$filename = $name;
header('Content-Type: '.fm_mime_type($path));
header('Content-Disposition: attachment; filename="'.$filename.'"; filename*=UTF-8\'\''.rawurlencode($filename));
header('Content-Length: '.filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

readfile($path);
exit;
?>
