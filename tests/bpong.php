<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
/*
 * Run: php tests/bpong.php - no database, no browser, no network.
 *
 * The physics live in tests/bpong-game.js, which runs the port against the
 * Python original's own cases. This file covers the PHP half: the shortcode,
 * the admin gate, the settings clamp, and the markup the board is built from.
 *
 * The claim most worth protecting is that this module has no endpoint a player
 * can reach. A pong board on a public page is only harmless while nothing about
 * a match is submitted, so "the only endpoints are admin-gated" is asserted
 * here rather than left to a reading of the source.
 */
chdir(dirname(__DIR__));
require_once 'ghoti.php';

$checks = 0;
function bpongCheck($ok, $label){
	global $checks; $checks++;
	if(!$ok){ throw new RuntimeException($label); }
}

//The admin gate calls isAdmin() from the login module, which is not loaded here.
function isAdmin($userId){ return !empty($GLOBALS['bpongTestAdmin']); }
function bpongTestSignIn($isAdmin){
	$GLOBALS['bpongTestAdmin'] = $isAdmin;
	$_SESSION['loggedIn'] = true;
	$_SESSION['userId'] = 1;
}
function bpongTestSignOut(){
	$GLOBALS['bpongTestAdmin'] = false;
	unset($_SESSION['loggedIn'], $_SESSION['userId']);
}

/* A stand-in for bpongdb: the two methods the module calls, backed by an array.
 * No table is touched and no connection is opened. */
class BpongDbFake{
	public $settings;
	public $saveCalls = 0;
	public $failSave = false;
	public function __construct($settings = array()){
		$this->settings = array_merge(bpongdb::defaultSettings(), $settings);
	}
	public function getSettings(){ return $this->settings; }
	public function saveSettings($settings){
		$this->saveCalls++;
		if($this->failSave){ return false; }
		foreach(array('winningScore','paddleHeight','cpuSpeed') as $key){
			if(isset($settings[$key])){ $settings[$key] = bpongdb::clamp($key, $settings[$key]); }
		}
		$this->settings = array_merge($this->settings, $settings);
		return true;
	}
}

ghoti::$enableBpong = true;
$loader = (new ReflectionClass(ghoti::class))->newInstanceWithoutConstructor();
$loader->loadModules(array('bpong'));

function bpongModule($settings = array()){
	$module = (new ReflectionClass(bpong::class))->newInstanceWithoutConstructor();
	$module->bpongdb = new BpongDbFake($settings);
	$module->bpongui = new bpongui();
	return $module;
}

/* ---------------- settings ---------------- */

$defaults = bpongdb::defaultSettings();
//The defaults are the terminal game's constants. If these drift, the board stops
//being the game it was ported from.
bpongCheck($defaults['winningScore'] === 7, 'Default winning score is not 7');
bpongCheck($defaults['paddleHeight'] === 5, 'Default paddle height is not 5');
bpongCheck($defaults['cpuSpeed'] === 155, 'Default CPU speed is not the original 15.5 cells/sec');
bpongCheck($defaults['showControls'] === true, 'The key legend is hidden by default');

//Clamping, not rejecting: every one of these is a bounded input on the form,
//and an out-of-range value makes an odd board rather than a security problem.
bpongCheck(bpongdb::clamp('winningScore', 0) === 1, 'Winning score not clamped up');
bpongCheck(bpongdb::clamp('winningScore', 9999) === 21, 'Winning score not clamped down');
bpongCheck(bpongdb::clamp('paddleHeight', 1) === 3, 'Paddle height not clamped up');
bpongCheck(bpongdb::clamp('paddleHeight', 99) === 11, 'Paddle height not clamped down');
bpongCheck(bpongdb::clamp('cpuSpeed', 1) === 60, 'CPU speed not clamped up');
bpongCheck(bpongdb::clamp('cpuSpeed', 100000) === 300, 'CPU speed not clamped down');
bpongCheck(bpongdb::clamp('cpuSpeed', 'nonsense') === 60, 'A non-numeric speed did not clamp to the floor');
bpongCheck(bpongdb::clamp('unknownKey', 42) === 42, 'An unknown key was clamped');
foreach(bpongdb::limits() as $key => $range){
	bpongCheck($range[0] < $range[1], "Limits for $key are inverted");
	bpongCheck(bpongdb::clamp($key, $defaults[$key]) === $defaults[$key], "The default for $key is outside its own limits");
}

/* ---------------- the shortcode ---------------- */

//With the module absent, the shortcode renders nothing rather than a broken
//board or a fatal - a page can outlive the module being switched off.
$_SESSION = array();
bpongCheck(bpong_shortcode_expand(array('[bpong:game]', 'game')) === '', 'The shortcode rendered without the module loaded');

$_SESSION['bpongObj'] = bpongModule();
$board = bpong_shortcode_expand(array('[bpong:game]', 'game'));
bpongCheck(strpos($board, '<canvas') !== false, 'The shortcode rendered no canvas');
bpongCheck(strpos($board, 'class="ghotiBpong"') !== false, 'The board is missing its hook class');
bpongCheck(strpos($board, 'tabindex="0"') !== false, 'The canvas cannot take focus, so the keys would have to bind to the document');
bpongCheck(strpos($board, 'aria-label=') !== false, 'The canvas has no accessible name');
bpongCheck(strpos($board, 'role="status"') !== false, 'There is no live region to announce the game state');
bpongCheck(strpos($board, 'data-bpong-action="restart"') !== false, 'There is no pointer route to restart');
bpongCheck(strpos($board, '<script') === false, 'The board emitted inline script');

//Case and spacing are the shortcode engine's business, but the argument is
//this module's: only "game" is a board.
bpongCheck(strpos(bpong_shortcode_expand(array('[bpong:GAME]', 'GAME')), '<canvas') !== false, 'The shortcode is case-sensitive');
$unknown = bpong_shortcode_expand(array('[bpong:nonsense]', 'nonsense'));
bpongCheck(strpos($unknown, '<canvas') === false, 'An unknown argument rendered a board');
bpongCheck(strpos($unknown, '[bpong:game]') !== false, 'An unknown argument did not say what to use instead');

//Registered against the real engine, so the tag actually expands in page content.
bpongCheck(isset($GLOBALS['ghoti_shortcode_handlers']['bpong']), 'The shortcode is not registered');
$page = ghoti_expand_shortcodes('<p>Before</p>[bpong:game]<p>After</p>');
bpongCheck(strpos($page, '<canvas') !== false, 'The tag did not expand in page content');
bpongCheck(strpos($page, '[bpong:game]') === false, 'The tag survived expansion');
bpongCheck(strpos($page, '<p>Before</p>') !== false && strpos($page, '<p>After</p>') !== false, 'Expansion damaged the surrounding content');

//Two boards on one page must be separately addressable, or they collide.
$two = ghoti_expand_shortcodes('[bpong:game] and [bpong:game]');
bpongCheck(substr_count($two, '<canvas') === 2, 'A second board did not render');
preg_match_all('/id="(ghotiBpong\d+)"/', $two, $ids);
bpongCheck(count($ids[1]) === 2 && $ids[1][0] !== $ids[1][1], 'Two boards on one page share an id: '.implode(',', $ids[1]));

/* ---------------- the config the board carries ---------------- */

$_SESSION['bpongObj'] = bpongModule(array('winningScore' => 3, 'paddleHeight' => 9, 'cpuSpeed' => 260));
$custom = $_SESSION['bpongObj']->board();
preg_match('/data-bpong-config="([^"]*)"/', $custom, $m);
bpongCheck(!empty($m[1]), 'The board carries no config');
$config = json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true);
bpongCheck($config['winningScore'] === 3, 'Winning score did not reach the board');
bpongCheck($config['paddleHeight'] === 9, 'Paddle height did not reach the board');
bpongCheck($config['cpuSpeed'] === 260, 'CPU speed did not reach the board');
bpongCheck(strpos($custom, 'first to 3') !== false, 'The legend does not state the winning score');

//The legend is optional; the board is not.
$_SESSION['bpongObj'] = bpongModule(array('showControls' => false));
$quiet = $_SESSION['bpongObj']->board();
bpongCheck(strpos($quiet, 'ghotiBpongKeys') === false, 'The key legend was rendered while switched off');
bpongCheck(strpos($quiet, '<canvas') !== false, 'Hiding the legend hid the board');

//The config is a JSON attribute, so it has to survive being quoted into HTML.
$_SESSION['bpongObj'] = bpongModule();
bpongCheck(strpos($_SESSION['bpongObj']->board(), 'data-bpong-config="{&quot;') !== false, 'The config attribute is not escaped');

/* ---------------- the admin gate ---------------- */

$_SESSION['bpongObj'] = bpongModule();
$db = $_SESSION['bpongObj']->bpongdb;

bpongTestSignOut();
bpongCheck(saveBpongSettings(7, 5, 155, 1) === 'Admin access required.', 'A signed-out caller saved pong settings');
bpongCheck($db->saveCalls === 0, 'A signed-out caller reached the database');
bpongCheck(strpos(showBpongManager(), 'Admin access required') !== false, 'The pong manager rendered for a signed-out caller');

//Signed in but not an admin is still not an admin.
$_SESSION['loggedIn'] = true; $_SESSION['userId'] = 2; $GLOBALS['bpongTestAdmin'] = false;
bpongCheck(saveBpongSettings(7, 5, 155, 1) === 'Admin access required.', 'A signed-in non-admin saved pong settings');

//Every registered endpoint in this module is admin-gated. A player never calls
//one, so any endpoint that answers a signed-out caller is a new attack surface.
bpongTestSignOut();
foreach(array('showBpongManager', 'saveBpongSettings') as $endpoint){
	bpongCheck(ghoti_async_is_registered($endpoint), "$endpoint is not registered");
	$answer = $endpoint === 'saveBpongSettings' ? saveBpongSettings(7, 5, 155, 1) : showBpongManager();
	bpongCheck(strpos((string)$answer, 'Admin access required') !== false, "$endpoint served a signed-out caller");
}
$registered = array_keys($GLOBALS['ghoti_async_registry'] ?? array());
foreach($registered as $name){
	if(stripos($name, 'bpong') === false){ continue; }
	bpongCheck(in_array($name, array('showBpongManager', 'saveBpongSettings'), true), "Unexpected pong endpoint registered: $name");
}

bpongTestSignIn(true);
bpongCheck(stripos(saveBpongSettings(3, 7, 200, 1), 'saved') !== false, 'An admin was refused');
bpongCheck($db->settings['winningScore'] === 3, 'Winning score not saved');
bpongCheck($db->settings['paddleHeight'] === 7, 'Paddle height not saved');
bpongCheck($db->settings['cpuSpeed'] === 200, 'CPU speed not saved');
bpongCheck($db->settings['showControls'] === true, 'Legend choice not saved');

//Out-of-range input is clamped rather than stored, and the board that results
//is still playable.
bpongCheck(stripos(saveBpongSettings(9999, 0, 99999, 0), 'saved') !== false, 'Clamped input was refused');
bpongCheck($db->settings['winningScore'] === 21, 'Winning score not clamped on save');
bpongCheck($db->settings['paddleHeight'] === 3, 'Paddle height not clamped on save');
bpongCheck($db->settings['cpuSpeed'] === 300, 'CPU speed not clamped on save');
bpongCheck($db->settings['showControls'] === false, 'Legend could not be switched off');

//A failed write says so instead of reporting success.
$db->failSave = true;
bpongCheck(stripos(saveBpongSettings(7, 5, 155, 1), 'failed') !== false, 'A failed save reported success');
$db->failSave = false;

/* ---------------- the admin screen ---------------- */

bpongTestSignIn(true);
$panel = showBpongManager();
bpongCheck(strpos($panel, '[bpong:game]') !== false, 'The admin screen does not say how to place a board');
bpongCheck(strpos($panel, '<canvas') !== false, 'The admin screen has no preview');
bpongCheck(strpos($panel, 'bpongWinningScore') !== false, 'The settings form is missing');
bpongCheck(strpos($panel, 'selected="selected"') !== false, 'The saved difficulty is not preselected');

//A speed saved outside the preset list must still appear, or it is silently
//reset the next time anyone saves the form.
$_SESSION['bpongObj'] = bpongModule(array('cpuSpeed' => 173));
$oddPanel = (new bpongui())->manageBpong($_SESSION['bpongObj']->bpongdb->getSettings());
bpongCheck(strpos($oddPanel, 'value="173" selected="selected"') !== false, 'A custom CPU speed vanished from the form');
bpongCheck(strpos($oddPanel, 'Custom (17.3 cells/sec)') !== false, 'A custom CPU speed is not labelled');

/* ---------------- the wiring ---------------- */

//A module missing from $validModules makes loadModuleSql() log at ERROR on
//every page load - and an ERROR line e-mails every administrator.
$dbSource = file_get_contents('ghoti.db.php');
bpongCheck(strpos($dbSource, "'bpong'") !== false, 'bpong is not in $validModules, so provisioning would log an ERROR every request');
bpongCheck(is_file('mod/bpong/bpong.sql'), 'bpong.sql is missing, which would log an ERROR every request');
$sql = file_get_contents('mod/bpong/bpong.sql');
bpongCheck(stripos($sql, 'create table if not exists bpong(') !== false, 'bpong.sql does not create a table named after the module');
foreach(array('winningScore','paddleHeight','cpuSpeed','showControls') as $column){
	bpongCheck(strpos($sql, '`'.$column.'`') !== false, "bpong.sql is missing the $column column");
}
//The module keeps no record of a match, and the schema is where that promise is
//either kept or quietly broken by a later addition.
bpongCheck(stripos($sql, 'score') === false || stripos($sql, 'winningScore') !== false, 'bpong.sql gained a score table');
bpongCheck(substr_count(strtolower($sql), 'create table') === 1, 'bpong.sql creates more than the settings table');

//The board draws with canvas and a same-origin script, so it must not have
//needed the content-security policy widened.
$index = file_get_contents('index.php');
bpongCheck(strpos($index, 'enableBpong') !== false, 'index.php does not bootstrap the module');
//The board is a canvas driven by a same-origin script, so it must not have
//needed a single origin adding. Checked against the policy block itself rather
//than the whole file, which mentions the module for unrelated reasons.
//Anchored on the code rather than a comment: the comments around this block
//have already been rewritten once by another change, which broke this check
//without anything being wrong with the module.
$policyStart = strpos($index, 'if(!headers_sent()){');
$policyEnd = strpos($index, 'Content-Security-Policy');
bpongCheck($policyStart !== false && $policyEnd !== false && $policyEnd > $policyStart, 'Could not find the policy block to check');
$policyBlock = substr($index, $policyStart, $policyEnd - $policyStart);
bpongCheck(stripos($policyBlock, 'bpong') === false, 'The pong module widened the content-security policy');
bpongCheck(strpos(file_get_contents('ghoti.async.php'), "'bpongObj'") !== false, 'bpongObj is not freed before the session is written');

//The board binds listeners rather than inline onclick attributes, so it is dead
//markup unless something initializes it after content is injected. This app
//replaces #ghotiContent through printPage() long after DOMContentLoaded - both
//for in-app page navigation and for the admin preview - so a board that only
//waits on that event never draws. This is the assertion that catches it.
$js = file_get_contents('mod/bpong/bpong.js');
bpongCheck(strpos($js, 'global.bpongInit = bpongInit') !== false, 'bpongInit is not exposed, so nothing can re-initialize a board');
bpongCheck(strpos($js, 'data-bpong-ready') !== false, 'bpongInit is not idempotent, so re-initializing would double-bind a board');
bpongCheck(strpos(file_get_contents('ghoti.js'), 'bpongInit(') !== false, 'printPage never initializes injected boards, so every board reached by in-app navigation is dead');

echo "PASS: $checks pong assertions; no database, no browser, no network\n";
