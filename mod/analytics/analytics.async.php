<?php
/*
 * analytics.async.php - analytics module async layer.
 *
 * Combines the former analytics.ajax.php (endpoints + the trackPageView hook)
 * and analytics.ui.php (class analyticsui) into one file, registered through
 * the ghoti async wrapper.
 *
 * showAnalytics() is admin-gated server-side - the client only shows the
 * "Analytics" menu item to admins, but (unlike some older endpoints in this
 * app) that alone isn't trusted. The CSV download lives in analytics.export.php
 * because a file download needs a plain navigable URL, not an async response.
 */

/* ---------------------------------------------------------------- *
 *  Endpoints (formerly analytics.ajax.php)
 * ---------------------------------------------------------------- */

function analyticsRequireAdmin(){
	if(!isset($_SESSION['userId']) || !isAdmin($_SESSION['userId'])){
		throw new Exception('Unauthorized');
	}
}

function analyticsServerValue($key){
	return isset($_SERVER[$key]) ? $_SERVER[$key] : '';
}

/* Hooked from ghoti.async.php::getPage(), which runs on every page view. */
function trackPageView($isAdminViewer=false){
	try{
		$_SESSION['analyticsObj']->analyticsdb->logPageView(
			isset($_SESSION['pageId']) ? $_SESSION['pageId'] : null,
			isset($_SESSION['userId']) ? $_SESSION['userId'] : null,
			$isAdminViewer,
			analyticsServerValue('REMOTE_ADDR'),
			analyticsServerValue('HTTP_USER_AGENT'),
			analyticsServerValue('HTTP_REFERER'),
			analyticsServerValue('REQUEST_URI'),
			session_id()
		);
	}catch (Throwable $e){
		ghoti::log("analytics.async.php ".$e->getMessage());
	}
}

function showAnalytics($days=30,$excludeAdmin=true){
	try{
		analyticsRequireAdmin();
	}catch (Exception $e){
		ghoti::log("analytics.async.php Unauthorized analytics access attempt from ".analyticsServerValue('REMOTE_ADDR'));
		return "<h1>Analytics</h1><p>Admin access required.</p>";
	}
	$days = (int)$days;
	return $_SESSION['analyticsObj']->analyticsui->printDashboard($days,(bool)$excludeAdmin);
}

ghoti_async_register("showAnalytics");

/* ---------------------------------------------------------------- *
 *  UI renderer (formerly analytics.ui.php / class analyticsui)
 *
 *  Renders the analytics dashboard as one HTML blob (mirrors
 *  ghotiui::showGhotiLog()'s "one shot" style): KPI tiles, chart placeholders,
 *  a raw data table, and a JSON payload that mod/analytics/analytics.js reads
 *  to draw the actual SVG charts client-side.
 * ---------------------------------------------------------------- */

class analyticsui{
	public $output;

	public function printDashboard($days=30,$excludeAdmin=true){
		$db = $_SESSION['analyticsObj']->analyticsdb;

		$summary   = $db->getSummary($days,$excludeAdmin);
		$byDay     = $db->getPageviewsByDay($days,$excludeAdmin);
		$byHour    = $db->getHourlyDistribution($days,$excludeAdmin);
		$topPages  = $db->getTopPages(8,$days,$excludeAdmin);
		$browsers  = $db->getBreakdown('browser',$days,$excludeAdmin);
		$oses      = $db->getBreakdown('os',$days,$excludeAdmin);
		$devices   = $db->getBreakdown('deviceType',$days,$excludeAdmin);
		$referrers = $db->getTopReferrers(8,$days,$excludeAdmin);
		$recent    = $db->getRecentPageviews(200,$days);
		$topErrors = $this->getTopLogErrors(8,$days);

		$totalViews     = isset($summary[0]) ? (int)$summary[0] : 0;
		$uniqueSessions = isset($summary[1]) ? (int)$summary[1] : 0;
		$uniqueVisitors = isset($summary[2]) ? (int)$summary[2] : 0;
		$pagesViewed    = isset($summary[3]) ? (int)$summary[3] : 0;
		$avgPerDay      = $days > 0 ? round($totalViews / $days, 1) : 0;

		$data = array(
			'days'         => (int)$days,
			'excludeAdmin' => (bool)$excludeAdmin,
			'byDay'        => $byDay,
			'byHour'       => $byHour,
			'topPages'     => $topPages,
			'browsers'     => $browsers,
			'oses'         => $oses,
			'devices'      => $devices,
			'referrers'    => $referrers,
		);

		$out  = "<div id=\"ghotiAnalytics\">\n";
		$docs = ghoti_docs_panel("How to use analytics", "ranges, tiles, export", array(
			array('heading' => 'Choose a range',
				'list' => array('The <b>7d / 30d / 90d / 1y</b> buttons switch the whole dashboard &mdash; including the CSV export.')),
			array('heading' => 'Exclude admin views',
				'list' => array('Tick the checkbox to ignore pageviews recorded while an admin was viewing, for visitor-only numbers.')),
			array('heading' => 'The tiles',
				'list' => array('<b>Pageviews</b> &mdash; total page loads tracked.', '<b>Unique sessions</b> &mdash; distinct browser sessions (a new session starts after 30 minutes of inactivity).', '<b>Unique visitors</b> &mdash; distinct IP + user-agent combinations.', '<b>Pages viewed</b> &mdash; distinct pages hit.', '<b>Avg. views/day</b> &mdash; pageviews divided by the range.')),
			array('heading' => 'CSV export',
				'list' => array('<b>Download CSV</b> opens a token-protected export of the recent-pageviews table for the current range.'))
		));
		$out .= "<div class=\"analytics-head\">\n";
		$out .= "<h1>Analytics</h1>\n";
		$out .= "<p class=\"analytics-sub\">Site usage for the last ".(int)$days." day".($days==1?'':'s')." &middot; complements the <a href=\"#\" class=\"ghotiMenu\" onclick=\"showGhotiLog();\">Log</a></p>\n";
		$out .= "</div>\n";

		//Range picker + toggles
		$out .= "<div class=\"analytics-toolbar\">\n";
		$out .= "<div class=\"analytics-ranges\">\n";
		foreach(array(7=>'7d',30=>'30d',90=>'90d',365=>'1y') as $d=>$label){
			$rangeClass = ($d == $days) ? ' ghotiRangeActive' : '';
			$out .= "<a href=\"#\" class=\"ghotiMenu ghotiButton ghotiButtonCompact ghotiButtonSecondary$rangeClass\" onclick=\"setAnalyticsRange($d);\">$label</a>\n";
		}
		$out .= "</div>\n";
		$checked = $excludeAdmin ? ' checked="checked"' : '';
		$out .= "<label class=\"analytics-toggle\"><input type=\"checkbox\" id=\"analyticsExcludeAdmin\"$checked onclick=\"toggleAnalyticsAdmin(this.checked);\" /> Exclude admin views</label>\n";
		$out .= "<a href=\"mod/analytics/analytics.export.php?days=".(int)$days."&amp;token=".rawurlencode(ghoti_csrf_token())."\" class=\"ghotiButton ghotiButtonCompact analytics-export\" target=\"_blank\" rel=\"noopener\">&#8681; Download CSV</a>\n";
		$out .= "</div>\n";

		//KPI row
		$out .= "<div class=\"analytics-kpis\">\n";
		$out .= $this->kpiTile('Pageviews', number_format($totalViews));
		$out .= $this->kpiTile('Unique sessions', number_format($uniqueSessions));
		$out .= $this->kpiTile('Unique visitors', number_format($uniqueVisitors));
		$out .= $this->kpiTile('Pages viewed', number_format($pagesViewed));
		$out .= $this->kpiTile('Avg. views/day', number_format($avgPerDay,1));
		$out .= "</div>\n";

		//Chart cards - drawn into these by analytics.js after this HTML is injected
		$out .= "<div class=\"analytics-grid\">\n";
		$out .= $this->chartCard('wide','Pageviews over time','chart-byday');
		$out .= $this->chartCard('','Traffic by hour of day','chart-byhour');
		$out .= $this->chartCard('','Top pages','chart-toppages');
		$out .= $this->chartCard('','Browsers','chart-browsers');
		$out .= $this->chartCard('','Operating systems','chart-os');
		$out .= $this->chartCard('','Device type','chart-devices');
		$out .= $this->chartCard('','Top referrers','chart-referrers');
		$out .= "</div>\n";

		$out .= $this->logErrorsCard($topErrors,$days);

		//Raw data table
		$out .= "<div class=\"card analytics-card wide\">\n";
		$out .= "<h2>Recent pageviews <span class=\"analytics-muted\">(".count($recent)." shown)</span></h2>\n";
		$out .= "<div class=\"analytics-table-wrap\"><table class=\"analytics-table\">\n";
		$out .= "<thead><tr><th>When</th><th>Page</th><th>Referrer</th></tr></thead>\n<tbody>\n";
		foreach($recent as $row){
			$referrerHost = !empty($row[6]) ? parse_url($row[6], PHP_URL_HOST) : null;
			$out .= "<tr>";
			$out .= "<td>".htmlspecialchars($row[0])."</td>";
			$out .= "<td>".htmlspecialchars($row[1] !== null ? $row[1] : '(untitled)')."</td>";
			$out .= "<td>".htmlspecialchars($referrerHost ? $referrerHost : 'Direct')."</td>";
			$out .= "</tr>\n";
		}
		if(empty($recent)){
			$out .= "<tr><td colspan=\"3\" class=\"analytics-empty\">No pageviews recorded yet.</td></tr>\n";
		}
		$out .= "</tbody></table></div>\n";
		$out .= "</div>\n"; //card

		$out .= $docs;
		$out .= "<script type=\"application/json\" id=\"analyticsData\">".json_encode($data)."</script>\n";
		$out .= "</div>\n"; //ghotiAnalytics

		return $out;
	}

	private function chartCard($extraClass,$title,$chartId){
		$class = trim("card analytics-card $extraClass");
		return "<div class=\"$class\"><h2>$title</h2><div class=\"chart\" id=\"$chartId\"></div></div>\n";
	}

	private function kpiTile($label,$value){
		return "<div class=\"analytics-kpi\"><span class=\"analytics-kpi-label\">$label</span><span class=\"analytics-kpi-value\">$value</span></div>\n";
	}

	private function logErrorsCard($errors,$days){
		$out  = "<div class=\"card analytics-card analytics-log-errors-card wide\">\n";
		$out .= "<h2>Top log errors <span class=\"analytics-muted\">last ".(int)$days." day".($days==1?'':'s')."</span></h2>\n";
		if(empty($errors)){
			$out .= "<div class=\"analytics-empty\">No error-like log messages found in this range.</div>\n";
		}else{
			$out .= "<ol class=\"analytics-log-errors\">\n";
			foreach($errors as $row){
				$message = htmlspecialchars($row['message'], ENT_QUOTES);
				$count = (int)$row['count'];
				$lastSeen = !empty($row['lastSeen']) ? gmdate('M j g:i A', (int)$row['lastSeen']) : '';
				$out .= "<li>";
				$out .= "<span class=\"analytics-log-count\">".number_format($count)."</span>";
				$out .= "<span class=\"analytics-log-message\" title=\"".$message."\">".$message."</span>";
				$out .= "<span class=\"analytics-log-last\">".$lastSeen."</span>";
				$out .= "</li>\n";
			}
			$out .= "</ol>\n";
		}
		$out .= "</div>\n";
		return $out;
	}

	private function getTopLogErrors($limit=8,$days=30){
		$limit = (int)$limit;
		if($limit < 1){ $limit = 1; }
		if($limit > 25){ $limit = 25; }

		$days = (int)$days;
		if($days < 1){ $days = 1; }
		if($days > 3650){ $days = 3650; }

		$logPath = ghoti::$ghotiLog;
		$lines = is_file($logPath) ? file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array();
		if(!is_array($lines)){ return array(); }

		// Keep dashboard rendering bounded even if ghoti.log has been allowed to grow.
		if(count($lines) > 10000){
			$lines = array_slice($lines, -10000);
		}

		$cutoff = time() - ($days * 86400);
		$counts = array();
		foreach($lines as $line){
			$line = trim((string)$line);
			if($line === ''){ continue; }

			$timestamp = null;
			$message = $line;
			if(preg_match('/^\[([^\]]+)\](.*)$/', $line, $m)){
				$parsed = @strtotime($m[1]);
				if($parsed !== false){ $timestamp = $parsed; }
				$message = trim($m[2]);
			}
			if($timestamp !== null && $timestamp < $cutoff){ continue; }
			if(!$this->isLogErrorMessage($message)){ continue; }

			$normalized = $this->normalizeLogErrorMessage($message);
			if($normalized === ''){ continue; }

			if(!isset($counts[$normalized])){
				$counts[$normalized] = array('message' => $normalized, 'count' => 0, 'lastSeen' => 0);
			}
			$counts[$normalized]['count']++;
			if($timestamp !== null && $timestamp > $counts[$normalized]['lastSeen']){
				$counts[$normalized]['lastSeen'] = $timestamp;
			}
		}

		uasort($counts, array($this,'compareLogErrorRows'));
		return array_slice(array_values($counts), 0, $limit);
	}

	private function compareLogErrorRows($a,$b){
		if($a['count'] === $b['count']){
			if($a['lastSeen'] === $b['lastSeen']){ return strcmp($a['message'],$b['message']); }
			return ($a['lastSeen'] > $b['lastSeen']) ? -1 : 1;
		}
		return ($a['count'] > $b['count']) ? -1 : 1;
	}

	private function isLogErrorMessage($message){
		$message = (string)$message;
		$patterns = array(
			'/\berror\b/i',
			'/\bfail(?:ed|ure)?\b/i',
			'/unauthorized/i',
			'/denied/i',
			'/rejected/i',
			'/invalid/i',
			'/required/i',
			'/duplicate/i',
			'/too many/i',
			'/cannot|can\'t|unable|unavailable/i',
			'/access required/i',
			'/not callable/i',
			'/missing/i',
			'/refused/i',
			'/blocked/i',
			'/expired/i',
			'/incorrect/i',
			'/exception/i'
		);
		foreach($patterns as $pattern){
			if(preg_match($pattern,$message)){ return true; }
		}
		return false;
	}

	private function normalizeLogErrorMessage($message){
		$message = trim((string)$message);
		$message = preg_replace('/https?:\/\/\S+/i','{url}',$message);
		$message = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i','{email}',$message);
		$message = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/','{ip}',$message);
		$message = preg_replace('/\b[0-9a-f]{0,4}:[0-9a-f:]{2,}\b/i','{ip}',$message);
		$message = preg_replace("/'[^']*'/","'{value}'",$message);
		$message = preg_replace('/\bUID:\d+\b/i','UID:{id}',$message);
		$message = preg_replace('/\buid\s*\d+\b/i','uid {id}',$message);
		$message = preg_replace('/\buserId\s*:?\s*\d+\b/i','userId {id}',$message);
		$message = preg_replace('/\buserID\s*:?\s*\d+\b/i','userID {id}',$message);
		$message = preg_replace('/\bpage\s+\d+\b/i','page {id}',$message);
		$message = preg_replace('/\bid\s*=?\s*\d+\b/i','id {id}',$message);
		$message = preg_replace('/\b[0-9a-f]{24,}\b/i','{token}',$message);
		$message = preg_replace('/\s+/',' ',$message);
		return trim($message);
	}
}
