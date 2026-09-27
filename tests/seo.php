<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
/*
 * Run: php tests/seo.php - no database, no browser, no network.
 *
 * Site Settings -> SEO. What must hold:
 *   - absolute URLs come only from the configured Site URL / GHOTI_PUBLIC_URL,
 *     never the Host header, and are left out when neither is set,
 *   - a private page's title never reaches <head>,
 *   - "indexing off" marks pages noindex but does not Disallow crawling,
 *   - JSON-LD cannot be broken out of its <script> by a site name,
 *   - the sitemap lists public pages only, the home page once,
 *   - settings refuse bad values instead of dropping them,
 *   - every theme's <title> goes through the SEO helper.
 */
chdir(dirname(__DIR__));
require_once 'ghoti.php';

$checks = 0;
function seoCheck($ok, $label){
	global $checks; $checks++;
	if(!$ok){ throw new RuntimeException($label); }
}

class SeoPagesFake{
	public $pages = array(6 => array('About', 'public'), 9 => array('Members lounge', 'private'));
	public function getPageById($id){
		return isset($this->pages[$id]) ? array(array('content', $this->pages[$id][0], $this->pages[$id][1])) : array();
	}
}
$db = new SeoPagesFake();

putenv('GHOTI_PUBLIC_URL');
$_SERVER['HTTP_HOST'] = 'evil.example';
ghoti::$siteTitle = 'Seo Site';
ghoti::$seoSiteUrl = '';
ghoti::$seoAllowIndexing = true;
ghoti::$seoStructuredData = true;

/* ---- no base URL: no absolute URLs, and nothing from Host ---- */
$home = ghoti_seo_page_context(array(), $db);
$tags = ghoti_seo_head_tags($home);
seoCheck(strpos($tags, 'canonical') === false && strpos($tags, 'og:url') === false && strpos($tags, 'ld+json') === false, 'absolute-URL tags were emitted without a base URL');
seoCheck(strpos($tags, 'evil.example') === false, 'the Host header leaked into metadata');
seoCheck(ghoti_seo_sitemap_xml(array()) === null, 'a sitemap was produced without a base URL');
seoCheck(strpos(ghoti_seo_robots_txt(), 'Sitemap:') === false, 'robots.txt named a sitemap without a base URL');

putenv('GHOTI_PUBLIC_URL=https://env.example/site');
seoCheck(ghoti_seo_base_url() === 'https://env.example/site', 'GHOTI_PUBLIC_URL was not used as the fallback');
seoCheck(strpos(ghoti_seo_robots_txt(), 'Disallow: /site/password-reset.php') !== false, 'robots.txt paths ignore the install path');
seoCheck(strpos(ghoti_seo_robots_txt(), 'seo.php') === false, 'robots.txt blocks seo.php, the fallback sitemap address');
ghoti::$seoSiteUrl = 'https://www.example.com';
seoCheck(ghoti_seo_base_url() === 'https://www.example.com', 'the Site URL setting did not win over the environment');

/* ---- titles and context ---- */
$page = ghoti_seo_page_context(array('page' => '6'), $db);
seoCheck($page['kind'] === 'page' && ghoti_seo_title($page) === 'About | Seo Site', 'a public page title is wrong: '.ghoti_seo_title($page));
ghoti::$seoTitleFormat = '{site} - {page}';
seoCheck(ghoti_seo_title($page) === 'Seo Site - About', 'the title format was not applied');
ghoti::$seoTitleFormat = '{page} | {site}';
seoCheck(ghoti_seo_title($home) === 'Seo Site', 'the home title is not the site title');
ghoti::$seoHomeTitle = 'Seo Site - handmade things';
seoCheck(ghoti_seo_title($home) === 'Seo Site - handmade things', 'the home title override was ignored');
ghoti::$seoHomeTitle = '';

$private = ghoti_seo_page_context(array('page' => '9'), $db);
$privateTags = ghoti_seo_head_tags($private);
seoCheck(strpos(ghoti_seo_title($private).$privateTags, 'Members lounge') === false, 'a private page title reached <head>');
seoCheck(strpos($privateTags, 'noindex') !== false && strpos($privateTags, 'canonical') === false, 'a private page was indexable');
seoCheck(ghoti_seo_page_context(array('page' => '6 or 1=1'), $db)['kind'] === 'home', 'a junk page id was looked up');
seoCheck(ghoti_seo_page_context(array('view' => 'sitemap'), $db)['kind'] === 'view' && !ghoti_seo_indexable(ghoti_seo_page_context(array('view' => 'sitemap'), $db)), 'the HTML sitemap view is indexable');

/* ---- head tags with a base URL ---- */
ghoti::$seoDescription = 'Plain description';
ghoti::$seoShareImage = 'gfx/ghoti-logo.png';
ghoti::$seoTwitterHandle = 'example';
ghoti::$seoGoogleVerification = 'abc_123';
$tags = ghoti_seo_head_tags($page);
seoCheck(strpos($tags, '<link rel="canonical" href="https://www.example.com/?page=6" />') !== false, 'canonical is wrong');
seoCheck(strpos($tags, 'og:image" content="https://www.example.com/gfx/ghoti-logo.png"') !== false, 'og:image is not absolute');
seoCheck(strpos($tags, 'twitter:site" content="@example"') !== false, 'twitter:site is wrong');
seoCheck(strpos($tags, 'google-site-verification" content="abc_123"') !== false, 'google verification missing');
seoCheck(strpos($tags, 'name="description" content="Plain description"') !== false, 'description missing');
seoCheck(strpos($tags, 'index, follow') !== false, 'an indexable page is not marked index');

ghoti::$siteTitle = 'Evil</script><script>alert(1)</script>';
$tags = ghoti_seo_head_tags($home);
seoCheck(preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $tags, $ld) === 1, 'no JSON-LD emitted');
seoCheck(stripos($ld[1], '</script') === false && stripos($ld[1], '<script') === false, 'the site name broke out of the JSON-LD script');
seoCheck(is_array(json_decode($ld[1], true)), 'JSON-LD is not valid JSON');
seoCheck(strpos($tags, '<script>alert') === false, 'the site name was not escaped in meta tags');
ghoti::$siteTitle = 'Seo Site';

/* ---- indexing off ---- */
ghoti::$seoAllowIndexing = false;
$tags = ghoti_seo_head_tags($page);
seoCheck(strpos($tags, 'noindex') !== false && strpos($tags, 'canonical') === false, 'indexing off did not mark noindex');
$robots = ghoti_seo_robots_txt();
seoCheck(strpos($robots, "Disallow: /\n") === false, 'indexing off disallowed crawling, which hides the noindex');
seoCheck(ghoti_seo_sitemap_xml(array()) === null, 'a sitemap was produced with indexing off');
seoCheck(strpos(file_get_contents('index.php'), '!ghoti::$seoAllowIndexing') !== false, 'index.php does not send X-Robots-Tag when indexing is off');
ghoti::$seoAllowIndexing = true;

/* ---- robots extras and sitemap ---- */
ghoti::$seoRobotsExtra = "User-agent: GPTBot\nDisallow: /";
seoCheck(strpos(ghoti_seo_robots_txt(), "User-agent: GPTBot\nDisallow: /") !== false, 'extra robots rules were not included');
$xml = ghoti_seo_sitemap_xml(array(
	array(1, 'Home', 'public', 0, 1),
	array(6, 'About', 'public', 1, 0),
	array(9, 'Members', 'private', 2, 0),
));
seoCheck(strpos($xml, '<loc>https://www.example.com/</loc>') !== false, 'the sitemap is missing the home page');
seoCheck(strpos($xml, '?page=6') !== false, 'the sitemap is missing a public page');
seoCheck(strpos($xml, '?page=9') === false, 'the sitemap lists a private page');
seoCheck(strpos($xml, '?page=1<') === false, 'the home page is listed twice');
seoCheck(simplexml_load_string($xml) !== false, 'the sitemap is not valid XML');

/* ---- settings refuse bad values ---- */
//Never the real file: if a refusal regressed, the save would land here.
ghoti::$settingsFile = 'ghoti.settings.seo-test-'.getmypid().'.json';
register_shutdown_function(function(){ @unlink(ghoti::settingsPath()); });
$refusals = array(
	'seoSiteUrl' => 'http://insecure.example',
	'seoGoogleVerification' => '<meta name="google-site-verification" content="x">',
	'seoTwitterHandle' => 'way_too_long_for_twitter',
	'seoRobotsExtra' => "<?php echo 1; ?>",
	'seoShareImage' => '../../etc/passwd',
);
foreach($refusals as $key => $value){
	$result = ghoti::saveSettings(array($key => $value));
	seoCheck(is_string($result), "$key accepted a bad value");
}

/* ---- every tracked theme's <title> goes through the helper ---- */
foreach(array('cyber','ghoticms','ironhide','mahogany','prosimii','smurfius','spore','veil') as $theme){
	$src = file_get_contents("css/$theme/$theme.php");
	seoCheck(strpos($src, '<title><?php echo ghoti_seo_title_html(); ?></title>') !== false, "$theme does not use the SEO title");
	seoCheck(substr_count($src, 'name="description"') <= 1 && (strpos($src, 'name="description"') === false || strpos($src, "ghoti::\$seoDescription === ''") !== false), "$theme can emit two descriptions");
}
seoCheck(strpos(file_get_contents('ghoti.header.php'), 'ghoti_seo_head_tags(') !== false, 'the header does not emit SEO tags');
seoCheck(strpos((new ghotiui())->printPageList(array(array(6, 'About'))), 'href="?page=6"') !== false, 'menu links are not crawlable');

echo "PASS: $checks seo assertions\n";
