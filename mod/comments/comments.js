function refreshComments(){
  x_getPageComments(refreshComments_cb);
}
function refreshComments_cb(comments){
  document.getElementById('pageComments').innerHTML = comments;
}
function addComment(){
  var comment = document.getElementById('commentBox').value;
  	
  if (comment.length >= 100){
    popupFeedBack("Your comment is too long.<br /> Please shorten it to 100 characters or less.");  
  }else{
    x_addComment(comment,addComment_cb);
  }
}
function addComment_cb(result){
  popupFeedBack("Comment Added.");
  cancelPopup("popup-bg");
  refreshComments();
}
function deleteComment(id){
	var confirmation = confirm ('Delete is permanent! \nAre you sure?');
	if (confirmation){		
		x_deleteComment(id,deleteComment_cb);
  }
}
function deleteComment_cb(result){
  refreshComments();
}
function popupCommentForm(){
 x_addCommentForm(popup_cb);
 $("#popupTitle").html("Comment");
}
