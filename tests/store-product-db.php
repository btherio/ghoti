<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
require __DIR__.'/../ghoti.db.php';
require __DIR__.'/../mod/store/store.db.php';
class StoreProductDbSpy extends storedb{
	public $calls = array();
	public $rows = array();
	public function __construct(){}
	public function __destruct(){}
	protected function query($sql, array $params = array()){
		if(substr_count($sql, '?') !== count($params)){ throw new RuntimeException('SQL placeholder count mismatch'); }
		$this->calls[] = array($sql, $params);
		return true;
	}
	protected function queryArray($sql, array $params = array()){
		$this->query($sql, $params);
		return $this->rows;
	}
}
$checks = 0;
function productDbCheck($ok, $label){ global $checks; if(!$ok){ throw new RuntimeException($label); } $checks++; }
$db = new StoreProductDbSpy();
$product = array('sku'=>'TEE','name'=>'Spring tee','description'=>'Cotton','priceCents'=>2500,
	'kind'=>'physical','category'=>'apparel','imageUrl'=>'files/tee.jpg','downloadPath'=>'','active'=>true,
	'sortOrder'=>3,'fulfilment'=>'spring','dropProvider'=>'spring','dropProductId'=>'','dropVariantId'=>'',
	'externalUrl'=>'https://studio.creator-spring.com/listing/tee','featured'=>true,'compareAtCents'=>3200,
	'badge'=>'New','deliveryNote'=>'Made to order','serviceTerm'=>'','servicePrompt'=>'','serviceRequired'=>false,
	'billingType'=>'one_time','paypalPlanId'=>'');
productDbCheck($db->addProduct($product), 'Product insert failed');
list($sql, $params) = $db->calls[0];
preg_match('/store_products \(([^)]+)\)/', $sql, $match);
$inserted = array_combine(explode(',', $match[1]), $params);
foreach($product as $key => $value){ productDbCheck($inserted[$key] === (is_bool($value) ? (int)$value : $value), 'Wrong insert value for '.$key); }
productDbCheck($db->updateProduct(7, $product), 'Product update failed');
list($sql, $params) = $db->calls[1];
preg_match('/set (.+) where productId=\?/', $sql, $match);
$columns = array_map(function($assignment){ return substr($assignment, 0, -2); }, explode(',', $match[1]));
productDbCheck(array_pop($params) === 7, 'Update does not target product ID');
$updated = array_combine($columns, $params);
foreach($product as $key => $value){ productDbCheck($updated[$key] === (is_bool($value) ? (int)$value : $value), 'Wrong update value for '.$key); }
$db->rows = array(array(7,'TEE','Spring tee','Cotton',2500,'physical','apparel','files/tee.jpg','',1,3,'spring','spring','','',123,
	$product['externalUrl'],1,3200,'New','Made to order','','',0,'one_time',''));
$row = $db->getProduct(7);
foreach($product as $key => $value){ productDbCheck($row[$key] === $value, 'Wrong persisted product field '.$key); }
productDbCheck($row['productId'] === 7 && $row['createdAt'] === 123, 'Product identity mapping changed');
$db->getProducts('apparel');
list($sql, $params) = end($db->calls);
productDbCheck(strpos($sql, 'active = 1') !== false && $params === array('apparel'), 'Public catalogue does not restrict active category');
productDbCheck(strpos($sql, 'featured desc, sortOrder asc, name asc') !== false, 'Featured product ordering missing');
echo "PASS: $checks product persistence assertions; SQL bindings verified without a database\n";

// Settings and order snapshots must preserve SQL binding order after upgrades.
$db->rows = array();
productDbCheck($db->saveSettings(array('commerceConfig'=>array('pointsPerUnit'=>2))), 'Commerce settings save failed');
list($sql, $params) = end($db->calls);
productDbCheck(json_decode($params[12], true) === array('pointsPerUnit'=>2), 'Commerce settings binding missing');
$db->rows = array(array('client','secret','sandbox','CAD',0,'',72,5,0,1,'{}',123,'{"pointsPerUnit":2}',1,'crypto-key','btc,eth',1,'pk_test_example','sk_test_example',1,'sq-app','sq-loc','sq-token','sandbox'));
productDbCheck($db->getSettings()['commerceConfig']['pointsPerUnit'] === 2, 'Commerce settings not decoded');
productDbCheck($db->getSettings()['cryptoEnabled'] && $db->getSettings()['cryptoCurrencies'] === 'btc,eth', 'Crypto settings not decoded');
$db->rows = array(array(73));
productDbCheck($db->getLoyaltyPoints(42, 'CAD') === 73, 'Loyalty aggregate mapping');
list($sql, $params) = end($db->calls);
productDbCheck($params === array(42, 'CAD') && strpos($sql, "status in ('paid','shipped')") !== false, 'Loyalty query must isolate account, currency and paid status');
class StoreOrderTransactionSpy {
	public $committed = false;
	public function beginTransaction(){}
	public function lastInsertId(){ return '7'; }
	public function commit(){ $this->committed = true; }
	public function rollBack(){}
}
class StoreOrderDbSpy extends StoreProductDbSpy {
	public $transaction;
	public function __construct(){ $this->transaction = new StoreOrderTransactionSpy(); }
	protected function db(){ return $this->transaction; }
}
$orderDb = new StoreOrderDbSpy();
$order = array('reference'=>'GH-TEST','userId'=>42,'email'=>'test@example.test','customerName'=>'Test','address1'=>'','address2'=>'','city'=>'','region'=>'','postcode'=>'','country'=>'','subtotalCents'=>1000,'shippingCents'=>0,'totalCents'=>900,'currency'=>'CAD','hasPhysical'=>false,'hasService'=>true,'paypalOrderId'=>'TEST','note'=>'','discountCents'=>100,'discountLabel'=>'SAVE10','loyaltyPoints'=>18);
productDbCheck($orderDb->createOrder($order, array()) === 7 && $orderDb->transaction->committed, 'Order snapshot transaction failed');
list($sql, $params) = $orderDb->calls[0];
preg_match('/store_orders \(([^)]+)\)/', $sql, $match);
$columns = array_values(array_filter(explode(',', $match[1]), function($column){ return $column !== 'status'; }));
$snapshot = array_combine($columns, $params);
foreach(array('discountCents', 'discountLabel', 'loyaltyPoints', 'totalCents') as $field){ productDbCheck($snapshot[$field] === $order[$field], 'Order binding mismatch: '.$field); }
$stored = array();
foreach(explode(',', 'orderId,reference,status,userId,email,customerName,address1,address2,city,region,postcode,country,subtotalCents,shippingCents,totalCents,currency,hasPhysical,hasService,serviceStatus,serviceFulfilledAt,paypalOrderId,paypalCaptureId,payerEmail,note,createdAt,paidAt,shippedAt,discountCents,discountLabel,loyaltyPoints,paymentProvider,cryptoPaymentId,cryptoStatus,cryptoCurrency,cryptoAmount,cryptoAddress,cryptoExtraId,cryptoNetwork,cryptoExpiresAt,cryptoUpdatedAt,providerPaymentId,providerStatus') as $field){
	$stored[] = $snapshot[$field] ?? ($field === 'status' ? 'paid' : ($field === 'serviceStatus' ? 'pending' : 0));
}
$orderDb->rows = array($stored);
$loaded = $orderDb->getOrder(7);
foreach(array('discountCents', 'discountLabel', 'loyaltyPoints', 'totalCents') as $field){ productDbCheck($loaded[$field] === $order[$field], 'Order read mismatch: '.$field); }
productDbCheck($loaded['hasService'] && $loaded['serviceStatus'] === 'pending', 'Service order state was not mapped');

$subscription = array('paypalSubscriptionId'=>'I-TEST','paypalPlanId'=>'P-1234567890','productId'=>9,'userId'=>42,
	'sku'=>'HOST','name'=>'Web hosting','priceCents'=>1200,'currency'=>'CAD','serviceTerm'=>'per month',
	'customerName'=>'Test','email'=>'test@example.test','serviceDetails'=>'example.test','status'=>'ACTIVE','nextBillingAt'=>1700000000);
productDbCheck($orderDb->addSubscription($subscription) === 7, 'Subscription insert failed');
list($sql, $params) = end($orderDb->calls);
productDbCheck(substr_count($sql, '?') === count($params), 'Subscription binding count mismatch');
$orderDb->rows = array(array(7,'I-TEST','P-1234567890',9,42,'HOST','Web hosting',1200,'CAD','per month','Test','test@example.test','example.test','ACTIVE',1700000000,'pending',0,1690000000,1690000000));
$loadedSubscription = $orderDb->getSubscription(7);
productDbCheck($loadedSubscription['paypalSubscriptionId'] === 'I-TEST' && $loadedSubscription['status'] === 'ACTIVE', 'Subscription read mapping failed');
echo "PASS: $checks total product, promotion and order persistence assertions\n";
