<?php
include_once('filemanager.async.php'); //endpoints + class filemanagerui
class filemanager{
	public function __construct(){
		$this->filemanagerui = new filemanagerui();
	}
}
?>
