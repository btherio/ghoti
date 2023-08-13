<?php 
/*
 * Created on May 1, 2010
 */

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
					$this->comments .= "<img class=\"linkIcon\" src=\"gfx/red-x.gif\" alt=\"Delete\" onclick=\"deleteComment('".$y[0]."');\" />";
					}
				}
			$this->comments .= "<b>".$y[1].":</b>&nbsp;".stripslashes($y[2])."<br />\n";
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
	 $addCommentForm = "<textarea id=\"commentBox\" rows=\"7\" cols=\"50\">Write your comment here...</textarea>\n<br />\n";
	 $addCommentForm .= "<input type=\"button\" value=\"Comment\" onclick=\"addComment();\" /><br />";
	 return $addCommentForm;
	}
	public function addCommentButton(){
		//Button Style
		//return "<input type=\"button\" value=\"Comment\" onclick=\"popupCommentForm();\" />";
		//Link Style
		return "<a onclick=\"popupCommentForm();\"><img class=\"linkIcon\" width=\"25pt\" height=\"25pt\" src=\"gfx/comment.png\" / ></a>\n";
	}
}
