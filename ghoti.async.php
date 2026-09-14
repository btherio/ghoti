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
 * If this request is an async call, return array($fn, $args, $token); otherwise
 * null so the caller lets the normal page render.
 *
 * Accepts both JSON bodies (the ghotiAsync wrapper) and form-encoded /
 * multipart POSTs (used by file uploads, e.g. the gallery module, which can't
 * send binary data as JSON). In the form-encoded case the payload fields come
 * from $_POST - arguments must be posted as args[0], args[1], ... so PHP turns
 * them into the same numeric array shape the JSON path produces.
 */
function ghoti_async_read_request(){
	if(ghoti_request_method() !== 'POST'){
		return null;
	}
	$isMultipart = isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') === 0;
	$raw = $isMultipart ? '' : file_get_contents('php://input', false, null, 0, 1048577);
	if(strlen((string)$raw) > 1048576){
		ghoti_async_send_json(array('ok'=>false, 'error'=>'Request too large'), 413);
		exit;
	}
	$payload = json_decode((string)$raw, true);
	//Form-encoded/multipart fallback, scoped STRICTLY to file uploads: binary
	//data can only arrive as multipart/form-data, and only the gallery and
	//file-manager modules use that today (their clients send the CSRF token as
	//a form field, which is verified before any endpoint runs). A plain
	//urlencoded form POST - now or in the future - is never mistaken for an RPC
	//call.
	if(!is_array($payload)
		&& !empty($_POST)
		&& isset($_SERVER['CONTENT_TYPE'])
		&& stripos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') === 0){
		$payload = $_POST;
	}
	if(!is_array($payload) || empty($payload['__ghoti_async']) || !isset($payload['fn'])){
		return null;
	}
	if(!is_string($payload['fn']) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,79}$/', $payload['fn'])
		|| (isset($payload['token']) && !is_string($payload['token']))
		|| (isset($payload['args']) && (!is_array($payload['args']) || count($payload['args']) > 32))){
		ghoti_async_send_json(array('ok'=>false, 'error'=>'Invalid request'), 400);
		exit;
	}
	$args = (isset($payload['args']) && is_array($payload['args'])) ? array_values($payload['args']) : array();
	$token = isset($payload['token']) ? (string)$payload['token'] : '';
	return array((string)$payload['fn'], $args, $token);
}

function ghoti_async_send_json($data, $status = 200){
	if(!headers_sent()){
		http_response_code($status);
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-cache, no-store, must-revalidate');
		header('Pragma: no-cache');
		header('X-Content-Type-Options: nosniff');
	}
	echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
}

/* ================================================================== *
 *  CSRF protection
 *
 *  Every state-changing async endpoint is protected by SameSite=Strict
 *  cookies (see index.php) AND a per-session CSRF token. The token is
 *  generated on page render, embedded in the JS wrapper (ghoti_async_emit_js)
 *  and echoed back in the JSON body of every async POST. Requests without a
 *  valid token are rejected with 403 before any endpoint runs - so a forged
 *  cross-site POST (or a POST that never loaded the page) is a no-op even if
 *  the SameSite cookie were bypassed.
 * ================================================================== */

//Get (or lazily create) the session's CSRF token.
function ghoti_csrf_token(){
	if(empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])){
		$t = '';
		if(function_exists('random_bytes')){
			$t = bin2hex(random_bytes(32));
		}elseif(function_exists('openssl_random_pseudo_bytes')){
			$bytes = openssl_random_pseudo_bytes(32, $strong);
			$t = ($bytes !== false && $strong) ? bin2hex($bytes) : '';
		}
		if($t === ''){
			throw new RuntimeException('Secure random source unavailable.');
		}
		$_SESSION['csrf_token'] = $t;
	}
	return $_SESSION['csrf_token'];
}

//True only when $token matches the session token (constant-time compare).
function ghoti_csrf_verify($token){
	return is_string($token) && $token !== ''
		&& isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token'])
		&& hash_equals($_SESSION['csrf_token'], $token);
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
	foreach(array('ghotiObj','loginObj','linksObj','bannersObj','commentsObj','analyticsObj','galleryObj','filemanagerObj','mailObj','vhostsObj','storeObj','ghotidb') as $k){
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
	list($fn, $args, $token) = $req;
	$startTime = microtime(true);
	ghoti::logDebug("ghoti.async.php:dispatch", "-> fn='".ghoti_validate()->logLine($fn)."' argc=".count($args)." from ".ghoti_remote_addr());

	//CSRF: reject anything without a valid session token before any endpoint
	//runs. Registered-or-not is irrelevant - an invalid token is a 403.
	if(!ghoti_csrf_verify($token)){
		//logLine() strips CR/LF so an attacker-chosen fn can't forge log entries.
		ghoti::logWarn("ghoti.async.php:dispatch", "rejected request without valid CSRF token ('".ghoti_validate()->logLine($fn)."') from ".ghoti_remote_addr());
		ghoti_async_send_json(array('ok' => false, 'error' => 'Invalid request token'), 403);
		ghoti_free_request_objects();
		exit;
	}

	if(!ghoti_async_is_registered($fn) || !function_exists($fn)){
		ghoti::logWarn("ghoti.async.php:dispatch", "rejected call to '".ghoti_validate()->logLine($fn)."' from ".ghoti_remote_addr());
		ghoti_async_send_json(array('ok' => false, 'error' => 'Not callable'), 400);
		ghoti_free_request_objects();
		exit;
	}

	try{
		$result = call_user_func_array($fn, $args);
		ghoti_async_send_json(array('ok' => true, 'result' => $result));
		ghoti::logDebug("ghoti.async.php:dispatch", "<- fn='".ghoti_validate()->logLine($fn)."' ok in ".round((microtime(true)-$startTime)*1000,1)."ms");
	}catch (Throwable $e){
		//logLine() strips CR/LF so an exception message can't forge log entries.
		ghoti::logException("ghoti.async.php:dispatch", $e, "fn='".ghoti_validate()->logLine($fn)."'");
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
	$csrfTokenJs = json_encode(ghoti_csrf_token());
	$helpTipsJs = ghoti::$showHelpTips ? 'true' : 'false';
	$out = <<<JS
// ghoti async layer - a tiny fetch() wrapper that replaces the SAJAX client.
// x_<fn>(arg0, ..., callback) posts {fn,args} as JSON and hands the decoded
// return value to the trailing callback (when the last argument is a function).
var GHOTI_ASYNC_URL = {$endpointJs};
var GHOTI_CSRF_TOKEN = {$csrfTokenJs};
var GHOTI_SHOW_HELP_TIPS = {$helpTipsJs};

function ghotiAsync(fn, argList){
	var args = Array.prototype.slice.call(argList);
	var callback = null;
	if(args.length && typeof args[args.length - 1] === 'function'){
		callback = args.pop();
	}else if(args.length && args[args.length - 1] && typeof args[args.length - 1] === 'object' && typeof args[args.length - 1].callback === 'function'){
		callback = args.pop().callback;
	}
	//Button feedback: if this call was triggered by a recent button press
	//(see ghoti.js), show the spinner on it for the duration of the request.
	var trigger = null;
	if(typeof GHOTI_LAST_TRIGGER !== 'undefined' && GHOTI_LAST_TRIGGER && (Date.now() - GHOTI_LAST_TRIGGER_AT) < 700){
		trigger = GHOTI_LAST_TRIGGER;
		GHOTI_LAST_TRIGGER = null;
		if(typeof ghotiButtonBusy === 'function'){ ghotiButtonBusy(trigger, true); }
	}
	function settle(){
		if(trigger && typeof ghotiButtonBusy === 'function'){ ghotiButtonBusy(trigger, false); }
	}
	fetch(GHOTI_ASYNC_URL, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		credentials: 'same-origin',
		body: JSON.stringify({ __ghoti_async: 1, fn: fn, args: args, token: GHOTI_CSRF_TOKEN })
	}).then(function(resp){
		return resp.json();
	}).then(function(data){
		if(data && data.ok){
			if(callback){ callback(data.result); }
		}else if(window.console){
			console.error('ghotiAsync ' + fn + ': ' + (data && data.error));
		}
		settle();
	}).catch(function(err){
		if(window.console){ console.error('ghotiAsync ' + fn + ' failed', err); }
		settle();
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
 *  Content shortcodes
 *
 *  Modules can register [tag:value] / [tag value] shortcodes that expand
 *  inside page content (see the gallery module for a consumer). Expansion
 *  runs in getPage(), which every public page view flows through.
 * ================================================================== */

$GLOBALS['ghoti_shortcode_handlers'] = array();

//Register a handler (callable receiving the regex matches array) for a
//[tag:value] / [tag value] shortcode. Returns true.
function ghoti_register_shortcode($tag, $handler){
	if(is_string($tag) && preg_match('/^[A-Za-z0-9_]+$/', $tag) && is_callable($handler)){
		$GLOBALS['ghoti_shortcode_handlers'][$tag] = $handler;
		return true;
	}
	return false;
}

//Expand every registered shortcode in $content. Shortcodes are regex-matched
//with a single value argument, e.g. [gallery:summer] or [gallery summer].
//Unknown shortcodes are left untouched; handlers decide what to emit.
function ghoti_expand_shortcodes($content){
	if(!is_string($content) || strpos($content, '[') === false){
		return $content;
	}
	foreach($GLOBALS['ghoti_shortcode_handlers'] as $tag => $handler){
		$pattern = '/\['.preg_quote($tag, '/').'(?::|\s+)([A-Za-z0-9_.-]+)\s*\]/i';
		$content = preg_replace_callback($pattern, $handler, $content);
	}
	return $content;
}

/* ================================================================== *
 *  Shared admin docs panel
 *
 *  The collapsible "how to" <details> panel used by every admin screen so
 *  all documentation looks and behaves the same. $title/$hint are plain
 *  text (escaped here); $sections is a list of:
 *    array('heading' => '...', 'list' => array('<li>content</li>', ...))
 *  or
 *    array('heading' => '...', 'html' => '<p>content</p>')
 *  List items / html are trusted static admin-facing markup (never user
 *  data). The matching client-side helper is ghotiDocsHtml() in ghoti.js.
 * ================================================================== */
function ghoti_docs_panel($title, $hint, $sections){
	if(class_exists('ghoti') && !ghoti::$showHelpTips){ return ''; }
	$o  = "<details class=\"ghotiDocs\">\n";
	$o .= "<summary><span class=\"ghotiDocsTitle\">".htmlspecialchars((string)$title, ENT_QUOTES)."</span><span class=\"ghotiDocsHint\">".htmlspecialchars((string)$hint, ENT_QUOTES)."</span></summary>\n";
	$o .= "<div class=\"ghotiDocsBody\">\n";
	foreach($sections as $section){
		if(!is_array($section)){ continue; }
		$heading = isset($section['heading']) ? htmlspecialchars((string)$section['heading'], ENT_QUOTES) : '';
		if($heading !== ''){
			$o .= "<h3>".$heading."</h3>\n";
		}
		if(isset($section['list']) && is_array($section['list'])){
			$o .= "<ul>\n";
			foreach($section['list'] as $item){
				$o .= "<li>".$item."</li>\n";
			}
			$o .= "</ul>\n";
		}elseif(isset($section['html'])){
			$o .= $section['html'];
		}
	}
	$o .= "</div>\n";
	$o .= "</details>\n";
	return $o;
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
	ghoti::logWarn("ghoti.async.php:ghoti_require_admin", "denied privileged action (uid ".$uid.") from ".ghoti_remote_addr());
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
	//Sanitize on every render as well as on save. This protects visitors from
	//unsafe markup stored by an older Ghoti version or written directly to DB.
	$pageDisplay = ghoti_expand_shortcodes(ghoti_validate()->pageHtml($content)); // e.g. [gallery:name] -> inline gallery
	//this next bit shows the comments for the current page
	$pageComments = $_SESSION["commentsObj"]->commentsdb->getPageComments($_SESSION['pageId']);
	$pageDisplay.= $_SESSION["commentsObj"]->commentsui->displayComments($pageComments,true);

	if(checkLogin()){//print the comment button if we're logged in
		$pageDisplay .= $_SESSION["commentsObj"]->commentsui->addCommentButton();
	}
	$content = "<div id=\"ghotiPageDisplay\">\n".$pageDisplay."</div>\n";
	ghoti::logDebug("ghoti.async.php:getPage", "Checking for session userId");
	$isAdminViewer = false;
	if(isSet($_SESSION['userId'])){
		ghoti::logDebug("ghoti.async.php:getPage", "Found userId".$_SESSION['userId']);
		ghoti::logDebug("ghoti.async.php:getPage", "Checking user for admin priv");
		if(isAdmin($_SESSION['userId'])){
			$isAdminViewer = true;
			ghoti::logDebug("ghoti.async.php:getPage", "admin checked positive");
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
	if(!ghoti_can_view_page($group)){
		ghoti::logWarn("ghoti.async.php:getPage", "denied private page $id to anon from ".ghoti_remote_addr());
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
	$group = isset($content[0][3]) ? $content[0][3] : 'public';
	if(!ghoti_can_view_page($group)){
		ghoti::logWarn("ghoti.async.php:getPageTitle", "denied private page title to anon from ".ghoti_remote_addr());
		return "<p>You must be logged in to view this page.</p>";
	}
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
	$content = ghoti_validate()->pageHtml($page[0][0]);
	$group = $page[0][2];
	return $_SESSION["ghotiObj"]->ghotiui->printEditPageForm($id,$title,$content,$group);
}
function getDefaultPage(){

	$_SESSION["ghotiObj"]->ghotidb = new ghotidb();
	$content = $_SESSION['ghotiObj']->ghotidb->getDefaultPage();
	if(!isset($content[0])){ return "<p>No public page is available.</p>"; }
	$_SESSION["pageId"] = $content[0][1];
	return getPage($content[0][0]);
}
function savePage($id,$title,$content,$group = null){
	if(!ghoti_require_admin()){ return false; }
	$v = ghoti_validate();
	try{
		$id = $v->id($id, "page id");
		$title = $v->text($title, validate::MAX_PAGE_TITLE, true, "page title"); // strips tags + caps length
		$content = $v->pageHtml($content, false);
		if($group !== null){ $group = $v->pageGroup($group); }
	}catch (Exception $e){
		return $e->getMessage();
	}
	$_SESSION["ghotiObj"] = new ghoti();
	$isDefault = false;
	$foundPage = false;
	$pages = $_SESSION['ghotiObj']->ghotidb->getPageManagementList();
	if(is_array($pages)){
		foreach($pages as $page){
			if((int)$page[0] !== $id){ continue; }
			$foundPage = true;
			$isDefault = (int)$page[4] === 1;
			break;
		}
	}
	if($group !== null && !$foundPage){ return "Could not verify the page audience."; }
	if($isDefault && $group === 'private'){
		return "The home page must remain visible to everyone.";
	}
	$result = $_SESSION['ghotiObj']->ghotidb->savePage($id,$content,$title,$group);
	if($result === true && $isDefault){
		$settingsResult = ghoti::saveSettings(array('defaultPageTitle'=>$title));
		if($settingsResult !== true){ return "Page saved, but the default-page setting file could not be updated."; }
	}
	return $result;
}
function savePageByTitle($title,$content){
	if(!ghoti_require_admin()){ return false; }
	$v = ghoti_validate();
	try{
		$title = $v->text($title, validate::MAX_PAGE_TITLE, true, "page title");
		$content = $v->pageHtml($content, false);
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
	$pages = $_SESSION['ghotiObj']->ghotidb->getPageManagementList();
	if(!is_array($pages)){ return "Could not load the page list."; }
	if(count($pages) <= 1){ return "The only page cannot be deleted."; }
	$found = false;
	foreach($pages as $page){
		if((int)$page[0] !== $id){ continue; }
		$found = true;
		if((int)$page[4] === 1){ return "Choose another default page before deleting this page."; }
		break;
	}
	if(!$found){ return "Page not found."; }
	return $_SESSION['ghotiObj']->ghotidb->deletePage($id);
}

function printPageManagementPanel(){
	if(!ghoti_require_admin()){ return "<h1>Manage Pages</h1><p>Admin access required.</p>"; }
	$ghoti = new ghoti();
	$pages = $ghoti->ghotidb->getPageManagementList();
	if(!is_array($pages)){ return "<h1>Manage Pages</h1><p>Could not load pages.</p>"; }
	return $ghoti->ghotiui->printPageManagementPanel($pages);
}

function savePageManagement($pages,$defaultPageId){
	if(!ghoti_require_admin()){ return "Admin access required."; }
	if(!is_array($pages) || count($pages) < 1 || count($pages) > 500){
		return "Invalid page list.";
	}

	try{
		$defaultPageId = ghoti_validate()->id($defaultPageId,"default page id");
	}catch(Exception $e){
		return "Choose a default page.";
	}

	$ghoti = new ghoti();
	$storedPages = $ghoti->ghotidb->getPageManagementList();
	if(!is_array($storedPages) || count($storedPages) !== count($pages)){
		return "The page list changed. Reload it and try again.";
	}

	$storedById = array();
	foreach($storedPages as $storedPage){
		$storedById[(int)$storedPage[0]] = $storedPage;
	}

	$cleanPages = array();
	$seen = array();
	$defaultTitle = null;
	foreach(array_values($pages) as $index => $page){
		if(!is_array($page)){ return "Invalid page data."; }
		try{
			$id = ghoti_validate()->id(isset($page['id']) ? $page['id'] : 0,"page id");
			$group = ghoti_validate()->pageGroup(isset($page['groupName']) ? $page['groupName'] : '');
		}catch(Exception $e){
			return $e->getMessage();
		}
		if(isset($seen[$id]) || !isset($storedById[$id])){
			return "The page list contains an invalid or duplicate page.";
		}
		$seen[$id] = true;
		if($id === $defaultPageId){
			if($group !== 'public'){ return "The default page must be visible to everyone."; }
			$defaultTitle = (string)$storedById[$id][1];
		}
		$cleanPages[] = array('id'=>$id,'sortOrder'=>$index + 1,'groupName'=>$group);
	}
	if(count($seen) !== count($storedById)){ return "The page list is incomplete."; }
	if($defaultTitle === null){ return "Choose a default page."; }

	if(!$ghoti->ghotidb->savePageManagement($cleanPages,$defaultPageId)){
		return "Could not save page management changes.";
	}
	$settingsResult = ghoti::saveSettings(array('defaultPageTitle'=>$defaultTitle));
	if($settingsResult !== true){
		ghoti::logWarn("ghoti.async.php:savePageManagement", "saved but default title setting failed");
		return "Pages were saved, but the default-page setting file could not be updated.";
	}
	return true;
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
	//Rate-limit writes per IP so an authenticated user can't fill the disk
	//(the throttle store is defined by the login module, always loaded first).
	if(function_exists('login_throttle_store')){
		$throttle = login_throttle_store();
		$key = 'log:'.(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
		if($throttle->countWindow($key, 3600) >= 100){
			return false;
		}
		$throttle->recordFailure($key, 200);
	}
	ghoti::logInfo("ghoti.async.php:appendUserLog", "(UID:".$_SESSION["userId"].") ".$line);
	return true;
}
function clearGhotiLog(){
	if(!isset($_SESSION['userId']) || !isAdmin($_SESSION['userId'])){
		ghoti::logWarn("ghoti.async.php:clearGhotiLog", "Unauthorized clearGhotiLog attempt from ".ghoti_remote_addr());
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
	$pages = $_SESSION['ghotiObj']->ghotidb->getPageManagementList();
	if(is_array($pages)){
		foreach($pages as $page){
			if((int)$page[0] === $id && (int)$page[4] === 1){
				return "The default page must remain public.";
			}
		}
	}
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
	if(!is_array($settings)){ return "Invalid settings."; }
	$db = null;
	$defaultTitle = null;
	if(array_key_exists('defaultPageTitle',$settings)){
		try{
			$defaultTitle = ghoti_validate()->text($settings['defaultPageTitle'],validate::MAX_PAGE_TITLE,false,"default page title");
		}catch(Exception $e){
			return $e->getMessage();
		}
		$db = new ghotidb();
		$pages = $db->getPageManagementList();
		$found = false;
		if(is_array($pages)){
			foreach($pages as $page){
				if((string)$page[1] === $defaultTitle && $page[2] === 'public'){ $found = true; break; }
			}
		}
		if(!$found){
			return "The default page must exist and be visible to everyone.";
		}
	}
	$result = ghoti::saveSettings($settings);
	if($result !== true){ return $result; }
	if($defaultTitle !== null && !$db->setDefaultPageByTitle($defaultTitle)){
		return "Settings were saved, but the default page could not be activated.";
	}
	return true;
}

ghoti_async_register(
	"setPagePublic",
	"setPagePrivate",
	"clearGhotiLog",
	"logToFile",
	"printSiteSettingsForm",
	"saveSiteSettings",
	"printPageManagementPanel",
	"savePageManagement",
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

	function printPageList($pageList){
		$this->output = "<ul class=\"navbar-nav text-light\" id=\"accordionSidebar\">\n";
		foreach($pageList as $records => $row){
			$id = (int)$row[0];
			$title = htmlspecialchars(stripslashes((string)$row[1]), ENT_QUOTES, 'UTF-8');
			$this->output .= "<li class=\"nav-item\"><a class=\"nav-link ghotiMenu\" href=\"#\" onclick=\"getPage(".$id.")\"><i class=\"fas fa-tachometer-alt\"></i><span>".$title."</span></a></li>\n";
		}
		$this->output .= "</ul>\n";
		return $this->output;
	}

	function printPageManagementPanel($pages){
		$esc = function($value){ return htmlspecialchars((string)$value,ENT_QUOTES); };
		$o  = "<section id=\"ghotiPageManager\" class=\"ghotiAdminPanel\">\n";
		$o .= "<div class=\"ghotiCrudHeader\"><div><h1>Manage Pages</h1><p class=\"ghotiHelpText\">Set navigation order, the home page, and who can view each page.</p></div>";
		$o .= "<form id=\"ghotiAddPageForm\" class=\"ghotiPageAddForm\" action=\"#\" onsubmit=\"addManagedPage(); return false;\"><input id=\"ghotiNewPageTitle\" type=\"text\" maxlength=\"24\" placeholder=\"Page title\" aria-label=\"New page title\" /><button type=\"submit\" class=\"ghotiButton\">Add page</button></form></div>\n";
		$docs = ghoti_docs_panel("How to use pages", "order, home page, permissions", array(
			array('heading' => 'Arrange your navigation',
				'list' => array('Drag a row by its handle, or use the up/down arrows. The order shown here is the order in the menu.')),
			array('heading' => 'Choose the home page',
				'list' => array('Pick the <b>Home</b> radio on the page visitors should land on first.', 'The home page must be visible to <b>Everyone</b>.')),
			array('heading' => 'Who can see a page',
				'list' => array('<b>Everyone</b> &mdash; appears in the public menu.', '<b>Signed-in users</b> &mdash; appears in the private menu; requires login.')),
			array('heading' => 'Edit page content',
				'list' => array('Click a page title to open the visual editor. Switch to <b>HTML</b> for source control or <b>Preview</b> to check the finished page.', 'Embed a photo gallery with <code>[gallery:name]</code> &mdash; see <b>Galleries</b> for details.', 'Page HTML is filtered to safe content elements and attributes when you preview, save, and publish.')),
			array('heading' => 'Add and remove pages',
				'list' => array('Type a title in the box and press <b>Add page</b>.', 'Delete a page with its Delete button; the last page and the current home page cannot be deleted.'))
		));
		$o .= "<div class=\"ghotiPageManagerLabels\" aria-hidden=\"true\"><span>Order</span><span>Page</span><span>Default</span><span>Permission</span><span>Actions</span></div>\n";
		$o .= "<div id=\"ghotiPageManagerRows\" class=\"ghotiPageManagerRows\">\n";
		foreach($pages as $page){
			$id = (int)$page[0];
			$title = $esc($page[1]);
			$group = $page[2] === 'private' ? 'private' : 'public';
			$isDefault = (int)$page[4] === 1;
			$o .= "<div class=\"ghotiPageManagerRow\" data-page-id=\"$id\" draggable=\"true\">\n";
			$o .= "<div class=\"ghotiPageOrderControls\"><span class=\"ghotiDragHandle\" title=\"Drag to reorder\" aria-hidden=\"true\">&#8942;&#8942;</span><button type=\"button\" title=\"Move up\" aria-label=\"Move ".$title." up\" onclick=\"moveManagedPage(this,-1);\">&#8593;</button><button type=\"button\" title=\"Move down\" aria-label=\"Move ".$title." down\" onclick=\"moveManagedPage(this,1);\">&#8595;</button></div>\n";
			$o .= "<button type=\"button\" class=\"ghotiPageManagerTitle\" onclick=\"editManagedPage($id);\"><span>".$title."</span><small>Page #$id</small></button>\n";
			$o .= "<label class=\"ghotiDefaultChoice\"".($group === 'private' ? " hidden=\"hidden\"" : "")."><input type=\"radio\" name=\"ghotiDefaultPage\" value=\"$id\"".($isDefault ? " checked=\"checked\"" : "")." /><span>Home</span></label>\n";
			$o .= "<label class=\"ghotiPagePermission\"><span class=\"ghotiMobileLabel\">Permission</span><select aria-label=\"Permission for ".$title."\"><option value=\"public\"".($group === 'public' ? " selected=\"selected\"" : "").">Everyone</option><option value=\"private\"".($group === 'private' ? " selected=\"selected\"" : "").">Signed-in users</option></select></label>\n";
			$o .= "<div class=\"ghotiPageManagerActions\"><button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" onclick=\"editManagedPage($id);\">Edit</button><button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonDanger\" onclick=\"deleteManagedPage($id);\">Delete</button></div>\n";
			$o .= "</div>\n";
		}
		$o .= "</div>\n<div class=\"ghotiPageManagerFooter\"><p class=\"ghotiHelpText\">Private pages appear in the signed-in menu.</p><button type=\"button\" class=\"ghotiButton\" onclick=\"savePageManagement();\"><img src=\"gfx/save.png\" alt=\"\" />Save changes</button></div>\n";
		$o .= $docs;
		$o .= "</section>\n";
		return $o;
	}

	function printFooter(){
		//Read the VERSION file directly instead of spawning `cat` on every render.
		$version = @file_get_contents("VERSION");
		return "<span>GhotiCMS ".htmlspecialchars(trim((string)$version), ENT_QUOTES, 'UTF-8')."</span>".ghoti_footer_links();
	}

	function printCloseButton($popupName){
		$this->output = html::divStart(null,"popup-close")."<a class=\"ghotiMenu\" href=\"#\" onclick=\"cancelPopup('$popupName');\">";
		$this->output .="<img src=\"gfx/popup-close.png\" alt=\"close\" /></a>".html::divEnd();
		return $this->output;
	}

	function printEditPageForm($id,$title,$content,$group){
		$safeTitle = htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8');
		$safeContent = htmlspecialchars(stripslashes((string)$content), ENT_NOQUOTES, 'UTF-8');
		$publicSelected = $group === 'private' ? '' : ' selected="selected"';
		$privateSelected = $group === 'private' ? ' selected="selected"' : '';
		$this->output .= html::divStart("managePagePanel")."<form class=\"ghotiPageEditorForm\" action=\"#\" onsubmit=\"savePage(); return false;\">";
		$this->output .= html::divStart("managePageForm","ghotiPageEditor");
		$this->output .= "<header class=\"ghotiEditorHeader\"><div><span class=\"ghotiEditorEyebrow\">Page studio</span><h2>Shape your story</h2><p>Compose visually, inspect the HTML, and preview the finished page.</p></div>";
		$this->output .= "<div class=\"ghotiEditorHeaderFields\"><label><span>Page title</span><input id=\"pageTitleEdit\" maxlength=\"24\" type=\"text\" value=\"".$safeTitle."\" required=\"required\" /></label>";
		$this->output .= "<label><span>Audience</span><select id=\"pageVisibilityEdit\"><option value=\"public\"".$publicSelected.">Everyone</option><option value=\"private\"".$privateSelected.">Signed-in users</option></select></label></div></header>";

		$this->output .= "<div class=\"ghotiEditorToolbar\" role=\"toolbar\" aria-label=\"Page formatting\">";
		$this->output .= "<div class=\"ghotiEditorToolGroup\"><select id=\"ghotiEditorBlock\" aria-label=\"Text style\"><option value=\"p\">Paragraph</option><option value=\"h1\">Heading 1</option><option value=\"h2\">Heading 2</option><option value=\"h3\">Heading 3</option><option value=\"blockquote\">Quote</option><option value=\"pre\">Code block</option></select>";
		$this->output .= "<button type=\"button\" data-editor-command=\"bold\" title=\"Bold (Ctrl+B)\" aria-label=\"Bold\"><b>B</b></button><button type=\"button\" data-editor-command=\"italic\" title=\"Italic (Ctrl+I)\" aria-label=\"Italic\"><i>I</i></button><button type=\"button\" data-editor-command=\"underline\" title=\"Underline (Ctrl+U)\" aria-label=\"Underline\"><u>U</u></button></div>";
		$this->output .= "<div class=\"ghotiEditorToolGroup\"><button type=\"button\" data-editor-command=\"insertUnorderedList\" title=\"Bulleted list\" aria-label=\"Bulleted list\">&#8226; List</button><button type=\"button\" data-editor-command=\"insertOrderedList\" title=\"Numbered list\" aria-label=\"Numbered list\">1. List</button><button type=\"button\" data-editor-action=\"link\" title=\"Add link\" aria-label=\"Add link\">Link</button><button type=\"button\" data-editor-command=\"removeFormat\" title=\"Clear formatting\" aria-label=\"Clear formatting\">Clear</button></div>";
		$this->output .= "<div class=\"ghotiEditorModes\" role=\"group\" aria-label=\"Editor view\"><button type=\"button\" class=\"is-active\" data-editor-mode=\"visual\" aria-pressed=\"true\">Visual</button><button type=\"button\" data-editor-mode=\"source\" aria-pressed=\"false\">HTML</button><button type=\"button\" data-editor-mode=\"preview\" aria-pressed=\"false\">Preview</button></div></div>";

		$this->output .= "<div class=\"ghotiEditorCanvas\"><div id=\"pageContentVisual\" class=\"ghotiVisualEditor\" contenteditable=\"true\" role=\"textbox\" aria-multiline=\"true\" aria-label=\"Page content\" data-placeholder=\"Start writing something memorable…\"></div>";
		$this->output .= "<textarea id=\"pageContentEdit\" class=\"ghotiSourceEditor\" rows=\"24\" spellcheck=\"false\" aria-label=\"Page HTML source\">".$safeContent."</textarea>";
		$this->output .= "<div id=\"pageContentPreview\" class=\"ghotiEditorPreview\" aria-label=\"Page preview\"></div></div>";

		$this->output .= "<footer class=\"ghotiEditorFooter\"><div class=\"ghotiEditorStatus\"><span class=\"ghotiEditorSafeBadge\">Protected HTML</span><span id=\"ghotiEditorCount\">0 words · 0 characters</span><span id=\"ghotiEditorMessage\" role=\"status\" aria-live=\"polite\"></span></div>";
		$this->output .= "<div class=\"ghotiEditorActions\"><button type=\"button\" class=\"ghotiButton ghotiButtonDanger ghotiButtonSecondary\" onclick=\"deletePage($id);\">Delete</button><button type=\"button\" class=\"ghotiButton ghotiButtonSecondary\" onclick=\"cancelPageEditor();\">Cancel</button><button type=\"submit\" class=\"ghotiButton ghotiEditorSave\" id=\"pageSaveButton\"><img src=\"gfx/save.png\" alt=\"\" />Save &amp; publish</button></div></footer>";

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
	/*
	 * The Site Settings panel.
	 *
	 * Grouped into one card per concern rather than a single long column: the
	 * form has grown to fourteen settings, and "which of these affects what a
	 * visitor sees" is the question an admin is actually answering.
	 *
	 * The #set-* ids are a contract with saveSiteSettings() in ghoti.js, which
	 * reads each one by id. A renamed or dropped id does not error - saveSettings()
	 * skips keys the client did not send - it just silently stops saving that
	 * setting. Change an id here and there.
	 *
	 * Note defaultPageTitle is deliberately absent: the home page is chosen in
	 * Manage Pages, which is where someone looking at a list of pages expects it.
	 */
	function printSiteSettingsForm(){
		$esc = function($v){ return htmlspecialchars((string)$v, ENT_QUOTES); };
		$chk = function($b){ return $b ? " checked=\"checked\"" : ""; };

		//Small builders so each row reads as the setting it is, instead of the
		//markup it produces. Every field in this form goes through one of these.
		$text = function($id, $label, $value, $type = "text", $hint = "", $attrs = "") use ($esc){
			$o  = "<label class=\"ghotiField\"><span>".$label;
			if($hint !== ""){ $o .= " <i>".$hint."</i>"; }
			$o .= "</span><input type=\"".$type."\" id=\"set-".$id."\" value=\"".$esc($value)."\" ".$attrs."/></label>\n";
			return $o;
		};
		$choice = function($id, $label, $checked, $describedBy = "") use ($chk){
			$aria = $describedBy !== "" ? " aria-describedby=\"".$describedBy."\"" : "";
			return "<label class=\"ghotiInlineChoice\"><input type=\"checkbox\" id=\"set-".$id."\"".$chk($checked).$aria." /> ".$label."</label>\n";
		};
		$textarea = function($id, $label, $value, $hint = "", $attrs = "") use ($esc){
			$o = "<label class=\"ghotiField ghotiFieldWide\"><span>".$label;
			if($hint !== ""){ $o .= " <i>".$hint."</i>"; }
			$o .= "</span><textarea id=\"set-".$id."\" ".$attrs.">".$esc($value)."</textarea></label>\n";
			return $o;
		};

		//Installed themes: a directory under css/ with a matching loader.
		$themes = array();
		foreach(glob("css/*", GLOB_ONLYDIR) as $dir){
			$name = basename($dir);
			if(is_file("css/".$name."/".$name.".php")){ $themes[] = $name; }
		}
		sort($themes);

		$o  = "<section id=\"ghotiSiteSettings\" class=\"ghotiAdminPanel\">\n";
		$o .= "<div class=\"ghotiCrudHeader\">\n";
		$o .= "<div><h1>Site Settings</h1>\n";
		$o .= "<p class=\"ghotiHelpText\">Saved to <code>ghoti.settings.json</code>. Delete that file to fall back to the built-in defaults.</p></div>\n";
		$o .= "<div class=\"ghotiFormActions\"><button type=\"button\" class=\"ghotiButton\" onclick=\"saveSiteSettings();\">Save Settings</button></div>\n";
		$o .= "</div>\n";

		$o .= "<form id=\"siteSettingsForm\" class=\"ghotiForm\" action=\"#\" onsubmit=\"saveSiteSettings(); return false;\">\n";
		$o .= "<div class=\"siteSettingsGrid\">\n";

		/* ---- Identity: site presentation and public operator details ---- */
		$o .= "<fieldset class=\"siteSettingsSection siteSettingsSectionWide\"><legend>Identity</legend>\n";
		$o .= "<p class=\"siteSettingsSectionIntro\">The name, look, and public identity details the site presents to everyone.</p>\n";
		$o .= "<div class=\"ghotiFormGrid\">\n";
		$o .= $text("siteTitle", "Site title", ghoti::$siteTitle, "text", "", "maxlength=\"120\" ");
		$o .= "<label class=\"ghotiField\"><span>Default theme</span><select id=\"set-defaultTheme\">\n";
		foreach($themes as $t){
			$o .= "<option value=\"".$esc($t)."\"".($t === ghoti::$defaultTheme ? " selected=\"selected\"" : "").">".$esc($t)."</option>\n";
		}
		$o .= "</select></label>\n";
		$o .= $text("headerImg", "Header image", ghoti::$headerImg, "text", "(path)", "maxlength=\"200\" placeholder=\"gfx/ghoti-logo.png\" ");
		$o .= $text("privacyOperator", "Legal operator name", ghoti::$privacyOperator, "text", "", "maxlength=\"120\" placeholder=\"Example Media Ltd.\" ");
		$o .= $text("privacyRegion", "Province or territory", ghoti::$privacyRegion, "text", "", "maxlength=\"120\" placeholder=\"Alberta, Canada\" ");
		$o .= "</div>\n";
		$contact = ghoti_admin_contact_email();
		$o .= "<p class=\"ghotiHelpText\">Theme and header image apply on the next page load. Public identity details appear in the footer policy and accessibility notice; leave them blank to omit that notice. The published contact address is the administrator account&rsquo;s own e-mail (currently <code>".$esc($contact === '' ? 'none on file' : $contact)."</code>) &mdash; change it in <b>Manage Users</b>. The home page is chosen in <b>Manage Pages</b>.</p>\n";
		$o .= "</fieldset>\n";

		/* ---- Visitor access ---- */
		$o .= "<fieldset class=\"siteSettingsSection\"><legend>Visitor access</legend>\n";
		$o .= "<p class=\"siteSettingsSectionIntro\">What visitors may do without an account.</p>\n";
		$o .= "<div class=\"siteSettingsChoices\">\n";
		$o .= $choice("allowRegister", "Allow new user registration", ghoti::$allowRegister);
		$o .= $choice("enableThemeChanger", "Show the theme-changer dropdown", ghoti::$enableThemeChanger);
		$o .= $choice("hideLoginButton", "Hide the login button", ghoti::$hideLoginButton, "set-hideLoginButton-help");
		$o .= "</div>\n";
		$o .= "<p class=\"ghotiHelpText\" id=\"set-hideLoginButton-help\">With the login button hidden, sign in via <a href=\"?theme=login\">?theme=login</a> &mdash; that reveals it for one visit and keeps your theme.</p>\n";
		$o .= "</fieldset>\n";

		/* ---- Administrator experience ---- */
		$o .= "<fieldset class=\"siteSettingsSection\"><legend>Admin experience</legend>\n";
		$o .= "<p class=\"siteSettingsSectionIntro\">Choose how much guidance appears beside everyday tools.</p>\n";
		$o .= "<div class=\"siteSettingsChoices\">\n";
		$o .= $choice("showHelpTips", "Show contextual &ldquo;How to&rdquo; tips", ghoti::$showHelpTips, "set-showHelpTips-help");
		$o .= "</div>\n";
		$o .= "<p class=\"ghotiHelpText\" id=\"set-showHelpTips-help\">This hides expandable tips across admin screens. The complete guide remains available under <button type=\"button\" class=\"ghotiTextButton\" onclick=\"showDocumentation();\">Documentation</button>.</p>\n";
		$o .= "</fieldset>\n";

		/* ---- Security policy and IP access controls ---- */
		$o .= "<fieldset class=\"siteSettingsSection siteSettingsSectionWide securitySettingsSection\"><legend>Security</legend>\n";
		$o .= "<p class=\"siteSettingsSectionIntro\">Control login abuse, network blocks, and authenticated session lifetime.</p>\n";
		$o .= "<div class=\"siteSettingsChoices\">\n";
		$o .= $choice("securityAutoBlacklist", "Automatically blacklist IPs after repeated failed logins", ghoti::$securityAutoBlacklist, "set-securityAutoBlacklist-help");
		$o .= "</div>\n";
		$o .= "<div id=\"security-auto-controls\" class=\"settingsDependent securityAutoControls\"".(ghoti::$securityAutoBlacklist ? "" : " data-inactive=\"true\"").">\n";
		$o .= "<div class=\"ghotiFormGrid\">\n";
		$o .= $text("securityFailedLoginThreshold", "Failed attempts", ghoti::$securityFailedLoginThreshold, "number", "(3–100)", "min=\"3\" max=\"100\" step=\"1\" ");
		$o .= $text("securityFailureWindowMinutes", "Within minutes", ghoti::$securityFailureWindowMinutes, "number", "(1–1440)", "min=\"1\" max=\"1440\" step=\"1\" ");
		$o .= $text("securityBlacklistDurationMinutes", "Block for minutes", ghoti::$securityBlacklistDurationMinutes, "number", "(5–43200)", "min=\"5\" max=\"43200\" step=\"1\" ");
		$o .= "</div></div>\n";
		$o .= "<p class=\"ghotiHelpText\" id=\"set-securityAutoBlacklist-help\">The default promotes 10 failures within 15 minutes to a 24-hour block. The existing short login throttle still slows earlier attempts.</p>\n";
		$o .= "<div class=\"ghotiFormGrid securityRuleGrid\">\n";
		$o .= $textarea("securityIpBlacklist", "Manual IP blacklist", ghoti::$securityIpBlacklist, "(one IP or CIDR per line)", "rows=\"5\" spellcheck=\"false\" placeholder=\"203.0.113.24&#10;198.51.100.0/24\" ");
		$o .= $textarea("securityIpAllowlist", "Automatic-block allowlist", ghoti::$securityIpAllowlist, "(one IP or CIDR per line)", "rows=\"5\" spellcheck=\"false\" placeholder=\"192.0.2.10&#10;2001:db8::/48\" ");
		$o .= "</div>\n";
		$o .= "<div class=\"ghotiFormGrid securitySessionGrid\">\n";
		$o .= $text("sessionTimeoutMinutes", "Session idle timeout", ghoti::$sessionTimeoutMinutes, "number", "(minutes)", "min=\"5\" max=\"1440\" step=\"1\" ");
		$o .= "</div>\n";
		$currentIp = ghoti_remote_addr();
		$o .= "<p class=\"ghotiHelpText\">Your current server-observed address is <code>".$esc($currentIp === '' ? 'unavailable' : $currentIp)."</code>. Manual rules override the allowlist; your current address cannot be added while saving from it.</p>\n";
		$o .= "<div class=\"securityBlockList\"><div class=\"securityBlockListHeader\"><strong>Automatic blacklist</strong><span>Active runtime blocks</span></div>\n";
		try{ $activeBlocks = GhotiIpBlockStore::active(); }
		catch(Throwable $e){ $activeBlocks = null; }
		if($activeBlocks === null){
			$o .= "<p class=\"ghotiHelpText\">Blacklist storage is unavailable. Check that the application directory is writable.</p>\n";
		}elseif(!$activeBlocks){
			$o .= "<p class=\"ghotiEmptyState\">No IPs are automatically blocked.</p>\n";
		}else{
			$o .= "<ul>\n";
			foreach($activeBlocks as $ip => $entry){
				$expires = date('Y-m-d H:i T', (int)($entry['expires'] ?? 0));
				$failures = (int)($entry['failures'] ?? 0);
				$ipJs = htmlspecialchars(json_encode((string)$ip), ENT_QUOTES, 'UTF-8');
				$o .= "<li><div><code>".$esc($ip)."</code><small>".$failures." failures · expires ".$esc($expires)."</small></div><button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" onclick=\"clearAutoBlockedIp(".$ipJs.");\">Unblock</button></li>\n";
			}
			$o .= "</ul>\n";
		}
		$o .= "</div>\n";
		$o .= "</fieldset>\n";

		/* ---- Logging and alerts. There is no recipient field: alerts go to every
		 * administrator account, so keeping that list current is user
		 * management's job rather than a setting to retype here. ---- */
		$o .= "<fieldset class=\"siteSettingsSection\"><legend>Logging &amp; alerts</legend>\n";
		$o .= "<p class=\"siteSettingsSectionIntro\">Diagnostics, and who hears about incidents.</p>\n";
		$o .= "<div class=\"siteSettingsChoices\">\n";
		$o .= $choice("enableDebug", "Enable debug logging", ghoti::$enableDebug);
		$o .= $choice("enableCriticalAlerts", "E-mail critical log alerts", ghoti::$enableCriticalAlerts);
		$o .= "</div>\n";
		$admins = ghoti_admin_emails();
		if($admins){
			$parts = array();
			foreach($admins as $address){ $parts[] = "<code>".$esc($address)."</code>"; }
			$o .= "<p class=\"ghotiHelpText\">Alerts go to every administrator account: ".implode(", ", $parts)."</p>\n";
		}else{
			$o .= "<p class=\"ghotiHelpText\">No administrator account has a valid e-mail address yet, so alerts cannot be enabled. Add one in <b>Manage Users</b>.</p>\n";
		}
		$o .= "<p class=\"ghotiHelpText\">Sent through <b>Mail Settings</b>, addressed to each administrator individually. Covers application errors, and 5 failed-login or suspicious-access events within 15 minutes &mdash; one delivery per category per 15 minutes.</p>\n";
		$o .= "</fieldset>\n";

		/* ---- Optional modules ---- */
		$o .= "<fieldset class=\"siteSettingsSection siteSettingsSectionWide\"><legend>Optional modules</legend>\n";
		$o .= "<p class=\"siteSettingsSectionIntro\">Off by default. Turning one off leaves its saved configuration alone.</p>\n";
		$o .= "<div class=\"siteSettingsChoices\">\n";
		$o .= $choice("enableVhosts", "Apache Vhosts &mdash; manage virtual hosts and TLS certificates", ghoti::$enableVhosts);
		$o .= $choice("enableStore", "Store &mdash; sell physical and digital goods through PayPal", ghoti::$enableStore, "set-enableStore-help");
		$o .= "</div>\n";
		$o .= "<p class=\"ghotiHelpText\">Save and reload to show or hide it in the workspace. It opens read-only; writing to Apache needs a root-owned helper installed first.</p>\n";
		$o .= "<p class=\"ghotiHelpText\" id=\"set-enableStore-help\">The store sells nothing until PayPal credentials are saved under <b>Admin Menu &rarr; Store</b>. Enabling it also lets pages load PayPal&rsquo;s button script, which widens this site&rsquo;s content-security policy to PayPal&rsquo;s hosts; turning the store off restores the tighter policy. See the <a href=\"docs/store.md\" target=\"_blank\" rel=\"noopener noreferrer\">store guide</a>.</p>\n";
		$o .= ghoti_docs_panel("Enabling Apache Vhosts", "optional, staged setup", array(
			array('heading' => 'In stages',
				'list' => array('Tick the box, save, and reload the page.',
					'Open <b>Apache Vhosts &rarr; Settings</b> and check the paths. Leave <b>Allow changes</b> off while you look around.',
					'For writes, follow the <a href="docs/vhosts-enablement.md">server setup guide</a> to install the root-owned helper and its sudoers rule, confirm <code>apachectl configtest</code> passes, then turn on <b>Allow changes</b>.',
					'Certificate monitoring is set up separately. Disabling this stops the module and its certificate watcher; the Apache sites themselves keep running.'))
		));
		$o .= "</fieldset>\n";

		$o .= "</div>\n"; //siteSettingsGrid
		$o .= "<div class=\"ghotiFormActions siteSettingsFooterActions\"><button type=\"button\" class=\"ghotiButton\" onclick=\"saveSiteSettings();\">Save Settings</button>\n";
		$o .= "<span class=\"ghotiHelpText\">Most changes apply on the next page load.</span></div>\n";
		$o .= "</form>\n";
		$o .= "</section>\n";
		return $o;
	}
}
