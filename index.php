<?php
//load the system
//ghoti.php pulls in ghoti.async.php (the fetch/JSON RPC layer + core endpoints)
require_once 'ghoti.php';

//apply admin-managed Site Settings (ghoti.settings.json) over the defaults
ghoti::loadSettings();

//initialize session
$secure = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
@ini_set('session.cookie_httponly', 1);
@ini_set('session.cookie_secure', $secure ? 1 : 0);
@ini_set('session.use_only_cookies', 1);
@ini_set('session.use_strict_mode', 1);
if (defined('PHP_VERSION_ID') && PHP_VERSION_ID >= 70300) {
    @ini_set('session.cookie_samesite', 'Strict');
}
//sessionName is a static; read it without constructing a ghoti (which would
//needlessly open a DB connection and run table checks on every request).
@session_name(ghoti::$sessionName);
@session_set_cookie_params(0, '/', '', $secure, true);
@session_start();

$timeout = 1800; // 30 minutes inactivity timeout
if (!isset($_SESSION['created'])) {
    $_SESSION['created'] = time();
}
if (!isset($_SESSION['user_agent'])) {
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
}
if (!isset($_SESSION['ip_address'])) {
    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
}
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== $_SESSION['ip_address']
    || ($_SERVER['HTTP_USER_AGENT'] ?? '') !== $_SESSION['user_agent'])) {
    session_unset();
    session_destroy();
    @session_start();
    $_SESSION = array();
}
$_SESSION['last_activity'] = time();

//Im going to a load the ghoti object instance into a session variable here
$_SESSION['ghotiObj'] = new ghoti();

//load the modules add module name into array like "module1","module2"
$modules = array("links","login","banners","comments","analytics");
$_SESSION['ghotiObj']->loadModules($modules);

//Initialize each module you want active
//If there's a sweet way to automate this with that array above, I don't know it.
//Im going to a load the module object instances into session variables here.
$_SESSION['linksObj'] = new links();
$_SESSION['loginObj'] = new login();
$_SESSION['bannersObj'] = new banners();
$_SESSION['commentsObj'] = new comments();
$_SESSION['analyticsObj'] = new analytics();
//(removed the unused $_SESSION['ghotidb'] = new ghotidb() - it was written every
// request and never read; $_SESSION['ghotiObj']->ghotidb is the one used.)
//dispatch an async (fetch) call if this request is one; otherwise fall
//through and render the page normally.
ghoti_async_handle_request();

//process GET & SESSION variables
//The theme name is concatenated into an include() path below, so it must be
//strictly validated - otherwise a value like "../../somewhere" is a path
//traversal / local file inclusion. Restrict to a safe filename charset.
$isValidTheme = function($t){
	return is_string($t) && preg_match('/^[A-Za-z0-9_-]+$/', $t)
		&& is_file("css/".$t."/".$t.".php");
};

if(isset($_SESSION['theme'])){ //if a session theme var is set, we want to use that as the default.
	if($isValidTheme($_SESSION['theme'])){
		  ghoti::$defaultTheme = $_SESSION['theme']; //then set it as default theme
	}
}

if($_GET){
	if(isset($_GET['theme'])){ //if this is set, it overrides the session variable
		if($isValidTheme($_GET['theme'])){
			ghoti::$defaultTheme = $_GET['theme']; //then set it as default theme
			$_SESSION['theme'] = $_GET['theme']; //and save it to the session
		}
	}

}

//load the default theme
include_once "css/".ghoti::$defaultTheme."/".ghoti::$defaultTheme.".php";

//The page has fully rendered; drop the per-request service objects so they are
//not serialized into the session (see ghoti_free_request_objects).
ghoti_free_request_objects();
?>