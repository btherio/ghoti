<?php
/*
 * Created on May 28, 2010
 */
include_once('comments.db.php');
include_once('comments.ui.php');
class comments{
	public $m_commentId = 0;
	public $m_userId = 0;
	public $m_pageId = 0;
	public $m_comment = "";

	public function __construct(){
		$this->commentsdb = new commentsdb();
		$this->commentsui = new commentsui();
	}	
	public function addComment($userId,$pageId,$comment){
		$this->m_userId = $userId;
		$this->m_pageId = $pageId;
		$this->m_comment = $comment;				
		$this->m_commentId = $this->commentsdb->addComment($this);
		return $this->m_commentId;
	}

}
?>
