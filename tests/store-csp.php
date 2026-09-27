<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
/*
 * Run: php tests/store-csp.php - no database, no browser, no network.
 *
 * Stripe and Square card forms are third-party scripts, frames, styles and
 * fonts. Without their origins in the content-security policy the browser
 * blocks them and card checkout silently cannot work - which no server-side
 * test notices. What must hold:
 *   - a configured processor's documented origins are allowed,
 *   - an unconfigured one adds nothing (a shop without it keeps the tighter policy),
 *   - Square allows only the environment in use,
 *   - an unreadable settings row adds nothing,
 *   - index.php actually puts these into the header.
 */
chdir(dirname(__DIR__));
require_once 'ghoti.php';
ghoti::$enableStore = true;
(new ReflectionClass(ghoti::class))->newInstanceWithoutConstructor()->loadModules(array('store'));

$checks = 0;
function cspCheck($ok, $label){
	global $checks; $checks++;
	if(!$ok){ throw new RuntimeException($label); }
}

class CspStoreDbFake{
	public $settings = array();
	public $fail = false;
	public function getSettings(){
		if($this->fail){ throw new RuntimeException('database down'); }
		return $this->settings;
	}
}
function cspStore($settings, $fail = false){
	$store = (new ReflectionClass(store::class))->newInstanceWithoutConstructor();
	$store->storedb = new CspStoreDbFake();
	$store->storedb->settings = $settings;
	$store->storedb->fail = $fail;
	return $store->cardCspOrigins();
}
function cspAll($origins){ return array_merge(...array_values($origins)); }

$stripe = array('stripeEnabled' => 1, 'stripePublishableKey' => 'pk_test_abc123', 'stripeSecretKey' => 'sk_test_abc123');
$square = array('squareEnabled' => 1, 'squareApplicationId' => 'sandbox-sq0idb-x', 'squareLocationId' => 'L1', 'squareAccessToken' => 'EAAAtoken');

/* ---- nothing configured: nothing added ---- */
cspCheck(cspAll(cspStore(array())) === array(), 'Origins were added with no card processor configured');
cspCheck(cspAll(cspStore(array('stripeEnabled' => 1))) === array(), 'Stripe origins were added without valid keys');
cspCheck(cspAll(cspStore($stripe + $square, true)) === array(), 'A settings read failure widened the policy');

/* ---- Stripe ---- */
$o = cspStore($stripe);
cspCheck(in_array('https://js.stripe.com', $o['script'], true) && in_array('https://*.js.stripe.com', $o['script'], true), 'Stripe.js cannot load');
cspCheck(in_array('https://js.stripe.com', $o['frame'], true) && in_array('https://hooks.stripe.com', $o['frame'], true), 'Stripe Elements / 3-D Secure frames are blocked');
cspCheck(in_array('https://api.stripe.com', $o['connect'], true), 'Stripe.js cannot reach the API');
cspCheck(!array_filter(cspAll($o), function($x){ return strpos($x, 'squarecdn') !== false; }), 'Square origins added for Stripe alone');

/* ---- Square, per environment ---- */
$o = cspStore($square + array('squareEnv' => 'sandbox'));
cspCheck($o['script'] === array('https://sandbox.web.squarecdn.com') && $o['frame'] === array('https://sandbox.web.squarecdn.com') && $o['style'] === array('https://sandbox.web.squarecdn.com'), 'Square sandbox origins are wrong');
cspCheck(in_array('https://pci-connect.squareupsandbox.com', $o['connect'], true), 'Square sandbox cannot tokenize');
cspCheck(in_array('https://square-fonts-production-f.squarecdn.com', $o['font'], true) && in_array('https://d1g145x70srn7h.cloudfront.net', $o['font'], true), 'Square fonts are blocked');
cspCheck(!in_array('https://web.squarecdn.com', cspAll($o), true), 'Square production allowed while on sandbox');
$o = cspStore($square + array('squareEnv' => 'live'));
cspCheck($o['script'] === array('https://web.squarecdn.com') && in_array('https://pci-connect.squareup.com', $o['connect'], true), 'Square production origins are wrong');
cspCheck(!array_filter(cspAll($o), function($x){ return strpos($x, 'sandbox') !== false; }), 'Square sandbox allowed while live');

/* ---- both ---- */
$o = cspStore($stripe + $square);
cspCheck(in_array('https://js.stripe.com', $o['script'], true) && in_array('https://sandbox.web.squarecdn.com', $o['script'], true), 'Both processors together lose one');

/* ---- the SDK URLs store.js loads are covered ---- */
$js = file_get_contents('mod/store/store.js');
preg_match_all("#'(https://[a-z0-9.-]*(?:stripe|squarecdn)[a-z0-9.-]*)/[^']*'#", $js, $m);
$sdkHosts = array_unique($m[1]);
cspCheck(count($sdkHosts) >= 3, 'Could not find the SDK URLs in store.js');
$allowed = array_merge(cspStore($stripe)['script'], cspStore($square + array('squareEnv' => 'sandbox'))['script'], cspStore($square + array('squareEnv' => 'live'))['script']);
foreach($sdkHosts as $host){
	cspCheck(in_array($host, $allowed, true), "store.js loads $host but the policy never allows it");
}

/* ---- index.php uses them ---- */
$index = file_get_contents('index.php');
cspCheck(strpos($index, '->cardCspOrigins()') !== false, 'index.php does not ask the store for card origins');
cspCheck(strpos($index, 'style-src ".$styleSrc."') !== false && strpos($index, 'font-src ".$fontSrc."') !== false, 'style-src/font-src cannot be widened');

echo "PASS: $checks store CSP assertions; no database, no browser\n";
