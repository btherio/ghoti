<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
/*
 * Run: php tests/bpong-disabled.php - no database, no browser, no network.
 *
 * Off by default, and off means absent: no menu entry, no endpoints, no assets
 * loaded, and a page that still contains [bpong:game] renders without it rather
 * than breaking. Mirrors tests/store-disabled.php and tests/vhosts-disabled.php.
 */
chdir(dirname(__DIR__));
require_once 'ghoti.php';
require_once 'mod/login/login.async.php';

$checks = 0;
function bpongCheck($ok, $label){
	global $checks; $checks++;
	if(!$ok){ throw new RuntimeException($label); }
}

bpongCheck(ghoti::$enableBpong === false, 'Module default must be off');
bpongCheck(!in_array('bpong', ghoti::enabledModules(), true), 'Disabled module is bootstrapped');
bpongCheck(!ghoti_async_is_registered('showBpongManager'), 'Disabled module registers RPC');
bpongCheck(!ghoti_async_is_registered('saveBpongSettings'), 'Disabled module registers its save RPC');
bpongCheck(!str_contains((new loginui())->printAdminMenu(), 'showBpongManager'), 'Disabled menu exposed');
bpongCheck(ghoti::currentSettings()['enableBpong'] === false, 'Setting missing from schema');

//The board's script and stylesheet must be behind the switch: an ordinary
//visitor to a site with no pong board should not download either.
$header = file_get_contents('ghoti.header.php');
bpongCheck(strpos($header, "if(ghoti::\$enableBpong){") !== false, 'Pong assets are not behind the enable switch');
bpongCheck(substr_count($header, 'mod/bpong/') === 2, 'Expected exactly the script and the stylesheet behind the switch');

//A page that still holds the shortcode must not break. With the module off no
//handler is registered, and the engine leaves an unknown tag alone - so the
//literal text stays on the page. That is the same behaviour every other module's
//shortcode has, and it is documented rather than special-cased here.
$_SESSION = array();
$off = ghoti_expand_shortcodes('<p>Hello</p>[bpong:game]');
bpongCheck(strpos($off, '<p>Hello</p>') === 0, 'Page content was damaged with the module off');
bpongCheck(strpos($off, '<canvas') === false, 'A board rendered with the module off');
bpongCheck($off === '<p>Hello</p>[bpong:game]', 'An unknown tag was not left untouched: '.$off);

ghoti::$enableBpong = true;
bpongCheck(in_array('bpong', ghoti::enabledModules(), true), 'Opt-in not in bootstrap');
bpongCheck(str_contains((new loginui())->printAdminMenu(), 'showBpongManager'), 'Opt-in menu missing');

//Load the module's definitions only; do not construct a database handle.
$loader = (new ReflectionClass(ghoti::class))->newInstanceWithoutConstructor();
$loader->loadModules(array('bpong'));
bpongCheck(ghoti_async_is_registered('showBpongManager'), 'Opt-in admin RPC missing');
bpongCheck(ghoti_async_is_registered('saveBpongSettings'), 'Opt-in save RPC missing');
bpongCheck(isset($GLOBALS['ghoti_shortcode_handlers']['bpong']), 'Opt-in shortcode missing');

//Loaded but not bootstrapped: the shortcode still must not fatal.
$_SESSION = array();
bpongCheck(bpong_shortcode_expand(array('[bpong:game]', 'game')) === '', 'The shortcode fatalled without the module object');

// Use an isolated settings file to exercise persistence without production changes.
$file = tempnam(sys_get_temp_dir(), 'ghoti-bpong-settings-');
ghoti::$settingsFile = str_repeat('../', count(explode('/', trim(realpath(__DIR__.'/..'), '/')))).ltrim($file, '/');
ghoti::$ghotiLog = $file.'.log';
try {
	bpongCheck(ghoti::saveSettings(array('enableBpong'=>1)) === true, 'Could not save opt-in');
	ghoti::$enableBpong = false; ghoti::loadSettings();
	bpongCheck(ghoti::$enableBpong === true, 'Opt-in not persisted');
	bpongCheck(ghoti::saveSettings(array('enableBpong'=>0)) === true, 'Could not save disable');
	bpongCheck(ghoti::$enableBpong === false, 'Disable not applied');
	echo "PASS: $checks assertions; pong default-off, menu/RPC/shortcode bootstrap, assets gated and saved opt-in\n";
} finally {unlink($file); if(is_file($file.'.log'))unlink($file.'.log');}
