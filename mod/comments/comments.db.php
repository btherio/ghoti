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
			$dbresult = $this->adodb->Execute("insert into comments(userId,pageId,comment) values(?,?,?)",array($comment->m_userId,$comment->m_pageId,$comment->m_comment));
			if (!$dbresult){
				mylogerr($this->adodb->ErrorMsg());	
				ghoti::log("comments.db.php: $this->adodb->ErrorMsg()");
				throw new Exception ('Database error. Check ghoti logs.');
			}
		}catch (exception $e){
			ghoti::log("comments.db.php $e");
			return false;
		}
		return $dbresult->fields[0]; //should return newly created commentId
	}
	
	function getPageComments($pageId){
		try{	
			$dbresult = $this->adodb->Execute("SELECT comments.commentId,users.userName,comments.comment,comments.userId FROM `comments`,`users` where users.userId = comments.userId AND pageId = ? order by commentId;",array($pageId));
			if (!$dbresult){
				mylogerr($this->adodb->ErrorMsg());	
				ghoti::log("comments.db.php: $this->adodb->ErrorMsg()");
				throw new Exception ('Database error. Check ghoti logs.');
			}
		}catch (exception $e){
			ghoti::log("comments.db.php $e");
			return false;
		}
		return $dbresult;
	}
	
	function deleteComment($commentId){
		try{	
			$dbresult = $this->adodb->Execute("delete from comments where commentId = ?",array($commentId));
			if (!$dbresult){
				mylogerr($this->adodb->ErrorMsg());	
				ghoti::log("comments.db.php: $this->adodb->ErrorMsg()");
				throw new Exception ('Database error. Check ghoti logs.');
			}
		}catch (exception $e){
			ghoti::log("comments.db.php $e");
			return false;
		}
		return $dbresult;
	}
	
	
}
?>
