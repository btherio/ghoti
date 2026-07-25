<?php
/*
 * links.async.php - links module async layer.
 *
 * Combines the former links.ajax.php (endpoints) and links.ui.php (class
 * linksui) into one file, registered through the ghoti async wrapper.
 *
 * Note: the old links.ajax.php also sajax_export()ed "editLinkForm" and
 * "addLinkForm", but those are client-side functions in links.js with no PHP
 * counterpart, so they were never actually callable server-side and are not
 * registered here.
 */

/* ---------------------------------------------------------------- *
 *  Endpoints (formerly links.ajax.php)
 * ---------------------------------------------------------------- */

function getLinks($group="default"){
	//"all" is a valid selector the admin edit view uses; otherwise constrain the
	//group to the safe slug charset before it hits the query.
	$group = ($group === "all") ? "all" : ghoti_validate()->linkGroup($group);
	return array($group,$_SESSION["linksObj"]->linksdb->getLinks($group));
}
function getLinkGroups(){
	return $_SESSION["linksObj"]->linksdb->getGroups();
}

function addLink($name,$url,$group){
	if(!ghoti_require_admin()){ return "Admin access required."; }
	$userId = checkLogin();
	try{
		$v = ghoti_validate();
		$name  = $v->text($name, validate::MAX_LINK_NAME, true, "link name");
		//url() rejects javascript:/data:/etc. - link urls are rendered straight
		//into an <a href> in the sidebar, so an unchecked scheme is script exec.
		$url   = $v->url($url, true, "link URL");
		$group = $v->linkGroup($group, true);
	}catch(Exception $e){
		ghoti::log("links.async.php: ".$e->getMessage());
		return $e->getMessage();
	}
	$_SESSION["ghotiObj"]->log("Adding a link to $name into the group $group. $url");

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
	if(!ghoti_require_admin()){ return false; }
	try{
		$id = ghoti_validate()->id($id, "link id");
	}catch(Exception $e){
		ghoti::log("links.async.php: ".$e->getMessage());
		return False;
	}
	$_SESSION["linksObj"]->linksdb->deleteLink($id);
	return True;
}
function saveLink($id,$name,$url,$grp){
	if(!ghoti_require_admin()){ return false; }
	try{
		$v = ghoti_validate();
		$id   = $v->id($id, "link id");
		$name = $v->text($name, validate::MAX_LINK_NAME, true, "link name");
		$url  = $v->url($url, true, "link URL"); // scheme-checked, see addLink()
		$grp  = $v->linkGroup($grp, true);
	}catch(Exception $e){
		ghoti::log("links.async.php: ".$e->getMessage());
		return $e->getMessage();
	}
	$_SESSION["ghotiObj"]->log("Saving link($id:$name:$url:$grp)");
	return $_SESSION["linksObj"]->linksdb->editLink($id,$name,$url,$grp);
}

ghoti_async_register(
	"saveLink",
	"addLink",
	"getLinks",
	"getLinkGroups",
	"deleteLink"
);

/* ---------------------------------------------------------------- *
 *  UI renderer (formerly links.ui.php / class linksui)
 * ---------------------------------------------------------------- */

class linksui{
 //replaced with jquery.
}
