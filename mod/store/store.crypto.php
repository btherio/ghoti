<?php
/*
 * store.crypto.php - dependency-free NOWPayments client for one-time crypto
 * checkout. The browser never receives the API key and never decides whether a
 * payment is complete: this client reads the payment back from NOWPayments and
 * the async layer compares its fiat amount, currency and local order reference
 * with the saved order before marking it paid.
 *
 * The transport is injectable for tests, matching StorePaypalClient. Crypto
 * amounts are kept as decimal strings. In particular, the amount the buyer
 * must send is extracted from the raw JSON token so PHP cannot round a small
 * Bitcoin amount through a float before displaying it.
 */

class StoreCryptoException extends RuntimeException {}

class StoreCryptoClient{
	const API_BASE = 'https://api.nowpayments.io';

	private $apiKey;
	private $transport;

	public function __construct($settings, callable $transport = null){
		$this->apiKey = trim((string)($settings['cryptoApiKey'] ?? ''));
		$this->transport = $transport ?: array($this, 'curlTransport');
	}

	public static function configured($settings){
		return !empty($settings['cryptoEnabled'])
			&& trim((string)($settings['cryptoApiKey'] ?? '')) !== ''
			&& count(self::currencyList($settings['cryptoCurrencies'] ?? '')) > 0;
	}

	public static function currencyList($value){
		$values = is_array($value) ? $value : preg_split('/[\s,]+/', strtolower((string)$value), -1, PREG_SPLIT_NO_EMPTY);
		$out = array();
		foreach($values as $value){
			$value = strtolower(trim((string)$value));
			//NOWPayments also uses network-specific codes such as usdttrc20.
			if(preg_match('/^[a-z0-9_-]{2,24}$/', $value) && !in_array($value, $out, true)){ $out[] = $value; }
		}
		return array_slice($out, 0, 50);
	}

	public function createPayment($order, $payCurrency){
		//NOWPayments documents price_amount as a JSON number. Build just this
		//small wire object explicitly so the exact cents string is not converted
		//through a binary float merely to remove JSON quotes.
		$body = '{"price_amount":'.StorePaypalClient::amount((int)$order['totalCents'])
			.',"price_currency":'.json_encode(strtolower((string)$order['currency']))
			.',"pay_currency":'.json_encode(strtolower((string)$payCurrency))
			.',"order_id":'.json_encode((string)$order['reference'])
			.',"order_description":'.json_encode('Store order '.(string)$order['reference'])
			.',"is_fixed_rate":true}';
		$response = $this->request('POST', '/v1/payment', $body);
		return $this->normalisePayment($this->decode($response), (string)($response['body'] ?? ''));
	}

	public function getPayment($paymentId){
		$paymentId = trim((string)$paymentId);
		if(!preg_match('/^[A-Za-z0-9_-]{1,80}$/', $paymentId)){
			throw new StoreCryptoException('That crypto payment reference is not valid.');
		}
		$response = $this->request('GET', '/v1/payment/'.rawurlencode($paymentId), null);
		return $this->normalisePayment($this->decode($response), (string)($response['body'] ?? ''));
	}

	private function normalisePayment($payload, $raw){
		$id = (string)($payload['payment_id'] ?? '');
		if(!preg_match('/^[A-Za-z0-9_-]{1,80}$/', $id)){
			throw new StoreCryptoException('NOWPayments did not return a valid payment reference.');
		}
		$payAmount = $this->rawDecimal($raw, 'pay_amount');
		if($payAmount === ''){ $payAmount = $this->decimalString($payload['pay_amount'] ?? ''); }
		$priceAmount = $this->rawDecimal($raw, 'price_amount');
		if($priceAmount === ''){ $priceAmount = $this->decimalString($payload['price_amount'] ?? ''); }
		$expires = (string)($payload['expiration_estimate_date'] ?? ($payload['expiration_date'] ?? ''));
		$expiresAt = $expires === '' ? 0 : strtotime($expires);
		$status = strtolower((string)($payload['payment_status'] ?? 'waiting'));
		$payCurrency = strtolower((string)($payload['pay_currency'] ?? ''));
		$address = (string)($payload['pay_address'] ?? '');
		$extraId = (string)($payload['payin_extra_id'] ?? '');
		$network = (string)($payload['network'] ?? '');
		if(!preg_match('/^[a-z_]{1,24}$/', $status)
			|| !preg_match('/^[a-z0-9_-]{2,24}$/', $payCurrency)
			|| !preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $payAmount) || $address === ''
			|| strlen($payAmount) > 80 || strlen($address) > 255 || strlen($extraId) > 255 || strlen($network) > 40){
			throw new StoreCryptoException('NOWPayments returned payment details this app could not safely store.');
		}
		return array(
			'paymentId' => $id,
			'status' => $status,
			'priceCents' => $this->fiatCents($priceAmount),
			'priceCurrency' => strtoupper((string)($payload['price_currency'] ?? '')),
			'payCurrency' => $payCurrency,
			'payAmount' => $payAmount,
			'address' => $address,
			'extraId' => $extraId,
			'network' => $network,
			'orderId' => (string)($payload['order_id'] ?? ''),
			'expiresAt' => $expiresAt === false ? 0 : (int)$expiresAt,
		);
	}

	private function request($method, $path, $body){
		if($this->apiKey === '' || preg_match('/[\r\n]/', $this->apiKey)){
			throw new StoreCryptoException('The NOWPayments API key is not configured correctly.');
		}
		$request = array(
			'method' => $method,
			'url' => self::API_BASE.$path,
			'headers' => array('Content-Type: application/json', 'x-api-key: '.$this->apiKey),
		);
		if($body !== null){ $request['body'] = is_string($body) ? $body : json_encode($body); }
		$response = call_user_func($this->transport, $request);
		if(!is_array($response) || !isset($response['status'])){
			throw new StoreCryptoException('The crypto payment request could not be completed.');
		}
		return $response;
	}

	private function decode($response){
		$payload = json_decode((string)($response['body'] ?? ''), true, 512, JSON_BIGINT_AS_STRING);
		$status = (int)$response['status'];
		if($status < 200 || $status >= 300){
			$message = is_array($payload) ? (string)($payload['message'] ?? '') : '';
			throw new StoreCryptoException('NOWPayments refused the request (HTTP '.$status.($message !== '' ? ': '.$message : '').').');
		}
		if(!is_array($payload)){ throw new StoreCryptoException('NOWPayments returned a response this app could not read.'); }
		return $payload;
	}

	private function rawDecimal($json, $field){
		if(preg_match('/"'.preg_quote($field, '/').'"\s*:\s*(?:"([0-9]+(?:\.[0-9]+)?)"|([0-9]+(?:\.[0-9]+)?))/', $json, $m)){
			return ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');
		}
		return '';
	}

	private function decimalString($value){
		if(is_float($value)){
			$value = rtrim(rtrim(number_format($value, 18, '.', ''), '0'), '.');
		}else{ $value = trim((string)$value); }
		return preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $value) ? $value : '';
	}

	//NOWPayments may return a fiat value with harmless trailing zeroes. Keep the
	//comparison exact while accepting "12.34000000" as the same 1,234 cents.
	private function fiatCents($value){
		if(!preg_match('/^(\d+)(?:\.(\d*))?$/', (string)$value, $m)){ return null; }
		$fraction = $m[2] ?? '';
		if(strlen($fraction) > 2 && trim(substr($fraction, 2), '0') !== ''){ return null; }
		return ((int)$m[1] * 100) + (int)str_pad(substr($fraction, 0, 2), 2, '0', STR_PAD_RIGHT);
	}

	private function curlTransport($request){
		if(!function_exists('curl_init')){ throw new StoreCryptoException('PHP cURL is not available, so this site cannot reach NOWPayments.'); }
		$handle = curl_init();
		curl_setopt_array($handle, array(
			CURLOPT_URL => $request['url'],
			CURLOPT_CUSTOMREQUEST => $request['method'],
			CURLOPT_POSTFIELDS => $request['body'] ?? null,
			CURLOPT_HTTPHEADER => $request['headers'] ?? array(),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 30,
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
			ghoti::logError('store.crypto.php:curlTransport', 'NOWPayments request failed: '.$error);
			throw new StoreCryptoException('This site could not reach the crypto payment provider. Try again in a moment.');
		}
		return array('status' => $status, 'body' => (string)$body);
	}
}
?>
