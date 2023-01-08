<?php
/*
 * Created on Apr 3, 2009
 */
 
class logindb extends ghotidb{
	public function __construct(){
		parent::__construct();
		parent::loadModuleSql("login");
	}
	public function __destruct(){
		parent::__destruct();
	}
	
	public function addUser($userName,$password,$email){
		$newId = array();
		if(!$this->checkDuplicate($userName,$email)){
			try{
				//execute the sql
				$nonQuery = $this->adodb->Execute("insert into users(userName,password,email,admin) values(?,?,?,?)",array($userName,$password,$email,false));
				if (!$nonQuery) ghoti::log($this->adodb->ErrorMsg()); //log any mysql errors
				
				$query = $this->adodb->Execute("select count(userId) from users;");//select the usercount to see if we should make them admin
				if(!$query) ghoti::log($this->adodb->ErrorMsg());
				
				if($query->fields[0] === '1'){ //if the count is 1, then this is the first user and we want to grant admin rights
					$nonquery = $this->adodb->Execute("update users set admin = ? where admin = 0",array("1"));
					if (!$nonquery) ghoti::log($this->adodb->ErrorMsg());
				}
				
			}catch (exception $e){
				ghoti::log("login.db.php $e");
				return false;
			}
			
			return true;	
		}else{
			return false;
		}
		
	}
	public function checkDuplicate($userName,$email){
		$result = array();
		try{
			$records = $this->adodb->Execute("select userId from users where userName = ? or email = ?",array($userName,$email));
			if (!$records) ghoti::log($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("login.db.php $e");
			return false;
		}
		foreach ($records as $records=>$row){
			$result[0] .= $row[0];
		}
		if($result[0] > 0){
			//if this is true then we must have found a duplicate
			return true;
		}else{
			//no dupes
			return false;
		}
	}
	public function isAdmin($id){
		$this->result = array();
		$this->result[0] = "";
		try{
			$records = $this->adodb->Execute("select admin from users where userId = ?",array($id));
		}catch (exception $e){
			ghoti::log("login.db.php $e");
			return false;
		}
		foreach ($records as $records=>$row){
			$this->result[0] .= $row[0];
		}
		if($this->result[0] === '1')
		  return true;
		else
			return false;
		
	}
	
	public function authenticate($userName,$password){
		$result = array("");
		try{
			$auth = $this->adodb->Execute("select userId from users where userName = ? and password = ?",array($userName,$password));
			if (!$auth) ghoti::log($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("login.db.php $e");
			return false;
		}
		foreach ($auth as $records=>$row){
			$result[0] .= $row[0];
		}
		//returns authenticated userid
		return $result[0];
	}
	
	public function getUserList(){
		try{
			$userList = $this->adodb->Execute("select userId,userName,email,admin from users");
			if (!$userList) ghoti::log($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("login.db.php $e");
			return false;
		}
		return $userList;
	}
	public function updateUser($userId,$userName,$email){
		try{
			$rs = $this->adodb->Execute("update users set userName = ?, email = ? where userId = ?",array($userName,$email,$userId));
			if (!$rs) ghoti::log($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("login.db.php $e");
			return false;
		}
		return true;
	}
	public function deleteUser($userId){
		try{
			$numberOfAdmins = $this->adodb->Execute("select count(userId) from users where admin = 1;");//select the usercount to see if we should make them admin
			if(!$numberOfAdmins) ghoti::log($this->adodb->ErrorMsg());
			if($numberOfAdmins->fields[0] === '1' && isAdmin($userId)){ //if the count is 1, then there's only 1 admin left and Im it, then we don't want to delete.
					Throw new Exception("Can't delete only admin.");
			}else{
				$users = $this->adodb->Execute("delete from users where userId = ?;",array($userId));
				if (!$users) ghoti::log($this->adodb->ErrorMsg());
				$comments = $this->adodb->Execute("delete from comments where userId = ?;",array($userId));
				if (!$comments) ghoti::log($this->adodb->ErrorMsg());
			}
		}catch (exception $e){
			ghoti::log("login.db.php ".$e->getMessage());
			return $e->getMessage();
		}
		return true;	
	}
	public function checkForLastAdmin(){
		try{

		}catch(Exception $e){
			return $e->getMessage();
		}
		return true; //if we made it this far.
	}
	public function toggleAdmin($userId){
		try{
			if(isAdmin($userId)){
				$numberOfAdmins = $this->adodb->Execute("select count(userId) from users where admin = 1;");//select the usercount to see if we should make them admin
				if(!$numberOfAdmins) ghoti::log($this->adodb->ErrorMsg());
				if($numberOfAdmins->fields[0] === '1'){ //if the count is 1, then there's only 1 admin left(us presumably) and we don't want to toggle.
					Throw new Exception("Can't revoke admin rights from only admin.");
				}else{
					$rs = $this->adodb->Execute("update users set admin = 0 where userId = ?;",array($userId)); //toggle it off	
				}
			}else{
				$rs = $this->adodb->Execute("update users set admin = 1 where userId = ?;",array($userId)); //toggle it on
			}
			if (!$rs) ghoti::log($this->adodb->ErrorMsg());
		}catch (Exception $e){
			ghoti::log("login.db.php ".$e->getMessage()); //we only want to log the message this time.
			return $e->getMessage();
		}
		return true;
	}
	public function changePassword($userId,$password){
		try{
			$sql = $this->adodb->Execute("update users set password = ? where userId = ?",array($password,$userId));
		}catch (exception $e){
			ghoti::log("login.db.php $e");
			return false;		
		}
		return true;
	}
	public function getUserNameById($userId){
		try{
			$query = $this->adodb->Execute("select userName from users where userId = ?",array($userId));
			if (!$query) ghoti::log($this->adodb->ErrorMsg());
		}catch (exception $e){
			ghoti::log("login.db.php $e");
			return false;		
		}
		return $query->fields[0];			
	}
}
?>
