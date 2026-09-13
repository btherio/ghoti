<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
chdir(dirname(__DIR__));
require_once 'ghoti.php';
require_once 'mod/login/login.async.php';
function vhostCheck($ok, $label){ if(!$ok){throw new RuntimeException($label);} }
vhostCheck(ghoti::$enableVhosts === false, 'Module default must be off');
vhostCheck(!in_array('vhosts', ghoti::enabledModules(), true), 'Disabled module is bootstrapped');
vhostCheck(!ghoti_async_is_registered('printVhostsPanel'), 'Disabled module registers RPC');
vhostCheck(!str_contains((new loginui())->printAdminMenu(), 'showVhosts'), 'Disabled menu exposed');
vhostCheck(ghoti::currentSettings()['enableVhosts'] === false, 'Setting missing from schema');
ghoti::$enableVhosts = true;
vhostCheck(in_array('vhosts', ghoti::enabledModules(), true), 'Opt-in not in bootstrap');
vhostCheck(str_contains((new loginui())->printAdminMenu(), 'showVhosts'), 'Opt-in menu missing');
// Load the module's definitions only; do not instantiate a DB or run sudo.
$loader = (new ReflectionClass(ghoti::class))->newInstanceWithoutConstructor();
$loader->loadModules(array('vhosts'));
vhostCheck(ghoti_async_is_registered('printVhostsPanel'), 'Opt-in RPC missing');
ghoti::$enableVhosts = false;
vhostCheck(!vhostsRequireAdmin(), 'Loaded module bypasses disable switch');
$helper = new VhostsHelper(array('enabled'=>true, 'helperPath'=>'/must-not-execute'));
vhostCheck(!$helper->run('ping')['ok'], 'Disabled helper executes');
vhostCheck(!$helper->canWrite(), 'Disabled helper permits writes');
// Use an isolated settings file to exercise persistence without production changes.
$file = tempnam(sys_get_temp_dir(), 'ghoti-vhosts-settings-');
// Resolve relative traversal from the repository root, independent of cwd.
ghoti::$settingsFile = str_repeat('../', count(explode('/', trim(realpath(__DIR__.'/..'), '/')))).ltrim($file, '/');
ghoti::$ghotiLog = $file.'.log';
try {
 vhostCheck(ghoti::saveSettings(array('enableVhosts'=>1)) === true, 'Could not save opt-in');
 ghoti::$enableVhosts=false; ghoti::loadSettings();
 vhostCheck(ghoti::$enableVhosts === true, 'Opt-in not persisted');
 vhostCheck(ghoti::saveSettings(array('enableVhosts'=>0)) === true, 'Could not save disable');
 vhostCheck(ghoti::$enableVhosts === false, 'Disable not applied');
 echo "PASS: vhosts default-off, menu/RPC bootstrap, helper guard and saved opt-in\n";
} finally {unlink($file); if(is_file($file.'.log'))unlink($file.'.log');}
