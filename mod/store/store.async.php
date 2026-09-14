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
 *  2. The order row is written BEFORE PayPal is asked to capture, and is only
 *     flipped to paid after the captured amount and currency are verified
 *     against it. A capture that succeeds while this process dies then leaves a
 *     pending row to reconcile, not a charged customer with no record.
 *
 * The storefront is public; management is admin-gated. Money is integer cents
 * everywhere, formatted for display exactly once, in storeui::money().
 */

/* ---------------------------------------------------------------- *
 *  Shared helpers
 * ---------------------------------------------------------------- */

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
		);
	}
	return $lines;
}

function storeCartTotals($lines = null){
	$lines = $lines === null ? storeCartLines() : $lines;
	$settings = storeDb()->getSettings();
	$subtotal = 0;
	$hasPhysical = false;
	$units = 0;
	foreach($lines as $line){
		$subtotal += $line['lineCents'];
		$units += $line['quantity'];
		if($line['kind'] === 'physical'){ $hasPhysical = true; }
	}
	//Shipping is a flat rate per order, charged only when something has to be
	//posted. An all-digital cart never pays it.
	$shipping = $hasPhysical ? max(0, (int)$settings['shippingCents']) : 0;
	return array(
		'subtotalCents' => $subtotal,
		'shippingCents' => $shipping,
		'totalCents'    => $subtotal + $shipping,
		'currency'      => $settings['currency'],
		'hasPhysical'   => $hasPhysical,
		'units'         => $units,
		'lines'         => count($lines),
	);
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

function storeShowCart(){
	try{
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

/*
 * Step one of payment: validate the buyer's details, record a pending order
 * from server-computed totals, then ask PayPal to create an order for that
 * amount. The PayPal order id goes back to the button script; nothing about
 * the price does.
 */
function storeBeginCheckout($customer){
	if(!is_array($customer)){ return array('ok' => false, 'error' => 'Enter your details before paying.'); }
	try{
		$settings = storeDb()->getSettings();
		if(!StorePaypalClient::configured($settings)){
			return array('ok' => false, 'error' => 'This store is not connected to PayPal yet.');
		}

		$lines = storeCartLines();
		if(!$lines){ return array('ok' => false, 'error' => 'Your cart is empty.'); }
		$totals = storeCartTotals($lines);
		if($totals['totalCents'] <= 0){
			return array('ok' => false, 'error' => 'This order has no payable total.');
		}

		$v = ghoti_validate();
		$order = array(
			'reference'     => storeReference(),
			'userId'        => isset($_SESSION['userId']) && ghoti_require_login() ? (int)$_SESSION['userId'] : null,
			'email'         => $v->email(isset($customer['email']) ? $customer['email'] : ''),
			'customerName'  => $v->text(isset($customer['name']) ? $customer['name'] : '', 120, true, "name"),
			'address1'      => '', 'address2' => '', 'city' => '', 'region' => '', 'postcode' => '', 'country' => '',
			'subtotalCents' => $totals['subtotalCents'],
			'shippingCents' => $totals['shippingCents'],
			'totalCents'    => $totals['totalCents'],
			'currency'      => $totals['currency'],
			'hasPhysical'   => $totals['hasPhysical'],
			'paypalOrderId' => '',
			'note'          => $v->text(isset($customer['note']) ? $customer['note'] : '', 500, false, "order note"),
		);

		//A shipping address is required only when something has to be posted.
		if($totals['hasPhysical']){
			$order['address1'] = $v->text(isset($customer['address1']) ? $customer['address1'] : '', 190, true, "address");
			$order['address2'] = $v->text(isset($customer['address2']) ? $customer['address2'] : '', 190, false, "address line 2");
			$order['city']     = $v->text(isset($customer['city']) ? $customer['city'] : '', 120, true, "city");
			$order['region']   = $v->text(isset($customer['region']) ? $customer['region'] : '', 120, false, "province or state");
			$order['postcode'] = $v->text(isset($customer['postcode']) ? $customer['postcode'] : '', 32, true, "postal code");
			$country = strtoupper(trim((string)(isset($customer['country']) ? $customer['country'] : '')));
			if(!preg_match('/^[A-Z]{2}$/', $country)){
				return array('ok' => false, 'error' => 'Enter a two-letter country code, such as CA or US.');
			}
			$order['country'] = $country;
		}

		$items = array();
		foreach($lines as $line){
			$items[] = array(
				'productId' => $line['productId'], 'name' => $line['name'], 'sku' => $line['sku'],
				'kind' => $line['kind'], 'unitCents' => $line['unitCents'], 'quantity' => $line['quantity'],
			);
		}

		$client = storePaypalClient($settings);
		$paypalOrderId = $client->createOrder($order, $items);
		$order['paypalOrderId'] = $paypalOrderId;

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

		$items = storeDb()->getOrderItems($order['orderId']);
		storeIssueDownloads($order['orderId'], $items, $settings);
		$order = storeDb()->getOrder($order['orderId']);
		$downloads = storeDb()->getOrderDownloads($order['orderId']);

		$_SESSION['storeCart'] = array();
		unset($_SESSION['storeOrderId']);
		ghoti::logInfo("store.async.php:storeCaptureOrder", "Order ".$order['reference']." paid (".$order['totalCents']." ".$order['currency'].")");
		storeSendOrderMail($order, $items, $downloads);

		return array('ok' => true, 'html' => storeUi()->renderReceipt($order, $items, $downloads));
	}catch (StorePaypalException $e){
		ghoti::logError("store.async.php:storeCaptureOrder", "PayPal: ".$e->getMessage());
		return array('ok' => false, 'error' => $e->getMessage());
	}catch (Throwable $e){
		ghoti::logException("store.async.php:storeCaptureOrder", $e);
		return array('ok' => false, 'error' => 'The payment could not be completed. Nothing further has been charged.');
	}
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
		$body = storeUi()->receiptText($order, $items, $downloads);
		$result = $_SESSION['mailObj']->send($order['email'], $order['customerName'], 'Your order '.$order['reference'], $body);
		if($result !== true){ ghoti::logWarn("store.async.php:storeSendOrderMail", "Receipt for ".$order['reference']." not sent: ".$result); }

		$adminBody = "A new order was paid.\n\n".storeUi()->receiptText($order, $items, array());
		foreach(ghoti_admin_emails() as $address){
			$_SESSION['mailObj']->send($address, '', 'New order '.$order['reference'], $adminBody);
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

/* ---------------------------------------------------------------- *
 *  Management endpoints (admin)
 * ---------------------------------------------------------------- */

function showStoreManager($tab = 'products'){
	if(!storeRequireAdmin()){ return "<h1>Store</h1><p>Admin access required.</p>"; }
	$tab = in_array($tab, array('products','orders','settings'), true) ? $tab : 'products';
	try{
		return storeUi()->renderManager($tab);
	}catch (Throwable $e){
		ghoti::logException("store.async.php:showStoreManager", $e);
		return "<h1>Store</h1><p>The store manager could not be loaded.</p>";
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
		$clean = array(
			'sku'         => $v->text(isset($product['sku']) ? $product['sku'] : '', 60, true, "SKU"),
			'name'        => $v->text(isset($product['name']) ? $product['name'] : '', 120, true, "product name"),
			'description' => $v->multilineText(isset($product['description']) ? $product['description'] : '', 2000, false, "description"),
			'priceCents'  => $v->intInRange($priceCents, 0, 99999999, "price"),
			'kind'        => (isset($product['kind']) && $product['kind'] === 'digital') ? 'digital' : 'physical',
			'category'    => $v->linkGroup(isset($product['category']) ? $product['category'] : 'default', true),
			'imageUrl'    => '',
			'downloadPath'=> '',
			'active'      => !empty($product['active']),
			'sortOrder'   => $v->intInRange(isset($product['sortOrder']) ? $product['sortOrder'] : 0, 0, 99999, "sort order"),
		);
		//Rendered into an <img src>, so it goes through the same scheme check as
		//every other URL the CMS accepts from an admin.
		if(trim((string)(isset($product['imageUrl']) ? $product['imageUrl'] : '')) !== ''){
			$clean['imageUrl'] = $v->url($product['imageUrl'], false, "image URL");
		}
		if($clean['kind'] === 'digital'){
			$clean['downloadPath'] = storeSafeDownloadPath(isset($product['downloadPath']) ? $product['downloadPath'] : '');
			if($clean['downloadPath'] === ''){
				return "A digital product needs a file that already exists under files/store/ to deliver.";
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

		$shippingCents = storePriceToCents(isset($settings['shipping']) ? $settings['shipping'] : '0');
		if($shippingCents < 0){ return "Enter the shipping rate as a number, for example 9.95."; }

		$clean = array(
			'paypalClientId' => $v->text(isset($settings['paypalClientId']) ? $settings['paypalClientId'] : '', 255, false, "PayPal client ID"),
			'paypalSecret'   => trim($secret),
			'paypalEnv'      => (isset($settings['paypalEnv']) && $settings['paypalEnv'] === 'live') ? 'live' : 'sandbox',
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
	if(!storeDb()->setOrderStatus($orderId, $status)){ return "The order could not be updated."; }
	ghoti::logInfo("store.async.php:setStoreOrderStatus", "Order ".$order['reference']." set to $status by UID:".($_SESSION['userId'] ?? '?'));
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
	return ((int)$m[1] * 100) + (int)str_pad(isset($m[2]) ? $m[2] : '0', 2, '0', STR_PAD_RIGHT);
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
function storeStoreFilesBase(){
	return realpath(__DIR__.'/../../files/store');
}

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
	"showStore",
	"storeShowCart",
	"storeAddToCart",
	"storeSetCartQuantity",
	"storeShowCheckout",
	"storeBeginCheckout",
	"storeCaptureOrder",
	"storePaypalConfig",
	"showStoreManager",
	"saveStoreProduct",
	"deleteStoreProduct",
	"saveStoreSettings",
	"showStoreOrder",
	"setStoreOrderStatus"
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
		$o  = "<div id=\"ghotiStore\" class=\"ghotiStore".($inline ? " ghotiStoreInline" : "")."\">\n";
		$o .= "<div class=\"ghotiStoreBar\">\n";
		$o .= "<h1 class=\"ghotiStoreTitle\">".($category === 'all' ? 'Store' : $this->esc(ucfirst($category)))."</h1>\n";
		$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary ghotiStoreCartButton\" onclick=\"storeShowCart();\">";
		$o .= "&#128722; <span id=\"ghotiStoreCartSummary\">".$this->esc($this->cartSummaryText($totals))."</span></button>\n";
		$o .= "</div>\n";

		if(!$products){
			$o .= "<p class=\"ghotiStoreEmpty\">Nothing is for sale here yet.</p>\n";
			$o .= "</div>\n";
			return $o;
		}

		$o .= "<div class=\"ghotiStoreGrid\">\n";
		foreach($products as $product){
			$id = (int)$product['productId'];
			$o .= "<article class=\"ghotiStoreCard\">\n";
			if($product['imageUrl'] !== ''){
				$o .= "<div class=\"ghotiStoreThumb\"><img src=\"".$this->esc($product['imageUrl'])."\" alt=\"".$this->esc($product['name'])."\" loading=\"lazy\" /></div>\n";
			}
			$o .= "<h2>".$this->esc($product['name'])."</h2>\n";
			if($product['kind'] === 'digital'){
				$o .= "<span class=\"ghotiStoreTag\">Digital download</span>\n";
			}
			if($product['description'] !== ''){
				$o .= "<p class=\"ghotiStoreBlurb\">".nl2br($this->esc($product['description']))."</p>\n";
			}
			$o .= "<div class=\"ghotiStoreBuy\">\n";
			$o .= "<span class=\"ghotiStorePrice\">".$this->money($product['priceCents'], $totals['currency'])."</span>\n";
			$o .= "<label class=\"ghotiStoreQty\"><span class=\"sr-only\">Quantity of ".$this->esc($product['name'])."</span>";
			$o .= "<input type=\"number\" id=\"storeQty-$id\" value=\"1\" min=\"1\" max=\"".STORE_MAX_QTY."\" step=\"1\" /></label>\n";
			$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact\" onclick=\"storeAddToCart($id);\">Add to cart</button>\n";
			$o .= "</div>\n</article>\n";
		}
		$o .= "</div>\n";
		$o .= "<p class=\"ghotiStoreHelpText\">Payment is handled by PayPal. This site never sees your card details.</p>\n";
		$o .= "</div>\n";
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

		$o .= "<table class=\"ghotiStoreTable\"><thead><tr><th>Item</th><th>Price</th><th>Qty</th><th>Line</th><th></th></tr></thead><tbody>\n";
		foreach($lines as $line){
			$id = (int)$line['productId'];
			$o .= "<tr>";
			$o .= "<td>".$this->esc($line['name'])."".($line['kind'] === 'digital' ? " <span class=\"ghotiStoreTag\">Download</span>" : "")."</td>";
			$o .= "<td>".$this->money($line['unitCents'], $totals['currency'])."</td>";
			$o .= "<td><input type=\"number\" class=\"ghotiStoreQtyInput\" value=\"".(int)$line['quantity']."\" min=\"1\" max=\"".STORE_MAX_QTY."\" step=\"1\" onchange=\"storeSetCartQuantity($id, this.value);\" aria-label=\"Quantity of ".$this->esc($line['name'])."\" /></td>";
			$o .= "<td>".$this->money($line['lineCents'], $totals['currency'])."</td>";
			$o .= "<td><button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary ghotiButtonDanger\" onclick=\"storeSetCartQuantity($id, 0);\">Remove</button></td>";
			$o .= "</tr>\n";
		}
		$o .= "</tbody></table>\n";

		$o .= "<dl class=\"ghotiStoreTotals\">\n";
		$o .= "<div><dt>Subtotal</dt><dd>".$this->money($totals['subtotalCents'], $totals['currency'])."</dd></div>\n";
		if($totals['hasPhysical']){
			$o .= "<div><dt>Shipping</dt><dd>".$this->money($totals['shippingCents'], $totals['currency'])."</dd></div>\n";
		}
		$o .= "<div class=\"ghotiStoreGrand\"><dt>Total</dt><dd>".$this->money($totals['totalCents'], $totals['currency'])."</dd></div>\n";
		$o .= "</dl>\n";
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
			$o .= "<li><span>".(int)$line['quantity']." &times; ".$this->esc($line['name'])."</span><span>".$this->money($line['lineCents'], $totals['currency'])."</span></li>\n";
		}
		if($totals['hasPhysical']){
			$o .= "<li><span>Shipping".($settings['shippingNote'] !== '' ? ' &mdash; '.$this->esc($settings['shippingNote']) : '')."</span><span>".$this->money($totals['shippingCents'], $totals['currency'])."</span></li>\n";
		}
		$o .= "<li class=\"ghotiStoreGrand\"><span>Total</span><span>".$this->money($totals['totalCents'], $totals['currency'])."</span></li>\n";
		$o .= "</ul></div>\n";

		$o .= "<form id=\"ghotiStoreCheckoutForm\" class=\"ghotiForm\" action=\"#\" onsubmit=\"return false;\">\n";
		$o .= "<div class=\"ghotiFormGrid\">\n";
		$o .= "<label class=\"ghotiField\"><span>Your name</span><input type=\"text\" id=\"storeName\" maxlength=\"120\" autocomplete=\"name\" required=\"required\" /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>E-mail <i>(for the receipt)</i></span><input type=\"email\" id=\"storeEmail\" maxlength=\"190\" autocomplete=\"email\" required=\"required\" /></label>\n";
		$o .= "</div>\n";

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

		$o .= "<div id=\"ghotiStorePayStatus\" class=\"ghotiStoreStatus\" role=\"status\" aria-live=\"polite\"></div>\n";
		$o .= "<div id=\"ghotiStorePaypal\" class=\"ghotiStorePaypal\"></div>\n";
		$o .= "<p class=\"ghotiStoreHelpText\">You pay through PayPal. Your card details never reach this site; the order is only recorded once PayPal confirms the payment.</p>\n";
		$o .= "</div>\n";
		return $o;
	}

	public function renderReceipt($order, $items, $downloads){
		$o  = "<div id=\"ghotiStoreReceipt\" class=\"ghotiStore\">\n";
		$o .= "<h1 class=\"ghotiStoreTitle\">Thank you</h1>\n";
		$o .= "<p class=\"ghotiStoreLead\">Order <strong>".$this->esc($order['reference'])."</strong> is paid. A receipt is on its way to ".$this->esc($order['email']).".</p>\n";

		$o .= "<table class=\"ghotiStoreTable\"><thead><tr><th>Item</th><th>Qty</th><th>Line</th></tr></thead><tbody>\n";
		foreach($items as $item){
			$o .= "<tr><td>".$this->esc($item['name'])."</td><td>".(int)$item['quantity']."</td><td>".$this->money($item['unitCents'] * $item['quantity'], $order['currency'])."</td></tr>\n";
		}
		$o .= "</tbody></table>\n";
		$o .= "<dl class=\"ghotiStoreTotals\">\n";
		$o .= "<div><dt>Subtotal</dt><dd>".$this->money($order['subtotalCents'], $order['currency'])."</dd></div>\n";
		if($order['hasPhysical']){
			$o .= "<div><dt>Shipping</dt><dd>".$this->money($order['shippingCents'], $order['currency'])."</dd></div>\n";
		}
		$o .= "<div class=\"ghotiStoreGrand\"><dt>Paid</dt><dd>".$this->money($order['totalCents'], $order['currency'])."</dd></div>\n";
		$o .= "</dl>\n";

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
			$lines[] = $item['quantity'].' x '.$item['name'].'  '.$order['currency'].' '.StorePaypalClient::amount($item['unitCents'] * $item['quantity']);
		}
		$lines[] = '';
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
		$o .= "<div class=\"ghotiCrudHeader\"><div><h1>Store</h1><p class=\"ghotiHelpText\">Catalogue, orders, and the PayPal connection. Put a shop on any page with <code>[store:all]</code>.</p></div></div>\n";

		$o .= "<div class=\"ghotiStoreTabs\" role=\"tablist\">\n";
		foreach(array('products' => 'Products', 'orders' => 'Orders', 'settings' => 'PayPal &amp; shipping') as $key => $label){
			$active = $tab === $key;
			$o .= "<button type=\"button\" role=\"tab\" aria-selected=\"".($active ? 'true' : 'false')."\" class=\"ghotiStoreTab".($active ? " is-active" : "")."\" onclick=\"showStoreManager('".$key."');\">".$label."</button>\n";
		}
		$o .= "</div>\n";

		if($tab === 'products'){ $o .= $this->renderProductAdmin($settings); }
		elseif($tab === 'orders'){ $o .= $this->renderOrderAdmin(); }
		else { $o .= $this->renderSettingsAdmin($settings); }

		$o .= ghoti_docs_panel("How to run the store", "catalogue, payments, fulfilment", array(
			array('heading' => 'Put the shop on a page',
				'list' => array('Edit any page and add <code class="ghotiDocCode">[store:all]</code> for everything, or <code class="ghotiDocCode">[store:prints]</code> for one category.', 'The cart, checkout and receipt all render in place; no extra pages are needed.')),
			array('heading' => 'Connect PayPal',
				'list' => array('Create REST API credentials in the PayPal Developer dashboard and paste the client ID and secret under <b>PayPal &amp; shipping</b>.', 'Leave the environment on <b>Sandbox</b> and buy something from yourself with a sandbox account first. Switch to <b>Live</b> only once that works.', 'The secret is stored in the database and is never sent to the browser. Re-saving with the secret box empty keeps the stored one.')),
			array('heading' => 'Physical and digital items',
				'list' => array('A <b>physical</b> item makes checkout ask for a shipping address and adds the flat shipping rate once per order.', 'A <b>digital</b> item needs a file that already exists under <code>files/</code>; buyers get an expiring, download-limited link on the receipt and in their e-mail.')),
			array('heading' => 'Fulfilment',
				'list' => array('Orders appear under <b>Orders</b> as soon as payment is confirmed by PayPal; every administrator is e-mailed.', 'Mark a paid order <b>Shipped</b> once it is posted. <b>Paid</b> can never be set by hand - only a verified PayPal capture sets it.', 'A <b>pending</b> row is a checkout nobody finished. It is safe to leave; nothing was charged.'))
		));
		$o .= "</section>\n";
		return $o;
	}

	private function renderProductAdmin($settings){
		$products = storeDb()->getProducts('all', true);
		$o  = "<div class=\"ghotiStoreAdminHead\"><h2>Products</h2>";
		$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact\" onclick=\"storeEditProduct(0);\">Add product</button></div>\n";
		$o .= "<div id=\"ghotiStoreProductForm\" class=\"ghotiStoreProductForm\" hidden=\"hidden\"></div>\n";

		if(!$products){
			$o .= "<p class=\"ghotiEmptyState\">No products yet.</p>\n";
			return $o;
		}
		$o .= "<div class=\"ghotiStoreAdminTableWrap\"><table class=\"ghotiStoreTable\"><thead><tr><th>Product</th><th>SKU</th><th>Kind</th><th>Category</th><th>Price</th><th>Live</th><th></th></tr></thead><tbody>\n";
		foreach($products as $product){
			$id = (int)$product['productId'];
			$o .= "<tr>";
			$o .= "<td><button type=\"button\" class=\"ghotiTextButton\" onclick=\"storeEditProduct($id);\">".$this->esc($product['name'])."</button></td>";
			$o .= "<td>".$this->esc($product['sku'])."</td>";
			$o .= "<td>".($product['kind'] === 'digital' ? 'Digital' : 'Physical')."</td>";
			$o .= "<td>".$this->esc($product['category'])."</td>";
			$o .= "<td>".$this->money($product['priceCents'], $settings['currency'])."</td>";
			$o .= "<td>".($product['active'] ? 'Yes' : 'No')."</td>";
			$o .= "<td><button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonDanger\" onclick=\"storeDeleteProduct($id);\">Delete</button></td>";
			$o .= "</tr>\n";
		}
		$o .= "</tbody></table></div>\n";
		//The editor reads these, so quantities and prices never round-trip through
		//the page as text the admin might have half-edited.
		$o .= "<script type=\"application/json\" id=\"ghotiStoreProducts\">".json_encode($products, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)."</script>\n";
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
			if($order['status'] === 'paid'){
				$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact\" onclick=\"storeSetOrderStatus($id,'shipped');\">Mark shipped</button>";
			}elseif($order['status'] === 'pending'){
				$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" onclick=\"storeSetOrderStatus($id,'cancelled');\">Cancel</button>";
			}
			$o .= "</td></tr>\n";
		}
		$o .= "</tbody></table></div>\n";
		return $o;
	}

	private function renderSettingsAdmin($settings){
		$hasSecret = $settings['paypalSecret'] !== '';
		$o  = "<div class=\"ghotiStoreAdminHead\"><h2>PayPal &amp; shipping</h2></div>\n";
		$o .= "<form id=\"ghotiStoreSettingsForm\" class=\"ghotiForm\" action=\"#\" onsubmit=\"storeSaveSettings(); return false;\">\n";
		$o .= "<div class=\"ghotiFormGrid\">\n";
		$o .= "<label class=\"ghotiField\"><span>PayPal client ID</span><input type=\"text\" id=\"store-clientId\" maxlength=\"255\" value=\"".$this->esc($settings['paypalClientId'])."\" autocomplete=\"off\" /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>PayPal secret".($hasSecret ? " <i>(saved &mdash; leave blank to keep)</i>" : "")."</span><input type=\"password\" id=\"store-secret\" maxlength=\"255\" value=\"\" autocomplete=\"new-password\" placeholder=\"".($hasSecret ? "••••••••" : "")."\" /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>Environment</span><select id=\"store-env\">";
		$o .= "<option value=\"sandbox\"".($settings['paypalEnv'] === 'sandbox' ? " selected=\"selected\"" : "").">Sandbox (test money)</option>";
		$o .= "<option value=\"live\"".($settings['paypalEnv'] === 'live' ? " selected=\"selected\"" : "").">Live (real money)</option>";
		$o .= "</select></label>\n";
		$o .= "<label class=\"ghotiField\"><span>Currency <i>(3 letters)</i></span><input type=\"text\" id=\"store-currency\" maxlength=\"3\" value=\"".$this->esc($settings['currency'])."\" /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>Flat shipping <i>(per order with a physical item)</i></span><input type=\"text\" id=\"store-shipping\" maxlength=\"12\" value=\"".$this->esc(StorePaypalClient::amount($settings['shippingCents']))."\" /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>Shipping note <i>(optional)</i></span><input type=\"text\" id=\"store-shippingNote\" maxlength=\"255\" value=\"".$this->esc($settings['shippingNote'])."\" /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>Download window <i>(hours)</i></span><input type=\"number\" id=\"store-downloadHours\" min=\"1\" max=\"8760\" step=\"1\" value=\"".(int)$settings['downloadHours']."\" /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>Downloads per item</span><input type=\"number\" id=\"store-downloadLimit\" min=\"1\" max=\"100\" step=\"1\" value=\"".(int)$settings['downloadLimit']."\" /></label>\n";
		$o .= "</div>\n";
		$o .= "<div class=\"ghotiFormActions\"><button type=\"submit\" class=\"ghotiButton\">Save store settings</button></div>\n";
		$o .= "</form>\n";
		$o .= "<p class=\"ghotiHelpText\">The secret authenticates this site to PayPal and is stored in the database, so it is in your backups &mdash; treat them accordingly. Sandbox and live credentials are different pairs; switching environment without swapping both will fail to take payment.</p>\n";
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
		if($order['payerEmail'] !== ''){ $o .= "<div><dt>PayPal payer</dt><dd>".$this->esc($order['payerEmail'])."</dd></div>\n"; }
		if($order['paypalCaptureId'] !== ''){ $o .= "<div><dt>Capture</dt><dd><code>".$this->esc($order['paypalCaptureId'])."</code></dd></div>\n"; }
		if($order['hasPhysical']){
			$address = array_filter(array($order['address1'], $order['address2'], trim($order['city'].' '.$order['region'].' '.$order['postcode']), $order['country']));
			$o .= "<div><dt>Ship to</dt><dd>".$this->esc(implode(', ', $address))."</dd></div>\n";
		}
		if($order['note'] !== ''){ $o .= "<div><dt>Note</dt><dd>".$this->esc($order['note'])."</dd></div>\n"; }
		$o .= "</dl>\n";

		$o .= "<table class=\"ghotiStoreTable\"><thead><tr><th>Item</th><th>SKU</th><th>Qty</th><th>Unit</th><th>Line</th></tr></thead><tbody>\n";
		foreach($items as $item){
			$o .= "<tr><td>".$this->esc($item['name'])."</td><td>".$this->esc($item['sku'])."</td><td>".(int)$item['quantity']."</td>";
			$o .= "<td>".$this->money($item['unitCents'], $order['currency'])."</td><td>".$this->money($item['unitCents'] * $item['quantity'], $order['currency'])."</td></tr>\n";
		}
		$o .= "</tbody></table>\n";
		$o .= "<p class=\"ghotiStoreOrderTotal\">Total ".$this->money($order['totalCents'], $order['currency'])."</p>\n";

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
