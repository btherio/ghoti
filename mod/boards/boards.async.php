<?php
/*
 * boards.async.php - boards module endpoints and the [board:slug] shortcode.
 *
 * This module replaces the old comments module. The difference that shapes
 * everything below: commenting was an implicit property of every page (the
 * page id came from the session, and getPage() appended a comment list to
 * whatever you were looking at), whereas a board is a THING YOU PLACE. You put
 * [board:support] where you want it, and the same board can appear on more
 * than one page. So no endpoint here reads $_SESSION['pageId']; the board is
 * always named explicitly by the caller and re-resolved server-side.
 *
 * Every endpoint answers one envelope, as the links module does:
 *   { "success": true,  "data": {...}, "error": null }
 *   { "success": false, "data": null,  "error": { "code": "...", "message": "..." } }
 * Error codes: forbidden, invalid, duplicate, not_found, locked, db_error.
 *
 * Rendering happens in boards.js, not here. getPage() runs stripslashes() over
 * the whole expanded page, which would eat backslashes out of post bodies, and
 * page bodies are swapped in over async - so a board that painted itself
 * server-side could not paginate without reloading the page it sits on.
 */

const BOARD_NAME_MAX  = 64;
const BOARD_SLUG_MAX  = 64;
const BOARD_DESC_MAX  = 255;
const BOARD_TITLE_MAX = 160;
const BOARD_POST_MAX  = 8000;
const BOARD_PAGE_SIZE = 25;
//Reply notices are sent inside the request that saved the post, so a thread
//with a crowd of subscribers is capped rather than left to stall the poster.
const BOARD_NOTIFY_MAX = 25;
const BOARD_NOTIFY_EXCERPT = 600;

/* ---------------------------------------------------------------- *
 *  Pure helpers (no session, no database)
 * ---------------------------------------------------------------- */

function boards_ok($data){
	return array('success' => true, 'data' => $data, 'error' => null);
}

function boards_fail($code, $message){
	return array('success' => false, 'data' => null, 'error' => array('code' => $code, 'message' => $message));
}

/*
 * The name a board answers to inside [board:...]. The shortcode pattern in
 * ghoti_expand_shortcodes() only captures [A-Za-z0-9_.-]+, so a slug that falls
 * outside that charset could never be written in a page; producing one here
 * would make a board unreachable. Spaces and underscores fold to "-", as they
 * do for link groups, so "Trip Reports" is [board:trip-reports].
 */
function boards_slug($value){
	$slug = strtolower(trim((string)$value));
	$slug = preg_replace('/[^a-z0-9_.-]+/', '-', $slug);
	$slug = preg_replace('/-+/', '-', $slug);
	return trim($slug, '-');
}

/*
 * One post body, cleaned and length-checked.
 *
 * multilineText() TRUNCATES at its max, so passing BOARD_POST_MAX to it would
 * quietly cut a long post off mid-sentence and report success. Max 0 disables
 * that, and the length is checked here so an over-long post is refused with a
 * message instead. Throws Exception with text that is safe to show.
 */
function boards_clean_body($body, $label = "post"){
	$clean = ghoti_validate()->multilineText($body, 0, true, $label);
	if(mb_strlen($clean) > BOARD_POST_MAX){
		throw new Exception("A ".$label." can be at most ".BOARD_POST_MAX." characters; yours is ".mb_strlen($clean).".");
	}
	return $clean;
}

function boards_valid_mode($mode){
	return in_array($mode, array('board','comments'), true) ? $mode : 'board';
}

function boards_valid_post_policy($policy){
	return in_array($policy, array('users','mods'), true) ? $policy : 'users';
}

function boards_valid_read_policy($policy){
	return in_array($policy, array('public','users'), true) ? $policy : 'public';
}

function boards_db(){
	return isset($_SESSION['boardsObj']) ? $_SESSION['boardsObj']->boardsdb : null;
}

/* ---------------------------------------------------------------- *
 *  Authorization
 *
 *  All of it is server-side and fails closed. The comments module it replaces
 *  hid its delete icon from non-owners and called that access control; the
 *  endpoint itself was callable by anyone who could POST.
 * ---------------------------------------------------------------- */

//Admins moderate everything; a moderator moderates the boards they are named on.
function boards_can_moderate($boardId){
	$uid = ghoti_current_user_id();
	if($uid <= 0){ return false; }
	if(function_exists('isAdmin') && isAdmin($uid)){ return true; }
	$db = boards_db();
	if($db === null){ return false; }
	return $db->isModerator($uid, (int)$boardId) === true;
}

function boards_can_read($board){
	return $board['readPolicy'] !== 'users' || ghoti_require_login();
}

//Why posting is refused, or '' when it is allowed. The board's own lock and a
//moderators-only policy are both overridden for people who moderate it.
function boards_post_refusal($board, $topic = null){
	if(!ghoti_require_login()){
		return "You must be signed in to post.";
	}
	$moderates = boards_can_moderate($board['boardId']);
	if($board['postPolicy'] === 'mods' && !$moderates){
		return "Only moderators can post on this board.";
	}
	if($board['locked'] && !$moderates){
		return "This board is locked.";
	}
	if($topic !== null && $topic['locked'] && !$moderates){
		return "This topic is locked.";
	}
	return '';
}

//A post may be edited or removed by its author or by anyone who moderates the
//board it is on.
function boards_can_touch_post($post){
	$uid = ghoti_current_user_id();
	if($uid <= 0){ return false; }
	return (int)$post['userId'] === $uid || boards_can_moderate($post['boardId']);
}

/* ---------------------------------------------------------------- *
 *  Shaping rows for the browser
 * ---------------------------------------------------------------- */

//Post bodies are stored as plain text and escaped in boards.js. Newlines are
//kept; everything else about how a post looks is the renderer's business.
function boards_shape_post($row){
	return array(
		'postId'      => (int)$row[0],
		'userId'      => (int)$row[1],
		'body'        => (string)$row[2],
		'createdAt'   => (int)$row[3],
		'editedAt'    => (int)$row[4],
		'userName'    => (string)$row[5],
		'authorAdmin' => (int)$row[6] === 1,
		'authorPosts' => (int)$row[7],
		'mine'        => (int)$row[1] === ghoti_current_user_id() && ghoti_current_user_id() > 0,
	);
}

function boards_shape_topic($row){
	return array(
		'topicId'   => (int)$row[0],
		'title'     => (string)$row[1],
		'locked'    => (int)$row[2] === 1,
		'sticky'    => (int)$row[3] === 1,
		'createdAt' => (int)$row[4],
		'lastPostAt'=> (int)$row[5],
		'userId'    => (int)$row[6],
		'userName'  => (string)$row[7],
		'postCount' => (int)$row[8],
	);
}

/* ---------------------------------------------------------------- *
 *  Public endpoints: reading a board
 * ---------------------------------------------------------------- */

/*
 * Everything a placed [board:slug] needs to paint itself.
 *
 * $topicId 0 means "the landing view": the topic list for a 'board', or the
 * single implicit thread for a 'comments' board. A 'comments' board that has
 * never been posted to has no topic row yet, which is not an error - it
 * answers with an empty post list.
 */
function boardsGetView($slug, $topicId = 0, $page = 1){
	$db = boards_db();
	if($db === null){ return boards_fail('db_error', "Boards are not available."); }
	$slug = boards_slug($slug);
	if($slug === ''){ return boards_fail('invalid', "No board was named."); }

	$board = $db->getBoardBySlug($slug);
	if($board === false){ return boards_fail('db_error', "Could not load the board. Try again in a moment."); }
	if($board === null){ return boards_fail('not_found', "That board does not exist."); }
	if(!boards_can_read($board)){
		return boards_fail('forbidden', "You must be signed in to read this board.");
	}

	$page = max(1, (int)$page);
	$offset = ($page - 1) * BOARD_PAGE_SIZE;
	$moderates = boards_can_moderate($board['boardId']);
	$refusal = boards_post_refusal($board);

	$view = array(
		'board'      => $board,
		'moderates'  => $moderates,
		'canPost'    => $refusal === '',
		'postRefusal'=> $refusal,
		'signedIn'   => ghoti_require_login(),
		'page'       => $page,
		'pageSize'   => BOARD_PAGE_SIZE,
	);

	//A comments board never shows a thread list; it opens straight into its one
	//thread, which may not exist yet.
	if($board['mode'] === 'comments'){
		//getDefaultTopicId() is the same call boardsAddPost() makes, so a comment
		//is always read back from the thread it was written to.
		$topicId = $db->getDefaultTopicId($board['boardId']);
		if($topicId === false){ return boards_fail('db_error', "Could not load the board. Try again in a moment."); }
		$view['view'] = 'thread';
		$view['topic'] = null;
		if($topicId === 0){
			$view['posts'] = array();
			$view['total'] = 0;
			return boards_ok($view);
		}
		//boards_thread_view() may answer with a failure envelope of its own (a
		//database outage), which must not be wrapped up as a success.
		return boards_ok_or_fail(boards_thread_view($db, $board, $topicId, $page, $offset, $view));
	}

	$topicId = (int)$topicId;
	if($topicId > 0){
		return boards_ok_or_fail(boards_thread_view($db, $board, $topicId, $page, $offset, $view));
	}

	$topics = $db->getTopics($board['boardId'], BOARD_PAGE_SIZE, $offset);
	$total = $db->countTopics($board['boardId']);
	if($topics === false || $total === false){
		return boards_fail('db_error', "Could not load the board. Try again in a moment.");
	}
	$view['view'] = 'topics';
	$view['topics'] = array_map('boards_shape_topic', $topics);
	$view['total'] = (int)$total;
	return boards_ok($view);
}

//boards_thread_view() returns either a view array or a ready-made failure
//envelope; this unwraps that without every caller repeating the check.
function boards_ok_or_fail($result){
	if(isset($result['success'])){ return $result; }
	return boards_ok($result);
}

function boards_thread_view($db, $board, $topicId, $page, $offset, $view){
	$topic = $db->getTopic($topicId);
	if($topic === false){ return boards_fail('db_error', "Could not load the topic. Try again in a moment."); }
	//A topic id from another board must not be readable through this board's
	//slug - that would be a way around a 'users'-only read policy.
	if($topic === null || $topic['boardId'] !== $board['boardId']){
		return boards_fail('not_found', "That topic no longer exists.");
	}
	$posts = $db->getPosts($topicId, BOARD_PAGE_SIZE, $offset);
	$total = $db->countPosts($topicId);
	if($posts === false || $total === false){
		return boards_fail('db_error', "Could not load the topic. Try again in a moment.");
	}
	$view['view'] = 'thread';
	$view['topic'] = $topic;
	$view['posts'] = array_map('boards_shape_post', $posts);
	$view['total'] = (int)$total;
	$view['canPost'] = boards_post_refusal($board, $topic) === '';
	$view['postRefusal'] = boards_post_refusal($board, $topic);
	return $view;
}

/* ---------------------------------------------------------------- *
 *  Posting
 * ---------------------------------------------------------------- */

//Shared by every write endpoint: resolve the board, or the envelope explaining
//why not. Returns array($board, null) or array(null, $failure).
function boards_resolve($slug){
	$db = boards_db();
	if($db === null){ return array(null, boards_fail('db_error', "Boards are not available.")); }
	$board = $db->getBoardBySlug(boards_slug($slug));
	if($board === false){ return array(null, boards_fail('db_error', "Could not load the board. Try again in a moment.")); }
	if($board === null){ return array(null, boards_fail('not_found', "That board does not exist.")); }
	if(!boards_can_read($board)){ return array(null, boards_fail('forbidden', "You must be signed in to read this board.")); }
	return array($board, null);
}

function boardsAddTopic($slug, $title, $body){
	if(!ghoti_require_login()){ return boards_fail('forbidden', "You must be signed in to post."); }
	list($board, $failure) = boards_resolve($slug);
	if($failure !== null){ return $failure; }
	if($board['mode'] === 'comments'){
		return boards_fail('invalid', "This board takes comments, not topics.");
	}
	$refusal = boards_post_refusal($board);
	if($refusal !== ''){ return boards_fail('forbidden', $refusal); }

	$v = ghoti_validate();
	try{
		$title = $v->text($title, 0, true, "topic title");
		if(mb_strlen($title) > BOARD_TITLE_MAX){
			throw new Exception("A topic title can be at most ".BOARD_TITLE_MAX." characters.");
		}
		$body = boards_clean_body($body);
	}catch(Exception $e){
		return boards_fail('invalid', $e->getMessage());
	}

	$db = boards_db();
	$userId = ghoti_current_user_id();
	$topicId = $db->addTopic($board['boardId'], $userId, $title);
	if($topicId === false){ return boards_fail('db_error', "The topic could not be posted. Try again in a moment."); }
	if($db->addPost($board['boardId'], $topicId, $userId, $body) === false){
		//A topic with no post is an empty thread nobody can read; take it back out.
		$db->deleteTopic($topicId);
		return boards_fail('db_error', "The topic could not be posted. Try again in a moment.");
	}
	ghoti::logInfo("boards.async.php:boardsAddTopic", "Topic $topicId opened on ".$board['slug']." by user $userId from ".ghoti_remote_addr().".");
	return boards_ok(array('topicId' => $topicId));
}

function boardsAddPost($slug, $topicId, $body){
	if(!ghoti_require_login()){ return boards_fail('forbidden', "You must be signed in to post."); }
	list($board, $failure) = boards_resolve($slug);
	if($failure !== null){ return $failure; }
	$db = boards_db();
	$userId = ghoti_current_user_id();

	$topic = null;
	$topicId = (int)$topicId;
	if($board['mode'] === 'comments'){
		//The implicit thread is made on demand, so the first comment on a page
		//does not need an administrator to have created a topic first.
		$refusal = boards_post_refusal($board);
		if($refusal !== ''){ return boards_fail('forbidden', $refusal); }
		$topicId = $db->getOrCreateDefaultTopic($board['boardId'], $userId);
		if($topicId === false){ return boards_fail('db_error', "The comment could not be posted. Try again in a moment."); }
	}else{
		$topic = $db->getTopic($topicId);
		if($topic === false){ return boards_fail('db_error', "Could not load the topic. Try again in a moment."); }
		if($topic === null || $topic['boardId'] !== $board['boardId']){
			return boards_fail('not_found', "That topic no longer exists.");
		}
		$refusal = boards_post_refusal($board, $topic);
		if($refusal !== ''){ return boards_fail('forbidden', $refusal); }
	}

	try{
		$body = boards_clean_body($body);
	}catch(Exception $e){
		return boards_fail('invalid', $e->getMessage());
	}

	$postId = $db->addPost($board['boardId'], $topicId, $userId, $body);
	if($postId === false){ return boards_fail('db_error', "The post could not be saved. Try again in a moment."); }
	ghoti::logInfo("boards.async.php:boardsAddPost", "Post $postId on ".$board['slug']." by user $userId from ".ghoti_remote_addr().".");
	//After the post is safely saved, and never able to change the answer: a
	//mail server that is down must not turn a successful post into an error.
	try{
		boards_notify_reply($_SESSION['mailObj'] ?? null, $db, $board, $topic, $topicId, $userId, $body);
	}catch(Throwable $e){
		ghoti::logException("boards.async.php:boardsAddPost", $e, "reply notification");
	}
	return boards_ok(array('postId' => $postId, 'topicId' => $topicId));
}

/* ---------------------------------------------------------------- *
 *  Reply notifications
 *
 *  Opt-in, per account (Your account -> Notifications). A "reply" is any new
 *  post in a thread you have posted in; in a comment section that is the one
 *  implicit thread, so earlier commenters hear about later comments.
 * ---------------------------------------------------------------- */

//The notice for one new post, as array(subject, plain-text message). Pure, so
//tests can check the wording without a database or a mailer.
function boards_reply_notice($siteTitle, $board, $topic, $authorName, $body){
	$where = $board['mode'] === 'comments' || $topic === null || $topic['title'] === ''
		? 'the comments on "'.$board['name'].'"'
		: '"'.$topic['title'].'" on '.$board['name'];
	$author = $authorName !== '' ? $authorName : 'Someone';
	$excerpt = trim((string)$body);
	if(mb_strlen($excerpt) > BOARD_NOTIFY_EXCERPT){
		$excerpt = rtrim(mb_substr($excerpt, 0, BOARD_NOTIFY_EXCERPT))."\u{2026}";
	}
	$subject = $author.' replied in '.$where;
	if(mb_strlen($subject) > 150){ $subject = mb_substr($subject, 0, 149)."\u{2026}"; }
	$message = $author.' posted in '.$where.' on '.$siteTitle.', a thread you have posted in:'."\n\n"
		.$excerpt."\n\n"
		.'Visit the site to read the whole thread and reply.';
	return array($subject, $message);
}

/*
 * Mail every opted-in participant of the thread except the author. The mailer
 * is passed in so a test can record instead of send. Returns the number of
 * notices sent; failures are logged per address and do not stop the rest.
 */
function boards_notify_reply($mailer, $db, $board, $topic, $topicId, $authorId, $body){
	if($db === null || !function_exists('ghoti_mail_is_enabled') || !ghoti_mail_is_enabled($mailer)){ return 0; }
	$subscribers = $db->getReplySubscribers($topicId, $authorId, BOARD_NOTIFY_MAX);
	if(!$subscribers){ return 0; }
	//A members-only board's post only goes to people who could read it anyway -
	//every subscriber has an account and posted there - so no extra check.
	list($subject, $message) = boards_reply_notice(ghoti::$siteTitle, $board, $topic, $db->getUserContact($authorId)['userName'], $body);
	$sent = 0;
	$seen = array();
	foreach($subscribers as $row){
		$email = trim((string)$row[2]);
		if(!filter_var($email, FILTER_VALIDATE_EMAIL) || isset($seen[strtolower($email)])){ continue; }
		$seen[strtolower($email)] = true;
		$result = ghoti_mail_send_themed($mailer, $email, (string)$row[1], $subject, $message, 'boards');
		if($result === true){ $sent++; continue; }
		ghoti::logWarn("boards.async.php:boards_notify_reply", "Reply notice for topic $topicId to user ".(int)$row[0]." failed: ".(is_string($result) ? $result : 'unknown error'));
	}
	if($sent > 0){
		ghoti::logInfo("boards.async.php:boards_notify_reply", "Sent $sent reply notice(s) for topic $topicId.");
	}
	return $sent;
}

//The signed-in account's notification choices, for the Notifications dialog.
function boardsGetNotifyPrefs(){
	if(!ghoti_require_login()){ return boards_fail('forbidden', "You must be signed in."); }
	$db = boards_db();
	if($db === null){ return boards_fail('db_error', "Boards are not available."); }
	$replies = $db->getNotifyReplies(ghoti_current_user_id());
	if($replies === null){ return boards_fail('db_error', "Could not load your notification settings. Try again in a moment."); }
	$email = $db->getUserContact(ghoti_current_user_id())['email'];
	return boards_ok(array(
		'replies'  => $replies,
		'hasEmail' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
		'mailOn'   => function_exists('ghoti_mail_is_enabled') && ghoti_mail_is_enabled($_SESSION['mailObj'] ?? null),
	));
}

function boardsSaveNotifyPrefs($replies){
	if(!ghoti_require_login()){ return boards_fail('forbidden', "You must be signed in."); }
	$db = boards_db();
	if($db === null){ return boards_fail('db_error', "Boards are not available."); }
	$on = (bool)ghoti_validate()->boolInt($replies);
	$userId = ghoti_current_user_id();
	if(!$db->setNotifyReplies($userId, $on)){
		return boards_fail('db_error', "Could not save your notification settings. Try again in a moment.");
	}
	ghoti::logInfo("boards.async.php:boardsSaveNotifyPrefs", "User $userId turned reply notifications ".($on ? 'on' : 'off').".");
	return boards_ok(array('replies' => $on));
}

function boardsEditPost($postId, $body){
	if(!ghoti_require_login()){ return boards_fail('forbidden', "You must be signed in."); }
	$db = boards_db();
	if($db === null){ return boards_fail('db_error', "Boards are not available."); }
	try{
		$postId = ghoti_validate()->id($postId, "post id");
		$body = boards_clean_body($body);
	}catch(Exception $e){
		return boards_fail('invalid', $e->getMessage());
	}
	$post = $db->getPost($postId);
	if($post === false){ return boards_fail('db_error', "Could not load the post. Try again in a moment."); }
	if($post === null){ return boards_fail('not_found', "That post no longer exists."); }
	if(!boards_can_touch_post($post)){
		ghoti::logWarn("boards.async.php:boardsEditPost", "denied edit of post $postId to user ".ghoti_current_user_id()." from ".ghoti_remote_addr());
		return boards_fail('forbidden', "You can only edit your own posts.");
	}
	if($db->editPost($postId, $body) === false){
		return boards_fail('db_error', "The post could not be saved. Try again in a moment.");
	}
	//A moderator can rewrite someone else's words, so every edit is on record
	//the same way a delete is.
	ghoti::logInfo("boards.async.php:boardsEditPost", "Post $postId (author ".$post['userId'].") edited by user ".ghoti_current_user_id()." from ".ghoti_remote_addr().".");
	return boards_ok(array('postId' => $postId));
}

function boardsDeletePost($postId){
	if(!ghoti_require_login()){ return boards_fail('forbidden', "You must be signed in."); }
	$db = boards_db();
	if($db === null){ return boards_fail('db_error', "Boards are not available."); }
	try{
		$postId = ghoti_validate()->id($postId, "post id");
	}catch(Exception $e){
		return boards_fail('invalid', $e->getMessage());
	}
	$post = $db->getPost($postId);
	if($post === false){ return boards_fail('db_error', "Could not delete the post. Try again in a moment."); }
	if($post === null){ return boards_fail('not_found', "That post no longer exists."); }
	if(!boards_can_touch_post($post)){
		ghoti::logWarn("boards.async.php:boardsDeletePost", "denied delete of post $postId to user ".ghoti_current_user_id()." from ".ghoti_remote_addr());
		return boards_fail('forbidden', "You can only delete your own posts.");
	}
	if($db->deletePost($postId) === false){
		return boards_fail('db_error', "Could not delete the post. Try again in a moment.");
	}
	//On a threaded board the opening post going means the thread is gone; an
	//empty topic would otherwise sit in the list forever. A comments board keeps
	//its implicit thread, which is what later comments attach to.
	$board = $db->getBoardById($post['boardId']);
	$topicGone = false;
	if(is_array($board) && $board['mode'] !== 'comments' && $db->countPosts($post['topicId']) === 0){
		$db->deleteTopic($post['topicId']);
		$topicGone = true;
	}
	ghoti::logInfo("boards.async.php:boardsDeletePost", "Post $postId deleted by user ".ghoti_current_user_id()." from ".ghoti_remote_addr().".");
	return boards_ok(array('postId' => $postId, 'topicDeleted' => $topicGone));
}

/* ---------------------------------------------------------------- *
 *  Moderation
 * ---------------------------------------------------------------- */

function boardsSetTopicFlags($topicId, $locked, $sticky){
	if(!ghoti_require_login()){ return boards_fail('forbidden', "You must be signed in."); }
	$db = boards_db();
	if($db === null){ return boards_fail('db_error', "Boards are not available."); }
	try{
		$topicId = ghoti_validate()->id($topicId, "topic id");
	}catch(Exception $e){
		return boards_fail('invalid', $e->getMessage());
	}
	$topic = $db->getTopic($topicId);
	if($topic === false){ return boards_fail('db_error', "Could not load the topic. Try again in a moment."); }
	if($topic === null){ return boards_fail('not_found', "That topic no longer exists."); }
	if(!boards_can_moderate($topic['boardId'])){
		ghoti::logWarn("boards.async.php:boardsSetTopicFlags", "denied moderation of topic $topicId to user ".ghoti_current_user_id()." from ".ghoti_remote_addr());
		return boards_fail('forbidden', "You do not moderate this board.");
	}
	$locked = !empty($locked);
	$sticky = !empty($sticky);
	if($db->setTopicFlags($topicId, $locked, $sticky) === false){
		return boards_fail('db_error', "The topic could not be updated. Try again in a moment.");
	}
	ghoti::logInfo("boards.async.php:boardsSetTopicFlags", "Topic $topicId locked=".($locked?1:0)." sticky=".($sticky?1:0)." by user ".ghoti_current_user_id().".");
	return boards_ok(array('topicId' => $topicId, 'locked' => $locked, 'sticky' => $sticky));
}

function boardsDeleteTopic($topicId){
	if(!ghoti_require_login()){ return boards_fail('forbidden', "You must be signed in."); }
	$db = boards_db();
	if($db === null){ return boards_fail('db_error', "Boards are not available."); }
	try{
		$topicId = ghoti_validate()->id($topicId, "topic id");
	}catch(Exception $e){
		return boards_fail('invalid', $e->getMessage());
	}
	$topic = $db->getTopic($topicId);
	if($topic === false){ return boards_fail('db_error', "Could not delete the topic. Try again in a moment."); }
	if($topic === null){ return boards_fail('not_found', "That topic no longer exists."); }
	if(!boards_can_moderate($topic['boardId'])){
		ghoti::logWarn("boards.async.php:boardsDeleteTopic", "denied delete of topic $topicId to user ".ghoti_current_user_id()." from ".ghoti_remote_addr());
		return boards_fail('forbidden', "You do not moderate this board.");
	}
	if($db->deleteTopic($topicId) === false){
		return boards_fail('db_error', "Could not delete the topic. Try again in a moment.");
	}
	ghoti::logInfo("boards.async.php:boardsDeleteTopic", "Topic $topicId and its posts deleted by user ".ghoti_current_user_id()." from ".ghoti_remote_addr().".");
	return boards_ok(array('topicId' => $topicId));
}

/* ---------------------------------------------------------------- *
 *  Admin: the Boards screen
 * ---------------------------------------------------------------- */

function boardsGetBoards(){
	if(!ghoti_require_admin()){ return boards_fail('forbidden', "Admin access required."); }
	$db = boards_db();
	if($db === null){ return boards_fail('db_error', "Boards are not available."); }
	$rows = $db->getBoards();
	if($rows === false){ return boards_fail('db_error', "Could not load the boards."); }
	$boards = array();
	foreach($rows as $row){
		$boards[] = array(
			'boardId'     => (int)$row[0],
			'name'        => (string)$row[1],
			'slug'        => (string)$row[2],
			'description' => (string)$row[3],
			'mode'        => (string)$row[4],
			'postPolicy'  => (string)$row[5],
			'readPolicy'  => (string)$row[6],
			'locked'      => (int)$row[7] === 1,
			'sortOrder'   => (int)$row[8],
			'topicCount'  => (int)$row[9],
			'postCount'   => (int)$row[10],
		);
	}
	return boards_ok(array('boards' => $boards));
}

//Validate an admin's board settings. Returns the clean values or throws with a
//message that is safe to show.
function boards_prepare($name, $slug, $description, $mode, $postPolicy, $readPolicy){
	$v = ghoti_validate();
	$name = $v->text($name, 0, true, "board name");
	if(mb_strlen($name) > BOARD_NAME_MAX){
		throw new Exception("A board name can be at most ".BOARD_NAME_MAX." characters.");
	}
	//A blank slug is named after the board, which is what an admin expects when
	//they leave the field alone.
	$slug = boards_slug($slug !== '' ? $slug : $name);
	if($slug === ''){
		throw new Exception("Give the board a shortcode name using letters or numbers.");
	}
	if(mb_strlen($slug) > BOARD_SLUG_MAX){
		throw new Exception("A board shortcode can be at most ".BOARD_SLUG_MAX." characters.");
	}
	$description = $v->text($description, 0, false, "description");
	if(mb_strlen($description) > BOARD_DESC_MAX){
		throw new Exception("A description can be at most ".BOARD_DESC_MAX." characters.");
	}
	return array($name, $slug, $description, boards_valid_mode($mode), boards_valid_post_policy($postPolicy), boards_valid_read_policy($readPolicy));
}

function boardsAddBoard($name, $slug, $description, $mode, $postPolicy, $readPolicy, $sortOrder = 0){
	if(!ghoti_require_admin()){ return boards_fail('forbidden', "Admin access required."); }
	$db = boards_db();
	if($db === null){ return boards_fail('db_error', "Boards are not available."); }
	try{
		list($name, $slug, $description, $mode, $postPolicy, $readPolicy) = boards_prepare($name, $slug, $description, $mode, $postPolicy, $readPolicy);
	}catch(Exception $e){
		return boards_fail('invalid', $e->getMessage());
	}
	$result = $db->addBoard($name, $slug, $description, $mode, $postPolicy, $readPolicy, (int)$sortOrder);
	if($result === 'duplicate'){
		return boards_fail('duplicate', "Another board already uses [board:$slug].");
	}
	if($result === false){
		return boards_fail('db_error', "The board could not be created. Try again in a moment.");
	}
	ghoti::logInfo("boards.async.php:boardsAddBoard", "Board $result ($slug) created by user ".ghoti_current_user_id()." from ".ghoti_remote_addr().".");
	return boards_ok(array('boardId' => $result, 'slug' => $slug));
}

function boardsSaveBoard($boardId, $name, $slug, $description, $mode, $postPolicy, $readPolicy, $locked, $sortOrder = 0){
	if(!ghoti_require_admin()){ return boards_fail('forbidden', "Admin access required."); }
	$db = boards_db();
	if($db === null){ return boards_fail('db_error', "Boards are not available."); }
	try{
		$boardId = ghoti_validate()->id($boardId, "board id");
		list($name, $slug, $description, $mode, $postPolicy, $readPolicy) = boards_prepare($name, $slug, $description, $mode, $postPolicy, $readPolicy);
	}catch(Exception $e){
		return boards_fail('invalid', $e->getMessage());
	}
	//An UPDATE that matches nothing still succeeds, which is how a board someone
	//else had just deleted used to report "Saved".
	$existing = $db->getBoardById($boardId);
	if($existing === false){ return boards_fail('db_error', "The board could not be saved. Try again in a moment."); }
	if($existing === null){ return boards_fail('not_found', "That board no longer exists. Reload the list."); }

	/*
	 * A comment section shows one thread and no topic list, so switching a board
	 * that has collected several topics would leave every topic but the oldest
	 * with no way to reach it - the posts would still be there, and simply stop
	 * appearing anywhere. Refuse, and say what to do about it, rather than hide
	 * somebody's discussion behind a dropdown.
	 */
	if($mode === 'comments' && $existing['mode'] !== 'comments'){
		$topicCount = $db->countTopics($boardId);
		if($topicCount === false){ return boards_fail('db_error', "The board could not be saved. Try again in a moment."); }
		if($topicCount > 1){
			return boards_fail('invalid', "\"".$existing['name']."\" has ".$topicCount." topics. A comment section shows only one, so the rest would no longer appear anywhere. Delete the extra topics first, or leave this board as a message board.");
		}
	}

	$result = $db->saveBoard($boardId, $name, $slug, $description, $mode, $postPolicy, $readPolicy, !empty($locked), (int)$sortOrder);
	if($result === 'duplicate'){
		return boards_fail('duplicate', "Another board already uses [board:$slug].");
	}
	if($result === false){
		return boards_fail('db_error', "The board could not be saved. Try again in a moment.");
	}
	ghoti::logInfo("boards.async.php:boardsSaveBoard", "Board $boardId ($slug) saved by user ".ghoti_current_user_id()." from ".ghoti_remote_addr().".");
	return boards_ok(array('boardId' => $boardId, 'slug' => $slug));
}

function boardsDeleteBoard($boardId){
	if(!ghoti_require_admin()){ return boards_fail('forbidden', "Admin access required."); }
	$db = boards_db();
	if($db === null){ return boards_fail('db_error', "Boards are not available."); }
	try{
		$boardId = ghoti_validate()->id($boardId, "board id");
	}catch(Exception $e){
		return boards_fail('invalid', $e->getMessage());
	}
	$existing = $db->getBoardById($boardId);
	if($existing === false){ return boards_fail('db_error', "Could not delete the board. Try again in a moment."); }
	if($existing === null){ return boards_fail('not_found', "That board no longer exists."); }
	if($db->deleteBoard($boardId) === false){
		return boards_fail('db_error', "Could not delete the board. Try again in a moment.");
	}
	ghoti::logInfo("boards.async.php:boardsDeleteBoard", "Board $boardId (".$existing['slug'].") and all its posts deleted by user ".ghoti_current_user_id()." from ".ghoti_remote_addr().".");
	return boards_ok(array('boardId' => $boardId));
}

/* ---------------------------------------------------------------- *
 *  Admin: moderators, edited from Manage Users
 * ---------------------------------------------------------------- */

//The boards one account moderates, plus every board, so Manage Users can draw
//the whole set of checkboxes from one call.
function boardsGetUserModeration($userId){
	if(!ghoti_require_admin()){ return boards_fail('forbidden', "Admin access required."); }
	$db = boards_db();
	if($db === null){ return boards_fail('db_error', "Boards are not available."); }
	try{
		$userId = ghoti_validate()->id($userId, "user id");
	}catch(Exception $e){
		return boards_fail('invalid', $e->getMessage());
	}
	$rows = $db->getBoards();
	$map = $db->getModeratorMap();
	if($rows === false || $map === false){
		return boards_fail('db_error', "Could not load the boards.");
	}
	$mine = isset($map[$userId]) ? $map[$userId] : array();
	$boards = array();
	foreach($rows as $row){
		$boards[] = array(
			'boardId'   => (int)$row[0],
			'name'      => (string)$row[1],
			'slug'      => (string)$row[2],
			'moderates' => in_array((int)$row[0], $mine, true),
		);
	}
	return boards_ok(array('userId' => $userId, 'boards' => $boards));
}

function boardsSetModerator($userId, $boardId, $on){
	if(!ghoti_require_admin()){ return boards_fail('forbidden', "Admin access required."); }
	$db = boards_db();
	if($db === null){ return boards_fail('db_error', "Boards are not available."); }
	try{
		$userId = ghoti_validate()->id($userId, "user id");
		$boardId = ghoti_validate()->id($boardId, "board id");
	}catch(Exception $e){
		return boards_fail('invalid', $e->getMessage());
	}
	//Granting moderation of a board that does not exist would leave a row no
	//screen can ever show, and no way to revoke it.
	$board = $db->getBoardById($boardId);
	if($board === false){ return boards_fail('db_error', "Could not update moderators. Try again in a moment."); }
	if($board === null){ return boards_fail('not_found', "That board no longer exists."); }
	//A grant to a userId that no longer exists is a row no screen can show and
	//nobody can revoke. getUserNameById() answers with an empty value for an
	//account that is gone.
	if(isset($_SESSION['loginObj'])){
		$userName = $_SESSION['loginObj']->logindb->getUserNameById($userId);
		if($userName === false || $userName === null || $userName === ''){
			return boards_fail('not_found', "That user no longer exists.");
		}
	}
	$on = !empty($on);
	if($db->setModerator($userId, $boardId, $on) === false){
		return boards_fail('db_error', "Could not update moderators. Try again in a moment.");
	}
	ghoti::logInfo("boards.async.php:boardsSetModerator", ($on ? "Granted" : "Revoked")." moderation of ".$board['slug']." for user $userId by user ".ghoti_current_user_id()." from ".ghoti_remote_addr().".");
	return boards_ok(array('userId' => $userId, 'boardId' => $boardId, 'moderates' => $on));
}

ghoti_async_register(
	"boardsGetView",
	"boardsAddTopic",
	"boardsAddPost",
	"boardsEditPost",
	"boardsDeletePost",
	"boardsSetTopicFlags",
	"boardsDeleteTopic",
	"boardsGetBoards",
	"boardsAddBoard",
	"boardsSaveBoard",
	"boardsDeleteBoard",
	"boardsGetUserModeration",
	"boardsSetModerator",
	"boardsGetNotifyPrefs",
	"boardsSaveNotifyPrefs"
);

/* ---------------------------------------------------------------- *
 *  Manage Users integration
 *
 *  The login module calls these through function_exists(), so Manage Users
 *  works unchanged when boards are switched off - the columns just vanish.
 * ---------------------------------------------------------------- */

//array(userId => postCount). Empty on any failure: a missing number in a column
//is better than an error where the user list should be.
function boards_user_post_counts(){
	$db = boards_db();
	if($db === null){ return array(); }
	$counts = $db->getPostCounts();
	return $counts === false ? array() : $counts;
}

//array(userId => array('name'=>..., ...)) of the boards each account moderates.
function boards_user_moderation_summary(){
	$db = boards_db();
	if($db === null){ return array(); }
	$map = $db->getModeratorMap();
	$rows = $db->getBoards();
	if($map === false || $rows === false){ return array(); }
	$names = array();
	foreach($rows as $row){ $names[(int)$row[0]] = (string)$row[1]; }
	$summary = array();
	foreach($map as $userId => $boardIds){
		$labels = array();
		foreach($boardIds as $boardId){
			if(isset($names[$boardId])){ $labels[] = $names[$boardId]; }
		}
		sort($labels);
		$summary[(int)$userId] = $labels;
	}
	return $summary;
}

/* ---------------------------------------------------------------- *
 *  Shortcode: [board:SLUG] / [board SLUG] inside page content.
 *  Expanded by getPage() (ghoti.async.php), like [links:GROUP].
 *
 *  This only ever emits an empty container. boards.js finds it and asks
 *  boardsGetView() what to draw, so the board can page through topics and
 *  posts without reloading the page it is placed on - and so post bodies never
 *  pass through the stripslashes() getPage() runs over expanded page content.
 * ---------------------------------------------------------------- */

function boards_shortcode_expand($matches){
	$slug = isset($matches[1]) ? boards_slug($matches[1]) : '';
	if($slug === '' || !isset($_SESSION['boardsObj'])){ return ''; }
	return '<div class="ghotiBoard" data-board-slug="'.htmlspecialchars($slug, ENT_QUOTES, 'UTF-8').'">'
		.'<p class="ghotiBoardLoading">Loading board&hellip;</p></div>';
}
ghoti_register_shortcode('board', 'boards_shortcode_expand');
