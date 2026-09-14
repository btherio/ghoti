<?php
/* Authenticated backup downloads and restore uploads. Kept outside the async
 * JSON transport so large binary files can be streamed with bounded memory. */
require_once __DIR__.'/ghoti.php';
require_once __DIR__.'/mod/login/login.db.php';
require_once __DIR__.'/ghoti.backup.lib.php';

ghoti::loadSettings();
ghoti_security_enforce_ip_access();

$secure = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
@ini_set('session.cookie_httponly', 1);
@ini_set('session.cookie_secure', $secure ? 1 : 0);
@ini_set('session.use_only_cookies', 1);
@ini_set('session.use_strict_mode', 1);
if(defined('PHP_VERSION_ID') && PHP_VERSION_ID >= 70300){ @ini_set('session.cookie_samesite', 'Strict'); }
@session_name(ghoti::$sessionName);
@session_set_cookie_params(0, '/', '', $secure, true);
@session_start();

function ghoti_backup_response($status, $message){
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(array('success'=>$status >= 200 && $status < 300, 'message'=>$message), JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function ghoti_backup_deny($reason){
    ghoti::logWarn('backup.php:deny', $reason.' from '.ghoti_remote_addr());
    if(ghoti_request_method() === 'POST'){ ghoti_backup_response(403, 'Backup authorization failed.'); }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo "Forbidden\n";
    exit;
}

if(!isset($_SESSION['loggedIn'], $_SESSION['userId']) || $_SESSION['loggedIn'] !== true){ ghoti_backup_deny('not logged in'); }
$token = ghoti_request_method() === 'POST' ? ($_POST['token'] ?? '') : ($_GET['token'] ?? '');
if(!ghoti_csrf_verify(is_string($token) ? $token : '')){ ghoti_backup_deny('invalid CSRF token'); }

$loginDb = new logindb();
if(!ghoti_validate_session($loginDb) || !$loginDb->isAdmin((int)$_SESSION['userId'])){ ghoti_backup_deny('non-admin or expired session'); }

$action = isset($_GET['action']) && is_string($_GET['action']) ? $_GET['action'] : '';
$allowed = array('download-files','download-database','restore-files','restore-database');
if(!in_array($action, $allowed, true)){ ghoti_backup_response(400, 'Unknown backup action.'); }

if(strpos($action, 'download-') === 0 && ghoti_request_method() !== 'GET'){ ghoti_backup_response(405, 'Downloads require GET.'); }
if(strpos($action, 'restore-') === 0 && ghoti_request_method() !== 'POST'){ ghoti_backup_response(405, 'Restore requires POST.'); }

try{
    if($action === 'download-files' || $action === 'download-database'){
        $result = $action === 'download-files'
            ? ghoti_backup_create_site_archive(__DIR__)
            : ghoti_backup_create_sql_dump();
        $path = $result['path'];
        register_shutdown_function(function() use ($path){ @unlink($path); });
        $extension = $action === 'download-files' ? 'zip' : 'sql';
        $contentType = $extension === 'zip' ? 'application/zip' : 'application/sql; charset=utf-8';
        $filename = 'ghoti-'.($extension === 'zip' ? 'site' : 'database').'-'.gmdate('Ymd-His').'.'.$extension;
        ghoti::logInfo('backup.php:download', $action.' by uid '.(int)$_SESSION['userId']);
        session_write_close();
        header('Content-Type: '.$contentType);
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Content-Length: '.filesize($path));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    $confirmation = isset($_POST['confirmation']) && is_string($_POST['confirmation']) ? $_POST['confirmation'] : '';
    $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    if($confirmation !== 'RESTORE'){ ghoti_backup_response(400, 'Type RESTORE exactly to continue.'); }
    if($password === '' || strlen($password) > validate::MAX_PASSWORD){ ghoti_backup_response(403, 'Current admin password is required.'); }
    $failures = array_values(array_filter((array)($_SESSION['backup_restore_failures'] ?? array()), function($time){ return (int)$time >= time() - 600; }));
    if(count($failures) >= 5){ ghoti_backup_response(429, 'Too many password checks. Try again later.'); }
    $username = $loginDb->getUserNameById((int)$_SESSION['userId']);
    $verifiedId = is_string($username) ? $loginDb->authenticate($username, $password) : false;
    unset($password);
    if((int)$verifiedId !== (int)$_SESSION['userId']){
        $failures[] = time();
        $_SESSION['backup_restore_failures'] = $failures;
        ghoti::logWarn('backup.php:restore', 'admin password reconfirmation failed for uid '.(int)$_SESSION['userId']);
        ghoti_backup_response(403, 'Current admin password was not accepted.');
    }
    unset($_SESSION['backup_restore_failures']);

    if(!isset($_FILES['backup']) || !is_array($_FILES['backup']) || ($_FILES['backup']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !isset($_FILES['backup']['tmp_name']) || !is_uploaded_file($_FILES['backup']['tmp_name'])){
        ghoti_backup_response(400, 'Choose a backup file that uploaded successfully.');
    }
    $upload = $_FILES['backup']['tmp_name'];
    $size = (int)($_FILES['backup']['size'] ?? 0);
    $max = $action === 'restore-files' ? 268435456 : 134217728;
    if($size < 1 || $size > $max){ ghoti_backup_response(413, 'Backup file exceeds the upload safety limit.'); }

    if($action === 'restore-files'){
        $count = ghoti_backup_restore_site_archive($upload, __DIR__);
        ghoti::logInfo('backup.php:restore', "restored $count site files by uid ".(int)$_SESSION['userId']);
        ghoti_backup_response(200, "Site restore completed: $count files applied. Reload the site and verify it now.");
    }
    $tableCount = ghoti_backup_restore_sql($upload);
    ghoti::logInfo('backup.php:restore', "restored $tableCount database tables by uid ".(int)$_SESSION['userId']);
    ghoti_backup_response(200, "Database restore completed: $tableCount tables replaced. Sign in again if your session changed.");
}catch(Throwable $e){
    ghoti::logException('backup.php', $e, $action);
    ghoti_backup_response(500, 'The backup operation failed. Review the application log for details.');
}
?>
