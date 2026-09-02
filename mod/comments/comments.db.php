<?php
/*
 * Created on May 28, 2010
 */

class commentsdb extends ghotidb{
	function __construct(){
		parent::__construct(); //this establishes our connection to the database.
		parent::loadModuleSql("comments"); //makes sure our sql is loaded for this module
	}
	function __destruct(){
		parent::__destruct();
	}
	function addComment($comment){
		try{
			$dbresult = $this->query("insert into comments(userId,pageId,comment) values(?,?,?)",array($comment->m_userId,$comment->m_pageId,$comment->m_comment));
		}catch (Throwable $e){
			ghoti::logException("comments.db.php:addComment", $e);
			return false;
		}
		//ghoti::debug("comments.db.php.addComment result: ".$dbresult->fields[0]);
		//return $dbresult->fields[0]; //should return newly created commentId
		return true;

	}

	function getPageComments($pageId){
		try{
			$dbresult = $this->query("SELECT comments.commentId,users.userName,comments.comment,comments.userId FROM `comments`,`users` where users.userId = comments.userId AND pageId = ? order by commentId;",array($pageId));
		}catch (Throwable $e){
			ghoti::logException("comments.db.php:getPageComments", $e);
			return false;
		}
		return $dbresult;
	}

	function deleteComment($commentId){
		try{
			$dbresult = $this->query("delete from comments where commentId = ?",array($commentId));
		}catch (Throwable $e){
			ghoti::logException("comments.db.php:deleteComment", $e);
			return false;
		}
		return $dbresult;
	}

	//Returns the userId that owns a comment, or false if not found / on error.
	//Used to enforce "author or admin" deletion server-side.
	function getCommentOwner($commentId){
		try{
			$dbresult = $this->queryArray("select userId from comments where commentId = ?",array((int)$commentId));
		}catch (Throwable $e){
			ghoti::logException("comments.db.php:getCommentOwner", $e);
			return false;
		}
		return isset($dbresult[0][0]) ? (int)$dbresult[0][0] : false;
	}


}
?>
