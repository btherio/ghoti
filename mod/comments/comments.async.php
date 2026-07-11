<?php
/*
 * comments.async.php - comments module async layer.
 *
 * Combines the former comments.ajax.php (endpoints) and comments.ui.php (class
 * commentsui) into one file, registered through the ghoti async wrapper.
 */

/* ---------------------------------------------------------------- *
 *  Endpoints (formerly comments.ajax.php)
 * ---------------------------------------------------------------- */

function addComment($comment){
	  if(!ghoti_require_login()){ return "You must be logged in to comment."; }
	  //gotta get the userid and maybe pageid from session.
	 try{
		$v = ghoti_validate();
		//Strip tags, drop stray control chars and cap the length. The stored value
		//is still htmlspecialchars()'d on display (commentsui::displayComments);
		//sanitizing here is defense in depth and keeps junk out of the table.
		$comment = $v->multilineText($comment, validate::MAX_COMMENT, true, "comment");
		$userId = $v->id($_SESSION['userId'] ?? 0, "user id");
		$pageId = $v->id($_SESSION['pageId'] ?? 0, "page id");

		$commentId = $_SESSION["commentsObj"]->addComment($userId,$pageId,$comment);
	 }catch (Exception $e) {
		return $e->getMessage();
	 }
	 return $commentId;
}

function getPageComments(){
  return $_SESSION["commentsObj"]->commentsui->displayComments($_SESSION["commentsObj"]->commentsdb->getPageComments($_SESSION['pageId']));
}

function addCommentForm(){
  return $_SESSION["commentsObj"]->commentsui->addCommentForm();
}

function deleteComment($commentId){
  $uid = ghoti_current_user_id();
  if($uid <= 0){ return false; }
  //Only the comment's author or an admin may delete it (the UI already hides
  //the delete icon otherwise, but that isn't enforcement).
  $owner = $_SESSION["commentsObj"]->commentsdb->getCommentOwner($commentId);
  if($owner !== $uid && !ghoti_require_admin()){ return false; }
  return $_SESSION["commentsObj"]->commentsdb->deleteComment($commentId);
}

ghoti_async_register(
	"addComment",
	"getPageComments",
	"addCommentForm",
	"deleteComment"
);

/* ---------------------------------------------------------------- *
 *  UI renderer (formerly comments.ui.php / class commentsui)
 * ---------------------------------------------------------------- */

class commentsui{
	public function displayComments($dbresult,$shortList=false){
		$count = 0;
		$this->comments = "<div id=\"pageComments\">";
		foreach($dbresult as $x => $y){//man this sucks... it's hard to remember which is which
			if($count == 0){
				$this->comments .= "<h5>Comments:</h5>\n";
			}
			/* $y
			*0 is commentId
			*1 is userName
			*2 is comment
			*3 is userId
			*/
			if(isSet($_SESSION['userId']) || isSet($_SESSION['admin'])){
				if($_SESSION['userId'] == $y[3] || $_SESSION['admin'] == True){
					$this->comments .= "<img class=\"linkIcon\" src=\"gfx/red-x.gif\" alt=\"Delete\" onclick=\"deleteComment('".((int)$y[0])."');\" />";
					}
				}
			//Comment body and username are user-supplied - escape them to prevent
			//stored XSS (a comment containing <script> would otherwise run for
			//everyone who views the page).
			$this->comments .= "<b>".htmlspecialchars($y[1], ENT_QUOTES)."</b>&nbsp;".htmlspecialchars(stripslashes($y[2]), ENT_QUOTES)."<br />\n";
			$count++;
			if ($count == 3 && $shortList == true){
				$this->comments .= "<span class=\"linkIcon\" onclick=\"refreshComments();\">Show more...</span>\n";
				break; //break out of our loop.
			}

		}
		$this->comments .= "</div>";
		return $this->comments;
	}
	public function addCommentForm(){
	 $addCommentForm = "<form id=\"addCommentForm\" class=\"ghotiForm\" action=\"javascript:addComment();\">\n";
	 $addCommentForm .= "<label class=\"ghotiField\"><span>Comment</span><textarea id=\"commentBox\" rows=\"7\" cols=\"50\" placeholder=\"Write your comment here...\"></textarea></label>\n";
	 $addCommentForm .= "<div class=\"ghotiFormActions\"><input type=\"submit\" value=\"Comment\" /></div></form>";
	 return $addCommentForm;
	}
	public function addCommentButton(){
		return "<div class=\"ghotiPageActions ghotiCommentActions\"><a href=\"#\" class=\"ghotiIconButton ghotiCommentButton ghotiMenu\" title=\"Add comment\" aria-label=\"Add comment\" onclick=\"popupCommentForm();\"><img src=\"gfx/comment.png\" alt=\"\" /></a></div>\n";
	}
}
