<?php
/*
 * Site usage analytics: collection + reporting. The dashboard here also
 * displays the raw application log (errors/audit events) alongside actual
 * visitor traffic.
 */

class analyticsdb extends ghotidb{
	public function __construct(){
		parent::__construct();
		parent::loadModuleSql("analytics");
	}
	public function __destruct(){
		parent::__destruct();
	}

	/* Called once per page render - see ghoti.async.php::getPage(). */
	public function logPageView($pageId,$userId,$isAdminView,$ipAddress,$userAgent,$referrer,$requestUri,$sessionId){
		try{
			$pageTitle = null;
			if($pageId){
				$titleRow = $this->queryArray("select title from pages where id = ?",array($pageId));
				if(!empty($titleRow)) $pageTitle = $titleRow[0][0];
			}
			list($browser,$os,$deviceType) = self::parseUserAgent($userAgent);
			$this->query(
				"insert into analytics (pageId,pageTitle,sessionId,userId,ipAddress,userAgent,browser,os,deviceType,referrer,requestUri,isAdminView) values (?,?,?,?,?,?,?,?,?,?,?,?)",
				array($pageId,$pageTitle,$sessionId,$userId,$ipAddress,$userAgent,$browser,$os,$deviceType,$referrer,$requestUri,$isAdminView?1:0)
			);
		}catch (Throwable $e){
			ghoti::logException("analytics.db.php:logPageView", $e);
			return false;
		}
		return true;
	}

	/* Lightweight UA parsing - no external dependency, good enough for reporting buckets. */
	public static function parseUserAgent($ua){
		$ua = (string)$ua;

		$os = 'Other';
		if(preg_match('/Windows/i',$ua))              $os = 'Windows';
		elseif(preg_match('/iPhone|iPad|iPod/i',$ua)) $os = 'iOS';
		elseif(preg_match('/Android/i',$ua))          $os = 'Android';
		elseif(preg_match('/Mac OS X/i',$ua))         $os = 'macOS';
		elseif(preg_match('/Linux/i',$ua))            $os = 'Linux';

		$browser = 'Other';
		if(preg_match('/Edg\//i',$ua))                                          $browser = 'Edge';
		elseif(preg_match('/OPR\//i',$ua))                                      $browser = 'Opera';
		elseif(preg_match('/Chrome\//i',$ua) && !preg_match('/Chromium/i',$ua)) $browser = 'Chrome';
		elseif(preg_match('/Firefox\//i',$ua))                                  $browser = 'Firefox';
		elseif(preg_match('/Safari\//i',$ua) && !preg_match('/Chrome/i',$ua))   $browser = 'Safari';
		elseif(preg_match('/MSIE|Trident\//i',$ua))                             $browser = 'Internet Explorer';

		$deviceType = 'Desktop';
		if(preg_match('/iPad|Tablet/i',$ua))                     $deviceType = 'Tablet';
		elseif(preg_match('/Mobi|iPhone|Android.*Mobile/i',$ua)) $deviceType = 'Mobile';

		return array($browser,$os,$deviceType);
	}

	/* $days is always cast+clamped to a plain int below, so interpolating it
	 * straight into the SQL text is safe - there is no string content that
	 * could break out of a numeric context. */
	private function rangeClause($days){
		$days = (int)$days;
		if($days < 1) $days = 1;
		if($days > 3650) $days = 3650;
		return "createdAt >= date_sub(now(), interval $days day)";
	}

	public function getSummary($days=30,$excludeAdmin=true){
		try{
			$where = $this->rangeClause($days).($excludeAdmin ? " and isAdminView = 0" : "");
			$rows = $this->queryArray("select count(*),count(distinct sessionId),count(distinct ipAddress),count(distinct pageId) from analytics where $where");
		}catch (Throwable $e){
			ghoti::logException("analytics.db.php:getSummary", $e);
			return array(0,0,0,0);
		}
		return isset($rows[0]) ? $rows[0] : array(0,0,0,0);
	}

	public function getPageviewsByDay($days=30,$excludeAdmin=true){
		try{
			$where = $this->rangeClause($days).($excludeAdmin ? " and isAdminView = 0" : "");
			$rows = $this->queryArray("select date(createdAt),count(*) from analytics where $where group by date(createdAt) order by date(createdAt) asc");
		}catch (Throwable $e){
			ghoti::logException("analytics.db.php:getPageviewsByDay", $e);
			$rows = array();
		}
		$byDate = array();
		foreach($rows as $row){ $byDate[$row[0]] = (int)$row[1]; }

		$days = (int)$days; if($days<1) $days=1; if($days>3650) $days=3650;
		$series = array();
		for($i=$days-1;$i>=0;$i--){
			$d = date('Y-m-d', strtotime("-$i day"));
			$series[] = array($d, isset($byDate[$d]) ? $byDate[$d] : 0);
		}
		return $series;
	}

	public function getHourlyDistribution($days=30,$excludeAdmin=true){
		try{
			$where = $this->rangeClause($days).($excludeAdmin ? " and isAdminView = 0" : "");
			$rows = $this->queryArray("select hour(createdAt),count(*) from analytics where $where group by hour(createdAt)");
		}catch (Throwable $e){
			ghoti::logException("analytics.db.php:getHourlyDistribution", $e);
			$rows = array();
		}
		$byHour = array_fill(0,24,0);
		foreach($rows as $row){ $byHour[(int)$row[0]] = (int)$row[1]; }
		$series = array();
		for($h=0;$h<24;$h++){ $series[] = array($h,$byHour[$h]); }
		return $series;
	}

	public function getTopPages($limit=10,$days=30,$excludeAdmin=true){
		try{
			$limit = (int)$limit; if($limit<1) $limit=1; if($limit>100) $limit=100;
			$where = $this->rangeClause($days).($excludeAdmin ? " and isAdminView = 0" : "");
			$rows = $this->queryArray("select coalesce(pageTitle,'(untitled)'),pageId,count(*) as c from analytics where $where group by pageId,pageTitle order by c desc limit $limit");
		}catch (Throwable $e){
			ghoti::logException("analytics.db.php:getTopPages", $e);
			$rows = array();
		}
		foreach($rows as &$row){ $row[2] = (int)$row[2]; }
		return $rows;
	}

	public function getBreakdown($column,$days=30,$excludeAdmin=true){
		$allowed = array('browser','os','deviceType');
		if(!in_array($column,$allowed,true)) return array();
		try{
			$where = $this->rangeClause($days).($excludeAdmin ? " and isAdminView = 0" : "");
			$rows = $this->queryArray("select coalesce($column,'Unknown'),count(*) as c from analytics where $where group by $column order by c desc");
		}catch (Throwable $e){
			ghoti::logException("analytics.db.php:getBreakdown", $e);
			$rows = array();
		}
		foreach($rows as &$row){ $row[1] = (int)$row[1]; }
		return $rows;
	}

	public function getTopReferrers($limit=8,$days=30,$excludeAdmin=true){
		try{
			$where = $this->rangeClause($days).($excludeAdmin ? " and isAdminView = 0" : "");
			$rows = $this->queryArray("select referrer from analytics where $where and referrer is not null and referrer != ''");
		}catch (Throwable $e){
			ghoti::logException("analytics.db.php:getTopReferrers", $e);
			$rows = array();
		}
		$counts = array();
		foreach($rows as $row){
			$host = parse_url($row[0], PHP_URL_HOST);
			if(!$host) $host = 'Direct/Unknown';
			$host = preg_replace('/^www\./i','',$host);
			if(!isset($counts[$host])) $counts[$host] = 0;
			$counts[$host]++;
		}
		arsort($counts);
		$limit = (int)$limit; if($limit<1) $limit=1;
		return array_slice($counts, 0, $limit, true);
	}

	public function getRecentPageviews($limit=200,$days=30){
		try{
			$limit = (int)$limit; if($limit<1) $limit=1; if($limit>5000) $limit=5000;
			$where = $this->rangeClause($days);
			$rows = $this->queryArray("select createdAt,pageTitle,ipAddress,browser,os,deviceType,referrer,isAdminView,sessionId from analytics where $where order by createdAt desc limit $limit");
		}catch (Throwable $e){
			ghoti::logException("analytics.db.php:getRecentPageviews", $e);
			$rows = array();
		}
		return $rows;
	}

	public function getExportRows($days=3650){
		try{
			$where = $this->rangeClause($days);
			$rows = $this->queryArray("select id,createdAt,pageId,pageTitle,sessionId,userId,ipAddress,userAgent,browser,os,deviceType,referrer,requestUri,isAdminView from analytics where $where order by createdAt desc");
		}catch (Throwable $e){
			ghoti::logException("analytics.db.php:getExportRows", $e);
			$rows = array();
		}
		return $rows;
	}
}
?>
