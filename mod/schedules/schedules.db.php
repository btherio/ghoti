<?php
/*
 * Created on Jan 17,2021
 */

class schedulesdb extends ghotidb{
	public function __construct(){
		parent::__construct();
		parent::loadModuleSql("schedules");	
	}
	public function __destruct(){
		parent::__destruct();
	}
}
?>
