<?php
/*
 * Created on Apr 2, 2009
 */
include_once('links.db.php');
include_once('links.ui.php');
class links{
	public $linksdb,$linksui;
	public function __construct(){
		$this->linksdb = new linksdb();
		$this->linksui = new linksui();
	}

	/*public function getLinks($group="default",$div=true){
		$links = $this->linksdb->getLinks($group);
		return $this->linksui->printLinks($links,$div);
	}*/

}
?>
