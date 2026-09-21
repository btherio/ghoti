<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
/*
 * Run: php tests/links.php - no database, no browser, no network.
 *
 * The bug that started this: adding a duplicate link made addLink() return a
 * bare `false`, which the popup printed as the word "false". So the claim worth
 * protecting is that EVERY endpoint answers with one envelope -
 * { success, data, error:{code,message} } - on every path, and that a failure
 * always carries a message a person can read. Around that:
 *
 *   1. URL normalisation: "example.com" must not be stored as a relative path.
 *   2. Validation against the table's real column widths (no silent truncation).
 *   3. The admin gate, including the "all" listing that reveals who added what.
 *   4. The [links:group] shortcode: matching, escaping, and refusing bad schemes.
 */
chdir(dirname(__DIR__));
require_once 'ghoti.php';

$checks = 0;
function linkCheck($ok, $label){
	global $checks; $checks++;
	if(!$ok){ throw new RuntimeException($label); }
}

//The admin gate calls isAdmin(), and addLink() calls checkLogin(); both live in
//the login module, which is not loaded here.
function isAdmin($userId){ return !empty($GLOBALS['linkTestAdmin']); }
function checkLogin(){ return 1; }
function linkTestSignIn($isAdmin){
	$GLOBALS['linkTestAdmin'] = $isAdmin;
	$_SESSION['loggedIn'] = true;
	$_SESSION['userId'] = 1;
}
function linkTestSignOut(){
	$GLOBALS['linkTestAdmin'] = false;
	unset($_SESSION['loggedIn'], $_SESSION['userId']);
}

/* A stand-in for linksdb backed by an array. No table, no connection. */
class LinksDbFake{
	public $rows = array();
	public $nextId = 1;
	public $fail = false; //simulate a database outage: every method returns false
	public function seed($name, $url, $grp, $userName = 'admin'){
		$id = $this->nextId++;
		$this->rows[$id] = array('id' => $id, 'name' => $name, 'url' => $url, 'grp' => $grp, 'userName' => $userName);
		return $id;
	}
	public function getGroups(){
		if($this->fail){ return false; }
		$groups = array_values(array_unique(array_column($this->rows, 'grp')));
		sort($groups);
		return $groups;
	}
	public function getLinks($group = 'default'){
		if($this->fail){ return false; }
		return array_values(array_filter($this->rows, function($r) use ($group){ return $group === 'all' || $r['grp'] === $group; }));
	}
	public function getLink($id){
		if($this->fail){ return false; }
		return isset($this->rows[$id]) ? $this->rows[$id] : null;
	}
	public function findDuplicate($name, $url, $group, $excludeId = 0){
		if($this->fail){ return false; }
		foreach($this->rows as $r){
			if($r['id'] === (int)$excludeId || $r['grp'] !== $group){ continue; }
			if($r['url'] === $url){ return 'url'; }
			if($r['name'] === $name){ return 'name'; }
		}
		return null;
	}
	public function addLink($userId, $name, $url, $group = 'default'){
		return $this->fail ? false : $this->seed($name, $url, $group);
	}
	public function editLink($id, $name, $url, $grp){
		if($this->fail){ return false; }
		$this->rows[$id] = array_merge($this->rows[$id], array('name' => $name, 'url' => $url, 'grp' => $grp));
		return true;
	}
	public function deleteLink($id){
		if($this->fail){ return false; }
		unset($this->rows[$id]);
		return true;
	}
}

$loader = (new ReflectionClass(ghoti::class))->newInstanceWithoutConstructor();
$loader->loadModules(array('links'));

function linksModule($db){
	$module = (new ReflectionClass(links::class))->newInstanceWithoutConstructor();
	$module->linksdb = $db;
	return $module;
}
$db = new LinksDbFake();
$_SESSION['linksObj'] = linksModule($db);

//The single most important assertion: a reply is an envelope, and a failure
//explains itself. Returns the reply so callers can keep asserting on it.
function linkEnvelope($reply, $label){
	linkCheck(is_array($reply) && array_key_exists('success', $reply) && array_key_exists('data', $reply) && array_key_exists('error', $reply),
		"$label: reply is not a {success,data,error} envelope: ".var_export($reply, true));
	linkCheck(is_bool($reply['success']), "$label: success is not a boolean");
	if($reply['success']){
		linkCheck($reply['error'] === null, "$label: a success carries an error");
	}else{
		linkCheck($reply['data'] === null, "$label: a failure carries data");
		linkCheck(is_array($reply['error']) && !empty($reply['error']['code']) && is_string($reply['error']['message']) && $reply['error']['message'] !== '',
			"$label: a failure has no code/message - the UI would print a bare word");
		linkCheck(!in_array(strtolower($reply['error']['message']), array('false', 'true', 'null'), true), "$label: the message is a bare boolean");
	}
	return $reply;
}
function linkFails($reply, $code, $label){
	linkEnvelope($reply, $label);
	linkCheck($reply['success'] === false && $reply['error']['code'] === $code,
		"$label: expected failure '$code', got ".json_encode($reply));
	return $reply;
}

/* ---------------- endpoints are registered ---------------- */

foreach(array('addLink', 'saveLink', 'deleteLink', 'getLinks', 'getLinkGroups') as $fn){
	linkCheck(ghoti_async_is_registered($fn), "$fn is not callable from the browser");
}
linkCheck(isset($GLOBALS['ghoti_shortcode_handlers']['links']), 'The [links:group] shortcode is not registered');

/* ---------------- URL normalisation ---------------- */

$urls = array(
	'example.com'                => 'https://example.com',
	'www.example.com/a?b=1'      => 'https://www.example.com/a?b=1',
	'example.com:8080/x'         => 'https://example.com:8080/x',
	'sub.example.co.uk'          => 'https://sub.example.co.uk',
	'me@example.com'             => 'mailto:me@example.com',
	'https://example.com'        => 'https://example.com',
	'http://example.com'         => 'http://example.com',
	'mailto:me@example.com'      => 'mailto:me@example.com',
	'/about'                     => '/about',
	'#top'                       => '#top',
	'?view=privacy'              => '?view=privacy',
	'about.html'                 => 'about.html',   //a page on this site, not host "about" on the .html domain
	'notes/2026.txt'             => 'notes/2026.txt',
	'  spaced.example.com  '     => 'https://spaced.example.com',
	'javascript:alert(1)'        => 'javascript:alert(1)', //left for the validator to reject, never "fixed"
	''                           => '',
);
foreach($urls as $in => $want){
	linkCheck(links_normalizeUrl($in) === $want, "links_normalizeUrl('$in') gave '".links_normalizeUrl($in)."', wanted '$want'");
}

/* ---------------- group slugs ---------------- */

linkCheck(links_slug('Field Guides') === 'field-guides', 'A space is not a hyphen in the slug');
linkCheck(links_slug('field_guides') === links_slug('Field  Guides'), '_ and space are not equivalent in the slug');
linkCheck(links_slug(' default ') === 'default', 'The slug is not trimmed');
linkCheck(preg_match('/^[A-Za-z0-9_.-]+$/', links_slug('A b_c-d 9')) === 1, 'A slug can not be written inside [links:...]');

/* ---------------- the admin gate ---------------- */

linkTestSignOut();
foreach(array(
	'addLink'       => addLink('Site', 'https://example.com', 'default'),
	'saveLink'      => saveLink(1, 'Site', 'https://example.com', 'default'),
	'deleteLink'    => deleteLink(1),
	'getLinkGroups' => getLinkGroups(),
	'getLinks(all)' => getLinks('all'),
) as $label => $reply){
	linkFails($reply, 'forbidden', "$label (signed out)");
}
linkTestSignIn(false);
linkFails(addLink('Site', 'https://example.com', 'default'), 'forbidden', 'addLink (signed in, not admin)');
linkFails(getLinks('all'), 'forbidden', 'getLinks(all) (signed in, not admin)');
linkCheck(count($db->rows) === 0, 'A forbidden call still wrote a row');

//The sidebar is public and must not reveal who added a link.
linkTestSignOut();
$db->seed('PHP', 'http://www.php.net', 'default', 'secretadmin');
$public = linkEnvelope(getLinks('default'), 'getLinks(default) (signed out)');
linkCheck($public['success'] && count($public['data']['links']) === 1, 'The public sidebar list did not load');
linkCheck(!isset($public['data']['links'][0]['userName']), 'The public list leaks who added the link');
linkCheck($public['data']['group'] === 'default', 'The reply does not name its group');
$db->rows = array(); $db->nextId = 1;

/* ---------------- add ---------------- */

linkTestSignIn(true);
$added = linkEnvelope(addLink('Example', 'example.com', 'Resources'), 'addLink');
linkCheck($added['success'] && $added['data']['link']['url'] === 'https://example.com', 'A bare domain was not given https://');
linkCheck($added['data']['link']['grp'] === 'Resources' && $added['data']['link']['slug'] === 'resources', 'The stored group/slug is wrong');
linkCheck(count($db->rows) === 1, 'The link was not written');

//A blank group means the default group - the first link ever added has no groups to pick from.
$def = addLink('Docs', 'https://docs.example.com', '');
linkCheck($def['success'] && $def['data']['link']['grp'] === 'default', 'A blank group did not become "default"');

//The regression: a duplicate used to come back as `false`.
$dupUrl = linkFails(addLink('Different name', 'https://example.com', 'Resources'), 'duplicate', 'duplicate URL');
linkCheck(strpos($dupUrl['error']['message'], 'URL') !== false && strpos($dupUrl['error']['message'], 'Resources') !== false, 'The duplicate-URL message does not say what collided: '.$dupUrl['error']['message']);
$dupName = linkFails(addLink('Example', 'https://other.example.com', 'Resources'), 'duplicate', 'duplicate name');
linkCheck(strpos($dupName['error']['message'], 'Example') !== false, 'The duplicate-name message does not name the link');
//A group is a category: the same address may live in two of them.
linkCheck(addLink('Example', 'https://example.com', 'Other')['success'], 'The same link in a different group was refused');
linkCheck(count($db->rows) === 3, 'Row count wrong after duplicate tests');

//A database outage is its own error, not "duplicate" and not `false`.
$db->fail = true;
linkFails(addLink('New', 'https://new.example.com', 'default'), 'db_error', 'addLink during a database outage');
linkFails(saveLink(1, 'New', 'https://new.example.com', 'default'), 'db_error', 'saveLink during a database outage');
linkFails(deleteLink(1), 'db_error', 'deleteLink during a database outage');
linkFails(getLinks('all'), 'db_error', 'getLinks during a database outage');
linkFails(getLinkGroups(), 'db_error', 'getLinkGroups during a database outage');
$db->fail = false;

/* ---------------- validation ---------------- */

linkFails(addLink('', 'https://example.com', 'default'), 'invalid', 'empty name');
linkFails(addLink('Name', '', 'default'), 'invalid', 'empty URL');
linkFails(addLink('Name', 'javascript:alert(1)', 'default'), 'invalid', 'javascript: URL');
linkFails(addLink('Name', 'data:text/html,<script>1</script>', 'default'), 'invalid', 'data: URL');
linkFails(addLink('Name', "java\tscript:alert(1)", 'default'), 'invalid', 'obfuscated javascript: URL');
$long = linkFails(addLink(str_repeat('n', LINK_NAME_MAX + 1), 'https://long.example.com', 'default'), 'invalid', 'name over the column width');
linkCheck(strpos($long['error']['message'], (string)LINK_NAME_MAX) !== false, 'The length message does not say the limit');
linkFails(addLink('Name', 'https://example.com/'.str_repeat('a', LINK_URL_MAX), 'default'), 'invalid', 'URL over the column width');
linkFails(addLink('Name', 'https://g.example.com', str_repeat('g', LINK_GROUP_MAX + 1)), 'invalid', 'group over the column width');
linkFails(addLink('Name', 'https://g.example.com', 'News!'), 'invalid', 'group with characters that would be stripped');
linkCheck(count($db->rows) === 3, 'A rejected link was still written');
//Exactly at the limit is fine.
linkCheck(addLink(str_repeat('n', LINK_NAME_MAX), 'https://max.example.com', str_repeat('g', LINK_GROUP_MAX))['success'], 'A name/group exactly at the column width was refused');
//Markup in a name is stripped, not stored.
$tagged = addLink('<b>Bold</b>', 'https://bold.example.com', 'default');
linkCheck($tagged['success'] && $tagged['data']['link']['name'] === 'Bold', 'Markup survived in a link name');

/* ---------------- save ---------------- */

$id = $added['data']['link']['id'];
$saved = linkEnvelope(saveLink($id, 'Renamed', 'renamed.example.com', 'Resources'), 'saveLink');
linkCheck($saved['success'] && $db->rows[$id]['url'] === 'https://renamed.example.com' && $db->rows[$id]['name'] === 'Renamed', 'Save did not store the normalised values');
//Saving a link without changing it must not collide with itself.
linkCheck(saveLink($id, 'Renamed', 'https://renamed.example.com', 'Resources')['success'], 'A link collided with itself on save');
//Save is as strict as add (it used to have no duplicate check at all).
linkFails(saveLink($def['data']['link']['id'], 'Docs', 'https://renamed.example.com', 'Resources'), 'duplicate', 'saving into a duplicate');
//Moving to another group.
$moved = saveLink($id, 'Renamed', 'https://renamed.example.com', 'Elsewhere');
linkCheck($moved['success'] && $moved['data']['link']['grp'] === 'Elsewhere', 'Moving a link to a new group failed');
linkFails(saveLink($id, 'Renamed', 'javascript:alert(1)', 'Elsewhere'), 'invalid', 'saving a javascript: URL');
//An UPDATE that matches nothing used to report "Link saved!".
linkFails(saveLink(9999, 'Ghost', 'https://ghost.example.com', 'default'), 'not_found', 'saving a link that no longer exists');
linkFails(saveLink('abc', 'Ghost', 'https://ghost.example.com', 'default'), 'invalid', 'a non-numeric id');

/* ---------------- delete ---------------- */

$before = count($db->rows);
$deleted = linkEnvelope(deleteLink($id), 'deleteLink');
linkCheck($deleted['success'] && !isset($db->rows[$id]) && count($db->rows) === $before - 1, 'Delete did not remove the row');
//Delete used to report success for anything.
linkFails(deleteLink($id), 'not_found', 'deleting the same link twice');
linkFails(deleteLink('nope'), 'invalid', 'deleting a non-numeric id');

/* ---------------- listing ---------------- */

$all = linkEnvelope(getLinks('all'), 'getLinks(all) as admin');
linkCheck($all['success'] && isset($all['data']['links'][0]['userName']), 'The admin list does not say who added each link');
$groups = linkEnvelope(getLinkGroups(), 'getLinkGroups');
linkCheck($groups['success'] && in_array('default', $groups['data']['groups'], true), 'The group list does not include "default"');

/* ---------------- the [links:group] shortcode ---------------- */

$db->rows = array(); $db->nextId = 1;
$db->seed('PHP', 'http://www.php.net', 'default');
$db->seed('Field Guide', 'https://guides.example.com/a?x=1&y=2', 'Field Guides');
$db->seed('Owls "R" Us', 'https://owls.example.com', 'Field Guides');
$db->seed('Mail me', 'mailto:me@example.com', 'Field Guides');
$db->seed('Local', '/about', 'Field Guides');
$db->seed('Evil', 'javascript:alert(1)', 'Field Guides'); //legacy/imported row that predates validation
$db->seed('<b>x</b>', 'https://x.example.com', 'Field Guides');

$page = ghoti_expand_shortcodes('<p>Before</p>[links:field-guides]<p>After</p>');
linkCheck(strpos($page, '[links:') === false, 'The tag survived expansion');
linkCheck(strpos($page, '<p>Before</p>') === 0 && substr($page, -strlen('<p>After</p>')) === '<p>After</p>', 'Expansion damaged the surrounding content');
linkCheck(strpos($page, 'class="ghotiLinkList"') !== false && strpos($page, 'data-links-group="field-guides"') !== false, 'The list was not rendered');
linkCheck(strpos($page, 'guides.example.com/a?x=1&amp;y=2') !== false, 'The URL was not attribute-escaped');
linkCheck(strpos($page, 'Owls &quot;R&quot; Us') !== false, 'A quote in a name was not escaped');
linkCheck(strpos($page, '&lt;b&gt;x&lt;/b&gt;') !== false && strpos($page, '<b>x</b>') === false, 'Markup in a stored name was rendered');
linkCheck(strpos($page, 'javascript') === false && strpos($page, 'Evil') === false, 'A stored javascript: link was rendered on a page');
linkCheck(strpos($page, 'href="mailto:me@example.com"') !== false && strpos($page, 'href="/about"') !== false, 'mailto: and site-relative links were dropped');
linkCheck(strpos($page, 'rel="noopener noreferrer"') !== false, 'External links are not hardened');
linkCheck(strpos($page, 'PHP') === false, 'A link from another group leaked in');

//Every spelling of the group finds it; the space form is not expressible in a tag.
foreach(array('[links:Field-Guides]', '[links:field_guides]', '[links Field-Guides]') as $tag){
	linkCheck(strpos(ghoti_expand_shortcodes($tag), 'data-links-group="field-guides"') !== false, "$tag did not match the group");
}
linkCheck(strpos(ghoti_expand_shortcodes('[links:default]'), 'PHP') !== false, '[links:default] did not render the default group');
//Two on one page.
linkCheck(substr_count(ghoti_expand_shortcodes('[links:default] [links:field-guides]'), 'class="ghotiLinkList"') === 2, 'Two shortcodes on one page did not both render');

//Two groups that differ only by case/separator share a slug; neither is hidden.
$db->seed('Second', 'https://second.example.com', 'field_guides');
$both = ghoti_expand_shortcodes('[links:field-guides]');
linkCheck(strpos($both, 'Second') !== false && strpos($both, 'Field Guide') !== false, 'Groups sharing a slug did not both render');
unset($db->rows[$db->nextId - 1]);

$missing = ghoti_expand_shortcodes('[links:nope]');
linkCheck(strpos($missing, 'ghotiLinksMissing') !== false && strpos($missing, 'nope') !== false, 'An unknown group gives no explanation');
$missingXss = ghoti_expand_shortcodes('[links:x"onmouseover=alert(1)]');
linkCheck(strpos($missingXss, '[links:') !== false, 'A value outside the shortcode charset was expanded'); //left untouched, so it is plain text
$db->fail = true;
linkCheck(ghoti_expand_shortcodes('[links:default]') === '', 'A database outage put an error on a public page');
$db->fail = false;
unset($_SESSION['linksObj']);
linkCheck(ghoti_expand_shortcodes('[links:default]') === '', 'The shortcode did not degrade to nothing without the module');

echo "PASS: $checks link assertions; no database, no browser, no network\n";
