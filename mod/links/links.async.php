<?php
/*
 * links.async.php - links module endpoints and the [links:group] shortcode.
 *
 * Every endpoint returns one envelope, so the browser never has to guess what a
 * bare true/false/string meant (an old duplicate-link reply of `false` was
 * shown to the admin as the word "false"):
 *
 *   { "success": true,  "data": {...},  "error": null }
 *   { "success": false, "data": null,   "error": { "code": "...", "message": "..." } }
 *
 * Error codes: forbidden, invalid, duplicate, not_found, db_error.
 *
 * The column widths below are the links table's real widths (links.sql). The
 * schema upgrade only ever adds columns, so they cannot be widened on a live
 * site; validating against them turns a silently cut-off name into a message.
 */

const LINK_NAME_MAX  = 32;
const LINK_GROUP_MAX = 32;
const LINK_URL_MAX   = 500;

/* ---------------------------------------------------------------- *
 *  Pure helpers (no session, no database)
 * ---------------------------------------------------------------- */

function links_ok($data){
	return array('success' => true, 'data' => $data, 'error' => null);
}

function links_fail($code, $message){
	return array('success' => false, 'data' => null, 'error' => array('code' => $code, 'message' => $message));
}

//What people actually type: "example.com" would otherwise be stored as a
//site-relative path and 404 on this site, and "me@example.com" as a relative
//file. Only fills in a missing scheme; anything that already has one, or looks
//like a path, anchor or query, is left for the validator to judge.
function links_normalizeUrl($url){
	$v = trim((string)$url);
	//"example.com:8080" looks like the scheme "example.com:", so spot host:port first.
	$hasPort = preg_match('#^(?:[A-Za-z0-9-]+\.)+[A-Za-z]{2,}:\d+(?:[/?\#]|$)#', $v);
	if($v === '' || (!$hasPort && preg_match('#^([A-Za-z][A-Za-z0-9+.\-]*:|[/\#?.])#', $v))){
		return $v;
	}
	if(preg_match('/^[^\s\/@]+@[^\s\/@]+\.[A-Za-z]{2,}$/', $v)){
		return 'mailto:'.$v;
	}
	$isHost = preg_match('#^(?:[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\.)+([A-Za-z]{2,})(?::\d+)?(?:[/?\#].*)?$#', $v, $m);
	//"about.html" is a page on this site, not a host on the .html domain.
	$fileExtensions = array('html','htm','php','pdf','png','jpg','jpeg','gif','txt','css','js');
	if($isHost && !in_array(strtolower($m[1]), $fileExtensions, true)){
		return 'https://'.$v;
	}
	return $v;
}

//A group as it appears in a page: [links:my-group] matches the group "My Group".
//The shortcode syntax has no room for spaces, so case, spaces, "_" and "-" are
//all treated as the same separator.
function links_slug($group){
	return strtolower(trim(preg_replace('/[\s_-]+/', '-', (string)$group), '-'));
}

//Validate and normalise user input for add/save. Returns array(name,url,group)
//or throws Exception with a message that is safe to show the admin.
function links_prepare($name, $url, $group){
	$v = ghoti_validate();
	//max 0 = no silent truncation; the length is checked (and reported) below.
	$name = $v->text($name, 0, true, "link name");
	if(mb_strlen($name) > LINK_NAME_MAX){
		throw new Exception("Link name can be at most ".LINK_NAME_MAX." characters.");
	}
	$url = $v->url(links_normalizeUrl($url), true, "link URL");
	if(mb_strlen($url) > LINK_URL_MAX){
		throw new Exception("Link URL can be at most ".LINK_URL_MAX." characters.");
	}
	$group = trim((string)$group);
	if(mb_strlen($group) > LINK_GROUP_MAX){
		throw new Exception("Group name can be at most ".LINK_GROUP_MAX." characters.");
	}
	//linkGroup() strips what it dislikes; refuse instead, so "News!" is not
	//quietly saved as "News". A blank group means the default group.
	$clean = $v->linkGroup($group, false);
	if($group !== '' && $clean !== $group){
		throw new Exception("Group names may only use letters, numbers, spaces, - and _.");
	}
	return array($name, $url, $clean);
}

//The <ul> a page shows for [links:group]. $rows come from linksdb->getLinks();
//everything is escaped here because page shortcode output is not re-sanitised.
function links_renderList($group, $rows){
	$v = ghoti_validate();
	$items = '';
	foreach($rows as $row){
		try{
			$url = $v->url($row['url'], true, "link URL"); //stored data is re-checked, not trusted
		}catch(Exception $e){
			continue;
		}
		$label = $row['name'] !== '' ? $row['name'] : $url;
		$rel = preg_match('#^https?://#i', $url) ? ' rel="noopener noreferrer"' : '';
		$items .= '<li><a href="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'"'.$rel.'>'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</a></li>';
	}
	if($items === ''){ return ''; }
	return '<ul class="ghotiLinkList" data-links-group="'.htmlspecialchars(links_slug($group), ENT_QUOTES, 'UTF-8').'">'.$items.'</ul>';
}

/* ---------------------------------------------------------------- *
 *  Endpoints
 * ---------------------------------------------------------------- */

//Public: the sidebar loads the default group for every visitor. "all" is the
//admin manager's view and also carries who added each link, so it is gated.
function getLinks($group="default"){
	$isAll = ($group === "all");
	if($isAll){
		if(!ghoti_require_admin()){ return links_fail('forbidden', "Admin access required."); }
	}else{
		$group = ghoti_validate()->linkGroup($group);
	}
	$links = $_SESSION["linksObj"]->linksdb->getLinks($group);
	if($links === false){
		return links_fail('db_error', "Could not load links. Try again in a moment.");
	}
	foreach($links as $i => $link){
		$links[$i]['slug'] = links_slug($link['grp']);
		if(!$isAll){ unset($links[$i]['userName']); }
	}
	return links_ok(array('group' => $group, 'links' => $links));
}

function getLinkGroups(){
	if(!ghoti_require_admin()){ return links_fail('forbidden', "Admin access required."); }
	$groups = $_SESSION["linksObj"]->linksdb->getGroups();
	if($groups === false){
		return links_fail('db_error', "Could not load groups.");
	}
	return links_ok(array('groups' => $groups));
}

//Shared by add and save: the reason a link collides with another in its group.
function links_duplicateFailure($db, $name, $url, $group, $excludeId){
	$dupe = $db->findDuplicate($name, $url, $group, $excludeId);
	if($dupe === false){
		return links_fail('db_error', "Could not check for duplicates. Try again in a moment.");
	}
	if($dupe === 'url'){
		return links_fail('duplicate', "That URL is already in the \"$group\" group.");
	}
	if($dupe === 'name'){
		return links_fail('duplicate', "A link named \"$name\" is already in the \"$group\" group.");
	}
	return null;
}

function addLink($name,$url,$group){
	if(!ghoti_require_admin()){ return links_fail('forbidden', "Admin access required."); }
	$userId = checkLogin();
	try{
		list($name, $url, $group) = links_prepare($name, $url, $group);
	}catch(Exception $e){
		ghoti::logInfo("links.async.php:addLink", "Rejected: ".ghoti_validate()->logLine($e->getMessage()));
		return links_fail('invalid', $e->getMessage());
	}
	$db = $_SESSION["linksObj"]->linksdb;
	$conflict = links_duplicateFailure($db, $name, $url, $group, 0);
	if($conflict !== null){
		ghoti::logInfo("links.async.php:addLink", "Rejected duplicate: ".ghoti_validate()->logLine($url));
		return $conflict;
	}
	$id = $db->addLink($userId, $name, $url, $group);
	if($id === false){
		ghoti::logError("links.async.php:addLink", "Failed to add link. ".ghoti_validate()->logLine($url));
		return links_fail('db_error', "The link could not be saved. Try again in a moment.");
	}
	ghoti::logInfo("links.async.php:addLink", "Added link $id ($name) to the group $group by user $userId from ".ghoti_remote_addr().".");
	return links_ok(array('link' => array('id' => $id, 'name' => $name, 'url' => $url, 'grp' => $group, 'slug' => links_slug($group))));
}

function deleteLink($id){
	if(!ghoti_require_admin()){ return links_fail('forbidden', "Admin access required."); }
	try{
		$id = ghoti_validate()->id($id, "link id");
	}catch(Exception $e){
		return links_fail('invalid', $e->getMessage());
	}
	$db = $_SESSION["linksObj"]->linksdb;
	$existing = $db->getLink($id);
	if($existing === false){
		return links_fail('db_error', "Could not delete the link. Try again in a moment.");
	}
	if($existing === null){
		return links_fail('not_found', "That link no longer exists.");
	}
	if(!$db->deleteLink($id)){
		return links_fail('db_error', "Could not delete the link. Try again in a moment.");
	}
	ghoti::logInfo("links.async.php:deleteLink", "Deleted link $id (".ghoti_validate()->logLine($existing['name']).") by user ".ghoti_current_user_id()." from ".ghoti_remote_addr().".");
	return links_ok(array('id' => $id));
}

function saveLink($id,$name,$url,$grp){
	if(!ghoti_require_admin()){ return links_fail('forbidden', "Admin access required."); }
	try{
		$id = ghoti_validate()->id($id, "link id");
		list($name, $url, $grp) = links_prepare($name, $url, $grp);
	}catch(Exception $e){
		ghoti::logInfo("links.async.php:saveLink", "Rejected: ".ghoti_validate()->logLine($e->getMessage()));
		return links_fail('invalid', $e->getMessage());
	}
	$db = $_SESSION["linksObj"]->linksdb;
	//An UPDATE that matches no row still "succeeds", which is how saving a link
	//someone else had just deleted used to report "Link saved!".
	$existing = $db->getLink($id);
	if($existing === false){
		return links_fail('db_error', "The link could not be saved. Try again in a moment.");
	}
	if($existing === null){
		return links_fail('not_found', "That link no longer exists. Reload the list.");
	}
	$conflict = links_duplicateFailure($db, $name, $url, $grp, $id);
	if($conflict !== null){
		return $conflict;
	}
	if(!$db->editLink($id, $name, $url, $grp)){
		return links_fail('db_error', "The link could not be saved. Try again in a moment.");
	}
	ghoti::logInfo("links.async.php:saveLink", "Saved link $id ($name) in the group $grp by user ".ghoti_current_user_id()." from ".ghoti_remote_addr().".");
	return links_ok(array('link' => array('id' => $id, 'name' => $name, 'url' => $url, 'grp' => $grp, 'slug' => links_slug($grp))));
}

ghoti_async_register(
	"saveLink",
	"addLink",
	"getLinks",
	"getLinkGroups",
	"deleteLink"
);

/* ---------------------------------------------------------------- *
 *  Shortcode: [links:GROUP] / [links GROUP] inside page content.
 *  Expanded by getPage() (ghoti.async.php), like [gallery:NAME].
 * ---------------------------------------------------------------- */

function links_shortcode_expand($matches){
	$wanted = isset($matches[1]) ? links_slug($matches[1]) : '';
	if($wanted === '' || !isset($_SESSION['linksObj'])){ return ''; }
	$db = $_SESSION['linksObj']->linksdb;
	$groups = $db->getGroups();
	if($groups === false){ return ''; } //already logged; a page should not show a database error
	//"Field Guides" and "field-guides" share a slug, so a tag shows every group
	//that matches rather than silently picking one.
	$rows = array();
	$shown = '';
	foreach($groups as $group){
		if(links_slug($group) !== $wanted){ continue; }
		$shown = $shown !== '' ? $shown : $group;
		$groupRows = $db->getLinks($group); //includes userName; links_renderList() must never print it
		if($groupRows){ $rows = array_merge($rows, $groupRows); }
	}
	if($shown === ''){
		return '<p class="ghotiLinksMissing">Links group "'.htmlspecialchars(trim($matches[1]), ENT_QUOTES, 'UTF-8').'" not found.</p>';
	}
	$list = links_renderList($shown, $rows);
	return $list !== '' ? $list : '<p class="ghotiLinksMissing">No links in "'.htmlspecialchars($shown, ENT_QUOTES, 'UTF-8').'" yet.</p>';
}
ghoti_register_shortcode('links', 'links_shortcode_expand');
