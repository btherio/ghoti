<?php
/*
 * Analytics module - complements the admin Log page with real usage data:
 * pageviews, unique visitors, browsers/OS/devices, top pages, referrers,
 * charted and tabulated, with a CSV export.
 */
include_once('analytics.db.php');
include_once('analytics.async.php'); //endpoints + trackPageView hook + class analyticsui
class analytics{
	public $analyticsdb,$analyticsui;
	public function __construct(){
		$this->analyticsdb = new analyticsdb();
		$this->analyticsui = new analyticsui();
	}
}
?>
