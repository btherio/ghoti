<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
// Run: php tests/privacy.php — no real database, cookies, or settings writes.
require_once __DIR__.'/../ghoti.php';
require_once __DIR__.'/../mod/analytics/analytics.async.php';
require_once __DIR__.'/../mod/analytics/analytics.db.php';
function expectPrivacy($condition, $message){ if(!$condition){ throw new RuntimeException($message); } }
class PrivacyPageFixture {
    public $groups = array();
    function getPageList($group){
        $this->groups[] = $group;
        return $group === 'public' ? array(array(1, 'Public <page>')) : array(array(2, 'Member secret'));
    }
}
class PrivacyAnalyticsFixture extends analyticsdb {
    public $inserts = array();
    function __construct(){}
    protected function queryArray($sql, array $params = array()){ return array(array('Example page')); }
    protected function query($sql, array $params = array()){ $this->inserts[] = $params; return true; }
}
$rows = array(array(1, 'Public <page>', 'public'), array(2, 'Member secret', 'private'), array(3, 'Unknown secret', 'unknown'), array(4, 'Missing permission'));
$_SESSION = array();
$guest = ghoti_sitemap_rows($rows);
expectPrivacy(str_contains($guest, 'Public &lt;page&gt;'), 'Titles must be HTML escaped');
expectPrivacy(!str_contains($guest, 'secret') && !str_contains($guest, 'Missing'), 'Guest sitemap leaks restricted titles');
$_SESSION = array('loggedIn'=>true, 'userId'=>10);
$member = ghoti_sitemap_rows($rows);
expectPrivacy(str_contains($member, 'Member secret'), 'Members cannot find their pages');
expectPrivacy(!str_contains($member, 'Unknown') && !str_contains($member, 'Missing'), 'Unknown groups must fail closed');
foreach(array(false, true) as $loggedIn){
    $db = new PrivacyPageFixture();
    $_SESSION = array('loggedIn'=>$loggedIn, 'userId'=>10, 'ghotiObj'=>(object)array('ghotidb'=>$db));
    ghoti_sitemap();
    expectPrivacy($db->groups === ($loggedIn ? array('public','private') : array('public')), 'Sitemap queried unauthorized groups');
}
$db = new PrivacyAnalyticsFixture();
$_SESSION = array('pageId'=>1, 'userId'=>99, 'analyticsObj'=>(object)array('analyticsdb'=>$db));
$_SERVER = array('REMOTE_ADDR'=>'192.0.2.4', 'HTTP_USER_AGENT'=>'Mozilla Firefox/100 Linux', 'HTTP_REFERER'=>'https://example.test/private?token=secret', 'REQUEST_URI'=>'/?password=secret');
trackPageView();
expectPrivacy(count($db->inserts) === 0, 'Analytics ran before consent');
expectPrivacy(setPrivacyChoice('true') === 'Invalid privacy choice.', 'Consent requires a boolean');
setPrivacyChoice(true); trackPageView();
expectPrivacy(count($db->inserts) === 1, 'Opt-in did not enable analytics');
$event = $db->inserts[0];
expectPrivacy(strlen($event[2]) === 32 && $event[2] !== session_id(), 'Login session identifier reused');
expectPrivacy($event[3] === null && $event[4] === '' && $event[5] === '', 'Identifying fields collected');
expectPrivacy($event[9] === 'example.test' && $event[10] === '', 'URL path or query collected');
setPrivacyChoice(false); trackPageView();
expectPrivacy(count($db->inserts) === 1 && !isset($_SESSION['analyticsVisitorId']), 'Withdrawal did not stop tracking');
foreach(array('HTTP_SEC_GPC','HTTP_DNT') as $signal){
    $_SERVER[$signal] = '1'; setPrivacyChoice(true); trackPageView();
    expectPrivacy(count($db->inserts) === 1, 'Privacy signal ignored');
    unset($_SERVER[$signal]);
}
$_SESSION = array(); expectPrivacy(!ghoti_analytics_allowed(), 'Consent carried into another session');
echo "PASS: sitemap permissions, escaped titles, authorized queries, opt-in, withdrawal, GPC/DNT, and minimized analytics payload\n";
