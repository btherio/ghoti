<?php
/*
 * ghoti.async.php - the ghoti async layer.
 *
 * Replaces the old SAJAX library (lib/Sajax.php) with a tiny, self-contained
 * wrapper: a JSON dispatch table on the server and a small fetch() shim on the
 * client. It also absorbs the former core endpoint file (ghoti.ajax.php) and
 * the core UI renderer (ghoti.ui.php / class ghotiui) so the "server functions
 * the browser can call" and "the wrapper that lets it call them" live together.
 *
 * Public API:
 *   ghoti_async_register($name, ...)  mark PHP functions callable from JS
 *   ghoti_async_handle_request()      dispatch an incoming async POST (or fall
 *                                     through for a normal page load)
 *   ghoti_async_emit_js()             emit the fetch() wrapper + x_<fn> stubs
 *
 * Wire protocol (POST, same URL as the page - so the full index.php bootstrap,
 * session and module objects are already in scope for every endpoint):
 *   request : {"__ghoti_async":1,"fn":"login","args":["bob","pw"]}
 *   response: {"ok":true,"result": <return value>}   (or {"ok":false,"error":...})
 *
 * The client keeps calling x_<fn>(arg0, ..., callback) exactly as it did under
 * SAJAX; when the trailing argument is a function it receives the decoded
 * return value. So the module *.js files need no changes.
 */

/* ================================================================== *
 *  Wrapper: registry, request dispatch, JS emitter
 * ================================================================== */

$GLOBALS['ghoti_async_registry'] = array();

/* Register one or more function names as callable from the browser. */
function ghoti_async_register(){
	foreach(func_get_args() as $name){
		if(is_array($name)){
			foreach($name as $n){ $GLOBALS['ghoti_async_registry'][$n] = true; }
		}else{
			$GLOBALS['ghoti_async_registry'][$name] = true;
		}
	}
}

function ghoti_async_is_registered($name){
	return isset($GLOBALS['ghoti_async_registry'][$name]);
}

function ghoti_request_method(){
	return isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
}

function ghoti_remote_addr(){
	return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
}

/*
 * If this request is an async call, return array($fn, $args); otherwise null
 * so the caller lets the normal page render.
 */
function ghoti_async_read_request(){
	if(ghoti_request_method() !== 'POST'){
		return null;
	}
	$raw = file_get_contents('php://input');
	if($raw === false || $raw === ''){
		return null;
	}
	$payload = json_decode($raw, true);
	if(!is_array($payload) || empty($payload['__ghoti_async']) || !isset($payload['fn'])){
		return null;
	}
	$args = (isset($payload['args']) && is_array($payload['args'])) ? array_values($payload['args']) : array();
	return array((string)$payload['fn'], $args);
}

function ghoti_async_send_json($data, $status = 200){
	if(!headers_sent()){
		http_response_code($status);
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-cache, no-store, must-revalidate');
		header('Pragma: no-cache');
	}
	echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
}

/*
 * The service objects index.php parks in $_SESSION (ghotiObj, loginObj, ...) are
 * rebuilt from scratch on every request, so persisting them is pure overhead:
 * they serialize on the way out - some carrying kilobytes of rendered HTML in
 * their ->output properties - and come back as __PHP_Incomplete_Class on the
 * way in (the module classes aren't loaded yet at session_start). Drop them
 * before the session is written. Scalar session state (userId, loggedIn,
 * pageId, theme, ...) is untouched.
 */
function ghoti_free_request_objects(){
	foreach(array('ghotiObj','loginObj','linksObj','bannersObj','commentsObj','analyticsObj','ghotidb') as $k){
		unset($_SESSION[$k]);
	}
}

/*
 * Dispatch an incoming async call. Returns (without output) for a normal page
 * load; otherwise sends the JSON response and exits - mirroring how the old
 * sajax_handle_client_request() short-circuited page rendering.
 */
function ghoti_async_handle_request(){
	$req = ghoti_async_read_request();
	if($req === null){
		return; // not an async call - carry on and render the page
	}
	list($fn, $args) = $req;

	if(!ghoti_async_is_registered($fn) || !function_exists($fn)){
		ghoti::log("ghoti.async.php: rejected call to '".$fn."' from ".ghoti_remote_addr());
		ghoti_async_send_json(array('ok' => false, 'error' => 'Not callable'), 400);
		ghoti_free_request_objects();
		exit;
	}

	try{
		$result = call_user_func_array($fn, $args);
		ghoti_async_send_json(array('ok' => true, 'result' => $result));
	}catch (Throwable $e){
		ghoti::log("ghoti.async.php: exception in '".$fn."': ".$e->getMessage());
		ghoti_async_send_json(array('ok' => false, 'error' => 'Server error'), 500);
	}
	ghoti_free_request_objects();
	exit;
}

/*
 * Emit the client half: the fetch() wrapper plus one x_<fn> stub per
 * registered function. Called from ghoti.header.php inside a <script> block,
 * exactly where sajax_show_javascript() used to be.
 */
function ghoti_async_emit_js(){
	$endpointJs = json_encode(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php');
	$out = <<<JS
// ghoti async layer - a tiny fetch() wrapper that replaces the SAJAX client.
// x_<fn>(arg0, ..., callback) posts {fn,args} as JSON and hands the decoded
// return value to the trailing callback (when the last argument is a function).
var GHOTI_ASYNC_URL = {$endpointJs};

function ghotiAsync(fn, argList){
	var args = Array.prototype.slice.call(argList);
	var callback = null;
	if(args.length && typeof args[args.length - 1] === 'function'){
		callback = args.pop();
	}else if(args.length && args[args.length - 1] && typeof args[args.length - 1] === 'object' && typeof args[args.length - 1].callback === 'function'){
		callback = args.pop().callback;
	}
	fetch(GHOTI_ASYNC_URL, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		credentials: 'same-origin',
		body: JSON.stringify({ __ghoti_async: 1, fn: fn, args: args })
	}).then(function(resp){
		return resp.json();
	}).then(function(data){
		if(data && data.ok){
			if(callback){ callback(data.result); }
		}else if(window.console){
			console.error('ghotiAsync ' + fn + ': ' + (data && data.error));
		}
	}).catch(function(err){
		if(window.console){ console.error('ghotiAsync ' + fn + ' failed', err); }
	});
	return true;
}

JS;
	foreach(array_keys($GLOBALS['ghoti_async_registry']) as $fn){
		if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $fn)){
			$out .= "function x_{$fn}(){ return ghotiAsync(".json_encode($fn).", arguments); }\n";
		}
	}
	echo $out;
}

/* ================================================================== *
 *  Authorization helpers
 *
 *  The app historically gated privileged features on the CLIENT (menus were
 *  only shown to admins). That is not enforcement: every registered function
 *  is directly callable by anyone who POSTs {fn,args}. These helpers let each
 *  privileged endpoint enforce access server-side. They fail closed.
 * ================================================================== */

// Returns the authenticated user id, or 0 if the session is not logged in.
function ghoti_current_user_id(){
	if(isset($_SESSION['loggedIn']) && $_SESSION['loggedIn'] === true
		&& isset($_SESSION['userId']) && (int)$_SESSION['userId'] > 0){
		return (int)$_SESSION['userId'];
	}
	return 0;
}

function ghoti_require_login(){
	return ghoti_current_user_id() > 0;
}

// True only for a logged-in admin. isAdmin() lives in the login module and is
// always loaded before any request is dispatched.
function ghoti_require_admin(){
	$uid = ghoti_current_user_id();
	if($uid > 0 && function_exists('isAdmin') && isAdmin($uid)){
		return true;
	}
	ghoti::log("ghoti.async.php denied privileged action (uid ".$uid.") from ".ghoti_remote_addr());
	return false;
}

/* ================================================================== *
 *  Core endpoints (formerly ghoti.ajax.php)
 *  Server-side functions the browser calls to move pages around.
 * ================================================================== */

function getPage($content){
	$_SESSION["ghotiObj"] = new ghoti();
	$_SESSION["commentsObj"] = new comments();
	$_SESSION["ghotiObj"]->ghotidb = new ghotidb();
	$pageDisplay = $content;
	//this next bit shows the comments for the current page
	$pageComments = $_SESSION["commentsObj"]->commentsdb->getPageComments($_SESSION['pageId']);
	$pageDisplay.= $_SESSION["commentsObj"]->commentsui->displayComments($pageComments,true);

	if(checkLogin()){//print the comment button if we're logged in
		$pageDisplay .= $_SESSION["commentsObj"]->commentsui->addCommentButton();
	}
	$content = "<div id=\"ghotiPageDisplay\">\n".$pageDisplay."</div>\n";
	ghoti::debug("ghoti.async.php:getPage: Checking for session userId");
	$isAdminViewer = false;
	if(isSet($_SESSION['userId'])){
		ghoti::debug("ghoti.async.php:getPage: Found userId".$_SESSION['userId']);
		ghoti::debug("ghoti.async.php:getPage: Checking user for admin priv");
		if(isAdmin($_SESSION['userId'])){
			$isAdminViewer = true;
			ghoti::debug("ghoti.async.php:getPage: admin checked positive");
			$content .= editPage($_SESSION['pageId']);
		}
	}
	trackPageView($isAdminViewer);

	return stripslashes($content);
}
function getPageById($id){
	//Public navigation endpoint: coerce to a positive int and bail quietly on
	//junk (a bad id just means "no such page"), rather than throwing.
	try{
		$id = ghoti_validate()->id($id, "page id");
	}catch (Exception $e){
		return "";
	}
	$_SESSION["ghotiObj"]->ghotidb = new ghotidb();
	$content = $_SESSION["ghotiObj"]->ghotidb->getPageById($id);
	if(!isset($content[0])){
		return "";
	}
	//Private pages must not be readable by anonymous visitors just by guessing
	//an id. getPageById returns [content,title,groupName].
	$group = isset($content[0][2]) ? $content[0][2] : 'public';
	if($group === 'private' && !ghoti_require_login()){
		ghoti::log("ghoti.async.php denied private page $id to anon from ".ghoti_remote_addr());
		return "<p>You must be logged in to view this page.</p>";
	}
		//set the session page id so we can pull it up any time
	$_SESSION["pageId"] = $id;
	return getPage($content[0][0]);
}
function getPageByTitle($title){
	$title = ghoti_validate()->text($title, validate::MAX_PAGE_TITLE, false, "page title");
	if($title === ''){ return ""; }
	$_SESSION["ghotiObj"]->ghotidb = new ghotidb();
	$content = $_SESSION['ghotiObj']->ghotidb->getPageByTitle($title);
	if(!isset($content[0])){ return ""; }
	//set the session page id so we can pull it up any time
	$_SESSION["pageId"] = $content[0][1];
	return getPage($content[0][0]);
}
function editPage($id){
	if(!ghoti_require_admin()){ return ""; }
	try{
		$id = ghoti_validate()->id($id, "page id");
	}catch (Exception $e){
		return "";
	}
	//this prints the edit/delete buttons at the bottom of each page.
	$_SESSION["ghotiObj"] = new ghoti();
	$_SESSION["ghotiObj"]->ghotidb = new ghotidb();
	$_SESSION["ghotiObj"]->ghotiui = new ghotiui();
	$page = $_SESSION["ghotiObj"]->ghotidb->getPageById($id); //first we get the page from db
	$title = $page[0][1];
	$content = $page[0][0];
	$group = $page[0][2];
	return $_SESSION["ghotiObj"]->ghotiui->printEditPageForm($id,$title,$content,$group);
}
function getDefaultPage(){

	$_SESSION["ghotiObj"]->ghotidb = new ghotidb();
	$content = $_SESSION['ghotiObj']->ghotidb->getDefaultPage();
	$_SESSION["pageId"] = $content[0][1];
	return getPage($content[0][0]);
}
function savePage($id,$title,$content){
	if(!ghoti_require_admin()){ return false; }
	$v = ghoti_validate();
	try{
		$id = $v->id($id, "page id");
		$title = $v->text($title, validate::MAX_PAGE_TITLE, true, "page title"); // strips tags + caps length
		$content = $v->multilineText($content, validate::MAX_PAGE_BODY, false, "page content");
	}catch (Exception $e){
		return $e->getMessage();
	}
	$_SESSION["ghotiObj"] = new ghoti();
	return $_SESSION['ghotiObj']->ghotidb->savePage($id,$content,$title);
}
function savePageByTitle($title,$content){
	if(!ghoti_require_admin()){ return false; }
	$v = ghoti_validate();
	try{
		$title = $v->text($title, validate::MAX_PAGE_TITLE, true, "page title");
		$content = $v->multilineText($content, validate::MAX_PAGE_BODY, false, "page content");
	}catch (Exception $e){
		return $e->getMessage();
	}
	$_SESSION["ghotiObj"] = new ghoti();
	return $_SESSION['ghotiObj']->ghotidb->savePageByTitle($content,$title);
}
function addPage($title){
	if(!ghoti_require_admin()){ return false; }
	try{
		$title = ghoti_validate()->text($title, validate::MAX_PAGE_TITLE, true, "page title");
	}catch (Exception $e){
		return $e->getMessage();
	}
	$ghoti = new ghoti();
	return $ghoti->ghotidb->addPage($title);
}
function deletePage($id){
	if(!ghoti_require_admin()){ return false; }
	try{
		$id = ghoti_validate()->id($id, "page id");
	}catch (Exception $e){
		return false;
	}
	$_SESSION["ghotiObj"] = new ghoti();
	return $_SESSION['ghotiObj']->ghotidb->deletePage($id);
}

function refreshPageMenu(){
	$_SESSION['ghotiObj'] = new ghoti();
	return $_SESSION['ghotiObj']->printPageMenu(false);
}
function refreshPrivateMenu() {
	if(!ghoti_require_login()){ return ""; }
	$_SESSION['ghotiObj'] = new ghoti();
	$_SESSION["ghotiObj"]->ghotiui = new ghotiui();
	$_SESSION['ghotiObj']->ghotidb = new ghotidb();
	$pageList = $_SESSION['ghotiObj']->ghotidb->getPageList("private");
	return $_SESSION['ghotiObj']->ghotiui->printPageMenu($pageList,false);
}
function logToFile($line){
	if(!ghoti_require_login()){ return false; }
	//Strip CR/LF and control chars so a client can't forge extra log lines, and
	//cap the length.
	$line = ghoti_validate()->logLine($line);
	ghoti::log("(UID:".$_SESSION["userId"].")".$line);
	return true;
}
function showGhotiLog(){
	if(!isset($_SESSION['userId']) || !isAdmin($_SESSION['userId'])){
		ghoti::log("ghoti.async.php Unauthorized showGhotiLog attempt from ".ghoti_remote_addr());
		return "<h1>Log</h1><p>Admin access required.</p>";
	}
	$_SESSION['ghotiObj'] = new ghoti();
	$_SESSION["ghotiObj"]->ghotiui = new ghotiui();
	return $_SESSION['ghotiObj']->ghotiui->showGhotiLog();
}
function clearGhotiLog(){
	if(!isset($_SESSION['userId']) || !isAdmin($_SESSION['userId'])){
		ghoti::log("ghoti.async.php Unauthorized clearGhotiLog attempt from ".ghoti_remote_addr());
		return false;
	}
	//Truncate the log in PHP rather than via a shell redirect (portable, and no
	//shell to worry about).
	@file_put_contents(ghoti::$ghotiLog, "");
	return True;
}
function setPagePublic($id){
	if(!ghoti_require_admin()){ return false; }
	try{
		$id = ghoti_validate()->id($id, "page id");
	}catch (Exception $e){
		return false;
	}
	$_SESSION["ghotiObj"] = new ghoti();
  if($_SESSION['ghotiObj']->ghotidb->setPageGroup($id,"public"))
	 return $id;
  else
	 return False;
}
function setPagePrivate($id){
	if(!ghoti_require_admin()){ return false; }
	try{
		$id = ghoti_validate()->id($id, "page id");
	}catch (Exception $e){
		return false;
	}
	$_SESSION["ghotiObj"] = new ghoti();
  if($_SESSION['ghotiObj']->ghotidb->setPageGroup($id,"private"))
	 return $id;
  else
	 return false;
}

//Render the Site Settings admin panel (admin only).
function printSiteSettingsForm(){
	if(!ghoti_require_admin()){ return "<h1>Site Settings</h1><p>Admin access required.</p>"; }
	$ui = new ghotiui();
	return $ui->printSiteSettingsForm();
}

//Persist admin-submitted Site Settings. $settings is the decoded object the
//client sends. Returns true, or an error message string.
function saveSiteSettings($settings){
	if(!ghoti_require_admin()){ return "Admin access required."; }
	return ghoti::saveSettings($settings);
}

ghoti_async_register(
	"setPagePublic",
	"setPagePrivate",
	"clearGhotiLog",
	"showGhotiLog",
	"logToFile",
	"printSiteSettingsForm",
	"saveSiteSettings",
	"getPage",
	"getDefaultPage",
	"getPageByTitle",
	"getPageById",
	"editPage",
	"savePage",
	"savePageByTitle",
	"addPage",
	"deletePage",
	"refreshPageMenu",
	"refreshPrivateMenu"
);

/* ================================================================== *
 *  Core UI renderer (formerly ghoti.ui.php / class ghotiui)
 * ================================================================== */

class ghotiui{
	public $output;

	function showGhotiLog(){
		$showGhotiLog = "<h1>Log</h1>\n";
		$showGhotiLog .= "<h7><i>Log file appears reverse chronologically</i></h7>\n";
		//Read + reverse in PHP. The old `tail -r ghoti.log` only exists on BSD/macOS
		//(GNU/Linux tail has no -r), so this view was broken on the Linux servers
		//this actually runs on. htmlspecialchars() stops logged user input (e.g. a
		//crafted username) from injecting HTML into the admin log view.
		$logPath = ghoti::$ghotiLog;
		$lines = is_file($logPath) ? file($logPath, FILE_IGNORE_NEW_LINES) : array();
		if(!is_array($lines)){ $lines = array(); }
		$logText = htmlspecialchars(implode("\n", array_reverse($lines)), ENT_QUOTES);
		$showGhotiLog .= "<br /><pre>".$logText."</pre><br />\n";
		$showGhotiLog .= "<a href=\"#\"  onclick=\"clearGhotiLog();\" >Clear log</a>\n";
		return $showGhotiLog;
	}
	function printPageList($pageList){
		$this->output = "<ul class=\"navbar-nav text-light\" id=\"accordionSidebar\">\n";
		foreach($pageList as $records => $row){
			$this->output .= "<li class=\"nav-item\"><a class=\"nav-link\" href=\"#\" class=\"ghotiMenu\" onclick=\"getPage(".$row[0].")\"><i class=\"fas fa-tachometer-alt\"></i><span>".stripslashes($row[1])."</span></a></li>\n";
		}
		$this->output .= "</ul>\n";
		return $this->output;
	}

	function printFooter(){
		//Read the VERSION file directly instead of spawning `cat` on every render.
		$version = @file_get_contents("VERSION");
		return "GhotiCMS ".trim((string)$version);
	}

	function printCloseButton($popupName){
		$this->output = html::divStart(null,"popup-close")."<a class=\"ghotiMenu\" href=\"#\" onclick=\"cancelPopup('$popupName');\">";
		$this->output .="<img src=\"gfx/popup-close.png\" alt=\"close\" /></a>".html::divEnd();
		return $this->output;
	}

	function printEditPageForm($id,$title,$content,$group){
		$safeTitle = htmlspecialchars((string)$title, ENT_QUOTES);
		$safeContent = htmlspecialchars(stripslashes((string)$content), ENT_NOQUOTES);
		$this->output .= html::divStart("managePagePanel")."<form class=\"ghotiForm\" action=\"#\">";
		$this->output .= html::divStart("managePageForm")."<label class=\"ghotiField\"><span>Page title</span><input id=\"pageTitleEdit\" maxlength=\"20\" type=\"text\" value=\"".$safeTitle."\" /></label>";
		$this->output .= "<label class=\"ghotiField\"><span>Page content</span><textarea id=\"pageContentEdit\" class=\"pageEditArea\" rows=\"24\" spellcheck=\"true\" style=\"width:100%;min-height:440px;box-sizing:border-box;padding:12px;font:14px/1.6 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;resize:vertical;\">".$safeContent."</textarea></label>";
		$this->output .= "<div class=\"managePageActions\"><a href=\"#\" class=\"ghotiIconButton ghotiMenu\" id=\"pageSaveButton\" title=\"Save page\" aria-label=\"Save page\" onclick=\"savePage();\"><img src=\"gfx/save.png\" alt=\"\" /></a>\n";
		$this->output .= "<span class=\"managePageVisibilityLabel\">Public</span>\n";

		if($group === "public"){
			$this->output .= "<span id=\"publicPrivateButton\"><img class=\"linkIcon ghotiIconButtonImage\" src=\"gfx/green-check.gif\" alt=\"yes\" title=\"Make private\" onclick=\"setPagePrivate($id);\" /></span>";
		}elseif($group === "private"){
			$this->output .= "<span id=\"publicPrivateButton\"><img class=\"linkIcon ghotiIconButtonImage\" src=\"gfx/red-x.gif\" alt=\"no\" title=\"Make public\" onclick=\"setPagePublic($id)\" /></span>";
		}

		$this->output .= "<a href=\"#\" class=\"ghotiIconButton ghotiDangerButton ghotiMenu\" title=\"Delete page\" aria-label=\"Delete page\" onclick=\"deletePage($id);\"><img src=\"gfx/delete.png\" alt=\"\" /></a></div>\n";

		$this->output .= html::divEnd()."</form>".html::divStart(null,"ghotiPageActions ghotiEditActions")."<a href=\"#\" id=\"pageEditButton\" class=\"ghotiIconButton ghotiEditButton ghotiMenu\" title=\"Edit page\" aria-label=\"Edit page\" onclick=\"printPageEditor();\"><img src=\"gfx/edit.png\" alt=\"\" /></a>\n";
		$this->output .= "<input type=\"hidden\" id=\"pageIdEdit\" value=\"$id\" /></div>";
		$this->output .= "".html::divEnd();
		return $this->output;
	}

	function printPageMenu($pageList,$newDiv){
		if($newDiv){
			return "<div id=\"ghotiPageMenu\">".$this->printPageList($pageList)."</div>\n";
		}else{
			return $this->printPageList($pageList);
		}
	}
	function printThemeChanger($themes){
		$this->output = "<form id=\"changeTheme\" class=\"ghotiInlineForm\" action=\"#\">\n";
		$this->output .="<p><select id=\"theme\" name=\"theme\" aria-label=\"Change theme\" onchange=\"changeTheme(this.form)\">\n";
		$this->output .="<option value=\"\">Change Theme</option>\n";
		foreach($themes as $theme){
			if(isset($theme['level'], $theme['value']) && $theme['level'] == 2 && ghoti::isValidTheme($theme['value'])){ //check for level two of the xml file <theme>
				$themeName = htmlspecialchars((string)$theme['value'], ENT_QUOTES);
				$selected = ($theme['value'] === ghoti::$defaultTheme) ? " selected=\"selected\"" : "";
				$this->output .= "<option value=\"?theme=".$themeName."\"".$selected.">".$themeName."</option>\n";
			}
		}
		$this->output .="</select></p></form>\n";
		return $this->output;
	}

	//Admin "Site Settings" panel: reflects the current ghoti::$* values and posts
	//them back through saveSiteSettings(). Values are escaped for display here;
	//they are validated/sanitized again server-side in ghoti::saveSettings().
	function printSiteSettingsForm(){
		$esc = function($v){ return htmlspecialchars((string)$v, ENT_QUOTES); };
		$chk = function($b){ return $b ? " checked=\"checked\"" : ""; };

		//Discover installed themes (dirs under css/ that have a matching loader).
		$themes = array();
		foreach(glob("css/*", GLOB_ONLYDIR) as $dir){
			$name = basename($dir);
			if(is_file("css/".$name."/".$name.".php")){ $themes[] = $name; }
		}
		sort($themes);

		$o  = "<div id=\"ghotiSiteSettings\">\n<h1>Site Settings</h1>\n";
		$o .= "<p class=\"ghotiHelpText\"><i>These were previously edited in ghoti.php; changes here are saved to ghoti.settings.json.</i></p>\n";
		$o .= "<form id=\"siteSettingsForm\" class=\"ghotiForm\" action=\"javascript:saveSiteSettings();\">\n";

		$o .= "<div class=\"ghotiFormGrid\">\n";
		$o .= "<label class=\"ghotiField\"><span>Site title</span><input type=\"text\" id=\"set-siteTitle\" size=\"40\" maxlength=\"120\" value=\"".$esc(ghoti::$siteTitle)."\" /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>Default page title</span><input type=\"text\" id=\"set-defaultPageTitle\" size=\"40\" maxlength=\"120\" value=\"".$esc(ghoti::$defaultPageTitle)."\" /></label>\n";
		$o .= "</div>\n";
		$o .= "<p class=\"ghotiHelpText\"><i>The default page must exist.</i></p>\n";

		$o .= "<label class=\"ghotiField\"><span>Default theme</span><select id=\"set-defaultTheme\">\n";
		foreach($themes as $t){
			$sel = ($t === ghoti::$defaultTheme) ? " selected=\"selected\"" : "";
			$o .= "<option value=\"".$esc($t)."\"$sel>".$esc($t)."</option>\n";
		}
		$o .= "</select></label>\n";

		$o .= "<label class=\"ghotiField\"><span>Header image</span><input type=\"text\" id=\"set-headerImg\" size=\"40\" maxlength=\"200\" value=\"".$esc(ghoti::$headerImg)."\" /></label>\n";

		$o .= "<label class=\"ghotiInlineChoice\"><input type=\"checkbox\" id=\"set-allowRegister\"".$chk(ghoti::$allowRegister)." /> Allow new user registration</label>\n";
		$o .= "<label class=\"ghotiInlineChoice\"><input type=\"checkbox\" id=\"set-enableThemeChanger\"".$chk(ghoti::$enableThemeChanger)." /> Show the theme-changer dropdown</label>\n";
		$o .= "<label class=\"ghotiInlineChoice\"><input type=\"checkbox\" id=\"set-enableDebug\"".$chk(ghoti::$enableDebug)." /> Enable debug logging</label>\n";

		$o .= "<div class=\"ghotiFormActions\"><input type=\"button\" value=\"Save Settings\" onclick=\"saveSiteSettings();\" /></div>\n";
		$o .= "</form>\n";
		$o .= "<p class=\"ghotiHelpText\"><i>Note: a changed default theme or header image takes effect on the next page load.</i></p>\n";
		$o .= "</div>\n";
		return $o;
	}
}
