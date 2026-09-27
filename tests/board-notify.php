<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
/*
 * Run: php tests/board-notify.php - no database, no browser, no real mail.
 *
 * Board reply notifications are opt-in e-mail sent from inside the request
 * that saved a post. What must hold:
 *
 *   - nobody is mailed unless they opted in and posted in that thread (the
 *     query owns that; the fake below mirrors its contract),
 *   - the poster never hears about their own post,
 *   - a bad or duplicate address is skipped, one failure does not stop the rest,
 *   - nothing is sent while site mail is off,
 *   - the notice is themed and its footer says how to turn it off,
 *   - a mail failure never turns a saved post into an error for the poster,
 *   - the Notifications menu item exists only while boards are enabled.
 */
chdir(dirname(__DIR__));
require_once 'ghoti.php';

$checks = 0;
function notifyCheck($ok, $label){
	global $checks; $checks++;
	if(!$ok){ throw new RuntimeException($label); }
}

ghoti::$enableBoards = true;
$loader = (new ReflectionClass(ghoti::class))->newInstanceWithoutConstructor();
$loader->loadModules(array('boards'));

class NotifyMailer{
	public $sent = array();
	public $enabled = true;
	public $failFor = array();
	public $maildb;
	public function __construct(){ $this->maildb = new NotifyMailerDb($this); }
	public function send($to, $name, $subject, $body, array $attachments = array(), $htmlBody = null){
		if(in_array($to, $this->failFor, true)){ return 'refused'; }
		$this->sent[] = array('to'=>$to,'name'=>$name,'subject'=>$subject,'body'=>$body,'html'=>$htmlBody);
		return true;
	}
}
class NotifyMailerDb{
	private $mailer;
	public function __construct($mailer){ $this->mailer = $mailer; }
	public function getSettings(){ return array('enabled'=>$this->mailer->enabled, 'fromAddress'=>'site@example.test'); }
}

/* Mirrors boardsdb::getReplySubscribers(): opted-in participants of the
 * topic, excluding the author. */
class NotifyDbFake{
	public $posts = array();   //array(topicId, userId)
	public $optIn = array();   //userId => bool
	public $users = array();   //userId => array(name, email)
	public function getReplySubscribers($topicId, $excludeUserId, $limit){
		$rows = array();
		foreach($this->posts as $p){
			list($t, $u) = $p;
			if($t !== $topicId || $u === $excludeUserId || empty($this->optIn[$u]) || isset($rows[$u])){ continue; }
			$rows[$u] = array($u, $this->users[$u][0], $this->users[$u][1]);
		}
		ksort($rows);
		return array_slice(array_values($rows), 0, $limit);
	}
	public function getUserContact($userId){
		return array('userName'=>$this->users[$userId][0] ?? '', 'email'=>$this->users[$userId][1] ?? '');
	}
}

ghoti::$siteTitle = 'Notify Test Site';
$board = array('boardId'=>1,'name'=>'General','slug'=>'general','mode'=>'board','postPolicy'=>'users','readPolicy'=>'public','locked'=>false);
$topic = array('topicId'=>7,'boardId'=>1,'userId'=>1,'title'=>'Hello there','locked'=>false,'sticky'=>false,'createdAt'=>1);

$db = new NotifyDbFake();
$db->users = array(
	1 => array('alice', 'alice@example.test'),
	2 => array('bob', 'bob@example.test'),
	3 => array('carol', 'not-an-address'),
	4 => array('dave', 'dave@example.test'),
	5 => array('erin', 'ERIN@example.test'),
	6 => array('frank', 'erin@example.test'),
);
$db->posts = array(array(7,1), array(7,2), array(7,3), array(7,4), array(7,5), array(7,6), array(8,2));
$db->optIn = array(1=>true, 2=>true, 3=>true, 4=>false, 5=>true, 6=>true);

/* ---- who is mailed ---- */
$mailer = new NotifyMailer();
$sent = boards_notify_reply($mailer, $db, $board, $topic, 7, 2, "A reply body");
$to = array_column($mailer->sent, 'to');
notifyCheck($sent === 2 && count($to) === 2, 'expected two notices (alice, erin; frank shares erin\'s address), got '.count($to));
notifyCheck(in_array('alice@example.test', $to, true), 'an opted-in participant was not mailed');
notifyCheck(!in_array('bob@example.test', $to, true), 'the author was mailed about their own post');
notifyCheck(!in_array('dave@example.test', $to, true), 'someone who did not opt in was mailed');
notifyCheck(!in_array('not-an-address', $to, true), 'an invalid address was used');
notifyCheck(count(array_unique(array_map('strtolower', $to))) === count($to), 'one address was mailed twice');

/* ---- what they get ---- */
$msg = $mailer->sent[0];
notifyCheck(strpos($msg['subject'], 'bob') === 0 && strpos($msg['subject'], 'Hello there') !== false, 'subject does not name the author and thread: '.$msg['subject']);
notifyCheck(strpos($msg['body'], 'A reply body') !== false, 'the plain-text part does not carry the reply');
notifyCheck(is_string($msg['html']) && stripos($msg['html'], '<html') !== false, 'the notice is not themed HTML');
notifyCheck(strpos($msg['body'], 'Notifications') !== false && strpos($msg['html'], 'Notifications') !== false, 'the footer does not say how to turn notices off');

list($subject, $body) = boards_reply_notice('Site', array('name'=>'Page chat','mode'=>'comments'), null, 'zed', str_repeat('x', 5000));
notifyCheck(strpos($subject, 'comments on "Page chat"') !== false, 'a comment section notice does not say so: '.$subject);
notifyCheck(mb_strlen($body) < 1000, 'a long post was not trimmed to an excerpt');

/* ---- failures ---- */
$mailer = new NotifyMailer();
$mailer->failFor = array('alice@example.test');
notifyCheck(boards_notify_reply($mailer, $db, $board, $topic, 7, 2, 'x') === 1 && count($mailer->sent) === 1, 'one failure stopped the rest');

$mailer = new NotifyMailer();
$mailer->enabled = false;
notifyCheck(boards_notify_reply($mailer, $db, $board, $topic, 7, 2, 'x') === 0 && !$mailer->sent, 'notices went out while site mail is off');
notifyCheck(boards_notify_reply(null, $db, $board, $topic, 7, 2, 'x') === 0, 'a missing mailer was not handled');

/* ---- the post still succeeds when notifying throws ---- */
$src = file_get_contents('mod/boards/boards.async.php');
notifyCheck(preg_match('/try\{\s*boards_notify_reply\(.*?\}catch\(Throwable \$e\)/s', $src) === 1, 'boardsAddPost does not isolate notification failures');
notifyCheck(strpos($src, '->send(') === false, 'boards sends mail without the themed helper');

/* ---- endpoints need a signed-in user ---- */
unset($_SESSION['loggedIn'], $_SESSION['userId']);
notifyCheck(boardsGetNotifyPrefs()['error']['code'] === 'forbidden', 'prefs were readable signed out');
notifyCheck(boardsSaveNotifyPrefs(1)['error']['code'] === 'forbidden', 'prefs were writable signed out');

/* ---- the menu item follows the module switch ---- */
require_once 'mod/login/login.php';
ghoti::$enableBoards = true;
notifyCheck(strpos((new loginui())->printSystemMenu(), 'boardsShowNotifyPrefs') !== false, 'no Notifications item while boards are on');
ghoti::$enableBoards = false;
notifyCheck(strpos((new loginui())->printSystemMenu(), 'boardsShowNotifyPrefs') === false, 'a Notifications item while boards are off');

echo "PASS: $checks board notification assertions\n";
