<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
require __DIR__.'/store.php';
$before = $checks;
$db = new StoreDbFake();
$_SESSION['storeObj']->storedb = $db;
$_SESSION['storeCart'] = array(1 => 2);
$db->products[1]['compareAtCents'] = 1500;
storeCheck(storeCartTotals()['saleSavingsCents'] === 500, 'Cart reports sale savings without subtracting twice');
storeTestSignOut();
$input = array('freeShipping' => '25.00', 'pointsPerUnit' => 2, 'loyaltyThreshold' => 100, 'loyaltyPercent' => 15,
	'coupons' => array(array('code' => 'save10', 'type' => 'percent', 'value' => 10, 'minimum' => '20.00', 'start' => '2026-01-01', 'end' => '2026-12-31', 'active' => 1)));
$config = storeValidateCommerce($input);
storeCheck($config['coupons'][0]['code'] === 'SAVE10', 'Codes normalize to uppercase');
$price = function($subtotal, $code = '', $points = 0, $signed = false, $date = '2026-09-23') use ($config){ return storeCalculatePromotions($subtotal, 995, $config, $code, $points, $signed, $date); };
$t = $price(2500, 'SAVE10');
storeCheck($t['discountCents'] === 250 && $t['totalCents'] === 3245 && $t['freeShippingRemaining'] === 250, 'Discount precedes shipping threshold');
storeCheck($t['loyaltyPoints'] === 0, 'Guests do not earn points');
storeCheck($price(2500)['shippingCents'] === 0, 'Threshold equality unlocks shipping');
storeCheck($price(1999, 'SAVE10')['couponError'] !== '', 'Minimum spend enforced');
storeCheck($price(2500, 'SAVE10', 0, false, '2027-01-01')['couponError'] !== '', 'Expired code rejected');
storeCheck($price(2500, 'SAVE10', 0, false, '2025-12-31')['couponError'] !== '', 'Future code rejected');
storeCheck($price(2500, 'SAVE10', 0, false, '2026-12-31')['discountCents'] === 250, 'End date inclusive');
$t = $price(2500, 'SAVE10', 100, true);
storeCheck($t['discountCents'] === 375 && $t['discountLabel'] === 'Member reward', 'Better member offer wins without stacking');
storeCheck($t['loyaltyPoints'] === 42, 'Points rounded down after discount and exclude shipping');
storeCheck($price(2500, '', 99, true)['discountCents'] === 0, 'Member threshold enforced');
storeCheck($price(2500, '', 100, false)['discountCents'] === 0, 'Unsigned visitor cannot claim member benefit');
$fixed = $config;
$fixed['coupons'][0]['type'] = 'fixed'; $fixed['coupons'][0]['value'] = 99999;
$t = storeCalculatePromotions(2500, 0, $fixed, 'SAVE10', 0, true, '2026-09-23');
storeCheck($t['discountCents'] === 2499 && $t['totalCents'] === 1, 'Oversized fixed discount never makes zero or negative checkout');
$fixed['coupons'][0]['active'] = 0;
storeCheck(storeCalculatePromotions(2500, 0, $fixed, 'SAVE10', 0, false, '2026-09-23')['discountCents'] === 0, 'Disabled code rejected');
foreach(array('2026-02-30', 'tomorrow', '2026-1-01') as $date){
	$rejected = false;
	try{ storePromotionDate($date); }catch(Exception $e){ $rejected = true; }
	storeCheck($rejected, 'Invalid date rejected');
}
$duplicate = $input; $duplicate['coupons'][] = $input['coupons'][0];
$rejected = false;
try{ storeValidateCommerce($duplicate); }catch(Exception $e){ $rejected = true; }
storeCheck($rejected, 'Duplicate code rejected');
storeCheck(saveStorePromotions($input) !== true, 'Signed-out visitor cannot change promotions');
storeTestSignIn(true);
storeCheck(saveStorePromotions($input) === true && $db->settings['commerceConfig'] === $config, 'Admin saves promotion rules');
storeCheck(strpos(showStoreManager('promotions'), 'storeCouponTemplate') !== false, 'Promotion editor rendered');
$db->settings['commerceConfig']['coupons'][0]['start'] = '';
$db->settings['commerceConfig']['coupons'][0]['end'] = '';
storeShowCart('save10');
storeCheck($_SESSION['storeCoupon'] === 'SAVE10' && storeCartTotals()['discountCents'] === 250, 'Cart code applied server-side');
storeCheck(strpos(storeShowCart(), 'SAVE10') !== false, 'Cart preserves selected code');
storeShowCart('');
storeCheck(storeCartTotals()['discountCents'] === 0, 'Clear removes discount');

// Exercise real checkout construction and PayPal breakdown, without a network.
$sent = array();
$GLOBALS['storePaypalTransport'] = function($request) use (&$sent){
	if(strpos($request['url'], '/oauth2/token') !== false){ return array('status'=>200, 'body'=>'{"access_token":"fixture-token"}'); }
	if(strpos($request['url'], '/capture') !== false){ return array('status'=>201, 'body'=>json_encode(array('status'=>'COMPLETED', 'purchase_units'=>array(array('payments'=>array('captures'=>array(array('id'=>'CAP-PROMO', 'amount'=>array('value'=>'32.45', 'currency_code'=>'CAD'))))))))); }
	$sent = json_decode($request['body'], true);
	return array('status'=>201, 'body'=>'{"id":"PROMO-ORDER"}');
};
$_SESSION['storeCoupon'] = 'SAVE10';
$result = storeBeginCheckout(array('name'=>'Member', 'email'=>'member@example.test', 'address1'=>'1 Main', 'city'=>'Town', 'postcode'=>'A1A1A1', 'country'=>'CA', 'totalCents'=>1, 'discountCents'=>99999, 'loyaltyPoints'=>999999));
storeCheck($result['ok'], 'Discounted checkout starts');
$order = $db->orders[1];
storeCheck($order['totalCents'] === 3245 && $order['discountCents'] === 250 && $order['loyaltyPoints'] === 45, 'Order snapshots trusted totals and points, ignores client amounts');
$amount = $sent['purchase_units'][0]['amount'];
storeCheck($amount['breakdown']['discount']['value'] === '2.50' && $amount['value'] === '32.45', 'PayPal receives matching discount breakdown');
storeCheck($db->getLoyaltyPoints(1, 'CAD') === 0, 'Pending purchase earns nothing');
$db->settings['commerceConfig']['pointsPerUnit'] = 100;
storeCheck(storeCaptureOrder('PROMO-ORDER')['ok'], 'Discounted capture verified');
storeCheck($db->getLoyaltyPoints(1, 'CAD') === 45, 'Paid purchase awards snapshotted points');
storeCaptureOrder('PROMO-ORDER');
storeCheck($db->getLoyaltyPoints(1, 'CAD') === 45, 'Capture replay cannot duplicate points');
storeCheck($db->getLoyaltyPoints(2, 'CAD') === 0 && $db->getLoyaltyPoints(1, 'USD') === 0, 'Points isolated by account and currency');
storeCheck(!isset($_SESSION['storeCoupon']), 'Paid checkout clears coupon');
storeCheck(strpos(storeUi()->renderReceipt($db->orders[1], $db->items[1], array()), 'SAVE10') !== false, 'Receipt records discount');
storeCheck(strpos(storeUi()->renderOrderDetail($db->orders[1], $db->items[1], array()), 'record external refund') !== false, 'Paid order offers cancellation action');
$db->setOrderStatus(1, 'cancelled');
storeCheck($db->getLoyaltyPoints(1, 'CAD') === 0, 'Cancelled purchase removes points');
$_SESSION['storeCart'] = array(1 => 1); $_SESSION['storeCoupon'] = 'BAD';
storeCheck(!storeBeginCheckout(array())['ok'], 'Unavailable coupon blocks checkout for review');
echo 'PASS: '.($checks - $before)." promotion assertions; no network or database\n";
