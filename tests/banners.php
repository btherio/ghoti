<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
/*
 * Run: php tests/banners.php - no database, no network, no Google.
 *
 * What is worth testing here is not "does an ad render" (only Google can answer
 * that) but the three things this site is responsible for:
 *
 *   1. Source selection. Local-only must never emit Google's tag; "both" must
 *      draw from one pool rather than doubling up a position; a size with no ad
 *      slot must fall back rather than reuse the other size's slot.
 *   2. Validation. The publisher and slot ids land inside a script URL and in
 *      attributes served to every visitor, so a value that does not match the
 *      pattern has to be refused, not escaped into submission.
 *   3. The content-security policy. It is assembled in index.php, and the bug
 *      it is easiest to reintroduce is assigning frame-src instead of
 *      accumulating it - which silently breaks PayPal on a site running both.
 */
chdir(dirname(__DIR__));
require_once 'ghoti.php';
require_once 'mod/banners/banners.ads.php';

$checks = 0;
function bannerCheck($ok, $label){
	global $checks; $checks++;
	if(!$ok){ throw new RuntimeException($label); }
}

//The admin gate calls isAdmin() from the login module, which is not loaded here.
function isAdmin($userId){ return !empty($GLOBALS['bannerTestAdmin']); }
function bannerTestSignIn($isAdmin){
	$GLOBALS['bannerTestAdmin'] = $isAdmin;
	$_SESSION['loggedIn'] = true;
	$_SESSION['userId'] = 1;
}
function bannerTestSignOut(){
	$GLOBALS['bannerTestAdmin'] = false;
	unset($_SESSION['loggedIn'], $_SESSION['userId']);
}

/* A stand-in for bannersdb: the methods banners.php and the endpoints call,
 * backed by arrays. No table is touched and no connection is opened. */
class BannersDbFake{
	public $settings;
	public $rows = array();
	public $saveCalls = 0;
	public function __construct($settings = array()){
		$this->settings = array_merge(bannersdbDefaults(), $settings);
	}
	public function getSettings(){ return $this->settings; }
	public function saveSettings($settings){
		$this->saveCalls++;
		$this->settings = array_merge($this->settings, $settings);
		return true;
	}
	public function countBanners($small = true){
		$n = 0;
		foreach($this->rows as $row){ if((int)$row[4] === ($small ? 1 : 0)){ $n++; } }
		return $n;
	}
	public function getRandomBanner($small = true){
		$eligible = array();
		foreach($this->rows as $row){ if((int)$row[4] === ($small ? 1 : 0)){ $eligible[] = $row; } }
		if(!$eligible){ return array(); }
		return array($eligible[array_rand($eligible)]);
	}
	public function getAllBanners(){ return $this->rows; }
}
function bannersdbDefaults(){
	//Mirrors bannersdb::defaultSettings(); asserted against the real one below,
	//so the fake cannot drift away from what the module actually stores.
	return array(
		'source' => 'local', 'adClient' => '', 'adSlotSmall' => '', 'adSlotLarge' => '',
		'adFormat' => 'auto', 'adFullWidth' => true, 'adTest' => false, 'adLabel' => '', 'updatedAt' => 0,
	);
}

/* ---------------- validation ---------------- */

$goodClient = 'ca-pub-1234567890123456';
bannerCheck(BannerAds::validClient($goodClient), 'A real publisher id was rejected');
foreach(array('', 'pub-1234567890123456', 'ca-pub-', 'ca-pub-12345', 'ca-pub-12345678901234567890123456789',
	'ca-pub-1234567890123456&x=1', "ca-pub-1234567890123456\"", 'ca-pub-123456789012345a',
	' ca-pub-1234567890123456', 'ca-pub-1234567890123456 ') as $bad){
	bannerCheck(!BannerAds::validClient($bad), 'Bad publisher id accepted: '.var_export($bad, true));
}
bannerCheck(BannerAds::validSlot('1234567890'), 'A real slot id was rejected');
foreach(array('', 'abc', '1234', '12345678"', '1234567890 ', '12-34567890') as $bad){
	bannerCheck(!BannerAds::validSlot($bad), 'Bad slot id accepted: '.var_export($bad, true));
}

//configured() is size-aware on purpose: a slot belongs to one ad unit.
$oneSlot = array('adClient'=>$goodClient, 'adSlotSmall'=>'1111111111', 'adSlotLarge'=>'');
bannerCheck(BannerAds::configured($oneSlot) === true, 'A publisher with one slot reported unconfigured');
bannerCheck(BannerAds::configured($oneSlot, true) === true, 'The configured size reported unconfigured');
bannerCheck(BannerAds::configured($oneSlot, false) === false, 'A missing slot borrowed the other size');
bannerCheck(BannerAds::configured(array('adClient'=>'', 'adSlotSmall'=>'1111111111')) === false, 'Slots without a publisher id counted as configured');

//The ads.txt line drops the ca- prefix and carries Google's certification id.
bannerCheck(BannerAds::adsTxtLine($goodClient) === 'google.com, pub-1234567890123456, DIRECT, f08c47fec0942fa0', 'ads.txt line is wrong: '.BannerAds::adsTxtLine($goodClient));
bannerCheck(BannerAds::adsTxtLine('nonsense') === '', 'ads.txt line built from an invalid publisher id');

/* ---------------- markup ---------------- */

$full = array_merge(bannersdbDefaults(), array(
	'source'=>'adsense', 'adClient'=>$goodClient, 'adSlotSmall'=>'1111111111', 'adSlotLarge'=>'2222222222',
));

BannerAds::resetLoader();
$unit = BannerAds::unit($full, true);
bannerCheck(strpos($unit, 'pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client='.$goodClient) !== false, 'Loader missing or malformed');
bannerCheck(strpos($unit, 'data-ad-slot="1111111111"') !== false, 'Small slot not used');
bannerCheck(strpos($unit, 'data-ad-client="'.$goodClient.'"') !== false, 'Publisher id not on the unit');
bannerCheck(strpos($unit, 'adsbygoogle=window.adsbygoogle||[]).push({})') !== false, 'Unit never pushed');
bannerCheck(strpos($unit, 'data-adtest') === false, 'Test mode leaked into a live unit');

//Themes render two or three positions per page; the loader is once per document.
$second = BannerAds::unit($full, false);
bannerCheck(strpos($second, 'adsbygoogle.js') === false, 'The loader was emitted twice on one page');
bannerCheck(strpos($second, 'data-ad-slot="2222222222"') !== false, 'Wide slot not used');
bannerCheck(strpos($second, 'push({})') !== false, 'Second unit never pushed');

//A size with no slot renders nothing at all, rather than a broken <ins>.
BannerAds::resetLoader();
bannerCheck(BannerAds::unit(array_merge($full, array('adSlotLarge'=>'')), false) === '', 'A slotless size emitted markup');
BannerAds::resetLoader();
bannerCheck(BannerAds::unit(array_merge($full, array('adClient'=>'')), true) === '', 'A publisher-less unit emitted markup');

//Test mode and the label.
BannerAds::resetLoader();
$test = BannerAds::unit(array_merge($full, array('adTest'=>true, 'adLabel'=>'Advertisement')), true);
bannerCheck(strpos($test, 'data-adtest="on"') !== false, 'Test mode not applied');
bannerCheck(strpos($test, '>Advertisement<') !== false, 'Label not rendered');

//An unknown format falls back to auto rather than being passed through.
BannerAds::resetLoader();
$odd = BannerAds::unit(array_merge($full, array('adFormat'=>'"><script>alert(1)</script>')), true);
bannerCheck(strpos($odd, '<script>alert(1)</script>') === false, 'An injected format reached the markup');
bannerCheck(strpos($odd, 'data-ad-format="auto"') !== false, 'Unknown format was not replaced with auto');

//A label is attacker-free by construction (it is validated on save), but it is
//printed to every visitor, so it is escaped on the way out as well.
BannerAds::resetLoader();
$xss = BannerAds::unit(array_merge($full, array('adLabel'=>'<img src=x onerror=alert(1)>')), true);
bannerCheck(strpos($xss, '<img src=x') === false, 'Label was rendered as markup');

/* ---------------- privacy signal ---------------- */

BannerAds::resetLoader();
$_SERVER['HTTP_SEC_GPC'] = '1';
$gpc = BannerAds::unit($full, true);
bannerCheck(strpos($gpc, 'requestNonPersonalizedAds=1') !== false, 'GPC did not request non-personalized ads');
//It has to come before the first push, or Google ignores it.
bannerCheck(strpos($gpc, 'requestNonPersonalizedAds=1') < strpos($gpc, 'push({})'), 'Non-personalized flag set after the first ad request');
unset($_SERVER['HTTP_SEC_GPC']);
BannerAds::resetLoader();
$normal = BannerAds::unit($full, true);
bannerCheck(strpos($normal, 'requestNonPersonalizedAds') === false, 'Non-personalized ads forced without a privacy signal');

/* ---------------- source selection ---------------- */

require_once 'mod/banners/banners.async.php';
require_once 'mod/banners/banners.php';

//The fake's defaults must match the module's, or every assertion below is
//testing a shape the application does not use.
bannerCheck(bannersdb::defaultSettings() == bannersdbDefaults(), 'The test fake has drifted from bannersdb::defaultSettings()');
bannerCheck(array_keys(bannersdb::validSources()) === array('local','adsense','both'), 'Source list changed');

function bannerModule($settings, $rows = array()){
	$module = (new ReflectionClass(banners::class))->newInstanceWithoutConstructor();
	$db = new BannersDbFake($settings);
	$db->rows = $rows;
	$module->bannersdb = $db;
	$module->bannersui = new bannersui();
	return $module;
}
$rows = array(
	array(1, 'One', 'https://example.com/1.png', 'https://example.com/', 1),
	array(2, 'Two', 'https://example.com/2.png', 'https://example.com/', 1),
	array(3, 'Wide', 'https://example.com/3.png', 'https://example.com/', 0),
);

//Local only: never Google's tag, whatever else is configured.
BannerAds::resetLoader();
$local = bannerModule(array_merge($full, array('source'=>'local')), $rows);
for($i = 0; $i < 40; $i++){
	$html = $local->displayBanner(true);
	bannerCheck(strpos($html, 'adsbygoogle') === false, 'Local-only emitted an ad');
	bannerCheck(strpos($html, '<img') !== false, 'Local-only rendered no banner');
}
bannerCheck($local->adsActive() === false, 'Local-only reported ads active');

//AdSense only: never a local row, even though rows exist.
BannerAds::resetLoader();
$ads = bannerModule(array_merge($full, array('source'=>'adsense')), $rows);
bannerCheck($ads->adsActive() === true, 'A configured ad source reported inactive');
for($i = 0; $i < 10; $i++){
	BannerAds::resetLoader();
	$html = $ads->displayBanner(true);
	bannerCheck(strpos($html, 'adsbygoogle') !== false, 'AdSense-only rendered no ad');
	bannerCheck(strpos($html, 'example.com/1.png') === false, 'AdSense-only rendered a local banner');
}
//A size with no slot shows nothing rather than falling back to local banners:
//the operator asked for ads in that position.
BannerAds::resetLoader();
$adsPartial = bannerModule(array_merge($full, array('source'=>'adsense', 'adSlotLarge'=>'')), $rows);
bannerCheck($adsPartial->displayBanner(false) === '', 'AdSense-only fell back to a local banner');

//Both: one pool, one winner per position - never an ad and a banner together.
$seenAd = false; $seenLocal = false;
for($i = 0; $i < 300; $i++){
	BannerAds::resetLoader();
	$both = bannerModule(array_merge($full, array('source'=>'both')), $rows);
	$html = $both->displayBanner(true);
	$isAd = strpos($html, 'adsbygoogle') !== false;
	$isLocal = strpos($html, '<img src="https://example.com/') !== false;
	bannerCheck(!($isAd && $isLocal), 'Both rendered an ad and a banner in one position');
	bannerCheck($isAd || $isLocal, 'Both rendered nothing');
	$seenAd = $seenAd || $isAd;
	$seenLocal = $seenLocal || $isLocal;
}
bannerCheck($seenAd, 'Both never drew the ad in 300 draws');
bannerCheck($seenLocal, 'Both never drew a local banner in 300 draws');

//Both, with no banners of this size: the ad wins every time rather than the
//position going empty.
for($i = 0; $i < 10; $i++){
	BannerAds::resetLoader();
	$empty = bannerModule(array_merge($full, array('source'=>'both')), array());
	bannerCheck(strpos($empty->displayBanner(true), 'adsbygoogle') !== false, 'Both left a position empty when only the ad was available');
}

//Both, with an unconfigured size: local banners carry that position alone.
BannerAds::resetLoader();
$mixed = bannerModule(array_merge($full, array('source'=>'both', 'adSlotLarge'=>'')), $rows);
for($i = 0; $i < 20; $i++){
	$html = $mixed->displayBanner(false);
	bannerCheck(strpos($html, 'adsbygoogle') === false, 'An unconfigured size served an ad under both');
	bannerCheck(strpos($html, 'example.com/3.png') !== false, 'An unconfigured size rendered nothing under both');
}

//A database that cannot be read leaves the defaults in place: local, no ads.
$broken = bannerModule(array());
bannerCheck($broken->adsActive() === false, 'Unreadable settings reported ads active');
bannerCheck(strpos($broken->displayBanner(true), 'adsbygoogle') === false, 'Unreadable settings emitted an ad');

/* ---------------- the save endpoint ---------------- */

$_SESSION['bannersObj'] = bannerModule(array());
$db = $_SESSION['bannersObj']->bannersdb;

bannerTestSignOut();
bannerCheck(saveBannerSettings('adsense', $goodClient, '1111111111', '', 'auto', 1, 0, '') === 'Admin access required.', 'A signed-out caller saved banner settings');
bannerCheck($db->saveCalls === 0, 'A signed-out caller reached the database');
bannerCheck(strpos(manageBanners(), 'Admin access required') !== false, 'The banner manager rendered for a signed-out caller');

bannerTestSignIn(true);
bannerCheck(saveBannerSettings('adsense', $goodClient, '1111111111', '2222222222', 'auto', 1, 0, 'Advertisement') !== 'Admin access required.', 'An admin was refused');
bannerCheck($db->settings['source'] === 'adsense', 'Source not saved');
bannerCheck($db->settings['adSlotLarge'] === '2222222222', 'Slot not saved');
bannerCheck($db->settings['adLabel'] === 'Advertisement', 'Label not saved');

//Rejections. Each one returns a message and writes nothing.
$before = $db->saveCalls;
foreach(array(
	array('nonsense', $goodClient, '1111111111', '', 'auto', 1, 0, ''),
	array('adsense', 'ca-pub-abc', '1111111111', '', 'auto', 1, 0, ''),
	array('adsense', $goodClient, 'not-a-slot', '', 'auto', 1, 0, ''),
	array('adsense', $goodClient, '1111111111', 'nope', 'auto', 1, 0, ''),
	array('adsense', '', '', '', 'auto', 1, 0, ''),
	array('both', '', '', '', 'auto', 1, 0, ''),
) as $args){
	$answer = call_user_func_array('saveBannerSettings', $args);
	bannerCheck(is_string($answer) && $answer !== 'Admin access required.' && stripos($answer, 'saved') === false,
		'Invalid settings were accepted: '.var_export($args, true).' -> '.var_export($answer, true));
}
bannerCheck($db->saveCalls === $before, 'A rejected save still wrote to the database');

//Local needs no ad credentials at all - turning ads off must always be possible.
bannerCheck(stripos(saveBannerSettings('local', '', '', '', 'auto', 1, 0, ''), 'saved') !== false, 'Switching back to local banners was refused');
bannerCheck($db->settings['source'] === 'local', 'Local not saved');

//An unknown format is normalized rather than rejected: it is a <select>, and
//the value is not attacker-controllable in any way that matters.
bannerCheck(stripos(saveBannerSettings('adsense', $goodClient, '1111111111', '', 'wat', 1, 0, ''), 'saved') !== false, 'A bad format blocked the save');
bannerCheck($db->settings['adFormat'] === 'auto', 'A bad format was stored verbatim');

/* ---------------- the admin panel ---------------- */

$panel = (new bannersui())->manageBanners($rows, array_merge($full, array('source'=>'adsense')));
bannerCheck(strpos($panel, 'ads.txt') !== false, 'The panel does not mention ads.txt');
bannerCheck(strpos($panel, 'google.com, pub-1234567890123456, DIRECT') !== false, 'The panel does not print the ads.txt line');
bannerCheck(strpos($panel, 'bannerSource') !== false, 'The source selector is missing');
bannerCheck(strpos($panel, 'selected="selected"') !== false, 'The saved source is not preselected');
$localPanel = (new bannersui())->manageBanners($rows, array_merge($full, array('source'=>'local')));
bannerCheck(strpos($localPanel, 'Google ads are switched off') !== false, 'The panel does not say ads are off');
//The help panel always explains AdSense setup; the *status* notes must not
//nag about a specific ads.txt line for a site that is not serving ads.
bannerCheck(strpos($localPanel, 'google.com, pub-1234567890123456, DIRECT') === false, 'The panel printed an ads.txt line with ads off');
//Called with no settings at all (an older caller), it must still render.
bannerCheck(strpos((new bannersui())->manageBanners($rows), 'Manage Banners') !== false, 'The panel failed without settings');

/* ---------------- content-security policy ---------------- */

//The header is assembled in index.php. Reading it is crude, but the alternative
//is running the whole bootstrap, and the property worth protecting is textual:
//the lists are accumulated, so two features can widen the policy at once.
$index = file_get_contents('index.php');
bannerCheck(strpos($index, '$frameSrc = array();') !== false, 'frame-src is no longer accumulated from an empty list');
bannerCheck(strpos($index, '$frameSrc = array_merge($frameSrc, $paypal);') !== false, 'PayPal origins are assigned rather than merged');
bannerCheck(strpos($index, 'array_merge($frameSrc, $google[\'frame\'])') !== false, 'Google frame origins are not merged in');
bannerCheck(strpos($index, 'adsActive()') !== false, 'The policy does not ask the module whether ads are on');
bannerCheck(preg_match('/\$frameSrc = \$frameSrc \? implode/', $index) === 1, "frame-src does not fall back to 'none'");

//Simulate the assembly the same way index.php does it, to prove a site running
//the shop and ads gets both sets of origins rather than the later one winning.
$scriptSrc = array("'self'", "'unsafe-inline'");
$connectSrc = array("'self'");
$frameSrc = array();
$paypal = array("https://www.paypal.com", "https://www.sandbox.paypal.com", "https://www.paypalobjects.com");
$scriptSrc = array_merge($scriptSrc, $paypal);
$connectSrc = array_merge($connectSrc, $paypal);
$frameSrc = array_merge($frameSrc, $paypal);
$google = BannerAds::cspOrigins();
$scriptSrc = array_merge($scriptSrc, $google['script']);
$connectSrc = array_merge($connectSrc, $google['connect']);
$frameSrc = array_merge($frameSrc, $google['frame']);
$frameValue = implode(' ', array_unique($frameSrc));
bannerCheck(strpos($frameValue, 'https://www.paypal.com') !== false, 'PayPal lost its frame origin to the ad origins');
bannerCheck(strpos($frameValue, 'https://tpc.googlesyndication.com') !== false, 'Ads lost their frame origin');
bannerCheck(strpos(implode(' ', $scriptSrc), 'https://pagead2.googlesyndication.com') !== false, 'The ad script host is missing');
//Every origin is an https scheme and host, with no path, wildcard or scheme-less entry.
foreach(array_merge($google['script'], $google['connect'], $google['frame']) as $origin){
	bannerCheck(preg_match('#^https://[a-z0-9.-]+$#', $origin) === 1, 'Suspicious policy origin: '.$origin);
}
//With neither feature on, frame-src stays closed.
bannerCheck((array() ? 'x' : "'none'") === "'none'", 'An empty frame list no longer collapses to none');

/* ---------------- privacy notice ---------------- */

$_SESSION['bannersObj'] = bannerModule(array_merge($full, array('source'=>'adsense')));
$notice = ghoti_privacy_advertising();
bannerCheck(strpos($notice, 'Google AdSense') !== false, 'The privacy notice does not name the ad network');
bannerCheck(strpos($notice, 'myadcenter.google.com') !== false, 'The privacy notice does not link ad settings');
bannerCheck(strpos($notice, 'consent management platform') !== false, 'The privacy notice does not state the CMP limitation');
$_SESSION['bannersObj'] = bannerModule(array());
bannerCheck(ghoti_privacy_advertising() === '', 'The advertising notice appears with ads switched off');
unset($_SESSION['bannersObj']);
bannerCheck(ghoti_privacy_advertising() === '', 'The advertising notice survived the module being absent');

echo "PASS: $checks banner assertions; no database, no network, no Google\n";
