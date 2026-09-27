<?php
/*
 * store.async.php - store module async layer: endpoints + class storeui.
 *
 * Same shape as every other module's async file - callable functions registered
 * with ghoti_async_register(), and the renderer class beside them - with two
 * rules this module cannot bend:
 *
 *  1. A price is never accepted from the browser. The cart holds product ids and
 *     quantities; every amount is looked up from store_products and totalled
 *     server-side (storeCartLines / storeCartTotals below). A checkout that
 *     trusted a posted total would let anyone buy anything for a cent.
 *
 *  2. The order row is written before PayPal capture or before crypto funds are
 *     accepted as paid. It is flipped only after the provider's amount,
 *     currency and reference are verified against it.
 *
 * The storefront is public; management is admin-gated. Money is integer cents
 * everywhere, formatted for display exactly once, in storeui::money().
 */

/* ---------------------------------------------------------------- *
 *  Shared helpers
 * ---------------------------------------------------------------- */

require_once __DIR__.'/store.promotions.php';

function storeDb(){
	if(!isset($_SESSION['storeObj'])){ throw new RuntimeException('The store module is not loaded.'); }
	return $_SESSION['storeObj']->storedb;
}

function storeUi(){
	if(!isset($_SESSION['storeObj'])){ throw new RuntimeException('The store module is not loaded.'); }
	return $_SESSION['storeObj']->storeui;
}

function storeRequireAdmin(){
	if(!ghoti_require_admin()){
		ghoti::logWarn("store.async.php:storeRequireAdmin", "Unauthorized store admin access attempt from ".ghoti_remote_addr());
		return false;
	}
	return true;
}

//The cart is per-visitor session state: product id => quantity, scalars only,
//so it survives ghoti_free_request_objects() and never holds a stale price.
function storeCart(){
	if(!isset($_SESSION['storeCart']) || !is_array($_SESSION['storeCart'])){
		$_SESSION['storeCart'] = array();
	}
	return $_SESSION['storeCart'];
}

const STORE_MAX_LINES = 20;    //distinct products in one cart
const STORE_MAX_QTY   = 99;    //units of any one product

/*
 * Turn the cart into priced lines. Products that have been deleted or
 * deactivated since they were added are dropped here rather than at checkout,
 * so the buyer sees the change while they can still act on it.
 */
function storeCartLines(){
	$cart = storeCart();
	if(!$cart){ return array(); }
	$products = storeDb()->getProductsById(array_keys($cart));
	$lines = array();
	foreach($cart as $productId => $quantity){
		$productId = (int)$productId;
		if(!isset($products[$productId]) || !$products[$productId]['active']){ continue; }
		$product = $products[$productId];
		if(($product['fulfilment'] ?? 'self') === 'spring'){ continue; }
		if(($product['billingType'] ?? 'one_time') === 'subscription'){ continue; }
		$quantity = max(1, min(STORE_MAX_QTY, (int)$quantity));
		$lines[] = array(
			'productId' => $productId,
			'name'      => $product['name'],
			'sku'       => $product['sku'],
			'kind'      => $product['kind'],
			'imageUrl'  => $product['imageUrl'],
			'unitCents' => $product['priceCents'],
			'quantity'  => $quantity,
			'lineCents' => $product['priceCents'] * $quantity,
			'saleSavingsCents' => max(0, (int)($product['compareAtCents'] ?? 0) - $product['priceCents']) * $quantity,
			'serviceTerm' => (string)($product['serviceTerm'] ?? ''),
			'servicePrompt' => (string)($product['servicePrompt'] ?? ''),
			'serviceRequired' => !empty($product['serviceRequired']),
		);
	}
	return $lines;
}

function storeCartTotals($lines = null){
	$lines = $lines === null ? storeCartLines() : $lines;
	$settings = storeDb()->getSettings();
	$subtotal = 0;
	$saleSavings = 0;
	$hasPhysical = false;
	$hasService = false;
	$units = 0;
	foreach($lines as $line){
		$subtotal += $line['lineCents'];
		$saleSavings += $line['saleSavingsCents'] ?? 0;
		$units += $line['quantity'];
		if($line['kind'] === 'physical'){ $hasPhysical = true; }
		if($line['kind'] === 'service'){ $hasService = true; }
	}
	//Shipping is a flat rate per order, charged only when something has to be
	//posted. An all-digital cart never pays it.
	$shipping = $hasPhysical ? max(0, (int)$settings['shippingCents']) : 0;
	$config = array_merge(storeCommerceDefaults(), $settings['commerceConfig'] ?? array());
	$signedIn = isset($_SESSION['userId']) && ghoti_require_login();
	$points = $signedIn && $config['pointsPerUnit'] > 0 ? storeDb()->getLoyaltyPoints((int)$_SESSION['userId'], $settings['currency']) : 0;
	$promotion = storeCalculatePromotions($subtotal, $shipping, $config, $_SESSION['storeCoupon'] ?? '', $points, $signedIn, gmdate('Y-m-d'));
	return array_merge(array(
		'saleSavingsCents' => $saleSavings,
		'subtotalCents' => $subtotal,
		'shippingCents' => $shipping,
		'totalCents'    => $subtotal + $shipping,
		'currency'      => $settings['currency'],
		'hasPhysical'   => $hasPhysical,
		'hasService'    => $hasService,
		'units'         => $units,
		'lines'         => count($lines),
	), $promotion);
}

/*
 * The one place a PayPal client is constructed. $GLOBALS['storePaypalTransport']
 * is the transport seam - set it to a callable and every call made here goes
 * through that instead of cURL. Production never sets it; tests substitute a
 * mock so the capture path (the part that must not be wrong) can be exercised
 * without reaching PayPal, the same way GhotiAlertService takes a transport.
 */
function storePaypalClient($settings){
	$transport = isset($GLOBALS['storePaypalTransport']) && is_callable($GLOBALS['storePaypalTransport'])
		? $GLOBALS['storePaypalTransport'] : null;
	return new StorePaypalClient($settings, $transport);
}

function storeCryptoClient($settings){
	$transport = isset($GLOBALS['storeCryptoTransport']) && is_callable($GLOBALS['storeCryptoTransport'])
		? $GLOBALS['storeCryptoTransport'] : null;
	return new StoreCryptoClient($settings, $transport);
}

function storeStripeClient($settings){
	$transport = isset($GLOBALS['storeStripeTransport']) && is_callable($GLOBALS['storeStripeTransport']) ? $GLOBALS['storeStripeTransport'] : null;
	return new StoreStripeClient($settings, $transport);
}

function storeSquareClient($settings){
	$transport = isset($GLOBALS['storeSquareTransport']) && is_callable($GLOBALS['storeSquareTransport']) ? $GLOBALS['storeSquareTransport'] : null;
	return new StoreSquareClient($settings, $transport);
}

//A short, human-quotable order reference. Ambiguous characters are left out so
//it can be read down a phone line without "was that an O or a zero". Eight of
//them rather than six: the column is uniquely indexed, so a collision would fail
//the insert after PayPal had already created its order, leaving an orphan on
//PayPal's side and "could not be started" in front of the buyer.
function storeReference(){
	$alphabet = 'ACDEFGHJKLMNPQRTUVWXY34679';
	$out = '';
	for($i = 0; $i < 8; $i++){ $out .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
	return 'GH-'.$out;
}

/* ---------------------------------------------------------------- *
 *  Storefront endpoints (public)
 * ---------------------------------------------------------------- */

function showStore($category = 'all'){
	try{
		$category = ($category === 'all') ? 'all' : ghoti_validate()->linkGroup($category);
		$products = storeDb()->getProducts($category);
		return storeUi()->renderStorefront($products, $category);
	}catch (Throwable $e){
		ghoti::logException("store.async.php:showStore", $e);
		return "<h1>Store</h1><p>The store is unavailable right now.</p>";
	}
}

function storeShowCart($code = null){
	try{
		if($code !== null){
			try{ $_SESSION['storeCoupon'] = storeCouponCode($code); }
			catch(Exception $e){ return storeUi()->renderCart(storeCartLines(), storeCartTotals()).'<p role="alert">'.htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8').'</p>'; }
		}
		return storeUi()->renderCart(storeCartLines(), storeCartTotals());
	}catch (Throwable $e){
		ghoti::logException("store.async.php:storeShowCart", $e);
		return "<p>The cart is unavailable right now.</p>";
	}
}

function storeAddToCart($productId, $quantity = 1){
	try{
		$productId = ghoti_validate()->id($productId, "product id");
		$quantity = ghoti_validate()->intInRange($quantity, 1, STORE_MAX_QTY, "quantity");
	}catch (Exception $e){
		return array('ok' => false, 'error' => $e->getMessage());
	}
	$product = storeDb()->getProduct($productId);
	if(!$product || !$product['active']){
		return array('ok' => false, 'error' => 'That item is no longer available.');
	}
	if(($product['fulfilment'] ?? 'self') === 'spring'){
		return array('ok' => false, 'error' => 'Choose options and check out on Spring for this item.');
	}
	if(($product['billingType'] ?? 'one_time') === 'subscription'){
		return array('ok' => false, 'error' => 'Use Subscribe on this service instead of adding it to the cart.');
	}
	$cart = storeCart();
	if(!isset($cart[$productId]) && count($cart) >= STORE_MAX_LINES){
		return array('ok' => false, 'error' => 'The cart is full. Check out or remove something first.');
	}
	$cart[$productId] = min(STORE_MAX_QTY, (isset($cart[$productId]) ? (int)$cart[$productId] : 0) + $quantity);
	$_SESSION['storeCart'] = $cart;
	$totals = storeCartTotals();
	return array('ok' => true, 'units' => $totals['units'], 'name' => $product['name'], 'summary' => storeUi()->cartSummaryText($totals));
}

//Quantity 0 removes the line - one endpoint for "change" and "remove" so the
//two can never disagree about the cap.
function storeSetCartQuantity($productId, $quantity){
	try{
		$productId = ghoti_validate()->id($productId, "product id");
		$quantity = ghoti_validate()->intInRange($quantity, 0, STORE_MAX_QTY, "quantity");
	}catch (Exception $e){
		return array('ok' => false, 'error' => $e->getMessage());
	}
	$cart = storeCart();
	if($quantity === 0){ unset($cart[$productId]); }
	else { $cart[$productId] = $quantity; }
	$_SESSION['storeCart'] = $cart;
	$lines = storeCartLines();
	$totals = storeCartTotals($lines);
	return array('ok' => true, 'units' => $totals['units'], 'html' => storeUi()->renderCart($lines, $totals));
}

function storeShowCheckout(){
	try{
		$lines = storeCartLines();
		if(!$lines){ return storeUi()->renderCart($lines, storeCartTotals($lines)); }
		return storeUi()->renderCheckout($lines, storeCartTotals($lines), storeDb()->getSettings());
	}catch (Throwable $e){
		ghoti::logException("store.async.php:storeShowCheckout", $e);
		return "<p>Checkout is unavailable right now.</p>";
	}
}

//Build the immutable order snapshot shared by PayPal and crypto checkout. It is
//the only path from browser details to an order; totals always come from the
//server-side cart and catalogue.
function storeBuildCheckout($customer){
	if(!is_array($customer)){ throw new InvalidArgumentException('Enter your details before paying.'); }
	$settings = storeDb()->getSettings();
	$lines = storeCartLines();
	if(!$lines){ throw new InvalidArgumentException('Your cart is empty.'); }
	$totals = storeCartTotals($lines);
	if($totals['couponError'] !== ''){ throw new InvalidArgumentException($totals['couponError'].' Return to the cart to change or remove it.'); }
	if($totals['totalCents'] <= 0){ throw new InvalidArgumentException('This order has no payable total.'); }

	$v = ghoti_validate();
	$order = array(
		'reference' => storeReference(),
		'userId' => isset($_SESSION['userId']) && ghoti_require_login() ? (int)$_SESSION['userId'] : null,
		'email' => $v->email($customer['email'] ?? ''),
		'customerName' => $v->text($customer['name'] ?? '', 120, true, 'name'),
		'address1' => '', 'address2' => '', 'city' => '', 'region' => '', 'postcode' => '', 'country' => '',
		'discountCents' => $totals['discountCents'], 'discountLabel' => $totals['discountLabel'],
		'loyaltyPoints' => $totals['loyaltyPoints'], 'subtotalCents' => $totals['subtotalCents'],
		'shippingCents' => $totals['shippingCents'], 'totalCents' => $totals['totalCents'],
		'currency' => $totals['currency'], 'hasPhysical' => $totals['hasPhysical'], 'hasService' => $totals['hasService'],
		'paypalOrderId' => '', 'paymentProvider' => '',
		'note' => $v->text($customer['note'] ?? '', 500, false, 'order note'),
	);
	if($totals['hasPhysical']){
		$order['address1'] = $v->text($customer['address1'] ?? '', 190, true, 'address');
		$order['address2'] = $v->text($customer['address2'] ?? '', 190, false, 'address line 2');
		$order['city'] = $v->text($customer['city'] ?? '', 120, true, 'city');
		$order['region'] = $v->text($customer['region'] ?? '', 120, false, 'province or state');
		$order['postcode'] = $v->text($customer['postcode'] ?? '', 32, true, 'postal code');
		$country = strtoupper(trim((string)($customer['country'] ?? '')));
		if(!preg_match('/^[A-Z]{2}$/', $country)){ throw new InvalidArgumentException('Enter a two-letter country code, such as CA or US.'); }
		$order['country'] = $country;
	}

	$items = array();
	foreach($lines as $line){
		$serviceDetails = '';
		if($line['kind'] === 'service'){
			$posted = isset($customer['serviceDetails']) && is_array($customer['serviceDetails'])
				? ($customer['serviceDetails'][(string)$line['productId']] ?? '') : '';
			$serviceDetails = $v->multilineText($posted, 500, !empty($line['serviceRequired']), $line['servicePrompt'] !== '' ? $line['servicePrompt'] : 'service details');
		}
		$items[] = array('productId'=>$line['productId'], 'name'=>$line['name'], 'sku'=>$line['sku'],
			'kind'=>$line['kind'], 'unitCents'=>$line['unitCents'], 'quantity'=>$line['quantity'],
			'serviceTerm'=>$line['serviceTerm'], 'serviceDetails'=>$serviceDetails);
	}
	return array('settings'=>$settings, 'order'=>$order, 'items'=>$items);
}

/* Step one of PayPal payment. */
function storeBeginCheckout($customer){
	try{
		$checkout = storeBuildCheckout($customer);
		$settings = $checkout['settings'];
		$order = $checkout['order'];
		$items = $checkout['items'];
		if(!StorePaypalClient::configured($settings)){ return array('ok'=>false, 'error'=>'This store is not connected to PayPal yet.'); }

		$paypalOrderId = storePaypalClient($settings)->createOrder($order, $items);
		$order['paypalOrderId'] = $paypalOrderId;
		$order['paymentProvider'] = 'paypal';

		$orderId = storeDb()->createOrder($order, $items);
		if(!$orderId){
			//PayPal has an order this app could not record. Refusing here means
			//nothing is ever captured against it, and it expires on PayPal's side.
			ghoti::logError("store.async.php:storeBeginCheckout", "Could not record order for PayPal order ".$paypalOrderId);
			return array('ok' => false, 'error' => 'This order could not be started. Nothing has been charged.');
		}

		$_SESSION['storeOrderId'] = $orderId;
		ghoti::logInfo("store.async.php:storeBeginCheckout", "Order ".$order['reference']." pending (".$order['totalCents']." ".$order['currency'].")");
		return array('ok' => true, 'paypalOrderId' => $paypalOrderId, 'reference' => $order['reference']);
	}catch (StorePaypalException $e){
		ghoti::logError("store.async.php:storeBeginCheckout", "PayPal: ".$e->getMessage());
		return array('ok' => false, 'error' => $e->getMessage());
	}catch (Exception $e){
		return array('ok' => false, 'error' => $e->getMessage());
	}catch (Throwable $e){
		ghoti::logException("store.async.php:storeBeginCheckout", $e);
		return array('ok' => false, 'error' => 'Checkout could not be started.');
	}
}

/*
 * Step two: capture. Everything here is keyed on the PayPal order id recorded
 * at step one, so a replayed call finds the same row; markOrderPaid() only
 * succeeds while the order is still pending, which is what stops a second
 * receipt (and a second set of download links) from being issued.
 */
function storeCaptureOrder($paypalOrderId){
	$paypalOrderId = trim((string)$paypalOrderId);
	if($paypalOrderId === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $paypalOrderId)){
		return array('ok' => false, 'error' => 'That payment reference is not valid.');
	}
	try{
		$order = storeDb()->getOrderByPaypalId($paypalOrderId);
		if(!$order){
			ghoti::logWarn("store.async.php:storeCaptureOrder", "Capture attempted for unknown PayPal order from ".ghoti_remote_addr());
			return array('ok' => false, 'error' => 'That order could not be found.');
		}
		if(($order['paymentProvider'] ?? 'paypal') !== 'paypal'){
			return array('ok' => false, 'error' => 'That is not a PayPal order.');
		}
		if($order['status'] !== 'pending'){
			//Already captured: show the receipt again rather than charging twice.
			return array('ok' => true, 'html' => storeUi()->renderReceipt($order, storeDb()->getOrderItems($order['orderId']), storeDb()->getOrderDownloads($order['orderId'])));
		}

		$settings = storeDb()->getSettings();
		$client = storePaypalClient($settings);
		$capture = $client->captureOrder($paypalOrderId);

		//What PayPal took must match what this app recorded. A mismatch is not a
		//display problem: it means the amount was changed somewhere in between.
		if($capture['valueCents'] !== $order['totalCents'] || strtoupper($capture['currency']) !== strtoupper($order['currency'])){
			ghoti::logError("store.async.php:storeCaptureOrder",
				"Captured amount does not match order ".$order['reference']." (captured ".$capture['valueCents']." ".$capture['currency'].", expected ".$order['totalCents']." ".$order['currency'].")");
			storeDb()->setOrderStatus($order['orderId'], 'failed');
			return array('ok' => false, 'error' => 'The payment amount did not match this order. Contact us before trying again; reference '.$order['reference'].'.');
		}

		if(!storeDb()->markOrderPaid($order['orderId'], $capture['captureId'], $capture['payerEmail'])){
			//Another request won the race and already finished this order.
			$fresh = storeDb()->getOrder($order['orderId']);
			return array('ok' => true, 'html' => storeUi()->renderReceipt($fresh ?: $order, storeDb()->getOrderItems($order['orderId']), storeDb()->getOrderDownloads($order['orderId'])));
		}

		return storeFinalizePaidOrder($order['orderId'], $settings, true, 'storeCaptureOrder');
	}catch (StorePaypalException $e){
		ghoti::logError("store.async.php:storeCaptureOrder", "PayPal: ".$e->getMessage());
		return array('ok' => false, 'error' => $e->getMessage());
	}catch (Throwable $e){
		ghoti::logException("store.async.php:storeCaptureOrder", $e);
		return array('ok' => false, 'error' => 'The payment could not be completed. Nothing further has been charged.');
	}
}

//NOWPayments checkout supports Bitcoin and any other currency the merchant
//allows. It creates a pending local order and returns payment instructions; a
//later status read, never a browser claim, is what can mark the order paid.
function storeBeginCryptoCheckout($customer, $payCurrency){
	try{
		$checkout = storeBuildCheckout($customer);
		$settings = $checkout['settings'];
		$order = $checkout['order'];
		$items = $checkout['items'];
		if(!StoreCryptoClient::configured($settings)){ return array('ok'=>false, 'error'=>'Cryptocurrency checkout is not configured yet.'); }
		$payCurrency = strtolower(trim((string)$payCurrency));
		if(!in_array($payCurrency, StoreCryptoClient::currencyList($settings['cryptoCurrencies']), true)){
			return array('ok'=>false, 'error'=>'Choose one of the available cryptocurrencies.');
		}

		$payment = storeCryptoClient($settings)->createPayment($order, $payCurrency);
		if($payment['payAmount'] === '' || $payment['address'] === ''){
			return array('ok'=>false, 'error'=>'The crypto provider did not return complete payment instructions. Nothing has been recorded.');
		}
		if($payment['priceCents'] !== $order['totalCents']
			|| strtoupper($payment['priceCurrency']) !== strtoupper($order['currency'])
			|| !hash_equals($order['reference'], $payment['orderId'])
			|| !hash_equals($payCurrency, $payment['payCurrency'])){
			ghoti::logError('store.async.php:storeBeginCryptoCheckout', 'NOWPayments returned mismatched details for '.$order['reference']);
			return array('ok'=>false, 'error'=>'The crypto provider returned different order details. Nothing has been recorded; please try again.');
		}

		$order['paymentProvider'] = 'crypto';
		$order['cryptoPaymentId'] = $payment['paymentId'];
		$order['cryptoStatus'] = $payment['status'];
		$order['cryptoCurrency'] = $payment['payCurrency'];
		$order['cryptoAmount'] = $payment['payAmount'];
		$order['cryptoAddress'] = $payment['address'];
		$order['cryptoExtraId'] = $payment['extraId'];
		$order['cryptoNetwork'] = $payment['network'];
		$order['cryptoExpiresAt'] = $payment['expiresAt'];
		$order['cryptoUpdatedAt'] = time();
		//paypalOrderId has a unique index from older schemas and cannot be empty
		//for more than one crypto order. The provider-specific id remains separate.
		$order['paypalOrderId'] = 'CRYPTO-'.substr(hash('sha256', $payment['paymentId']), 0, 56);
		$orderId = storeDb()->createOrder($order, $items);
		if(!$orderId){
			ghoti::logError('store.async.php:storeBeginCryptoCheckout', 'Could not record crypto payment '.$payment['paymentId']);
			return array('ok'=>false, 'error'=>'This payment could not be recorded. Do not send cryptocurrency to it; start again.');
		}
		$_SESSION['storeOrderId'] = $orderId;
		$_SESSION['storeCryptoOrderId'] = $orderId;
		$saved = storeDb()->getOrder($orderId);
		ghoti::logInfo('store.async.php:storeBeginCryptoCheckout', 'Order '.$order['reference'].' awaiting '.$payCurrency.' payment');
		return array('ok'=>true, 'orderId'=>$orderId, 'final'=>false, 'html'=>storeUi()->renderCryptoPayment($saved ?: array_merge($order, array('orderId'=>$orderId,'status'=>'pending'))));
	}catch(StoreCryptoException $e){
		ghoti::logError('store.async.php:storeBeginCryptoCheckout', 'NOWPayments: '.$e->getMessage());
		return array('ok'=>false, 'error'=>$e->getMessage());
	}catch(Exception $e){
		return array('ok'=>false, 'error'=>$e->getMessage());
	}catch(Throwable $e){
		ghoti::logException('store.async.php:storeBeginCryptoCheckout', $e);
		return array('ok'=>false, 'error'=>'Crypto checkout could not be started.');
	}
}

function storeRefreshCryptoPayment($orderId){
	try{ $orderId = ghoti_validate()->id($orderId, 'order id'); }
	catch(Exception $e){ return array('ok'=>false, 'error'=>$e->getMessage()); }
	$buyerOwns = isset($_SESSION['storeCryptoOrderId']) && (int)$_SESSION['storeCryptoOrderId'] === $orderId;
	$isAdmin = ghoti_require_admin();
	if(!$buyerOwns && !$isAdmin){ return array('ok'=>false, 'error'=>'That crypto payment is not available in this session.'); }

	try{
		$order = storeDb()->getOrder($orderId);
		if(!$order || ($order['paymentProvider'] ?? '') !== 'crypto'){ return array('ok'=>false, 'error'=>'That crypto payment could not be found.'); }
		if(in_array($order['status'], array('paid','shipped'), true)){
			return array('ok'=>true, 'final'=>true, 'html'=>storeUi()->renderReceipt($order, storeDb()->getOrderItems($orderId), storeDb()->getOrderDownloads($orderId)));
		}
		if($order['status'] !== 'pending'){
			return array('ok'=>true, 'final'=>true, 'html'=>storeUi()->renderCryptoPayment($order));
		}

		$settings = storeDb()->getSettings();
		$payment = storeCryptoClient($settings)->getPayment($order['cryptoPaymentId']);
		if(!hash_equals($order['cryptoPaymentId'], $payment['paymentId'])
			|| !hash_equals($order['reference'], $payment['orderId'])
			|| $payment['priceCents'] !== $order['totalCents']
			|| strtoupper($payment['priceCurrency']) !== strtoupper($order['currency'])
			|| !hash_equals($order['cryptoCurrency'], $payment['payCurrency'])){
			ghoti::logError('store.async.php:storeRefreshCryptoPayment', 'Provider mismatch for '.$order['reference']);
			storeDb()->setOrderStatus($orderId, 'failed');
			return array('ok'=>false, 'error'=>'The provider returned details that do not match order '.$order['reference'].'. Contact the store before paying again.');
		}
		storeDb()->updateCryptoPayment($orderId, $payment);
		if(in_array($payment['status'], array('confirmed','sending','finished'), true)){
			if(!storeDb()->markCryptoOrderPaid($orderId, $payment['paymentId'])){
				$fresh = storeDb()->getOrder($orderId);
				if($fresh && in_array($fresh['status'], array('paid','shipped'), true)){
					return array('ok'=>true, 'final'=>true, 'html'=>storeUi()->renderReceipt($fresh, storeDb()->getOrderItems($orderId), storeDb()->getOrderDownloads($orderId)));
				}
				return array('ok'=>false, 'error'=>'The confirmed payment could not be applied to the order. Contact the store with reference '.$order['reference'].'.');
			}
			return storeFinalizePaidOrder($orderId, $settings, $buyerOwns, 'storeRefreshCryptoPayment');
		}
		$fresh = storeDb()->getOrder($orderId) ?: array_merge($order, array(
			'cryptoStatus'=>$payment['status'], 'cryptoAmount'=>$payment['payAmount'], 'cryptoAddress'=>$payment['address'],
			'cryptoExtraId'=>$payment['extraId'], 'cryptoNetwork'=>$payment['network'], 'cryptoExpiresAt'=>$payment['expiresAt']));
		return array('ok'=>true, 'final'=>in_array($payment['status'], array('failed','refunded','expired'), true), 'html'=>storeUi()->renderCryptoPayment($fresh));
	}catch(StoreCryptoException $e){
		ghoti::logError('store.async.php:storeRefreshCryptoPayment', 'NOWPayments: '.$e->getMessage());
		return array('ok'=>false, 'error'=>$e->getMessage());
	}catch(Throwable $e){
		ghoti::logException('store.async.php:storeRefreshCryptoPayment', $e);
		return array('ok'=>false, 'error'=>'The crypto payment status could not be checked.');
	}
}

function storeBeginStripeCheckout($customer){
	try{
		$checkout=storeBuildCheckout($customer); $settings=$checkout['settings']; $order=$checkout['order']; $items=$checkout['items'];
		if(!StoreStripeClient::configured($settings)){ return array('ok'=>false,'error'=>'Stripe checkout is not configured yet.'); }
		$stripe=storeStripeClient($settings); $payment=$stripe->createIntent($order);
		if($payment['amount'] !== $order['totalCents'] || $payment['currency'] !== strtoupper($order['currency']) || !hash_equals($order['reference'],$payment['reference'])){
			return array('ok'=>false,'error'=>'Stripe returned different order details. Nothing has been charged.');
		}
		$order['paymentProvider']='stripe'; $order['providerPaymentId']=$payment['id']; $order['providerStatus']=$payment['status'];
		$order['paypalOrderId']='STRIPE-'.substr(hash('sha256',$payment['id']),0,55);
		$orderId=storeDb()->createOrder($order,$items);
		if(!$orderId){ return array('ok'=>false,'error'=>'This Stripe payment could not be recorded. Nothing has been charged.'); }
		$_SESSION['storeCardOrderId']=$orderId; $_SESSION['storeOrderId']=$orderId;
		$saved=storeDb()->getOrder($orderId) ?: array_merge($order,array('orderId'=>$orderId,'status'=>'pending'));
		return array('ok'=>true,'orderId'=>$orderId,'html'=>storeUi()->renderStripePayment($saved),
			'publishableKey'=>$stripe->publishableKey(),'clientSecret'=>$payment['clientSecret']);
	}catch(StoreCardException $e){ return array('ok'=>false,'error'=>$e->getMessage()); }
	catch(Exception $e){ return array('ok'=>false,'error'=>$e->getMessage()); }
	catch(Throwable $e){ ghoti::logException('store.async.php:storeBeginStripeCheckout',$e); return array('ok'=>false,'error'=>'Stripe checkout could not be started.'); }
}

function storeBeginSquareCheckout($customer,$sourceId){
	$orderId=0; $reference='';
	try{
		$checkout=storeBuildCheckout($customer); $settings=$checkout['settings']; $order=$checkout['order']; $items=$checkout['items'];
		$reference=$order['reference'];
		if(!StoreSquareClient::configured($settings)){ return array('ok'=>false,'error'=>'Square checkout is not configured yet.'); }
		$order['paymentProvider']='square'; $order['providerPaymentId']=''; $order['providerStatus']='creating';
		$order['paypalOrderId']='SQUARE-'.substr(hash('sha256',$order['reference']),0,55);
		$orderId=storeDb()->createOrder($order,$items);
		if(!$orderId){ return array('ok'=>false,'error'=>'This Square payment could not be recorded. Nothing has been charged.'); }
		$_SESSION['storeCardOrderId']=$orderId; $_SESSION['storeOrderId']=$orderId;
		$payment=storeSquareClient($settings)->createPayment($order,$sourceId);
		if($payment['amount'] !== $order['totalCents'] || $payment['currency'] !== strtoupper($order['currency'])
			|| !hash_equals($order['reference'],$payment['reference']) || !hash_equals((string)$settings['squareLocationId'],$payment['locationId'])){
			storeDb()->setOrderStatus($orderId,'failed');
			return array('ok'=>false,'error'=>'Square returned payment details that do not match order '.$order['reference'].'.');
		}
		storeDb()->updateProviderPayment($orderId,$payment['id'],$payment['status']);
		if($payment['status'] !== 'COMPLETED'){
			return array('ok'=>false,'error'=>'Square did not complete the payment (status: '.$payment['status'].'). Reference '.$order['reference'].'.');
		}
		if(!storeDb()->markProviderOrderPaid($orderId,$payment['id'],$payment['status'])){ return array('ok'=>false,'error'=>'The completed Square payment could not be applied to the order.'); }
		return storeFinalizePaidOrder($orderId,$settings,true,'storeBeginSquareCheckout');
	}catch(StoreCardException $e){
		if($orderId){ ghoti::logError('store.async.php:storeBeginSquareCheckout','Uncertain Square result for '.$reference.': '.$e->getMessage()); return array('ok'=>false,'error'=>'Square could not confirm the payment result. Do not pay again; contact the store with reference '.$reference.'.'); }
		return array('ok'=>false,'error'=>$e->getMessage());
	}
	catch(Exception $e){ return array('ok'=>false,'error'=>$e->getMessage()); }
	catch(Throwable $e){ ghoti::logException('store.async.php:storeBeginSquareCheckout',$e); return array('ok'=>false,'error'=>'Square checkout could not be completed.'); }
}

function storeRefreshProcessorPayment($orderId){
	try{ $orderId=ghoti_validate()->id($orderId,'order id'); }catch(Exception $e){ return array('ok'=>false,'error'=>$e->getMessage()); }
	$buyerOwns=isset($_SESSION['storeCardOrderId']) && (int)$_SESSION['storeCardOrderId']===$orderId;
	if(!$buyerOwns && !ghoti_require_admin()){ return array('ok'=>false,'error'=>'That payment is not available in this session.'); }
	try{
		$order=storeDb()->getOrder($orderId);
		if(!$order || !in_array($order['paymentProvider'] ?? '',array('stripe','square'),true)){ return array('ok'=>false,'error'=>'That processor payment could not be found.'); }
		if(in_array($order['status'],array('paid','shipped'),true)){ return array('ok'=>true,'final'=>true,'html'=>storeUi()->renderReceipt($order,storeDb()->getOrderItems($orderId),storeDb()->getOrderDownloads($orderId))); }
		if($order['status']!=='pending' || $order['providerPaymentId']===''){ return array('ok'=>false,'error'=>'That payment cannot be refreshed.'); }
		$settings=storeDb()->getSettings();
		if($order['paymentProvider']==='stripe'){
			$p=storeStripeClient($settings)->getIntent($order['providerPaymentId']);
			$matches=$p['amount']===$order['totalCents'] && $p['currency']===strtoupper($order['currency']) && hash_equals($order['reference'],$p['reference']);
			$paid=$p['status']==='succeeded' && $p['amountReceived']===$order['totalCents'];
		}else{
			$p=storeSquareClient($settings)->getPayment($order['providerPaymentId']);
			$matches=$p['amount']===$order['totalCents'] && $p['currency']===strtoupper($order['currency']) && hash_equals($order['reference'],$p['reference']) && hash_equals((string)$settings['squareLocationId'],$p['locationId']);
			$paid=$p['status']==='COMPLETED';
		}
		if(!$matches){ storeDb()->setOrderStatus($orderId,'failed'); return array('ok'=>false,'error'=>'The provider payment does not match order '.$order['reference'].'.'); }
		storeDb()->updateProviderPayment($orderId,$p['id'],$p['status']);
		if(!$paid){ return array('ok'=>true,'final'=>false,'html'=>storeUi()->renderProcessorPending(storeDb()->getOrder($orderId) ?: $order)); }
		if(!storeDb()->markProviderOrderPaid($orderId,$p['id'],$p['status'])){ return array('ok'=>false,'error'=>'The completed payment could not be applied to the order.'); }
		return storeFinalizePaidOrder($orderId,$settings,$buyerOwns,'storeRefreshProcessorPayment');
	}catch(StoreCardException $e){ return array('ok'=>false,'error'=>$e->getMessage()); }
	catch(Throwable $e){ ghoti::logException('store.async.php:storeRefreshProcessorPayment',$e); return array('ok'=>false,'error'=>'The payment status could not be checked.'); }
}

function storeFinalizePaidOrder($orderId, $settings, $clearBuyerSession, $context){
	$items = storeDb()->getOrderItems($orderId);
	storeIssueDownloads($orderId, $items, $settings);
	storeQueueFulfilments($orderId, $items, $settings);
	$order = storeDb()->getOrder($orderId);
	$downloads = storeDb()->getOrderDownloads($orderId);
	if($clearBuyerSession){
		$_SESSION['storeCart'] = array();
		//Keep storeCryptoOrderId for this session so a delayed/replayed status
		//check can show the same receipt without making the order public.
		unset($_SESSION['storeOrderId'], $_SESSION['storeCoupon']);
	}
	ghoti::logInfo('store.async.php:'.$context, 'Order '.$order['reference'].' paid ('.$order['totalCents'].' '.$order['currency'].')');
	storeSendOrderMail($order, $items, $downloads);
	return array('ok'=>true, 'final'=>true, 'html'=>storeUi()->renderReceipt($order, $items, $downloads),
		'submitQueued'=>!empty($settings['dropshipEnabled']) && !empty($settings['dropshipAutoSubmit']));
}

//One download grant per purchased digital line (not per unit: buying two copies
//of the same file is still one file).
function storeIssueDownloads($orderId, $items, $settings){
	$hours = max(1, (int)$settings['downloadHours']);
	$limit = max(1, (int)$settings['downloadLimit']);
	foreach($items as $item){
		if($item['kind'] !== 'digital'){ continue; }
		storeDb()->addDownloadGrant($orderId, $item['productId'], bin2hex(random_bytes(24)), $limit, time() + ($hours * 3600));
	}
}

//Receipt to the buyer, notice to every administrator. Mail failures are logged
//and swallowed: the payment succeeded, and that must not be reported as failure.
function storeSendOrderMail($order, $items, $downloads){
	if(!isset($_SESSION['mailObj'])){ return; }
	try{
		//A buyer is a customer, not a member: the footer must not tell them they
		//have an account here, because usually they do not.
		$body = storeUi()->receiptText($order, $items, $downloads);
		$result = ghoti_mail_send_themed($_SESSION['mailObj'], $order['email'], $order['customerName'], 'Your order '.$order['reference'], $body, 'customer');
		if($result !== true){ ghoti::logWarn("store.async.php:storeSendOrderMail", "Receipt for ".$order['reference']." not sent: ".$result); }

		$adminBody = "A new order was paid.\n\n".storeUi()->receiptText($order, $items, array());
		foreach(ghoti_admin_emails() as $address){
			ghoti_mail_send_themed($_SESSION['mailObj'], $address, '', 'New order '.$order['reference'], $adminBody, 'operator');
		}
	}catch (Throwable $e){
		ghoti::logException("store.async.php:storeSendOrderMail", $e);
	}
}

//Public configuration the button script needs. The client id identifies the
//merchant in PayPal's own SDK URL and is not a secret; the secret is not here.
function storePaypalConfig(){
	$settings = storeDb()->getSettings();
	if(!StorePaypalClient::configured($settings)){
		return array('ok' => false, 'error' => 'This store is not connected to PayPal yet.');
	}
	return array(
		'ok'       => true,
		'clientId' => $settings['paypalClientId'],
		'currency' => $settings['currency'],
		'env'      => $settings['paypalEnv'],
	);
}

//Recurring services deliberately bypass the cart: PayPal subscriptions are
//created from one billing plan at a time and cannot be mixed into an Orders API
//purchase. The product page supplies the plan; this endpoint supplies the form.
function storeShowSubscription($productId){
	try{ $productId = ghoti_validate()->id($productId, 'product id'); }
	catch(Exception $e){ return '<p role="alert">'.htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8').'</p>'; }
	$product = storeDb()->getProduct($productId);
	if(!$product || !$product['active'] || $product['kind'] !== 'service' || ($product['billingType'] ?? '') !== 'subscription' || storePaypalPlanId($product['paypalPlanId'] ?? '') === ''){
		return '<p role="alert">That subscription is no longer available.</p>';
	}
	return storeUi()->renderSubscriptionCheckout($product, storeDb()->getSettings());
}

function storeConfirmSubscription($productId, $paypalSubscriptionId, $customer){
	if(!is_array($customer)){ return array('ok' => false, 'error' => 'Enter your details before subscribing.'); }
	try{
		$productId = ghoti_validate()->id($productId, 'product id');
		$paypalSubscriptionId = trim((string)$paypalSubscriptionId);
		if(!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $paypalSubscriptionId)){
			return array('ok' => false, 'error' => 'That subscription reference is not valid.');
		}
		$existing = storeDb()->getSubscriptionByPaypalId($paypalSubscriptionId);
		if($existing){
			$html = isset($_SESSION['storeSubscriptionId']) && (int)$_SESSION['storeSubscriptionId'] === (int)$existing['subscriptionId']
				? storeUi()->renderSubscriptionReceipt($existing)
				: storeUi()->renderSubscriptionRecorded($existing['paypalSubscriptionId']);
			return array('ok' => true, 'html' => $html);
		}

		$product = storeDb()->getProduct($productId);
		if(!$product || !$product['active'] || $product['kind'] !== 'service' || ($product['billingType'] ?? '') !== 'subscription'){
			return array('ok' => false, 'error' => 'That subscription is no longer available.');
		}
		$settings = storeDb()->getSettings();
		if(!StorePaypalClient::configured($settings)){ return array('ok' => false, 'error' => 'This store is not connected to PayPal yet.'); }
		$v = ghoti_validate();
		$name = $v->text($customer['name'] ?? '', 120, true, 'name');
		$email = $v->email($customer['email'] ?? '');
		$details = $v->multilineText($customer['serviceDetails'] ?? '', 500, !empty($product['serviceRequired']), $product['servicePrompt'] !== '' ? $product['servicePrompt'] : 'service details');

		$paypal = storePaypalClient($settings)->getSubscription($paypalSubscriptionId);
		if(!hash_equals((string)$product['paypalPlanId'], (string)$paypal['planId'])){
			ghoti::logWarn('store.async.php:storeConfirmSubscription', 'Plan mismatch for PayPal subscription '.$paypalSubscriptionId);
			return array('ok' => false, 'error' => 'PayPal returned a different plan. The subscription was not recorded; contact us before trying again.');
		}
		if(!in_array($paypal['status'], array('ACTIVE', 'APPROVED'), true)){
			return array('ok' => false, 'error' => 'PayPal has not approved this subscription (status: '.($paypal['status'] ?: 'unknown').').');
		}
		$row = array(
			'paypalSubscriptionId' => $paypal['id'], 'paypalPlanId' => $paypal['planId'], 'productId' => $productId,
			'userId' => isset($_SESSION['userId']) && ghoti_require_login() ? (int)$_SESSION['userId'] : null,
			'sku' => $product['sku'], 'name' => $product['name'], 'priceCents' => $product['priceCents'],
			'currency' => $settings['currency'], 'serviceTerm' => $product['serviceTerm'],
			'customerName' => $name, 'email' => $email, 'serviceDetails' => $details,
			'status' => $paypal['status'], 'nextBillingAt' => $paypal['nextBillingAt'],
		);
		$id = storeDb()->addSubscription($row);
		if(!$id){
			//A parallel approval callback may have inserted the unique PayPal id.
			$existing = storeDb()->getSubscriptionByPaypalId($paypalSubscriptionId);
			if($existing){ return array('ok' => true, 'html' => storeUi()->renderSubscriptionRecorded($existing['paypalSubscriptionId'])); }
			return array('ok' => false, 'error' => 'The subscription was approved but could not be recorded. Contact us with reference '.$paypalSubscriptionId.'.');
		}
		$subscription = storeDb()->getSubscription($id);
		$_SESSION['storeSubscriptionId'] = $id;
		ghoti::logInfo('store.async.php:storeConfirmSubscription', 'Subscription '.$paypalSubscriptionId.' recorded for product '.$product['sku']);
		storeSendSubscriptionMail($subscription);
		return array('ok' => true, 'html' => storeUi()->renderSubscriptionReceipt($subscription));
	}catch(StorePaypalException $e){
		ghoti::logError('store.async.php:storeConfirmSubscription', 'PayPal: '.$e->getMessage());
		return array('ok' => false, 'error' => $e->getMessage());
	}catch(Exception $e){ return array('ok' => false, 'error' => $e->getMessage()); }
	catch(Throwable $e){
		ghoti::logException('store.async.php:storeConfirmSubscription', $e);
		return array('ok' => false, 'error' => 'The subscription could not be confirmed.');
	}
}

function storeSendSubscriptionMail($subscription){
	if(!$subscription || !isset($_SESSION['mailObj'])){ return; }
	try{
		$body = "Subscription confirmed\n\nService: ".$subscription['name']."\nReference: ".$subscription['paypalSubscriptionId']."\nStatus: ".$subscription['status']."\n";
		if($subscription['serviceTerm'] !== ''){ $body .= 'Billing: '.$subscription['serviceTerm']."\n"; }
		if($subscription['serviceDetails'] !== ''){ $body .= 'Setup details: '.$subscription['serviceDetails']."\n"; }
		$body .= "\nWe will contact you when your service is ready. Questions? Reply with the reference above.\n";
		ghoti_mail_send_themed($_SESSION['mailObj'], $subscription['email'], $subscription['customerName'], 'Your subscription '.$subscription['paypalSubscriptionId'], $body, 'customer');
		foreach(ghoti_admin_emails() as $address){
			ghoti_mail_send_themed($_SESSION['mailObj'], $address, '', 'New subscription '.$subscription['paypalSubscriptionId'], $body, 'operator');
		}
	}catch(Throwable $e){ ghoti::logException('store.async.php:storeSendSubscriptionMail', $e); }
}

/* ---------------------------------------------------------------- *
 *  Supplier fulfilment
 *
 *  Payment and fulfilment are deliberately separate state machines. A paid
 *  order queues one row per supplier and stops there; draining that queue is
 *  somebody else's turn - the browser right after the receipt, an admin
 *  pressing Retry, or cron running store.fulfil.php. A supplier being slow or
 *  down must never make a completed payment look like a failed checkout.
 * ---------------------------------------------------------------- */

//The dropship lines of an order, grouped by the supplier that fulfils them.
function storeDropshipGroups($items){
	$groups = array();
	foreach($items as $item){
		if(($item['fulfilment'] ?? 'self') !== 'dropship'){ continue; }
		$provider = (string)($item['dropProvider'] ?? '');
		if($provider === '' || !StoreDropship::isProvider($provider)){ continue; }
		$groups[$provider][] = $item;
	}
	return $groups;
}

//Called from the capture path. Writes rows and nothing else: no HTTP happens
//here, so a supplier outage cannot reach the buyer.
function storeQueueFulfilments($orderId, $items, $settings){
	if(empty($settings['dropshipEnabled'])){ return 0; }
	$queued = 0;
	foreach(storeDropshipGroups($items) as $provider => $group){
		if(storeDb()->queueFulfilment($orderId, $provider)){ $queued++; }
	}
	if($queued > 0){
		ghoti::logInfo("store.async.php:storeQueueFulfilments", "Queued $queued supplier submission(s) for order $orderId");
	}
	return $queued;
}

//CJ caches its access token in the settings row: its token endpoint is rate
//limited, so fetching one per submission would throttle the queue.
function storeDropshipTokenStore($provider){
	return array(
		'get' => function() use ($provider){
			$config = storeDb()->getSettings()['dropshipConfig'];
			return isset($config[$provider]['_token']) ? $config[$provider]['_token'] : null;
		},
		'set' => function($token) use ($provider){
			$settings = storeDb()->getSettings();
			$config = $settings['dropshipConfig'];
			if(!isset($config[$provider]) || !is_array($config[$provider])){ $config[$provider] = array(); }
			$config[$provider]['_token'] = $token;
			storeDb()->saveSettings(array('dropshipConfig' => $config));
		},
	);
}

function storeDropshipDriver($provider, $settings){
	$config = isset($settings['dropshipConfig'][$provider]) && is_array($settings['dropshipConfig'][$provider])
		? $settings['dropshipConfig'][$provider] : array();
	return StoreDropship::driver($provider, $config, storeDropshipTokenStore($provider));
}

/*
 * Submit one queued row. Returns array('ok'=>bool, 'error'=>string).
 *
 * The claim is conditional on the row still being queued, so a cron drain and
 * an admin pressing Retry at the same moment cannot both send the order.
 */
function storeSubmitFulfilment($fulfilment){
	$settings = storeDb()->getSettings();
	if(empty($settings['dropshipEnabled'])){
		return array('ok' => false, 'error' => 'Dropshipping is switched off.');
	}
	$order = storeDb()->getOrder($fulfilment['orderId']);
	if(!$order || !in_array($order['status'], array('paid','shipped'), true)){
		storeDb()->markFulfilmentFailed($fulfilment['fulfilmentId'], 'The order is not paid.', false);
		return array('ok' => false, 'error' => 'The order is not paid.');
	}

	$items = storeDropshipGroups(storeDb()->getOrderItems($fulfilment['orderId']));
	if(empty($items[$fulfilment['provider']])){
		storeDb()->markFulfilmentFailed($fulfilment['fulfilmentId'], 'No lines on this order are mapped to '.StoreDropship::label($fulfilment['provider']).' any more.', false);
		return array('ok' => false, 'error' => 'Nothing to send.');
	}

	if(!storeDb()->claimFulfilment($fulfilment['fulfilmentId'])){
		//Another drain got there first; not an error, just not ours to do.
		return array('ok' => false, 'error' => 'Already being sent.');
	}

	try{
		$driver = storeDropshipDriver($fulfilment['provider'], $settings);
		if(!$driver->configured()){
			throw new StoreDropshipPermanentException(StoreDropship::label($fulfilment['provider']).' is not configured yet.');
		}
		$result = $driver->submitOrder($order, $items[$fulfilment['provider']]);
		storeDb()->markFulfilmentSent($fulfilment['fulfilmentId'], $result['providerOrderId'], isset($result['status']) ? $result['status'] : 'sent');
		if(!empty($result['warning'])){
			storeDb()->markFulfilmentFailed($fulfilment['fulfilmentId'], $result['warning'], false);
		}
		ghoti::logInfo("store.async.php:storeSubmitFulfilment", "Order ".$order['reference']." sent to ".$fulfilment['provider']." as ".$result['providerOrderId']);
		return array('ok' => true, 'providerOrderId' => $result['providerOrderId']);
	}catch (StoreDropshipPermanentException $e){
		//The supplier said no for a reason a person has to fix; stop retrying.
		storeDb()->markFulfilmentFailed($fulfilment['fulfilmentId'], $e->getMessage(), false);
		ghoti::logError("store.async.php:storeSubmitFulfilment", "Order ".$order['reference']." rejected by ".$fulfilment['provider'].": ".$e->getMessage());
		return array('ok' => false, 'error' => $e->getMessage());
	}catch (Throwable $e){
		//Unreachable, rate limited, 5xx: back on the queue for the next drain.
		storeDb()->markFulfilmentFailed($fulfilment['fulfilmentId'], $e->getMessage(), true);
		ghoti::logWarn("store.async.php:storeSubmitFulfilment", "Order ".$order['reference']." to ".$fulfilment['provider']." will be retried: ".$e->getMessage());
		return array('ok' => false, 'error' => $e->getMessage());
	}
}

//Drain up to $limit queued rows. Used by the CLI, the admin screen and the
//post-receipt nudge.
function storeDrainFulfilments($limit = 10){
	$sent = 0; $failed = 0;
	foreach(storeDb()->getQueuedFulfilments($limit) as $fulfilment){
		$result = storeSubmitFulfilment($fulfilment);
		if(!empty($result['ok'])){ $sent++; } else { $failed++; }
	}
	return array('sent' => $sent, 'failed' => $failed);
}

//Ask the supplier what has happened since. Tracking that arrives this way is
//what moves an order from paid to shipped.
function storeSyncFulfilment($fulfilment){
	if($fulfilment['providerOrderId'] === ''){
		return array('ok' => false, 'error' => 'This order has not reached the supplier yet.');
	}
	$settings = storeDb()->getSettings();
	try{
		$driver = storeDropshipDriver($fulfilment['provider'], $settings);
		$status = $driver->fetchStatus($fulfilment['providerOrderId']);
		storeDb()->updateFulfilmentTracking($fulfilment['fulfilmentId'], $status['status'], $status);

		//Once every supplier line has shipped, the order itself has shipped.
		if($status['status'] === 'shipped'){
			$order = storeDb()->getOrder($fulfilment['orderId']);
			$allShipped = true;
			foreach(storeDb()->getOrderFulfilments($fulfilment['orderId']) as $row){
				if($row['status'] !== 'shipped'){ $allShipped = false; break; }
			}
			if($allShipped && $order && $order['status'] === 'paid'){
				storeDb()->setOrderStatus($order['orderId'], 'shipped');
				ghoti::logInfo("store.async.php:storeSyncFulfilment", "Order ".$order['reference']." marked shipped by ".$fulfilment['provider']);
			}
		}
		return array('ok' => true, 'status' => $status['status'], 'trackingNumber' => $status['trackingNumber']);
	}catch (Throwable $e){
		ghoti::logWarn("store.async.php:storeSyncFulfilment", "Could not read ".$fulfilment['provider']." order ".$fulfilment['providerOrderId'].": ".$e->getMessage());
		return array('ok' => false, 'error' => $e->getMessage());
	}
}

function storeSyncOpenFulfilments($limit = 25){
	$synced = 0;
	foreach(storeDb()->getOpenFulfilments($limit) as $fulfilment){
		if(!empty(storeSyncFulfilment($fulfilment)['ok'])){ $synced++; }
	}
	return $synced;
}

/*
 * Called by the browser once the receipt is on screen, so the usual case - a
 * supplier that is up - reaches them within seconds of payment without the
 * buyer ever waiting on it. Public on purpose: it acts only on rows this app
 * queued, takes nothing from the caller, and does nothing at all when the
 * queue is empty.
 */
function storeSubmitQueued(){
	try{
		$settings = storeDb()->getSettings();
		if(empty($settings['dropshipEnabled']) || empty($settings['dropshipAutoSubmit'])){
			return array('ok' => true, 'sent' => 0);
		}
		$result = storeDrainFulfilments(5);
		return array('ok' => true, 'sent' => $result['sent']);
	}catch (Throwable $e){
		ghoti::logException("store.async.php:storeSubmitQueued", $e);
		return array('ok' => false, 'error' => 'The supplier queue could not be processed.');
	}
}

function storeRetryFulfilment($fulfilmentId){
	if(!storeRequireAdmin()){ return "Admin access required."; }
	try{
		$fulfilmentId = ghoti_validate()->id($fulfilmentId, "fulfilment id");
	}catch (Exception $e){
		return $e->getMessage();
	}
	$fulfilment = storeDb()->getFulfilment($fulfilmentId);
	if(!$fulfilment){ return "That submission could not be found."; }
	if($fulfilment['status'] === 'sent' || $fulfilment['status'] === 'shipped'){
		return "That order is already with the supplier.";
	}
	$result = storeSubmitFulfilment($fulfilment);
	return !empty($result['ok']) ? true : (string)$result['error'];
}

function storeRefreshFulfilment($fulfilmentId){
	if(!storeRequireAdmin()){ return "Admin access required."; }
	try{
		$fulfilmentId = ghoti_validate()->id($fulfilmentId, "fulfilment id");
	}catch (Exception $e){
		return $e->getMessage();
	}
	$fulfilment = storeDb()->getFulfilment($fulfilmentId);
	if(!$fulfilment){ return "That submission could not be found."; }
	$result = storeSyncFulfilment($fulfilment);
	return !empty($result['ok']) ? true : (string)$result['error'];
}

function saveStoreDropshipSettings($settings){
	if(!storeRequireAdmin()){ return "Admin access required."; }
	if(!is_array($settings)){ return "Invalid settings."; }
	try{
		$current = storeDb()->getSettings();
		$config = $current['dropshipConfig'];
		$drivers = StoreDropship::drivers();
		$posted = isset($settings['providers']) && is_array($settings['providers']) ? $settings['providers'] : array();

		foreach($drivers as $provider => $driver){
			if(!isset($posted[$provider]) || !is_array($posted[$provider])){ continue; }
			if(!isset($config[$provider]) || !is_array($config[$provider])){ $config[$provider] = array(); }
			foreach($driver['fields'] as $field => $definition){
				if(!array_key_exists($field, $posted[$provider])){ continue; }
				$value = trim((string)$posted[$provider][$field]);
				//A blank secret means "keep the stored one": the form never
				//renders a secret back, so saving any other field must not wipe it.
				if(!empty($definition['secret']) && $value === ''){ continue; }
				$config[$provider][$field] = mb_substr($value, 0, 500);
			}
			//Credentials changed, so a cached token minted with the old ones is
			//no longer the one to use.
			unset($config[$provider]['_token']);
		}

		$clean = array(
			'dropshipEnabled'    => !empty($settings['enabled']),
			'dropshipAutoSubmit' => !empty($settings['autoSubmit']),
			'dropshipConfig'     => $config,
		);
		if(!storeDb()->saveSettings($clean)){ return "The dropshipping settings could not be saved."; }
		ghoti::logInfo("store.async.php:saveStoreDropshipSettings", "Dropshipping settings updated by UID:".($_SESSION['userId'] ?? '?'));
		return true;
	}catch (Throwable $e){
		ghoti::logException("store.async.php:saveStoreDropshipSettings", $e);
		return "The dropshipping settings could not be saved.";
	}
}

/* ---------------------------------------------------------------- *
 *  Management endpoints (admin)
 * ---------------------------------------------------------------- */

function showStoreManager($tab = 'products'){
	if(!storeRequireAdmin()){ return "<h1>Store</h1><p>Admin access required.</p>"; }
	$tab = in_array($tab, array('products','orders','subscriptions','settings','dropship','promotions'), true) ? $tab : 'products';
	try{
		return storeUi()->renderManager($tab);
	}catch (Throwable $e){
		ghoti::logException("store.async.php:showStoreManager", $e);
		return "<h1>Store</h1><p>The store manager could not be loaded.</p>";
	}
}

function saveStorePromotions($input){
	if(!storeRequireAdmin()){ return 'Admin access required.'; }
	try{ $config = storeValidateCommerce($input); }
	catch(Exception $e){ return $e->getMessage(); }
	try{
		if(!storeDb()->saveSettings(array('commerceConfig' => $config))){ return 'The promotions could not be saved.'; }
		ghoti::logInfo('store.async.php:saveStorePromotions', 'Promotions updated by UID:'.($_SESSION['userId'] ?? '?'));
		return true;
	}catch(Throwable $e){
		ghoti::logException('store.async.php:saveStorePromotions', $e);
		return 'The promotions could not be saved.';
	}
}

function saveStoreProduct($product){
	if(!storeRequireAdmin()){ return "Admin access required."; }
	if(!is_array($product)){ return "Invalid product."; }
	try{
		$v = ghoti_validate();
		$productId = isset($product['productId']) && (int)$product['productId'] > 0 ? $v->id($product['productId'], "product id") : 0;
		//storePriceToCents() answers -1 for anything it could not read. That has
		//to be caught here: intInRange() CLAMPS to its minimum rather than
		//throwing, so a typo in the price box would silently list the item free.
		$priceCents = storePriceToCents(isset($product['price']) ? $product['price'] : '0');
		if($priceCents < 0){ return "Enter the price as a number, for example 12.50."; }
		$compareAt = storePriceToCents(($product['compareAtPrice'] ?? '') === '' ? '0' : $product['compareAtPrice']);
		if($compareAt < 0 || ($compareAt > 0 && $compareAt <= $priceCents)){
			return 'The original price must be higher than the selling price, or blank.';
		}
		$clean = array(
			'featured' => !empty($product['featured']),
			'compareAtCents' => $compareAt,
			'badge' => $v->text($product['badge'] ?? '', 32, false, 'badge'),
			'deliveryNote' => $v->text($product['deliveryNote'] ?? '', 160, false, 'delivery note'),
			'serviceTerm' => $v->text($product['serviceTerm'] ?? '', 80, false, 'service term'),
			'servicePrompt' => $v->text($product['servicePrompt'] ?? '', 160, false, 'service setup question'),
			'serviceRequired' => !empty($product['serviceRequired']),
			'billingType' => 'one_time',
			'paypalPlanId' => '',
			'externalUrl' => '',
			'sku'         => $v->text(isset($product['sku']) ? $product['sku'] : '', 60, true, "SKU"),
			'name'        => $v->text(isset($product['name']) ? $product['name'] : '', 120, true, "product name"),
			'description' => $v->multilineText(isset($product['description']) ? $product['description'] : '', 2000, false, "description"),
			'priceCents'  => $v->intInRange($priceCents, 0, 99999999, "price"),
			'kind'        => in_array($product['kind'] ?? '', array('digital', 'service'), true) ? $product['kind'] : 'physical',
			'category'    => $v->linkGroup(isset($product['category']) ? $product['category'] : 'default', true),
			'imageUrl'    => '',
			'downloadPath'=> '',
			'active'      => !empty($product['active']),
			'sortOrder'   => $v->intInRange(isset($product['sortOrder']) ? $product['sortOrder'] : 0, 0, 99999, "sort order"),
			'fulfilment'    => 'self',
			'dropProvider'  => '',
			'dropProductId' => '',
			'dropVariantId' => '',
		);
		if($clean['kind'] !== 'service'){
			$clean['serviceTerm'] = '';
			$clean['servicePrompt'] = '';
			$clean['serviceRequired'] = false;
		}elseif($clean['serviceRequired'] && $clean['servicePrompt'] === ''){
			return 'Enter the setup question customers must answer, or make it optional.';
		}
		if($clean['kind'] === 'service' && ($product['billingType'] ?? '') === 'subscription'){
			$clean['billingType'] = 'subscription';
			$clean['paypalPlanId'] = storePaypalPlanId($product['paypalPlanId'] ?? '');
			if($clean['paypalPlanId'] === ''){ return 'Enter the PayPal plan ID for this subscription (it starts with P-).'; }
			if($clean['priceCents'] <= 0){ return 'Enter the recurring price shown in the PayPal plan.'; }
			if($clean['serviceTerm'] === ''){ return 'Describe the recurring billing term, for example “per month”.'; }
		}
		if($clean['kind'] === 'service' && ($product['fulfilment'] ?? 'self') !== 'self'){
			return 'A service is provisioned by you and cannot use Spring or a dropshipping supplier.';
		}
		//Rendered into an <img src>, so it goes through the same scheme check as
		//every other URL the CMS accepts from an admin.
		if(trim((string)(isset($product['imageUrl']) ? $product['imageUrl'] : '')) !== ''){
			$clean['imageUrl'] = $v->url($product['imageUrl'], false, "image URL");
		}
		if(($product['fulfilment'] ?? '') === 'spring'){
			$externalUrl = $product['externalUrl'] ?? '';
			$clean['externalUrl'] = storeSpringUrl(is_string($externalUrl) ? trim($externalUrl) : $externalUrl);
			if($clean['externalUrl'] === ''){ return 'Enter an HTTPS Spring product URL on creator-spring.com, teespring.com, or spri.ng.'; }
			$clean['fulfilment'] = 'spring';
			$clean['dropProvider'] = 'spring';
		}
		if($clean['kind'] === 'digital' && $clean['fulfilment'] !== 'spring'){
			$clean['downloadPath'] = storeSafeDownloadPath(isset($product['downloadPath']) ? $product['downloadPath'] : '');
			if($clean['downloadPath'] === ''){
				return "A digital product needs a file that already exists under files/store/ to deliver.";
			}
		}
		//Fulfilment is separate from kind: a dropshipped item is still physical,
		//still charges shipping, and still needs an address.
		if(isset($product['fulfilment']) && $product['fulfilment'] === 'dropship'){
			if($clean['kind'] !== 'physical'){
				return "Only a physical product can be fulfilled by a supplier.";
			}
			$provider = (string)(isset($product['dropProvider']) ? $product['dropProvider'] : '');
			if(!StoreDropship::isProvider($provider)){
				return "Choose a supplier for this product.";
			}
			$clean['fulfilment']   = 'dropship';
			$clean['dropProvider'] = $provider;
			//Supplier ids are opaque strings, so they are length- and
			//charset-bounded rather than interpreted.
			$clean['dropProductId'] = storeSupplierId(isset($product['dropProductId']) ? $product['dropProductId'] : '');
			$clean['dropVariantId'] = storeSupplierId(isset($product['dropVariantId']) ? $product['dropVariantId'] : '');
			if($clean['dropVariantId'] === ''){
				return "A dropshipped product needs the supplier's variant id.";
			}
			if($provider === 'printify' && $clean['dropProductId'] === ''){
				return "Printify needs both a product id and a variant id.";
			}
		}

		if(storeDb()->skuTaken($clean['sku'], $productId)){
			return "Another product already uses that SKU.";
		}

		$saved = $productId > 0 ? storeDb()->updateProduct($productId, $clean) : storeDb()->addProduct($clean);
		if(!$saved){ return "The product could not be saved."; }
		ghoti::logInfo("store.async.php:saveStoreProduct", ($productId > 0 ? "Updated" : "Added")." product ".$clean['sku']." by UID:".($_SESSION['userId'] ?? '?'));
		return true;
	}catch (Exception $e){
		return $e->getMessage();
	}catch (Throwable $e){
		ghoti::logException("store.async.php:saveStoreProduct", $e);
		return "The product could not be saved.";
	}
}

function deleteStoreProduct($productId){
	if(!storeRequireAdmin()){ return "Admin access required."; }
	try{
		$productId = ghoti_validate()->id($productId, "product id");
	}catch (Exception $e){
		return $e->getMessage();
	}
	//Order lines keep their own copy of name, sku and price, so deleting a
	//product never rewrites what somebody already paid.
	if(!storeDb()->deleteProduct($productId)){ return "The product could not be deleted."; }
	ghoti::logInfo("store.async.php:deleteStoreProduct", "Deleted product $productId by UID:".($_SESSION['userId'] ?? '?'));
	return true;
}

function saveStoreSettings($settings){
	if(!storeRequireAdmin()){ return "Admin access required."; }
	if(!is_array($settings)){ return "Invalid settings."; }
	try{
		$v = ghoti_validate();
		$current = storeDb()->getSettings();
		$currency = strtoupper(trim((string)(isset($settings['currency']) ? $settings['currency'] : '')));
		if(!preg_match('/^[A-Z]{3}$/', $currency)){ return "Enter a three-letter currency code, such as CAD."; }

		//A blank secret means "leave it alone": the form never renders the stored
		//one back, so re-saving any other field must not wipe it.
		$secret = (string)(isset($settings['paypalSecret']) ? $settings['paypalSecret'] : '');
		if(trim($secret) === ''){ $secret = $current['paypalSecret']; }
		$cryptoKey = trim((string)($settings['cryptoApiKey'] ?? ''));
		if($cryptoKey === ''){ $cryptoKey = $current['cryptoApiKey'] ?? ''; }
		$cryptoCurrencies = implode(',', StoreCryptoClient::currencyList($settings['cryptoCurrencies'] ?? ''));
		$cryptoEnabled = !empty($settings['cryptoEnabled']);
		if($cryptoEnabled && $cryptoKey === ''){ return 'Enter a NOWPayments API key before enabling crypto checkout.'; }
		if($cryptoEnabled && $cryptoCurrencies === ''){ return 'Add at least one cryptocurrency code, such as btc.'; }
		$stripeSecret = trim((string)($settings['stripeSecretKey'] ?? ''));
		if($stripeSecret === ''){ $stripeSecret = $current['stripeSecretKey'] ?? ''; }
		$squareToken = trim((string)($settings['squareAccessToken'] ?? ''));
		if($squareToken === ''){ $squareToken = $current['squareAccessToken'] ?? ''; }
		$stripe = array('stripeEnabled'=>!empty($settings['stripeEnabled']),
			'stripePublishableKey'=>$v->text($settings['stripePublishableKey'] ?? '',255,false,'Stripe publishable key'),
			'stripeSecretKey'=>$v->text($stripeSecret,255,false,'Stripe secret key'));
		$square = array('squareEnabled'=>!empty($settings['squareEnabled']),
			'squareApplicationId'=>$v->text($settings['squareApplicationId'] ?? '',255,false,'Square application ID'),
			'squareLocationId'=>$v->text($settings['squareLocationId'] ?? '',100,false,'Square location ID'),
			'squareAccessToken'=>$v->text($squareToken,255,false,'Square access token'),
			'squareEnv'=>($settings['squareEnv'] ?? '') === 'live' ? 'live' : 'sandbox');
		if($stripe['stripeEnabled'] && !StoreStripeClient::configured($stripe)){ return 'Enter valid Stripe publishable and secret keys before enabling Stripe.'; }
		if($square['squareEnabled'] && !StoreSquareClient::configured($square)){ return 'Enter the Square application ID, location ID, and access token before enabling Square.'; }

		$shippingCents = storePriceToCents(isset($settings['shipping']) ? $settings['shipping'] : '0');
		if($shippingCents < 0){ return "Enter the shipping rate as a number, for example 9.95."; }

		$clean = array(
			'paypalClientId' => $v->text(isset($settings['paypalClientId']) ? $settings['paypalClientId'] : '', 255, false, "PayPal client ID"),
			'paypalSecret'   => trim($secret),
			'paypalEnv'      => (isset($settings['paypalEnv']) && $settings['paypalEnv'] === 'live') ? 'live' : 'sandbox',
			'cryptoEnabled'  => $cryptoEnabled,
			'cryptoApiKey'   => $v->text($cryptoKey, 255, false, 'NOWPayments API key'),
			'cryptoCurrencies' => $v->text($cryptoCurrencies, 500, false, 'cryptocurrencies'),
			'stripeEnabled'=>$stripe['stripeEnabled'], 'stripePublishableKey'=>$stripe['stripePublishableKey'], 'stripeSecretKey'=>$stripe['stripeSecretKey'],
			'squareEnabled'=>$square['squareEnabled'], 'squareApplicationId'=>$square['squareApplicationId'], 'squareLocationId'=>$square['squareLocationId'],
			'squareAccessToken'=>$square['squareAccessToken'], 'squareEnv'=>$square['squareEnv'],
			'currency'       => $currency,
			'shippingCents'  => $v->intInRange($shippingCents, 0, 99999999, "shipping"),
			'shippingNote'   => $v->text(isset($settings['shippingNote']) ? $settings['shippingNote'] : '', 255, false, "shipping note"),
			'downloadHours'  => $v->intInRange(isset($settings['downloadHours']) ? $settings['downloadHours'] : 72, 1, 8760, "download window"),
			'downloadLimit'  => $v->intInRange(isset($settings['downloadLimit']) ? $settings['downloadLimit'] : 5, 1, 100, "download limit"),
		);
		if($clean['paypalEnv'] === 'live' && !StorePaypalClient::configured($clean)){
			return "Enter the live client ID and secret before switching to live payments.";
		}
		if(!storeDb()->saveSettings($clean)){ return "The settings could not be saved."; }
		ghoti::logInfo("store.async.php:saveStoreSettings", "Store settings updated (".$clean['paypalEnv'].", ".$clean['currency'].") by UID:".($_SESSION['userId'] ?? '?'));
		return true;
	}catch (Exception $e){
		return $e->getMessage();
	}catch (Throwable $e){
		ghoti::logException("store.async.php:saveStoreSettings", $e);
		return "The settings could not be saved.";
	}
}

function showStoreOrder($orderId){
	if(!storeRequireAdmin()){ return "<p>Admin access required.</p>"; }
	try{
		$orderId = ghoti_validate()->id($orderId, "order id");
	}catch (Exception $e){
		return "<p>".htmlspecialchars($e->getMessage(), ENT_QUOTES)."</p>";
	}
	$order = storeDb()->getOrder($orderId);
	if(!$order){ return "<p>That order could not be found.</p>"; }
	return storeUi()->renderOrderDetail($order, storeDb()->getOrderItems($orderId), storeDb()->getOrderDownloads($orderId));
}

function setStoreOrderStatus($orderId, $status){
	if(!storeRequireAdmin()){ return "Admin access required."; }
	try{
		$orderId = ghoti_validate()->id($orderId, "order id");
	}catch (Exception $e){
		return $e->getMessage();
	}
	//'paid' is deliberately absent: only a verified capture may set that.
	if(!in_array($status, array('shipped','cancelled'), true)){
		return "That status cannot be set by hand.";
	}
	$order = storeDb()->getOrder($orderId);
	if(!$order){ return "That order could not be found."; }
	if($status === 'shipped' && $order['status'] !== 'paid'){
		return "Only a paid order can be marked shipped.";
	}
	if($status === 'shipped' && !$order['hasPhysical']){ return 'Only an order with physical goods can be marked shipped.'; }
	if(!storeDb()->setOrderStatus($orderId, $status)){ return "The order could not be updated."; }
	ghoti::logInfo("store.async.php:setStoreOrderStatus", "Order ".$order['reference']." set to $status by UID:".($_SESSION['userId'] ?? '?'));
	return true;
}

function setStoreOrderServiceStatus($orderId, $status){
	if(!storeRequireAdmin()){ return 'Admin access required.'; }
	try{ $orderId = ghoti_validate()->id($orderId, 'order id'); }
	catch(Exception $e){ return $e->getMessage(); }
	if(!in_array($status, array('pending', 'fulfilled'), true)){ return 'That service status is not valid.'; }
	$order = storeDb()->getOrder($orderId);
	if(!$order || !$order['hasService']){ return 'That service order could not be found.'; }
	if(!in_array($order['status'], array('paid', 'shipped'), true)){ return 'Only a paid service can be fulfilled.'; }
	if(!storeDb()->setServiceStatus($orderId, $status)){ return 'The service status could not be updated.'; }
	ghoti::logInfo('store.async.php:setStoreOrderServiceStatus', 'Order '.$order['reference'].' service set to '.$status.' by UID:'.($_SESSION['userId'] ?? '?'));
	return true;
}

function refreshStoreSubscription($subscriptionId){
	if(!storeRequireAdmin()){ return 'Admin access required.'; }
	try{ $subscriptionId = ghoti_validate()->id($subscriptionId, 'subscription id'); }
	catch(Exception $e){ return $e->getMessage(); }
	$subscription = storeDb()->getSubscription($subscriptionId);
	if(!$subscription){ return 'That subscription could not be found.'; }
	try{
		$paypal = storePaypalClient(storeDb()->getSettings())->getSubscription($subscription['paypalSubscriptionId']);
		if(!hash_equals($subscription['paypalPlanId'], $paypal['planId'])){ return 'PayPal returned a different plan for this subscription.'; }
		if(!storeDb()->updateSubscriptionStatus($subscriptionId, $paypal['status'], $paypal['nextBillingAt'])){ return 'The subscription status could not be saved.'; }
		return true;
	}catch(StorePaypalException $e){ return $e->getMessage(); }
}

function setStoreSubscriptionServiceStatus($subscriptionId, $status){
	if(!storeRequireAdmin()){ return 'Admin access required.'; }
	try{ $subscriptionId = ghoti_validate()->id($subscriptionId, 'subscription id'); }
	catch(Exception $e){ return $e->getMessage(); }
	if(!in_array($status, array('pending', 'fulfilled'), true)){ return 'That service status is not valid.'; }
	$subscription = storeDb()->getSubscription($subscriptionId);
	if(!$subscription){ return 'That subscription could not be found.'; }
	if(!storeDb()->setSubscriptionServiceStatus($subscriptionId, $status)){ return 'The service status could not be updated.'; }
	ghoti::logInfo('store.async.php:setStoreSubscriptionServiceStatus', 'Subscription '.$subscription['paypalSubscriptionId'].' setup set to '.$status.' by UID:'.($_SESSION['userId'] ?? '?'));
	return true;
}

/* ---------------------------------------------------------------- *
 *  Input helpers
 * ---------------------------------------------------------------- */

/*
 * "12.34" / "12" / "12,34" -> 1234. Parsed as text, never as a float: casting
 * "12.34" through a float and multiplying by 100 can land on 1233.
 */
function storePriceToCents($value){
	$value = trim((string)$value);
	$value = str_replace(array(',', ' ', "\xc2\xa0"), array('.', '', ''), $value);
	$value = preg_replace('/^[^\d.-]+/', '', $value); //drop a leading currency symbol
	if($value === '' || !preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $m)){
		return -1; //rejected by intInRange() in the caller, with its label
	}
	// Reject oversized prices before arithmetic can overflow or the caller can clamp them.
	if(strlen(ltrim($m[1], '0')) > 6){ return -1; }
	return ((int)$m[1] * 100) + (int)str_pad(isset($m[2]) ? $m[2] : '0', 2, '0', STR_PAD_RIGHT);
}

// Hosted checkout uses only Spring's own HTTPS domains, never arbitrary redirects.
function storeSpringUrl($value){
	if(!is_string($value) || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $value)){ return ''; }
	$parts = parse_url($value);
	if(!$parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])){ return ''; }
	$host = strtolower($parts['host'] ?? '');
	foreach(array('creator-spring.com', 'teespring.com', 'spri.ng') as $domain){
		if($host === $domain || str_ends_with($host, '.'.$domain)){ return $value; }
	}
	return '';
}

function storePaypalPlanId($value){
	$value = trim((string)$value);
	return preg_match('/^P-[A-Za-z0-9-]{8,62}$/', $value) ? $value : '';
}

// Supplier identifiers are opaque, bounded strings.
function storeSupplierId($value){
	$value = trim((string)$value);
	if($value === '' || !preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $value)){ return ''; }
	return $value;
}

function storeStoreFilesBase(){
	return realpath(__DIR__.'/../../files/store');
}

/*
 * A digital product's file is named relative to files/store/ and may not leave
 * it. That directory - not files/ itself - is the one the web server is told to
 * deny (files/store/.htaccess), because files/ has to stay readable for gallery
 * images and product photos. A paid file served straight off the filesystem
 * would make the download tokens decorative.
 *
 * The same rule the file manager and the log analyzer follow: resolve, then
 * prove the result is still inside the directory it was supposed to be in. Dot
 * segments are refused outright rather than left to realpath, so a name can
 * never reach for a sibling directory or a hidden file.
 */
function storeSafeDownloadPath($path){
	$path = trim(str_replace('\\', '/', (string)$path), '/');
	if($path === '' || strpos($path, "\0") !== false){ return ''; }
	if(!preg_match('#^[A-Za-z0-9._/-]{1,255}$#', $path)){ return ''; }
	foreach(explode('/', $path) as $segment){
		if($segment === '' || $segment[0] === '.'){ return ''; }
	}
	$base = storeStoreFilesBase();
	if($base === false){ return ''; }
	$full = realpath($base.'/'.$path);
	if($full === false || !is_file($full) || strpos($full, $base.DIRECTORY_SEPARATOR) !== 0){ return ''; }
	return $path;
}

ghoti_async_register(
	"storeSubmitQueued",
	"storeRetryFulfilment",
	"storeRefreshFulfilment",
	"saveStoreDropshipSettings",
	"showStore",
	"storeShowCart",
	"storeAddToCart",
	"storeSetCartQuantity",
	"storeShowCheckout",
	"storeBeginCheckout",
	"storeCaptureOrder",
	"storeBeginCryptoCheckout",
	"storeRefreshCryptoPayment",
	"storeBeginStripeCheckout",
	"storeBeginSquareCheckout",
	"storeRefreshProcessorPayment",
	"storePaypalConfig",
	"storeShowSubscription",
	"storeConfirmSubscription",
	"showStoreManager",
	"saveStoreProduct",
	"deleteStoreProduct",
	"saveStoreSettings",
	"saveStorePromotions",
	"showStoreOrder",
	"setStoreOrderStatus",
	"setStoreOrderServiceStatus",
	"refreshStoreSubscription",
	"setStoreSubscriptionServiceStatus"
);

/* ---------------------------------------------------------------- *
 *  Shortcode: [store:CATEGORY] / [store CATEGORY] inside page content,
 *  with [store:all] for the whole catalogue. Same mechanism the gallery
 *  module uses, so a shop lives on an ordinary page.
 * ---------------------------------------------------------------- */

function store_shortcode_expand($matches){
	$category = isset($matches[1]) ? trim($matches[1]) : 'all';
	if(!isset($_SESSION['storeObj'])){ return ''; }
	try{
		$category = ($category === 'all' || $category === '') ? 'all' : ghoti_validate()->linkGroup($category);
	}catch (Exception $e){
		return '<p class="ghotiStoreMissing">Unknown store category.</p>';
	}
	$products = storeDb()->getProducts($category);
	return storeUi()->renderStorefront($products, $category, true);
}
ghoti_register_shortcode('store', 'store_shortcode_expand');

/* ---------------------------------------------------------------- *
 *  UI renderer (class storeui)
 *
 *  Every value that reaches the page goes through $esc(): product copy and
 *  customer details are user input, and the receipt renders back what a buyer
 *  typed into the checkout form.
 * ---------------------------------------------------------------- */

class storeui{
	public $output;

	private function esc($value){ return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

	//The one place cents become a displayed amount.
	public function money($cents, $currency){
		return $this->esc($currency.' '.StorePaypalClient::amount((int)$cents));
	}

	public function cartSummaryText($totals){
		if($totals['units'] === 0){ return 'Cart is empty'; }
		return $totals['units'].' item'.($totals['units'] === 1 ? '' : 's').' · '.$totals['currency'].' '.StorePaypalClient::amount($totals['totalCents']);
	}

	/* ---------------- storefront ---------------- */

	public function renderStorefront($products, $category = 'all', $inline = false){
		$totals = storeCartTotals();
		// Each shortcode owns its controls, even with several catalogues on a page.
		static $instance = 0;
		$prefix = 'store-'.(++$instance);
		$categories = array();
		foreach($products as $product){ $categories[$product['category']] = true; }
		ksort($categories);
		$o = '<section class="ghotiStore ghotiStoreCatalog'.($inline ? ' ghotiStoreInline' : '').'" aria-label="Store">';
		$o .= '<header class="ghotiStoreHero"><div><p class="ghotiStoreEyebrow">THE COLLECTION</p>';
		$o .= '<h1 class="ghotiStoreTitle">'.($category === 'all' ? 'Find your next favourite.' : $this->esc(ucfirst($category))).'</h1>';
		$o .= '<p class="ghotiStoreLead">Explore the collection. Find something that feels like you.</p></div>';
		$o .= '<button type="button" class="ghotiButton ghotiButtonSecondary ghotiStoreCartButton" onclick="storeShowCart();">Cart <span class="ghotiStoreCartSummary">'.$this->esc($this->cartSummaryText($totals)).'</span></button></header>';
		$config = $totals['commerce'];
		if($config['freeShippingCents'] > 0 || $config['pointsPerUnit'] > 0){
			$o .= '<div class="ghotiStoreOfferStrip">';
			if($config['freeShippingCents'] > 0){ $o .= '<span>Free shipping from '.$this->money($config['freeShippingCents'], $totals['currency']).' after discounts</span>'; }
			if($config['pointsPerUnit'] > 0){ $o .= '<span>'.($totals['signedIn'] ? 'Your rewards: '.(int)$totals['pointsBalance'].' points' : 'Members earn '.$config['pointsPerUnit'].' points per '.$this->esc($totals['currency']).' 1 spent').'</span>'; }
			$o .= '<small>Local checkout only; Spring purchases are separate.</small></div>';
		}
		if(!$products){
			return $o.'<div class="ghotiStoreEmpty"><h2>A little something is on its way.</h2><p>Check back soon for new additions to the collection.</p></div></section>';
		}
		$o .= '<div class="ghotiStoreTools"><label class="ghotiStoreSearch"><span>Search the collection</span><input type="search" data-store-search placeholder="Search products, descriptions, or SKU…" oninput="storeFilterCatalog(this);" /></label>';
		$o .= '<label><span>Product type</span><select data-store-kind onchange="storeFilterCatalog(this);"><option value="all">All products</option><option value="physical">Physical goods</option><option value="digital">Digital downloads</option><option value="service">Digital services</option><option value="spring">Spring merch</option><option value="sale">On sale</option><option value="saved">Saved favourites</option></select></label>';
		$o .= '<label><span>Sort by</span><select data-store-sort onchange="storeFilterCatalog(this);"><option value="featured">Featured</option><option value="newest">Newest first</option><option value="price-asc">Price: low to high</option><option value="price-desc">Price: high to low</option><option value="name">Name: A–Z</option></select></label></div>';
		$o .= '<div class="ghotiStoreCollectionBar"><div class="ghotiStoreChips" role="group" aria-label="Categories"><button type="button" data-store-category="all" aria-pressed="true" onclick="storeSelectCategory(this);">All items</button>';
		foreach(array_keys($categories) as $name){
			$o .= '<button type="button" data-store-category="'.$this->esc($name).'" aria-pressed="false" onclick="storeSelectCategory(this);">'.$this->esc(ucfirst($name)).'</button>';
		}
		$o .= '</div><p class="ghotiStoreResultCount" role="status">'.count($products).' products</p></div><div class="ghotiStoreGrid">';
		foreach($products as $index => $product){
			$id = (int)$product['productId'];
			$spring = ($product['fulfilment'] ?? 'self') === 'spring';
			$subscription = $product['kind'] === 'service' && ($product['billingType'] ?? '') === 'subscription';
			$externalUrl = $spring ? storeSpringUrl($product['externalUrl'] ?? '') : '';
			$featured = !empty($product['featured']);
			$compareAt = (int)($product['compareAtCents'] ?? 0);
			$badge = $product['badge'] ?? '';
			if($compareAt > $product['priceCents']){ $badge = 'On sale · Save '.intdiv(($compareAt - $product['priceCents']) * 100, $compareAt).'%'; }
			$o .= '<article class="ghotiStoreCard" data-product-id="'.$id.'" data-sale="'.($compareAt > $product['priceCents'] ? '1' : '0').'" data-category="'.$this->esc($product['category']).'" data-kind="'.$this->esc($product['kind']).'" data-spring="'.($spring ? '1' : '0').'" data-name="'.$this->esc($product['name']).'" data-search="'.$this->esc($product['name'].' '.$product['description'].' '.$product['sku']).'" data-price="'.(int)$product['priceCents'].'" data-featured="'.($featured ? '1' : '0').'" data-created="'.(int)$product['createdAt'].'" data-index="'.(int)$index.'">';
			$o .= '<div class="ghotiStoreThumb">';
			if($product['imageUrl'] !== ''){
				$o .= '<img src="'.$this->esc($product['imageUrl']).'" alt="'.$this->esc($product['name']).'" loading="lazy" decoding="async" />';
			}else{
				$o .= '<span class="ghotiStorePlaceholder" aria-hidden="true">'.($product['kind'] === 'digital' ? '↓' : ($product['kind'] === 'service' ? '◎' : '◇')).'</span>';
			}
			if($badge !== '' || $featured){ $o .= '<span class="ghotiStoreRibbon">'.$this->esc($badge !== '' ? $badge : 'Featured').'</span>'; }
			$typeLabel = $spring ? 'Spring' : ($product['kind'] === 'digital' ? 'Digital download' : ($product['kind'] === 'service' ? ($subscription ? 'Subscription service' : 'Digital service') : 'Physical goods'));
			$o .= '</div><div class="ghotiStoreCardBody"><div class="ghotiStoreMeta"><span>'.$this->esc(ucfirst($product['category'])).'</span><span>'.$typeLabel.'</span></div>';
			$o .= '<h2>'.$this->esc($product['name']).'</h2><button type="button" class="ghotiStoreSave" data-store-save="'.$id.'" aria-pressed="false" aria-label="Save '.$this->esc($product['name']).'" onclick="storeToggleSaved(this);">♡ Save favourite</button>';
			if($product['description'] !== ''){
				$o .= '<details class="ghotiStoreDescription"><summary>Product details</summary><p class="ghotiStoreBlurb">'.nl2br($this->esc($product['description'])).'</p></details>';
			}
			$o .= '<div class="ghotiStorePricing">';
			if($compareAt > $product['priceCents']){ $o .= '<del aria-label="Original price">'.$this->money($compareAt, $totals['currency']).'</del>'; }
			$o .= '<span class="ghotiStorePrice">'.($spring ? '<small>From </small>' : '').$this->money($product['priceCents'], $totals['currency']).(!empty($product['serviceTerm']) ? ' <small>'.$this->esc($product['serviceTerm']).'</small>' : '').'</span></div>';
			if(!empty($product['deliveryNote'])){ $o .= '<p class="ghotiStoreDelivery">'.$this->esc($product['deliveryNote']).'</p>'; }
			$o .= '<div class="ghotiStoreBuy">';
			if($spring){
				$o .= $externalUrl !== '' ? '<a class="ghotiButton ghotiStoreExternal" href="'.$this->esc($externalUrl).'" target="_blank" rel="noopener noreferrer">Buy on Spring <span aria-hidden="true">↗</span><span class="sr-only"> (opens in a new tab)</span></a>' : '<span class="ghotiStoreMissing">Currently unavailable</span>';
			}elseif($subscription){
				$o .= '<button type="button" class="ghotiButton" onclick="storeShowSubscription('.$id.');">Subscribe</button>';
			}else{
				$o .= '<label class="ghotiStoreQty"><span class="sr-only">Quantity of '.$this->esc($product['name']).'</span><input type="number" id="'.$prefix.'-qty-'.$id.'" value="1" min="1" max="'.STORE_MAX_QTY.'" step="1" /></label>';
				$o .= '<button type="button" class="ghotiButton" onclick="storeAddToCart('.$id.', this);">Add to cart</button>';
			}
			$purchaseNote = $spring ? 'Options, final price, payment &amp; fulfilment on Spring.' : ($product['kind'] === 'digital' ? 'Download link delivered after payment.' : ($product['kind'] === 'service' ? ($subscription ? 'Recurring billing managed securely by PayPal.' : 'We will contact you to provision the service after payment.') : 'Shipping calculated in your cart.'));
			$o .= '</div><p class="ghotiStorePurchaseNote">'.$purchaseNote.'</p></div></article>';
		}
		$o .= '</div><div class="ghotiStoreNoResults ghotiStoreEmpty" hidden><h2>No products found.</h2><p>Try another search or reset your filters.</p><button class="ghotiButton ghotiButtonSecondary" type="button" onclick="storeResetFilters(this);">Reset filters</button></div>';
		$o .= '<footer class="ghotiStoreTrust"><span>Provider-verified local checkout</span><span>Spring items check out separately</span><span>Payment credentials stay with your provider</span></footer></section>';
		return $o;
	}

	public function renderCart($lines, $totals){
		$o  = "<div id=\"ghotiStoreCart\" class=\"ghotiStore\">\n";
		$o .= "<div class=\"ghotiStoreBar\"><h1 class=\"ghotiStoreTitle\">Your cart</h1>";
		$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" onclick=\"showStore();\">Keep shopping</button></div>\n";

		if(!$lines){
			$o .= "<p class=\"ghotiStoreEmpty\">Your cart is empty.</p>\n</div>\n";
			return $o;
		}

		$o .= "<div class=\"ghotiStoreTableScroll\"><table class=\"ghotiStoreTable\"><thead><tr><th>Item</th><th>Price</th><th>Qty</th><th>Line</th><th></th></tr></thead><tbody>\n";
		foreach($lines as $line){
			$id = (int)$line['productId'];
			$o .= "<tr>";
			$tag = $line['kind'] === 'digital' ? 'Download' : ($line['kind'] === 'service' ? 'Service' : '');
			$o .= "<td>".$this->esc($line['name']).($tag !== '' ? " <span class=\"ghotiStoreTag\">".$tag."</span>" : "").(!empty($line['serviceTerm']) ? '<br /><small>'.$this->esc($line['serviceTerm']).'</small>' : '')."</td>";
			$o .= "<td>".$this->money($line['unitCents'], $totals['currency'])."</td>";
			$o .= "<td><input type=\"number\" class=\"ghotiStoreQtyInput\" value=\"".(int)$line['quantity']."\" min=\"1\" max=\"".STORE_MAX_QTY."\" step=\"1\" onchange=\"storeSetCartQuantity($id, this.value);\" aria-label=\"Quantity of ".$this->esc($line['name'])."\" /></td>";
			$o .= "<td>".$this->money($line['lineCents'], $totals['currency'])."</td>";
			$o .= "<td><button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary ghotiButtonDanger\" onclick=\"storeSetCartQuantity($id, 0);\">Remove</button></td>";
			$o .= "</tr>\n";
		}
		$o .= "</tbody></table></div>\n";

		$o .= '<form class="ghotiStoreCoupon" onsubmit="storeApplyCoupon(this); return false;"><label for="storeCoupon">Discount code</label><div><input id="storeCoupon" name="code" maxlength="32" autocomplete="off" value="'.$this->esc($_SESSION['storeCoupon'] ?? '').'" placeholder="Enter your code" /><button class="ghotiButton" type="submit">Apply</button><button class="ghotiButton ghotiButtonSecondary" type="button" onclick="storeShowCart(\'\');">Clear</button></div><small>One offer per order. The better of your code or member reward applies.</small></form>';
		if(!empty($totals['couponError'])){ $o .= '<p class="ghotiStoreStatus is-error" role="alert">'.$this->esc($totals['couponError']).'</p>'; }
		$o .= $this->renderRewards($totals);
		$o .= "<dl class=\"ghotiStoreTotals\">\n";
		$o .= "<div><dt>Subtotal</dt><dd>".$this->money($totals['subtotalCents'], $totals['currency'])."</dd></div>\n";
		$o .= $this->renderDiscount($totals);
		if($totals['hasPhysical']){
			$o .= "<div><dt>Shipping</dt><dd>".$this->money($totals['shippingCents'], $totals['currency'])."</dd></div>\n";
		}
		$o .= "<div class=\"ghotiStoreGrand\"><dt>Total</dt><dd>".$this->money($totals['totalCents'], $totals['currency'])."</dd></div>\n";
		$o .= "</dl>\n";
		if(!empty($totals['saleSavingsCents'])){ $o .= '<p class="ghotiStoreSavings">Sale prices already save you '.$this->money($totals['saleSavingsCents'], $totals['currency']).'.</p>'; }
		$o .= "<div class=\"ghotiStoreActions\"><button type=\"button\" class=\"ghotiButton\" onclick=\"storeShowCheckout();\">Checkout</button></div>\n";
		$o .= "</div>\n";
		return $o;
	}

	public function renderCheckout($lines, $totals, $settings){
		$o  = "<div id=\"ghotiStoreCheckout\" class=\"ghotiStore\" data-currency=\"".$this->esc($totals['currency'])."\" data-physical=\"".($totals['hasPhysical'] ? '1' : '0')."\">\n";
		$o .= "<div class=\"ghotiStoreBar\"><h1 class=\"ghotiStoreTitle\">Checkout</h1>";
		$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" onclick=\"storeShowCart();\">Back to cart</button></div>\n";

		$o .= "<div class=\"ghotiStoreSummary\"><h2>Order summary</h2><ul>\n";
		foreach($lines as $line){
			$o .= "<li><span>".(int)$line['quantity']." &times; ".$this->esc($line['name']).(!empty($line['serviceTerm']) ? ' <small>'.$this->esc($line['serviceTerm']).'</small>' : '')."</span><span>".$this->money($line['lineCents'], $totals['currency'])."</span></li>\n";
		}
		$o .= $this->renderDiscount($totals, true);
		if($totals['hasPhysical']){
			$o .= "<li><span>Shipping".($settings['shippingNote'] !== '' ? ' &mdash; '.$this->esc($settings['shippingNote']) : '')."</span><span>".$this->money($totals['shippingCents'], $totals['currency'])."</span></li>\n";
		}
		$o .= "<li class=\"ghotiStoreGrand\"><span>Total</span><span>".$this->money($totals['totalCents'], $totals['currency'])."</span></li>\n";
		$o .= "</ul></div>\n";

		$o .= $this->renderRewards($totals);
		$o .= "<form id=\"ghotiStoreCheckoutForm\" class=\"ghotiForm\" action=\"#\" onsubmit=\"return false;\">\n";
		$o .= "<div class=\"ghotiFormGrid\">\n";
		$o .= "<label class=\"ghotiField\"><span>Your name</span><input type=\"text\" id=\"storeName\" maxlength=\"120\" autocomplete=\"name\" required=\"required\" /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>E-mail <i>(for the receipt)</i></span><input type=\"email\" id=\"storeEmail\" maxlength=\"190\" autocomplete=\"email\" required=\"required\" /></label>\n";
		$o .= "</div>\n";
		if($totals['hasService']){
			$o .= '<fieldset class="ghotiStoreFieldset"><legend>Service setup</legend>';
			foreach($lines as $line){
				if($line['kind'] !== 'service'){ continue; }
				$prompt = $line['servicePrompt'] !== '' ? $line['servicePrompt'] : 'Anything we should know before setting up '.$line['name'].'?';
				$o .= '<label class="ghotiField ghotiFieldWide"><span>'.$this->esc($prompt).(!empty($line['serviceRequired']) ? '' : ' <i>(optional)</i>').'</span><textarea data-store-service="'.(int)$line['productId'].'" rows="3" maxlength="500"'.(!empty($line['serviceRequired']) ? ' required="required"' : '').'></textarea></label>';
			}
			$o .= '<p class="ghotiHelpText">These details are sent with your order so we can provision your service.</p></fieldset>';
		}

		if($totals['hasPhysical']){
			$o .= "<fieldset class=\"ghotiStoreFieldset\"><legend>Shipping address</legend>\n<div class=\"ghotiFormGrid\">\n";
			$o .= "<label class=\"ghotiField\"><span>Address</span><input type=\"text\" id=\"storeAddress1\" maxlength=\"190\" autocomplete=\"address-line1\" required=\"required\" /></label>\n";
			$o .= "<label class=\"ghotiField\"><span>Address line 2 <i>(optional)</i></span><input type=\"text\" id=\"storeAddress2\" maxlength=\"190\" autocomplete=\"address-line2\" /></label>\n";
			$o .= "<label class=\"ghotiField\"><span>City</span><input type=\"text\" id=\"storeCity\" maxlength=\"120\" autocomplete=\"address-level2\" required=\"required\" /></label>\n";
			$o .= "<label class=\"ghotiField\"><span>Province or state</span><input type=\"text\" id=\"storeRegion\" maxlength=\"120\" autocomplete=\"address-level1\" /></label>\n";
			$o .= "<label class=\"ghotiField\"><span>Postal code</span><input type=\"text\" id=\"storePostcode\" maxlength=\"32\" autocomplete=\"postal-code\" required=\"required\" /></label>\n";
			$o .= "<label class=\"ghotiField\"><span>Country <i>(two letters)</i></span><input type=\"text\" id=\"storeCountry\" maxlength=\"2\" autocomplete=\"country\" placeholder=\"CA\" required=\"required\" /></label>\n";
			$o .= "</div></fieldset>\n";
		}
		$o .= "<label class=\"ghotiField ghotiFieldWide\"><span>Order note <i>(optional)</i></span><textarea id=\"storeNote\" rows=\"3\" maxlength=\"500\"></textarea></label>\n";
		$o .= "</form>\n";

		$paypalReady = StorePaypalClient::configured($settings);
		$cryptoReady = StoreCryptoClient::configured($settings);
		$stripeReady = StoreStripeClient::configured($settings);
		$squareReady = StoreSquareClient::configured($settings);
		$o .= '<section class="ghotiStorePaymentMethods"><h2>Payment method</h2>';
		$o .= "<div id=\"ghotiStorePayStatus\" class=\"ghotiStoreStatus\" role=\"status\" aria-live=\"polite\"></div>\n";
		if($paypalReady){
			$o .= '<div class="ghotiStorePaymentOption"><h3>PayPal or card</h3><div id="ghotiStorePaypal" class="ghotiStorePaypal"></div><p class="ghotiStoreHelpText">Card details stay with PayPal.</p></div>';
		}
		if($stripeReady){
			$o .= '<div class="ghotiStorePaymentOption"><h3>Card with Stripe</h3><button type="button" class="ghotiButton" onclick="storeBeginStripePayment(this);">Continue with Stripe</button><p class="ghotiStoreHelpText">Stripe Elements securely collects and authenticates your card.</p></div>';
		}
		if($squareReady){
			$o .= '<div class="ghotiStorePaymentOption" id="ghotiStoreSquare" data-app-id="'.$this->esc($settings['squareApplicationId']).'" data-location-id="'.$this->esc($settings['squareLocationId']).'" data-env="'.$this->esc($settings['squareEnv']).'" data-amount="'.$this->esc(StorePaypalClient::amount($totals['totalCents'])).'" data-currency="'.$this->esc($totals['currency']).'"><h3>Card with Square</h3><div id="ghotiStoreSquareCard"></div><button type="button" id="ghotiStoreSquareButton" class="ghotiButton" onclick="storePayWithSquare(this);" disabled="disabled">Pay with Square</button><p class="ghotiStoreHelpText">Square securely tokenizes the card before this site creates the payment.</p></div>';
		}
		if($cryptoReady){
			$o .= '<div class="ghotiStorePaymentOption"><h3>Cryptocurrency</h3><label class="ghotiField"><span>Pay with</span><select id="storeCryptoCurrency">';
			foreach(StoreCryptoClient::currencyList($settings['cryptoCurrencies']) as $currency){
				$o .= '<option value="'.$this->esc($currency).'">'.$this->esc(strtoupper($currency)).'</option>';
			}
			$o .= '</select></label><button type="button" class="ghotiButton" onclick="storeBeginCryptoPayment(this);">Create crypto payment</button>';
			$o .= '<p class="ghotiStoreHelpText">A deposit address and exact amount are created by NOWPayments. The order is fulfilled only after the provider confirms it.</p></div>';
		}
		if(!$paypalReady && !$cryptoReady && !$stripeReady && !$squareReady){ $o .= '<p class="ghotiStoreStatus is-error">No payment method is configured. Contact the store before placing this order.</p>'; }
		$o .= '</section>';
		$o .= "</div>\n";
			return $o;
		}

	public function renderCryptoPayment($order){
		$status = strtolower((string)($order['cryptoStatus'] ?? 'waiting'));
		$terminal = in_array($status, array('failed','refunded','expired'), true) || ($order['status'] ?? '') !== 'pending';
		$labels = array('waiting'=>'Waiting for payment', 'confirming'=>'Confirming on the network', 'confirmed'=>'Payment confirmed',
			'sending'=>'Sending to merchant', 'finished'=>'Payment complete', 'partially_paid'=>'Partially paid',
			'failed'=>'Payment failed', 'refunded'=>'Payment refunded', 'expired'=>'Payment expired');
		$label = $labels[$status] ?? ucwords(str_replace('_', ' ', $status));
		$o = '<div id="ghotiStoreCryptoPayment" class="ghotiStore ghotiStoreCryptoPayment" data-order-id="'.(int)$order['orderId'].'" data-final="'.($terminal ? '1' : '0').'">';
		$o .= '<div class="ghotiStoreBar"><div><p class="ghotiStoreEyebrow">CRYPTO PAYMENT</p><h1 class="ghotiStoreTitle">Send payment for '.$this->esc($order['reference']).'</h1></div><button type="button" class="ghotiButton ghotiButtonCompact ghotiButtonSecondary" onclick="storeShowCart();">Back to cart</button></div>';
		$o .= '<p class="ghotiStoreLead">Send the exact amount using the selected currency and network. This page checks the provider for confirmations automatically.</p>';
		$o .= '<dl class="ghotiStoreCryptoDetails">';
		$o .= '<div><dt>Amount</dt><dd><code id="storeCryptoAmount">'.$this->esc($order['cryptoAmount']).'</code> <strong>'.$this->esc(strtoupper($order['cryptoCurrency'])).'</strong><button type="button" class="ghotiTextButton" onclick="storeCopyCrypto(&quot;storeCryptoAmount&quot;, this);">Copy</button></dd></div>';
		if($order['cryptoNetwork'] !== ''){ $o .= '<div><dt>Network</dt><dd>'.$this->esc($order['cryptoNetwork']).'</dd></div>'; }
		$o .= '<div><dt>Deposit address</dt><dd><code id="storeCryptoAddress">'.$this->esc($order['cryptoAddress']).'</code><button type="button" class="ghotiTextButton" onclick="storeCopyCrypto(&quot;storeCryptoAddress&quot;, this);">Copy</button></dd></div>';
		if($order['cryptoExtraId'] !== ''){ $o .= '<div><dt>Memo / tag</dt><dd><code id="storeCryptoExtra">'.$this->esc($order['cryptoExtraId']).'</code><button type="button" class="ghotiTextButton" onclick="storeCopyCrypto(&quot;storeCryptoExtra&quot;, this);">Copy</button></dd></div>'; }
		$o .= '<div><dt>Provider reference</dt><dd><code>'.$this->esc($order['cryptoPaymentId']).'</code></dd></div>';
		if((int)$order['cryptoExpiresAt'] > 0){ $o .= '<div><dt>Quote expires</dt><dd>'.$this->esc(date('M j, Y H:i T', (int)$order['cryptoExpiresAt'])).'</dd></div>'; }
		$o .= '</dl>';
		$o .= '<p class="ghotiStoreCryptoState ghotiStoreBadge-'.$this->esc($status).'"><strong>'.$this->esc($label).'</strong>';
		if($status === 'partially_paid'){ $o .= ' — the received amount is short. Do not send a different currency; refresh after completing the exact payment.'; }
		elseif($terminal){ $o .= ' — do not send funds to this payment address.'; }
		else{ $o .= ' — network confirmation can take a few minutes.'; }
		$o .= '</p><div id="ghotiStorePayStatus" class="ghotiStoreStatus" role="status" aria-live="polite"></div>';
		if(!$terminal){ $o .= '<button type="button" class="ghotiButton" onclick="storeRefreshCryptoPayment('.(int)$order['orderId'].', this);">Check payment now</button>'; }
		$o .= '<p class="ghotiStoreHelpText">Only send '.$this->esc(strtoupper($order['cryptoCurrency'])).($order['cryptoNetwork'] !== '' ? ' on '.$this->esc($order['cryptoNetwork']) : '').'. Cryptocurrency transfers cannot be reversed.</p></div>';
		return $o;
	}

	public function renderStripePayment($order){
		return '<div id="ghotiStoreStripePayment" class="ghotiStore" data-order-id="'.(int)$order['orderId'].'"><div class="ghotiStoreBar"><h1 class="ghotiStoreTitle">Pay order '.$this->esc($order['reference']).' with Stripe</h1><button type="button" class="ghotiButton ghotiButtonSecondary" onclick="storeShowCart();">Back to cart</button></div><p class="ghotiStoreLead">Enter your card in Stripe&rsquo;s secure payment form.</p><div class="ghotiStorePaymentOption"><div id="ghotiStoreStripeElement"></div><button type="button" id="ghotiStoreStripeButton" class="ghotiButton">Pay '.$this->money($order['totalCents'],$order['currency']).'</button></div><div id="ghotiStorePayStatus" class="ghotiStoreStatus" role="status" aria-live="polite"></div></div>';
	}

	public function renderProcessorPending($order){
		$provider=ucfirst((string)$order['paymentProvider']);
		return '<div class="ghotiStore" data-order-id="'.(int)$order['orderId'].'"><h1 class="ghotiStoreTitle">'.$this->esc($provider).' payment pending</h1><p class="ghotiStoreLead">Order '.$this->esc($order['reference']).' has provider status <strong>'.$this->esc($order['providerStatus'] ?: 'pending').'</strong>.</p><button type="button" class="ghotiButton" onclick="storeRefreshProcessorPayment('.(int)$order['orderId'].',this);">Check payment now</button><div id="ghotiStorePayStatus" class="ghotiStoreStatus" role="status" aria-live="polite"></div></div>';
	}

	public function renderSubscriptionCheckout($product, $settings){
		$o = '<div id="ghotiStoreSubscription" class="ghotiStore" data-product-id="'.(int)$product['productId'].'" data-plan-id="'.$this->esc($product['paypalPlanId']).'">';
		$o .= '<div class="ghotiStoreBar"><h1 class="ghotiStoreTitle">Subscribe to '.$this->esc($product['name']).'</h1><button type="button" class="ghotiButton ghotiButtonCompact ghotiButtonSecondary" onclick="showStore();">Back to the store</button></div>';
		$o .= '<div class="ghotiStoreSummary"><h2>Subscription summary</h2><ul><li><span>'.$this->esc($product['name']).'</span><span>'.$this->money($product['priceCents'], $settings['currency']).'</span></li>';
		if($product['serviceTerm'] !== ''){ $o .= '<li><span>Billing term</span><span>'.$this->esc($product['serviceTerm']).'</span></li>'; }
		$o .= '</ul><p class="ghotiHelpText">PayPal shows the authoritative recurring amount, billing interval, trial period, and cancellation terms before you approve.</p></div>';
		$o .= '<form id="ghotiStoreSubscriptionForm" class="ghotiForm" onsubmit="return false;"><div class="ghotiFormGrid">';
		$o .= '<label class="ghotiField"><span>Your name</span><input type="text" id="storeSubscriptionName" maxlength="120" autocomplete="name" required="required" /></label>';
		$o .= '<label class="ghotiField"><span>E-mail <i>(for confirmation)</i></span><input type="email" id="storeSubscriptionEmail" maxlength="190" autocomplete="email" required="required" /></label></div>';
		$prompt = $product['servicePrompt'] !== '' ? $product['servicePrompt'] : 'Anything we should know before setting up this service?';
		$o .= '<label class="ghotiField ghotiFieldWide"><span>'.$this->esc($prompt).(!empty($product['serviceRequired']) ? '' : ' <i>(optional)</i>').'</span><textarea id="storeSubscriptionDetails" rows="3" maxlength="500"'.(!empty($product['serviceRequired']) ? ' required="required"' : '').'></textarea></label></form>';
		$o .= '<div id="ghotiStorePayStatus" class="ghotiStoreStatus" role="status" aria-live="polite"></div><div id="ghotiStoreSubscriptionPaypal" class="ghotiStorePaypal"></div>';
		$o .= '<p class="ghotiStoreHelpText">Your recurring payment is managed by PayPal. Card details never reach this site.</p></div>';
		return $o;
	}

	public function renderSubscriptionReceipt($subscription){
		$o = '<div class="ghotiStore"><h1 class="ghotiStoreTitle">Subscription confirmed</h1>';
		$o .= '<p class="ghotiStoreLead">Thank you, '.$this->esc($subscription['customerName']).'. We will contact you to provision <strong>'.$this->esc($subscription['name']).'</strong>.</p>';
		$o .= '<dl class="ghotiStoreDetailGrid"><div><dt>PayPal reference</dt><dd><code>'.$this->esc($subscription['paypalSubscriptionId']).'</code></dd></div>';
		$o .= '<div><dt>Status</dt><dd>'.$this->esc(ucwords(strtolower(str_replace('_', ' ', $subscription['status'])))).'</dd></div>';
		if($subscription['serviceTerm'] !== ''){ $o .= '<div><dt>Billing</dt><dd>'.$this->esc($subscription['serviceTerm']).'</dd></div>'; }
		if($subscription['nextBillingAt'] > 0){ $o .= '<div><dt>Next PayPal billing</dt><dd>'.$this->esc(date('M j, Y', $subscription['nextBillingAt'])).'</dd></div>'; }
		if($subscription['serviceDetails'] !== ''){ $o .= '<div><dt>Setup details</dt><dd>'.nl2br($this->esc($subscription['serviceDetails'])).'</dd></div>'; }
		$o .= '</dl><p class="ghotiStoreHelpText">A confirmation is being sent to '.$this->esc($subscription['email']).'. Manage payment or cancellation from your PayPal account.</p>';
		$o .= '<div class="ghotiStoreActions"><button type="button" class="ghotiButton ghotiButtonSecondary" onclick="showStore();">Back to the store</button></div></div>';
		return $o;
	}

	public function renderSubscriptionRecorded($paypalSubscriptionId){
		return '<div class="ghotiStore"><h1 class="ghotiStoreTitle">Subscription already confirmed</h1>'
			.'<p class="ghotiStoreLead">PayPal subscription <strong>'.$this->esc($paypalSubscriptionId).'</strong> is already recorded. Check your confirmation e-mail or contact us with this reference.</p>'
			.'<div class="ghotiStoreActions"><button type="button" class="ghotiButton ghotiButtonSecondary" onclick="showStore();">Back to the store</button></div></div>';
	}

	public function renderReceipt($order, $items, $downloads){
		$o  = "<div id=\"ghotiStoreReceipt\" class=\"ghotiStore\">\n";
		$o .= "<h1 class=\"ghotiStoreTitle\">Thank you</h1>\n";
		$o .= "<p class=\"ghotiStoreLead\">Order <strong>".$this->esc($order['reference'])."</strong> is paid. A receipt is on its way to ".$this->esc($order['email']).".</p>\n";

		$o .= "<table class=\"ghotiStoreTable\"><thead><tr><th>Item</th><th>Qty</th><th>Line</th></tr></thead><tbody>\n";
		foreach($items as $item){
			$detail = !empty($item['serviceTerm']) ? '<br /><small>'.$this->esc($item['serviceTerm']).'</small>' : '';
			if(!empty($item['serviceDetails'])){ $detail .= '<br /><small>Setup: '.nl2br($this->esc($item['serviceDetails'])).'</small>'; }
			$o .= "<tr><td>".$this->esc($item['name']).$detail."</td><td>".(int)$item['quantity']."</td><td>".$this->money($item['unitCents'] * $item['quantity'], $order['currency'])."</td></tr>\n";
		}
		$o .= "</tbody></table>\n";
		$o .= "<dl class=\"ghotiStoreTotals\">\n";
		$o .= "<div><dt>Subtotal</dt><dd>".$this->money($order['subtotalCents'], $order['currency'])."</dd></div>\n";
		$o .= $this->renderDiscount($order);
		if($order['hasPhysical']){
			$o .= "<div><dt>Shipping</dt><dd>".$this->money($order['shippingCents'], $order['currency'])."</dd></div>\n";
		}
		$o .= "<div class=\"ghotiStoreGrand\"><dt>Paid</dt><dd>".$this->money($order['totalCents'], $order['currency'])."</dd></div>\n";
		$o .= "</dl>\n";

		if(!empty($order['loyaltyPoints'])){ $o .= '<p class="ghotiStoreBenefit">Member points earned: '.(int)$order['loyaltyPoints'].'</p>'; }
		if($downloads){
			$o .= "<div class=\"ghotiStoreDownloads\"><h2>Your downloads</h2><ul>\n";
			foreach($downloads as $grant){
				$o .= "<li><a href=\"mod/store/store.download.php?token=".$this->esc($grant['token'])."\">".$this->esc($grant['name'])."</a>";
				$o .= " <small>".(int)$grant['maxDownloads']." downloads, until ".$this->esc(date('M j, Y H:i T', (int)$grant['expiresAt']))."</small></li>\n";
			}
			$o .= "</ul><p class=\"ghotiStoreHelpText\">These links are in your receipt e-mail as well.</p></div>\n";
		}
		if($order['hasPhysical']){
			$o .= "<p class=\"ghotiStoreHelpText\">We will post your order to ".$this->esc(trim($order['address1'].', '.$order['city'].' '.$order['postcode'].' '.$order['country']))."</p>\n";
		}
		if(!empty($order['hasService'])){
			$o .= '<p class="ghotiStoreHelpText">We have your setup details and will contact you when your service is ready.</p>';
		}
		$o .= "<div class=\"ghotiStoreActions\"><button type=\"button\" class=\"ghotiButton ghotiButtonSecondary\" onclick=\"showStore();\">Back to the store</button></div>\n";
		$o .= "</div>\n";
		return $o;
	}

	//Plain-text receipt for e-mail. Kept next to the HTML one so the two say the
	//same thing; no markup, because this is a mail body, not a page.
	public function receiptText($order, $items, $downloads){
		$lines = array();
		$lines[] = 'Order '.$order['reference'];
		$lines[] = 'Placed: '.gmdate('Y-m-d H:i', (int)$order['createdAt']).' UTC';
		$lines[] = '';
		foreach($items as $item){
			$line = $item['quantity'].' x '.$item['name'].'  '.$order['currency'].' '.StorePaypalClient::amount($item['unitCents'] * $item['quantity']);
			if(!empty($item['serviceTerm'])){ $line .= ' ('.$item['serviceTerm'].')'; }
			$lines[] = $line;
			if(!empty($item['serviceDetails'])){ $lines[] = '  Setup: '.$item['serviceDetails']; }
		}
		$lines[] = '';
		if(!empty($order['discountCents'])){ $lines[] = 'Discount ('.$order['discountLabel'].'): -'.$order['currency'].' '.StorePaypalClient::amount($order['discountCents']); }
		if(!empty($order['loyaltyPoints'])){ $lines[] = 'Member points earned: '.(int)$order['loyaltyPoints']; }
		$lines[] = 'Subtotal: '.$order['currency'].' '.StorePaypalClient::amount($order['subtotalCents']);
		if($order['hasPhysical']){
			$lines[] = 'Shipping: '.$order['currency'].' '.StorePaypalClient::amount($order['shippingCents']);
			$lines[] = 'Ship to: '.trim($order['customerName'].', '.$order['address1'].' '.$order['address2'].', '.$order['city'].' '.$order['region'].' '.$order['postcode'].' '.$order['country']);
		}
		$lines[] = 'Total paid: '.$order['currency'].' '.StorePaypalClient::amount($order['totalCents']);
		if($order['note'] !== ''){
			$lines[] = '';
			$lines[] = 'Your note: '.$order['note'];
		}
		if($downloads){
			$lines[] = '';
			$lines[] = 'Downloads (each link expires):';
			foreach($downloads as $grant){
				$lines[] = '  '.$grant['name'].': '.storeAbsoluteUrl('mod/store/store.download.php?token='.$grant['token']);
			}
		}
		if(!empty($order['hasService'])){
			$lines[] = '';
			$lines[] = 'We will contact you when your service is ready.';
		}
		$lines[] = '';
		$lines[] = 'Questions? Reply to this message quoting '.$order['reference'].'.';
		//CR/LF from customer input would let a note forge mail headers; the mail
		//module builds headers separately, but keep the body single-purpose too.
		return preg_replace('/[\r\n]+/', "\n", implode("\n", $lines))."\n";
	}

	/* ---------------- admin ---------------- */

	public function renderManager($tab){
		$settings = storeDb()->getSettings();
		$o  = "<section id=\"ghotiStoreManager\" class=\"ghotiAdminPanel\">\n";
		$o .= "<div class=\"ghotiCrudHeader\"><div><h1>Store</h1><p class=\"ghotiHelpText\">Catalogue, orders, subscriptions, and payment providers. Put a shop on any page with <code>[store:all]</code>.</p></div></div>\n";

		$o .= "<div class=\"ghotiStoreTabs\" aria-label=\"Store management\">\n";
		foreach(array('products' => 'Products', 'orders' => 'Orders', 'subscriptions' => 'Subscriptions', 'promotions' => 'Promotions &amp; loyalty', 'settings' => 'Payments &amp; shipping', 'dropship' => 'Dropshipping') as $key => $label){
			$active = $tab === $key;
			$o .= "<button type=\"button\" aria-pressed=\"".($active ? 'true' : 'false')."\" class=\"ghotiStoreTab".($active ? " is-active" : "")."\" onclick=\"showStoreManager('".$key."');\">".$label."</button>\n";
		}
		$o .= "</div>\n";

		if($tab === 'products'){ $o .= $this->renderProductAdmin($settings); }
		elseif($tab === 'promotions'){ $o .= $this->renderPromotionsAdmin($settings); }
		elseif($tab === 'orders'){ $o .= $this->renderOrderAdmin(); }
		elseif($tab === 'subscriptions'){ $o .= $this->renderSubscriptionAdmin(); }
		elseif($tab === 'dropship'){ $o .= $this->renderDropshipAdmin($settings); }
		else { $o .= $this->renderSettingsAdmin($settings); }

		$o .= ghoti_docs_panel("How to run the store", "catalogue, payments, fulfilment", array(
			array('heading' => 'Put the shop on a page',
				'list' => array('Edit any page and add <code class="ghotiDocCode">[store:all]</code> for everything, or <code class="ghotiDocCode">[store:prints]</code> for one category.', 'The cart, checkout and receipt all render in place; no extra pages are needed.')),
			array('heading' => 'Connect PayPal',
				'list' => array('Create REST API credentials in the PayPal Developer dashboard and paste the client ID and secret under <b>Payments &amp; shipping</b>.', 'Leave the environment on <b>Sandbox</b> and buy something from yourself with a sandbox account first. Switch to <b>Live</b> only once that works.', 'The secret is stored in the database and is never sent to the browser. Re-saving with the secret box empty keeps the stored one.')),
			array('heading' => 'Connect Stripe or Square',
				'list' => array('For Stripe, save the matching publishable and secret key pair. Stripe Elements collects the card and the server verifies the PaymentIntent.', 'For Square, save the application ID, location ID, access token, and matching Sandbox or Live environment. Square Web Payments tokenizes the card before the server calls Payments API.', 'Card numbers and security codes are handled inside the provider SDKs and never pass through this site.')),
			array('heading' => 'Accept Bitcoin and other crypto',
				'list' => array('Create a NOWPayments API key, save it under <b>Payments &amp; shipping</b>, and enable crypto checkout.', 'List the currency codes you accept, such as <code>btc, eth, ltc, usdc</code>. The provider creates the address and exact amount for each order.', 'The store checks provider status repeatedly and only fulfils a payment after its order reference, fiat amount, currency, and crypto asset all match.')),
			array('heading' => 'Products and services',
				'list' => array('A <b>physical</b> item makes checkout ask for a shipping address and adds the flat shipping rate once per order.', 'A <b>digital</b> item needs a file under <code>files/store/</code>; buyers get an expiring link.', 'A <b>service</b> can be a one-time purchase or a recurring PayPal subscription. Add a setup question when you need a domain, account name, or migration details.')),
			array('heading' => 'Fulfilment',
				'list' => array('Orders appear under <b>Orders</b> as soon as payment is confirmed by the selected provider; every administrator is e-mailed.', 'Mark a paid order <b>Shipped</b> once it is posted. <b>Paid</b> can never be set by hand - only a verified provider response sets it.', 'A <b>pending</b> row is a checkout that has not been confirmed. Crypto orders have a refresh action for delayed network confirmation.'))
		));
		$o .= "</section>\n";
		return $o;
	}

	private function renderProductAdmin($settings){
		$products = storeDb()->getProducts('all', true);
		$live = count(array_filter($products, function($product){ return !empty($product['active']); }));
		$featured = count(array_filter($products, function($product){ return !empty($product['featured']); }));
		$spring = count(array_filter($products, function($product){ return ($product['fulfilment'] ?? '') === 'spring'; }));
		$services = count(array_filter($products, function($product){ return ($product['kind'] ?? '') === 'service'; }));
		$o = '<div class="ghotiStoreStats">';
		foreach(array('Products' => count($products), 'Live in store' => $live, 'Services' => $services, 'Spring listings' => $spring) as $label => $value){
			$o .= '<div><span>'.$label.'</span><strong>'.$value.'</strong></div>';
		}
		$o .= '</div>';
		$o .= "<div class=\"ghotiStoreAdminHead\"><h2>Products</h2>";
		$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact\" onclick=\"storeEditProduct(0);\">Add product</button></div>\n";
		$o .= "<div id=\"ghotiStoreProductForm\" class=\"ghotiStoreProductForm\" hidden=\"hidden\"></div>\n";

		if(!$products){ $o .= '<p class="ghotiEmptyState">Start your collection with a physical product, digital download, service, or Spring listing.</p>'; }
		$o .= '<div class="ghotiStoreAdminSearch"><label>Find a product<input type="search" placeholder="Search name, SKU, category, or provider…" oninput="storeFilterProducts(this);" /></label><span id="storeAdminResults" role="status">'.count($products).' products</span></div>';
		$o .= "<div class=\"ghotiStoreAdminTableWrap\"><table class=\"ghotiStoreTable\"><thead><tr><th>Product</th><th>SKU</th><th>Kind</th><th>Fulfilled by</th><th>Category</th><th>Price</th><th>Live</th><th></th></tr></thead><tbody>\n";
		foreach($products as $product){
			$id = (int)$product['productId'];
			$o .= '<tr data-store-admin-product>';
			$o .= '<td><div class="ghotiStoreAdminProduct">';
			if($product['imageUrl'] !== ''){ $o .= '<img src="'.$this->esc($product['imageUrl']).'" alt="" loading="lazy" />'; }
			$o .= "<button type=\"button\" class=\"ghotiTextButton\" onclick=\"storeEditProduct($id);\">".$this->esc($product['name'])."</button></div></td>";
			$o .= "<td>".$this->esc($product['sku'])."</td>";
			$o .= "<td>".($product['kind'] === 'digital' ? 'Digital' : ($product['kind'] === 'service' ? (($product['billingType'] ?? '') === 'subscription' ? 'Subscription' : 'Service') : 'Physical'))."</td>";
			$o .= "<td>".(($product['fulfilment'] ?? '') === 'spring' ? 'Spring (hosted checkout)' : (($product['fulfilment'] ?? '') === 'dropship' ? $this->esc(StoreDropship::label($product['dropProvider'])) : 'You'))."</td>";
			$o .= "<td>".$this->esc($product['category'])."</td>";
			$o .= "<td>".$this->money($product['priceCents'], $settings['currency'])."</td>";
			$o .= "<td>".($product['active'] ? '<span class="ghotiStoreBadge ghotiStoreBadge-paid">Live</span>' : '<span class="ghotiStoreBadge">Hidden</span>')."</td>";
			$o .= '<td><div class="ghotiStoreRowActions"><button type="button" class="ghotiButton ghotiButtonCompact ghotiButtonSecondary" onclick="storeEditProduct('.$id.', true);">Duplicate</button>';
			$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonDanger\" onclick=\"storeDeleteProduct($id);\">Delete</button></div></td>";
			$o .= "</tr>\n";
		}
		$o .= "</tbody></table></div>\n";
		//The editor reads these, so quantities and prices never round-trip through
		//the page as text the admin might have half-edited.
		$o .= "<script type=\"application/json\" id=\"ghotiStoreProducts\">".json_encode($products, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)."</script>\n";
		//The supplier list and what each one calls its ids, so the product form
		//can label its fields without hard-coding any provider.
		$providers = array();
		foreach(StoreDropship::drivers() as $provider => $driver){
			$providers[$provider] = array('label' => $driver['label'], 'mapping' => $driver['mapping']);
		}
		$o .= "<script type=\"application/json\" id=\"ghotiStoreProviders\">".json_encode($providers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)."</script>\n";
		return $o;
	}

	private function renderOrderAdmin(){
		$orders = storeDb()->getOrders('all', 100);
		$summary = storeDb()->getSalesSummary(30);
		$settings = storeDb()->getSettings();
		$o  = "<div class=\"ghotiStoreAdminHead\"><h2>Orders</h2><span class=\"ghotiHelpText\">Last 30 days: ".(int)$summary['orders']." paid, ".$this->money($summary['totalCents'], $settings['currency'])."</span></div>\n";
		$o .= "<div id=\"ghotiStoreOrderDetail\" class=\"ghotiStoreOrderDetail\" hidden=\"hidden\"></div>\n";
		if(!$orders){
			$o .= "<p class=\"ghotiEmptyState\">No orders yet.</p>\n";
			return $o;
		}
		$o .= "<div class=\"ghotiStoreAdminTableWrap\"><table class=\"ghotiStoreTable\"><thead><tr><th>Reference</th><th>Placed</th><th>Customer</th><th>Total</th><th>Status</th><th></th></tr></thead><tbody>\n";
		foreach($orders as $order){
			$id = (int)$order['orderId'];
			$o .= "<tr class=\"ghotiStoreStatus-".$this->esc($order['status'])."\">";
			$o .= "<td><button type=\"button\" class=\"ghotiTextButton\" onclick=\"storeShowOrder($id);\">".$this->esc($order['reference'])."</button></td>";
			$o .= "<td>".$this->esc(date('M j, Y H:i', (int)$order['createdAt']))."</td>";
			$o .= "<td>".$this->esc($order['customerName'])."<br /><small>".$this->esc($order['email'])."</small></td>";
			$o .= "<td>".$this->money($order['totalCents'], $order['currency'])."</td>";
			$o .= "<td><span class=\"ghotiStoreBadge ghotiStoreBadge-".$this->esc($order['status'])."\">".$this->esc(ucfirst($order['status']))."</span></td>";
			$o .= "<td>";
			if($order['status'] === 'paid' && $order['hasPhysical']){
				$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact\" onclick=\"storeSetOrderStatus($id,'shipped');\">Mark shipped</button>";
			}
			if($order['status'] === 'paid' && $order['hasService'] && $order['serviceStatus'] !== 'fulfilled'){
				$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact\" onclick=\"storeSetOrderServiceStatus($id,'fulfilled');\">Mark service ready</button>";
			}
			if($order['status'] === 'pending'){
				if(($order['paymentProvider'] ?? '') === 'crypto'){
					$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact\" onclick=\"storeRefreshCryptoOrder($id);\">Refresh payment</button>";
				}
				if(in_array($order['paymentProvider'] ?? '',array('stripe','square'),true) && $order['providerPaymentId'] !== ''){
					$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact\" onclick=\"storeRefreshProcessorOrder($id);\">Refresh payment</button>";
				}
				$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" onclick=\"storeSetOrderStatus($id,'cancelled');\">Cancel</button>";
			}
			$o .= "</td></tr>\n";
		}
		$o .= "</tbody></table></div>\n";
		return $o;
	}

	private function renderSubscriptionAdmin(){
		$subscriptions = storeDb()->getSubscriptions(100);
		$active = count(array_filter($subscriptions, function($row){ return in_array($row['status'], array('ACTIVE', 'APPROVED'), true); }));
		$pending = count(array_filter($subscriptions, function($row){ return $row['serviceStatus'] !== 'fulfilled'; }));
		$o = '<div class="ghotiStoreStats"><div><span>Subscriptions</span><strong>'.count($subscriptions).'</strong></div><div><span>Active / approved</span><strong>'.$active.'</strong></div><div><span>Awaiting setup</span><strong>'.$pending.'</strong></div></div>';
		$o .= '<div class="ghotiStoreAdminHead"><h2>Subscriptions</h2><span class="ghotiHelpText">PayPal is authoritative for billing; refresh a row after a customer changes or cancels it.</span></div>';
		if(!$subscriptions){ return $o.'<p class="ghotiEmptyState">No subscriptions yet.</p>'; }
		$o .= '<div class="ghotiStoreAdminTableWrap"><table class="ghotiStoreTable"><thead><tr><th>Service</th><th>Customer</th><th>PayPal reference</th><th>Billing</th><th>Status</th><th>Setup</th><th></th></tr></thead><tbody>';
		foreach($subscriptions as $row){
			$id = (int)$row['subscriptionId'];
			$o .= '<tr><td><strong>'.$this->esc($row['name']).'</strong><br /><small>'.$this->esc($row['sku']).'</small></td>';
			$o .= '<td>'.$this->esc($row['customerName']).'<br /><small>'.$this->esc($row['email']).'</small>'.($row['serviceDetails'] !== '' ? '<br /><small>'.$this->esc($row['serviceDetails']).'</small>' : '').'</td>';
			$o .= '<td><code>'.$this->esc($row['paypalSubscriptionId']).'</code></td><td>'.$this->esc($row['serviceTerm']).($row['nextBillingAt'] > 0 ? '<br /><small>Next: '.$this->esc(date('M j, Y', $row['nextBillingAt'])).'</small>' : '').'</td>';
			$statusClass = strtolower($row['status']);
			$statusLabel = ucwords(strtolower(str_replace('_', ' ', $row['status'])));
			$o .= '<td><span class="ghotiStoreBadge ghotiStoreBadge-'.$this->esc($statusClass).'">'.$this->esc($statusLabel).'</span></td>';
			$o .= '<td>'.$this->esc(ucfirst($row['serviceStatus'])).'</td><td><div class="ghotiStoreRowActions"><button type="button" class="ghotiButton ghotiButtonCompact ghotiButtonSecondary" onclick="storeRefreshSubscription('.$id.');">Refresh</button>';
			if($row['serviceStatus'] !== 'fulfilled'){ $o .= '<button type="button" class="ghotiButton ghotiButtonCompact" onclick="storeSetSubscriptionServiceStatus('.$id.',\'fulfilled\');">Mark ready</button>'; }
			else { $o .= '<button type="button" class="ghotiButton ghotiButtonCompact ghotiButtonSecondary" onclick="storeSetSubscriptionServiceStatus('.$id.',\'pending\');">Reopen setup</button>'; }
			$o .= '</div></td></tr>';
		}
		return $o.'</tbody></table></div>';
	}

	private function renderPromotionsAdmin($settings){
		$config = array_merge(storeCommerceDefaults(), $settings['commerceConfig'] ?? array());
		$o = '<h2>Promotions &amp; loyalty</h2><p>Reward repeat customers and make every offer clear. These rules apply to local checkout; Spring purchases are separate.</p>';
		$o .= '<form class="ghotiForm" onsubmit="storeSavePromotions(this); return false;"><fieldset class="ghotiStoreFieldset"><legend>Shipping &amp; member rewards</legend><div class="ghotiFormGrid">';
		$fields = array('freeShipping' => array('Free shipping from (0 disables)', StorePaypalClient::amount($config['freeShippingCents']), 'text', ''),
			'pointsPerUnit' => array('Points per currency unit spent (0 disables)', $config['pointsPerUnit'], 'number', 'min="0" max="100"'),
			'loyaltyThreshold' => array('Points needed for member discount', $config['loyaltyThreshold'], 'number', 'min="1" max="1000000"'),
			'loyaltyPercent' => array('Member discount (%)', $config['loyaltyPercent'], 'number', 'min="1" max="50"'));
		foreach($fields as $name => $field){
			$o .= '<label class="ghotiField"><span>'.$field[0].'</span><input name="'.$name.'" type="'.$field[2].'" value="'.$this->esc($field[1]).'" '.$field[3].' required /></label>';
		}
		$o .= '</div><p class="ghotiHelpText">Members earn points on paid merchandise after discounts, excluding shipping. Points unlock an ongoing discount; they are not spent. Cancelled orders do not count. The better of a code or member reward applies. Free shipping uses the merchandise total after discounts.</p></fieldset>';
		$o .= '<h3>Discount codes</h3><p class="ghotiHelpText">Reusable codes, up to 50. Dates are inclusive in UTC; blank dates have no limit. Percentage discounts are capped at 90%. Offers always leave at least one cent payable. Remove or disable a code to stop new checkouts using it; existing pending orders keep their quote.</p><div data-store-coupons>';
		foreach($config['coupons'] as $coupon){ $o .= $this->renderCouponEditor($coupon); }
		$o .= '</div><template id="storeCouponTemplate">'.$this->renderCouponEditor(array()).'</template>';
		$o .= '<div class="ghotiFormActions"><button class="ghotiButton ghotiButtonSecondary" type="button" onclick="storeAddCoupon(this);">Add discount code</button><button class="ghotiButton" type="submit">Save promotions</button></div></form>';
		return $o;
	}

	private function renderCouponEditor($coupon){
		$o = '<fieldset class="ghotiStoreFieldset" data-store-coupon><legend>Discount code</legend><div class="ghotiFormGrid">';
		$fields = array('code' => array('Code', $coupon['code'] ?? '', 'text', 'maxlength="32" pattern="[A-Za-z0-9-]+" required'),
			'value' => array('Discount amount or percentage', isset($coupon['value']) ? ($coupon['type'] === 'fixed' ? StorePaypalClient::amount($coupon['value']) : $coupon['value']) : '', 'text', 'required'),
			'minimum' => array('Minimum merchandise spend', StorePaypalClient::amount($coupon['minimumCents'] ?? 0), 'text', 'required'),
			'start' => array('Starts (UTC, optional)', $coupon['start'] ?? '', 'date', ''),
			'end' => array('Ends (UTC, optional)', $coupon['end'] ?? '', 'date', ''));
		foreach($fields as $name => $field){ $o .= '<label class="ghotiField"><span>'.$field[0].'</span><input data-coupon-field="'.$name.'" type="'.$field[2].'" value="'.$this->esc($field[1]).'" '.$field[3].' /></label>'; }
		$o .= '<label class="ghotiField"><span>Discount type</span><select data-coupon-field="type"><option value="percent">Percentage</option><option value="fixed"'.(($coupon['type'] ?? '') === 'fixed' ? ' selected' : '').'>Fixed amount</option></select></label></div>';
		$o .= '<label class="ghotiInlineChoice"><input type="checkbox" data-coupon-field="active"'.(!isset($coupon['active']) || $coupon['active'] ? ' checked' : '').' /> Active</label><button class="ghotiButton ghotiButtonSecondary" type="button" onclick="this.closest(\'[data-store-coupon]\').remove();">Remove code</button></fieldset>';
		return $o;
	}

	private function renderRewards($totals){
		$config = $totals['commerce'] ?? storeCommerceDefaults();
		$o = '';
		if($config['freeShippingCents'] > 0 && $totals['hasPhysical']){
			$remaining = $totals['freeShippingRemaining'];
			$o .= '<div class="ghotiStoreBenefit"><strong>'.($remaining > 0 ? $this->money($remaining, $totals['currency']).' away from free shipping' : 'You unlocked free shipping').'</strong><progress aria-label="Progress toward free shipping" max="'.(int)$config['freeShippingCents'].'" value="'.(int)max(0, $config['freeShippingCents'] - $remaining).'"></progress><small>Based on merchandise after discounts.</small></div>';
		}
		if($config['pointsPerUnit'] > 0){
			$o .= '<div class="ghotiStoreBenefit"><strong>Member rewards</strong>';
			if(!empty($totals['signedIn'])){
				$o .= '<span>'.(int)$totals['pointsBalance'].' points · Earn '.(int)$totals['loyaltyPoints'].' with this order</span>';
				$o .= '<small>'.($totals['pointsBalance'] >= $config['loyaltyThreshold'] ? 'Your '.$config['loyaltyPercent'].'% member discount is unlocked.' : max(0, $config['loyaltyThreshold'] - $totals['pointsBalance']).' more points unlock '.$config['loyaltyPercent'].'% off future orders.').'</small>';
			}else{
				$o .= '<span>Sign in before checkout to earn '.$config['pointsPerUnit'].' points per '.$this->esc($totals['currency']).' 1 spent on merchandise.</span><small>'.$config['loyaltyThreshold'].' points unlock '.$config['loyaltyPercent'].'% off future orders. Guest checkout is always welcome.</small>';
			}
			$o .= '<small>Points are not spent. The better of a code or member reward applies.</small></div>';
		}
		return $o === '' ? '' : '<aside class="ghotiStoreBenefits" aria-label="Shopping benefits">'.$o.'</aside>';
	}

	private function renderDiscount($totals, $list = false){
		if(empty($totals['discountCents'])){ return ''; }
		$label = 'Discount · '.$this->esc($totals['discountLabel']);
		$value = '−'.$this->money($totals['discountCents'], $totals['currency']);
		return $list ? '<li><span>'.$label.'</span><span>'.$value.'</span></li>' : '<div class="ghotiStoreSavings"><dt>'.$label.'</dt><dd>'.$value.'</dd></div>';
	}

	private function renderSettingsAdmin($settings){
		$hasSecret = $settings['paypalSecret'] !== '';
		$hasCryptoKey = ($settings['cryptoApiKey'] ?? '') !== '';
		$hasStripeSecret = ($settings['stripeSecretKey'] ?? '') !== '';
		$hasSquareToken = ($settings['squareAccessToken'] ?? '') !== '';
		$o  = "<div class=\"ghotiStoreAdminHead\"><h2>Payments &amp; shipping</h2></div>\n";
		$o .= "<form id=\"ghotiStoreSettingsForm\" class=\"ghotiForm\" action=\"#\" onsubmit=\"storeSaveSettings(); return false;\">\n";
		$o .= '<fieldset class="ghotiStoreFieldset"><legend>PayPal</legend>';
		$o .= "<div class=\"ghotiFormGrid\">\n";
		$o .= "<label class=\"ghotiField\"><span>PayPal client ID</span><input type=\"text\" id=\"store-clientId\" maxlength=\"255\" value=\"".$this->esc($settings['paypalClientId'])."\" autocomplete=\"off\" /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>PayPal secret".($hasSecret ? " <i>(saved &mdash; leave blank to keep)</i>" : "")."</span><input type=\"password\" id=\"store-secret\" maxlength=\"255\" value=\"\" autocomplete=\"new-password\" placeholder=\"".($hasSecret ? "••••••••" : "")."\" /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>Environment</span><select id=\"store-env\">";
		$o .= "<option value=\"sandbox\"".($settings['paypalEnv'] === 'sandbox' ? " selected=\"selected\"" : "").">Sandbox (test money)</option>";
		$o .= "<option value=\"live\"".($settings['paypalEnv'] === 'live' ? " selected=\"selected\"" : "").">Live (real money)</option>";
		$o .= "</select></label>\n</div></fieldset>\n";
		$o .= '<fieldset class="ghotiStoreFieldset"><legend>Stripe</legend><label class="ghotiInlineChoice"><input type="checkbox" id="store-stripeEnabled"'.(!empty($settings['stripeEnabled'])?' checked="checked"':'').' /> Offer card checkout through Stripe</label><div class="ghotiFormGrid">';
		$o .= '<label class="ghotiField"><span>Publishable key</span><input type="text" id="store-stripePublishableKey" maxlength="255" autocomplete="off" value="'.$this->esc($settings['stripePublishableKey'] ?? '').'" placeholder="pk_test_…" /></label>';
		$o .= '<label class="ghotiField"><span>Secret key'.($hasStripeSecret?' <i>(saved &mdash; leave blank to keep)</i>':'').'</span><input type="password" id="store-stripeSecretKey" maxlength="255" autocomplete="new-password" placeholder="'.($hasStripeSecret?'••••••••':'sk_test_…').'" /></label></div><p class="ghotiHelpText">Stripe Elements collects the card. The server creates and verifies a PaymentIntent; secret keys never reach the browser.</p></fieldset>';
		$o .= '<fieldset class="ghotiStoreFieldset"><legend>Square</legend><label class="ghotiInlineChoice"><input type="checkbox" id="store-squareEnabled"'.(!empty($settings['squareEnabled'])?' checked="checked"':'').' /> Offer card checkout through Square</label><div class="ghotiFormGrid">';
		$o .= '<label class="ghotiField"><span>Application ID</span><input type="text" id="store-squareApplicationId" maxlength="255" autocomplete="off" value="'.$this->esc($settings['squareApplicationId'] ?? '').'" /></label>';
		$o .= '<label class="ghotiField"><span>Location ID</span><input type="text" id="store-squareLocationId" maxlength="100" autocomplete="off" value="'.$this->esc($settings['squareLocationId'] ?? '').'" /></label>';
		$o .= '<label class="ghotiField"><span>Access token'.($hasSquareToken?' <i>(saved &mdash; leave blank to keep)</i>':'').'</span><input type="password" id="store-squareAccessToken" maxlength="255" autocomplete="new-password" placeholder="'.($hasSquareToken?'••••••••':'').'" /></label>';
		$o .= '<label class="ghotiField"><span>Environment</span><select id="store-squareEnv"><option value="sandbox"'.(($settings['squareEnv'] ?? 'sandbox')==='sandbox'?' selected="selected"':'').'>Sandbox</option><option value="live"'.(($settings['squareEnv'] ?? '')==='live'?' selected="selected"':'').'>Live</option></select></label></div><p class="ghotiHelpText">The application and location must belong to the same Square environment as the access token.</p></fieldset>';
		$o .= '<fieldset class="ghotiStoreFieldset"><legend>Bitcoin &amp; cryptocurrency</legend>';
		$o .= '<label class="ghotiInlineChoice"><input type="checkbox" id="store-cryptoEnabled"'.(!empty($settings['cryptoEnabled']) ? ' checked="checked"' : '').' /> Offer cryptocurrency checkout through NOWPayments</label>';
		$o .= '<div class="ghotiFormGrid">';
		$o .= '<label class="ghotiField"><span>NOWPayments API key'.($hasCryptoKey ? ' <i>(saved &mdash; leave blank to keep)</i>' : '').'</span><input type="password" id="store-cryptoApiKey" maxlength="255" value="" autocomplete="new-password" placeholder="'.($hasCryptoKey ? '••••••••' : '').'" /></label>';
		$o .= '<label class="ghotiField"><span>Accepted currencies <i>(codes separated by commas)</i></span><input type="text" id="store-cryptoCurrencies" maxlength="500" value="'.$this->esc($settings['cryptoCurrencies'] ?? 'btc,eth,ltc,usdc').'" placeholder="btc, eth, ltc, usdc" /></label>';
		$o .= '</div><p class="ghotiHelpText">Use currency codes enabled in your NOWPayments account. Bitcoin is <code>btc</code>; network-specific assets may use codes such as <code>usdttrc20</code>. Crypto is available for one-time orders; recurring services continue to use PayPal.</p></fieldset>';
		$o .= '<fieldset class="ghotiStoreFieldset"><legend>Store currency &amp; delivery</legend><div class="ghotiFormGrid">';
		$o .= "<label class=\"ghotiField\"><span>Currency <i>(3 letters)</i></span><input type=\"text\" id=\"store-currency\" maxlength=\"3\" value=\"".$this->esc($settings['currency'])."\" /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>Flat shipping <i>(per order with a physical item)</i></span><input type=\"text\" id=\"store-shipping\" maxlength=\"12\" value=\"".$this->esc(StorePaypalClient::amount($settings['shippingCents']))."\" /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>Shipping note <i>(optional)</i></span><input type=\"text\" id=\"store-shippingNote\" maxlength=\"255\" value=\"".$this->esc($settings['shippingNote'])."\" /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>Download window <i>(hours)</i></span><input type=\"number\" id=\"store-downloadHours\" min=\"1\" max=\"8760\" step=\"1\" value=\"".(int)$settings['downloadHours']."\" /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>Downloads per item</span><input type=\"number\" id=\"store-downloadLimit\" min=\"1\" max=\"100\" step=\"1\" value=\"".(int)$settings['downloadLimit']."\" /></label>\n";
		$o .= "</div></fieldset>\n";
		$o .= "<div class=\"ghotiFormActions\"><button type=\"submit\" class=\"ghotiButton\">Save store settings</button></div>\n";
		$o .= "</form>\n";
		$o .= "<p class=\"ghotiHelpText\">Payment secrets authenticate this site to its providers and are stored in the database, so they are in your backups &mdash; treat them accordingly. Keep each provider&rsquo;s test/sandbox and live credentials together, and enable only the methods you intend to show.</p>\n";
		return $o;
	}

	/*
	 * Dropshipping screen: the switch, the per-supplier credentials, and what is
	 * currently waiting to go out. Field definitions come from the driver
	 * registry, so a new supplier appears here without this method changing.
	 */
	private function renderDropshipAdmin($settings){
		$config = $settings['dropshipConfig'];
		$o  = "<div class=\"ghotiStoreAdminHead\"><h2>Dropshipping</h2></div>\n";
		$o .= "<p class=\"ghotiHelpText\">Mark a product as supplier-fulfilled under <b>Products</b>, with that supplier&rsquo;s variant id. A paid order is then queued here and sent automatically; nothing is ever sent during checkout, so a supplier being down cannot look like a failed payment.</p>\n";

		$o .= '<aside class="ghotiStoreSpringPanel"><span class="ghotiStoreEyebrow">HOSTED CHECKOUT</span><h3>Spring / Teespring</h3><p>Sell print-on-demand merchandise alongside your own products. Under Products, choose <b>Fulfilled by → Spring / Teespring</b> and paste the product’s Spring URL.</p><p>Customers choose sizes and colours, pay, and receive support on Spring. Spring handles production and delivery. These purchases do not appear in local orders or the supplier queue. No API credentials are needed.</p><a href="https://www.spri.ng/" target="_blank" rel="noopener noreferrer">Open Spring <span class="sr-only">(opens in a new tab)</span> ↗</a></aside>';

		$o .= "<form id=\"ghotiStoreDropshipForm\" class=\"ghotiForm\" action=\"#\" onsubmit=\"storeSaveDropship(); return false;\">\n";
		$o .= "<div class=\"siteSettingsChoices\">\n";
		$o .= "<label class=\"ghotiInlineChoice\"><input type=\"checkbox\" id=\"drop-enabled\"".($settings['dropshipEnabled'] ? " checked=\"checked\"" : "")." /> Route supplier-fulfilled orders to their supplier</label>\n";
		$o .= "<label class=\"ghotiInlineChoice\"><input type=\"checkbox\" id=\"drop-autoSubmit\"".($settings['dropshipAutoSubmit'] ? " checked=\"checked\"" : "")." /> Send automatically as soon as an order is paid</label>\n";
		$o .= "</div>\n";
		$o .= "<p class=\"ghotiHelpText\">With automatic sending off, paid orders wait in the queue below until you press <b>Send now</b>. Either way, run <code>php mod/store/store.fulfil.php</code> from cron so a queued order still goes out when nobody is looking, and tracking numbers come back.</p>\n";

		foreach(StoreDropship::drivers() as $provider => $driver){
			$saved = isset($config[$provider]) && is_array($config[$provider]) ? $config[$provider] : array();
			$ready = false;
			try{ $ready = StoreDropship::driver($provider, $saved)->configured(); }catch(Throwable $e){ $ready = false; }
			$o .= "<fieldset class=\"ghotiStoreFieldset\"><legend>".$this->esc($driver['label'])."";
			$o .= $ready ? " <span class=\"ghotiStoreBadge ghotiStoreBadge-paid\">Ready</span>" : " <span class=\"ghotiStoreBadge\">Not configured</span>";
			$o .= "</legend>\n";
			$o .= "<p class=\"ghotiHelpText\">".$this->esc($driver['help'])."</p>\n";
			$o .= "<div class=\"ghotiFormGrid\">\n";
			foreach($driver['fields'] as $field => $definition){
				$id = "drop-".$provider."-".$field;
				$label = $this->esc($definition['label']).(empty($definition['optional']) ? "" : " <i>(optional)</i>");
				if(!empty($definition['secret'])){
					$hasValue = isset($saved[$field]) && $saved[$field] !== '';
					$o .= "<label class=\"ghotiField\"><span>".$label.($hasValue ? " <i>(saved &mdash; leave blank to keep)</i>" : "")."</span>";
					$o .= "<input type=\"password\" id=\"".$id."\" value=\"\" autocomplete=\"new-password\" placeholder=\"".($hasValue ? "••••••••" : "")."\" /></label>\n";
				}else{
					$value = isset($saved[$field]) ? (string)$saved[$field] : '';
					$o .= "<label class=\"ghotiField\"><span>".$label."</span>";
					$o .= "<input type=\"text\" id=\"".$id."\" maxlength=\"500\" value=\"".$this->esc($value)."\" autocomplete=\"off\" /></label>\n";
				}
			}
			$o .= "</div></fieldset>\n";
		}
		$o .= "<div class=\"ghotiFormActions\"><button type=\"submit\" class=\"ghotiButton\">Save dropshipping settings</button></div>\n";
		$o .= "</form>\n";

		//The queue itself: what is waiting, what failed and why.
		$queued = storeDb()->getQueuedFulfilments(50);
		$o .= "<div class=\"ghotiStoreAdminHead\"><h3>Waiting to be sent</h3>";
		if($queued){
			$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact\" onclick=\"storeDrainQueue();\">Send now</button>";
		}
		$o .= "</div>\n";
		if(!$queued){
			$o .= "<p class=\"ghotiEmptyState\">Nothing is waiting. Failed submissions appear on the order itself.</p>\n";
		}else{
			$o .= "<div class=\"ghotiStoreAdminTableWrap\"><table class=\"ghotiStoreTable\"><thead><tr><th>Order</th><th>Supplier</th><th>Queued</th><th>Attempts</th><th>Last problem</th></tr></thead><tbody>\n";
			foreach($queued as $row){
				$order = storeDb()->getOrder($row['orderId']);
				$o .= "<tr><td>".$this->esc($order ? $order['reference'] : '#'.$row['orderId'])."</td>";
				$o .= "<td>".$this->esc(StoreDropship::label($row['provider']))."</td>";
				$o .= "<td>".$this->esc(date('M j, H:i', (int)$row['createdAt']))."</td>";
				$o .= "<td>".(int)$row['attempts']."</td>";
				$o .= "<td>".$this->esc($row['lastError'])."</td></tr>\n";
			}
			$o .= "</tbody></table></div>\n";
		}
		return $o;
	}

	//One supplier submission, as shown on an order.
	private function renderFulfilmentRow($fulfilment){
		$id = (int)$fulfilment['fulfilmentId'];
		$o  = "<li class=\"ghotiStoreFulfilment\">";
		$o .= "<span class=\"ghotiStoreBadge ghotiStoreBadge-".$this->esc($fulfilment['status'] === 'queued' ? 'pending' : $fulfilment['status'])."\">".$this->esc(ucfirst($fulfilment['status']))."</span> ";
		$o .= "<strong>".$this->esc(StoreDropship::label($fulfilment['provider']))."</strong>";
		if($fulfilment['providerOrderId'] !== ''){
			$o .= " <code>".$this->esc($fulfilment['providerOrderId'])."</code>";
		}
		if($fulfilment['trackingNumber'] !== ''){
			$o .= "<br /><span>Tracking: ";
			if($fulfilment['trackingUrl'] !== ''){
				$o .= "<a href=\"".ghoti_safe_url_attribute($fulfilment['trackingUrl'])."\" target=\"_blank\" rel=\"noopener noreferrer\">".$this->esc($fulfilment['trackingNumber'])."</a>";
			}else{
				$o .= $this->esc($fulfilment['trackingNumber']);
			}
			if($fulfilment['carrier'] !== ''){ $o .= " <small>".$this->esc($fulfilment['carrier'])."</small>"; }
			$o .= "</span>";
		}
		if($fulfilment['lastError'] !== ''){
			$o .= "<br /><span class=\"ghotiStoreFulfilmentError\">".$this->esc($fulfilment['lastError'])."</span>";
		}
		$o .= "<span class=\"ghotiStoreFulfilmentActions\">";
		if(in_array($fulfilment['status'], array('queued','failed','sending'), true)){
			$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact\" onclick=\"storeRetryFulfilment($id);\">Send now</button>";
		}
		if($fulfilment['providerOrderId'] !== ''){
			$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" onclick=\"storeRefreshFulfilment($id);\">Refresh tracking</button>";
		}
		$o .= "</span></li>\n";
		return $o;
	}

	public function renderOrderDetail($order, $items, $downloads){
		$o  = "<div class=\"ghotiStoreOrderCard\">\n";
		$o .= "<div class=\"ghotiStoreAdminHead\"><h3>Order ".$this->esc($order['reference'])."</h3>";
		$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" onclick=\"storeCloseOrder();\">Close</button></div>\n";
		$o .= "<dl class=\"ghotiStoreDetailGrid\">\n";
		$o .= "<div><dt>Status</dt><dd>".$this->esc(ucfirst($order['status']))."</dd></div>\n";
		$o .= "<div><dt>Placed</dt><dd>".$this->esc(date('M j, Y H:i T', (int)$order['createdAt']))."</dd></div>\n";
		if($order['paidAt'] > 0){ $o .= "<div><dt>Paid</dt><dd>".$this->esc(date('M j, Y H:i T', (int)$order['paidAt']))."</dd></div>\n"; }
		if($order['shippedAt'] > 0){ $o .= "<div><dt>Shipped</dt><dd>".$this->esc(date('M j, Y H:i T', (int)$order['shippedAt']))."</dd></div>\n"; }
		$o .= "<div><dt>Customer</dt><dd>".$this->esc($order['customerName'])."<br />".$this->esc($order['email'])."</dd></div>\n";
		$paymentLabels=array('paypal'=>'PayPal','crypto'=>'Cryptocurrency via NOWPayments','stripe'=>'Stripe','square'=>'Square');
		$o .= '<div><dt>Payment</dt><dd>'.$this->esc($paymentLabels[$order['paymentProvider'] ?? 'paypal'] ?? ucfirst($order['paymentProvider'])).'</dd></div>';
		if($order['payerEmail'] !== ''){ $o .= "<div><dt>PayPal payer</dt><dd>".$this->esc($order['payerEmail'])."</dd></div>\n"; }
		if($order['paypalCaptureId'] !== ''){ $o .= "<div><dt>Capture</dt><dd><code>".$this->esc($order['paypalCaptureId'])."</code></dd></div>\n"; }
		if(($order['paymentProvider'] ?? '') === 'crypto'){
			$o .= '<div><dt>Crypto status</dt><dd>'.$this->esc(ucwords(str_replace('_', ' ', $order['cryptoStatus']))).'</dd></div>';
			$o .= '<div><dt>Crypto payment</dt><dd><code>'.$this->esc($order['cryptoPaymentId']).'</code></dd></div>';
			$o .= '<div><dt>Requested</dt><dd>'.$this->esc($order['cryptoAmount']).' '.$this->esc(strtoupper($order['cryptoCurrency'])).($order['cryptoNetwork'] !== '' ? '<br /><small>'.$this->esc($order['cryptoNetwork']).'</small>' : '').'</dd></div>';
		}
		if(in_array($order['paymentProvider'] ?? '',array('stripe','square'),true)){
			$o .= '<div><dt>Provider status</dt><dd>'.$this->esc($order['providerStatus']).'</dd></div>';
			if($order['providerPaymentId']!==''){ $o .= '<div><dt>Provider payment</dt><dd><code>'.$this->esc($order['providerPaymentId']).'</code></dd></div>'; }
		}
		if($order['hasService']){
			$o .= '<div><dt>Service setup</dt><dd>'.$this->esc(ucfirst($order['serviceStatus'] ?: 'pending')).($order['serviceFulfilledAt'] > 0 ? '<br /><small>'.$this->esc(date('M j, Y H:i T', $order['serviceFulfilledAt'])).'</small>' : '').'</dd></div>';
		}
		if($order['hasPhysical']){
			$address = array_filter(array($order['address1'], $order['address2'], trim($order['city'].' '.$order['region'].' '.$order['postcode']), $order['country']));
			$o .= "<div><dt>Ship to</dt><dd>".$this->esc(implode(', ', $address))."</dd></div>\n";
		}
		if($order['note'] !== ''){ $o .= "<div><dt>Note</dt><dd>".$this->esc($order['note'])."</dd></div>\n"; }
		$o .= "</dl>\n";

		$o .= "<table class=\"ghotiStoreTable\"><thead><tr><th>Item</th><th>SKU</th><th>Qty</th><th>Unit</th><th>Line</th></tr></thead><tbody>\n";
		foreach($items as $item){
			$detail = !empty($item['serviceTerm']) ? '<br /><small>'.$this->esc($item['serviceTerm']).'</small>' : '';
			if(!empty($item['serviceDetails'])){ $detail .= '<br /><small>Setup: '.nl2br($this->esc($item['serviceDetails'])).'</small>'; }
			$o .= "<tr><td>".$this->esc($item['name']).$detail."</td><td>".$this->esc($item['sku'])."</td><td>".(int)$item['quantity']."</td>";
			$o .= "<td>".$this->money($item['unitCents'], $order['currency'])."</td><td>".$this->money($item['unitCents'] * $item['quantity'], $order['currency'])."</td></tr>\n";
		}
		$o .= "</tbody></table>\n";
		$o .= "<p class=\"ghotiStoreOrderTotal\">Total ".$this->money($order['totalCents'], $order['currency'])."</p>\n";

		$o .= '<dl class="ghotiStoreTotals">'.$this->renderDiscount($order).'<div><dt>Member points</dt><dd>'.(int)($order['loyaltyPoints'] ?? 0).'</dd></div></dl>';
		if($order['hasService'] && in_array($order['status'], array('paid','shipped'), true)){
			$nextStatus = $order['serviceStatus'] === 'fulfilled' ? 'pending' : 'fulfilled';
			$o .= '<button type="button" class="ghotiButton" onclick="storeSetOrderServiceStatus('.(int)$order['orderId'].', \''.$nextStatus.'\');">'.($nextStatus === 'fulfilled' ? 'Mark service ready' : 'Reopen service setup').'</button>';
		}
		if(in_array($order['status'], array('paid','shipped'), true)){
			$refundProvider=$paymentLabels[$order['paymentProvider'] ?? 'paypal'] ?? 'the payment provider';
			$o .= '<button type="button" class="ghotiButton ghotiButtonSecondary ghotiButtonDanger" onclick="storeSetOrderStatus('.(int)$order['orderId'].', \'cancelled\');">Cancel order / record external refund</button><p class="ghotiHelpText">This removes earned loyalty points. Refund the payment with '.$this->esc($refundProvider).' separately; supplier orders and download grants are not revoked automatically.</p>';
		}
		$fulfilments = storeDb()->getOrderFulfilments($order['orderId']);
		if($fulfilments){
			$o .= "<h4>Supplier fulfilment</h4><ul class=\"ghotiStoreFulfilmentList\">\n";
			foreach($fulfilments as $fulfilment){ $o .= $this->renderFulfilmentRow($fulfilment); }
			$o .= "</ul>\n";
		}

		if($downloads){
			$o .= "<h4>Download links issued</h4><ul class=\"ghotiStoreDownloadList\">\n";
			foreach($downloads as $grant){
				$o .= "<li>".$this->esc($grant['name'])." &mdash; ".(int)$grant['downloads']." of ".(int)$grant['maxDownloads']." used, expires ".$this->esc(date('M j, Y H:i T', (int)$grant['expiresAt']))."</li>\n";
			}
			$o .= "</ul>\n";
		}
		$o .= "</div>\n";
		return $o;
	}
}

//Absolute URL for links that have to work outside the browser session (e-mail).
//Built from the request because this app has no configured site URL.
function storeAbsoluteUrl($path){
	$https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off');
	$host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^A-Za-z0-9.\-:]/', '', $_SERVER['HTTP_HOST']) : '';
	if($host === ''){ return $path; }
	$dir = isset($_SERVER['SCRIPT_NAME']) ? rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') : '';
	return ($https ? 'https://' : 'http://').$host.$dir.'/'.ltrim($path, '/');
}
?>
