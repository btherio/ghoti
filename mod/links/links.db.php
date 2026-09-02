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
			$links = $this->queryArray("select distinct grp from links");
		}catch (Throwable $e){
			ghoti::logException("links.db.php:getGroups", $e);
			return $e->getMessage();
		}
		$groups = array();
		foreach($links as $row){
			$groups[] = array('grp' => isset($row[0]) ? $row[0] : '');
		}
		return $groups;
	}
	public function getLinks($group = "default"){
		try{
			if ($group === "all"){
						$links = $this->queryArray("select links.name,links.url,links.id,links.grp,users.userName from users,links where links.userId = users.userId order by links.grp;");
			}else{
						$links = $this->queryArray("select links.name,links.url,links.id,links.grp,users.userName from users,links where links.userId = users.userId and links.grp = ?;",array($group));
			}
		}catch (Throwable $e){
			ghoti::logException("links.db.php:getLinks", $e);
			return $e->getMessage();
		}
		$formattedLinks = array();
		foreach($links as $row){
			$formattedLinks[] = array(
				'name' => isset($row[0]) ? $row[0] : '',
				'url' => isset($row[1]) ? $row[1] : '',
				'id' => isset($row[2]) ? $row[2] : '',
				'grp' => isset($row[3]) ? $row[3] : '',
				'userName' => isset($row[4]) ? $row[4] : ''
			);
		}
		return $formattedLinks;
	}
	function addLink($userId,$name,$url,$group="default"){
		try{
			$this->query("insert into links(userId,name,url,grp) values(?,?,?,?)",array((int)$userId,$name,$url,$group));
		}catch (Throwable $e){
			ghoti::logException("links.db.php:addLink", $e);
			return false;
		}
		return true;
	}
	function checkDupe($name,$url){
		try{
			$query = $this->query("select count(name) from links where name = ? or url = ?",array($name,$url));
		}catch (Throwable $e){
			ghoti::logException("links.db.php:checkDupe", $e);
			return false;
		}
		if($query->fields[0] > 0){ //if number of records returned is greater than 0
			return true; //we have a dupe
		}
		return false; //if we made it this far, no dupes

	}
	function editLink($id,$name,$url,$grp){
		try{
			$this->query("update links set name=?,url=?,grp=? where id=?",array($name,$url,$grp,$id));
		}catch (Throwable $e){
			ghoti::logException("links.db.php:editLink", $e);
			return false;
		}
		return true;
	}
	function deleteLink($id){
		try{
			$this->query("delete from links where id=?",array($id));
		}catch (Throwable $e){
			ghoti::logException("links.db.php:deleteLink", $e);
			return false;
		}
	}
}
?>
