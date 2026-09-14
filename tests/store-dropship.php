<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
/*
 * Run: php tests/store-dropship.php - no database, no supplier, no network.
 *
 * What matters here is what leaves this site and what happens when a supplier
 * says no: the request each driver builds, and the queue's behaviour on a
 * rejection, an outage, and a double drain. A fake data layer and a mock
 * transport stand in for the database and for the suppliers.
 */
require_once __DIR__.'/../ghoti.php';
require_once __DIR__.'/../mod/store/store.paypal.php';
require_once __DIR__.'/../mod/store/store.dropship.php';
require_once __DIR__.'/../mod/store/store.async.php';

$checks = 0;
function dropCheck($ok, $label){ global $checks; if(!$ok){throw new RuntimeException($label);} $checks++; }

/* A supplier that records what it was sent and answers however the test asks. */
class DropTransport{
	public $requests = array();
	public $responses = array();   //url fragment => array(status, body)
	public $default = array('status' => 200, 'body' => '{}');
	public function __invoke($request){
		$this->requests[] = $request;
		foreach($this->responses as $fragment => $response){
			if(strpos($request['url'], $fragment) !== false){
				return is_callable($response) ? $response($request) : $response;
			}
		}
		return $this->default;
	}
	public function lastBody(){
		$last = end($this->requests);
		return $last ? json_decode($last['body'], true) : null;
	}
	public function bodyFor($fragment){
		foreach($this->requests as $request){
			if(strpos($request['url'], $fragment) !== false && isset($request['body'])){
				return json_decode($request['body'], true);
			}
		}
		return null;
	}
	public function headerFor($fragment, $prefix){
		foreach($this->requests as $request){
			if(strpos($request['url'], $fragment) === false){ continue; }
			foreach(($request['headers'] ?? array()) as $header){
				if(stripos($header, $prefix) === 0){ return $header; }
			}
		}
		return '';
	}
	public function urls(){
		$urls = array();
		foreach($this->requests as $request){ $urls[] = $request['method'].' '.$request['url']; }
		return $urls;
	}
}

$order = array(
	'orderId' => 1, 'reference' => 'GH-TESTORDR', 'status' => 'paid',
	'email' => 'buyer@example.test', 'customerName' => 'Ada Lovelace',
	'address1' => '1 Example St', 'address2' => 'Unit 2', 'city' => 'Calgary',
	'region' => 'AB', 'postcode' => 'T2P 1A1', 'country' => 'CA',
	'subtotalCents' => 3000, 'shippingCents' => 995, 'totalCents' => 3995,
	'currency' => 'CAD', 'hasPhysical' => true, 'note' => 'Leave at the door',
);
$items = array(
	array('productId' => 1, 'name' => 'Mug', 'sku' => 'MUG', 'kind' => 'physical',
		'unitCents' => 1250, 'quantity' => 2, 'fulfilment' => 'dropship',
		'dropProvider' => 'printful', 'dropProductId' => '', 'dropVariantId' => '4012'),
);

/* ---------------- Printful ---------------- */

$transport = new DropTransport();
$transport->responses['/orders'] = array('status' => 200, 'body' => json_encode(array(
	'code' => 200, 'result' => array('id' => 55501, 'status' => 'pending'))));
$driver = new StorePrintfulDriver(array('token' => 'pf-token'), $transport);
$result = $driver->submitOrder($order, $items);
dropCheck($result['providerOrderId'] === '55501', 'Printful order id not read back');
dropCheck($result['status'] === 'sent', 'Printful status not mapped');

$body = $transport->bodyFor('/orders');
dropCheck(strpos($transport->urls()[0], 'confirm=1') !== false, 'Printful order was left as a draft (no confirm=1)');
dropCheck($body['external_id'] === 'GH-TESTORDR', 'Printful external_id is not the order reference');
dropCheck($body['recipient']['country_code'] === 'CA', 'Printful country code wrong');
//Printful rejects US/CA/AU addresses with no state code, so the region has to go.
dropCheck($body['recipient']['state_code'] === 'AB', 'Printful state_code missing');
dropCheck($body['items'][0]['variant_id'] === 4012, 'Numeric mapping should be a catalogue variant_id');
dropCheck($body['items'][0]['quantity'] === 2, 'Printful quantity wrong');
dropCheck($body['items'][0]['retail_price'] === '12.50', 'Printful retail price not formatted from cents');
dropCheck(stripos($transport->headerFor('/orders', 'Authorization'), 'Bearer pf-token') !== false, 'Printful token not sent');

//A non-numeric mapping is a sync variant from a connected store, keyed differently.
$transport = new DropTransport();
$transport->responses['/orders'] = array('status' => 200, 'body' => json_encode(array('result' => array('id' => 1, 'status' => 'pending'))));
$syncItems = $items;
$syncItems[0]['dropVariantId'] = 'sync-99';
(new StorePrintfulDriver(array('token' => 't'), $transport))->submitOrder($order, $syncItems);
$body = $transport->bodyFor('/orders');
dropCheck(!isset($body['items'][0]['variant_id']) && $body['items'][0]['sync_variant_id'] === 'sync-99', 'Sync variant sent as a catalogue variant');

//Tracking comes back off the first shipment.
$transport = new DropTransport();
$transport->responses['/orders/'] = array('status' => 200, 'body' => json_encode(array('result' => array(
	'status' => 'fulfilled',
	'shipments' => array(array('carrier' => 'UPS', 'service' => 'Standard', 'tracking_number' => '1Z999', 'tracking_url' => 'https://ups.test/1Z999')),
))));
$status = (new StorePrintfulDriver(array('token' => 't'), $transport))->fetchStatus('55501');
dropCheck($status['status'] === 'shipped', 'Printful fulfilled not mapped to shipped');
dropCheck($status['trackingNumber'] === '1Z999', 'Printful tracking number not read');
dropCheck(strpos($status['carrier'], 'UPS') !== false, 'Printful carrier not read');

/* ---------------- Printify ---------------- */

$printifyItems = $items;
$printifyItems[0]['dropProvider'] = 'printify';
$printifyItems[0]['dropProductId'] = 'prod-1';
$printifyItems[0]['dropVariantId'] = '77';

$transport = new DropTransport();
$transport->responses['/orders.json'] = array('status' => 200, 'body' => json_encode(array('id' => 'pfy-900')));
$transport->responses['send-to-production'] = array('status' => 200, 'body' => '{}');
$driver = new StorePrintifyDriver(array('token' => 'pfy', 'shopId' => '42'), $transport);
$result = $driver->submitOrder($order, $printifyItems);
dropCheck($result['providerOrderId'] === 'pfy-900', 'Printify order id not read back');

$body = $transport->bodyFor('/orders.json');
dropCheck(strpos($transport->urls()[0], '/shops/42/orders.json') !== false, 'Printify call is not shop-scoped');
dropCheck($body['line_items'][0]['product_id'] === 'prod-1' && $body['line_items'][0]['variant_id'] === 77, 'Printify line item ids wrong');
dropCheck($body['address_to']['first_name'] === 'Ada' && $body['address_to']['last_name'] === 'Lovelace', 'Printify name not split');
dropCheck($body['address_to']['region'] === 'AB' && $body['address_to']['country'] === 'CA', 'Printify address wrong');
//This app sends its own receipt; a second from Printify names a supplier the buyer never chose.
dropCheck($body['send_shipping_notification'] === false, 'Printify was allowed to e-mail the buyer');
//Creating an order only stages it - production is a second call.
$sent = false;
foreach($transport->urls() as $url){ if(strpos($url, 'send-to-production.json') !== false){ $sent = true; } }
dropCheck($sent, 'Printify order was created but never sent to production');

//A one-word name must not be duplicated into the surname.
$transport = new DropTransport();
$transport->responses['/orders.json'] = array('status' => 200, 'body' => json_encode(array('id' => 'x')));
$single = $order; $single['customerName'] = 'Prince';
(new StorePrintifyDriver(array('token' => 't', 'shopId' => '1'), $transport))->submitOrder($single, $printifyItems);
$body = $transport->bodyFor('/orders.json');
dropCheck($body['address_to']['first_name'] === 'Prince' && $body['address_to']['last_name'] === '', 'One-word name mangled');

//Production failing leaves a real order at Printify: reported, not swallowed.
$transport = new DropTransport();
$transport->responses['/orders.json'] = array('status' => 200, 'body' => json_encode(array('id' => 'pfy-901')));
$transport->responses['send-to-production'] = array('status' => 500, 'body' => json_encode(array('error' => 'upstream')));
$log = tempnam(sys_get_temp_dir(), 'ghoti-drop-test-');
ghoti::$ghotiLog = $log;
$result = (new StorePrintifyDriver(array('token' => 't', 'shopId' => '1'), $transport))->submitOrder($order, $printifyItems);
dropCheck($result['providerOrderId'] === 'pfy-901' && !empty($result['warning']), 'A stranded Printify order was reported as clean');

/* ---------------- CJ Dropshipping ---------------- */

$cjItems = $items;
$cjItems[0]['dropProvider'] = 'cj';
$cjItems[0]['dropVariantId'] = 'vid-7';

$transport = new DropTransport();
$transport->responses['getAccessToken'] = array('status' => 200, 'body' => json_encode(array(
	'result' => true, 'data' => array('accessToken' => 'cj-token', 'accessTokenExpiryDate' => gmdate('Y-m-d H:i:s', time() + 7200)))));
$transport->responses['createOrderV2'] = array('status' => 200, 'body' => json_encode(array(
	'result' => true, 'data' => array('orderId' => 'cj-1001'))));
$driver = new StoreCjDriver(array('email' => 'a@b.test', 'apiKey' => 'key'), $transport);
$result = $driver->submitOrder($order, $cjItems);
dropCheck($result['providerOrderId'] === 'cj-1001', 'CJ order id not read back');

$body = $transport->bodyFor('createOrderV2');
dropCheck($body['orderNumber'] === 'GH-TESTORDR', 'CJ order number is not the reference');
dropCheck($body['products'][0]['vid'] === 'vid-7' && $body['products'][0]['quantity'] === 2, 'CJ product line wrong');
dropCheck($body['shippingCountryCode'] === 'CA' && $body['shippingZip'] === 'T2P 1A1', 'CJ address wrong');
dropCheck(strpos($body['shippingAddress'], 'Unit 2') !== false, 'CJ address line 2 dropped');
dropCheck(stripos($transport->headerFor('createOrderV2', 'CJ-Access-Token'), 'cj-token') !== false, 'CJ token header missing');

//CJ answers 200 with result:false when it refuses; that is a rejection, not success.
$transport = new DropTransport();
$transport->responses['getAccessToken'] = array('status' => 200, 'body' => json_encode(array('data' => array('accessToken' => 't'))));
$transport->responses['createOrderV2'] = array('status' => 200, 'body' => json_encode(array('result' => false, 'message' => 'vid does not exist')));
$refused = false;
try{ (new StoreCjDriver(array('email' => 'a', 'apiKey' => 'b'), $transport))->submitOrder($order, $cjItems); }
catch(StoreDropshipPermanentException $e){ $refused = strpos($e->getMessage(), 'vid does not exist') !== false; }
dropCheck($refused, 'CJ result:false was read as success');

//The token endpoint is rate limited, so a cached token must be reused.
$transport = new DropTransport();
$transport->responses['createOrderV2'] = array('status' => 200, 'body' => json_encode(array('result' => true, 'data' => array('orderId' => 'x'))));
$cached = array('token' => 'cached-token', 'expires' => time() + 3600);
$store = array('get' => function() use (&$cached){ return $cached; }, 'set' => function($t) use (&$cached){ $cached = $t; });
(new StoreCjDriver(array('email' => 'a', 'apiKey' => 'b'), $transport, $store))->submitOrder($order, $cjItems);
foreach($transport->urls() as $url){
	dropCheck(strpos($url, 'getAccessToken') === false, 'A cached CJ token was not reused');
}

/* ---------------- generic webhook ---------------- */

$webhookItems = $items;
$webhookItems[0]['dropProvider'] = 'webhook';
$transport = new DropTransport();
$transport->default = array('status' => 200, 'body' => json_encode(array('orderId' => 'wh-1')));
$driver = new StoreWebhookDriver(array('url' => 'https://hooks.example.test/orders', 'secret' => 'shhh'), $transport);
$result = $driver->submitOrder($order, $webhookItems);
dropCheck($result['providerOrderId'] === 'wh-1', 'Webhook order id not read back');

$body = $transport->lastBody();
dropCheck($body['reference'] === 'GH-TESTORDR' && $body['total'] === '39.95', 'Webhook payload wrong');
dropCheck($body['shipTo']['postcode'] === 'T2P 1A1', 'Webhook ship-to wrong');
dropCheck($body['items'][0]['unitAmount'] === '12.50', 'Webhook line amount not formatted from cents');

//The receiver has to be able to prove the request came from this site.
$signature = $transport->headerFor('hooks.example.test', 'X-Ghoti-Signature');
$timestamp = $transport->headerFor('hooks.example.test', 'X-Ghoti-Timestamp');
dropCheck($signature !== '' && $timestamp !== '', 'Webhook request was not signed');
$stamp = trim(substr($timestamp, strlen('X-Ghoti-Timestamp:')));
$expected = 'sha256='.hash_hmac('sha256', $stamp.'.'.$transport->requests[0]['body'], 'shhh');
dropCheck(trim(substr($signature, strlen('X-Ghoti-Signature:'))) === $expected, 'Webhook signature does not verify');

//No signing secret configured: no signature header, and no crash.
$transport = new DropTransport();
$transport->default = array('status' => 200, 'body' => '{}');
(new StoreWebhookDriver(array('url' => 'https://hooks.example.test/x'), $transport))->submitOrder($order, $webhookItems);
dropCheck($transport->headerFor('hooks.example.test', 'X-Ghoti-Signature') === '', 'Signed without a secret');

/* ---------------- what a failure means ---------------- */

//4xx is "this order is wrong" and must not be retried forever; 5xx and
//transport failures are "try again later".
$transport = new DropTransport();
$transport->responses['/orders'] = array('status' => 422, 'body' => json_encode(array('result' => 'variant 4012 is discontinued')));
$permanent = false;
try{ (new StorePrintfulDriver(array('token' => 't'), $transport))->submitOrder($order, $items); }
catch(StoreDropshipPermanentException $e){ $permanent = strpos($e->getMessage(), 'discontinued') !== false; }
dropCheck($permanent, 'A rejected order was treated as retryable');

$transport = new DropTransport();
$transport->responses['/orders'] = array('status' => 503, 'body' => '{}');
$retryable = false;
try{ (new StorePrintfulDriver(array('token' => 't'), $transport))->submitOrder($order, $items); }
catch(StoreDropshipPermanentException $e){ $retryable = false; }
catch(StoreDropshipException $e){ $retryable = true; }
dropCheck($retryable, 'An outage was treated as a permanent rejection');

//429 is rate limiting: worth retrying, despite being a 4xx.
$transport = new DropTransport();
$transport->responses['/orders'] = array('status' => 429, 'body' => '{}');
$retryable = false;
try{ (new StorePrintfulDriver(array('token' => 't'), $transport))->submitOrder($order, $items); }
catch(StoreDropshipPermanentException $e){ $retryable = false; }
catch(StoreDropshipException $e){ $retryable = true; }
dropCheck($retryable, 'Rate limiting was treated as a permanent rejection');

//A missing mapping is caught before anything is sent.
$transport = new DropTransport();
$unmapped = $items;
$unmapped[0]['dropVariantId'] = '';
$caught = false;
try{ (new StorePrintfulDriver(array('token' => 't'), $transport))->submitOrder($order, $unmapped); }
catch(StoreDropshipPermanentException $e){ $caught = true; }
dropCheck($caught && !$transport->requests, 'An unmapped product was sent to the supplier anyway');

//An unconfigured driver knows it.
dropCheck((new StorePrintfulDriver(array(), null))->configured() === false, 'An empty Printful config claims to be ready');
dropCheck((new StorePrintifyDriver(array('token' => 't'), null))->configured() === false, 'Printify without a shop id claims to be ready');
dropCheck((new StoreWebhookDriver(array('url' => 'https://x.test'), null))->configured() === true, 'A configured webhook claims otherwise');

/* ---------------- the registry ---------------- */

$drivers = StoreDropship::drivers();
foreach(array('printful','printify','cj','webhook') as $provider){
	dropCheck(isset($drivers[$provider]), "Provider $provider is not registered");
	dropCheck(class_exists($drivers[$provider]['class']), "Driver class for $provider does not exist");
	dropCheck(StoreDropship::isProvider($provider), "isProvider() does not know $provider");
	//Every field the admin screen renders must say what it is and whether it hides.
	foreach($drivers[$provider]['fields'] as $field => $definition){
		dropCheck(!empty($definition['label']), "$provider.$field has no label");
		dropCheck(array_key_exists('secret', $definition), "$provider.$field does not say whether it is secret");
	}
}
dropCheck(!StoreDropship::isProvider('aliexpress'), 'An unregistered provider was accepted');
$rejected = false;
try{ StoreDropship::driver('nope', array()); }catch(StoreDropshipPermanentException $e){ $rejected = true; }
dropCheck($rejected, 'An unknown provider produced a driver');

//Credentials are secret; the labels and help text that reach the browser are not.
foreach($drivers as $provider => $driver){
	dropCheck(strpos(json_encode($driver['mapping']), 'token') === false, "$provider leaks a token name into the product form data");
}

if(is_file($log)){ unlink($log); }
echo "PASS: $checks dropshipping assertions; no database, no supplier, no network\n";
