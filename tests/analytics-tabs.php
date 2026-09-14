<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
/*
 * Run: php tests/analytics-tabs.php - no database, no browser, no network.
 *
 * The dashboard was one long scroll and is now three tabs. The regression a
 * refactor like that invites is not a broken tab - that is visible the moment
 * anyone opens the screen - it is a card that quietly stops being rendered at
 * all, because it was dropped between two panels and nothing scrolls past its
 * absence. So the assertions below check that every card the old dashboard had
 * is present, exactly once, in exactly one panel.
 *
 * The dashboard is rendered against a fake database: this host has no driver,
 * and none of what is being checked here needs one.
 */
chdir(dirname(__DIR__));
require_once 'ghoti.php';
require_once 'mod/analytics/analytics.async.php';

$checks = 0;
function analyticsCheck($ok, $label){
	global $checks; $checks++;
	if(!$ok){ throw new RuntimeException($label); }
}

function isAdmin($userId){ return true; }

/* A stand-in for analyticsdb: every method printDashboard() calls, each
 * returning the shape the renderer expects and nothing more. */
class AnalyticsDbFake{
	public function getSummary($days, $excludeAdmin){ return array(1234, 567, 89, 42); }
	public function getPageviewsByDay($days, $excludeAdmin){ return array(array('2026-09-01', 12), array('2026-09-02', 30)); }
	public function getHourlyDistribution($days, $excludeAdmin){ return array(array(9, 5), array(14, 22)); }
	public function getTopPages($limit, $days, $excludeAdmin){ return array(array('Home', 1, 400)); }
	public function getBreakdown($field, $days, $excludeAdmin){ return array(array('Firefox', 120)); }
	public function getTopReferrers($limit, $days, $excludeAdmin){ return array('example.com' => 9); }
	public function getRecentPageviews($limit, $days){
		return array(array('2026-09-13 10:00:00', 'Home', '', '', '', '', 'https://example.com/x'));
	}
}
class AnalyticsObjFake{ public $analyticsdb; public function __construct(){ $this->analyticsdb = new AnalyticsDbFake(); } }

$_SESSION = array('analyticsObj' => new AnalyticsObjFake(), 'loggedIn' => true, 'userId' => 1);
$html = (new analyticsui())->printDashboard(30, true);

/* ---------------- the three tabs exist and are wired ---------------- */

$tabs = analyticsui::tabs();
analyticsCheck(count($tabs) === 3, 'Expected exactly three dashboard tabs');
analyticsCheck(array_column($tabs, 0) === array('usage','errors','logs'), 'The tab ids changed: '.implode(',', array_column($tabs, 0)));
analyticsCheck(array_column($tabs, 1) === array('Usage data','Errors','Server logs'), 'The tab labels changed: '.implode(',', array_column($tabs, 1)));

foreach($tabs as $tab){
	list($id, $label) = $tab;
	//A tab whose panel id does not exist is a tab that does nothing, and
	//nothing on the page would say why.
	analyticsCheck(substr_count($html, 'id="analyticsTab-'.$id.'"') === 1, "Tab $id is missing or duplicated");
	analyticsCheck(substr_count($html, 'id="analyticsPanel-'.$id.'"') === 1, "Panel $id is missing or duplicated");
	analyticsCheck(strpos($html, 'aria-controls="analyticsPanel-'.$id.'"') !== false, "Tab $id does not point at its panel");
	analyticsCheck(strpos($html, 'aria-labelledby="analyticsTab-'.$id.'"') !== false, "Panel $id is not labelled by its tab");
	analyticsCheck(strpos($html, htmlspecialchars($label, ENT_QUOTES)) !== false, "Tab $id has no visible label");
}
//Two tablists on the page: the dashboard's, and the Apache report's inside the
//Server logs panel. They are told apart by their labels, not their count.
analyticsCheck(substr_count($html, 'aria-label="Analytics views"') === 1, 'Expected one dashboard tablist');
analyticsCheck(substr_count($html, 'aria-label="Report views"') === 1, 'The Apache report tablist went missing');
analyticsCheck(substr_count($html, 'data-analytics-tab=') === 3, 'Expected three tab buttons');
analyticsCheck(substr_count($html, 'data-analytics-panel=') === 3, 'Expected three tab panels');

//Exactly one tab is selected and exactly two panels are hidden, or the screen
//opens either blank or showing everything at once. Counted inside the dashboard
//strip only: the Apache report has a selected tab of its own.
$stripStart = strpos($html, 'aria-label="Analytics views"');
$strip = substr($html, $stripStart, strpos($html, '</div>', $stripStart) - $stripStart);
analyticsCheck(substr_count($strip, 'aria-selected="true"') === 1, 'Expected exactly one selected dashboard tab');
analyticsCheck(substr_count($strip, 'aria-selected="false"') === 2, 'Expected the other two dashboard tabs to be unselected');
analyticsCheck(substr_count($strip, 'data-analytics-tab=') === 3, 'The dashboard strip does not hold all three tabs');
analyticsCheck(substr_count($html, 'data-analytics-panel="usage"') === 1 && strpos($html, 'data-analytics-panel="usage" hidden') === false, 'The first panel is not the visible one');
analyticsCheck(substr_count($html, 'data-analytics-panel="errors" hidden="hidden"') === 1, 'The errors panel is not hidden on arrival');
analyticsCheck(substr_count($html, 'data-analytics-panel="logs" hidden="hidden"') === 1, 'The server logs panel is not hidden on arrival');
//Roving tabindex: the inactive tabs are skipped by Tab, reachable by arrow keys.
analyticsCheck(substr_count($strip, 'tabindex="-1"') === 2, 'Inactive dashboard tabs are still in the tab order');

/* ---------------- the attribute names cannot collide ---------------- */

//The Apache card lives inside the Server logs panel and has tabs of its own,
//queried by [data-report-tab]. If the dashboard used the same attribute, the
//Apache panel's initializer would pick up the dashboard's tabs as its own.
analyticsCheck(strpos($html, 'data-report-tab=') !== false, 'The Apache report tabs are gone, so this collision check proves nothing');
analyticsCheck(strpos($html, 'data-analytics-tab=') !== false, 'The dashboard tabs are missing');
analyticsCheck(strpos($html, 'data-analytics-tab="overview"') === false, 'The dashboard and the Apache report share a tab namespace');
$js = file_get_contents('mod/analytics/charts.js');
analyticsCheck(strpos($js, '[data-analytics-tab]') !== false, 'charts.js does not query the dashboard tabs');
//A mention in a comment is fine; a query is not.
analyticsCheck(strpos($js, "querySelectorAll('[data-report-tab]')") === false, 'charts.js queries the Apache report tabs');
$apacheJs = file_get_contents('mod/analytics/apachelog.js');
analyticsCheck(strpos($apacheJs, "querySelectorAll('[data-analytics-tab]')") === false, 'apachelog.js queries the dashboard tabs');
analyticsCheck(strpos($apacheJs, "querySelectorAll('[data-report-tab]')") !== false, 'apachelog.js no longer finds its own tabs');

//Likewise for the stylesheets, which load together.
//Comments stripped first: each file explains the other's naming, and a mention
//in prose is not a selector.
$rules = function($file){ return preg_replace('#/\*.*?\*/#s', '', file_get_contents($file)); };
$css = $rules('mod/analytics/analytics.css');
$apacheCss = $rules('mod/analytics/apachelog.css');
analyticsCheck(strpos($css, '.analytics-tab') !== false, 'The tab strip has no styles');
analyticsCheck(strpos($css, '.report-tab') === false, 'analytics.css styles the Apache report tabs');
analyticsCheck(strpos($apacheCss, '.analytics-tab') === false, 'apachelog.css styles the dashboard tabs');
analyticsCheck(strpos($apacheCss, '.report-tab') !== false, 'apachelog.css no longer styles its own tabs');

/* ---------------- nothing was dropped ---------------- */

//Every card the dashboard had before the split, and which panel it must now be
//in. This is the assertion that catches a card lost between two panels.
function analyticsPanelOf($html, $needle){
	$at = strpos($html, $needle);
	if($at === false){ return null; }
	//Bounded to the panels container. Without the upper bound, anything
	//rendered after the last panel closes would walk back to it and report as
	//'logs' - so a card that had fallen out of the tabs entirely would look
	//correctly filed.
	$open = strpos($html, '<div class="analytics-tab-panels">');
	$close = strrpos($html, '</section>');
	if($open === false || $at < $open || $at > $close){ return 'outside'; }
	$before = substr($html, 0, $at);
	$last = strrpos($before, 'data-analytics-panel="');
	if($last === false){ return 'outside'; }
	$rest = substr($before, $last + strlen('data-analytics-panel="'));
	return substr($rest, 0, strpos($rest, '"'));
}

$expected = array(
	//Usage data
	'chart-byday'          => 'usage',
	'chart-byhour'         => 'usage',
	'chart-toppages'       => 'usage',
	'chart-browsers'       => 'usage',
	'chart-os'             => 'usage',
	'chart-devices'        => 'usage',
	'chart-referrers'      => 'usage',
	'analytics-kpis'       => 'usage',
	'Recent pageviews'     => 'usage',
	//Errors
	'Top log errors'       => 'errors',
	//Server logs
	'analytics-log-raw'    => 'logs',
	'ghotiApacheLog'       => 'logs',
	'apacheAnalyzeButton'  => 'logs',
	'clearGhotiLog'        => 'logs',
);
foreach($expected as $needle => $panel){
	analyticsCheck(strpos($html, $needle) !== false, "The dashboard lost '$needle' entirely");
	$found = analyticsPanelOf($html, $needle);
	analyticsCheck($found === $panel, "'$needle' should be in the $panel panel, found in: ".var_export($found, true));
}

//The helper has to be able to return 'outside', or every assertion above is
//satisfied by a card that fell out of the tabs entirely.
analyticsCheck(analyticsPanelOf($html, 'analytics-toolbar') === 'outside', 'The panel locator cannot detect content outside the tabs');
analyticsCheck(analyticsPanelOf($html, 'no-such-marker-anywhere') === null, 'The panel locator does not report missing content');

//Each chart container appears once: a duplicate id means charts.js draws into
//whichever the browser found first and silently leaves the other empty.
foreach(array('chart-byday','chart-byhour','chart-toppages','chart-browsers','chart-os','chart-devices','chart-referrers') as $chart){
	analyticsCheck(substr_count($html, 'id="'.$chart.'"') === 1, "Chart $chart is rendered more than once");
}

//The toolbar stays above the tabs: the range scopes Usage and Errors alike, so
//it cannot live inside one of them.
$stripAt = strpos($html, 'role="tablist"');
analyticsCheck(strpos($html, 'analytics-toolbar') < $stripAt, 'The range toolbar moved inside a tab');
analyticsCheck(strpos($html, 'setAnalyticsRange') < $stripAt, 'The range buttons moved inside a tab');
analyticsCheck(strpos($html, 'analytics.export.php') < $stripAt, 'The CSV export moved inside a tab');
//The docs panel stays outside, below: it describes all three tabs.
analyticsCheck(analyticsPanelOf($html, 'How to use analytics') === 'outside', 'The docs panel was absorbed into a tab');
analyticsCheck(strpos($html, 'The three tabs') !== false, 'The docs panel does not explain the tabs');

//The chart payload is still emitted, and after the panels, so charts.js can
//find it however the panels are arranged.
analyticsCheck(substr_count($html, 'id="analyticsData"') === 1, 'The chart payload is missing');

/* ---------------- the client keeps the admin in place ---------------- */

//Changing the range re-renders the whole dashboard through printPage(), and the
//server always marks Usage data active. Without a restored tab, an admin
//reading Errors who clicks 7d is thrown back to the charts.
analyticsCheck(strpos($js, "tab: 'usage'") !== false, 'The dashboard tab is not part of the analytics state');
analyticsCheck(strpos($js, 'ANALYTICS_STATE.tab = name') !== false, 'Switching tabs does not record the choice');
$cb = substr($js, strpos($js, 'function renderAnalytics_cb'), 900);
analyticsCheck(strpos($cb, 'initAnalyticsTabs()') !== false, 'The tabs are not re-initialized after a re-render');
analyticsCheck(strpos($cb, 'initAnalyticsTabs()') < strpos($cb, 'drawAllAnalyticsCharts()'), 'The tab is restored after the charts are drawn, so the wrong panel would flash');
analyticsCheck(strpos($cb, 'initApacheLogPanel') !== false, 'The Apache panel is no longer re-wired on render');
analyticsCheck(substr_count($js, 'initApacheLogPanel()') === 1, 'The Apache panel is initialized more than once per render, which double-binds its listeners');
//Keyboard support is the part of a tablist that is easiest to leave out.
foreach(array('ArrowRight','ArrowLeft','Home','End') as $key){
	analyticsCheck(strpos($js, "'".$key."'") !== false, "The tab strip does not handle $key");
}
analyticsCheck(strpos($js, "setAttribute('tabindex'") !== false, 'The tab strip has no roving tabindex');
analyticsCheck(strpos($js, 'panel.hidden =') !== false, 'Panels are not toggled with the hidden attribute');

echo "PASS: $checks analytics tab assertions; no database, no browser, no network\n";
