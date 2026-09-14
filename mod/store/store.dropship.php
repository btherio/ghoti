<?php
/*
 * store.dropship.php - supplier fulfilment drivers.
 *
 * One small class per supplier, behind one interface, in the same spirit as
 * store.paypal.php: no vendored SDKs, an injectable transport, and nothing in
 * here that knows about HTTP beyond the request array it hands to that
 * transport.
 *
 * A driver answers two questions and nothing else:
 *
 *   submitOrder()  - send this paid order to the supplier, return their id
 *   fetchStatus()  - what has the supplier done with it since?
 *
 * That is the whole contract, because it is the whole of what dropshipping is
 * from this side: hand over an address and a list of variant ids, then watch for
 * a tracking number.
 *
 * Adding a supplier means adding a class here, registering it in
 * StoreDropship::drivers(), and nothing else - the admin screens, the queue, the
 * retry path and the CLI drain are all provider-agnostic. See docs/store.md.
 *
 * Every amount stays in integer cents until a driver formats it, and no driver
 * is ever handed a price from the browser: submitOrder() reads the order rows
 * this app wrote.
 */

class StoreDropshipException extends RuntimeException {}

/* A supplier rejecting an order for a reason the admin must fix - a bad variant
 * id, a missing state code - is not worth retrying until something changes.
 * Separating it from a transport failure is what stops the queue grinding. */
class StoreDropshipPermanentException extends StoreDropshipException {}

abstract class StoreDropshipDriver{
	protected $config;
	protected $transport;

	public function __construct($config, callable $transport = null){
		$this->config = is_array($config) ? $config : array();
		$this->transport = $transport ?: array($this, 'curlTransport');
	}

	/* Submit one order. Returns array('providerOrderId'=>string, 'status'=>string).
	 * $items are this order's dropship lines, each already carrying the supplier
	 * ids mapped on the product. */
	abstract public function submitOrder($order, $items);

	/* Current supplier state. Returns array('status','trackingNumber','trackingUrl','carrier'). */
	abstract public function fetchStatus($providerOrderId);

	/* True when this driver has everything it needs to be used at all. */
	abstract public function configured();

	/* Keys beginning with an underscore are runtime state the app parks in the
	 * same config array (CJ's cached access token, for one) - never credentials
	 * an admin types. Settings saves preserve them and the admin form ignores
	 * them, so do not name a declared field with a leading underscore. */
	protected function setting($key, $default = ''){
		return isset($this->config[$key]) ? trim((string)$this->config[$key]) : $default;
	}

	/* ---------------- shared plumbing ---------------- */

	protected function send($request){
		$response = call_user_func($this->transport, $request);
		if(!is_array($response) || !isset($response['status'])){
			throw new StoreDropshipException('The supplier request could not be completed.');
		}
		return $response;
	}

	protected function json($request){
		$response = $this->send($request);
		$payload = json_decode((string)($response['body'] ?? ''), true);
		$status = (int)$response['status'];
		if($status < 200 || $status >= 300){
			$message = $this->errorMessage($payload, $status);
			//4xx is the supplier saying "this order is wrong"; 5xx and transport
			//failures are "try again later". Only the latter stays queued.
			if($status >= 400 && $status < 500 && $status !== 429){
				throw new StoreDropshipPermanentException($message);
			}
			throw new StoreDropshipException($message);
		}
		if(!is_array($payload)){
			throw new StoreDropshipException('The supplier returned a response this app could not read.');
		}
		return $payload;
	}

	//Suppliers disagree about where the message lives; take the first one that
	//looks like prose, and never echo the whole body - it repeats the address.
	protected function errorMessage($payload, $status){
		$candidates = array();
		if(is_array($payload)){
			foreach(array('error','message','result','reason','detail') as $key){
				if(isset($payload[$key]) && is_string($payload[$key])){ $candidates[] = $payload[$key]; }
			}
			if(isset($payload['error']['message']) && is_string($payload['error']['message'])){ $candidates[] = $payload['error']['message']; }
			if(isset($payload['errors']) && is_array($payload['errors'])){
				foreach($payload['errors'] as $field => $problem){
					$candidates[] = is_string($problem) ? $field.': '.$problem : (string)$field;
				}
			}
		}
		$message = $candidates ? $candidates[0] : 'no reason given';
		$message = preg_replace('/\s+/', ' ', (string)$message);
		return 'HTTP '.$status.': '.trim(mb_substr($message, 0, 300));
	}

	protected function curlTransport($request){
		if(!function_exists('curl_init')){
			throw new StoreDropshipException('PHP cURL is not available, so this site cannot reach suppliers.');
		}
		$handle = curl_init();
		curl_setopt_array($handle, array(
			CURLOPT_URL            => $request['url'],
			CURLOPT_CUSTOMREQUEST  => isset($request['method']) ? $request['method'] : 'GET',
			CURLOPT_POSTFIELDS     => isset($request['body']) ? $request['body'] : null,
			CURLOPT_HTTPHEADER     => isset($request['headers']) ? $request['headers'] : array(),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_FOLLOWLOCATION => false,
		));
		$body = curl_exec($handle);
		$status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		$error = curl_error($handle);
		curl_close($handle);
		if($body === false){
			ghoti::logError("store.dropship.php:curlTransport", "supplier request failed: ".$error);
			throw new StoreDropshipException('The supplier could not be reached.');
		}
		return array('status' => $status, 'body' => (string)$body);
	}

	/* Split a customer name into the first/last pair some suppliers insist on.
	 * One-word names keep the surname empty rather than being duplicated. */
	protected static function nameParts($name){
		$name = trim(preg_replace('/\s+/', ' ', (string)$name));
		if($name === ''){ return array('', ''); }
		$position = strrpos($name, ' ');
		if($position === false){ return array($name, ''); }
		return array(substr($name, 0, $position), substr($name, $position + 1));
	}

	protected static function amount($cents){
		return StorePaypalClient::amount((int)$cents);
	}
}

/* ------------------------------------------------------------------ *
 *  Printful - https://developers.printful.com/docs/
 *  Private token in an Authorization header; orders are created against the
 *  account, confirmed immediately so they go to production rather than sitting
 *  as drafts.
 * ------------------------------------------------------------------ */
class StorePrintfulDriver extends StoreDropshipDriver{
	const BASE = 'https://api.printful.com';

	public function configured(){ return $this->setting('token') !== ''; }

	private function headers(){
		$headers = array('Content-Type: application/json', 'Authorization: Bearer '.$this->setting('token'));
		//An account with several stores needs to say which one; a single-store
		//account must not send the header at all.
		if($this->setting('storeId') !== ''){ $headers[] = 'X-PF-Store-Id: '.$this->setting('storeId'); }
		return $headers;
	}

	public function submitOrder($order, $items){
		$lines = array();
		foreach($items as $item){
			$variant = $item['dropVariantId'];
			if($variant === ''){
				throw new StoreDropshipPermanentException('Product "'.$item['name'].'" has no Printful variant id.');
			}
			$line = array(
				'quantity'     => (int)$item['quantity'],
				'name'         => $item['name'],
				'retail_price' => self::amount($item['unitCents']),
			);
			//A numeric id is a catalogue variant; anything else is a sync variant
			//from a connected store, which Printful keys differently.
			if(ctype_digit($variant)){ $line['variant_id'] = (int)$variant; }
			else { $line['sync_variant_id'] = $variant; }
			$lines[] = $line;
		}

		$payload = $this->json(array(
			'method'  => 'POST',
			//confirm=1 submits for fulfilment. Without it the order waits in the
			//dashboard as a draft and nobody is told.
			'url'     => self::BASE.'/orders?confirm=1',
			'headers' => $this->headers(),
			'body'    => json_encode(array(
				'external_id' => $order['reference'],
				'recipient'   => $this->recipient($order),
				'items'       => $lines,
			)),
		));
		$result = isset($payload['result']) && is_array($payload['result']) ? $payload['result'] : array();
		if(empty($result['id'])){
			throw new StoreDropshipException('Printful accepted the request but returned no order id.');
		}
		return array(
			'providerOrderId' => (string)$result['id'],
			'status'          => self::mapStatus((string)($result['status'] ?? '')),
		);
	}

	private function recipient($order){
		$recipient = array(
			'name'         => $order['customerName'],
			'address1'     => $order['address1'],
			'address2'     => $order['address2'],
			'city'         => $order['city'],
			'country_code' => $order['country'],
			'zip'          => $order['postcode'],
			'email'        => $order['email'],
		);
		//Printful rejects US/CA/AU addresses with no state code, and accepts the
		//field as-is elsewhere. Sending the region when we have it is the whole
		//fix; a rejection still lands in lastError rather than at the buyer.
		if($order['region'] !== ''){ $recipient['state_code'] = $order['region']; }
		return $recipient;
	}

	public function fetchStatus($providerOrderId){
		$payload = $this->json(array(
			'method'  => 'GET',
			'url'     => self::BASE.'/orders/'.rawurlencode($providerOrderId),
			'headers' => $this->headers(),
		));
		$result = isset($payload['result']) && is_array($payload['result']) ? $payload['result'] : array();
		$shipment = isset($result['shipments'][0]) && is_array($result['shipments'][0]) ? $result['shipments'][0] : array();
		return array(
			'status'         => self::mapStatus((string)($result['status'] ?? '')),
			'trackingNumber' => (string)($shipment['tracking_number'] ?? ''),
			'trackingUrl'    => (string)($shipment['tracking_url'] ?? ''),
			'carrier'        => trim((string)($shipment['carrier'] ?? '').' '.(string)($shipment['service'] ?? '')),
		);
	}

	private static function mapStatus($status){
		switch(strtolower($status)){
			case 'fulfilled': return 'shipped';
			case 'canceled':
			case 'cancelled': return 'cancelled';
			case 'failed':    return 'failed';
			default:          return 'sent';
		}
	}
}

/* ------------------------------------------------------------------ *
 *  Printify - https://developers.printify.com/
 *  Shop-scoped: every call carries the shop id, and an order is created first,
 *  then pushed to production as a second call.
 * ------------------------------------------------------------------ */
class StorePrintifyDriver extends StoreDropshipDriver{
	const BASE = 'https://api.printify.com/v1';

	public function configured(){ return $this->setting('token') !== '' && $this->setting('shopId') !== ''; }

	private function headers(){
		return array(
			'Content-Type: application/json',
			'Authorization: Bearer '.$this->setting('token'),
			//Printify asks every client to identify itself.
			'User-Agent: GhotiCMS-Store',
		);
	}

	private function shopUrl($path){
		return self::BASE.'/shops/'.rawurlencode($this->setting('shopId')).$path;
	}

	public function submitOrder($order, $items){
		$lines = array();
		foreach($items as $item){
			if($item['dropProductId'] === '' || $item['dropVariantId'] === ''){
				throw new StoreDropshipPermanentException('Product "'.$item['name'].'" needs both a Printify product id and variant id.');
			}
			$lines[] = array(
				'product_id' => $item['dropProductId'],
				'variant_id' => (int)$item['dropVariantId'],
				'quantity'   => (int)$item['quantity'],
			);
		}

		list($first, $last) = self::nameParts($order['customerName']);
		$payload = $this->json(array(
			'method'  => 'POST',
			'url'     => $this->shopUrl('/orders.json'),
			'headers' => $this->headers(),
			'body'    => json_encode(array(
				'external_id' => $order['reference'],
				'label'       => $order['reference'],
				'line_items'  => $lines,
				'address_to'  => array(
					'first_name' => $first,
					'last_name'  => $last,
					'email'      => $order['email'],
					'country'    => $order['country'],
					'region'     => $order['region'],
					'city'       => $order['city'],
					'address1'   => $order['address1'],
					'address2'   => $order['address2'],
					'zip'        => $order['postcode'],
				),
				//This app sends its own receipt; a second one from Printify would
				//be the first the buyer hears of a supplier they never chose.
				'send_shipping_notification' => false,
			)),
		));
		$providerOrderId = (string)($payload['id'] ?? '');
		if($providerOrderId === ''){
			throw new StoreDropshipException('Printify accepted the request but returned no order id.');
		}

		//Creating an order only stages it. Production is a second call, and a
		//failure here leaves a real order in the dashboard - so it is reported
		//as sent-with-an-error rather than swallowed or retried blindly.
		try{
			$this->json(array(
				'method'  => 'POST',
				'url'     => $this->shopUrl('/orders/'.rawurlencode($providerOrderId).'/send-to-production.json'),
				'headers' => $this->headers(),
				'body'    => json_encode(new stdClass()),
			));
		}catch(StoreDropshipException $e){
			ghoti::logError("store.dropship.php:printify", "order ".$order['reference']." was created but not sent to production: ".$e->getMessage());
			return array('providerOrderId' => $providerOrderId, 'status' => 'sent',
				'warning' => 'Created at Printify but not sent to production: '.$e->getMessage());
		}
		return array('providerOrderId' => $providerOrderId, 'status' => 'sent');
	}

	public function fetchStatus($providerOrderId){
		$payload = $this->json(array(
			'method'  => 'GET',
			'url'     => $this->shopUrl('/orders/'.rawurlencode($providerOrderId).'.json'),
			'headers' => $this->headers(),
		));
		$shipment = isset($payload['shipments'][0]) && is_array($payload['shipments'][0]) ? $payload['shipments'][0] : array();
		return array(
			'status'         => self::mapStatus((string)($payload['status'] ?? '')),
			'trackingNumber' => (string)($shipment['number'] ?? ''),
			'trackingUrl'    => (string)($shipment['url'] ?? ''),
			'carrier'        => (string)($shipment['carrier'] ?? ''),
		);
	}

	private static function mapStatus($status){
		$status = strtolower($status);
		if(in_array($status, array('fulfilled','shipped','partially-fulfilled'), true)){ return 'shipped'; }
		if(in_array($status, array('canceled','cancelled'), true)){ return 'cancelled'; }
		if(strpos($status, 'failed') !== false || $status === 'payment-not-received'){ return 'failed'; }
		return 'sent';
	}
}

/* ------------------------------------------------------------------ *
 *  CJ Dropshipping - https://developers.cjdropshipping.com/api2.0/v1/
 *  Email + API key are exchanged for an access token carried in its own header.
 *  The token endpoint is rate limited (one call per 5 minutes), so the token is
 *  cached in the settings row rather than fetched per submission.
 * ------------------------------------------------------------------ */
class StoreCjDriver extends StoreDropshipDriver{
	const BASE = 'https://developers.cjdropshipping.com/api2.0/v1';

	private $tokenStore;

	//$tokenStore is a pair of callables, so the driver can cache its token
	//without knowing that settings are a database row.
	public function __construct($config, callable $transport = null, $tokenStore = null){
		parent::__construct($config, $transport);
		$this->tokenStore = is_array($tokenStore) ? $tokenStore : null;
	}

	public function configured(){ return $this->setting('email') !== '' && $this->setting('apiKey') !== ''; }

	private function accessToken(){
		if($this->tokenStore){
			$cached = call_user_func($this->tokenStore['get']);
			if(is_array($cached) && !empty($cached['token']) && (int)($cached['expires'] ?? 0) > time() + 300){
				return (string)$cached['token'];
			}
		}
		$payload = $this->json(array(
			'method'  => 'POST',
			'url'     => self::BASE.'/authentication/getAccessToken',
			'headers' => array('Content-Type: application/json'),
			'body'    => json_encode(array('email' => $this->setting('email'), 'password' => $this->setting('apiKey'))),
		));
		$result = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : array();
		$token = (string)($result['accessToken'] ?? '');
		if($token === ''){
			throw new StoreDropshipPermanentException('CJ did not issue an access token. Check the account e-mail and API key.');
		}
		$expires = isset($result['accessTokenExpiryDate']) ? strtotime((string)$result['accessTokenExpiryDate']) : 0;
		if(!$expires){ $expires = time() + 3600; }
		if($this->tokenStore){
			call_user_func($this->tokenStore['set'], array('token' => $token, 'expires' => $expires));
		}
		return $token;
	}

	private function headers(){
		return array('Content-Type: application/json', 'CJ-Access-Token: '.$this->accessToken());
	}

	//CJ answers 200 with a result flag rather than an HTTP error, so success has
	//to be read out of the body.
	private function call($method, $path, $body = null){
		$payload = $this->json(array(
			'method'  => $method,
			'url'     => self::BASE.$path,
			'headers' => $this->headers(),
			'body'    => $body === null ? null : json_encode($body),
		));
		if(isset($payload['result']) && $payload['result'] === false){
			$message = (string)($payload['message'] ?? 'no reason given');
			throw new StoreDropshipPermanentException('CJ refused the order: '.trim(mb_substr($message, 0, 300)));
		}
		return isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : array();
	}

	public function submitOrder($order, $items){
		$products = array();
		foreach($items as $item){
			if($item['dropVariantId'] === ''){
				throw new StoreDropshipPermanentException('Product "'.$item['name'].'" has no CJ variant id (vid).');
			}
			$products[] = array(
				'vid'      => $item['dropVariantId'],
				'quantity' => (int)$item['quantity'],
			);
		}
		$data = $this->call('POST', '/shopping/order/createOrderV2', array(
			'orderNumber'          => $order['reference'],
			'shippingCountryCode'  => $order['country'],
			'shippingProvince'     => $order['region'],
			'shippingCity'         => $order['city'],
			'shippingAddress'      => trim($order['address1'].' '.$order['address2']),
			'shippingCustomerName' => $order['customerName'],
			'shippingZip'          => $order['postcode'],
			'shippingPhone'        => $this->setting('phone'),
			'email'                => $order['email'],
			'logisticName'         => $this->setting('logistic', 'CJPacket Ordinary'),
			'fromCountryCode'      => $this->setting('fromCountry', 'CN'),
			'products'             => $products,
		));
		$providerOrderId = (string)($data['orderId'] ?? '');
		if($providerOrderId === ''){
			throw new StoreDropshipException('CJ accepted the request but returned no order id.');
		}
		return array('providerOrderId' => $providerOrderId, 'status' => 'sent');
	}

	public function fetchStatus($providerOrderId){
		$data = $this->call('GET', '/shopping/order/getOrderDetail?orderId='.rawurlencode($providerOrderId));
		$tracking = (string)($data['trackNumber'] ?? '');
		return array(
			'status'         => self::mapStatus((string)($data['orderStatus'] ?? ''), $tracking),
			'trackingNumber' => $tracking,
			'trackingUrl'    => $tracking === '' ? '' : 'https://t.17track.net/en#nums='.rawurlencode($tracking),
			'carrier'        => (string)($data['logisticName'] ?? ''),
		);
	}

	private static function mapStatus($status, $tracking){
		$status = strtoupper($status);
		if($status === 'CANCELLED' || $status === 'CANCELED'){ return 'cancelled'; }
		if($status === 'SHIPPED' || $status === 'DELIVERED' || $tracking !== ''){ return 'shipped'; }
		return 'sent';
	}
}

/* ------------------------------------------------------------------ *
 *  Generic webhook - any supplier, via whatever sits in front of it.
 *
 *  This is how the suppliers without a public order API get wired up in
 *  practice: the order is POSTed as JSON to an endpoint you control - your own
 *  middleware, an automation platform, a supplier's private integration - which
 *  does the talking. The request is signed with a shared secret so the receiver
 *  can prove it came from this site.
 * ------------------------------------------------------------------ */
class StoreWebhookDriver extends StoreDropshipDriver{
	public function configured(){ return $this->setting('url') !== ''; }

	private function sign($body){
		$headers = array('Content-Type: application/json');
		$secret = $this->setting('secret');
		if($secret !== ''){
			//Timestamped so a captured request cannot be replayed indefinitely;
			//the receiver should reject a stale one.
			$timestamp = (string)time();
			$headers[] = 'X-Ghoti-Timestamp: '.$timestamp;
			$headers[] = 'X-Ghoti-Signature: sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
		}
		return $headers;
	}

	public function submitOrder($order, $items){
		$lines = array();
		foreach($items as $item){
			$lines[] = array(
				'sku'        => $item['sku'],
				'name'       => $item['name'],
				'quantity'   => (int)$item['quantity'],
				'unitAmount' => self::amount($item['unitCents']),
				'productId'  => $item['dropProductId'],
				'variantId'  => $item['dropVariantId'],
			);
		}
		$body = json_encode(array(
			'event'     => 'order.paid',
			'reference' => $order['reference'],
			'currency'  => $order['currency'],
			'total'     => self::amount($order['totalCents']),
			'customer'  => array('name' => $order['customerName'], 'email' => $order['email']),
			'shipTo'    => array(
				'address1' => $order['address1'], 'address2' => $order['address2'],
				'city' => $order['city'], 'region' => $order['region'],
				'postcode' => $order['postcode'], 'country' => $order['country'],
			),
			'items'     => $lines,
			'note'      => $order['note'],
		));

		$payload = $this->json(array(
			'method'  => 'POST',
			'url'     => $this->setting('url'),
			'headers' => $this->sign($body),
			'body'    => $body,
		));
		//A receiver that answers with an id lets this order be tracked; one that
		//just answers 200 is taken at its word, with the reference as the key.
		$providerOrderId = (string)($payload['orderId'] ?? $payload['id'] ?? $order['reference']);
		return array('providerOrderId' => $providerOrderId, 'status' => 'sent');
	}

	public function fetchStatus($providerOrderId){
		$statusUrl = $this->setting('statusUrl');
		if($statusUrl === ''){
			//Nothing to poll: the receiver is expected to be the source of truth
			//and the admin marks the order shipped by hand.
			return array('status' => 'sent', 'trackingNumber' => '', 'trackingUrl' => '', 'carrier' => '');
		}
		$url = $statusUrl.(strpos($statusUrl, '?') === false ? '?' : '&').'orderId='.rawurlencode($providerOrderId);
		$payload = $this->json(array('method' => 'GET', 'url' => $url, 'headers' => $this->sign('')));
		$status = strtolower((string)($payload['status'] ?? 'sent'));
		if(!in_array($status, array('sent','shipped','failed','cancelled'), true)){ $status = 'sent'; }
		return array(
			'status'         => $status,
			'trackingNumber' => (string)($payload['trackingNumber'] ?? ''),
			'trackingUrl'    => (string)($payload['trackingUrl'] ?? ''),
			'carrier'        => (string)($payload['carrier'] ?? ''),
		);
	}
}

/* ------------------------------------------------------------------ *
 *  Registry
 * ------------------------------------------------------------------ */
final class StoreDropship{
	/*
	 * Every supplier this store can talk to: the driver class, the label the
	 * admin sees, and the credential fields its screen should render. Adding a
	 * supplier is adding a row here and a class above.
	 */
	public static function drivers(){
		return array(
			'printful' => array(
				'label'  => 'Printful',
				'class'  => 'StorePrintfulDriver',
				'help'   => 'Create a private token under Printful → Settings → Developers. The store id is only needed on an account with more than one store.',
				'fields' => array(
					'token'   => array('label' => 'Private token', 'secret' => true),
					'storeId' => array('label' => 'Store id', 'secret' => false, 'optional' => true),
				),
				'mapping' => array('variant' => 'Variant id (catalogue) or sync variant id', 'product' => ''),
			),
			'printify' => array(
				'label'  => 'Printify',
				'class'  => 'StorePrintifyDriver',
				'help'   => 'Create a personal access token under Printify → My profile → Connections. The shop id comes from GET /v1/shops.json.',
				'fields' => array(
					'token'  => array('label' => 'Personal access token', 'secret' => true),
					'shopId' => array('label' => 'Shop id', 'secret' => false),
				),
				'mapping' => array('variant' => 'Variant id', 'product' => 'Product id'),
			),
			'cj' => array(
				'label'  => 'CJ Dropshipping',
				'class'  => 'StoreCjDriver',
				'help'   => 'Use the account e-mail and the API key from CJ → Authorization → API. The token endpoint is rate limited, so the token is cached here between orders.',
				'fields' => array(
					'email'       => array('label' => 'Account e-mail', 'secret' => false),
					'apiKey'      => array('label' => 'API key', 'secret' => true),
					'phone'       => array('label' => 'Contact phone for shipments', 'secret' => false, 'optional' => true),
					'logistic'    => array('label' => 'Shipping method', 'secret' => false, 'optional' => true),
					'fromCountry' => array('label' => 'Ship from country code', 'secret' => false, 'optional' => true),
				),
				'mapping' => array('variant' => 'Variant id (vid)', 'product' => ''),
			),
			'webhook' => array(
				'label'  => 'Generic webhook',
				'class'  => 'StoreWebhookDriver',
				'help'   => 'POSTs the paid order as JSON to an endpoint you control, signed with HMAC-SHA256. Use this for any supplier without a public order API, through your own middleware or an automation platform.',
				'fields' => array(
					'url'       => array('label' => 'Order endpoint URL', 'secret' => false),
					'secret'    => array('label' => 'Signing secret', 'secret' => true, 'optional' => true),
					'statusUrl' => array('label' => 'Status endpoint URL', 'secret' => false, 'optional' => true),
				),
				'mapping' => array('variant' => 'Variant id passed through', 'product' => 'Product id passed through'),
			),
		);
	}

	public static function isProvider($provider){
		return is_string($provider) && isset(self::drivers()[$provider]);
	}

	public static function label($provider){
		$drivers = self::drivers();
		return isset($drivers[$provider]) ? $drivers[$provider]['label'] : $provider;
	}

	/*
	 * The one place a driver is constructed. $GLOBALS['storeDropshipTransport']
	 * is the transport seam, exactly as storePaypalClient() has: production
	 * never sets it, tests substitute a mock so submission and status parsing
	 * can be exercised without a supplier account.
	 */
	public static function driver($provider, $config, $tokenStore = null){
		$drivers = self::drivers();
		if(!isset($drivers[$provider])){
			throw new StoreDropshipPermanentException('Unknown dropshipping provider "'.$provider.'".');
		}
		$transport = isset($GLOBALS['storeDropshipTransport']) && is_callable($GLOBALS['storeDropshipTransport'])
			? $GLOBALS['storeDropshipTransport'] : null;
		$class = $drivers[$provider]['class'];
		if($class === 'StoreCjDriver'){
			return new StoreCjDriver($config, $transport, $tokenStore);
		}
		return new $class($config, $transport);
	}
}
?>
