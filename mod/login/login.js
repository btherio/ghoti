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
		refreshRegisterCaptcha();
	}else if(password != password1){
		popupFeedBack("New passwords don't match.");
		refreshRegisterCaptcha();
	}else if(password.length < 8){
		popupFeedBack("New password is too short.");
		refreshRegisterCaptcha();
	}else{
		x_addUser(username,email,password,captchaAnswer,register_cb);
	}
}

function refreshRegisterCaptcha(){
	x_refreshRegisterCaptcha(function(content){
		if(typeof content === 'string'){
			$("#loginCaptcha-register").replaceWith(content);
		}
	});
}

function printRegisterForm(){
	$("#popupTitle").html("Register");
	x_printRegisterForm(popup_cb);
}
function printManageUserForm(){
	x_printManageUserForm(function(content){
		printPage(content);
		initComposeMail();
	});
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

/*
 * The session is live: bring the page up to date.
 *
 * setSessionVars regenerates the session id (session_regenerate_id). The
 * remaining calls MUST wait for it to finish and for the new session cookie to
 * be applied - otherwise they race, each arriving with the old (now-deleted)
 * session id, spawn fresh empty sessions under session.use_strict_mode, and the
 * browser ends up on a session with no userId. That is what caused "admin
 * access required" while logged in.
 */
function loginEstablished(id){
	x_setSessionVars(id, function(){
		x_printSystemMenu(systemMenu_cb);
		x_refreshPrivateMenu(privateMenu_cb);
		x_isAdmin(id,adminMenu_cb);
		x_getDefaultPage(printPage);
	});
	cancelPopup('popup-bg');
}

/* ---------------------------------------------------------------- *
 *  Two-factor sign-in codes
 *
 *  login() answers {twoFactor:"required"} instead of a user id when the
 *  password was right but a code is still needed. Nothing is signed in at that
 *  point - only verifyTwoFactor() can do that.
 * ---------------------------------------------------------------- */

function loginTwoFactorPending(result){
	return !!(result && typeof result === 'object' && result.twoFactor === 'required');
}

function loginShowTwoFactorForm(){
	$("#popupTitle").html("Check your email");
	$("#popup-content").html(
		"<form id=\"twoFactorForm\" class=\"ghotiForm\" action=\"#\" novalidate>"+
			"<p class=\"ghotiHelpText\">Your password was accepted. We sent a six-digit sign-in code to the address on your account. It is good for ten minutes.</p>"+
			"<label class=\"ghotiField\"><span>Sign-in code</span>"+
				"<input type=\"text\" id=\"twoFactorCode\" inputmode=\"numeric\" autocomplete=\"one-time-code\" "+
				"maxlength=\"6\" pattern=\"[0-9]*\" autocapitalize=\"off\" spellcheck=\"false\" required /></label>"+
			"<p id=\"twoFactorFeedback\" class=\"ghotiFormError\" role=\"alert\" hidden></p>"+
			"<div class=\"ghotiFormActions\">"+
				"<button type=\"submit\" class=\"ghotiButton\">Sign in</button>"+
				"<button type=\"button\" class=\"ghotiButton ghotiButtonSecondary\" onclick=\"cancelTwoFactor();\">Cancel</button>"+
			"</div>"+
		"</form>"
	);
	var form = document.getElementById("twoFactorForm");
	form.addEventListener("submit", function(event){
		event.preventDefault();
		submitTwoFactor();
	});
	//Most people paste the code; submit as soon as six digits are in.
	var field = document.getElementById("twoFactorCode");
	field.addEventListener("input", function(){
		setTwoFactorError("");
		if(field.value.replace(/\D/g, "").length === 6){ submitTwoFactor(); }
	});
	showPopup();
	field.focus();
}

function setTwoFactorError(message){
	var box = document.getElementById("twoFactorFeedback");
	if(!box){ return; }
	box.textContent = message || "";
	box.hidden = !message;
}

function submitTwoFactor(){
	var field = document.getElementById("twoFactorCode");
	if(!field){ return; }
	var code = field.value.replace(/\D/g, "");
	if(code.length !== 6){ return setTwoFactorError("Enter the six-digit code from your email."); }
	x_verifyTwoFactor(code, verifyTwoFactor_cb);
}

function verifyTwoFactor_cb(result){
	if(result > 0){ return loginEstablished(result); }
	if(result === 0 || result === "0"){
		setTwoFactorError("That code is not right. Check your email and try again.");
		var field = document.getElementById("twoFactorCode");
		if(field){ field.value = ""; field.focus(); }
		return;
	}
	//A string: expired, too many tries, or already signed in. The attempt is
	//over, so send them back to the start rather than leaving a dead form up.
	setTwoFactorError(typeof result === "string" ? result : "Sign-in failed. Start again.");
	window.setTimeout(function(){ popupLogin(); }, 2500);
}

function cancelTwoFactor(){
	x_cancelTwoFactor(function(){ cancelPopup('popup-bg'); });
}

function login_cb(id){
	if(loginTwoFactorPending(id)){
		loginShowTwoFactorForm();
	}else if(id > 0){
		loginEstablished(id);
	}else if(id == 0){
		$("#loginFeedback").html("Bad username or password!");
		window.setTimeout(function(){ $("#loginFeedback").html(""); },3000);
	}else if(typeof id === 'string' && id.length){
		//Server-side message (e.g. "Too many login attempts. Please try again later.").
		$("#loginFeedback").html(ghotiEscapeHtml(id));
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
	//The pending marker is an object, and `object == true` is false in JS - so
	//this check has to come first or the marker falls through to the else and
	//is printed as "[object Object]".
	if(registrationPending(resultMessage)){
		//The captcha is deliberately NOT refreshed here: this attempt is still
		//in progress. It is refreshed if the person backs out, so a retry gets
		//a fresh one rather than a spent one.
		showRegistrationCodeForm(resultMessage.email);
		return;
	}
	refreshRegisterCaptcha();
	if(resultMessage == true){
		pageFeedBack("Registered successfully. Please login to continue.");
	}else{
		popupFeedBack(resultMessage);
	}
}

/* ---------------------------------------------------------------- *
 *  Confirming a new registration by e-mail
 *
 *  addUser() answers {verifyEmail:"required", email:...} when the address has
 *  to be confirmed first. No account exists at that point, and none is created
 *  until verifyRegistration() accepts the code.
 * ---------------------------------------------------------------- */

function registrationPending(result){
	return !!(result && typeof result === 'object' && result.verifyEmail === 'required');
}

function showRegistrationCodeForm(email){
	$("#popupTitle").html("Confirm your email");
	$("#popup-content").html(
		"<form id=\"registerVerifyForm\" class=\"ghotiForm\" action=\"#\" novalidate>"+
			"<p class=\"ghotiHelpText\">We sent a six-digit confirmation code to <b>"+ghotiEscapeHtml(email || "your address")+"</b>. "+
			"It is good for fifteen minutes. Your account is not created until the code is confirmed.</p>"+
			"<label class=\"ghotiField\"><span>Confirmation code</span>"+
				"<input type=\"text\" id=\"registerVerifyCode\" inputmode=\"numeric\" autocomplete=\"one-time-code\" "+
				"maxlength=\"6\" pattern=\"[0-9]*\" autocapitalize=\"off\" spellcheck=\"false\" required /></label>"+
			"<p id=\"registerVerifyFeedback\" class=\"ghotiFormError\" role=\"alert\" hidden></p>"+
			"<div class=\"ghotiFormActions\">"+
				"<button type=\"submit\" class=\"ghotiButton\">Create my account</button>"+
				"<button type=\"button\" class=\"ghotiButton ghotiButtonSecondary\" onclick=\"cancelRegistration();\">Cancel</button>"+
			"</div>"+
		"</form>"
	);
	var form = document.getElementById("registerVerifyForm");
	form.addEventListener("submit", function(event){
		event.preventDefault();
		submitRegistrationCode();
	});
	var field = document.getElementById("registerVerifyCode");
	field.addEventListener("input", function(){
		setRegistrationError("");
		if(field.value.replace(/\D/g, "").length === 6){ submitRegistrationCode(); }
	});
	showPopup();
	field.focus();
}

function setRegistrationError(message){
	var box = document.getElementById("registerVerifyFeedback");
	if(!box){ return; }
	box.textContent = message || "";
	box.hidden = !message;
}

function submitRegistrationCode(){
	var field = document.getElementById("registerVerifyCode");
	if(!field){ return; }
	var code = field.value.replace(/\D/g, "");
	if(code.length !== 6){ return setRegistrationError("Enter the six-digit code from your email."); }
	x_verifyRegistration(code, verifyRegistration_cb);
}

function verifyRegistration_cb(result){
	if(result === true){
		cancelPopup('popup-bg');
		//Registering is not signing in - they still log in as normal, which is
		//what keeps a new account subject to whatever sign-in rules are set.
		pageFeedBack("Your email is confirmed and your account is created. Please login to continue.");
		return;
	}
	if(result === 0 || result === "0"){
		setRegistrationError("That code is not right. Check your email and try again.");
		var field = document.getElementById("registerVerifyCode");
		if(field){ field.value = ""; field.focus(); }
		return;
	}
	setRegistrationError(typeof result === "string" ? result : "Registration failed. Start again.");
	window.setTimeout(function(){ cancelRegistration(); }, 2500);
}

function cancelRegistration(){
	x_cancelRegistration(function(){
		cancelPopup('popup-bg');
		//A fresh captcha, since the one from the abandoned attempt is spent.
		refreshRegisterCaptcha();
	});
}
function saveUser_cb(result){
	if(result == true){
		pageFeedBack("User saved!");
		printManageUserForm();
	}else{
		pageFeedBack(result);
	}
}
function deleteUser_cb(result){
	if(result == true){
		printManageUserForm();
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
		printManageUserForm();
	}else{
		pageFeedBack(result);
	}
}
function getLogin_cb(result){
	if(result == true){ //if this returns true, login was set in $_GET
		popupLogin();   //and we should popup a login prompt
	}
}
