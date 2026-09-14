<?php
/*
 * store.paypal.php - a small PayPal Orders v2 client.
 *
 * Mirrors mail.smtp.php: a dependency-free client class the module owns, rather
 * than a vendored SDK. It speaks exactly three calls - get an access token,
 * create an order, capture an order - because that is the whole of what a
 * server-side checkout needs.
 *
 * The transport is injectable (a callable taking a request array and returning
 * array(status, body)) for the same reason GhotiAlertService takes one: the
 * capture path is the part that must be tested, and it cannot reach PayPal from
 * a test. cURL is the default transport.
 *
 * Amounts crossing this boundary are strings in major units ("12.34") because
 * that is the wire format PayPal requires. Every amount is produced here from
 * integer cents; nothing in this file accepts a float.
 */

class StorePaypalException extends RuntimeException {}

class StorePaypalClient{
	const SANDBOX_BASE = 'https://api-m.sandbox.paypal.com';
	const LIVE_BASE    = 'https://api-m.paypal.com';

	private $clientId;
	private $secret;
	private $base;
	private $transport;
	private $token = null;

	public function __construct($settings, callable $transport = null){
		$this->clientId = (string)($settings['paypalClientId'] ?? '');
		$this->secret   = (string)($settings['paypalSecret'] ?? '');
		$this->base     = ($settings['paypalEnv'] ?? 'sandbox') === 'live' ? self::LIVE_BASE : self::SANDBOX_BASE;
		$this->transport = $transport ?: array($this, 'curlTransport');
	}

	public static function configured($settings){
		return trim((string)($settings['paypalClientId'] ?? '')) !== ''
			&& trim((string)($settings['paypalSecret'] ?? '')) !== '';
	}

	//Which PayPal the buyer's browser should load the SDK from. The client id is
	//public by design - it identifies the merchant in the button script - while
	//the secret never leaves the server.
	public function clientId(){ return $this->clientId; }

	/* ---------------- calls ---------------- */

	private function accessToken(){
		if($this->token !== null){ return $this->token; }
		$response = $this->send(array(
			'method'  => 'POST',
			'url'     => $this->base.'/v1/oauth2/token',
			'headers' => array('Content-Type: application/x-www-form-urlencoded'),
			'body'    => 'grant_type=client_credentials',
			'auth'    => $this->clientId.':'.$this->secret,
		));
		$payload = $this->decode($response);
		if(empty($payload['access_token'])){
			throw new StorePaypalException('PayPal did not issue an access token. Check the client ID and secret.');
		}
		$this->token = (string)$payload['access_token'];
		return $this->token;
	}

	/*
	 * Create an order from figures the server computed. $items carry their own
	 * prices so the buyer sees an itemised PayPal page, but the total PayPal
	 * charges is the amount below, which is the one this app recorded.
	 */
	public function createOrder($order, $items){
		$currency = (string)$order['currency'];
		$breakdown = array(
			'item_total' => array('currency_code' => $currency, 'value' => self::amount((int)$order['subtotalCents'])),
		);
		if((int)$order['shippingCents'] > 0){
			$breakdown['shipping'] = array('currency_code' => $currency, 'value' => self::amount((int)$order['shippingCents']));
		}

		$lineItems = array();
		foreach($items as $item){
			$lineItems[] = array(
				'name'        => self::clip($item['name'], 127),
				'sku'         => self::clip($item['sku'], 127),
				'quantity'    => (string)(int)$item['quantity'],
				'category'    => $item['kind'] === 'digital' ? 'DIGITAL_GOODS' : 'PHYSICAL_GOODS',
				'unit_amount' => array('currency_code' => $currency, 'value' => self::amount((int)$item['unitCents'])),
			);
		}

		$purchaseUnit = array(
			'reference_id' => (string)$order['reference'],
			'custom_id'    => (string)$order['reference'],
			'amount'       => array(
				'currency_code' => $currency,
				'value'         => self::amount((int)$order['totalCents']),
				'breakdown'     => $breakdown,
			),
			'items' => $lineItems,
		);

		$body = array(
			'intent'         => 'CAPTURE',
			'purchase_units' => array($purchaseUnit),
			//No shipping address is sent to PayPal: this app collects it and is
			//the system of record for fulfilment.
			'payment_source' => array('paypal' => array('experience_context' => array(
				'shipping_preference' => 'NO_SHIPPING',
				'user_action'         => 'PAY_NOW',
			))),
		);

		$payload = $this->decode($this->authorized('POST', '/v2/checkout/orders', $body));
		if(empty($payload['id'])){
			throw new StorePaypalException('PayPal did not return an order id.');
		}
		return (string)$payload['id'];
	}

	/*
	 * Capture, and report back only what the caller must verify: the PayPal
	 * capture id, and the amount and currency PayPal actually took. The caller
	 * compares those against its own record before marking anything paid.
	 */
	public function captureOrder($paypalOrderId){
		$payload = $this->decode($this->authorized('POST', '/v2/checkout/orders/'.rawurlencode($paypalOrderId).'/capture', new stdClass()));
		$status = (string)($payload['status'] ?? '');
		$capture = $payload['purchase_units'][0]['payments']['captures'][0] ?? null;
		if($status !== 'COMPLETED' || !is_array($capture)){
			throw new StorePaypalException('PayPal did not complete the payment (status: '.($status !== '' ? $status : 'unknown').').');
		}
		return array(
			'captureId'  => (string)($capture['id'] ?? ''),
			'status'     => (string)($capture['status'] ?? ''),
			'valueCents' => self::cents((string)($capture['amount']['value'] ?? '0')),
			'currency'   => (string)($capture['amount']['currency_code'] ?? ''),
			'payerEmail' => (string)($payload['payer']['email_address'] ?? ''),
		);
	}

	/* ---------------- plumbing ---------------- */

	private function authorized($method, $path, $body){
		return $this->send(array(
			'method'  => $method,
			'url'     => $this->base.$path,
			'headers' => array(
				'Content-Type: application/json',
				'Authorization: Bearer '.$this->accessToken(),
			),
			'body'    => json_encode($body),
		));
	}

	private function send($request){
		$response = call_user_func($this->transport, $request);
		if(!is_array($response) || !isset($response['status'])){
			throw new StorePaypalException('The PayPal request could not be completed.');
		}
		return $response;
	}

	//PayPal's error bodies carry a name/message plus a details array. Surface
	//enough to act on, never the raw body: it can echo request content back.
	private function decode($response){
		$payload = json_decode((string)($response['body'] ?? ''), true);
		$status = (int)$response['status'];
		if($status < 200 || $status >= 300){
			$name = is_array($payload) ? (string)($payload['name'] ?? '') : '';
			$detail = '';
			if(is_array($payload) && isset($payload['details'][0]['issue'])){
				$detail = (string)$payload['details'][0]['issue'];
			}
			$parts = array_filter(array($name, $detail));
			throw new StorePaypalException('PayPal refused the request (HTTP '.$status.($parts ? ': '.implode(' / ', $parts) : '').').');
		}
		if(!is_array($payload)){
			throw new StorePaypalException('PayPal returned a response this app could not read.');
		}
		return $payload;
	}

	private function curlTransport($request){
		if(!function_exists('curl_init')){
			throw new StorePaypalException('PHP cURL is not available, so this site cannot talk to PayPal.');
		}
		$handle = curl_init();
		curl_setopt_array($handle, array(
			CURLOPT_URL            => $request['url'],
			CURLOPT_CUSTOMREQUEST  => $request['method'],
			CURLOPT_POSTFIELDS     => isset($request['body']) ? $request['body'] : null,
			CURLOPT_HTTPHEADER     => isset($request['headers']) ? $request['headers'] : array(),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_CONNECTTIMEOUT => 10,
			//Never negotiable: this connection carries the merchant credentials.
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_FOLLOWLOCATION => false,
		));
		if(isset($request['auth'])){
			curl_setopt($handle, CURLOPT_USERPWD, $request['auth']);
			curl_setopt($handle, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		}
		$body = curl_exec($handle);
		$status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		$error = curl_error($handle);
		curl_close($handle);
		if($body === false){
			//The cURL message can name internal hosts and proxies; log it, do not return it.
			ghoti::logError("store.paypal.php:curlTransport", "PayPal request failed: ".$error);
			throw new StorePaypalException('This site could not reach PayPal. Try again in a moment.');
		}
		return array('status' => $status, 'body' => (string)$body);
	}

	/* ---------------- money ---------------- */

	//Integer cents to the "12.34" string PayPal expects. Integer arithmetic
	//only: 1234/100 in floating point is not reliably 12.34.
	public static function amount($cents){
		$cents = (int)$cents;
		$sign = $cents < 0 ? '-' : '';
		$cents = abs($cents);
		return $sign.intdiv($cents, 100).'.'.str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
	}

	//"12.34" back to 1234, for comparing what PayPal captured against the order.
	public static function cents($value){
		$value = trim((string)$value);
		if(!preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $value, $m)){ return null; }
		$cents = ((int)$m[2] * 100) + (int)str_pad(isset($m[3]) ? $m[3] : '0', 2, '0', STR_PAD_RIGHT);
		return $m[1] === '-' ? -$cents : $cents;
	}

	private static function clip($value, $max){
		$value = preg_replace('/\s+/', ' ', (string)$value);
		return trim(mb_substr($value, 0, $max));
	}
}
?>
