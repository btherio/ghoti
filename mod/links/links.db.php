<?php
/*
 * links.db.php - persistence for the links module.
 *
 * Every method returns false (after logging) when the database fails, so the
 * endpoints can tell "nothing matched" (null / empty) from "the query broke"
 * and report them differently.
 */

class linksdb extends ghotidb{
	public function __construct(){
		parent::__construct();
		parent::loadModuleSql("links");
	}

	public function __destruct(){
		parent::__destruct();
	}

	//Distinct group names, "default" first. false on a database error.
	public function getGroups(){
		try{
			$rows = $this->queryArray("select distinct grp from links order by grp");
		}catch (Throwable $e){
			ghoti::logException("links.db.php:getGroups", $e);
			return false;
		}
		$groups = array();
		foreach($rows as $row){
			$groups[] = isset($row[0]) ? (string)$row[0] : '';
		}
		$default = array_search('default', $groups, true);
		if($default !== false && $default > 0){
			array_splice($groups, $default, 1);
			array_unshift($groups, 'default');
		}
		return $groups;
	}

	//Links in one group, or every link for "all". LEFT JOIN so a link whose
	//owner was deleted still shows up in the admin list instead of becoming
	//impossible to edit or remove.
	public function getLinks($group = "default"){
		$sql = "select links.name,links.url,links.id,links.grp,coalesce(users.userName,'') from links left join users on links.userId = users.userId";
		try{
			if($group === "all"){
				$rows = $this->queryArray($sql." order by links.grp,links.id");
			}else{
				$rows = $this->queryArray($sql." where links.grp = ? order by links.id",array($group));
			}
		}catch (Throwable $e){
			ghoti::logException("links.db.php:getLinks", $e);
			return false;
		}
		$links = array();
		foreach($rows as $row){
			$links[] = $this->formatRow($row);
		}
		return $links;
	}

	//One link, null when the id does not exist, false on a database error.
	public function getLink($id){
		try{
			$rows = $this->queryArray("select links.name,links.url,links.id,links.grp,coalesce(users.userName,'') from links left join users on links.userId = users.userId where links.id = ?",array((int)$id));
		}catch (Throwable $e){
			ghoti::logException("links.db.php:getLink", $e);
			return false;
		}
		return $rows ? $this->formatRow($rows[0]) : null;
	}

	//Which field ("url" or "name") already exists in $group, ignoring the link
	//being edited; null when nothing collides, false on a database error. The
	//same address in two groups is fine - a group is a category.
	public function findDuplicate($name,$url,$group,$excludeId = 0){
		//Placeholders in order: url (selected), group, excludeId, url, name, url (sort
		//exact-URL matches first so the message names the more useful collision).
		try{
			$rows = $this->queryArray("select url = ? from links where grp = ? and id <> ? and (url = ? or name = ?) order by url = ? desc limit 1",array($url,$group,(int)$excludeId,$url,$name,$url));
		}catch (Throwable $e){
			ghoti::logException("links.db.php:findDuplicate", $e);
			return false;
		}
		if(!$rows){ return null; }
		return ((int)$rows[0][0] === 1) ? 'url' : 'name';
	}

	//The new link's id, or false.
	public function addLink($userId,$name,$url,$group="default"){
		try{
			$this->query("insert into links(userId,name,url,grp) values(?,?,?,?)",array((int)$userId,$name,$url,$group));
			return (int)$this->db()->lastInsertId();
		}catch (Throwable $e){
			ghoti::logException("links.db.php:addLink", $e);
			return false;
		}
	}

	public function editLink($id,$name,$url,$grp){
		try{
			$this->query("update links set name=?,url=?,grp=? where id=?",array($name,$url,$grp,(int)$id));
		}catch (Throwable $e){
			ghoti::logException("links.db.php:editLink", $e);
			return false;
		}
		return true;
	}

	public function deleteLink($id){
		try{
			$this->query("delete from links where id=?",array((int)$id));
		}catch (Throwable $e){
			ghoti::logException("links.db.php:deleteLink", $e);
			return false;
		}
		return true;
	}

	private function formatRow($row){
		return array(
			'id' => (int)$row[2],
			'name' => (string)$row[0],
			'url' => (string)$row[1],
			'grp' => (string)$row[3],
			'userName' => (string)$row[4]
		);
	}
}
?>
