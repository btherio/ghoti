<?php
/*
 * store.php - store module entry point.
 *
 * A small shop that takes payment through PayPal. Same shape as every other
 * module: this file wires the db + async (endpoints/UI) classes together into
 * one object index.php parks in $_SESSION.
 *
 *   store.db.php      - settings, catalogue, orders, download grants
 *   store.paypal.php  - dependency-free PayPal Orders v2 client (class
 *                       StorePaypalClient), with an injectable transport
 *   store.dropship.php- supplier fulfilment drivers (Printful, Printify, CJ
 *                       Dropshipping, generic webhook) behind one interface
 *   store.async.php   - storefront + management endpoints, [store:CATEGORY]
 *                       shortcode, and class storeui
 *   store.download.php- delivers a purchased digital file (plain URL, token)
 *   store.fulfil.php  - CLI drain for the supplier queue (cron)
 *
 * The module is optional and off by default: enable it under Site Settings →
 * Optional modules. Nothing is for sale until PayPal credentials are saved
 * under Admin Menu → Store → PayPal & shipping. Spring-hosted listings only
 * need the product URL and can be used without PayPal credentials.
 */
include_once('store.db.php');
include_once('store.paypal.php');
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
		return StorePaypalClient::configured($this->storedb->getSettings());
	}
}
?>
