<?php
/*
 * Created on Jan 17,2021
 */

class Modulenamedb extends ghotidb{
	public function __construct(){
		parent::__construct();
		parent::loadModuleSql("Modulename");	
	}
	public function __destruct(){
		parent::__destruct();
	}
	
	
	function checkDupe($name,$pin){
		try{
			$query = $this->adodb->Execute("select count(name) from Modulename where name = ? or pin = ?",array($name,$pin));
			if (!$query) throw new Exception($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("Modulename.db.php $e");
			return False;
		}
		if($query->fields[0] > 0){ //if number of records returned is greater than 0
			return True; //we have a dupe
		}
		return False; //if we made it this far, no dupes
	}
    function addModulename($name,$pin,$state="off"){
		try{
			$nonQuery = $this->adodb->Execute("insert into Modulename(name,pin,state) values(?,?,?)",array($name,$pin,$state));
			if (!$nonQuery) throw new Exception($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("Modulename.db.php $e");
			return False;
		}
		return True;
	}
    function deleteModulename($id){
		try{
			$nonQuery = $this->adodb->Execute("delete from Modulename where id=?",array($id));
			if (!$nonQuery) throw new Exception($this->adodb->ErrorMsg());
		}catch (exception $e){
			ghoti::log("Modulename.db.php $e");
			return False;
		}
	}
	public function getModulename(){
        try{
            $Modulename = $this->adodb->GetArray("select id,name,pin,state from Modulename;");
		}catch (exception $e){
			ghoti::log("Modulename.db.php $e");
			return $e->getMessage();
		}
		return $Modulename; //return the fields here for a simpler array?
	}
    function modifyModulename($id,$name,$pin){
		try{
			$nonQuery = $this->adodb->Execute("update Modulename set name=?,pin=? where id=?",array($name,$pin,$id));
			if (!$nonQuery) throw new Exception($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("Modulename.db.php $e");
			return False;
		}
		return True;
	}
    function saveModulenameState($id,$state){
		try{
			$nonQuery = $this->adodb->Execute("update Modulename set state=? where id=?",array($state,$id));
			if (!$nonQuery) throw new Exception($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("Modulename.db.php $e");
			return False;
		}
		return True;
	}
}
?>
