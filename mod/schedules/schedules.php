<?php
/*
 * Created on Jan 17, 2021
 * schedules Module for schedules
 * requires install of 'gpio' from wiringpi package
 *
 */
include_once('schedules.db.php');
class schedules{
	public $schedulesdb;
    public int $checkAP;
    public string $listAP;
    
	public function __construct(){
		$this->schedulesdb = new schedulesdb();
		}
}
?>
