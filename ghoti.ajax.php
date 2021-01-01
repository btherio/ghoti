<?php
/*
 * Created on Jun 24, 2008
 *
 * Author: Bryan Theriault
 * 
 */ 
/*server side ajax functions*/

function getPage($content){
	//this next bit shows the comments for the current page
	$pageComments = $_SESSION["commentsObj"]->commentsdb->getPageComments($_SESSION['pageId']);
	$content.= $_SESSION["commentsObj"]->commentsui->displayComments($pageComments,true);
	
	if(checkLogin()){//print the comment button if we're logged in
		$content .= $_SESSION["commentsObj"]->commentsui->addCommentButton();
	}
	if(isSet($_SESSION['userId'])){
		if(isAdmin($_SESSION['userId'])){
			$content .= editPage($_SESSION['pageId']);
		}	
	}
	
	return stripslashes($content);
}
function getPageById($id){
	$content = $_SESSION["ghotiObj"]->ghotidb->getPageById($id);
		//set the session page id so we can pull it up any time
	$_SESSION["pageId"] = $id;
	return getPage($content[0][0]);
}
function getPageByTitle($title){
	$content = $_SESSION['ghotiObj']->ghotidb->getPageByTitle($title);
	//set the session page id so we can pull it up any time
	$_SESSION["pageId"] = $content[0][1]; 	
	return getPage($content[0][0]);
}
function editPage($id){
	//this prints the edit/delete buttons at the bottom of each page.
	$page = $_SESSION["ghotiObj"]->ghotidb->getPageById($id); //first we get the page from db
	$title = $page[0][1];
	$content = $page[0][0];
	$group = $page[0][2];
	return $_SESSION["ghotiObj"]->ghotiui->printEditPageForm($id,$title,$content,$group);
}
function getDefaultPage(){
	//returns the 'default' page. The lowest id public page. This probably obsoletes the getPageByTitle sets of functions.
	$content = $_SESSION["ghotiObj"]->ghotidb->getDefaultPage();
	$_SESSION["pageId"] = $content[0][1];
	return getPage($content[0][0]);
}
function savePage($id,$title,$content){
	$title = strip_tags($title); // we don't want html tags in the title.
	return $_SESSION['ghotiObj']->ghotidb->savePage($id,$content,$title);
}
function savePageByTitle($title,$content){
	//$title = strip_tags($title); // we don't want html tags in the title.
	return $_SESSION['ghotiObj']->ghotidb->savePageByTitle($content,$title);
}
function addPage($title){
	return $_SESSION['ghotiObj']->ghotidb->addPage($title);
}
function deletePage($id){
	return $_SESSION['ghotiObj']->ghotidb->deletePage($id);	
}

function refreshPageMenu(){
	return $_SESSION['ghotiObj']->printPageMenu(false);
}
function refreshPrivateMenu(){
	$pageList = $_SESSION['ghotiObj']->ghotidb->getPageList("private");
	return $_SESSION['ghotiObj']->ghotiui->printPageMenu($pageList,false);
}
function logToFile($line){
	ghoti::log("(UID:".$_SESSION["userId"].")".$line);
	return true;
}
function showGhotiLog(){
	return $_SESSION['ghotiObj']->ghotiui->showGhotiLog();
}
function clearGhotiLog(){
	$clearlog = `echo "" > ghoti.log`;
	return True;
}
function setPagePublic($id){
  if($_SESSION['ghotiObj']->ghotidb->setPageGroup($id,"public"))
	 return $id;
  else
	 return False;
}
function setPagePrivate($id){
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
