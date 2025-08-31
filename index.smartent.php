<?php 
//load the system
require_once 'lib/Sajax.php';
require_once 'ghoti.php';
require_once 'ghoti.ajax.php';
$ghoti = new ghoti();

//sajax debugging. 
//Very annoying and unneeded if you use firebug with firefox
$sajax_debug_mode = 0; 			//0 = off 1 = on

//initialize session 
@session_name($ghoti->sessionName);
@session_start();

//Im going to a load the ghoti object instance into a session variable here
$_SESSION['ghotiObj'] = new ghoti();

//load the modules add module name into array like "module1","module2"
$modules = array("links","login","banners","comments","sensors");
$_SESSION['ghotiObj']->loadModules($modules);

//Initialize each module you want active
//If there's a sweet way to automate this with that array above, I don't know it.
//Im going to a load the module object instances into session variables here.
$_SESSION['linksObj'] = new links();
$_SESSION['loginObj'] = new login();
$_SESSION['bannersObj'] = new banners();
$_SESSION['commentsObj'] = new comments();
$_SESSION['sensorsObj'] = new sensors();
$_SESSION['relaysObj'] = new relays();
$_SESSION['schedulesObj'] = new schedules();

//inititalize sajax
sajax_init();
sajax_handle_client_request();

//process GET & SESSION variables
if(isset($_SESSION['theme'])){ //if a session theme var is set, we want to use that as the default.
	//Check whether file exists first
	if(file_exists("css/".$_SESSION['theme']."/".$_SESSION['theme'].".php")){
		  ghoti::$defaultTheme = $_SESSION['theme']; //then set it as default theme
	}
}

if($_GET){
	if(isset($_GET['theme'])){ //if this is set, it overrides the session variable
		//Check whether file exists first
		if(file_exists("css/".$_GET['theme']."/".$_GET['theme'].".php")){
			ghoti::$defaultTheme = $_GET['theme']; //then set it as default theme
			$_SESSION['theme'] = $_GET['theme']; //and save it to the session
		}
	}

}

//load the default theme
include_once "css/".ghoti::$defaultTheme."/".ghoti::$defaultTheme.".php";


?>
