<?php
/*
 * Created on Jan 17, 2021
 * Modulename Module template
 *
 */
include_once('Modulename.db.php');
class Modulename{
	public $Modulenamedb;
    
	public function __construct(){
		$this->Modulenamedb = new Modulenamedb();
		}
}
?>
