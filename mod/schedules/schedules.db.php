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
	
	
	function checkDupe($name,$pin){
		try{
			$query = $this->adodb->Execute("select count(name) from schedules where name = ? or pin = ?",array($name,$pin));
			if (!$query) throw new Exception($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("schedules.db.php $e");
			return False;
		}
		if($query->fields[0] > 0){ //if number of records returned is greater than 0
			return True; //we have a dupe
		}
		return False; //if we made it this far, no dupes
	}
    function addSchedule($name,$pin,$state="off"){
		try{
			$nonQuery = $this->adodb->Execute("insert into schedules(name,pin,state) values(?,?,?)",array($name,$pin,$state));
			if (!$nonQuery) throw new Exception($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("schedules.db.php $e");
			return False;
		}
		return True;
	}
    function deleteSchedule($id){
		try{
			$nonQuery = $this->adodb->Execute("delete from schedules where id=?",array($id));
			if (!$nonQuery) throw new Exception($this->adodb->ErrorMsg());
		}catch (exception $e){
			ghoti::log("schedules.db.php $e");
			return False;
		}
	}
	public function getschedules(){
        try{
            $schedules = $this->adodb->GetArray("select id,name,pin,state from schedules;");
		}catch (exception $e){
			ghoti::log("schedules.db.php $e");
			return $e->getMessage();
		}
		return $schedules; //return the fields here for a simpler array?
	}
    function modifySchedule($id,$name,$pin){
		try{
			$nonQuery = $this->adodb->Execute("update schedules set name=?,pin=? where id=?",array($name,$pin,$id));
			if (!$nonQuery) throw new Exception($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("schedules.db.php $e");
			return False;
		}
		return True;
	}
    function saveschedulestate($id,$state){
		try{
			$nonQuery = $this->adodb->Execute("update schedules set state=? where id=?",array($state,$id));
			if (!$nonQuery) throw new Exception($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("schedules.db.php $e");
			return False;
		}
		return True;
	}
}
?>
