<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
/*
 * Run: php tests/store-disabled.php - no database, no PayPal, no network.
 *
 * The store is an optional module that takes money and widens this site's
 * content-security policy, so "off by default, and off means absent" is a
 * property worth testing on its own. Mirrors tests/vhosts-disabled.php.
 */
chdir(dirname(__DIR__));
require_once 'ghoti.php';
require_once 'mod/login/login.async.php';
function storeCheck($ok, $label){ if(!$ok){throw new RuntimeException($label);} }

storeCheck(ghoti::$enableStore === false, 'Module default must be off');
storeCheck(!in_array('store', ghoti::enabledModules(), true), 'Disabled module is bootstrapped');
storeCheck(!ghoti_async_is_registered('storeBeginCheckout'), 'Disabled module registers RPC');
storeCheck(!str_contains((new loginui())->printAdminMenu(), 'showStoreManager'), 'Disabled menu exposed');
storeCheck(ghoti::currentSettings()['enableStore'] === false, 'Setting missing from schema');

//The header must not load the payment script or its stylesheet while the module
//is off, and index.php must not widen the policy for a site with no shop.
$header = file_get_contents('ghoti.header.php');
storeCheck(strpos($header, "if(ghoti::\$enableStore){") !== false, 'Store assets are not behind the enable switch');
$index = file_get_contents('index.php');
storeCheck(strpos($index, "if(ghoti::\$enableStore){") !== false, 'PayPal origins are not behind the enable switch');
storeCheck(substr_count($index, 'paypal.com') > 0, 'PayPal origins missing from the policy entirely');

ghoti::$enableStore = true;
storeCheck(in_array('store', ghoti::enabledModules(), true), 'Opt-in not in bootstrap');
storeCheck(str_contains((new loginui())->printAdminMenu(), 'showStoreManager'), 'Opt-in menu missing');

//Load the module's definitions only; do not construct a database handle.
$loader = (new ReflectionClass(ghoti::class))->newInstanceWithoutConstructor();
$loader->loadModules(array('store'));
storeCheck(ghoti_async_is_registered('storeBeginCheckout'), 'Opt-in RPC missing');
storeCheck(ghoti_async_is_registered('showStoreManager'), 'Opt-in admin RPC missing');

//Even loaded, the module sells nothing until PayPal credentials exist.
storeCheck(StorePaypalClient::configured(storedb::defaultSettings()) === false, 'An unconfigured store claims it can take payment');
storeCheck(StorePaypalClient::configured(array('paypalClientId'=>'id','paypalSecret'=>'secret')) === true, 'A configured store reports itself unconfigured');

//The shortcode must not render (or fatal) when the module object is absent.
$_SESSION = array();
storeCheck(store_shortcode_expand(array('[store:all]', 'all')) === '', 'Shortcode rendered without the module loaded');

// Use an isolated settings file to exercise persistence without production changes.
$file = tempnam(sys_get_temp_dir(), 'ghoti-store-settings-');
ghoti::$settingsFile = str_repeat('../', count(explode('/', trim(realpath(__DIR__.'/..'), '/')))).ltrim($file, '/');
ghoti::$ghotiLog = $file.'.log';
try {
	storeCheck(ghoti::saveSettings(array('enableStore'=>1)) === true, 'Could not save opt-in');
	ghoti::$enableStore = false; ghoti::loadSettings();
	storeCheck(ghoti::$enableStore === true, 'Opt-in not persisted');
	storeCheck(ghoti::saveSettings(array('enableStore'=>0)) === true, 'Could not save disable');
	storeCheck(ghoti::$enableStore === false, 'Disable not applied');
	echo "PASS: store default-off, menu/RPC bootstrap, unconfigured payments and saved opt-in\n";
} finally {unlink($file); if(is_file($file.'.log'))unlink($file.'.log');}
