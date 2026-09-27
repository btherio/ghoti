<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
require __DIR__.'/../ghoti.php';
$checks = 0;
function backgroundCheck($ok, $message){ global $checks; if(!$ok){ throw new RuntimeException($message); } $checks++; }
$settings = tempnam(dirname(__DIR__), '.background-test-');
$log = tempnam(sys_get_temp_dir(), 'background-log-');
ghoti::$settingsFile = basename($settings);
ghoti::$ghotiLog = $log;
ghoti::$enableCriticalAlerts = false;
register_shutdown_function(function() use ($settings, $log){ @unlink($settings); @unlink($log); });
$_SESSION = array();
$logo = ghoti::$headerImg;
backgroundCheck(ghoti::$backgroundImg === '', 'Background override should default to blank');
backgroundCheck(ghoti::themeBackground('css/ghoticms/background.png') === 'css/ghoticms/background.png', 'Bundled fallback missing');
$path = 'css/ghoticms/background.png';
backgroundCheck(ghoti::saveSettings(array('backgroundImg'=>$path)) === true, 'Valid image path rejected');
ghoti::$backgroundImg = '';
ghoti::loadSettings();
backgroundCheck(ghoti::$backgroundImg === $path, 'Background did not persist');
backgroundCheck(ghoti::themeBackground('css/smurfius/background.png') === $path, 'Themes must share the override');
backgroundCheck(ghoti::$headerImg === $logo, 'Background changed the logo');
foreach(array('../outside.png', '/etc/passwd', 'https://example.test/bg.jpg', 'files/missing.png', 'ghoti.php', array('bad')) as $invalid){
    backgroundCheck(ghoti::saveSettings(array('backgroundImg'=>$invalid)) !== true, 'Invalid image path accepted');
    backgroundCheck(ghoti::$backgroundImg === $path, 'Rejected setting replaced valid background');
}
ghoti::$backgroundImg = 'files/deleted-background.png';
backgroundCheck(ghoti::themeBackground('css/smurfius/background.png') === 'css/smurfius/background.png', 'Missing image must fall back');
backgroundCheck(ghoti::saveSettings(array('backgroundImg'=>'')) === true, 'Clearing background failed');
backgroundCheck(ghoti::themeBackground('css/smurfius/background.png') === 'css/smurfius/background.png', 'Cleared background must use theme default');
echo "PASS: $checks background image assertions; temporary settings only\n";
