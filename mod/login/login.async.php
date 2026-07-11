<?php
/*
 * login.async.php - login module async layer.
 *
 * Combines the former login.ajax.php (browser-callable endpoints) and
 * login.ui.php (class loginui) into one file, registered through the ghoti
 * async wrapper (ghoti_async_register) instead of the old sajax_export().
 */

/* ---------------------------------------------------------------- *
 *  Endpoints (formerly login.ajax.php)
 * ---------------------------------------------------------------- */

function checkGetLogin(){
	return false;
}

function loginRemoteAddr(){
	return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
}

function loginSecureRandomInt($min,$max){
	if(function_exists('random_int')){
		return random_int($min,$max);
	}
	if(function_exists('openssl_random_pseudo_bytes')){
		$range = $max - $min + 1;
		$maxRandom = 0xFFFFFFFF;
		$limit = $maxRandom - ($maxRandom % $range);
		do{
			$strong = false;
			$bytes = openssl_random_pseudo_bytes(4,$strong);
			if($bytes === false || !$strong){
				throw new Exception("Secure random source unavailable.");
			}
			$unpacked = unpack('Nvalue',$bytes);
			$value = $unpacked['value'];
		}while($value >= $limit);
		return $min + ($value % $range);
	}
	throw new Exception("Secure random source unavailable.");
}

function loginHashEquals($known,$user){
	if(function_exists('hash_equals')){
		return hash_equals((string)$known,(string)$user);
	}
	$known = (string)$known;
	$user = (string)$user;
	if(strlen($known) !== strlen($user)){ return false; }
	$result = 0;
	for($i = 0; $i < strlen($known); $i++){
		$result |= ord($known[$i]) ^ ord($user[$i]);
	}
	return $result === 0;
}

function loginCaptchaCreate($purpose){
	$left = loginSecureRandomInt(2,12);
	$right = loginSecureRandomInt(2,12);
	if(!isset($_SESSION['loginCaptcha']) || !is_array($_SESSION['loginCaptcha'])){
		$_SESSION['loginCaptcha'] = array();
	}
	$_SESSION['loginCaptcha'][$purpose] = array(
		'answer' => (string)($left + $right),
		'expires' => time() + 600
	);
	return "What is ".$left." + ".$right."?";
}

function loginCaptchaHtml($purpose,$inputId){
	try{
		$question = loginCaptchaCreate($purpose);
	}catch (Exception $e){
		ghoti::log("login.async.php captcha unavailable: ".$e->getMessage());
		return "<p class=\"captchaBlock\">Security check unavailable. Please try again later.</p>\n";
	}
	$question = htmlspecialchars($question, ENT_QUOTES);
	$inputId = htmlspecialchars($inputId, ENT_QUOTES);
	return "<div class=\"captchaBlock\"><label class=\"ghotiField\"><span>Security check</span><strong class=\"captchaQuestion\">".$question."</strong><input type=\"text\" id=\"".$inputId."\" size=\"10\" autocomplete=\"off\" inputmode=\"numeric\" /></label></div>\n";
}

function loginCaptchaVerify($purpose,$answer){
	$answer = trim((string)$answer);
	if($answer === ''){
		return "Security check answer required.";
	}
	if(!isset($_SESSION['loginCaptcha'][$purpose]) || !is_array($_SESSION['loginCaptcha'][$purpose])){
		return "Security check expired. Please reopen the form and try again.";
	}
	$challenge = $_SESSION['loginCaptcha'][$purpose];
	if(!isset($challenge['expires'], $challenge['answer']) || (int)$challenge['expires'] < time()){
		unset($_SESSION['loginCaptcha'][$purpose]);
		return "Security check expired. Please reopen the form and try again.";
	}
	if(!loginHashEquals((string)$challenge['answer'],$answer)){
		return "Security check answer incorrect.";
	}
	unset($_SESSION['loginCaptcha'][$purpose]);
	return true;
}

function login($username,$password){
	$username = trim((string) $username);
	$password = (string) $password;
	$now = time();
	$attempts = isset($_SESSION['login_attempts']) ? (int) $_SESSION['login_attempts'] : 0;
	$lastAttempt = isset($_SESSION['login_last_attempt']) ? (int) $_SESSION['login_last_attempt'] : 0;

	ghoti::log("Login flow start for user '$username' from ".loginRemoteAddr());

	if ($attempts >= 5 && ($now - $lastAttempt) < 600) {
		ghoti::log("Blocked login attempt for $username from ".loginRemoteAddr());
		return "Too many login attempts. Please try again later.";
	}

	if ($username === '' || $password === '') {
		ghoti::log("Login rejected: missing username or password for '$username'");
		return false;
	}

	// Reject absurdly long credentials before they reach the database or the
	// (deliberately slow) password hasher - an unbounded password is a cheap
	// slow-hash DoS. No valid username/password can exceed these.
	if (strlen($username) > validate::MAX_USERNAME || strlen($password) > validate::MAX_PASSWORD) {
		ghoti::log("Login rejected: over-length credentials for '".substr($username,0,20)."' from ".loginRemoteAddr());
		return false;
	}

	$_SESSION['login_last_attempt'] = $now;
	$_SESSION['login_attempts'] = $attempts + 1;
	ghoti::log("Login attempt($username) from ".loginRemoteAddr());
	$id = $_SESSION["loginObj"]->logindb->authenticate($username,$password);
	ghoti::log("Login authentication result for '$username': ".var_export($id, true));
	if ($id && $id > 0) {
		$_SESSION['login_attempts'] = 0;
		$_SESSION['login_last_attempt'] = 0;
		// Establish the authenticated session HERE, immediately after we have
		// verified the password. Previously the browser called setSessionVars()
		// with an id of its choosing to elevate the session - which meant anyone
		// could POST setSessionVars with id=1 and become that user (typically the
		// admin) with no password at all. Doing it here, keyed to the id we just
		// authenticated, closes that bypass. session_regenerate_id prevents
		// session fixation.
		session_regenerate_id(true);
		$_SESSION['loggedIn'] = true;
		$_SESSION['userId'] = (int) $id;
		$_SESSION['admin'] = isAdmin((int) $id);
		$_SESSION['last_activity'] = time();
		ghoti::log("Login succeeded for '$username' with userId $id");
	} else {
		ghoti::log("Login failed for '$username'");
	}
	return $id;
}

function addUser($username,$email,$password,$captchaAnswer=''){
	//Honour the server-side registration switch instead of relying on the
	//client hiding the Register button.
	if(ghoti::$allowRegister !== true){
		ghoti::log("Registration attempt while disabled from ".loginRemoteAddr());
		return "Registration is disabled.";
	}
	$username = trim((string) $username);
	$email = trim((string) $email);
	$password = (string) $password;

	ghoti::log("Registration flow start for '$username' from ".loginRemoteAddr());

	//Validate + normalize every field up front. username() enforces the safe
	//charset (the username is echoed into several admin views), email() actually
	//rejects a bad address (the old checkEmail silently passed everything), and
	//password() enforces the min/max length window (max guards the slow hasher).
	try{
		$v = ghoti_validate();
		$username = $v->username($username);
		$email    = $v->email($email);
		$password = $v->password($password);
		ghoti::log("Registration validation passed for '$username'");
	}catch (Exception $e) {
		ghoti::log("Registration validation failed for '$username': ".$e->getMessage());
		return $e->getMessage();
	}

	$captchaResult = loginCaptchaVerify('register',$captchaAnswer);
	if($captchaResult !== true){
		ghoti::log("Registration rejected for '$username': captcha failed from ".loginRemoteAddr());
		return $captchaResult;
	}

	$duplicate = $_SESSION["loginObj"]->logindb->checkDuplicate($username,$email);
	ghoti::log("Registration duplicate check for '$username': ".var_export($duplicate, true));
	if($duplicate){
		ghoti::log("Registration rejected for '$username': duplicate user/email");
		return "Username or Email is already registered!";
	}

	ghoti::log("Calling addUser for '$username'");
	$result = $_SESSION["loginObj"]->logindb->addUser($username,$password,$email);
	ghoti::log("Registration database result for '$username': ".var_export($result, true));
	if($result){
		ghoti::log("Registered $username from ".loginRemoteAddr());
		return true;
	}
	ghoti::log("Registration failed for '$username' with no exception details");
	return "Error!";
}

function saveUser($name,$email,$id){
	if(!ghoti_require_admin()){ return "Admin access required."; }
	try{
		$v = ghoti_validate();
		$id    = $v->id($id, "user id");
		$name  = $v->username($name); // same safe charset as registration
		$email = $v->email($email);
	}catch (Exception $e) {
		return $e->getMessage();
	}
	ghoti::log("Attempting to save user info for $name from ".loginRemoteAddr());
	return $_SESSION["loginObj"]->logindb->updateUser($id,$name,$email);
}

function deleteUser($id){
	$id = (int) $id;
	$selfId = ghoti_current_user_id();
	if($selfId <= 0){ return false; } //must be logged in
	if($id === 0){ $id = $selfId; }   //0 means "delete my own account"
	//Deleting anyone other than yourself is an admin-only action.
	if($id !== $selfId && !ghoti_require_admin()){ return false; }
	ghoti::log("Attempting to delete userID: $id from ".loginRemoteAddr());
	return $_SESSION["loginObj"]->logindb->deleteUser($id);
}

function setSessionVars($id){
	$id = (int) $id;
	// Hardened: this endpoint previously elevated the session to ANY
	// client-supplied id with no authentication - a trivial account-takeover
	// (POST setSessionVars id=1 => you are the admin). login() now establishes
	// the session itself, so this only CONFIRMS the session already
	// authenticated for the same id. It never grants access.
	if($id <= 0 || ghoti_current_user_id() !== $id){
		ghoti::log("login.async.php setSessionVars refused id ".$id." (session uid ".ghoti_current_user_id().") from ".loginRemoteAddr());
		return false;
	}
	$_SESSION['last_activity'] = time();
	return true;
}

function changePassword($password,$newPassword,$captchaAnswer=''){
	if(!ghoti_require_login()){ return false; }
	$userName = $_SESSION["loginObj"]->logindb->getUserNameById($_SESSION["userId"]);
	$password = (string) $password;
	try{
		$v = ghoti_validate();
		$v->checkExists($userName);
		$v->checkExists($password);
		//The new password must satisfy the full length window (min 8, and a max so
		//it can't be used to hammer the slow hasher).
		$newPassword = $v->password($newPassword);
	}catch (Exception $e) {
		return $e->getMessage();
	}
	//Guard the current-password field against slow-hash abuse too.
	if(strlen($password) > validate::MAX_PASSWORD){
		return "Password is too long.";
	}

	$captchaResult = loginCaptchaVerify('changePassword',$captchaAnswer);
	if($captchaResult !== true){
		ghoti::log("Change password captcha failed for $userName from ".loginRemoteAddr());
		return $captchaResult;
	}

	ghoti::log("Change password for $userName from ".loginRemoteAddr().".");
	$id = $_SESSION["loginObj"]->logindb->authenticate($userName,$password);
	if ($id > 0){
		return $_SESSION["loginObj"]->logindb->changePassword($id,$newPassword);
	}
	ghoti::log("Auth failed for user ".$id."(".loginRemoteAddr().") trying to change password");
	return false;
}

function logout(){
	try{
		ghoti::log("Trying logout...");
		$_SESSION['login_attempts'] = 0;
		$_SESSION['login_last_attempt'] = 0;
		$_SESSION = array();
		if (ini_get('session.use_cookies')) {
			$params = session_get_cookie_params();
			setcookie(session_name(), '', time() - 42000,
				$params['path'], $params['domain'],
				$params['secure'], $params['httponly']
			);
		}
		session_unset();
		session_destroy();
	}catch (Exception $e) {
		ghoti::log($e->getMessage());
		return $e->getMessage();
	}
	ghoti::log("Logout finished.");
	return true;
}

function isAdmin($id){
	return $_SESSION["loginObj"]->logindb->isAdmin($id);
}

function printAdminMenu(){
	//Defense in depth: the client only asks for this menu when isAdmin() is
	//true, but don't hand the admin menu to anyone who calls the endpoint.
	if(!ghoti_require_admin()){ return ""; }
	return $_SESSION["loginObj"]->loginui->printAdminMenu();
}

function printManageUserForm(){
	if(!isset($_SESSION['userId']) || !isAdmin($_SESSION['userId'])){
		ghoti::log("login.async.php Unauthorized printManageUserForm attempt from ".loginRemoteAddr());
		return "<h1>Users</h1><p>Admin access required.</p>";
	}
	$userList = $_SESSION["loginObj"]->logindb->getUserList();
	return $_SESSION["loginObj"]->loginui->printManageUserForm($userList);
}

function printLoginForm(){
	return $_SESSION["loginObj"]->loginui->printLoginForm();
}

function printChangePasswordForm(){
	if(!ghoti_require_login()){ return "<p>You must be logged in to change your password.</p>"; }
	return $_SESSION["loginObj"]->loginui->printChangePasswordForm();
}

function toggleAdmin($id){
	//Critical: without this an anonymous caller could grant themselves admin.
	if(!ghoti_require_admin()){ return "Admin access required."; }
	try{
		$id = ghoti_validate()->id($id, "user id");
	}catch (Exception $e) {
		return "Invalid user.";
	}
	ghoti::log("Toggling admin status for userID: $id from ".loginRemoteAddr());
	return $_SESSION["loginObj"]->logindb->toggleAdmin($id, ghoti_current_user_id());
}

function checkLogin(){
	if(isset($_SESSION["loggedIn"]) && $_SESSION["loggedIn"] == true && isset($_SESSION["userId"]) && $_SESSION["userId"] > 0){
		ghoti::log("Checking login, found uid" .$_SESSION["userId"]."");
		return $_SESSION["userId"];
	}
	ghoti::debug("login.async.php.checklogin failed");
	return false;
}

function getLoggedInId(){
	return $_SESSION['userId'];
}

function printSystemMenu(){
	return $_SESSION["loginObj"]->loginui->printSystemMenu();
}

function printRegisterForm(){
	return $_SESSION["loginObj"]->loginui->printRegisterForm();
}

ghoti_async_register(
	"checkGetLogin",
	"addUser",
	"changePassword",
	"checkLogin",
	"deleteUser",
	"getLoggedInId",
	"isAdmin",
	"login",
	"logout",
	"printAdminMenu",
	"printManageUserForm",
	"printLoginForm",
	"printChangePasswordForm",
	"printSystemMenu",
	"printRegisterForm",
	"setSessionVars",
	"saveUser",
	"toggleAdmin"
);

/* ---------------------------------------------------------------- *
 *  UI renderer (formerly login.ui.php / class loginui)
 * ---------------------------------------------------------------- */

class loginui{
	public $output;

	public function printSystemMenu(){
		$this->output .= "<ul>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\" class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"logout();\">&nbsp;Log Out</a></li>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\" class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"printChangePasswordForm();\">&nbsp;Change Password</a></li>\n";
		$this->output .= "</ul>\n";
		return $this->output;
	}

	public function printAdminMenu(){
		$this->output = "<ul>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\" class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"addPage();\">Add Page</a></li>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\"class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"manageBanners();\">Banners</a></li>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\"class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"editLinkForm();\">Links</a></li>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\"class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"showGhotiLog();\">Log</a></li>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\"class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"showAnalytics();\">Analytics</a></li>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\"class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"printManageUserForm();\">Users</a></li>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\"class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"showSiteSettings();\">Site Settings</a></li>\n";
		$this->output .= "</ul>\n";
		return $this->output;
	}

	public function printLoginForm(){
		$this->output = "<div id=\"ghotiLogin\"><form id=\"loginForm\" class=\"ghotiForm\" action=\"javascript:login();\">\n";
		$this->output .= "<label class=\"ghotiField\"><span>Username</span><input type=\"text\" name=\"userName\" id=\"userName\" size=\"20\" autocomplete=\"username\" autocapitalize=\"none\" spellcheck=\"false\" /></label>\n";
		$this->output .= "<label class=\"ghotiField\"><span>Password</span><input type=\"password\" name=\"password\" id=\"password\" size=\"20\" autocomplete=\"current-password\" /></label>\n";
		$this->output .= "<div class=\"ghotiFormActions\"><input type=\"submit\" value=\"Login\" />\n";
		if(ghoti::$allowRegister == true){
			$this->output .= "<input type=\"button\" class=\"ghotiButtonSecondary\" value=\"Register\" onclick=\"printRegisterForm();\" />\n";
		}
		$this->output .= "</div></form><span id=\"loginFeedback\"></span></div>\n";
		return $this->output;
	}

	public function printPopupLogin(){
		$this->output = "<div id=\"ghotiLogin\"><a class=\"dropdown-item\" href=\"#\" onclick=\"popupLogin();\">Login</a></div>\n";
		return $this->output;
	}

	public function printRegisterForm(){
		$this->output = "<div id=\"ghotiLogin\"><form id=\"registerForm\" class=\"ghotiForm\" action=\"javascript:register();\">\n";
		$this->output .= "<label class=\"ghotiField\"><span>Username</span><input type=\"text\" name=\"userName\" id=\"registerForm-userName\" size=\"20\" autocomplete=\"username\" autocapitalize=\"none\" spellcheck=\"false\" /></label>\n";
		$this->output .= "<label class=\"ghotiField\"><span>E-mail</span><input type=\"email\" name=\"email\" id=\"registerForm-email\" size=\"20\" autocomplete=\"email\" /></label>\n";
		$this->output .= "<label class=\"ghotiField\"><span>Password</span><input type=\"password\" name=\"password\" id=\"registerForm-password\" size=\"20\" autocomplete=\"new-password\" /></label>\n";
		$this->output .= "<label class=\"ghotiField\"><span>Password again</span><input type=\"password\" name=\"password1\" id=\"registerForm-password1\" size=\"20\" autocomplete=\"new-password\" /></label>\n";
		$this->output .= loginCaptchaHtml('register','registerForm-captcha');
		$this->output .= "<div class=\"ghotiFormActions\"><input type=\"submit\" value=\"Register\" /></div>\n";
		$this->output .= "</form><span id=\"loginFeedback\"></span></div>\n";
		return $this->output;
	}

	public function printChangePasswordForm(){
		$this->output = "<form id=\"changePasswordForm\" class=\"ghotiForm\" action=\"javascript:changePassword();\">";
		$this->output .= "<label class=\"ghotiField\"><span>Old password</span><input type=\"password\" id=\"chpw-password\" size=\"20\" autocomplete=\"current-password\" /></label>";
		$this->output .= "<label class=\"ghotiField\"><span>New password</span><input type=\"password\" id=\"chpw-newPassword1\" size=\"20\" autocomplete=\"new-password\" /></label>";
		$this->output .= "<label class=\"ghotiField\"><span>New password again</span><input type=\"password\" id=\"chpw-newPassword2\" size=\"20\" autocomplete=\"new-password\" /></label>";
		$this->output .= loginCaptchaHtml('changePassword','chpw-captcha');
		$this->output .= "<div class=\"ghotiFormActions\"><input type=\"submit\" value=\"Change Password\"/>";
		$this->output .= "<input type=\"button\" value=\"Remove Account\" class=\"ghotiButtonDanger ghotiMenu\" onclick=\"printDeleteUserDialog();\" /></div></form>";
		return $this->output;
	}

	function printManageUserForm($userList){
		$this->output = "<section id=\"ghotiManageUsers\" class=\"ghotiAdminPanel\"><div class=\"ghotiCrudHeader\"><h1>Manage Users</h1></div>\n";
		$this->output .= "<table class=\"ghotiManageTable\"><thead><tr><th>Username</th><th>Email</th><th>Admin</th><th>Actions</th></tr></thead><tbody>\n";
		foreach($userList as $records => $row){
			$userId = (int)$row[0];
			$userName = htmlspecialchars((string)$row[1], ENT_QUOTES);
			$userEmail = htmlspecialchars((string)$row[2], ENT_QUOTES);
			$nameField = "user-".$userId."-name";
			$emailField = "user-".$userId."-email";
			$this->output .= "<tr>";
			$this->output .= "<td><input type=\"text\" id=\"".$nameField."\" value=\"".$userName."\" size=\"18\" /></td>\n";
			$this->output .= "<td><input type=\"text\" id=\"".$emailField."\" value=\"".$userEmail."\" size=\"24\" /></td>\n";
			if($row[3] == 1)
				$this->output .= "<td><button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" onclick=\"toggleAdmin('".$userId."');\"><img src=\"gfx/green-check.gif\" alt=\"\" />Admin</button></td>\n<td>";
			else
				$this->output .= "<td><button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" onclick=\"toggleAdmin('".$userId."');\"><img src=\"gfx/red-x.gif\" alt=\"\" />User</button></td>\n<td>";
			$this->output .= "<div class=\"ghotiFormActions\"><button type=\"button\" class=\"ghotiButton ghotiButtonCompact\" onclick=\"saveUser('".$nameField."','".$emailField."','".$userId."');\"><img src=\"gfx/save.png\" alt=\"\" />Save</button>\n";
			$this->output .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonDanger\" onclick=\"deleteUser('".$userId."');\"><img src=\"gfx/delete.png\" alt=\"\" />Delete</button></div></td>\n";
			$this->output .= "</tr>\n";
		}
		$this->output .= "</tbody></table></section>\n";
		return $this->output;
	}
}
