<?php
/*
 * Created on May 1, 2010
 */
include_once('banners.db.php');
include_once('banners.ui.php');
class banners{
	public $bannersdb,$bannersui;
	public function __construct(){
		$this->bannersdb = new bannersdb();
		$this->bannersui = new bannersui();
	}
	public function displayBanner($smallBanner=true){
			return $this->bannersui->displayBanner($this->bannersdb->getRandomBanner($smallBanner));
	}
}
?>
