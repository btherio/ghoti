<?php
/*
 * Created on Apr 2, 2009
 */
include_once('links.db.php');
include_once('links.async.php'); //endpoints + the [links:group] shortcode
class links{
	public $linksdb;
	public function __construct(){
		$this->linksdb = new linksdb();
	}
}
?>
