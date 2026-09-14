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
	if(!ghoti_analytics_allowed()){ return; }
	try{
		if(empty($_SESSION['analyticsVisitorId'])){ $_SESSION['analyticsVisitorId'] = bin2hex(random_bytes(16)); }
		$_SESSION['analyticsObj']->analyticsdb->logPageView(
			isset($_SESSION['pageId']) ? $_SESSION['pageId'] : null,
			null,
			$isAdminViewer,
			'',
			analyticsServerValue('HTTP_USER_AGENT'),
			(string)(parse_url(analyticsServerValue('HTTP_REFERER'), PHP_URL_HOST) ?: ''),
			'',
			$_SESSION['analyticsVisitorId']
		);
	}catch (Throwable $e){
		ghoti::logException("analytics.async.php:trackPageView", $e);
	}
}

function showAnalytics($days=30,$excludeAdmin=true){
	try{
		analyticsRequireAdmin();
	}catch (Exception $e){
		ghoti::logWarn("analytics.async.php:showAnalytics", "Unauthorized analytics access attempt from ".analyticsServerValue('REMOTE_ADDR'));
		return "<h1>Analytics</h1><p>Admin access required.</p>";
	}
	$days = (int)$days;
	return $_SESSION['analyticsObj']->analyticsui->printDashboard($days,(bool)$excludeAdmin);
}

/* ---------------------------------------------------------------- *
 *  Apache log analyzer endpoints.
 *
 *  The analyzer library (apachelog.php) is required lazily: it is a large
 *  file with declare(strict_types=1), and no ordinary page view needs it.
 *
 *  Both endpoints answer with an array the browser reads directly, rather
 *  than rendered HTML, because mod/analytics/apachelog.js redraws the same
 *  report shape from a live stream as well as from these calls. Errors come
 *  back as {ok:false,error} - the analyzer's HTTP status codes are dropped
 *  here, the same way showAnalytics() answers with a message and not a code.
 * ---------------------------------------------------------------- */

function apacheLogRequireAdmin(){
	analyticsRequireAdmin();
	require_once __DIR__.'/apachelog.php';
}

function listApacheLogs(){
	try{
		apacheLogRequireAdmin();
	}catch (Exception $e){
		ghoti::logWarn("analytics.async.php:listApacheLogs", "Unauthorized Apache log access attempt from ".analyticsServerValue('REMOTE_ADDR'));
		return array('ok' => false, 'error' => 'Admin access required.');
	}
	$config = apache_log_config();
	try{
		return array(
			'ok' => true,
			'logDir' => $config['log_dir'],
			'files' => apache_list_log_files($config),
		);
	}catch (ApacheLogHttpException $e){
		return array('ok' => false, 'error' => $e->getMessage());
	}catch (Throwable $e){
		ghoti::logException("analytics.async.php:listApacheLogs", $e);
		return array('ok' => false, 'error' => 'The log directory could not be read.');
	}
}

function analyzeApacheLog($fileId){
	try{
		apacheLogRequireAdmin();
	}catch (Exception $e){
		ghoti::logWarn("analytics.async.php:analyzeApacheLog", "Unauthorized Apache log access attempt from ".analyticsServerValue('REMOTE_ADDR'));
		return array('ok' => false, 'error' => 'Admin access required.');
	}
	$config = apache_log_config();
	try{
		list($path, $name) = apache_safe_log_path((string)$fileId, $config);
		return array(
			'ok' => true,
			'report' => apache_analyze_file($path, $name, $config, $config['json_entry_limit']),
		);
	}catch (ApacheLogHttpException $e){
		return array('ok' => false, 'error' => $e->getMessage());
	}catch (Throwable $e){
		ghoti::logException("analytics.async.php:analyzeApacheLog", $e);
		return array('ok' => false, 'error' => 'The selected log could not be analyzed.');
	}
}

ghoti_async_register("showAnalytics", "listApacheLogs", "analyzeApacheLog");

/* ---------------------------------------------------------------- *
 *  UI renderer (formerly analytics.ui.php / class analyticsui)
 *
 *  Renders the analytics dashboard as one HTML blob: KPI tiles, chart
 *  placeholders, a raw data table, the raw error log, and a JSON payload
 *  that mod/analytics/charts.js reads to draw the actual SVG charts
 *  client-side.
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
		$docs = ghoti_docs_panel("How to use analytics", "tabs, ranges, tiles, export, logs", array(
			array('heading' => 'The three tabs',
				'list' => array(
					'<b>Usage data</b> &mdash; who visited, which pages, which browsers and referrers.',
					'<b>Errors</b> &mdash; error-like lines from this site&rsquo;s own log, grouped by message and counted, so a fault that recurs stands out from one that happened once.',
					'<b>Server logs</b> &mdash; the raw site log, newest first, and the Apache log analyzer for the web server&rsquo;s own access and error logs.',
					'The tab you are on survives a range change, so you can compare 7d and 90d without losing your place.')),
			array('heading' => 'Choose a range',
				'list' => array('The <b>7d / 30d / 90d / 1y</b> buttons scope <b>Usage data</b> and <b>Errors</b> &mdash; and the CSV export.', 'The range does not apply to <b>Server logs</b>: a log file is whatever the server has written, and the Apache analyzer reads the file you pick.')),
			array('heading' => 'Exclude admin views',
				'list' => array('Tick the checkbox to ignore pageviews recorded while an admin was viewing, for visitor-only numbers.')),
			array('heading' => 'The tiles',
				'list' => array('<b>Pageviews</b> &mdash; total page loads tracked.', '<b>Unique sessions</b> &mdash; distinct browser sessions (a new session starts after 30 minutes of inactivity).', '<b>Visitor identification</b> &mdash; new analytics does not collect IP addresses or account identifiers. Counts include only sessions that opt in. Historical data may contain identifying fields.', '<b>Pages viewed</b> &mdash; distinct pages hit.', '<b>Avg. views/day</b> &mdash; pageviews divided by the range.')),
			array('heading' => 'CSV export',
				'list' => array('<b>Download CSV</b> opens a token-protected export of the recent-pageviews table for the current range. It exports usage data only &mdash; not the logs.')),
			array('heading' => 'Apache logs',
				'list' => array('Pick a file from the web server\u{2019}s log directory and press <b>Analyze</b> to group its entries by severity and category.', 'Tick <b>Follow live</b> before analyzing to watch a file as it is written; press <b>Stop stream</b> when you are done.', 'A row with <b>Diagnostic hints available</b> explains what usually causes that entry. <b>Download PDF</b> saves the report.', 'Read-only: this never writes to, rotates, or clears an Apache log.')),
			array('heading' => 'The log',
				'list' => array('One line per event: logins, page saves, uploads, blocked requests and errors. Newest entries appear at the top.', '<code>SECURITY:</code> lines flag legacy plaintext passwords and throttled logins &mdash; investigate and fix them. <code>denied</code> / <code>rejected</code> lines are blocked attempts (bad CSRF token, unauthorised endpoint, private page).', 'The log rotates automatically at 5MB and keeps three generations. <b>Clear log</b> empties it now.'))
		));
		$out .= "<div class=\"analytics-head\">\n";
		$out .= "<h1>Analytics</h1>\n";
		$out .= "<p class=\"analytics-sub\">Site usage for the last ".(int)$days." day".($days==1?'':'s')."</p>\n";
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
		//Three tabs, because this screen had grown into three unrelated jobs
		//stacked in one scroll: what visitors did, what broke, and what the web
		//server itself recorded. An admin chasing a 500 should not have to
		//scroll past seven charts to reach the log.
		//
		//data-analytics-tab, NOT data-report-tab: the Apache card inside the
		//Server logs panel has tabs of its own and queries that attribute.
		$out .= $this->tabStrip();
		$out .= "<div class=\"analytics-tab-panels\">\n";

		/* ---- Usage data ---- */
		$out .= $this->panelOpen('usage', 'Usage data', true);
		$out .= "<div class=\"analytics-kpis\">\n";
		$out .= $this->kpiTile('Pageviews', number_format($totalViews));
		$out .= $this->kpiTile('Unique sessions', number_format($uniqueSessions));
		$out .= $this->kpiTile('Visitor identification', 'Not collected');
		$out .= $this->kpiTile('Pages viewed', number_format($pagesViewed));
		$out .= $this->kpiTile('Avg. views/day', number_format($avgPerDay,1));
		$out .= "</div>\n";

		//Chart cards - drawn into these by charts.js after this HTML is injected.
		//Safe to draw while a panel is hidden: every chart uses a fixed viewBox
		//and never measures its container, so nothing depends on layout.
		$out .= "<div class=\"analytics-grid\">\n";
		$out .= $this->chartCard('wide','Pageviews over time','chart-byday');
		$out .= $this->chartCard('','Traffic by hour of day','chart-byhour');
		$out .= $this->chartCard('','Top pages','chart-toppages');
		$out .= $this->chartCard('','Browsers','chart-browsers');
		$out .= $this->chartCard('','Operating systems','chart-os');
		$out .= $this->chartCard('','Device type','chart-devices');
		$out .= $this->chartCard('','Top referrers','chart-referrers');
		$out .= "</div>\n";

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
		$out .= $this->panelClose();

		/* ---- Errors ---- */
		//The distilled view: what is going wrong, grouped and counted. The raw
		//log it is distilled from lives under Server logs, because reading a log
		//line by line is a different task from seeing what recurs.
		$out .= $this->panelOpen('errors', 'Errors', false);
		$out .= $this->logErrorsCard($topErrors,$days);
		$out .= $this->panelClose();

		/* ---- Server logs ---- */
		$out .= $this->panelOpen('logs', 'Server logs', false);
		$out .= $this->rawLogCard();
		$out .= $this->apacheLogCard();
		$out .= $this->panelClose();

		$out .= "</div>\n"; //analytics-tab-panels

		$out .= $docs;
		$out .= "<script type=\"application/json\" id=\"analyticsData\">".json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)."</script>\n";
		$out .= "</div>\n"; //ghotiAnalytics

		return $out;
	}

	/* The three dashboard views. Kept in one place so the strip and the panels
	 * cannot drift apart - a tab pointing at a panel id that does not exist is
	 * a tab that does nothing, and nothing about the page would say why. */
	public static function tabs(){
		return array(
			array('usage',  'Usage data',  'Visitors, pages, referrers'),
			array('errors', 'Errors',      'What is going wrong, grouped'),
			array('logs',   'Server logs', 'The raw log and the Apache analyzer'),
		);
	}

	private function tabStrip(){
		$out = "<div class=\"analytics-tabs\" role=\"tablist\" aria-label=\"Analytics views\">\n";
		foreach(self::tabs() as $i => $tab){
			$active = $i === 0;
			//The first tab is always the one marked active in the markup; the
			//client switches to whichever the admin was on, in the same frame,
			//so a range change does not throw them back to Usage data.
			$out .= "<button type=\"button\" class=\"analytics-tab".($active ? " is-active" : "")."\""
				." id=\"analyticsTab-".$tab[0]."\" role=\"tab\" aria-selected=\"".($active ? "true" : "false")."\""
				." aria-controls=\"analyticsPanel-".$tab[0]."\" data-analytics-tab=\"".$tab[0]."\""
				.($active ? "" : " tabindex=\"-1\"").">"
				."<span class=\"analytics-tab-name\">".htmlspecialchars($tab[1], ENT_QUOTES)."</span>"
				."<span class=\"analytics-tab-hint\">".htmlspecialchars($tab[2], ENT_QUOTES)."</span></button>\n";
		}
		$out .= "</div>\n";
		return $out;
	}

	private function panelOpen($id, $label, $active){
		return "<section class=\"analytics-tab-panel\" id=\"analyticsPanel-".$id."\" role=\"tabpanel\""
			." aria-labelledby=\"analyticsTab-".$id."\" data-analytics-panel=\"".$id."\""
			.($active ? "" : " hidden=\"hidden\"").">\n";
	}

	private function panelClose(){
		return "</section>\n";
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

	private function rawLogCard(){
		//Read + reverse in PHP. The old `tail -r ghoti.log` only exists on BSD/macOS
		//(GNU/Linux tail has no -r), so this view was broken on the Linux servers
		//this actually runs on. htmlspecialchars() stops logged user input (e.g. a
		//crafted username) from injecting HTML into the admin log view.
		$logPath = ghoti::$ghotiLog;
		$lines = is_file($logPath) ? file($logPath, FILE_IGNORE_NEW_LINES) : array();
		if(!is_array($lines)){ $lines = array(); }
		$logText = htmlspecialchars(implode("\n", array_reverse($lines)), ENT_QUOTES);

		$out  = "<div class=\"card analytics-card analytics-log-card wide\">\n";
		$out .= "<h2>Log <span class=\"analytics-muted\">reverse chronological</span></h2>\n";
		$out .= "<pre class=\"analytics-log-raw\">".$logText."</pre>\n";
		$out .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" onclick=\"clearGhotiLog();\">Clear log</button>\n";
		$out .= "</div>\n";
		return $out;
	}

	/*
	 * Apache log analyzer.
	 *
	 * Only the shell is rendered here. The report itself is drawn by
	 * mod/analytics/apachelog.js from the payload the listApacheLogs /
	 * analyzeApacheLog endpoints return - and redrawn from the live stream,
	 * which pushes the same shape. One renderer, two sources; server-rendering
	 * the report would mean writing it twice.
	 *
	 * The ids below are read by apachelog.js. The PDF link and the live stream
	 * carry the session CSRF token because both are plain navigable URLs
	 * outside the async layer, exactly like the CSV export above; the client
	 * re-uses the same token from GHOTI_CSRF_TOKEN when it fills in the file.
	 */
	private function apacheLogCard(){
		$token = rawurlencode(ghoti_csrf_token());
		$out  = "<div class=\"card analytics-card wide\" id=\"ghotiApacheLog\">\n";
		$out .= "<h2>Apache logs <span class=\"analytics-muted\">web server access and error logs</span></h2>\n";
		$out .= "<p class=\"analytics-sub\">Parses the server\u{2019}s own logs, groups entries by severity and category, and suggests where to look first. Read-only: nothing here writes to or clears a log file.</p>\n";

		//Controls
		$out .= "<div class=\"control-panel\">\n<div class=\"control-grid\">\n";
		$out .= "<label class=\"field\"><span id=\"apacheLogSourceTitle\">Log file</span><select id=\"apacheLogFileSelect\"><option value=\"\">Loading log files\u{2026}</option></select></label>\n";
		$out .= "<label class=\"toggle\"><input id=\"apacheFollowToggle\" type=\"checkbox\" /> <span>Follow live</span></label>\n";
		$out .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" id=\"apacheRefreshButton\">Refresh list</button>\n";
		$out .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact\" id=\"apacheAnalyzeButton\" disabled=\"disabled\">Analyze</button>\n";
		$out .= "</div>\n";
		$out .= "<div class=\"control-meta\"><span id=\"apacheLogDir\" class=\"analytics-muted\"></span><button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonDanger\" id=\"apacheStopButton\" hidden=\"hidden\">Stop stream</button></div>\n";
		$out .= "<div id=\"apacheStatus\" class=\"status\" role=\"status\" aria-live=\"polite\" hidden=\"hidden\"></div>\n";
		$out .= "</div>\n";

		//Report
		$out .= "<section id=\"apacheReport\" hidden=\"hidden\">\n";
		$out .= "<div class=\"report-head\"><div><h3 id=\"apacheReportTitle\">Analysis report</h3><p class=\"file-meta\" id=\"apacheFileMeta\"></p></div>";
		$out .= "<div class=\"actions\"><button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" id=\"apacheReloadButton\">Restart analysis</button>";
		$out .= "<a class=\"ghotiButton ghotiButtonCompact\" id=\"apacheExportButton\" href=\"mod/analytics/apachelog.export.php?token=".$token."\" target=\"_blank\" rel=\"noopener\" hidden=\"hidden\">&#8681; Download PDF</a></div></div>\n";

		$tabs = array(
			array('overview','Analysis report'),
			array('charts','Charts'),
			array('recommendations','Recommended next steps'),
			array('entries','Recent log entries'),
		);
		$out .= "<div class=\"report-tabs\" role=\"tablist\" aria-label=\"Report views\">\n";
		foreach($tabs as $i => $tab){
			$active = $i === 0;
			$out .= "<button type=\"button\" class=\"report-tab".($active ? " is-active" : "")."\" id=\"apacheReportTab-".$tab[0]."\" role=\"tab\" aria-selected=\"".($active ? "true" : "false")."\" aria-controls=\"apacheReportPanel-".$tab[0]."\" data-report-tab=\"".$tab[0]."\"".($active ? "" : " tabindex=\"-1\"").">".$tab[1]."</button>\n";
		}
		$out .= "</div>\n<div class=\"report-tab-panels\">\n";

		$out .= "<section class=\"report-tab-panel\" id=\"apacheReportPanel-overview\" role=\"tabpanel\" aria-labelledby=\"apacheReportTab-overview\" data-report-panel=\"overview\">\n";
		$out .= "<div class=\"metric-grid\" id=\"apacheMetrics\"></div>\n";
		$out .= "<div class=\"panel wide-section\"><h4>Entries by category</h4><div id=\"apacheCategoryChart\"></div></div>\n</section>\n";

		$out .= "<section class=\"report-tab-panel charts-grid\" id=\"apacheReportPanel-charts\" role=\"tabpanel\" aria-labelledby=\"apacheReportTab-charts\" data-report-panel=\"charts\" hidden=\"hidden\">\n";
		$out .= "<div class=\"panel\"><h4>Severity mix</h4><div id=\"apacheSeverityRing\"></div></div>\n";
		$out .= "<div class=\"panel\"><h4>Entries over time</h4><div id=\"apacheTimelineScatter\"></div></div>\n</section>\n";

		$out .= "<section class=\"report-tab-panel\" id=\"apacheReportPanel-recommendations\" role=\"tabpanel\" aria-labelledby=\"apacheReportTab-recommendations\" data-report-panel=\"recommendations\" hidden=\"hidden\">\n";
		$out .= "<div class=\"panel wide-section\"><h4>Recommended next steps</h4><ol class=\"recommendations\" id=\"apacheRecommendations\"></ol></div>\n</section>\n";

		$out .= "<section class=\"report-tab-panel\" id=\"apacheReportPanel-entries\" role=\"tabpanel\" aria-labelledby=\"apacheReportTab-entries\" data-report-panel=\"entries\" hidden=\"hidden\">\n";
		$out .= "<div class=\"panel details\"><div class=\"details-head\"><h4>Recent log entries</h4>\n";
		$out .= "<div class=\"filters\"><label><span class=\"sr-only\">Search entries</span><input id=\"apacheSearchInput\" type=\"search\" placeholder=\"Search messages, clients, codes\" /></label>";
		$out .= "<label><span class=\"sr-only\">Filter by severity</span><select id=\"apacheSeverityFilter\"><option value=\"all\">All severities</option></select></label></div></div>\n";
		$out .= "<div class=\"table-wrap\"><table><thead><tr><th>Time</th><th>Level</th><th>Category</th><th>Source</th><th>Message</th></tr></thead><tbody id=\"apacheEntryRows\"></tbody></table></div>\n";
		$out .= "<p class=\"table-footer\" id=\"apacheTableFooter\"></p></div>\n</section>\n";

		$out .= "</div>\n</section>\n";
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
