<?php
/*
 * Created on May 29, 2010
 */

function addComment($comment){
	  //gotta get the userid and maybe pageid from session.
	 try{
		$_SESSION["ghotiObj"]->validate->checkExists($comment);
	  	$_SESSION["ghotiObj"]->validate->checkNumber($_SESSION['userId']);
		$_SESSION["ghotiObj"]->validate->checkNumber($_SESSION['pageId']);
		
		$commentId = $_SESSION["commentsObj"]->addComment($_SESSION['userId'],$_SESSION['pageId'],$comment);
	 }catch (Exception $e) {
		return $e->getMessage();
	 }
	 return $commentId;
}
sajax_export("addComment");

function getPageComments(){
  return $_SESSION["commentsObj"]->commentsui->displayComments($_SESSION["commentsObj"]->commentsdb->getPageComments($_SESSION['pageId']));
}
sajax_export("getPageComments");

function addCommentForm(){
  return $_SESSION["commentsObj"]->commentsui->addCommentForm();
}
sajax_export("addCommentForm");

function deleteComment($commentId){
	 
  return $_SESSION["commentsObj"]->commentsdb->deleteComment($commentId);
}
sajax_export("deleteComment");
?>