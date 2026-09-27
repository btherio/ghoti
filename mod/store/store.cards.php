<?php
/* Stripe PaymentIntents and Square Web Payments/Payments API clients.
 * Browser SDKs collect card data; this server receives only provider tokens.
 * Every success response is reduced to the amount, currency, status and local
 * reference that store.async.php must verify before an order becomes paid.
 */

class StoreCardException extends RuntimeException {}

abstract class StoreCardClient{
	protected $transport;
	protected function send($request, $provider){
		$response = call_user_func($this->transport, $request);
		if(!is_array($response) || !isset($response['status'])){ throw new StoreCardException($provider.' could not be reached.'); }
		$payload = json_decode((string)($response['body'] ?? ''), true, 512, JSON_BIGINT_AS_STRING);
		$status = (int)$response['status'];
		if($status < 200 || $status >= 300){
			$message = '';
			if(is_array($payload)){
				$message = (string)($payload['error']['message'] ?? ($payload['errors'][0]['detail'] ?? ''));
			}
			throw new StoreCardException($provider.' refused the payment request (HTTP '.$status.($message !== '' ? ': '.$message : '').').');
		}
		if(!is_array($payload)){ throw new StoreCardException($provider.' returned a response this app could not read.'); }
		return $payload;
	}
	protected function curl($request, $provider){
		if(!function_exists('curl_init')){ throw new StoreCardException('PHP cURL is not available, so this site cannot reach '.$provider.'.'); }
		$handle = curl_init();
		curl_setopt_array($handle, array(CURLOPT_URL=>$request['url'], CURLOPT_CUSTOMREQUEST=>$request['method'],
			CURLOPT_POSTFIELDS=>$request['body'] ?? null, CURLOPT_HTTPHEADER=>$request['headers'] ?? array(),
			CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30, CURLOPT_CONNECTTIMEOUT=>10,
			CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_FOLLOWLOCATION=>false));
		$body = curl_exec($handle); $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE); $error = curl_error($handle); curl_close($handle);
		if($body === false){ ghoti::logError('store.cards.php:curl', $provider.' request failed: '.$error); throw new StoreCardException('This site could not reach '.$provider.'. Try again in a moment.'); }
		return array('status'=>$status, 'body'=>(string)$body);
	}
}

class StoreStripeClient extends StoreCardClient{
	const API_BASE = 'https://api.stripe.com';
	private $publishableKey;
	private $secretKey;
	public function __construct($settings, callable $transport = null){
		$this->publishableKey = trim((string)($settings['stripePublishableKey'] ?? ''));
		$this->secretKey = trim((string)($settings['stripeSecretKey'] ?? ''));
		$this->transport = $transport ?: function($request){ return $this->curl($request, 'Stripe'); };
	}
	public static function configured($settings){
		$publishable=trim((string)($settings['stripePublishableKey'] ?? '')); $secret=trim((string)($settings['stripeSecretKey'] ?? ''));
		if(!preg_match('/^pk_(test|live)_[A-Za-z0-9_]+$/',$publishable,$pk) || !preg_match('/^sk_(test|live)_[A-Za-z0-9_]+$/',$secret,$sk)){ return false; }
		return !empty($settings['stripeEnabled']) && $pk[1]===$sk[1];
	}
	public function publishableKey(){ return $this->publishableKey; }
	public function createIntent($order){
		$payload = $this->request('POST', '/v1/payment_intents', array(
			'amount'=>(string)(int)$order['totalCents'], 'currency'=>strtolower((string)$order['currency']),
			'payment_method_types[0]'=>'card', 'metadata[order_reference]'=>(string)$order['reference'],
			'description'=>'Store order '.(string)$order['reference'], 'receipt_email'=>(string)$order['email']));
		$payment = $this->normalise($payload);
		if(empty($payload['client_secret']) || strlen((string)$payload['client_secret']) > 255){ throw new StoreCardException('Stripe did not return a usable client secret.'); }
		$payment['clientSecret'] = (string)$payload['client_secret'];
		return $payment;
	}
	public function getIntent($id){
		if(!preg_match('/^pi_[A-Za-z0-9_]{8,96}$/', (string)$id)){ throw new StoreCardException('That Stripe payment reference is not valid.'); }
		return $this->normalise($this->request('GET', '/v1/payment_intents/'.rawurlencode($id), null));
	}
	private function normalise($p){
		$id = (string)($p['id'] ?? ''); $status = (string)($p['status'] ?? '');
		if(!preg_match('/^pi_[A-Za-z0-9_]{8,96}$/', $id) || !preg_match('/^[a-z_]{2,32}$/', $status)){ throw new StoreCardException('Stripe returned invalid payment details.'); }
		return array('id'=>$id, 'status'=>$status, 'amount'=>(int)($p['amount'] ?? -1),
			'amountReceived'=>(int)($p['amount_received'] ?? 0), 'currency'=>strtoupper((string)($p['currency'] ?? '')),
			'reference'=>(string)($p['metadata']['order_reference'] ?? ''));
	}
	private function request($method, $path, $body){
		if(preg_match('/[\r\n]/', $this->secretKey)){ throw new StoreCardException('The Stripe secret key is not configured correctly.'); }
		$request = array('method'=>$method, 'url'=>self::API_BASE.$path,
			'headers'=>array('Authorization: Bearer '.$this->secretKey, 'Content-Type: application/x-www-form-urlencoded'));
		if($body !== null){ $request['body'] = http_build_query($body, '', '&', PHP_QUERY_RFC3986); }
		return $this->send($request, 'Stripe');
	}
}

class StoreSquareClient extends StoreCardClient{
	const SANDBOX_BASE = 'https://connect.squareupsandbox.com';
	const LIVE_BASE = 'https://connect.squareup.com';
	private $accessToken;
	private $locationId;
	private $base;
	public function __construct($settings, callable $transport = null){
		$this->accessToken = trim((string)($settings['squareAccessToken'] ?? ''));
		$this->locationId = trim((string)($settings['squareLocationId'] ?? ''));
		$this->base = ($settings['squareEnv'] ?? 'sandbox') === 'live' ? self::LIVE_BASE : self::SANDBOX_BASE;
		$this->transport = $transport ?: function($request){ return $this->curl($request, 'Square'); };
	}
	public static function configured($settings){
		return !empty($settings['squareEnabled']) && trim((string)($settings['squareApplicationId'] ?? '')) !== ''
			&& trim((string)($settings['squareLocationId'] ?? '')) !== '' && trim((string)($settings['squareAccessToken'] ?? '')) !== '';
	}
	public function createPayment($order, $sourceId){
		$sourceId = trim((string)$sourceId);
		if(!preg_match('/^[A-Za-z0-9:_-]{8,255}$/', $sourceId)){ throw new StoreCardException('Square returned an invalid one-time payment token.'); }
		$payload = $this->request('POST', '/v2/payments', array('source_id'=>$sourceId,
			'idempotency_key'=>(string)$order['reference'], 'amount_money'=>array('amount'=>(int)$order['totalCents'],'currency'=>(string)$order['currency']),
			'autocomplete'=>true, 'location_id'=>$this->locationId, 'reference_id'=>(string)$order['reference'], 'note'=>'Store order '.(string)$order['reference']));
		return $this->normalise($payload['payment'] ?? array());
	}
	public function getPayment($id){
		if(!preg_match('/^[A-Za-z0-9_-]{8,100}$/', (string)$id)){ throw new StoreCardException('That Square payment reference is not valid.'); }
		$payload = $this->request('GET', '/v2/payments/'.rawurlencode($id), null);
		return $this->normalise($payload['payment'] ?? array());
	}
	private function normalise($p){
		$id=(string)($p['id'] ?? ''); $status=strtoupper((string)($p['status'] ?? ''));
		if(!preg_match('/^[A-Za-z0-9_-]{8,100}$/', $id) || !preg_match('/^[A-Z_]{2,32}$/', $status)){ throw new StoreCardException('Square returned invalid payment details.'); }
		return array('id'=>$id,'status'=>$status,'amount'=>(int)($p['amount_money']['amount'] ?? -1),
			'currency'=>strtoupper((string)($p['amount_money']['currency'] ?? '')),
			'reference'=>(string)($p['reference_id'] ?? ''),'locationId'=>(string)($p['location_id'] ?? ''));
	}
	private function request($method,$path,$body){
		if(preg_match('/[\r\n]/', $this->accessToken)){ throw new StoreCardException('The Square access token is not configured correctly.'); }
		$request=array('method'=>$method,'url'=>$this->base.$path,'headers'=>array('Authorization: Bearer '.$this->accessToken,
			'Content-Type: application/json','Square-Version: 2026-09-16'));
		if($body!==null){ $request['body']=json_encode($body); }
		return $this->send($request,'Square');
	}
}
?>
