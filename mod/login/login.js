$(document).ready(function(){
	//this runs these functions when the page is finished loading
	checkLogin();//checks if we're logged in
	x_checkGetLogin(getLogin_cb); //checks if the ?login is set, and displays a popupLogin
});
//ajax functions
function login(){
	var username = $("#userName").val();
	var password = $("#password").val();
	
	if(!username || !password){
		$("#loginFeedback").html("Required field missing")
		window.setTimeout('$("#loginFeedback").html("")',3000);
	}else{
		var md5password = hex_md5(password);	
		x_login(username,md5password,login_cb);			
	}
}
function logout_cb(result){
		if(result){
			//x_logToFile('logout success');
			location.href = "index.php";//refresh the page. 
		}
}
function logout(){
	//x_logToFile('logout trial...');
	x_logout(logout_cb);
}
function register(){
	var username = $("#registerForm-userName").val();
	var email = $("#registerForm-email").val();
	var password = $("#registerForm-password").val();
	var password1 = $("#registerForm-password1").val();
	
	if(!username || !password || !password1 || !email){
		popupFeedBack("Required field missing.");
	}else	if(password != password1){
		popupFeedBack("New passwords don't match.");
	}else if(password.length < 6){
		popupFeedBack("New password is too short.");
	}else{
		var md5password = hex_md5(password);	
		x_addUser(username,email,md5password,register_cb);	
	}
}

function printRegisterForm(){
	//Prints register form to a popup window
	$("#popupTitle").html("Register");
	$("#popup-content").html("<form id=\"registerForm\" action=\"javascript:register();\">\n");
	$("#popup-content").append("Username:<br /><input type=\"text\" id=\"registerForm-userName\" size=\"10\" /><br />\n");
	$("#popup-content").append("E-Mail:<br /><input type=\"text\" id=\"registerForm-email\" size=\"10\" /><br />\n");
	$("#popup-content").append("Password:<br /><input type=\"password\" id=\"registerForm-password\" size=\"10\" /><br />\n");
	$("#popup-content").append("Password(<i>again</i>):<br /><input type=\"password\" id=\"registerForm-password1\" size=\"10\" /><br />\n");
	$("#popup-content").append("<input type=\"submit\" value=\"Register\" onclick=\"register();\" /></form>\n");
	showPopup();	
}
function printManageUserForm(){
	x_printManageUserForm(printPage);
}
function saveUser(username,email,id){
	var newUsername = $("#"+username).val();
	var newEmail = $("#"+email).val();

	if(!newUsername || !newEmail){
		pageFeedBack("Required field missing.");
	}else{
		x_saveUser(newUsername,newEmail,id,saveUser_cb);	
	}
}
function popupLogin(){
	x_printLoginForm(popup_cb);
	$("#popupTitle").html("Login");
}
function deleteUser(id){
	var confirmation = confirm ('Delete is permanent! \nAre you sure?');
	if (confirmation){
		if(id == 0){
			x_deleteUser(id, deleteMyAccount_cb);
		}else{
			x_deleteUser(id, deleteUser_cb);
		}
	}
}
function toggleAdmin(id){
	x_toggleAdmin(id,toggleAdmin_cb);
}
function printMenus(id){
	if(id >0){
		x_printUserMenu(userMenu_cb);	
		x_isAdmin(id,adminMenu_cb);
	}
}
function changePassword(){
	var password = $("#chpw-password").val();
	var newPassword1 = $("#chpw-newPassword1").val();
	var newPassword2 = $("#chpw-newPassword2").val();
	
	if(!password || !newPassword1 || !newPassword2){
		popupFeedBack("Required field missing.");
	}else if(newPassword1 != newPassword2){
		popupFeedBack("New passwords don't match.");
	}else if(newPassword1.length < 6){
		popupFeedBack("New password is too short.");
	}else{
		var md5newPassword = hex_md5(newPassword1);
		var md5password = hex_md5(password);
		x_changePassword(md5password,md5newPassword,changePassword_cb);
	}
}
function printChangePasswordForm(){
	//first make the form
	$("#popupTitle").html("Change Password");
	$("#popup-content").append("<form action=\"#\">");
	$("#popup-content").append("Old Password:<input type=\"password\" id=\"chpw-password\" size=\"10\" /><br />");
	$("#popup-content").append("New Password:<input type=\"password\" id=\"chpw-newPassword1\" size=\"10\" /><br />");
	$("#popup-content").append("New Password:<input type=\"password\" id=\"chpw-newPassword2\" size=\"10\" /><br />");
	$("#popup-content").append("<input type=\"submit\" value=\"Change Password\" onclick=\"changePassword();\"/>");
	$("#popup-content").append("<input type=\"button\" value=\"Remove Account\" =class=\"ghotiMenu\" onclick=\"printDeleteUserDialog();\" /></form>")
	showPopup();//then show the popup
}
function printDeleteUserDialog(){
	$("#popupTitle").html("Delete Account?");
	$("#popup-content").append("This will delete your account and everything associated with it.<br />");
	$("#popup-content").append("Delete Account?<br />");
	$("#popup-content").append("<input type=\"button\" value=\"Delete!\" onclick=\"deleteUser(0);\"><br />");
	showPopup();
}
function checkLogin(){
  /* This is totally unsecure IMO. Basically we are trusting 
  * the users cookies to tell us whether or not they are logged in. 
  * Without even verifying it with a password prompt. Just asking politely. Hmm.
  * Maybe I'm just paranoid.
  */
  x_checkLogin(checkLogin_cb);
}
//callbacks
function checkLogin_cb(result){
	/*At first glance, this seems useless, but it avoids unneccesary login attempts*/
	if(result > 0){
		//success
		//x_logToFile(result);
		login_cb(result);
	}else{
		//fail
		return false;
	}
}
function login_cb(id){
	if(id > 0){ //assume if ID is above 0 then it's set, so we are successfully logging in
		//alert(id);
		x_setSessionVars(id,doNothing_cb);
		x_printSystemMenu(systemMenu_cb);
		x_refreshPrivateMenu(privateMenu_cb);
		x_isAdmin(id,adminMenu_cb);
		cancelPopup('popup-bg');
		x_getDefaultPage(printPage);
	}else if(id == 0){
		$("#loginFeedback").html("Bad username or password!");
		window.setTimeout('$("#loginFeedback").html("");',3000);
	}
}
function changePassword_cb(result){
	if(result != true){
		popupFeedBack("Changing password failed. Check your current password.");
	}else{
		popupFeedBack("Password changed!");
	}
}

function adminMenu_cb(result){
	if(result){
		x_printAdminMenu(printAdminMenu_cb);
		return true;
	}else{
		return false;
	}
}
function systemMenu_cb(systemMenu){
	$("#ghotiLogin").html(systemMenu);
	$("#ghotiLoginTitle").html("Logged in");
	$(".ghotiMenu").click(function(e){ //we do this again, because we just made more links.
			e.preventDefault();// stop normal link click on ghotiMenu links
		});
}
function privateMenu_cb(privateMenu){
	$("#ghotiPrivateMenu").html(privateMenu);
	$("#ghotiPrivateMenuTitle").html("Private Menu");
	$("#ghotiPrivateMenuTitle").css("visibility","visible");
	$("#ghotiPrivateMenu").css("visibility","visible");
	$(".ghotiMenu").click(function(e){ //we do this again, because we just made more links.
			e.preventDefault();// stop normal link click on ghotiMenu links
		});
}
function printAdminMenu_cb(adminMenu){
	$("#ghotiAdminMenu").html(adminMenu);
	$("#ghotiAdminMenuTitle").html("Admin Menu");
	$("#ghotiAdminMenuTitle").css("visibility","visible");
	$("#ghotiAdminMenu").css("visibility","visible");
	$(".ghotiMenu").click(function(e){ //we do this again, because we just made more links.
			e.preventDefault();// stop normal link click on ghotiMenu links
	});
}
function register_cb(resultMessage){
	if(resultMessage == true){
		pageFeedBack("Registered successfully. Please login to continue.");
	}else{
		popupFeedBack(resultMessage);
	}
}
function saveUser_cb(result){
	if(result == true){
		pageFeedBack("User saved!");
	}else{
		pageFeedBack(result);
	}
}
function deleteUser_cb(result){
	if(result == true){
		x_printManageUserForm(printPage);
	}else{
		pageFeedBack("Deleting user failed!");
	}
}
function deleteMyAccount_cb(result){
	if(result == true){
		//this logs us out. mucho important. 
		logout();
	}else{
		pageFeedBack(result);
	}
}
function toggleAdmin_cb(result){
	if(result == true){
		x_printManageUserForm(printPage);
	}else{
		pageFeedBack(result);
	}
}
function getLogin_cb(result){
	if(result == true){ //if this returns true, login was set in $_GET
		popupLogin();   //and we should popup a login prompt
	}
}
