<?php
/*
 * boards.db.php - every query the boards module makes.
 *
 * Shape of the contract with the rest of the module: a read returns rows (or
 * an empty array), a write returns the new id / true, and ANY failure returns
 * false after logging. Callers turn that false into an envelope with a message
 * a person can read - they never see the exception.
 *
 * Rows come back as associative arrays here rather than the numeric tuples the
 * older modules pass around, because a post carries eight fields and
 * `$y[3]` at the call site is how the comments module ended up with a comment
 * "man this sucks... it's hard to remember which is which".
 */

class boardsdb extends ghotidb{
	function __construct(){
		parent::__construct();
		parent::loadModuleSql("boards");
	}
	function __destruct(){
		parent::__destruct();
	}

	/* ---------------- boards ---------------- */

	//Every board, in admin order. The counts are derived, never stored, so they
	//cannot drift out of step with the rows they describe.
	public function getBoards(){
		try{
			return $this->queryArray(
				"select b.boardId,b.name,b.slug,b.description,b.mode,b.postPolicy,b.readPolicy,b.locked,b.sortOrder,
				        (select count(*) from board_topics t where t.boardId = b.boardId) as topicCount,
				        (select count(*) from board_posts p where p.boardId = b.boardId) as postCount
				   from boards b
				  order by b.sortOrder asc, b.name asc");
		}catch (Throwable $e){
			ghoti::logException("boards.db.php:getBoards", $e);
			return false;
		}
	}

	//One board by its [board:slug] name. null (not false) means "no such board",
	//so a page can say so instead of showing a database error.
	public function getBoardBySlug($slug){
		try{
			$rows = $this->queryArray("select boardId,name,slug,description,mode,postPolicy,readPolicy,locked from boards where slug = ? limit 1",array((string)$slug));
		}catch (Throwable $e){
			ghoti::logException("boards.db.php:getBoardBySlug", $e);
			return false;
		}
		return isset($rows[0]) ? $this->boardRow($rows[0]) : null;
	}

	public function getBoardById($boardId){
		try{
			$rows = $this->queryArray("select boardId,name,slug,description,mode,postPolicy,readPolicy,locked from boards where boardId = ? limit 1",array((int)$boardId));
		}catch (Throwable $e){
			ghoti::logException("boards.db.php:getBoardById", $e);
			return false;
		}
		return isset($rows[0]) ? $this->boardRow($rows[0]) : null;
	}

	private function boardRow($row){
		return array(
			'boardId'     => (int)$row[0],
			'name'        => (string)$row[1],
			'slug'        => (string)$row[2],
			'description' => (string)$row[3],
			'mode'        => (string)$row[4],
			'postPolicy'  => (string)$row[5],
			'readPolicy'  => (string)$row[6],
			'locked'      => (int)$row[7] === 1,
		);
	}

	//Returns the new boardId, 'duplicate' if the slug is taken, or false.
	public function addBoard($name,$slug,$description,$mode,$postPolicy,$readPolicy,$sortOrder){
		try{
			if($this->slugTaken($slug, 0)){ return 'duplicate'; }
			$this->query("insert into boards(name,slug,description,mode,postPolicy,readPolicy,sortOrder,createdAt) values(?,?,?,?,?,?,?,?)",
				array((string)$name,(string)$slug,(string)$description,(string)$mode,(string)$postPolicy,(string)$readPolicy,(int)$sortOrder,time()));
			return (int)$this->db()->lastInsertId();
		}catch (Throwable $e){
			ghoti::logException("boards.db.php:addBoard", $e);
			return false;
		}
	}

	public function saveBoard($boardId,$name,$slug,$description,$mode,$postPolicy,$readPolicy,$locked,$sortOrder){
		try{
			if($this->slugTaken($slug, (int)$boardId)){ return 'duplicate'; }
			$this->query("update boards set name=?,slug=?,description=?,mode=?,postPolicy=?,readPolicy=?,locked=?,sortOrder=? where boardId=?",
				array((string)$name,(string)$slug,(string)$description,(string)$mode,(string)$postPolicy,(string)$readPolicy,$locked?1:0,(int)$sortOrder,(int)$boardId));
			return true;
		}catch (Throwable $e){
			ghoti::logException("boards.db.php:saveBoard", $e);
			return false;
		}
	}

	private function slugTaken($slug, $excludeId){
		$rows = $this->queryArray("select boardId from boards where slug = ? and boardId <> ? limit 1",array((string)$slug,(int)$excludeId));
		return isset($rows[0]);
	}

	//Deleting a board takes its topics, posts and moderator grants with it.
	//There are no FK cascades (the older tables in this app are MyISAM), so the
	//children are removed explicitly rather than left as orphans.
	public function deleteBoard($boardId){
		$boardId = (int)$boardId;
		try{
			$this->query("delete from board_posts where boardId = ?",array($boardId));
			$this->query("delete from board_topics where boardId = ?",array($boardId));
			$this->query("delete from board_moderators where boardId = ?",array($boardId));
			$this->query("delete from boards where boardId = ?",array($boardId));
			return true;
		}catch (Throwable $e){
			ghoti::logException("boards.db.php:deleteBoard", $e);
			return false;
		}
	}

	/* ---------------- topics ---------------- */

	public function getTopics($boardId,$limit,$offset){
		try{
			return $this->queryArray(
				"select t.topicId,t.title,t.locked,t.sticky,t.createdAt,t.lastPostAt,t.userId,
				        coalesce(u.userName,'(deleted)') as userName,
				        (select count(*) from board_posts p where p.topicId = t.topicId) as postCount
				   from board_topics t left join users u on u.userId = t.userId
				  where t.boardId = ?
				  order by t.sticky desc, t.lastPostAt desc, t.topicId desc
				  limit ".(int)$limit." offset ".(int)$offset,
				array((int)$boardId));
		}catch (Throwable $e){
			ghoti::logException("boards.db.php:getTopics", $e);
			return false;
		}
	}

	public function countTopics($boardId){
		try{
			$rows = $this->queryArray("select count(*) from board_topics where boardId = ?",array((int)$boardId));
			return isset($rows[0][0]) ? (int)$rows[0][0] : 0;
		}catch (Throwable $e){
			ghoti::logException("boards.db.php:countTopics", $e);
			return false;
		}
	}

	public function getTopic($topicId){
		try{
			$rows = $this->queryArray("select topicId,boardId,userId,title,locked,sticky,createdAt from board_topics where topicId = ? limit 1",array((int)$topicId));
		}catch (Throwable $e){
			ghoti::logException("boards.db.php:getTopic", $e);
			return false;
		}
		if(!isset($rows[0])){ return null; }
		$row = $rows[0];
		return array(
			'topicId'   => (int)$row[0],
			'boardId'   => (int)$row[1],
			'userId'    => (int)$row[2],
			'title'     => (string)$row[3],
			'locked'    => (int)$row[4] === 1,
			'sticky'    => (int)$row[5] === 1,
			'createdAt' => (int)$row[6],
		);
	}

	public function addTopic($boardId,$userId,$title){
		try{
			$now = time();
			$this->query("insert into board_topics(boardId,userId,title,createdAt,lastPostAt) values(?,?,?,?,?)",
				array((int)$boardId,(int)$userId,(string)$title,$now,$now));
			return (int)$this->db()->lastInsertId();
		}catch (Throwable $e){
			ghoti::logException("boards.db.php:addTopic", $e);
			return false;
		}
	}

	/*
	 * THE definition of "the implicit thread" a 'comments' board hangs its posts
	 * on: its oldest topic. Returns 0 when the board has none yet, or false on
	 * error.
	 *
	 * Reading and writing a comment section must agree on this, and they once
	 * did not: the read took the board's first topic in display order (newest
	 * first) while the write took the lowest topicId. With one topic those are
	 * the same row, so it looked fine - but a board switched from 'board' to
	 * 'comments' in the admin screen keeps the several topics it collected, and
	 * there a comment saved into one thread and was read back from another, so
	 * it simply never appeared. Both callers go through here now.
	 */
	public function getDefaultTopicId($boardId){
		try{
			$rows = $this->queryArray("select topicId from board_topics where boardId = ? order by topicId asc limit 1",array((int)$boardId));
		}catch (Throwable $e){
			ghoti::logException("boards.db.php:getDefaultTopicId", $e);
			return false;
		}
		return isset($rows[0][0]) ? (int)$rows[0][0] : 0;
	}

	/*
	 * The implicit topic, created on first use. Two visitors commenting at the
	 * same moment on a page that has never been commented on would otherwise
	 * create two topics and split the thread, so the row is re-read after the
	 * insert and the loser of that race adopts the winner's topic.
	 */
	public function getOrCreateDefaultTopic($boardId,$userId){
		$boardId = (int)$boardId;
		$existing = $this->getDefaultTopicId($boardId);
		if($existing === false){ return false; }
		if($existing > 0){ return $existing; }
		$id = $this->addTopic($boardId,(int)$userId,'');
		if($id === false){ return false; }
		$settled = $this->getDefaultTopicId($boardId);
		return $settled ? $settled : $id;
	}

	public function setTopicFlags($topicId,$locked,$sticky){
		try{
			$this->query("update board_topics set locked=?,sticky=? where topicId=?",array($locked?1:0,$sticky?1:0,(int)$topicId));
			return true;
		}catch (Throwable $e){
			ghoti::logException("boards.db.php:setTopicFlags", $e);
			return false;
		}
	}

	public function deleteTopic($topicId){
		try{
			$this->query("delete from board_posts where topicId = ?",array((int)$topicId));
			$this->query("delete from board_topics where topicId = ?",array((int)$topicId));
			return true;
		}catch (Throwable $e){
			ghoti::logException("boards.db.php:deleteTopic", $e);
			return false;
		}
	}

	/* ---------------- posts ---------------- */

	//A post carries its author's TOTAL post count across every board, which is
	//what the board shows under a name. It is a subquery, not a stored counter:
	//deleting a post, a topic, a board or a user keeps it correct with no
	//bookkeeping on any of those paths.
	public function getPosts($topicId,$limit,$offset){
		try{
			return $this->queryArray(
				"select p.postId,p.userId,p.body,p.createdAt,p.editedAt,
				        coalesce(u.userName,'(deleted)') as userName,
				        coalesce(u.admin,0) as isAdmin,
				        (select count(*) from board_posts c where c.userId = p.userId) as authorPosts
				   from board_posts p left join users u on u.userId = p.userId
				  where p.topicId = ?
				  order by p.postId asc
				  limit ".(int)$limit." offset ".(int)$offset,
				array((int)$topicId));
		}catch (Throwable $e){
			ghoti::logException("boards.db.php:getPosts", $e);
			return false;
		}
	}

	public function countPosts($topicId){
		try{
			$rows = $this->queryArray("select count(*) from board_posts where topicId = ?",array((int)$topicId));
			return isset($rows[0][0]) ? (int)$rows[0][0] : 0;
		}catch (Throwable $e){
			ghoti::logException("boards.db.php:countPosts", $e);
			return false;
		}
	}

	public function addPost($boardId,$topicId,$userId,$body){
		try{
			$now = time();
			$this->query("insert into board_posts(boardId,topicId,userId,body,createdAt) values(?,?,?,?,?)",
				array((int)$boardId,(int)$topicId,(int)$userId,(string)$body,$now));
			$postId = (int)$this->db()->lastInsertId();
			$this->query("update board_topics set lastPostAt = ? where topicId = ?",array($now,(int)$topicId));
			return $postId;
		}catch (Throwable $e){
			ghoti::logException("boards.db.php:addPost", $e);
			return false;
		}
	}

	public function getPost($postId){
		try{
			$rows = $this->queryArray("select postId,boardId,topicId,userId,body,createdAt from board_posts where postId = ? limit 1",array((int)$postId));
		}catch (Throwable $e){
			ghoti::logException("boards.db.php:getPost", $e);
			return false;
		}
		if(!isset($rows[0])){ return null; }
		$row = $rows[0];
		return array(
			'postId'    => (int)$row[0],
			'boardId'   => (int)$row[1],
			'topicId'   => (int)$row[2],
			'userId'    => (int)$row[3],
			'body'      => (string)$row[4],
			'createdAt' => (int)$row[5],
		);
	}

	public function editPost($postId,$body){
		try{
			$this->query("update board_posts set body=?,editedAt=? where postId=?",array((string)$body,time(),(int)$postId));
			return true;
		}catch (Throwable $e){
			ghoti::logException("boards.db.php:editPost", $e);
			return false;
		}
	}

	//Removes the post only. Tidying up a thread left empty by it is the caller's
	//decision (boardsDeletePost() drops it on a 'board', keeps it on 'comments').
	public function deletePost($postId){
		try{
			$this->query("delete from board_posts where postId = ?",array((int)$postId));
			return true;
		}catch (Throwable $e){
			ghoti::logException("boards.db.php:deletePost", $e);
			return false;
		}
	}

	/* ---------------- moderators and per-user counts ---------------- */

	public function getModerators($boardId){
		try{
			$rows = $this->queryArray("select userId from board_moderators where boardId = ?",array((int)$boardId));
		}catch (Throwable $e){
			ghoti::logException("boards.db.php:getModerators", $e);
			return false;
		}
		return array_map(function($row){ return (int)$row[0]; }, $rows);
	}

	//array(userId => array(boardId, ...)) - what Manage Users needs in one read
	//rather than one query per account.
	public function getModeratorMap(){
		try{
			$rows = $this->queryArray("select userId,boardId from board_moderators");
		}catch (Throwable $e){
			ghoti::logException("boards.db.php:getModeratorMap", $e);
			return false;
		}
		$map = array();
		foreach($rows as $row){
			$map[(int)$row[0]][] = (int)$row[1];
		}
		return $map;
	}

	public function isModerator($userId,$boardId){
		try{
			$rows = $this->queryArray("select 1 from board_moderators where userId = ? and boardId = ? limit 1",array((int)$userId,(int)$boardId));
		}catch (Throwable $e){
			ghoti::logException("boards.db.php:isModerator", $e);
			return false; //fail closed: a database error is not a grant
		}
		return isset($rows[0]);
	}

	public function setModerator($userId,$boardId,$on){
		try{
			if($on){
				//The primary key makes a second grant a no-op rather than a duplicate row.
				$this->query("insert into board_moderators(boardId,userId,grantedAt) values(?,?,?) on duplicate key update grantedAt = grantedAt",
					array((int)$boardId,(int)$userId,time()));
			}else{
				$this->query("delete from board_moderators where boardId = ? and userId = ?",array((int)$boardId,(int)$userId));
			}
			return true;
		}catch (Throwable $e){
			ghoti::logException("boards.db.php:setModerator", $e);
			return false;
		}
	}

	//array(userId => postCount) for every account that has ever posted.
	public function getPostCounts(){
		try{
			$rows = $this->queryArray("select userId,count(*) from board_posts group by userId");
		}catch (Throwable $e){
			ghoti::logException("boards.db.php:getPostCounts", $e);
			return false;
		}
		$counts = array();
		foreach($rows as $row){
			$counts[(int)$row[0]] = (int)$row[1];
		}
		return $counts;
	}

	//Called when an account is removed, so posts are not left attributed to a
	//userId a new account could later be given.
	public function deleteUserContent($userId){
		try{
			$this->query("delete from board_posts where userId = ?",array((int)$userId));
			$this->query("delete from board_moderators where userId = ?",array((int)$userId));
			return true;
		}catch (Throwable $e){
			ghoti::logException("boards.db.php:deleteUserContent", $e);
			return false;
		}
	}
}
?>
