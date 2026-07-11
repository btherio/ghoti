$(document).ready(function(){
	checkLogin();
	x_checkGetLogin(getLogin_cb);
});

function login(){
	var username = $("#userName").val().trim();
	var password = $("#password").val();

	if(!username || !password){
		$("#loginFeedback").html("Required field missing");
		window.setTimeout(function(){ $("#loginFeedback").html(""); },3000);
	}else{
		x_login(username,password,login_cb);
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
	var username = $("#registerForm-userName").val().trim();
	var email = $("#registerForm-email").val().trim();
	var password = $("#registerForm-password").val();
	var password1 = $("#registerForm-password1").val();
	var captchaAnswer = $("#registerForm-captcha").val();

	if(!username || !password || !password1 || !email || !captchaAnswer){
		popupFeedBack("Required field missing.");
	}else if(password != password1){
		popupFeedBack("New passwords don't match.");
	}else if(password.length < 8){
		popupFeedBack("New password is too short.");
	}else{
		x_addUser(username,email,password,captchaAnswer,register_cb);
	}
}

function printRegisterForm(){
	$("#popupTitle").html("Register");
	x_printRegisterForm(popup_cb);
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
	var captchaAnswer = $("#chpw-captcha").val();

	if(!password || !newPassword1 || !newPassword2 || !captchaAnswer){
		popupFeedBack("Required field missing.");
	}else if(newPassword1 != newPassword2){
		popupFeedBack("New passwords don't match.");
	}else if(newPassword1.length < 8){
		popupFeedBack("New password is too short.");
	}else{
		x_changePassword(password,newPassword1,captchaAnswer,changePassword_cb);
	}
}

function printChangePasswordForm(){
	$("#popupTitle").html("Change Password");
	x_printChangePasswordForm(popup_cb);
}
function printDeleteUserDialog(){
	$("#popupTitle").html("Delete Account?");
	$("#popup-content").html(
		"<div class=\"ghotiForm\">"+
			"<p class=\"ghotiHelpText\">This will delete your account and everything associated with it.</p>"+
			"<div class=\"ghotiFormActions\"><button type=\"button\" class=\"ghotiButton ghotiButtonDanger\" onclick=\"deleteUser(0);\">Delete Account</button></div>"+
		"</div>"
	);
	showPopup();
}
function checkLogin(){
	x_checkLogin(checkLogin_cb);
}

function checkLogin_cb(result){
	if(result > 0){
		login_cb(result);
	}else{
		return false;
	}
}

function login_cb(id){
	if(id > 0){
		// setSessionVars regenerates the session id (session_regenerate_id).
		// The remaining calls MUST wait for it to finish and for the new session
		// cookie to be applied - otherwise they race, each arriving with the old
		// (now-deleted) session id, spawn fresh empty sessions under
		// session.use_strict_mode, and the browser ends up on a session with no
		// userId. That is what caused "admin access required" while logged in.
		x_setSessionVars(id, function(){
			x_printSystemMenu(systemMenu_cb);
			x_refreshPrivateMenu(privateMenu_cb);
			x_isAdmin(id,adminMenu_cb);
			x_getDefaultPage(printPage);
		});
		cancelPopup('popup-bg');
	}else if(id == 0){
		$("#loginFeedback").html("Bad username or password!");
		window.setTimeout(function(){ $("#loginFeedback").html(""); },3000);
	}
}
function changePassword_cb(result){
	if(result != true){
		popupFeedBack(result || "Changing password failed. Check your current password.");
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
	bindGhotiMenuLinks();
}
function privateMenu_cb(privateMenu){
	$("#ghotiPrivateMenu").html(privateMenu);
	$("#ghotiPrivateMenuTitle").html("Private Menu");
	$("#ghotiPrivateMenuTitle").css("visibility","visible");
	$("#ghotiPrivateMenu").css("visibility","visible");
	bindGhotiMenuLinks();
}
function printAdminMenu_cb(adminMenu){
	$("#ghotiAdminMenu").html(adminMenu);
	$("#ghotiAdminMenuTitle").html("Admin Menu");
	$("#ghotiAdminMenuTitle").css("visibility","visible");
	$("#ghotiAdminMenu").css("visibility","visible");
	bindGhotiMenuLinks();
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
