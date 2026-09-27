<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
/*
 * Run: php tests/site-settings-contract.php - no database, no browser, no network.
 *
 * The bug that started this: the Site Settings form rendered a working "Pong"
 * checkbox (#set-enableBpong), but saveSiteSettings() in ghoti.js never read it
 * into the payload it POSTs. saveSettings() skips any key the client did not
 * send, so ticking the box and pressing Save reported success and changed
 * nothing. The log showed four saves in twenty-five seconds - somebody trying
 * again and again - and not one error line, because nothing anywhere failed.
 *
 * printSiteSettingsForm() already documents this as a contract ("The #set-* ids
 * are a contract with saveSiteSettings() in ghoti.js ... A renamed or dropped id
 * does not error, it just silently stops saving that setting"). A documented
 * contract that nothing checks is how it broke, so this checks it:
 *
 *   every #set-<key> the form renders for a settings-schema key must be read by
 *   saveSiteSettings(), and every key it sends must be one the schema accepts.
 */
chdir(dirname(__DIR__));
require_once 'ghoti.php';

$checks = 0;
function settingsCheck($ok, $label){
	global $checks; $checks++;
	if(!$ok){ throw new RuntimeException($label); }
}

/* ---------------- what the form renders ---------------- */

$html = (new ghotiui())->printSiteSettingsForm();
settingsCheck(preg_match_all('/id="set-([A-Za-z0-9_]+)"/', $html, $matches) > 0, 'The settings form rendered no #set-* controls at all');
$rendered = array_values(array_unique($matches[1]));
settingsCheck(count($rendered) >= 20, 'The settings form rendered suspiciously few controls: '.count($rendered));

/* ---------------- what the browser sends ---------------- */

$js = file_get_contents('ghoti.js');
settingsCheck(preg_match('/function saveSiteSettings\s*\(\s*\)\s*\{(.*?)\n\}/s', $js, $fn) === 1, 'saveSiteSettings() could not be found in ghoti.js');
$body = $fn[1];

//The keys of the object literal it POSTs...
preg_match_all('/^\s*([A-Za-z0-9_]+)\s*:/m', $body, $sentMatch);
$sent = array_values(array_unique($sentMatch[1]));
//...and the control ids it actually reads, which must line up with the keys.
preg_match_all('/#set-([A-Za-z0-9_]+)/', $body, $readMatch);
$read = array_values(array_unique($readMatch[1]));

$schema = array_keys(ghoti::currentSettings());

/* ---------------- the contract ---------------- */

//1. A control the form renders for a schema key must be read by the save.
//   This is the one that was broken: enableBpong rendered, never read.
foreach($rendered as $key){
	if(!in_array($key, $schema, true)){ continue; } //not a settings key; not this contract's business
	settingsCheck(in_array($key, $read, true),
		"The form renders #set-$key but saveSiteSettings() never reads it - ticking it would silently do nothing");
	settingsCheck(in_array($key, $sent, true),
		"saveSiteSettings() reads #set-$key but does not send it under that name");
}

//2. Nothing is read from a control the form does not render, which would send a
//   blank or "unchecked" over a real saved value.
foreach($read as $key){
	settingsCheck(in_array($key, $rendered, true),
		"saveSiteSettings() reads #set-$key, which the settings form does not render");
}

//3. Every key sent is one saveSettings() will actually accept; anything else is
//   silently dropped on the floor at the other end.
foreach($sent as $key){
	settingsCheck(in_array($key, $schema, true),
		"saveSiteSettings() sends '$key', which is not in the settings schema");
}

//4. The optional-module switches are the ones this bug class keeps hitting, and
//   the ones whose failure is most invisible - the module simply never appears.
foreach(array('enableBoards','enableVhosts','enableStore','enableBpong') as $key){
	settingsCheck(in_array($key, $schema, true), "$key is missing from the settings schema");
	settingsCheck(in_array($key, $rendered, true), "$key has no checkbox in the settings form");
	settingsCheck(in_array($key, $read, true), "$key is rendered but never read - the module could not be switched on");
}

//5. defaultPageTitle is deliberately NOT in this form (it is chosen in Manage
//   Pages). Pin that, so it is not "fixed" into the form by someone reading
//   rule 3 and finding it missing.
settingsCheck(in_array('defaultPageTitle', $schema, true), 'defaultPageTitle left the settings schema');
settingsCheck(!in_array('defaultPageTitle', $rendered, true), 'defaultPageTitle appeared in the settings form; the home page is chosen in Manage Pages');

/* ---------------- and the round trip actually persists ---------------- */

//A settings key is only really wired up if a save of it survives a reload, so
//prove it end to end on a temporary file rather than the real one.
$realFile = ghoti::$settingsFile;
$tmpName = 'ghoti.settings.test-'.getmypid().'.json';
ghoti::$settingsFile = $tmpName;
$restore = ghoti::currentSettings();
try{
	foreach(array('enableBpong','enableBoards','enableStore','enableVhosts') as $key){
		settingsCheck(ghoti::saveSettings(array($key => 1)) === true, "Could not save $key=1");
		ghoti::$$key = false;         //forget it, the way a new request would
		ghoti::loadSettings();
		settingsCheck(ghoti::$$key === true, "$key did not survive a save and reload");
		settingsCheck(ghoti::saveSettings(array($key => 0)) === true, "Could not save $key=0");
		ghoti::loadSettings();
		settingsCheck(ghoti::$$key === false, "$key could not be switched back off");
	}
}finally{
	if(is_file(ghoti::settingsPath())){ unlink(ghoti::settingsPath()); }
	ghoti::$settingsFile = $realFile;
	foreach($restore as $key => $value){ ghoti::$$key = $value; }
}

echo "PASS: $checks site settings contract assertions\n";
