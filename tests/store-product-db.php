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
	'badge'=>'New','deliveryNote'=>'Made to order');
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
	$product['externalUrl'],1,3200,'New','Made to order'));
$row = $db->getProduct(7);
foreach($product as $key => $value){ productDbCheck($row[$key] === $value, 'Wrong persisted product field '.$key); }
productDbCheck($row['productId'] === 7 && $row['createdAt'] === 123, 'Product identity mapping changed');
$db->getProducts('apparel');
list($sql, $params) = end($db->calls);
productDbCheck(strpos($sql, 'active = 1') !== false && $params === array('apparel'), 'Public catalogue does not restrict active category');
productDbCheck(strpos($sql, 'featured desc, sortOrder asc, name asc') !== false, 'Featured product ordering missing');
echo "PASS: $checks product persistence assertions; SQL bindings verified without a database\n";
