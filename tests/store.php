<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
/*
 * Run: php tests/store.php - no database, no PayPal, no web server.
 *
 * The store's two load-bearing rules are what this covers: a price never comes
 * from the browser, and an order only becomes paid when the captured amount
 * matches what the server recorded. A fake storedb stands in for the database
 * and a mock transport stands in for PayPal.
 */
require_once __DIR__.'/../ghoti.php';
require_once __DIR__.'/../mod/store/store.paypal.php';
require_once __DIR__.'/../mod/store/store.dropship.php';
require_once __DIR__.'/../mod/store/store.async.php';

$checks = 0;
function storeCheck($ok, $label){ global $checks; if(!$ok){throw new RuntimeException($label);} $checks++; }

//The admin gate calls isAdmin() from the login module, which is not loaded here.
//This stand-in lets the admin-only endpoints be exercised as an admin, and - by
//flipping the flag - as a signed-out visitor.
function isAdmin($userId){ return !empty($GLOBALS['storeTestAdmin']); }
function storeTestSignIn($isAdmin){
	$GLOBALS['storeTestAdmin'] = $isAdmin;
	$_SESSION['loggedIn'] = true;
	$_SESSION['userId'] = 1;
}
function storeTestSignOut(){
	$GLOBALS['storeTestAdmin'] = false;
	unset($_SESSION['loggedIn'], $_SESSION['userId']);
}

/* A stand-in for storedb: the same methods store.async.php calls, backed by
 * arrays. Prices live here and nowhere else, which is the point of the test. */
class StoreDbFake{
	public $settings;
	public $products = array();
	public $orders = array();
	public $items = array();
	public $downloads = array();
	public $paidCalls = 0;
	public $statusCalls = array();

	public function __construct(){
		$this->settings = array(
			'paypalClientId' => 'test-client', 'paypalSecret' => 'test-secret',
			'paypalEnv' => 'sandbox', 'currency' => 'CAD', 'shippingCents' => 995,
			'shippingNote' => '', 'downloadHours' => 72, 'downloadLimit' => 5, 'updatedAt' => 0,
			'dropshipEnabled' => true, 'dropshipAutoSubmit' => true,
			'dropshipConfig' => array('printful' => array('token' => 'pf-test-token')),
		);
		$this->products = array(
			1 => array('productId'=>1,'sku'=>'MUG','name'=>'Mug','description'=>'','priceCents'=>1250,
				'kind'=>'physical','category'=>'default','imageUrl'=>'','downloadPath'=>'','active'=>true,'sortOrder'=>0,'createdAt'=>0),
			2 => array('productId'=>2,'sku'=>'PDF','name'=>'Guide','description'=>'','priceCents'=>500,
				'kind'=>'digital','category'=>'default','imageUrl'=>'','downloadPath'=>'guide.pdf','active'=>true,'sortOrder'=>0,'createdAt'=>0),
			4 => array('productId'=>4,'sku'=>'TEE','name'=>'Shirt','description'=>'','priceCents'=>2000,
				'kind'=>'physical','category'=>'default','imageUrl'=>'','downloadPath'=>'','active'=>true,'sortOrder'=>0,'createdAt'=>0,
				'fulfilment'=>'dropship','dropProvider'=>'printful','dropProductId'=>'','dropVariantId'=>'4012'),
			3 => array('productId'=>3,'sku'=>'OLD','name'=>'Retired','description'=>'','priceCents'=>9900,
				'kind'=>'physical','category'=>'default','imageUrl'=>'','downloadPath'=>'','active'=>false,'sortOrder'=>0,'createdAt'=>0),
		);
	}
	public function skuTaken($sku, $excludeId = 0){
		foreach($this->products as $id => $product){ if($id !== (int)$excludeId && $product['sku'] === $sku){ return true; } }
		return false;
	}
	public function addProduct($product){
		$id = $this->products ? max(array_keys($this->products)) + 1 : 1;
		$this->products[$id] = array_merge($product, array('productId' => $id, 'createdAt' => time()));
		return true;
	}
	public function updateProduct($id, $product){
		if(!isset($this->products[(int)$id])){ return false; }
		$this->products[(int)$id] = array_merge($this->products[(int)$id], $product);
		return true;
	}
	public function getSettings(){ return $this->settings; }
	public function saveSettings($settings){ $this->settings = array_merge($this->settings, $settings); return true; }
	public function getProduct($id){ return isset($this->products[(int)$id]) ? $this->products[(int)$id] : null; }
	public function getProductsById($ids){
		$out = array();
		foreach((array)$ids as $id){ if(isset($this->products[(int)$id])){ $out[(int)$id] = $this->products[(int)$id]; } }
		return $out;
	}
	public function getProducts($category = 'all', $includeInactive = false){
		$out = array();
		foreach($this->products as $product){
			if(!$includeInactive && !$product['active']){ continue; }
			$out[] = $product;
		}
		return $out;
	}
	public function createOrder($order, $items){
		$id = count($this->orders) + 1;
		$order['orderId'] = $id;
		$order['status'] = 'pending';
		$order['paypalCaptureId'] = '';
		$order['payerEmail'] = '';
		$order['createdAt'] = time();
		$order['paidAt'] = 0;
		$order['shippedAt'] = 0;
		$this->orders[$id] = $order;
		$this->items[$id] = $items;
		return $id;
	}
	public function getOrder($id){ return isset($this->orders[(int)$id]) ? $this->orders[(int)$id] : null; }
	public function getOrderByPaypalId($paypalOrderId){
		foreach($this->orders as $order){
			if($order['paypalOrderId'] === $paypalOrderId){ return $order; }
		}
		return null;
	}
	//Mirrors the real query: line items carry the product's current supplier
	//mapping rather than a snapshot, so a corrected variant id can be retried.
	public function getOrderItems($id){
		$items = isset($this->items[(int)$id]) ? $this->items[(int)$id] : array();
		foreach($items as $index => $item){
			$product = isset($this->products[(int)$item['productId']]) ? $this->products[(int)$item['productId']] : array();
			$items[$index]['fulfilment']    = isset($product['fulfilment']) ? $product['fulfilment'] : 'self';
			$items[$index]['dropProvider']  = isset($product['dropProvider']) ? $product['dropProvider'] : '';
			$items[$index]['dropProductId'] = isset($product['dropProductId']) ? $product['dropProductId'] : '';
			$items[$index]['dropVariantId'] = isset($product['dropVariantId']) ? $product['dropVariantId'] : '';
		}
		return $items;
	}

	/* ---- supplier fulfilment queue ---- */
	public $fulfilments = array();
	public function queueFulfilment($orderId, $provider){
		foreach($this->fulfilments as $row){
			//The real table has a unique key on (orderId, provider): a replayed
			//capture must not become a second supplier order.
			if($row['orderId'] === (int)$orderId && $row['provider'] === $provider){ return true; }
		}
		$id = count($this->fulfilments) + 1;
		$this->fulfilments[$id] = array('fulfilmentId'=>$id,'orderId'=>(int)$orderId,'provider'=>$provider,
			'providerOrderId'=>'','status'=>'queued','trackingNumber'=>'','trackingUrl'=>'','carrier'=>'',
			'lastError'=>'','attempts'=>0,'createdAt'=>time(),'sentAt'=>0,'syncedAt'=>0);
		return true;
	}
	public function getFulfilment($id){ return isset($this->fulfilments[(int)$id]) ? $this->fulfilments[(int)$id] : null; }
	public function getOrderFulfilments($orderId){
		$rows = array();
		foreach($this->fulfilments as $row){ if($row['orderId'] === (int)$orderId){ $rows[] = $row; } }
		return $rows;
	}
	public function getQueuedFulfilments($limit = 25){
		$rows = array();
		foreach($this->fulfilments as $row){ if($row['status'] === 'queued'){ $rows[] = $row; } }
		return array_slice($rows, 0, $limit);
	}
	public function getOpenFulfilments($limit = 50){
		$rows = array();
		foreach($this->fulfilments as $row){ if($row['status'] === 'sent'){ $rows[] = $row; } }
		return array_slice($rows, 0, $limit);
	}
	public function claimFulfilment($id){
		if(!isset($this->fulfilments[(int)$id])){ return false; }
		if(!in_array($this->fulfilments[(int)$id]['status'], array('queued','failed'), true)){ return false; }
		$this->fulfilments[(int)$id]['status'] = 'sending';
		$this->fulfilments[(int)$id]['attempts']++;
		return true;
	}
	public function markFulfilmentSent($id, $providerOrderId, $status = 'sent'){
		$this->fulfilments[(int)$id]['status'] = $status;
		$this->fulfilments[(int)$id]['providerOrderId'] = $providerOrderId;
		$this->fulfilments[(int)$id]['lastError'] = '';
		$this->fulfilments[(int)$id]['sentAt'] = time();
		return true;
	}
	public function markFulfilmentFailed($id, $error, $retryable = true){
		$this->fulfilments[(int)$id]['status'] = $retryable ? 'queued' : 'failed';
		$this->fulfilments[(int)$id]['lastError'] = $error;
		return true;
	}
	public function updateFulfilmentTracking($id, $status, $tracking){
		$this->fulfilments[(int)$id]['status'] = $status;
		$this->fulfilments[(int)$id]['trackingNumber'] = $tracking['trackingNumber'];
		$this->fulfilments[(int)$id]['trackingUrl'] = $tracking['trackingUrl'];
		$this->fulfilments[(int)$id]['carrier'] = $tracking['carrier'];
		$this->fulfilments[(int)$id]['syncedAt'] = time();
		return true;
	}
	public function markOrderPaid($id, $captureId, $payerEmail){
		$this->paidCalls++;
		if(!isset($this->orders[(int)$id]) || $this->orders[(int)$id]['status'] !== 'pending'){ return false; }
		$this->orders[(int)$id]['status'] = 'paid';
		$this->orders[(int)$id]['paypalCaptureId'] = $captureId;
		$this->orders[(int)$id]['payerEmail'] = $payerEmail;
		$this->orders[(int)$id]['paidAt'] = time();
		return true;
	}
	public function setOrderStatus($id, $status){
		$this->statusCalls[] = array((int)$id, $status);
		if(isset($this->orders[(int)$id])){ $this->orders[(int)$id]['status'] = $status; }
		return true;
	}
	public function addDownloadGrant($orderId, $productId, $token, $max, $expires){
		$this->downloads[] = array('token'=>$token,'expiresAt'=>$expires,'downloads'=>0,'maxDownloads'=>$max,
			'name'=>$this->products[(int)$productId]['name']);
		return true;
	}
	public function getOrderDownloads($orderId){ return $this->downloads; }
	public function getSalesSummary($days = 30){ return array('orders'=>0,'totalCents'=>0); }
}

//Mock supplier transport, shared by the fulfilment section below.
class DropshipMock{
	public $requests = array();
	public $responses = array();
	public function __invoke($request){
		$this->requests[] = $request;
		foreach($this->responses as $fragment => $response){
			if(strpos($request['url'], $fragment) !== false){ return $response; }
		}
		return array('status' => 200, 'body' => '{}');
	}
	public function bodyFor($fragment){
		foreach($this->requests as $request){
			if(strpos($request['url'], $fragment) !== false && isset($request['body'])){
				return json_decode($request['body'], true);
			}
		}
		return null;
	}
}

//Mock PayPal. Records every request and answers with the shape the real API
//returns; $captureCents is what "PayPal" claims to have taken.
class StorePaypalMock{
	public $requests = array();
	public $captureCents = null;
	public $captureCurrency = 'CAD';
	public $orderId = 'PAYPAL-TEST-1';
	public function __invoke($request){
		$this->requests[] = $request;
		if(strpos($request['url'], '/v1/oauth2/token') !== false){
			return array('status'=>200, 'body'=>json_encode(array('access_token'=>'test-token')));
		}
		if(substr($request['url'], -8) === '/capture'){
			return array('status'=>200, 'body'=>json_encode(array(
				'status' => 'COMPLETED',
				'payer' => array('email_address' => 'buyer@example.test'),
				'purchase_units' => array(array('payments' => array('captures' => array(array(
					'id' => 'CAPTURE-1', 'status' => 'COMPLETED',
					'amount' => array('value' => StorePaypalClient::amount($this->captureCents), 'currency_code' => $this->captureCurrency),
				))))),
			)));
		}
		return array('status'=>201, 'body'=>json_encode(array('id'=>$this->orderId)));
	}
	public function createBody(){
		foreach($this->requests as $request){
			if(substr($request['url'], -19) === '/v2/checkout/orders'){ return json_decode($request['body'], true); }
		}
		return null;
	}
}

$db = new StoreDbFake();
$mock = new StorePaypalMock();
$_SESSION = array();
$_SESSION['storeObj'] = (object)array('storedb' => $db, 'storeui' => new storeui());
$GLOBALS['storePaypalTransport'] = $mock;

/* ---------------- money parsing ---------------- */

foreach(array('12.34'=>1234, '12'=>1200, '12.3'=>1230, '0.05'=>5, '$9.99'=>999, '12,50'=>1250, ' 7 '=>700) as $input => $expected){
	storeCheck(storePriceToCents($input) === $expected, "Price '$input' parsed as ".storePriceToCents($input));
}
foreach(array('', 'free', '1.234', '-5', '1e3', '12.3.4') as $bad){
	storeCheck(storePriceToCents($bad) === -1, "Unparsable price accepted: '$bad'");
}
//intInRange() clamps rather than throwing, so an unreadable price must be
//rejected before it reaches the product row - otherwise it would be listed free.
storeTestSignIn(true);
$rejected = saveStoreProduct(array('name'=>'X','sku'=>'X','price'=>'free'));
storeCheck(is_string($rejected) && stripos($rejected, 'price') !== false, 'Unreadable price was not rejected: '.var_export($rejected, true));

//Management is admin-only; a signed-out visitor gets nothing from it.
storeTestSignOut();
foreach(array('saveStoreProduct','deleteStoreProduct','saveStoreSettings','setStoreOrderStatus') as $endpoint){
	$answer = $endpoint === 'saveStoreProduct' || $endpoint === 'saveStoreSettings'
		? $endpoint(array('name'=>'X','sku'=>'X','price'=>'1.00'))
		: $endpoint(1, 'shipped');
	storeCheck($answer === 'Admin access required.', "$endpoint served a signed-out caller");
}
storeCheck(strpos(showStoreManager('products'), 'Admin access required') !== false, 'The store manager rendered for a signed-out caller');

storeCheck(StorePaypalClient::amount(1234) === '12.34', 'Cents to amount wrong');
storeCheck(StorePaypalClient::amount(5) === '0.05', 'Sub-dime amount wrong');
storeCheck(StorePaypalClient::cents('12.34') === 1234, 'Amount to cents wrong');
storeCheck(StorePaypalClient::cents('nope') === null, 'Unparsable amount accepted');

/* ---------------- cart pricing ---------------- */

$_SESSION['storeCart'] = array();
storeCheck(storeAddToCart(1, 2)['ok'] === true, 'Could not add to cart');
$totals = storeCartTotals();
storeCheck($totals['subtotalCents'] === 2500, 'Subtotal wrong: '.$totals['subtotalCents']);
storeCheck($totals['shippingCents'] === 995, 'Shipping not charged on a physical cart');
storeCheck($totals['totalCents'] === 3495, 'Total wrong: '.$totals['totalCents']);

//An all-digital cart pays no shipping.
$_SESSION['storeCart'] = array(2 => 1);
$digital = storeCartTotals();
storeCheck($digital['shippingCents'] === 0 && $digital['totalCents'] === 500, 'Digital-only cart was charged shipping');
storeCheck($digital['hasPhysical'] === false, 'Digital-only cart asked for shipping');

//A deactivated product silently leaves the cart rather than being sold.
$_SESSION['storeCart'] = array(3 => 1);
storeCheck(storeCartTotals()['totalCents'] === 0, 'Inactive product was priced into the cart');
storeCheck(storeAddToCart(3, 1)['ok'] === false, 'Inactive product could be added');

//Quantity is bounded server-side whatever the browser sends.
$_SESSION['storeCart'] = array();
storeAddToCart(1, 99);
storeAddToCart(1, 99);
storeCheck(storeCartTotals()['units'] === STORE_MAX_QTY, 'Quantity cap not enforced');

/* ---------------- checkout: the server prices the order ---------------- */

$_SESSION['storeCart'] = array(1 => 2, 2 => 1);
$begin = storeBeginCheckout(array(
	'name' => 'Test Buyer', 'email' => 'buyer@example.test',
	'address1' => '1 Example St', 'city' => 'Calgary', 'region' => 'AB',
	'postcode' => 'T2P 1A1', 'country' => 'ca', 'note' => 'Leave at the door',
	//Anything a tampered client might add is ignored: there is no price input.
	'total' => '0.01', 'priceCents' => 1, 'shipping' => '0',
));
storeCheck($begin['ok'] === true, 'Checkout did not start: '.($begin['error'] ?? ''));
$order = $db->getOrder(1);
storeCheck($order['subtotalCents'] === 3000, 'Order subtotal not computed from the catalogue: '.$order['subtotalCents']);
storeCheck($order['totalCents'] === 3995, 'Order total ignored the catalogue price: '.$order['totalCents']);
storeCheck($order['status'] === 'pending', 'Order was not recorded as pending before capture');
storeCheck($order['country'] === 'CA', 'Country code not normalized');

$sent = $mock->createBody();
storeCheck($sent['purchase_units'][0]['amount']['value'] === '39.95', 'PayPal was asked for the wrong amount: '.$sent['purchase_units'][0]['amount']['value']);
storeCheck($sent['purchase_units'][0]['amount']['currency_code'] === 'CAD', 'PayPal was asked in the wrong currency');
storeCheck(count($sent['purchase_units'][0]['items']) === 2, 'Line items not itemised for PayPal');

//A physical cart without an address never reaches PayPal.
$_SESSION['storeCart'] = array(1 => 1);
$missing = storeBeginCheckout(array('name'=>'Test Buyer','email'=>'buyer@example.test'));
storeCheck($missing['ok'] === false, 'Physical order accepted with no shipping address');
$badEmail = storeBeginCheckout(array('name'=>'Test Buyer','email'=>'not-an-address','address1'=>'1 St','city'=>'C','postcode'=>'X','country'=>'CA'));
storeCheck($badEmail['ok'] === false, 'Order accepted with an invalid e-mail');

/* ---------------- capture: amount must match ---------------- */

//PayPal reporting a smaller amount than the order is a mismatch, not a rounding
//quirk: the order must not become paid.
$mock->captureCents = 100;
$short = storeCaptureOrder('PAYPAL-TEST-1');
storeCheck($short['ok'] === false, 'A short payment was accepted');
storeCheck($db->getOrder(1)['status'] === 'failed', 'Mismatched capture left the order payable');
storeCheck($db->paidCalls === 0, 'Mismatched capture tried to mark the order paid');

//Wrong currency, right number.
$db->orders[1]['status'] = 'pending';
$mock->captureCents = 3995;
$mock->captureCurrency = 'USD';
storeCheck(storeCaptureOrder('PAYPAL-TEST-1')['ok'] === false, 'A payment in the wrong currency was accepted');
storeCheck($db->paidCalls === 0, 'Currency mismatch tried to mark the order paid');

//The matching capture pays the order, empties the cart and issues one download
//grant for the one digital line.
$db->orders[1]['status'] = 'pending';
$mock->captureCurrency = 'CAD';
$paid = storeCaptureOrder('PAYPAL-TEST-1');
storeCheck($paid['ok'] === true, 'A matching capture was refused');
storeCheck($db->getOrder(1)['status'] === 'paid', 'Matching capture did not pay the order');
storeCheck($db->getOrder(1)['paypalCaptureId'] === 'CAPTURE-1', 'Capture id not recorded');
storeCheck(count($db->downloads) === 1, 'Wrong number of download grants: '.count($db->downloads));
storeCheck(preg_match('/^[a-f0-9]{48}$/', $db->downloads[0]['token']) === 1, 'Download token is not 48 random hex characters');
storeCheck(empty($_SESSION['storeCart']), 'Cart survived a completed payment');

//Replay: the second capture returns the receipt and charges nothing again.
$before = $db->paidCalls;
$replay = storeCaptureOrder('PAYPAL-TEST-1');
storeCheck($replay['ok'] === true && isset($replay['html']), 'A replayed capture was not answered with the receipt');
storeCheck($db->paidCalls === $before, 'A replayed capture tried to pay the order again');
storeCheck(count($db->downloads) === 1, 'A replayed capture issued more download links');

storeCheck(storeCaptureOrder('NOT-A-KNOWN-ORDER')['ok'] === false, 'Capture accepted an unknown order');
storeCheck(storeCaptureOrder('../../etc/passwd')['ok'] === false, 'Capture accepted a malformed reference');

/* ---------------- fulfilment and escaping ---------------- */

//'paid' is not a status an admin may set; only a verified capture sets it.
storeTestSignIn(true);
$byHand = setStoreOrderStatus(1, 'paid');
storeCheck(is_string($byHand) && stripos($byHand, 'by hand') !== false, 'Paid could be set by hand: '.var_export($byHand, true));
storeCheck(setStoreOrderStatus(1, 'shipped') === true, 'A paid order could not be marked shipped');
storeCheck($db->getOrder(1)['status'] === 'shipped', 'Shipped status not stored');

//Product copy and customer input are rendered as text, never as markup.
$db->products[1]['name'] = 'Mug <script>alert(1)</script>';
$html = storeUi()->renderStorefront($db->getProducts(), 'all');
storeCheck(strpos($html, '<script>alert(1)</script>') === false, 'Product name was rendered as HTML');
storeCheck(strpos($html, '&lt;script&gt;') !== false, 'Product name was not escaped');

$db->orders[1]['customerName'] = 'Buyer "><img src=x onerror=alert(1)>';
$receipt = storeUi()->renderOrderDetail($db->getOrder(1), $db->getOrderItems(1), $db->getOrderDownloads(1));
storeCheck(strpos($receipt, '<img src=x') === false, 'Customer name was rendered as HTML');

//A note with CR/LF must not be able to shape the receipt e-mail.
$db->orders[1]['note'] = "Nice\r\nBcc: attacker@example.test";
$text = storeUi()->receiptText($db->getOrder(1), $db->getOrderItems(1), array());
storeCheck(strpos($text, "\r") === false, 'Receipt text carried a carriage return');

/* ---------------- supplier fulfilment ----------------
 * Payment and fulfilment are separate on purpose: a supplier being down must
 * never look like a failed payment, and a replayed capture must not become a
 * second supplier order. */

storeTestSignOut();
$db->fulfilments = array();
$db->orders = array();
$db->items = array();
$mock->captureCurrency = 'CAD';

//A cart with one self-fulfilled line, one download and one supplier line.
$_SESSION['storeCart'] = array(1 => 1, 2 => 1, 4 => 2);
$mock->orderId = 'PAYPAL-DROP-1';
$begin = storeBeginCheckout(array('name'=>'Ada Lovelace','email'=>'buyer@example.test',
	'address1'=>'1 Example St','city'=>'Calgary','region'=>'AB','postcode'=>'T2P 1A1','country'=>'CA'));
storeCheck($begin['ok'] === true, 'Dropship checkout did not start: '.($begin['error'] ?? ''));
$dropOrderId = $db->orders[count($db->orders)]['orderId'];
$mock->captureCents = $db->getOrder($dropOrderId)['totalCents'];

//Capture queues, and only queues: nothing is sent to a supplier in the payment
//path, so no transport is even configured at this point.
$paid = storeCaptureOrder('PAYPAL-DROP-1');
storeCheck($paid['ok'] === true, 'Dropship order could not be captured');
storeCheck($paid['submitQueued'] === true, 'The receipt did not ask the browser to drain the queue');
$queued = $db->getOrderFulfilments($dropOrderId);
storeCheck(count($queued) === 1, 'Wrong number of supplier submissions queued: '.count($queued));
storeCheck($queued[0]['provider'] === 'printful' && $queued[0]['status'] === 'queued', 'Queued row is wrong: '.json_encode($queued[0]));
storeCheck($queued[0]['providerOrderId'] === '', 'A queued row already claims a supplier order id');

//A replayed capture must not queue the order a second time.
storeCaptureOrder('PAYPAL-DROP-1');
storeCheck(count($db->getOrderFulfilments($dropOrderId)) === 1, 'A replayed capture queued a second supplier order');

//Draining the queue is what talks to the supplier.
$dropTransport = new DropshipMock();
$GLOBALS['storeDropshipTransport'] = $dropTransport;
$dropTransport->responses['/orders'] = array('status' => 200, 'body' => json_encode(array('result' => array('id' => 777, 'status' => 'pending'))));
$drained = storeDrainFulfilments(10);
storeCheck($drained['sent'] === 1 && $drained['failed'] === 0, 'Drain did not send the queued order: '.json_encode($drained));
$row = $db->getOrderFulfilments($dropOrderId)[0];
storeCheck($row['status'] === 'sent' && $row['providerOrderId'] === '777', 'Submission not recorded: '.json_encode($row));
//Only the supplier's own lines are sent - not the mug, and never the download.
$body = $dropTransport->bodyFor('/orders');
storeCheck(count($body['items']) === 1 && $body['items'][0]['quantity'] === 2, 'The wrong lines went to the supplier: '.json_encode($body['items']));

//A second drain has nothing to do: the row is no longer queued.
$dropTransport->requests = array();
storeCheck(storeDrainFulfilments(10)['sent'] === 0, 'A sent order was submitted again');
storeCheck(!$dropTransport->requests, 'A second drain called the supplier again');

//Tracking moves the order to shipped once every supplier line has shipped.
//Replace the map rather than adding to it: '/orders' would otherwise match the
//status call first and answer it with the create-order body.
$dropTransport->responses = array('/orders/777' => array('status' => 200, 'body' => json_encode(array('result' => array(
	'status' => 'fulfilled',
	'shipments' => array(array('carrier' => 'UPS', 'tracking_number' => '1Z777', 'tracking_url' => 'https://ups.test/1Z777')),
)))));
storeCheck(storeSyncOpenFulfilments(10) === 1, 'Tracking sync did nothing');
$row = $db->getOrderFulfilments($dropOrderId)[0];
storeCheck($row['status'] === 'shipped' && $row['trackingNumber'] === '1Z777', 'Tracking not stored: '.json_encode($row));
storeCheck($db->getOrder($dropOrderId)['status'] === 'shipped', 'The order was not marked shipped by its supplier');

//A supplier rejecting the order stops the row rather than retrying forever, and
//says why on the order.
$db->fulfilments = array();
$db->queueFulfilment($dropOrderId, 'printful');
$dropTransport->responses = array('/orders' => array('status' => 422, 'body' => json_encode(array('result' => 'variant 4012 is discontinued'))));
storeCheck(storeDrainFulfilments(10)['failed'] === 1, 'A rejected submission was reported as sent');
$row = $db->getOrderFulfilments($dropOrderId)[0];
storeCheck($row['status'] === 'failed', 'A rejected submission stayed on the queue');
storeCheck(strpos($row['lastError'], 'discontinued') !== false, 'The rejection reason was not recorded: '.$row['lastError']);
storeCheck(storeDrainFulfilments(10)['sent'] === 0, 'A failed row was picked up by the next drain');

//An outage is different: the row goes back on the queue for the next run.
$db->fulfilments = array();
$db->queueFulfilment($dropOrderId, 'printful');
$dropTransport->responses = array('/orders' => array('status' => 503, 'body' => '{}'));
storeDrainFulfilments(10);
$row = $db->getOrderFulfilments($dropOrderId)[0];
storeCheck($row['status'] === 'queued', 'An outage was treated as a permanent rejection');
storeCheck($row['attempts'] === 1, 'The attempt was not counted');

//With dropshipping switched off, nothing queues at all.
$db->settings['dropshipEnabled'] = false;
$db->fulfilments = array();
storeCheck(storeQueueFulfilments($dropOrderId, $db->getOrderItems($dropOrderId), $db->getSettings()) === 0, 'Queued a supplier order while dropshipping was off');
storeCheck(storeSubmitQueued() === array('ok' => true, 'sent' => 0), 'Submitted while dropshipping was off');
$db->settings['dropshipEnabled'] = true;

//A digital product can never be routed to a supplier. The file has to exist, or
//the refusal would be about the missing file rather than the routing.
storeTestSignIn(true);
$deliverable = __DIR__.'/../files/store/store-test-deliverable.bin';
file_put_contents($deliverable, 'sample');
try{
	$refused = saveStoreProduct(array('name'=>'X','sku'=>'X2','price'=>'1.00','kind'=>'digital',
		'downloadPath'=>'store-test-deliverable.bin','fulfilment'=>'dropship','dropProvider'=>'printful','dropVariantId'=>'1'));
	storeCheck(is_string($refused) && stripos($refused, 'supplier') !== false, 'A download was routed to a supplier: '.var_export($refused, true));
}finally{
	unlink($deliverable);
}
//And a supplier product needs the ids that supplier actually uses.
$refused = saveStoreProduct(array('name'=>'X','sku'=>'X3','price'=>'1.00','kind'=>'physical',
	'fulfilment'=>'dropship','dropProvider'=>'printify','dropVariantId'=>'9'));
storeCheck(is_string($refused) && stripos($refused, 'product id') !== false, 'Printify accepted a mapping with no product id');
$refused = saveStoreProduct(array('name'=>'X','sku'=>'X4','price'=>'1.00','kind'=>'physical',
	'fulfilment'=>'dropship','dropProvider'=>'not-a-supplier','dropVariantId'=>'9'));
storeCheck(is_string($refused) && stripos($refused, 'supplier') !== false, 'An unknown supplier was accepted');
//Supplier ids are opaque, but bounded.
storeCheck(storeSupplierId('4012') === '4012' && storeSupplierId('sync-99') === 'sync-99', 'A valid supplier id was rejected');
storeCheck(storeSupplierId('<script>') === '' && storeSupplierId(str_repeat('9', 65)) === '', 'An unusable supplier id was accepted');

//Retrying is admin-only, like every other management endpoint.
storeTestSignOut();
storeCheck(storeRetryFulfilment(1) === 'Admin access required.', 'Retry served a signed-out caller');
storeCheck(storeRefreshFulfilment(1) === 'Admin access required.', 'Refresh served a signed-out caller');
unset($GLOBALS['storeDropshipTransport']);
storeTestSignIn(true);

/* ---------------- digital delivery paths ---------------- */

foreach(array('../../ghoti.settings.json', '/etc/passwd', 'x/../../../etc/passwd', '', "a\0b",
	'../gallery/photo.jpg', '.htaccess', 'sub/.hidden') as $bad){
	storeCheck(storeSafeDownloadPath($bad) === '', "Download path escaped files/store/: '$bad'");
}
storeCheck(storeSafeDownloadPath('definitely-not-here.bin') === '', 'A path with no file was accepted');

//A real file inside files/store/ is the only thing that is accepted.
$base = __DIR__.'/../files/store';
$sample = $base.'/store-test-sample.bin';
file_put_contents($sample, 'sample');
try{
	storeCheck(storeSafeDownloadPath('store-test-sample.bin') === 'store-test-sample.bin', 'A genuine file under files/store/ was rejected');
}finally{
	unlink($sample);
}

//files/ itself stays web-readable (gallery images, product photos), so paid files
//live in files/store/, and that directory must carry its own deny rule. Without
//it the token, expiry and download count are all decorative.
$deny = @file_get_contents($base.'/.htaccess');
storeCheck(is_string($deny), 'files/store/ has no .htaccess');
storeCheck(strpos($deny, 'Require all denied') !== false, 'files/store/ does not deny HTTP access');
storeCheck(strpos($deny, 'Options -Indexes') !== false, 'files/store/ allows directory listings');

//The delivery endpoint is a plain URL with no session, so the token has to carry
//all of the proof and the file may never be served inline - a purchased .html or
//.svg rendered in this origin would run as this site.
$delivery = file_get_contents(__DIR__.'/../mod/store/store.download.php');
storeCheck(strpos($delivery, "'/^[a-f0-9]{48}$/'") !== false, 'Download token pattern is not an anchored 48-hex match');
storeCheck(strpos($delivery, "Content-Type: application/octet-stream") !== false, 'Downloads are not forced to a generic type');
storeCheck(strpos($delivery, "Content-Disposition: attachment") !== false, 'Downloads are not forced to an attachment');
storeCheck(strpos($delivery, 'claimDownload') < strpos($delivery, 'readfile'), 'The download is sent before it is claimed');

echo "PASS: $checks store assertions; no database, no PayPal, no mail\n";
