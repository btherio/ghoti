<?php
/*
 * seo.php - serves robots.txt and sitemap.xml (Site Settings -> SEO).
 *
 *   /robots.txt   -> seo.php?file=robots   (rewrite in .htaccess)
 *   /sitemap.xml  -> seo.php?file=sitemap
 *
 * Public and read-only: no session, no login, nothing but the public page
 * list. The content is built in ghoti.seo.php.
 */
require_once __DIR__.'/ghoti.php';
ghoti::loadSettings();
ghoti_security_enforce_ip_access();

header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=3600');

$file = $_GET['file'] ?? '';

if($file === 'robots'){
	header('Content-Type: text/plain; charset=utf-8');
	echo ghoti_seo_robots_txt();
	exit;
}

if($file === 'sitemap'){
	$pages = array();
	if(ghotidb::isConfigured()){
		$rows = (new ghotidb())->getPageManagementList();
		if(is_array($rows)){ $pages = $rows; }
	}
	$xml = ghoti_seo_sitemap_xml($pages);
	if($xml === null){
		http_response_code(404);
		header('Content-Type: text/plain; charset=utf-8');
		echo "No sitemap: set the Site URL under Site Settings -> SEO, and allow indexing.\n";
		exit;
	}
	header('Content-Type: application/xml; charset=utf-8');
	echo $xml;
	exit;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Not found.\n";
