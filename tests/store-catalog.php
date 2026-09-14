<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
require __DIR__.'/store.php';
$checks = 0;
storeTestSignIn(true);
$db = new StoreDbFake();
$_SESSION['storeObj']->storedb = $db;
$_SESSION['storeCart'] = array();
foreach(array(
	'https://my-shop.creator-spring.com/listing/cotton-tee?product=46',
	'https://teespring.com/stores/my-shop', 'https://www.spri.ng/example',
	'https://my-shop.creator-spring.com/listing/tee?name=a%20b'
) as $url){ storeCheck(storeSpringUrl($url) === $url, 'Valid Spring URL rejected: '.$url); }
foreach(array('', 'javascript:alert(1)', 'http://teespring.com/x', '//teespring.com/x',
	'https://teespring.com.evil.test/x', 'https://evilteespring.com/x', 'https://teespring.com@evil.test',
	'https://evil.test@teespring.com/x', 'https://teespring.com:443/x', 'https://127.0.0.1/x',
	"https://teespring.com/\nx", 'https://teespring.com\\@evil.test/x', array('https://teespring.com/x'),
	'https://teespring.com/'.str_repeat('a', 2048)
) as $bad){ storeCheck(storeSpringUrl($bad) === '', 'Unsafe Spring URL accepted'); }

$product = array('name' => 'Studio tee', 'sku' => 'SPRING-TEE', 'price' => '28.00',
	'category' => 'merch', 'kind' => 'physical', 'fulfilment' => 'spring', 'active' => 1,
	'externalUrl' => 'https://studio.creator-spring.com/listing/studio-tee',
	'featured' => 1, 'compareAtPrice' => '35.00', 'badge' => 'New arrival',
	'deliveryNote' => 'Printed to order', 'description' => 'Soft cotton. Choose your size on Spring.');
storeCheck(saveStoreProduct($product) === true, 'Spring listing could not be saved with only URL mapping');
$id = max(array_keys($db->products));
$saved = $db->products[$id];
storeCheck($saved['fulfilment'] === 'spring' && $saved['dropProvider'] === 'spring', 'Spring route not persisted');
storeCheck($saved['externalUrl'] === $product['externalUrl'], 'Spring URL not persisted');
storeCheck($saved['featured'] && $saved['compareAtCents'] === 3500 && $saved['deliveryNote'] === 'Printed to order', 'Merchandising fields not saved');
storeCheck($saved['dropProductId'] === '' && $saved['dropVariantId'] === '', 'Spring requires no supplier IDs');
storeCheck(storeAddToCart($id)['ok'] === false && !storeCart(), 'Spring item accepted by local cart');
storeSetCartQuantity($id, 2);
storeCheck(storeCartTotals()['totalCents'] === 0, 'Forged quantity bypassed Spring checkout boundary');
$_SESSION['storeCart'] = array($id => 2, 1 => 1);
storeCheck(count(storeCartLines()) === 1 && storeCartTotals()['subtotalCents'] === 1250, 'Mixed stale cart charged for Spring goods');
storeCheck(storeDropshipGroups(array($saved)) === array(), 'Spring product entered API fulfilment queue');

foreach(array('20.00', '28.00', 'oops', '-1', '10000000000000000000') as $compare){
	$bad = array_merge($product, array('compareAtPrice' => $compare));
	storeCheck(saveStoreProduct($bad) !== true, 'Invalid original price accepted');
}
foreach(array('999999999999999999999999999999', '1000000.00') as $price){ storeCheck(storePriceToCents($price) === -1, 'Oversized price was not rejected'); }
storeCheck(storePriceToCents('999999.99') === 99999999, 'Maximum price rejected');
$bad = array_merge($product, array('externalUrl' => 'https://evil.test'));
storeCheck(saveStoreProduct($bad) !== true, 'Foreign hosted checkout saved');
$digital = array_merge($product, array('sku' => 'SPRING-DIGITAL', 'kind' => 'digital', 'compareAtPrice' => ''));
storeCheck(saveStoreProduct($digital) === true, 'Spring-hosted digital product required a local download');

$updated = array_merge($product, array('productId' => $id, 'fulfilment' => 'self', 'compareAtPrice' => ''));
storeCheck(saveStoreProduct($updated) === true && $db->products[$id]['externalUrl'] === '' && $db->products[$id]['dropProvider'] === '', 'Switching to local fulfilment retained Spring mapping');
storeCheck($db->products[$id]['compareAtCents'] === 0, 'Blank original price did not clear sale');

$ui = storeUi();
$html = $ui->renderStorefront(array($saved));
$doc = new DOMDocument();
@$doc->loadHTML('<?xml encoding="UTF-8">'.$html);
$xpath = new DOMXPath($doc);
storeCheck($xpath->query('//a[@href="'.$product['externalUrl'].'"]')->length === 1, 'Spring button does not link to item');
storeCheck($xpath->query('//button[contains(@onclick,"storeAddToCart")]')->length === 0, 'Spring card exposes local add-to-cart');
storeCheck($xpath->query('//a[@target="_blank" and @rel="noopener noreferrer"]')->length === 1, 'Spring external link lacks isolation');
storeCheck(strpos($html, 'Options, final price') !== false, 'External checkout not explained');
storeCheck(strpos($html, 'CAD 35.00') !== false && strpos($html, 'CAD 28.00') !== false, 'Sale prices missing');
$saved['externalUrl'] = 'javascript:alert(1)';
$saved['badge'] = '<script>alert(2)</script>';
$html = $ui->renderStorefront(array($saved));
storeCheck(strpos($html, 'javascript:') === false && strpos($html, '<script>') === false, 'Imported malicious data rendered as executable content');
storeCheck(strpos($html, 'Currently unavailable') !== false, 'Broken Spring mapping is not unavailable');
$html = $ui->renderStorefront(array($db->products[1]), 'all', true).$ui->renderStorefront(array($db->products[1]), 'all', true);
@$doc->loadHTML($html);
$ids = array();
foreach((new DOMXPath($doc))->query('//*[@id]') as $node){ $ids[] = $node->getAttribute('id'); }
storeCheck(count($ids) === count(array_unique($ids)), 'Multiple shortcodes produce duplicate IDs');

$db->products = array();
$method = new ReflectionMethod(storeui::class, 'renderProductAdmin');
$html = $method->invoke($ui, $db->settings);
storeCheck(strpos($html, 'ghotiStoreProviders') !== false && strpos($html, 'Printful') !== false, 'First product has no supplier registry');
storeTestSignOut();
storeCheck(saveStoreProduct($product) !== true, 'Signed-out caller saved Spring listing');
echo "PASS: $checks catalogue assertions; no database or network\n";
