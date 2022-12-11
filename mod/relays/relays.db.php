<?php
/*
 * Created on Jan 17,2021
 */

class relaysdb extends ghotidb{
	public function __construct(){
		parent::__construct();
		parent::loadModuleSql("relays");	
	}
	public function __destruct(){
		parent::__destruct();
	}
	
	
	function checkDupe($name,$pin){
		try{
			$query = $this->adodb->Execute("select count(name) from relays where name = ? or pin = ?",array($name,$pin));
			if (!$query) throw new Exception($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("relays.db.php $e");
			return False;
		}
		if($query->fields[0] > 0){ //if number of records returned is greater than 0
			return True; //we have a dupe
		}
		return False; //if we made it this far, no dupes
	}
    function addRelay($name,$pin,$state="off"){
		try{
			$nonQuery = $this->adodb->Execute("insert into relays(name,pin,state) values(?,?,?)",array($name,$pin,$state));
			if (!$nonQuery) throw new Exception($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("relays.db.php $e");
			return False;
		}
		return True;
	}
    function deleteRelay($id){
		try{
			$nonQuery = $this->adodb->Execute("delete from relays where id=?",array($id));
			if (!$nonQuery) throw new Exception($this->adodb->ErrorMsg());
		}catch (exception $e){
			ghoti::log("relays.db.php $e");
			return False;
		}
	}
	public function getRelays($limit){
        try{
            $relays = $this->adodb->GetArray("select id,name,pin,state from relays where pin < ?;",array($limit));
		}catch (exception $e){
			ghoti::log("relays.db.php $e");
			return $e->getMessage();
		}
		return $relays; //return the fields here for a simpler array?
	}
	public function getRelayById($id){
        try{
            $relays = $this->adodb->GetArray("select id,name,pin,state from relays where id=?;",array($id));
		}catch (exception $e){
			ghoti::log("relays.db.php $e");
			return $e->getMessage();
		}
		return $relays; //return the fields here for a simpler array?
	}	
	public function getRelayNameByPin($pin){
        try{
            $relays = $this->adodb->GetArray("select name from relays where pin=?;",array($pin));
		}catch (exception $e){
			ghoti::log("relays.db.php $e");
			return $e->getMessage();
		}
		return $relays; //return the fields here for a simpler array?
	}
	function modifyRelay($id,$name,$pin){
		try{
			$nonQuery = $this->adodb->Execute("update relays set name=?,pin=? where id=?",array($name,$pin,$id));
			if (!$nonQuery) throw new Exception($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("relays.db.php $e");
			return False;
		}
		return True;
	}
    function saveRelayState($id,$state){
		try{
			$nonQuery = $this->adodb->Execute("update relays set state=? where id=?",array($state,$id));
			if (!$nonQuery) throw new Exception($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("relays.db.php $e");
			return False;
		}
		return True;
	}
}
?>
