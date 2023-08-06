<?php
/*
 * Created on Jun 24, 2008
 *
 * Author: Bryan Theriault
 * 
 */ 
/*server side ajax functions*/

function getPage($content){
    $_SESSION["ghotiObj"] = new ghoti();
    $_SESSION["commentsObj"] = new comments();
    $_SESSION["ghotiObj"]->ghotidb = new ghotidb();
	//this next bit shows the comments for the current page
	$pageComments = $_SESSION["commentsObj"]->commentsdb->getPageComments($_SESSION['pageId']);
	$content.= $_SESSION["commentsObj"]->commentsui->displayComments($pageComments,true);
	
	if(checkLogin()){//print the comment button if we're logged in
		$content .= $_SESSION["commentsObj"]->commentsui->addCommentButton();
	}
	if(isSet($_SESSION['userId'])){
		if(isAdmin($_SESSION['userId'])){
			ghoti::log("Adding editPage form to content");
			$content .= editPage($_SESSION['pageId']);
		}	
	}
	
	return stripslashes($content);
}
function getPageById($id){
    $_SESSION["ghotiObj"]->ghotidb = new ghotidb();
	$content = $_SESSION["ghotiObj"]->ghotidb->getPageById($id);
		//set the session page id so we can pull it up any time
	$_SESSION["pageId"] = $id;
	return getPage($content[0][0]);
}
function getPageByTitle($title){
    $_SESSION["ghotiObj"]->ghotidb = new ghotidb();
	$content = $_SESSION['ghotiObj']->ghotidb->getPageByTitle($title);
	//set the session page id so we can pull it up any time
	$_SESSION["pageId"] = $content[0][1]; 	
	return getPage($content[0][0]);
}
function editPage($id){
	//this prints the edit/delete buttons at the bottom of each page.
	$_SESSION["ghotiObj"] = new ghoti();
	$_SESSION["ghotiObj"]->ghotidb = new ghotidb();
	$_SESSION["ghotiObj"]->ghotiui = new ghotiui();
	$page = $_SESSION["ghotiObj"]->ghotidb->getPageById($id); //first we get the page from db
	$title = $page[0][1];
	$content = $page[0][0];
	$group = $page[0][2];
	return $_SESSION["ghotiObj"]->ghotiui->printEditPageForm($id,$title,$content,$group);
}
function getDefaultPage(){
    
    $_SESSION["ghotiObj"]->ghotidb = new ghotidb();
    $content = $_SESSION['ghotiObj']->ghotidb->getDefaultPage();
    $_SESSION["pageId"] = $content[0][1];
    return getPage($content[0][0]);
}
function savePage($id,$title,$content){
    $_SESSION["ghotiObj"] = new ghoti();
	$title = strip_tags($title); // we don't want html tags in the title.
	return $_SESSION['ghotiObj']->ghotidb->savePage($id,$content,$title);
}
function savePageByTitle($title,$content){
    $_SESSION["ghotiObj"] = new ghoti();
	//$title = strip_tags($title); // we don't want html tags in the title.
	return $_SESSION['ghotiObj']->ghotidb->savePageByTitle($content,$title);
}
function addPage($title){
    //$_SESSION["ghotiObj"] = new ghoti();
    //$_SESSION["ghotiObj"]->ghotidb = new ghotidb();
	$ghoti = new ghoti();
	return $ghoti->ghotidb->addPage($title);
}
function deletePage($id){
    $_SESSION["ghotiObj"] = new ghoti();
	return $_SESSION['ghotiObj']->ghotidb->deletePage($id);	
}

function refreshPageMenu(){
    $_SESSION['ghotiObj'] = new ghoti();
	return $_SESSION['ghotiObj']->printPageMenu(false);
}
function refreshPrivateMenu() {   
    $_SESSION['ghotiObj'] = new ghoti();
    $_SESSION["ghotiObj"]->ghotiui = new ghotiui();
    $_SESSION['ghotiObj']->ghotidb = new ghotidb();
    //$pageList = $_SESSION['ghotiObj']->ghotidb?->getPageList("private");
    $pageList = $_SESSION['ghotiObj']?->ghotidb->getPageList("private");
    return $_SESSION['ghotiObj']->ghotiui?->printPageMenu($pageList,false);
}
function logToFile($line){
	ghoti::log("(UID:".$_SESSION["userId"].")".$line);
	return true;
}
function showGhotiLog(){   
    $_SESSION['ghotiObj'] = new ghoti();
    $_SESSION["ghotiObj"]->ghotiui = new ghotiui();
	return $_SESSION['ghotiObj']->ghotiui->showGhotiLog();
}
function clearGhotiLog(){
	$clearlog = `echo "" > ghoti.log`;
	return True;
}
function setPagePublic($id){
    $_SESSION["ghotiObj"] = new ghoti();
  if($_SESSION['ghotiObj']->ghotidb->setPageGroup($id,"public"))
	 return $id;
  else
	 return False;
}
function setPagePrivate($id){
    $_SESSION["ghotiObj"] = new ghoti();
  if($_SESSION['ghotiObj']->ghotidb->setPageGroup($id,"private"))
	 return $id;
  else
	 return false;
}
sajax_export("setPagePublic");
sajax_export("setPagePrivate");
sajax_export("clearGhotiLog");
sajax_export("showGhotiLog");
sajax_export("logToFile");
sajax_export("printPageTitle");
sajax_export("getPage");
sajax_export("getDefaultPage");
sajax_export("getPageByTitle");
sajax_export("getPageById");
sajax_export("editPage");
sajax_export("savePage");
sajax_export("savePageByTitle");
sajax_export("addPage");
sajax_export("deletePage");
sajax_export("refreshPageMenu");
sajax_export("refreshPrivateMenu");
?>
