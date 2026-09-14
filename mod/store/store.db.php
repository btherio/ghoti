<?php
/*
 * store.db.php - storage for the store module: settings, catalogue, orders and
 * download grants.
 *
 * Same shape as every other *.db.php: a class extending ghotidb, loading its own
 * SQL in the constructor via loadModuleSql(), exposing typed methods that never
 * throw at their callers (they log and return false/array() instead).
 *
 * NOTE on paypalSecret: like the SMTP password in mail.db.php, it is stored as
 * written. It has to be replayable to authenticate against PayPal's API, so it
 * cannot be hashed. Treat database access and backups accordingly - anyone who
 * can read this table can create and capture payments against the merchant
 * account. Move it to the environment if that tradeoff is not acceptable for
 * an install; see docs/store.md.
 *
 * Money is integer cents everywhere in this module. Nothing here ever converts
 * to a float, including for display - see storeui::money().
 */

class storedb extends ghotidb{
	public function __construct(){
		parent::__construct();
		parent::loadModuleSql("store");
	}

	public function __destruct(){
		parent::__destruct();
	}

	/* ---------------- settings ---------------- */

	public static function defaultSettings(){
		return array(
			'paypalClientId' => '',
			'paypalSecret'   => '',
			'paypalEnv'      => 'sandbox',
			'currency'       => 'CAD',
			'shippingCents'  => 0,
			'shippingNote'   => '',
			'downloadHours'  => 72,
			'downloadLimit'  => 5,
			'dropshipEnabled'    => false,
			'dropshipAutoSubmit' => true,
			'dropshipConfig'     => array(),
			'updatedAt'      => 0,
		);
	}

	public function getSettings(){
		try{
			$rows = $this->queryArray("select paypalClientId,paypalSecret,paypalEnv,currency,shippingCents,shippingNote,downloadHours,downloadLimit,dropshipEnabled,dropshipAutoSubmit,dropshipConfig,updatedAt from store where id = 1 limit 1");
			if(isset($rows[0])){
				$row = $rows[0];
				return array(
					'paypalClientId' => (string)$row[0],
					'paypalSecret'   => (string)$row[1],
					'paypalEnv'      => $row[2] === 'live' ? 'live' : 'sandbox',
					'currency'       => strtoupper((string)$row[3]),
					'shippingCents'  => (int)$row[4],
					'shippingNote'   => (string)$row[5],
					'downloadHours'  => (int)$row[6],
					'downloadLimit'  => (int)$row[7],
					'dropshipEnabled'    => (int)$row[8] === 1,
					'dropshipAutoSubmit' => (int)$row[9] === 1,
					//Per-provider credentials as JSON: four suppliers with three
					//or four fields each would otherwise be a dozen columns that
					//every new driver has to migrate.
					'dropshipConfig'     => self::decodeConfig($row[10]),
					'updatedAt'      => (int)$row[11],
				);
			}
		}catch (Throwable $e){
			ghoti::logException("store.db.php:getSettings", $e);
		}
		return self::defaultSettings();
	}

	public static function decodeConfig($raw){
		$config = json_decode((string)$raw, true);
		return is_array($config) ? $config : array();
	}

	public function saveSettings($settings){
		try{
			//The seed row may be missing if the table was created by hand; upsert
			//rather than fail with "0 rows updated" and no explanation.
			$current = $this->getSettings();
			//Every caller saves one screen's worth of settings, so anything it
			//did not send keeps the value it already had rather than being
			//blanked by the upsert.
			$settings = array_merge($current, $settings);
			$config = isset($settings['dropshipConfig']) && is_array($settings['dropshipConfig']) ? $settings['dropshipConfig'] : array();
			$this->query(
				"insert into store (id,paypalClientId,paypalSecret,paypalEnv,currency,shippingCents,shippingNote,downloadHours,downloadLimit,dropshipEnabled,dropshipAutoSubmit,dropshipConfig,updatedAt)"
				." values (1,?,?,?,?,?,?,?,?,?,?,?,?)"
				." on duplicate key update paypalClientId=values(paypalClientId),paypalSecret=values(paypalSecret),paypalEnv=values(paypalEnv),"
				." currency=values(currency),shippingCents=values(shippingCents),shippingNote=values(shippingNote),"
				." downloadHours=values(downloadHours),downloadLimit=values(downloadLimit),"
				." dropshipEnabled=values(dropshipEnabled),dropshipAutoSubmit=values(dropshipAutoSubmit),dropshipConfig=values(dropshipConfig),"
				." updatedAt=values(updatedAt)",
				array(
					$settings['paypalClientId'], $settings['paypalSecret'], $settings['paypalEnv'],
					$settings['currency'], (int)$settings['shippingCents'], $settings['shippingNote'],
					(int)$settings['downloadHours'], (int)$settings['downloadLimit'],
					!empty($settings['dropshipEnabled']) ? 1 : 0, !empty($settings['dropshipAutoSubmit']) ? 1 : 0,
					json_encode($config), time()
				)
			);
			return true;
		}catch (Throwable $e){
			ghoti::logException("store.db.php:saveSettings", $e);
			return false;
		}
	}

	/* ---------------- catalogue ---------------- */

	private static function productRow($row){
		return array(
			'productId'    => (int)$row[0],
			'sku'          => (string)$row[1],
			'name'         => (string)$row[2],
			'description'  => (string)$row[3],
			'priceCents'   => (int)$row[4],
			'kind'         => $row[5] === 'digital' ? 'digital' : 'physical',
			'category'     => (string)$row[6],
			'imageUrl'     => (string)$row[7],
			'downloadPath' => (string)$row[8],
			'active'       => (int)$row[9] === 1,
			'sortOrder'    => (int)$row[10],
			//Fulfilment is orthogonal to kind: a dropshipped item is still a
			//physical one, and still charges shipping and needs an address.
			'fulfilment'    => in_array($row[11], array('dropship', 'spring'), true) ? $row[11] : 'self',
			'dropProvider'  => (string)$row[12],
			'dropProductId' => (string)$row[13],
			'dropVariantId' => (string)$row[14],
			'createdAt'    => (int)$row[15],
			'externalUrl'  => (string)$row[16],
			'featured'     => (int)$row[17] === 1,
			'compareAtCents' => (int)$row[18],
			'badge'        => (string)$row[19],
			'deliveryNote' => (string)$row[20],
		);
	}

	private const PRODUCT_COLUMNS = "productId,sku,name,description,priceCents,kind,category,imageUrl,downloadPath,active,sortOrder,fulfilment,dropProvider,dropProductId,dropVariantId,createdAt,externalUrl,featured,compareAtCents,badge,deliveryNote";

	//$category 'all' returns every category. Inactive products are never
	//returned to the storefront; the admin list asks for them explicitly.
	public function getProducts($category = 'all', $includeInactive = false){
		try{
			$sql = "select ".self::PRODUCT_COLUMNS." from store_products";
			$where = array();
			$params = array();
			if(!$includeInactive){ $where[] = "active = 1"; }
			if($category !== 'all'){ $where[] = "category = ?"; $params[] = $category; }
			if($where){ $sql .= " where ".implode(" and ", $where); }
			$sql .= " order by featured desc, sortOrder asc, name asc";
			$rows = $this->queryArray($sql, $params);
		}catch (Throwable $e){
			ghoti::logException("store.db.php:getProducts", $e);
			return array();
		}
		$products = array();
		foreach($rows as $row){ $products[] = self::productRow($row); }
		return $products;
	}

	public function getProduct($productId){
		try{
			$rows = $this->queryArray("select ".self::PRODUCT_COLUMNS." from store_products where productId = ? limit 1", array((int)$productId));
		}catch (Throwable $e){
			ghoti::logException("store.db.php:getProduct", $e);
			return null;
		}
		return isset($rows[0]) ? self::productRow($rows[0]) : null;
	}

	//Fetch several products at once, keyed by id. The cart holds ids and
	//quantities only, so this is how a price is ever obtained: from the
	//database, never from the browser.
	public function getProductsById($ids){
		$ids = array_values(array_unique(array_map('intval', (array)$ids)));
		if(!$ids){ return array(); }
		$placeholders = implode(',', array_fill(0, count($ids), '?'));
		try{
			$rows = $this->queryArray("select ".self::PRODUCT_COLUMNS." from store_products where productId in ($placeholders)", $ids);
		}catch (Throwable $e){
			ghoti::logException("store.db.php:getProductsById", $e);
			return array();
		}
		$products = array();
		foreach($rows as $row){
			$product = self::productRow($row);
			$products[$product['productId']] = $product;
		}
		return $products;
	}

	public function getCategories(){
		try{
			$rows = $this->queryArray("select distinct category from store_products order by category asc");
		}catch (Throwable $e){
			ghoti::logException("store.db.php:getCategories", $e);
			return array();
		}
		$categories = array();
		foreach($rows as $row){ $categories[] = (string)$row[0]; }
		return $categories;
	}

	public function addProduct($product){
		try{
			$this->query(
				"insert into store_products (sku,name,description,priceCents,kind,category,imageUrl,downloadPath,active,sortOrder,fulfilment,dropProvider,dropProductId,dropVariantId,createdAt,externalUrl,featured,compareAtCents,badge,deliveryNote)"
				." values (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
				array($product['sku'], $product['name'], $product['description'], (int)$product['priceCents'],
					$product['kind'], $product['category'], $product['imageUrl'], $product['downloadPath'],
					$product['active'] ? 1 : 0, (int)$product['sortOrder'],
					$product['fulfilment'], $product['dropProvider'], $product['dropProductId'], $product['dropVariantId'], time(),
					$product['externalUrl'], $product['featured'] ? 1 : 0, (int)$product['compareAtCents'], $product['badge'], $product['deliveryNote'])
			);
			return true;
		}catch (Throwable $e){
			ghoti::logException("store.db.php:addProduct", $e);
			return false;
		}
	}

	public function updateProduct($productId, $product){
		try{
			$this->query(
				"update store_products set sku=?,name=?,description=?,priceCents=?,kind=?,category=?,imageUrl=?,downloadPath=?,active=?,sortOrder=?,"
				."fulfilment=?,dropProvider=?,dropProductId=?,dropVariantId=?,externalUrl=?,featured=?,compareAtCents=?,badge=?,deliveryNote=? where productId=?",
				array($product['sku'], $product['name'], $product['description'], (int)$product['priceCents'],
					$product['kind'], $product['category'], $product['imageUrl'], $product['downloadPath'],
					$product['active'] ? 1 : 0, (int)$product['sortOrder'],
					$product['fulfilment'], $product['dropProvider'], $product['dropProductId'], $product['dropVariantId'],
					$product['externalUrl'], $product['featured'] ? 1 : 0, (int)$product['compareAtCents'], $product['badge'], $product['deliveryNote'], (int)$productId)
			);
			return true;
		}catch (Throwable $e){
			ghoti::logException("store.db.php:updateProduct", $e);
			return false;
		}
	}

	public function deleteProduct($productId){
		try{
			$this->query("delete from store_products where productId = ?", array((int)$productId));
			return true;
		}catch (Throwable $e){
			ghoti::logException("store.db.php:deleteProduct", $e);
			return false;
		}
	}

	//True when another product already uses this SKU. The unique index is the
	//real guard; this exists to answer with a sentence instead of an exception.
	public function skuTaken($sku, $excludeId = 0){
		try{
			$rows = $this->queryArray("select productId from store_products where sku = ? and productId <> ? limit 1", array($sku, (int)$excludeId));
			return !empty($rows);
		}catch (Throwable $e){
			ghoti::logException("store.db.php:skuTaken", $e);
			return true; //fail closed: refuse the save rather than risk a duplicate
		}
	}

	/* ---------------- orders ---------------- */

	private static function orderRow($row){
		return array(
			'orderId'        => (int)$row[0],
			'reference'      => (string)$row[1],
			'status'         => (string)$row[2],
			'userId'         => $row[3] === null ? null : (int)$row[3],
			'email'          => (string)$row[4],
			'customerName'   => (string)$row[5],
			'address1'       => (string)$row[6],
			'address2'       => (string)$row[7],
			'city'           => (string)$row[8],
			'region'         => (string)$row[9],
			'postcode'       => (string)$row[10],
			'country'        => (string)$row[11],
			'subtotalCents'  => (int)$row[12],
			'shippingCents'  => (int)$row[13],
			'totalCents'     => (int)$row[14],
			'currency'       => (string)$row[15],
			'hasPhysical'    => (int)$row[16] === 1,
			'paypalOrderId'  => (string)$row[17],
			'paypalCaptureId'=> (string)$row[18],
			'payerEmail'     => (string)$row[19],
			'note'           => (string)$row[20],
			'createdAt'      => (int)$row[21],
			'paidAt'         => (int)$row[22],
			'shippedAt'      => (int)$row[23],
		);
	}

	private const ORDER_COLUMNS = "orderId,reference,status,userId,email,customerName,address1,address2,city,region,postcode,country,subtotalCents,shippingCents,totalCents,currency,hasPhysical,paypalOrderId,paypalCaptureId,payerEmail,note,createdAt,paidAt,shippedAt";

	//Writes the order and its line items as one transaction: an order without
	//its items would be unreconcilable. Returns the new orderId, or false.
	public function createOrder($order, $items){
		try{
			$pdo = $this->db();
			$pdo->beginTransaction();
			try{
				$this->query(
					"insert into store_orders (reference,status,userId,email,customerName,address1,address2,city,region,postcode,country,"
					."subtotalCents,shippingCents,totalCents,currency,hasPhysical,paypalOrderId,note,createdAt)"
					." values (?,'pending',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
					array($order['reference'], $order['userId'], $order['email'], $order['customerName'],
						$order['address1'], $order['address2'], $order['city'], $order['region'],
						$order['postcode'], $order['country'], (int)$order['subtotalCents'],
						(int)$order['shippingCents'], (int)$order['totalCents'], $order['currency'],
						$order['hasPhysical'] ? 1 : 0, $order['paypalOrderId'], $order['note'], time())
				);
				$orderId = (int)$pdo->lastInsertId();
				foreach($items as $item){
					$this->query(
						"insert into store_order_items (orderId,productId,name,sku,kind,unitCents,quantity) values (?,?,?,?,?,?,?)",
						array($orderId, (int)$item['productId'], $item['name'], $item['sku'],
							$item['kind'], (int)$item['unitCents'], (int)$item['quantity'])
					);
				}
				$pdo->commit();
				return $orderId;
			}catch (Throwable $e){
				$pdo->rollBack();
				throw $e;
			}
		}catch (Throwable $e){
			ghoti::logException("store.db.php:createOrder", $e);
			return false;
		}
	}

	public function getOrder($orderId){
		try{
			$rows = $this->queryArray("select ".self::ORDER_COLUMNS." from store_orders where orderId = ? limit 1", array((int)$orderId));
		}catch (Throwable $e){
			ghoti::logException("store.db.php:getOrder", $e);
			return null;
		}
		return isset($rows[0]) ? self::orderRow($rows[0]) : null;
	}

	public function getOrderByPaypalId($paypalOrderId){
		try{
			$rows = $this->queryArray("select ".self::ORDER_COLUMNS." from store_orders where paypalOrderId = ? limit 1", array((string)$paypalOrderId));
		}catch (Throwable $e){
			ghoti::logException("store.db.php:getOrderByPaypalId", $e);
			return null;
		}
		return isset($rows[0]) ? self::orderRow($rows[0]) : null;
	}

	public function getOrders($status = 'all', $limit = 100){
		$limit = max(1, min(500, (int)$limit));
		try{
			if($status === 'all'){
				$rows = $this->queryArray("select ".self::ORDER_COLUMNS." from store_orders order by createdAt desc limit ".$limit);
			}else{
				$rows = $this->queryArray("select ".self::ORDER_COLUMNS." from store_orders where status = ? order by createdAt desc limit ".$limit, array($status));
			}
		}catch (Throwable $e){
			ghoti::logException("store.db.php:getOrders", $e);
			return array();
		}
		$orders = array();
		foreach($rows as $row){ $orders[] = self::orderRow($row); }
		return $orders;
	}

	/*
	 * Order lines, each carrying the supplier mapping the product had at the
	 * time. Read from the product rather than snapshotted into the line: a
	 * mis-typed variant id has to be fixable on the product and then retried,
	 * which is impossible if the wrong value is frozen into the order.
	 */
	public function getOrderItems($orderId){
		try{
			$rows = $this->queryArray(
				"select i.itemId,i.productId,i.name,i.sku,i.kind,i.unitCents,i.quantity,"
				."coalesce(p.fulfilment,'self'),coalesce(p.dropProvider,''),coalesce(p.dropProductId,''),coalesce(p.dropVariantId,'')"
				." from store_order_items i left join store_products p on p.productId = i.productId"
				." where i.orderId = ? order by i.itemId asc",
				array((int)$orderId)
			);
		}catch (Throwable $e){
			ghoti::logException("store.db.php:getOrderItems", $e);
			return array();
		}
		$items = array();
		foreach($rows as $row){
			$items[] = array(
				'itemId'    => (int)$row[0],
				'productId' => (int)$row[1],
				'name'      => (string)$row[2],
				'sku'       => (string)$row[3],
				'kind'      => (string)$row[4],
				'unitCents' => (int)$row[5],
				'quantity'  => (int)$row[6],
				'fulfilment'    => isset($row[7]) && $row[7] === 'dropship' ? 'dropship' : 'self',
				'dropProvider'  => isset($row[8]) ? (string)$row[8] : '',
				'dropProductId' => isset($row[9]) ? (string)$row[9] : '',
				'dropVariantId' => isset($row[10]) ? (string)$row[10] : '',
			);
		}
		return $items;
	}

	/*
	 * Mark an order paid, but only if it is still pending. The affected-row
	 * count is the idempotency guard: a replayed capture updates zero rows and
	 * the caller knows not to send a second receipt.
	 */
	public function markOrderPaid($orderId, $captureId, $payerEmail){
		try{
			$statement = $this->db()->prepare("update store_orders set status='paid',paypalCaptureId=?,payerEmail=?,paidAt=? where orderId=? and status='pending'");
			$statement->execute(array((string)$captureId, (string)$payerEmail, time(), (int)$orderId));
			return $statement->rowCount() > 0;
		}catch (Throwable $e){
			ghoti::logException("store.db.php:markOrderPaid", $e);
			return false;
		}
	}

	public function setOrderStatus($orderId, $status){
		try{
			if($status === 'shipped'){
				$this->query("update store_orders set status='shipped',shippedAt=? where orderId=?", array(time(), (int)$orderId));
			}else{
				$this->query("update store_orders set status=? where orderId=?", array($status, (int)$orderId));
			}
			return true;
		}catch (Throwable $e){
			ghoti::logException("store.db.php:setOrderStatus", $e);
			return false;
		}
	}

	/* ---------------- digital delivery ---------------- */

	public function addDownloadGrant($orderId, $productId, $token, $maxDownloads, $expiresAt){
		try{
			$this->query(
				"insert into store_downloads (token,orderId,productId,downloads,maxDownloads,expiresAt,createdAt) values (?,?,?,0,?,?,?)",
				array((string)$token, (int)$orderId, (int)$productId, (int)$maxDownloads, (int)$expiresAt, time())
			);
			return true;
		}catch (Throwable $e){
			ghoti::logException("store.db.php:addDownloadGrant", $e);
			return false;
		}
	}

	public function getDownloadGrant($token){
		try{
			$rows = $this->queryArray("select downloadId,token,orderId,productId,downloads,maxDownloads,expiresAt from store_downloads where token = ? limit 1", array((string)$token));
		}catch (Throwable $e){
			ghoti::logException("store.db.php:getDownloadGrant", $e);
			return null;
		}
		if(!isset($rows[0])){ return null; }
		$row = $rows[0];
		return array(
			'downloadId'   => (int)$row[0],
			'token'        => (string)$row[1],
			'orderId'      => (int)$row[2],
			'productId'    => (int)$row[3],
			'downloads'    => (int)$row[4],
			'maxDownloads' => (int)$row[5],
			'expiresAt'    => (int)$row[6],
		);
	}

	//Claim one download against the grant. Conditional so two parallel requests
	//cannot both take the last remaining download.
	public function claimDownload($downloadId){
		try{
			$statement = $this->db()->prepare("update store_downloads set downloads = downloads + 1 where downloadId = ? and downloads < maxDownloads and expiresAt > ?");
			$statement->execute(array((int)$downloadId, time()));
			return $statement->rowCount() > 0;
		}catch (Throwable $e){
			ghoti::logException("store.db.php:claimDownload", $e);
			return false;
		}
	}

	public function getOrderDownloads($orderId){
		try{
			$rows = $this->queryArray(
				"select d.token,d.expiresAt,d.downloads,d.maxDownloads,p.name from store_downloads d"
				." left join store_products p on p.productId = d.productId where d.orderId = ? order by d.downloadId asc",
				array((int)$orderId)
			);
		}catch (Throwable $e){
			ghoti::logException("store.db.php:getOrderDownloads", $e);
			return array();
		}
		$grants = array();
		foreach($rows as $row){
			$grants[] = array(
				'token'        => (string)$row[0],
				'expiresAt'    => (int)$row[1],
				'downloads'    => (int)$row[2],
				'maxDownloads' => (int)$row[3],
				'name'         => (string)$row[4],
			);
		}
		return $grants;
	}

	/* ---------------- supplier fulfilment ---------------- */

	private static function fulfilmentRow($row){
		return array(
			'fulfilmentId'    => (int)$row[0],
			'orderId'         => (int)$row[1],
			'provider'        => (string)$row[2],
			'providerOrderId' => (string)$row[3],
			'status'          => (string)$row[4],
			'trackingNumber'  => (string)$row[5],
			'trackingUrl'     => (string)$row[6],
			'carrier'         => (string)$row[7],
			'lastError'       => (string)$row[8],
			'attempts'        => (int)$row[9],
			'createdAt'       => (int)$row[10],
			'sentAt'          => (int)$row[11],
			'syncedAt'        => (int)$row[12],
		);
	}

	private const FULFILMENT_COLUMNS = "fulfilmentId,orderId,provider,providerOrderId,status,trackingNumber,trackingUrl,carrier,lastError,attempts,createdAt,sentAt,syncedAt";

	/*
	 * Queue one submission per provider for an order. Re-queuing an order that
	 * already has a row for that provider does nothing: the unique key is on
	 * (orderId, provider), so a capture retried by the browser cannot turn into
	 * two supplier orders.
	 */
	public function queueFulfilment($orderId, $provider){
		try{
			$this->query(
				"insert into store_order_fulfilments (orderId,provider,status,createdAt) values (?,?,'queued',?)"
				." on duplicate key update orderId=orderId",
				array((int)$orderId, (string)$provider, time())
			);
			return true;
		}catch (Throwable $e){
			ghoti::logException("store.db.php:queueFulfilment", $e);
			return false;
		}
	}

	public function getFulfilment($fulfilmentId){
		try{
			$rows = $this->queryArray("select ".self::FULFILMENT_COLUMNS." from store_order_fulfilments where fulfilmentId = ? limit 1", array((int)$fulfilmentId));
		}catch (Throwable $e){
			ghoti::logException("store.db.php:getFulfilment", $e);
			return null;
		}
		return isset($rows[0]) ? self::fulfilmentRow($rows[0]) : null;
	}

	public function getOrderFulfilments($orderId){
		try{
			$rows = $this->queryArray("select ".self::FULFILMENT_COLUMNS." from store_order_fulfilments where orderId = ? order by fulfilmentId asc", array((int)$orderId));
		}catch (Throwable $e){
			ghoti::logException("store.db.php:getOrderFulfilments", $e);
			return array();
		}
		$rowsOut = array();
		foreach($rows as $row){ $rowsOut[] = self::fulfilmentRow($row); }
		return $rowsOut;
	}

	//Work waiting to go to a supplier. 'queued' only: a failed row is left for a
	//person to look at rather than hammered on a timer.
	public function getQueuedFulfilments($limit = 25){
		$limit = max(1, min(200, (int)$limit));
		try{
			$rows = $this->queryArray("select ".self::FULFILMENT_COLUMNS." from store_order_fulfilments where status = 'queued' order by createdAt asc limit ".$limit);
		}catch (Throwable $e){
			ghoti::logException("store.db.php:getQueuedFulfilments", $e);
			return array();
		}
		$rowsOut = array();
		foreach($rows as $row){ $rowsOut[] = self::fulfilmentRow($row); }
		return $rowsOut;
	}

	//Rows worth polling for a tracking number: accepted by the supplier and not
	//yet shipped, cancelled or failed.
	public function getOpenFulfilments($limit = 50){
		$limit = max(1, min(200, (int)$limit));
		try{
			$rows = $this->queryArray("select ".self::FULFILMENT_COLUMNS." from store_order_fulfilments where status = 'sent' order by syncedAt asc limit ".$limit);
		}catch (Throwable $e){
			ghoti::logException("store.db.php:getOpenFulfilments", $e);
			return array();
		}
		$rowsOut = array();
		foreach($rows as $row){ $rowsOut[] = self::fulfilmentRow($row); }
		return $rowsOut;
	}

	/*
	 * Claim a queued row for submission. Conditional on it still being queued,
	 * so two drains - a cron run and an admin pressing Retry - cannot both send
	 * the same order to the supplier.
	 */
	public function claimFulfilment($fulfilmentId){
		try{
			$statement = $this->db()->prepare("update store_order_fulfilments set status='sending',attempts=attempts+1 where fulfilmentId=? and status in ('queued','failed')");
			$statement->execute(array((int)$fulfilmentId));
			return $statement->rowCount() > 0;
		}catch (Throwable $e){
			ghoti::logException("store.db.php:claimFulfilment", $e);
			return false;
		}
	}

	public function markFulfilmentSent($fulfilmentId, $providerOrderId, $status = 'sent'){
		try{
			$this->query(
				"update store_order_fulfilments set status=?,providerOrderId=?,lastError='',sentAt=?,syncedAt=? where fulfilmentId=?",
				array((string)$status, (string)$providerOrderId, time(), time(), (int)$fulfilmentId)
			);
			return true;
		}catch (Throwable $e){
			ghoti::logException("store.db.php:markFulfilmentSent", $e);
			return false;
		}
	}

	/*
	 * A failed attempt. $retryable decides whether it goes back on the queue or
	 * stops: a supplier that was unreachable is worth trying again, one that
	 * rejected the variant id is not, until somebody fixes the product.
	 */
	public function markFulfilmentFailed($fulfilmentId, $error, $retryable = true){
		try{
			$this->query(
				"update store_order_fulfilments set status=?,lastError=? where fulfilmentId=?",
				array($retryable ? 'queued' : 'failed', mb_substr((string)$error, 0, 500), (int)$fulfilmentId)
			);
			return true;
		}catch (Throwable $e){
			ghoti::logException("store.db.php:markFulfilmentFailed", $e);
			return false;
		}
	}

	public function updateFulfilmentTracking($fulfilmentId, $status, $tracking){
		try{
			$this->query(
				"update store_order_fulfilments set status=?,trackingNumber=?,trackingUrl=?,carrier=?,syncedAt=? where fulfilmentId=?",
				array((string)$status, mb_substr((string)$tracking['trackingNumber'], 0, 120),
					mb_substr((string)$tracking['trackingUrl'], 0, 500), mb_substr((string)$tracking['carrier'], 0, 80),
					time(), (int)$fulfilmentId)
			);
			return true;
		}catch (Throwable $e){
			ghoti::logException("store.db.php:updateFulfilmentTracking", $e);
			return false;
		}
	}

	/* ---------------- reporting ---------------- */

	public function getSalesSummary($days = 30){
		$days = max(1, min(3650, (int)$days));
		$since = time() - ($days * 86400);
		try{
			$rows = $this->queryArray(
				"select count(*),coalesce(sum(totalCents),0) from store_orders where status in ('paid','shipped') and paidAt >= ?",
				array($since)
			);
		}catch (Throwable $e){
			ghoti::logException("store.db.php:getSalesSummary", $e);
			return array('orders' => 0, 'totalCents' => 0);
		}
		return array(
			'orders'     => isset($rows[0][0]) ? (int)$rows[0][0] : 0,
			'totalCents' => isset($rows[0][1]) ? (int)$rows[0][1] : 0,
		);
	}
}
?>
