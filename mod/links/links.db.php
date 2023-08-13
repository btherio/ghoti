<?php
/*
 * Created on Apr 2, 2009
 */

class linksdb extends ghotidb{
	public function __construct(){
		parent::__construct();
		parent::loadModuleSql("links");	
	}
	
	public function __destruct(){
		parent::__destruct();
	}
	public function getGroups(){
		try{
			$links = $this->adodb->GetArray("select distinct grp from links");
		}catch (exception $e){
			ghoti::log("links.db.php $e");
			return $e->getMessage();
		}
		return $links;
	}
	public function getLinks($group = "default"){
		try{
			if ($group === "all"){
						$links = $this->adodb->GetArray("select links.name,links.url,links.id,links.grp,users.userName from users,links where links.userId = users.userId order by links.grp;");			
			}else{
						$links = $this->adodb->GetArray("select links.name,links.url,links.id,links.grp,users.userName from users,links where links.userId = users.userId and links.grp = ?;",array($group));			
			}
		}catch (exception $e){
			ghoti::log("links.db.php $e");
			return $e->getMessage();
		}
		return $links[0]; //return the fields here for a simpler array?
	}
	function addLink($userId,$name,$url,$group="default"){
		try{
			$nonQuery = $this->adodb->Execute("insert into links(userId,name,url,grp) values(?,?,?,?)",array((integer)$userId,$name,$url,$group));
			if (!$nonQuery) ghoti::log($this->adodb->ErrorMsg());	
		}catch (exception $e){
			print_r($e);
			return false;
		}
		return true;
	}
	function checkDupe($name,$url){
		try{
			$query = $this->adodb->Execute("select count(name) from links where name = ? or url = ?",array($name,$url));
			if (!$query) ghoti::log($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("links.db.php $e");
			return false;
		}
		if($query->fields[0] > 0){ //if number of records returned is greater than 0
			return true; //we have a dupe
		}
		return false; //if we made it this far, no dupes
		
	}
	function editLink($id,$name,$url,$grp){
		try{
			$nonQuery = $this->adodb->Execute("update links set name=?,url=?,grp=? where id=?",array($name,$url,$grp,$id));
			if (!$nonQuery) ghoti::log($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("links.db.php $e");
			return false;
		}
		return true;
	}
	function deleteLink($id){
		try{
			$nonQuery = $this->adodb->Execute("delete from links where id=?",array($id));
			if (!$nonQuery) ghoti::log($this->adodb->ErrorMsg());
		}catch (exception $e){
			ghoti::log("links.db.php $e");
			return false;
		}
	}
}
?>
