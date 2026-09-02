<?php
include_once('gallery.db.php');
include_once('gallery.async.php'); //endpoints + class galleryui
class gallery{
	public function __construct(){
		$this->gallerydb = new gallerydb();
		$this->galleryui = new galleryui();
	}
}
?>
