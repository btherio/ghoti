<?php
/*
 * ghoti.seo.php - search and sharing metadata (Site Settings -> SEO).
 *
 * Three outputs, all built from the SEO settings on the ghoti class:
 *
 *   ghoti_seo_head_tags()  - <meta>/<link>/JSON-LD for ghoti.header.php, which
 *                            every theme includes inside <head>
 *   ghoti_seo_robots_txt() - served by seo.php as /robots.txt
 *   ghoti_seo_sitemap_xml()- served by seo.php as /sitemap.xml
 *
 * Absolute URLs (canonical, og:url, og:image, the sitemap) are built ONLY from
 * the configured Site URL, or GHOTI_PUBLIC_URL when that is blank - never from
 * the request's Host header, which a client controls. With neither set those
 * tags are left out rather than guessed.
 *
 * Every function here except ghoti_seo_page_context() is pure over its
 * arguments and the ghoti:: settings, so tests can drive them without a
 * database or a request.
 */

//The site's public base URL with no trailing slash, or '' when unknown.
function ghoti_seo_base_url(){
	$url = ghoti::$seoSiteUrl;
	if($url === ''){
		$env = getenv('GHOTI_PUBLIC_URL');
		$url = is_string($env) ? ghoti_seo_clean_url($env) : '';
	}
	return $url === '' ? '' : rtrim($url, '/');
}

//An https URL with a host and no credentials, query or fragment, or ''.
function ghoti_seo_clean_url($value){
	$value = trim((string)$value);
	if($value === '' || strlen($value) > 200 || preg_match('/[\x00-\x20\x7f"\'<>\\\\]/', $value)){ return ''; }
	$parts = parse_url($value);
	if(!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
		|| isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
		|| filter_var($value, FILTER_VALIDATE_URL) === false){
		return '';
	}
	return rtrim($value, '/');
}

//A site-relative path made absolute against the base URL, or '' without one.
function ghoti_seo_absolute($path){
	if(preg_match('#^https://#i', $path)){ return ghoti_seo_clean_url($path); }
	$base = ghoti_seo_base_url();
	if($base === '' || $path === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $path)){ return ''; }
	return $base.'/'.ltrim($path, '/');
}

/*
 * What the current request is showing, as far as metadata cares:
 *   array('kind' => 'home'|'page'|'view'|'private'|'missing', 'title' => ..., 'query' => ...)
 * 'query' is the part after the base URL a canonical link should carry.
 * A private page reports only that it is private - its title is not put in
 * <head>, where an anonymous visitor could read it.
 */
function ghoti_seo_page_context(array $get = null, $db = null){
	//The theme's <title> and ghoti.header.php both ask; one page read serves both.
	static $current = null;
	if($get === null && $db === null){
		if($current === null){ $current = ghoti_seo_page_context($_GET, $_SESSION['ghotiObj']->ghotidb ?? false); }
		return $current;
	}
	$get = $get ?? $_GET;
	$views = array('privacy' => 'Privacy policy', 'accessibility' => 'Accessibility', 'sitemap' => 'Sitemap');
	$view = $get['view'] ?? null;
	if(is_string($view) && isset($views[$view])){
		return array('kind' => 'view', 'title' => $views[$view], 'query' => '?view='.$view, 'view' => $view);
	}
	$page = $get['page'] ?? null;
	if(!is_string($page) || !preg_match('/^[1-9][0-9]{0,9}$/', $page)){
		return array('kind' => 'home', 'title' => '', 'query' => '');
	}
	try{
		$row = $db ? $db->getPageById((int)$page) : false;
	}catch(Throwable $e){
		$row = false;
	}
	if(!is_array($row) || !isset($row[0])){
		return array('kind' => 'missing', 'title' => '', 'query' => '');
	}
	if(($row[0][2] ?? 'public') !== 'public'){
		return array('kind' => 'private', 'title' => '', 'query' => '');
	}
	return array('kind' => 'page', 'title' => stripslashes((string)$row[0][1]), 'query' => '?page='.(int)$page);
}

//The <title> text for a context. {page} and {site} are the only placeholders.
function ghoti_seo_title(array $context){
	$site = ghoti::$siteTitle;
	if($context['title'] === ''){
		return ghoti::$seoHomeTitle !== '' ? ghoti::$seoHomeTitle : $site;
	}
	$format = ghoti::$seoTitleFormat !== '' ? ghoti::$seoTitleFormat : '{page} | {site}';
	if(strpos($format, '{page}') === false){ $format = '{page} | '.$format; }
	return str_replace(array('{page}', '{site}'), array($context['title'], $site), $format);
}

function ghoti_seo_title_html(array $context = null){
	return htmlspecialchars(ghoti_seo_title($context ?? ghoti_seo_page_context()), ENT_QUOTES, 'UTF-8');
}

//Whether this response may be indexed.
function ghoti_seo_indexable(array $context){
	return ghoti::$seoAllowIndexing && in_array($context['kind'], array('home', 'page', 'view'), true)
		&& ($context['view'] ?? '') !== 'sitemap';
}

function ghoti_seo_head_tags(array $context){
	$e = function($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
	$meta = function($attr, $name, $content) use ($e){
		return $content === '' ? '' : '<meta '.$attr.'="'.$e($name).'" content="'.$e($content).'" />'."\n";
	};
	$o = '';
	$title = ghoti_seo_title($context);
	$description = ghoti::$seoDescription;
	$indexable = ghoti_seo_indexable($context);

	$o .= $meta('name', 'description', $description);
	$o .= $meta('name', 'keywords', ghoti::$seoKeywords);
	$o .= $meta('name', 'robots', $indexable ? 'index, follow' : 'noindex, nofollow');
	$o .= $meta('name', 'google-site-verification', ghoti::$seoGoogleVerification);
	$o .= $meta('name', 'msvalidate.01', ghoti::$seoBingVerification);

	$canonical = $indexable ? ghoti_seo_absolute('/'.$context['query']) : '';
	if($canonical !== ''){
		$o .= '<link rel="canonical" href="'.$e($canonical).'" />'."\n";
	}
	$sitemap = ghoti_seo_absolute('sitemap.xml');
	if($sitemap !== '' && ghoti::$seoAllowIndexing){
		$o .= '<link rel="sitemap" type="application/xml" href="'.$e($sitemap).'" />'."\n";
	}

	//Sharing cards (Open Graph, read by most platforms; Twitter/X card).
	$image = ghoti::$seoShareImage !== '' ? ghoti_seo_absolute(ghoti::$seoShareImage) : '';
	$o .= $meta('property', 'og:type', 'website');
	$o .= $meta('property', 'og:site_name', ghoti::$siteTitle);
	$o .= $meta('property', 'og:title', $title);
	$o .= $meta('property', 'og:description', $description);
	$o .= $meta('property', 'og:url', $canonical);
	$o .= $meta('property', 'og:image', $image);
	$o .= $meta('name', 'twitter:card', $image !== '' ? 'summary_large_image' : 'summary');
	$handle = ghoti::$seoTwitterHandle;
	$o .= $meta('name', 'twitter:site', $handle === '' ? '' : '@'.ltrim($handle, '@'));

	//Structured data: who publishes the site. Only with a base URL, since
	//every value schema.org wants here is an absolute URL.
	$base = ghoti_seo_base_url();
	if(ghoti::$seoStructuredData && $indexable && $base !== ''){
		$org = array('@type' => 'Organization', 'name' => ghoti::$privacyOperator !== '' ? ghoti::$privacyOperator : ghoti::$siteTitle, 'url' => $base.'/');
		$logo = ghoti::$headerImg !== '' ? ghoti_seo_absolute(ghoti::$headerImg) : '';
		if($logo !== ''){ $org['logo'] = $logo; }
		$data = array('@context' => 'https://schema.org', '@graph' => array(
			array('@type' => 'WebSite', 'name' => ghoti::$siteTitle, 'url' => $base.'/'),
			$org,
		));
		if($description !== ''){ $data['@graph'][0]['description'] = $description; }
		//HEX_TAG/HEX_AMP: this sits inside <script>, so "</script>" in a site
		//name must not be able to close it.
		$o .= '<script type="application/ld+json">'
			.json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
			."</script>\n";
	}
	return $o;
}

/*
 * robots.txt. Switching indexing off does NOT disallow crawling: a crawler
 * that may not fetch a page never sees its noindex, and can still list the
 * bare URL from links elsewhere. The pages say noindex instead.
 */
function ghoti_seo_robots_txt(){
	$base = ghoti_seo_base_url();
	$path = '';
	if($base !== ''){
		$path = rtrim((string)(parse_url($base, PHP_URL_PATH) ?? ''), '/');
	}
	$lines = array('User-agent: *');
	//seo.php is NOT disallowed: without mod_rewrite, seo.php?file=sitemap is
	//the sitemap's address, and a sitemap robots.txt blocks is not read.
	foreach(array('password-reset.php', 'backup.php') as $script){
		$lines[] = 'Disallow: '.$path.'/'.$script;
	}
	$extra = trim(ghoti::$seoRobotsExtra);
	if($extra !== ''){
		$lines[] = '';
		$lines[] = $extra;
	}
	if($base !== '' && ghoti::$seoAllowIndexing){
		$lines[] = '';
		$lines[] = 'Sitemap: '.$base.'/sitemap.xml';
	}
	return implode("\n", $lines)."\n";
}

/*
 * sitemap.xml for the public pages. $pages is getPageManagementList()'s rows
 * (id, title, groupName, sortOrder, isDefault). The home page is listed once,
 * as the site root, since that is its canonical address. Returns null when a
 * sitemap cannot be produced (no base URL, or indexing is off).
 */
function ghoti_seo_sitemap_xml(array $pages){
	$base = ghoti_seo_base_url();
	if($base === '' || !ghoti::$seoAllowIndexing){ return null; }
	$urls = array(array($base.'/', '1.0'));
	foreach($pages as $row){
		if(($row[2] ?? '') !== 'public' || !empty($row[4])){ continue; }
		$urls[] = array($base.'/?page='.(int)$row[0], '0.8');
	}
	$urls[] = array($base.'/?view=privacy', '0.2');
	$urls[] = array($base.'/?view=accessibility', '0.2');
	$o = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
	foreach($urls as $url){
		$o .= '  <url><loc>'.htmlspecialchars($url[0], ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc><priority>'.$url[1].'</priority></url>'."\n";
	}
	return $o.'</urlset>'."\n";
}
?>
