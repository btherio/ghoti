<?php
/*
 * store.php - store module entry point.
 *
 * A small shop for physical goods, downloads, and provisioned services, with
 * one-time payment through PayPal or cryptocurrency, and recurring payment
 * through PayPal. Same shape as every other
 * module: this file wires the db + async (endpoints/UI) classes together into
 * one object index.php parks in $_SESSION.
 *
 *   store.db.php      - settings, catalogue, orders, download grants
 *   store.paypal.php  - dependency-free PayPal Orders v2 client (class
 *                       StorePaypalClient), with an injectable transport
 *   store.crypto.php  - NOWPayments client for Bitcoin and other currencies
 *   store.cards.php   - Stripe PaymentIntents and Square Payments clients
 *   store.dropship.php- supplier fulfilment drivers (Printful, Printify, CJ
 *                       Dropshipping, generic webhook) behind one interface
 *   store.async.php   - storefront + management endpoints, [store:CATEGORY]
 *                       shortcode, and class storeui
 *   store.download.php- delivers a purchased digital file (plain URL, token)
 *   store.fulfil.php  - CLI drain for the supplier queue (cron)
 *
 * The module is optional and off by default: enable it under Site Settings →
 * Optional modules. Nothing is for sale until a payment provider is configured
 * under Admin Menu → Store → Payments & shipping. Spring-hosted listings only
 * need the product URL and can be used without payment credentials.
 */
include_once('store.db.php');
include_once('store.paypal.php');
include_once('store.crypto.php');
include_once('store.cards.php');
include_once('store.dropship.php');
include_once('store.async.php'); //endpoints + shortcode + class storeui
class store{
	public $storedb,$storeui;
	public function __construct(){
		$this->storedb = new storedb();
		$this->storeui = new storeui();
	}

	//Convenience entry point for other PHP in this app: the settings the
	//storefront needs to decide whether it can take money at all.
	public function ready(){
		$settings = $this->storedb->getSettings();
		return StorePaypalClient::configured($settings) || StoreCryptoClient::configured($settings)
			|| StoreStripeClient::configured($settings) || StoreSquareClient::configured($settings);
	}

	//Extra content-security-policy origins for the card processors that are
	//actually configured, so a shop without Stripe or Square keeps the tighter
	//policy. Keyed script/frame/connect/style/font. An unreadable settings row
	//reports none - the safe direction, as for banners' adsActive().
	public function cardCspOrigins(){
		$out = array('script'=>array(), 'frame'=>array(), 'connect'=>array(), 'style'=>array(), 'font'=>array());
		try{ $settings = $this->storedb->getSettings(); }
		catch(Throwable $e){ return $out; }
		$add = array();
		if(StoreStripeClient::configured($settings)){ $add[] = StoreStripeClient::cspOrigins(); }
		if(StoreSquareClient::configured($settings)){ $add[] = StoreSquareClient::cspOrigins($settings); }
		foreach($add as $origins){
			foreach($out as $key => $list){ $out[$key] = array_merge($list, $origins[$key]); }
		}
		return $out;
	}
}
?>
