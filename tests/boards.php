<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
/*
 * Run: php tests/boards.php - no database, no browser, no network.
 *
 * The boards module replaced comments, and the two claims that replacement
 * rests on are the ones worth protecting here:
 *
 *   1. Discussion appears ONLY where a [board:slug] tag puts it. The comments
 *      module appended a comment list and a comment button to every page from
 *      inside getPage(); nothing may do that again.
 *   2. Moderation is enforced on the SERVER. The comments module hid its delete
 *      icon from non-owners and called that access control, while the endpoint
 *      stayed callable by anyone who could POST.
 *
 * Around those: the envelope contract every endpoint answers with, the slug
 * charset (a slug outside it names a board no page could ever reach), the
 * read/post policies, and the two modes sharing one renderer.
 */
chdir(dirname(__DIR__));
require_once 'ghoti.php';

$checks = 0;
function boardCheck($ok, $label){
	global $checks; $checks++;
	if(!$ok){ throw new RuntimeException($label); }
}

//The admin gate calls isAdmin(); the login module is not loaded here.
function isAdmin($userId){ return !empty($GLOBALS['boardTestAdmin']); }
function checkLogin(){ return ghoti_current_user_id(); }
function boardTestSignIn($userId, $isAdmin = false){
	$GLOBALS['boardTestAdmin'] = $isAdmin;
	$_SESSION['loggedIn'] = true;
	$_SESSION['userId'] = $userId;
}
function boardTestSignOut(){
	$GLOBALS['boardTestAdmin'] = false;
	unset($_SESSION['loggedIn'], $_SESSION['userId']);
}

/* A stand-in for boardsdb backed by arrays. No table, no connection. */
class BoardsDbFake{
	public $boards = array(), $topics = array(), $posts = array(), $mods = array();
	public $nextBoard = 1, $nextTopic = 1, $nextPost = 1;
	public $fail = false;      //simulate a database outage: every read returns false
	public $failPosts = false; //fail ONLY the post reads, with the board still readable

	public function seedBoard($slug, $overrides = array()){
		$id = $this->nextBoard++;
		$this->boards[$id] = array_merge(array(
			'boardId' => $id, 'name' => ucfirst($slug), 'slug' => $slug, 'description' => '',
			'mode' => 'board', 'postPolicy' => 'users', 'readPolicy' => 'public', 'locked' => false,
		), $overrides);
		return $id;
	}
	public function seedTopic($boardId, $userId = 1, $title = 'Topic', $locked = false){
		$id = $this->nextTopic++;
		$this->topics[$id] = array('topicId'=>$id,'boardId'=>$boardId,'userId'=>$userId,'title'=>$title,
			'locked'=>$locked,'sticky'=>false,'createdAt'=>100);
		return $id;
	}
	public function seedPost($boardId, $topicId, $userId, $body = 'hello'){
		$id = $this->nextPost++;
		$this->posts[$id] = array('postId'=>$id,'boardId'=>$boardId,'topicId'=>$topicId,'userId'=>$userId,
			'body'=>$body,'createdAt'=>100);
		return $id;
	}

	public function getBoardBySlug($slug){
		if($this->fail){ return false; }
		foreach($this->boards as $b){ if($b['slug'] === $slug){ return $b; } }
		return null;
	}
	public function getBoardById($id){
		if($this->fail){ return false; }
		return isset($this->boards[$id]) ? $this->boards[$id] : null;
	}
	public function getTopic($id){
		if($this->fail){ return false; }
		return isset($this->topics[$id]) ? $this->topics[$id] : null;
	}
	public function getTopics($boardId, $limit, $offset){
		if($this->fail){ return false; }
		$rows = array();
		foreach($this->topics as $t){
			if($t['boardId'] === $boardId){ $rows[] = $t; }
		}
		//Mirror the real ordering - "order by sticky desc, lastPostAt desc,
		//topicId desc". A fake that returns insertion order hides every bug that
		//is ABOUT which row comes first, which is exactly what this one was.
		usort($rows, function($a, $b){
			if($a['sticky'] !== $b['sticky']){ return $a['sticky'] ? -1 : 1; }
			if($a['createdAt'] !== $b['createdAt']){ return $b['createdAt'] - $a['createdAt']; }
			return $b['topicId'] - $a['topicId'];
		});
		$out = array();
		foreach($rows as $t){
			$out[] = array($t['topicId'],$t['title'],$t['locked']?1:0,$t['sticky']?1:0,$t['createdAt'],$t['createdAt'],$t['userId'],'someone',1);
		}
		return array_slice($out, $offset, $limit);
	}
	public function countTopics($boardId){
		if($this->fail){ return false; }
		$n = 0; foreach($this->topics as $t){ if($t['boardId'] === $boardId){ $n++; } }
		return $n;
	}
	public function getPosts($topicId, $limit, $offset){
		if($this->fail || $this->failPosts){ return false; }
		$out = array();
		foreach($this->posts as $p){
			if($p['topicId'] !== $topicId){ continue; }
			$out[] = array($p['postId'],$p['userId'],$p['body'],$p['createdAt'],0,'someone',0,3);
		}
		return array_slice($out, $offset, $limit);
	}
	public function countPosts($topicId){
		if($this->fail || $this->failPosts){ return false; }
		$n = 0; foreach($this->posts as $p){ if($p['topicId'] === $topicId){ $n++; } }
		return $n;
	}
	public function getPost($id){
		if($this->fail){ return false; }
		return isset($this->posts[$id]) ? $this->posts[$id] : null;
	}
	public function addTopic($boardId, $userId, $title){ return $this->seedTopic($boardId, $userId, $title); }
	public function addPost($boardId, $topicId, $userId, $body){ return $this->seedPost($boardId, $topicId, $userId, $body); }
	public function editPost($id, $body){ $this->posts[$id]['body'] = $body; return true; }
	public function deletePost($id){ unset($this->posts[$id]); return true; }
	public function deleteTopic($id){
		foreach($this->posts as $pid => $p){ if($p['topicId'] === $id){ unset($this->posts[$pid]); } }
		unset($this->topics[$id]);
		return true;
	}
	public function setTopicFlags($id, $locked, $sticky){
		$this->topics[$id]['locked'] = $locked; $this->topics[$id]['sticky'] = $sticky; return true;
	}
	public function getDefaultTopicId($boardId){
		if($this->fail){ return false; }
		$ids = array();
		foreach($this->topics as $t){ if($t['boardId'] === $boardId){ $ids[] = $t['topicId']; } }
		return $ids ? min($ids) : 0;
	}
	public function getOrCreateDefaultTopic($boardId, $userId){
		$existing = $this->getDefaultTopicId($boardId);
		if($existing){ return $existing; }
		return $this->seedTopic($boardId, $userId, '');
	}
	public function isModerator($userId, $boardId){
		return !empty($this->mods[$boardId][$userId]);
	}
	public function setModerator($userId, $boardId, $on){
		if($on){ $this->mods[$boardId][$userId] = true; } else { unset($this->mods[$boardId][$userId]); }
		return true;
	}
	public function getModerators($boardId){ return array_keys(isset($this->mods[$boardId]) ? $this->mods[$boardId] : array()); }
	public function getModeratorMap(){
		$map = array();
		foreach($this->mods as $boardId => $users){ foreach($users as $userId => $_){ $map[$userId][] = $boardId; } }
		return $map;
	}
	public function getBoards(){
		if($this->fail){ return false; }
		$out = array();
		foreach($this->boards as $b){
			$out[] = array($b['boardId'],$b['name'],$b['slug'],$b['description'],$b['mode'],
				$b['postPolicy'],$b['readPolicy'],$b['locked']?1:0,0,0,0);
		}
		return $out;
	}
	public function getPostCounts(){
		$counts = array();
		foreach($this->posts as $p){
			$counts[$p['userId']] = (isset($counts[$p['userId']]) ? $counts[$p['userId']] : 0) + 1;
		}
		return $counts;
	}
	public function addBoard($name,$slug,$d,$m,$pp,$rp,$so){
		if($this->getBoardBySlug($slug)){ return 'duplicate'; }
		return $this->seedBoard($slug, array('name'=>$name,'mode'=>$m,'postPolicy'=>$pp,'readPolicy'=>$rp));
	}
	public function saveBoard($id,$name,$slug,$d,$m,$pp,$rp,$locked,$so){
		foreach($this->boards as $b){ if($b['slug'] === $slug && $b['boardId'] !== $id){ return 'duplicate'; } }
		$this->boards[$id] = array_merge($this->boards[$id], array('name'=>$name,'slug'=>$slug,'mode'=>$m,
			'postPolicy'=>$pp,'readPolicy'=>$rp,'locked'=>(bool)$locked));
		return true;
	}
	public function deleteBoard($id){ unset($this->boards[$id]); return true; }
}

ghoti::$enableBoards = true;
$loader = (new ReflectionClass(ghoti::class))->newInstanceWithoutConstructor();
$loader->loadModules(array('boards'));

function boardsModule($db){
	$module = (new ReflectionClass(boards::class))->newInstanceWithoutConstructor();
	$module->boardsdb = $db;
	return $module;
}
$db = new BoardsDbFake();
$_SESSION['boardsObj'] = boardsModule($db);

//Every reply is an envelope, and a failure explains itself in words.
function boardEnvelope($reply, $label){
	boardCheck(is_array($reply) && array_key_exists('success',$reply) && array_key_exists('data',$reply) && array_key_exists('error',$reply),
		"$label: reply is not a {success,data,error} envelope: ".var_export($reply, true));
	boardCheck(is_bool($reply['success']), "$label: success is not a boolean");
	if($reply['success']){
		boardCheck($reply['error'] === null, "$label: a success carries an error");
	}else{
		boardCheck($reply['data'] === null, "$label: a failure carries data");
		boardCheck(is_array($reply['error']) && !empty($reply['error']['code']) && is_string($reply['error']['message']) && $reply['error']['message'] !== '',
			"$label: a failure has no code/message - the UI would print a bare word");
		boardCheck(!in_array(strtolower($reply['error']['message']), array('false','true','null'), true), "$label: the message is a bare boolean");
	}
	return $reply;
}
function boardFails($reply, $code, $label){
	boardEnvelope($reply, $label);
	boardCheck($reply['success'] === false && $reply['error']['code'] === $code,
		"$label: expected failure '$code', got ".json_encode($reply));
	return $reply;
}

/* ---------------- registration ---------------- */

foreach(array('boardsGetView','boardsAddTopic','boardsAddPost','boardsEditPost','boardsDeletePost',
	'boardsSetTopicFlags','boardsDeleteTopic','boardsGetBoards','boardsAddBoard','boardsSaveBoard',
	'boardsDeleteBoard','boardsGetUserModeration','boardsSetModerator') as $fn){
	boardCheck(ghoti_async_is_registered($fn), "$fn is not callable from the browser");
}
boardCheck(isset($GLOBALS['ghoti_shortcode_handlers']['board']), 'The [board:slug] shortcode is not registered');

/* ---------------- slugs stay inside the shortcode charset ---------------- */

boardCheck(boards_slug('Trip Reports') === 'trip-reports', 'spaces do not fold to hyphens');
boardCheck(boards_slug('Trip_Reports') === 'trip_reports', 'an underscore is a legal slug character');
boardCheck(boards_slug('  General  ') === 'general', 'a slug is not trimmed and lowercased');
boardCheck(boards_slug('--a--b--') === 'a-b', 'repeated separators are not collapsed');
foreach(array('a<b>', 'x" onerror=1', "quote'", 'héllo', '[board:x]') as $hostile){
	boardCheck(preg_match('/^[A-Za-z0-9_.-]*$/', boards_slug($hostile)) === 1,
		"A slug can not be written inside [board:...]: ".boards_slug($hostile));
}

/* ---------------- discussion appears ONLY where it is placed ---------------- */

//The whole point of the module: getPage() must not append discussion to a page.
$pageSource = file_get_contents('ghoti.async.php');
boardCheck(strpos($pageSource, 'displayComments') === false, 'getPage() still appends a comment list to every page');
boardCheck(strpos($pageSource, 'addCommentButton') === false, 'getPage() still appends a comment button to every page');
boardCheck(!is_dir('mod/comments'), 'the comments module is still installed');

//And the tag itself only leaves a container behind for boards.js to fill.
$expanded = ghoti_expand_shortcodes('<p>Before</p>[board:general]<p>After</p>');
boardCheck(strpos($expanded, '[board:') === false, 'the tag survived expansion');
boardCheck(strpos($expanded, 'data-board-slug="general"') !== false, 'the tag did not leave a board container');
boardCheck(strpos($expanded, '<p>Before</p>') === 0, 'the board did not render in place');
//Case and separators fold to one board, as they do for link groups.
foreach(array('[board:General]', '[board:general]', '[board general]') as $tag){
	boardCheck(strpos(ghoti_expand_shortcodes($tag), 'data-board-slug="general"') !== false, "$tag did not resolve");
}
//A slug is attacker-controlled text inside an attribute.
$xss = ghoti_expand_shortcodes('[board:x-"onmouseover=alert(1)]');
boardCheck(strpos($xss, '[board:') !== false, 'a value outside the shortcode charset was expanded');

/* ---------------- reading: policies are enforced per request ---------------- */

$public = $db->seedBoard('general');
$members = $db->seedBoard('lounge', array('readPolicy' => 'users'));
$db->seedTopic($public, 1, 'Hello');

boardTestSignOut();
boardEnvelope(boardsGetView('general'), 'anonymous read of a public board');
boardCheck(boardsGetView('general')['success'] === true, 'a public board is not readable signed out');
boardFails(boardsGetView('lounge'), 'forbidden', 'anonymous read of a members-only board');
boardFails(boardsGetView('nope'), 'not_found', 'read of a board that does not exist');
boardFails(boardsGetView(''), 'invalid', 'read with no board named');

boardTestSignIn(5);
boardCheck(boardsGetView('lounge')['success'] === true, 'a signed-in user cannot read a members-only board');

//A topic id belonging to another board must not be readable through this
//board's slug - that would walk around the members-only read policy.
$memberTopic = $db->seedTopic($members, 1, 'Private');
boardTestSignOut();
boardFails(boardsGetView('general', $memberTopic), 'not_found', 'a topic was readable through another board');

/* ---------------- posting: the refusal is server-side ---------------- */

boardTestSignOut();
boardFails(boardsAddPost('general', 1, 'hi'), 'forbidden', 'anonymous post accepted');
boardFails(boardsAddTopic('general', 'T', 'body'), 'forbidden', 'anonymous topic accepted');

boardTestSignIn(5);
boardCheck(boardsAddTopic('general', 'A topic', 'The body')['success'] === true, 'a signed-in user could not open a topic');

$locked = $db->seedBoard('archive', array('locked' => true));
$lockedTopic = $db->seedTopic($locked, 1, 'Old');
boardFails(boardsAddPost('archive', $lockedTopic, 'hi'), 'forbidden', 'a locked board accepted a post');

$modsOnly = $db->seedBoard('notices', array('postPolicy' => 'mods'));
$noticeTopic = $db->seedTopic($modsOnly, 1, 'Notice');
boardFails(boardsAddPost('notices', $noticeTopic, 'hi'), 'forbidden', 'a moderators-only board accepted a member post');
//Naming the user a moderator of that board is what changes the answer.
$db->setModerator(5, $modsOnly, true);
boardCheck(boardsAddPost('notices', $noticeTopic, 'hi')['success'] === true, 'a moderator could not post on their own board');
//...and only on that board.
boardCheck(boards_can_moderate($modsOnly) === true, 'a named moderator does not moderate their board');
boardCheck(boards_can_moderate($public) === false, 'a moderator of one board moderates another');

$closedTopic = $db->seedTopic($public, 1, 'Closed', true);
boardFails(boardsAddPost('general', $closedTopic, 'hi'), 'forbidden', 'a locked topic accepted a reply');

//An empty post is refused with a message, not stored as a blank row.
boardEnvelope(boardsAddPost('general', 1, ''), 'empty post');
boardCheck(boardsAddPost('general', 1, '')['success'] === false, 'an empty post was stored');
boardFails(boardsAddPost('general', 1, str_repeat('x', BOARD_POST_MAX + 10)), 'invalid', 'an oversized post was stored');

/* ---------------- editing and deleting someone else's post ---------------- */

$mine = $db->seedPost($public, 1, 5, 'mine');
$theirs = $db->seedPost($public, 1, 99, 'theirs');

boardTestSignIn(5);
boardCheck(boardsEditPost($mine, 'edited')['success'] === true, 'an author could not edit their own post');
boardFails(boardsEditPost($theirs, 'hacked'), 'forbidden', "a member edited another member's post");
boardFails(boardsDeletePost($theirs), 'forbidden', "a member deleted another member's post");
boardCheck($db->getPost($theirs)['body'] === 'theirs', "another member's post was changed anyway");

//A moderator of that board may; an admin may anywhere.
$db->setModerator(5, $public, true);
boardCheck(boardsDeletePost($theirs)['success'] === true, 'a moderator could not remove a post on their board');
$another = $db->seedPost($public, 1, 99, 'theirs again');
boardTestSignIn(7, true);
boardCheck(boardsDeletePost($another)['success'] === true, 'an admin could not remove a post');

/* ---------------- moderation of topics ---------------- */

$topic = $db->seedTopic($public, 99, 'Noisy');
boardTestSignIn(50); // an ordinary member, moderating nothing
boardFails(boardsSetTopicFlags($topic, true, false), 'forbidden', 'a member locked a topic');
boardFails(boardsDeleteTopic($topic), 'forbidden', 'a member deleted a topic');
boardTestSignIn(7, true);
boardCheck(boardsSetTopicFlags($topic, true, true)['success'] === true, 'an admin could not lock a topic');
boardCheck($db->getTopic($topic)['locked'] === true && $db->getTopic($topic)['sticky'] === true, 'the flags were not applied');
boardCheck(boardsDeleteTopic($topic)['success'] === true, 'an admin could not delete a topic');
boardCheck($db->getTopic($topic) === null, 'the topic survived deletion');

/* ---------------- the admin screen is admin-only ---------------- */

boardTestSignIn(5); // signed in, but not an admin
foreach(array(
	'boardsGetBoards' => boardsGetBoards(),
	'boardsAddBoard' => boardsAddBoard('X','x','','board','users','public'),
	'boardsSaveBoard' => boardsSaveBoard($public,'X','x','','board','users','public',0),
	'boardsDeleteBoard' => boardsDeleteBoard($public),
	'boardsGetUserModeration' => boardsGetUserModeration(5),
	'boardsSetModerator' => boardsSetModerator(5, $public, true),
) as $name => $reply){
	boardFails($reply, 'forbidden', "$name is callable by a non-admin");
}

boardTestSignIn(7, true);
boardCheck(boardsGetBoards()['success'] === true, 'an admin cannot list the boards');
boardFails(boardsAddBoard('Dupe','general','','board','users','public'), 'duplicate', 'two boards took the same shortcode');
boardFails(boardsSaveBoard(99999,'X','x','','board','users','public',0), 'not_found', 'saving a deleted board reported success');
boardFails(boardsDeleteBoard(99999), 'not_found', 'deleting a board that is gone reported success');
//A board named only in characters the shortcode cannot carry is unreachable,
//so it is refused rather than created.
boardFails(boardsAddBoard('!!!', '!!!', '', 'board', 'users', 'public'), 'invalid', 'a board was created with an unreachable shortcode');
//An unknown mode or policy falls back to the safe default rather than being stored.
$created = boardsAddBoard('Modes','modes','','nonsense','nonsense','nonsense');
boardCheck($created['success'] === true, 'the board was not created');
boardCheck($db->getBoardById($created['data']['boardId'])['mode'] === 'board', 'an unknown mode was stored');
boardCheck($db->getBoardById($created['data']['boardId'])['postPolicy'] === 'users', 'an unknown post policy was stored');
boardCheck($db->getBoardById($created['data']['boardId'])['readPolicy'] === 'public', 'an unknown read policy was stored');

//Switching a board with several topics to a comment section would leave all but
//the oldest unreachable, so it is refused with an explanation.
boardTestSignIn(7, true);
$busy = $db->seedBoard('busy');
$db->seedTopic($busy, 1, 'One');
$db->seedTopic($busy, 1, 'Two');
$refused = boardFails(boardsSaveBoard($busy,'Busy','busy','','comments','users','public',0), 'invalid',
	'a board with several topics was switched to a comment section');
boardCheck(strpos($refused['error']['message'], '2 topics') !== false, 'the refusal does not say how many topics are in the way');
boardCheck($db->getBoardById($busy)['mode'] === 'board', 'the mode was changed anyway');
//One topic is the shape a comment section has, so that switch is allowed.
$quiet = $db->seedBoard('quiet');
$db->seedTopic($quiet, 1, 'Only');
boardCheck(boardsSaveBoard($quiet,'Quiet','quiet','','comments','users','public',0)['success'] === true,
	'a board with one topic could not become a comment section');

/* ---------------- comment-section mode ---------------- */

//A comments board is the same renderer with the topic list turned off: it opens
//straight into one thread, and makes that thread on the first comment.
$comments = $db->seedBoard('page-talk', array('mode' => 'comments'));
boardTestSignOut();
$view = boardsGetView('page-talk');
boardCheck($view['success'] === true, 'a comment section is not readable');
boardCheck($view['data']['view'] === 'thread', 'a comment section showed a topic list');
boardCheck($view['data']['posts'] === array() && $view['data']['total'] === 0, 'an unused comment section is not empty');
boardCheck($view['data']['canPost'] === false, 'a signed-out visitor was offered a comment box');

boardTestSignIn(5);
$posted = boardsAddPost('page-talk', 0, 'First comment');
boardCheck($posted['success'] === true, 'the first comment could not be posted');
boardCheck($posted['data']['topicId'] > 0, 'the first comment did not create the implicit thread');
//The second comment joins the same thread rather than starting another.
$second = boardsAddPost('page-talk', 0, 'Second comment');
boardCheck($second['data']['topicId'] === $posted['data']['topicId'], 'a second comment split the thread');
boardCheck(boardsGetView('page-talk')['data']['total'] === 2, 'the comment section does not show both comments');
//Topics are a message-board idea; a comment section refuses them outright.
boardFails(boardsAddTopic('page-talk', 'T', 'b'), 'invalid', 'a comment section accepted a topic');

//A board switched from "message board" to "comment section" in the admin screen
//still has the several topics it collected as a board. Reading and posting must
//agree on WHICH of them is the implicit thread - they once disagreed (the read
//took the most recently posted, the write took the oldest), so a comment saved
//successfully and then never appeared.
$switched = $db->seedBoard('was-a-board', array('mode' => 'comments'));
$db->seedTopic($switched, 1, 'First');
$db->seedTopic($switched, 1, 'Second');
$mixed = boardsAddPost('was-a-board', 0, 'Where did this go?');
boardCheck($mixed['success'] === true, 'the comment could not be posted');
$readBack = boardsGetView('was-a-board');
boardCheck($readBack['success'] === true, 'the switched board could not be read');
$bodies = array_map(function($p){ return $p['body']; }, $readBack['data']['posts']);
boardCheck(in_array('Where did this go?', $bodies, true),
	'a comment was saved to one thread and read back from another');

/* ---------------- a database outage is not an error page ---------------- */

$db->fail = true;
boardFails(boardsGetView('general'), 'db_error', 'a database outage did not report itself');
$db->fail = false;

//A comment section reads its thread down a different branch from a message
//board's, and that branch used to wrap the failure up as a success - so the
//browser was handed {success:true} with no posts in it and drew an empty
//comment section instead of saying the board could not be loaded.
//
//This needs the board itself to stay readable and only the POSTS to fail: a
//total outage is refused earlier, before either branch is reached, which is why
//the flag below is narrower than $fail.
$db->failPosts = true;
boardFails(boardsGetView('page-talk'), 'db_error', 'a comment section reported a failed post read as success');
boardFails(boardsGetView('general', $closedTopic), 'db_error', 'a topic view reported a failed post read as success');
$db->failPosts = false;
$db->fail = true;
boardCheck(ghoti_expand_shortcodes('[board:general]') !== '', 'the shortcode should still place its container');
$db->fail = false;

/* ---------------- the module degrades when it is switched off ---------------- */

unset($_SESSION['boardsObj']);
boardCheck(ghoti_expand_shortcodes('[board:general]') === '', 'the shortcode did not degrade to nothing without the module');
boardFails(boardsGetView('general'), 'db_error', 'an endpoint did not fail closed without the module');
boardCheck(boards_user_post_counts() === array(), 'post counts did not degrade to empty');
boardCheck(boards_user_moderation_summary() === array(), 'the moderator summary did not degrade to empty');

/* ---------------- the enable switch is wired up ---------------- */

boardCheck(array_key_exists('enableBoards', ghoti::currentSettings()), 'enableBoards is missing from the settings schema');
$header = file_get_contents('ghoti.header.php');
boardCheck(strpos($header, "if(ghoti::\$enableBoards){") !== false, 'board assets are not behind the enable switch');
boardCheck(strpos(file_get_contents('index.php'), 'enableBoards') !== false, 'index.php does not bootstrap the module');
ghoti::$enableBoards = false;
boardCheck(!in_array('boards', ghoti::enabledModules(), true), 'the module loads while switched off');
ghoti::$enableBoards = true;
boardCheck(in_array('boards', ghoti::enabledModules(), true), 'the module does not load while switched on');

echo "PASS: $checks boards assertions\n";
