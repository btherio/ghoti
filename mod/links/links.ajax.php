<?php
/*
 * Created on Apr 2, 2009
 */

function getLinks($group="default"){
	return array($group,$_SESSION["linksObj"]->linksdb->getLinks($group));
}
function getLinkGroups(){
	return $_SESSION["linksObj"]->linksdb->getGroups();
}

function addLink($name,$url,$group){
	$userId = checkLogin();
	try{
		$_SESSION["ghotiObj"]->validate->checkExists($name);
		$_SESSION["ghotiObj"]->validate->checkExists($url);
		$_SESSION["ghotiObj"]->validate->checkExists($group);
	}catch(Exception $e){
		ghoti::log("links.ajax.php: $e\n");
		return $e->getMessage();
	}
	$_SESSION["ghotiObj"]->log("Adding a link to $name into the group $group. $url");
	// wtf?
	//if(substr($url,0,7) !== "http://") //so likes.. add it if we need it right? bad for https:// urls though. :(
	//	$url = "http://".$url;
	
	if(!$_SESSION["linksObj"]->linksdb->checkDupe($name,$url)){
		if(!$_SESSION["linksObj"]->linksdb->addLink($userId,$name,$url,$group)){
			$_SESSION["ghotiObj"]->log("Failed to add link. $url");			
			return False;
		}
	}else{		
		$_SESSION["ghotiObj"]->log("Attempted to add duplicate link. $url");
		return False;
	}
	$_SESSION["ghotiObj"]->log("Added a link to $name into the group $group. $url");
	return True;
}

function deleteLink($id){
	try{
		$_SESSION["ghotiObj"]->validate->checkNumber($id);
	}catch(Exception $e){
		ghoti::log("links.ajax.php: $e\n");
		return False;
	}
 	$_SESSION["linksObj"]->linksdb->deleteLink($id);
	return True;
}
function saveLink($id,$name,$url,$grp){
	try{
		$_SESSION["ghotiObj"]->validate->checkNumber($id);
		$_SESSION["ghotiObj"]->validate->checkExists($name);
		$_SESSION["ghotiObj"]->validate->checkExists($url);
		$_SESSION["ghotiObj"]->validate->checkExists($grp);
	}catch(Exception $e){
		ghoti::log("links.ajax.php: $e\n");
		return False;
	}
	$_SESSION["ghotiObj"]->log("Saving link($id:$name:$url:$grp)");
	return $_SESSION["linksObj"]->linksdb->editLink($id,$name,$url,$grp);
}
sajax_export("saveLink");
sajax_export("addLink");
sajax_export("editLinkForm");
sajax_export("addLinkForm");
sajax_export("getLinks");
sajax_export("getLinkGroups");
sajax_export("deleteLink");
?>
