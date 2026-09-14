<?php
/*
 * store.download.php - delivers a purchased digital product.
 *
 * Standalone rather than an async RPC endpoint, for the same reason
 * analytics.export.php is: a file download needs a plain navigable URL that can
 * be clicked from a receipt e-mail, days later, in a browser with no session.
 *
 * That is exactly why the token IS the authorisation. It is 48 random hex
 * characters, tied to one paid order and one product, it expires, and it is
 * download-counted. There is no session check here on purpose - requiring one
 * would break the link in the receipt - so the token must carry all of the
 * proof, and the claim must be atomic.
 */

/* Apache (and PHP's built-in server) run a script with the working directory set
 * to the script's own directory, not the application root. ghoti::$ghotiLog and
 * the other runtime paths are relative, so without this a log line written from
 * here would create a second ghoti.log inside mod/store/. */
chdir(__DIR__.'/../..');
require_once __DIR__.'/../../ghoti.php';
require_once __DIR__.'/store.db.php';

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

function storeDownloadFail($message, $status){
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $message."\n";
    exit;
}

ghoti::loadSettings();
if(!ghoti::$enableStore){
    storeDownloadFail('This store is not available.', 404);
}

$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
if(!preg_match('/^[a-f0-9]{48}$/', $token)){
    //One message for "malformed", "unknown", "expired" and "used up": a caller
    //guessing tokens learns nothing from the difference.
    storeDownloadFail('This download link is not valid, or it has expired.', 404);
}

$storedb = new storedb();
$grant = $storedb->getDownloadGrant($token);
if(!$grant){
    storeDownloadFail('This download link is not valid, or it has expired.', 404);
}

$order = $storedb->getOrder($grant['orderId']);
if(!$order || !in_array($order['status'], array('paid','shipped'), true)){
    storeDownloadFail('This download link is not valid, or it has expired.', 404);
}

$product = $storedb->getProduct($grant['productId']);
if(!$product || $product['kind'] !== 'digital' || $product['downloadPath'] === ''){
    ghoti::logWarn("store.download.php", "Grant for order ".$order['reference']." points at a product with no file");
    storeDownloadFail('This file is no longer available. Contact us quoting '.$order['reference'].'.', 410);
}

//The stored path was validated when the product was saved, but it is re-checked
//here: the file may have been moved, replaced by a symlink, or the product row
//edited directly in the database since. files/store/ is the directory the web
//server denies (see files/store/.htaccess); this script is the only way out of it.
$base = realpath(__DIR__.'/../../files/store');
$full = $base === false ? false : realpath($base.'/'.$product['downloadPath']);
if($full === false || !is_file($full) || strpos($full, $base.DIRECTORY_SEPARATOR) !== 0 || !is_readable($full)){
    ghoti::logError("store.download.php", "Digital product ".$product['sku']." has an unreadable file");
    storeDownloadFail('This file is no longer available. Contact us quoting '.$order['reference'].'.', 410);
}

//Claim first, send second. The claim is a conditional update, so two parallel
//requests cannot both take the last remaining download.
if(!$storedb->claimDownload($grant['downloadId'])){
    storeDownloadFail('This download link has expired, or its download limit is used up.', 410);
}

ghoti::logInfo("store.download.php", "Download ".($grant['downloads'] + 1)." of ".$grant['maxDownloads']." for order ".$order['reference']." (".$product['sku'].")");

//Nothing below reads the session, and PHP holds an exclusive lock on the session
//file for the life of a request: without this, one buyer downloading a large file
//on a slow connection blocks every other request of their own session until it
//finishes.
session_write_close();

$name = basename($product['downloadPath']);
$name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
if($name === '' || $name === '-'){ $name = 'download'; }

//Always served as an attachment with a generic type: a purchased .html or .svg
//rendered inline would run in this site's origin.
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="'.$name.'"');
header('Content-Length: '.(string)filesize($full));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
while(ob_get_level() > 0){ @ob_end_clean(); }
readfile($full);
exit;
?>
