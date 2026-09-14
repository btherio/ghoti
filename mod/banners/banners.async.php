<?php
/*
 * banners.async.php - banners module async layer.
 *
 * Combines the former banners.ajax.php (endpoints) and banners.ui.php (class
 * bannersui) into one file, registered through the ghoti async wrapper.
 */

/* ---------------------------------------------------------------- *
 *  Endpoints (formerly banners.ajax.php)
 * ---------------------------------------------------------------- */

/*
 * Banner *management* is admin-only. Previously these endpoints trusted the
 * client (the menu is only shown to admins) - but that isn't enforcement, so a
 * non-admin who knew the endpoint names could add/edit/delete banners. The
 * check mirrors analyticsRequireAdmin(). Note getRandomBanner() stays public:
 * banners are rendered to every visitor by the theme.
 */
function bannersRemoteAddr(){
	return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
}

function bannersRequireAdmin(){
	if(!isset($_SESSION['userId']) || !isAdmin($_SESSION['userId'])){
		ghoti::logWarn("banners.async.php", "Unauthorized banner management attempt from ".bannersRemoteAddr());
		return false;
	}
	return true;
}

function addBannerForm(){
	if(!bannersRequireAdmin()){ return "Admin access required."; }
	return $_SESSION["bannersObj"]->bannersui->addBannerForm();
}

/*
 * Legacy. Public on purpose - banners are rendered to every visitor by the
 * theme - but nothing in this tree calls it: themes use displayBanner()
 * directly. It now goes through the same path they do, so it honours the
 * banner source instead of handing out local rows on a site that has chosen
 * Google ads. That makes it return rendered HTML for the small position rather
 * than the raw rows it once did; see docs/banners.md.
 */
function getRandomBanner(){
	return $_SESSION["bannersObj"]->displayBanner(true);
}

function manageBanners(){
	if(!bannersRequireAdmin()){ return "<h1>Banners</h1><p>Admin access required.</p>"; }
	return $_SESSION["bannersObj"]->bannersui->manageBanners(getAllBanners(), $_SESSION["bannersObj"]->bannersdb->getSettings());
}

function getAllBanners(){
	if(!bannersRequireAdmin()){ return array(); }
	return $_SESSION["bannersObj"]->bannersdb->getAllBanners();
}

function addBanner($desc,$imgUrl,$linkUrl,$smallBanner){
	if(!bannersRequireAdmin()){ return "Admin access required."; }
	try{
		$v = ghoti_validate();
		$desc    = $v->text($desc, validate::MAX_TEXT, true, "banner description");
		//imgUrl/linkUrl are printed straight into <img src>/<a href> for EVERY
		//visitor (bannersui::displayBanner), so they must be scheme-checked -
		//otherwise a banner is a site-wide stored-XSS vector.
		$imgUrl  = $v->url($imgUrl, true, "image URL");
		$linkUrl = $v->url($linkUrl, true, "link URL");
		$smallBanner = $v->boolInt($smallBanner);
	}catch (Exception $e) {
		return $e->getMessage();
	}
	return $_SESSION["bannersObj"]->bannersdb->addBanner($desc,$imgUrl,$linkUrl,$smallBanner);
}

function editBanner($id,$desc,$imgUrl,$linkUrl,$smallBanner){
	if(!bannersRequireAdmin()){ return "Admin access required."; }
	//Previously this endpoint validated nothing at all - it stored the client's
	//id and URLs verbatim. Apply the same rules as addBanner().
	try{
		$v = ghoti_validate();
		$id      = $v->id($id, "banner id");
		$desc    = $v->text($desc, validate::MAX_TEXT, true, "banner description");
		$imgUrl  = $v->url($imgUrl, true, "image URL");
		$linkUrl = $v->url($linkUrl, true, "link URL");
		$smallBanner = $v->boolInt($smallBanner);
	}catch (Exception $e) {
		return $e->getMessage();
	}
	if($_SESSION["bannersObj"]->bannersdb->editBanner($id,$desc,$imgUrl,$linkUrl,$smallBanner))
		return "Banner Saved!";
	else
		return "Saving Banner Failed!";
}

function deleteBanner($id){
	if(!bannersRequireAdmin()){ return false; }
	try{
		$id = ghoti_validate()->id($id, "banner id");
	}catch (Exception $e) {
		return false;
	}
	return $_SESSION["bannersObj"]->bannersdb->deleteBanner($id);
}

/*
 * Save the ad settings.
 *
 * The publisher and slot ids end up inside a <script src> query string and
 * inside attributes served to every visitor, so they are matched against a
 * strict pattern and rejected on failure. Nothing here tries to "clean up" a
 * near-miss: a publisher id with a typo in it earns nothing, and silently
 * storing it would make the empty ad slot impossible to explain.
 */
function saveBannerSettings($source,$adClient,$adSlotSmall,$adSlotLarge,$adFormat,$adFullWidth,$adTest,$adLabel){
	if(!bannersRequireAdmin()){ return "Admin access required."; }
	$source = (string)$source;
	if(!array_key_exists($source, bannersdb::validSources())){ return "Choose which banners this site should show."; }

	$adClient    = trim((string)$adClient);
	$adSlotSmall = trim((string)$adSlotSmall);
	$adSlotLarge = trim((string)$adSlotLarge);
	if($adClient !== '' && !BannerAds::validClient($adClient)){
		return "That publisher ID does not look right. It is the ca-pub-… value from your AdSense account.";
	}
	if($adSlotSmall !== '' && !BannerAds::validSlot($adSlotSmall)){ return "The small-banner ad slot ID must be the digits AdSense shows for that unit."; }
	if($adSlotLarge !== '' && !BannerAds::validSlot($adSlotLarge)){ return "The wide-banner ad slot ID must be the digits AdSense shows for that unit."; }

	$adFormat = (string)$adFormat;
	if(!array_key_exists($adFormat, BannerAds::formats())){ $adFormat = 'auto'; }

	try{
		$v = ghoti_validate();
		$adLabel = $v->text($adLabel, 60, false, "advertising label");
		$adFullWidth = $v->boolInt($adFullWidth);
		$adTest = $v->boolInt($adTest);
	}catch (Exception $e) {
		return $e->getMessage();
	}

	//Refuse the combination that produces a blank page region rather than
	//storing it and leaving the admin to wonder where the ads went.
	if($source !== 'local' && !BannerAds::configured(array('adClient'=>$adClient,'adSlotSmall'=>$adSlotSmall,'adSlotLarge'=>$adSlotLarge))){
		return "Ads need a publisher ID and at least one ad slot ID before they can be switched on.";
	}

	$saved = $_SESSION["bannersObj"]->bannersdb->saveSettings(array(
		'source' => $source, 'adClient' => $adClient,
		'adSlotSmall' => $adSlotSmall, 'adSlotLarge' => $adSlotLarge,
		'adFormat' => $adFormat, 'adFullWidth' => $adFullWidth === 1,
		'adTest' => $adTest === 1, 'adLabel' => $adLabel,
	));
	if(!$saved){ return "Saving banner settings failed."; }
	//A policy header is written once per page load from these values, so the
	//change only takes full effect on the next request. Say so.
	return "Banner settings saved. Reload the site to see the change.";
}

ghoti_async_register(
	"addBannerForm",
	"getRandomBanner",
	"manageBanners",
	"getAllBanners",
	"addBanner",
	"editBanner",
	"deleteBanner",
	"saveBannerSettings"
);

/* ---------------------------------------------------------------- *
 *  UI renderer (formerly banners.ui.php / class bannersui)
 * ---------------------------------------------------------------- */

class bannersui{
	public function addBannerForm(){
		$addBannerForm = "<form id=\"addBannerForm\" class=\"ghotiForm\" action=\"#\" onsubmit=\"addBanner(); return false;\">\n";
		$addBannerForm .= "<label class=\"ghotiField\"><span>Banner description</span><input type=\"text\" id=\"bannerDesc\" name=\"bannerDesc\" size=\"24\" /></label>\n";
		$addBannerForm .= "<label class=\"ghotiField\"><span>Image URL</span><input type=\"text\" id=\"bannerImgUrl\" name=\"bannerImgUrl\" size=\"32\" /></label>\n";
		$addBannerForm .= "<label class=\"ghotiField\"><span>Link URL</span><input type=\"text\" id=\"bannerLinkUrl\" name=\"bannerLinkUrl\" size=\"32\" /></label>\n";
		$addBannerForm .= "<label class=\"ghotiInlineChoice\"><input type=\"checkbox\" id=\"bannerSmallBanner\" name=\"bannerSmallBanner\" value=\"true\" /> Small banner</label>\n";
		$addBannerForm .= "<div class=\"ghotiFormActions\"><button type=\"submit\" class=\"ghotiButton\">Add Banner</button></div>\n";
		$addBannerForm .= "<span id=\"addBannerMessages\"></span></form>\n";
		return $addBannerForm;
	}

	public function displayBanner($dbresult){
		$this->banner = "";
		foreach($dbresult as $x => $y){
				//Escape every field before it goes into the attribute. Banner URLs
				//are scheme-validated at ingestion (addBanner/editBanner), but this
				//view is shown to every visitor, so escape here too (defense in depth
				//for any rows that predate the input validation).
				$linkUrl = ghoti_safe_url_attribute($y[3]);
				$imgUrl  = ghoti_safe_url_attribute($y[2]);
				$alt     = htmlspecialchars((string)$y[1], ENT_QUOTES);
				$this->banner .= "<a href=\"".$linkUrl."\"><img src=\"".$imgUrl."\" alt=\"".$alt."\" class=\"ghotiBanner\" /></a>\n";
		}
		return $this->banner;
	}
	/*
	 * The "where do banners come from" panel. Sits above the list because it
	 * decides whether the list below it is being shown to anyone at all.
	 */
	public function bannerSettingsForm($settings, $counts = array()){
		$client = htmlspecialchars((string)$settings['adClient'], ENT_QUOTES);
		$small  = htmlspecialchars((string)$settings['adSlotSmall'], ENT_QUOTES);
		$large  = htmlspecialchars((string)$settings['adSlotLarge'], ENT_QUOTES);
		$label  = htmlspecialchars((string)$settings['adLabel'], ENT_QUOTES);

		$out  = "<form id=\"bannerSettingsForm\" class=\"ghotiForm\" action=\"#\" onsubmit=\"saveBannerSettings(); return false;\">\n";
		$out .= "<h2>Where banners come from</h2>\n";
		$out .= "<div class=\"ghotiFormGrid\">\n";
		$out .= "<label class=\"ghotiField\"><span>Banner source</span><select id=\"bannerSource\">\n";
		foreach(bannersdb::validSources() as $value => $text){
			$selected = $settings['source'] === $value ? " selected=\"selected\"" : "";
			$out .= "<option value=\"".htmlspecialchars($value, ENT_QUOTES)."\"".$selected.">".htmlspecialchars($text, ENT_QUOTES)."</option>\n";
		}
		$out .= "</select></label>\n";
		$out .= "<label class=\"ghotiField ghotiFieldWide\"><span>AdSense publisher ID</span><input type=\"text\" id=\"bannerAdClient\" size=\"28\" value=\"".$client."\" placeholder=\"ca-pub-0000000000000000\" /></label>\n";
		$out .= "<label class=\"ghotiField\"><span>Ad slot ID (small position)</span><input type=\"text\" id=\"bannerAdSlotSmall\" size=\"16\" value=\"".$small."\" /></label>\n";
		$out .= "<label class=\"ghotiField\"><span>Ad slot ID (wide position)</span><input type=\"text\" id=\"bannerAdSlotLarge\" size=\"16\" value=\"".$large."\" /></label>\n";
		$out .= "<label class=\"ghotiField\"><span>Ad format</span><select id=\"bannerAdFormat\">\n";
		foreach(BannerAds::formats() as $value => $text){
			$selected = ($settings['adFormat'] ?? 'auto') === $value ? " selected=\"selected\"" : "";
			$out .= "<option value=\"".htmlspecialchars($value, ENT_QUOTES)."\"".$selected.">".htmlspecialchars($text, ENT_QUOTES)."</option>\n";
		}
		$out .= "</select></label>\n";
		$out .= "<label class=\"ghotiField ghotiFieldWide\"><span>Label above each ad (optional)</span><input type=\"text\" id=\"bannerAdLabel\" size=\"24\" value=\"".$label."\" placeholder=\"Advertisement\" /></label>\n";
		$out .= "</div>\n";
		$out .= "<label class=\"ghotiInlineChoice\"><input type=\"checkbox\" id=\"bannerAdFullWidth\"".(!empty($settings['adFullWidth']) ? " checked=\"checked\"" : "")." /> Let responsive units use the full width</label>\n";
		$out .= "<label class=\"ghotiInlineChoice\"><input type=\"checkbox\" id=\"bannerAdTest\"".(!empty($settings['adTest']) ? " checked=\"checked\"" : "")." /> Test mode &mdash; show placeholder ads, earn nothing</label>\n";
		$out .= $this->adStatus($settings, $counts);
		$out .= "<div class=\"ghotiFormActions\"><button type=\"submit\" class=\"ghotiButton\">Save banner settings</button></div>\n";
		$out .= "<span id=\"bannerSettingsMessages\"></span></form>\n";
		return $out;
	}

	/*
	 * Why ads are or are not appearing. Every line here is something the admin
	 * can act on; none of it can be answered by looking at the page, because an
	 * ad slot that is unconfigured and one that Google simply has not filled
	 * yet look exactly the same - empty.
	 */
	private function adStatus($settings, $counts = array()){
		if($settings['source'] === 'local'){
			return "<p class=\"ghotiHint\">Google ads are switched off. This site shows only the banners listed below.</p>\n";
		}
		$notes = array();
		//A row edited by hand, or slots blanked directly in the database, can
		//leave a site asking for ads it cannot serve. The save form refuses that
		//combination, so this says plainly what the page cannot show.
		if(!BannerAds::configured($settings)){
			$notes[] = "<b>Ads are selected as a source but cannot be served</b> &mdash; a publisher ID and at least one ad slot ID are needed.";
		}
		if(!BannerAds::validClient($settings['adClient'])){
			$notes[] = "No valid publisher ID, so no ads are being requested.";
		}else{
			if(!BannerAds::validSlot($settings['adSlotSmall'])){ $notes[] = "No ad slot for the small position &mdash; themes asking for a small banner will show ".($settings['source'] === 'both' ? "only your own banners" : "nothing")."."; }
			if(!BannerAds::validSlot($settings['adSlotLarge'])){ $notes[] = "No ad slot for the wide position &mdash; themes asking for a wide banner will show ".($settings['source'] === 'both' ? "only your own banners" : "nothing")."."; }
			$adsTxt = BannerAds::adsTxtLine($settings['adClient']);
			if($adsTxt !== ''){
				$notes[] = "Publish this line as <code>ads.txt</code> at the root of your domain, or Google will not buy against these slots:<br /><code>".htmlspecialchars($adsTxt, ENT_QUOTES)."</code>";
			}
		}
		if(!empty($settings['adTest'])){ $notes[] = "Test mode is on: placeholder ads only, and nothing is earned. Turn it off when you have confirmed the slots render."; }
		if($settings['source'] === 'both' && BannerAds::configured($settings)){
			//"Both" draws one winner from this size's banners plus the ad unit,
			//so the ad's share falls as banners are added. An operator who
			//expected an even split would otherwise have to work that out from
			//the source, or never notice the dilution at all.
			foreach(array(true => 'small', false => 'wide') as $small => $name){
				if(!BannerAds::configured($settings, (bool)$small)){ continue; }
				$own = (int)($counts[$small ? 'small' : 'wide'] ?? 0);
				$notes[] = "With ".$own." of your own ".$name." banner".($own === 1 ? "" : "s").", about 1 ".$name." position in ".($own + 1)." is an ad. Adding your own banners makes ads rarer.";
			}
		}
		if(!$notes){ $notes[] = "Ads are configured. New slots can take a few hours before Google starts filling them."; }
		return "<ul class=\"ghotiHint\"><li>".implode("</li>\n<li>", $notes)."</li></ul>\n";
	}

	public function manageBanners($dbresult, $settings = null){
		if(!is_array($settings)){ $settings = bannersdb::defaultSettings(); }
		$manageBanners = "<section id=\"ghotiManageBanners\" class=\"ghotiAdminPanel\">\n";
		$manageBanners .= "<div class=\"ghotiCrudHeader\"><h1>Manage Banners</h1><button type=\"button\" class=\"ghotiButton ghotiButtonSecondary\" onclick=\"addBannerForm();\">Add Banner</button></div>\n";
		$docs = ghoti_docs_panel("How to use banners", "add, edit, remove, Google ads", array(
			array('heading' => 'Add a banner',
				'list' => array('Press <b>Add Banner</b> and fill in a description, image URL and link URL.', 'Use direct http(s) image links; tick <b>Small banner</b> for the compact size.')),
			array('heading' => 'Edit or remove',
				'list' => array('Change any field and press <b>Save</b>.', '<b>Delete</b> removes the banner everywhere.')),
			array('heading' => 'Where banners appear',
				'list' => array('Banners are picked at random from this list and shown to every visitor by the theme.')),
			array('heading' => 'Google AdSense',
				'list' => array(
					'Apply at <b>adsense.google.com</b> and wait for your site to be approved &mdash; a new account cannot serve ads before then.',
					'In AdSense, create two <b>display</b> ad units: one for the small banner position, one for the wide one. Each gives you a slot ID.',
					'Paste the publisher ID (<b>ca-pub-…</b>) and both slot IDs above, then choose <b>Google AdSense only</b> or <b>Both</b>.',
					'Publish the <b>ads.txt</b> line shown above at the root of your domain.',
					'Empty space where an ad should be is normal at first: approval, ads.txt and slot warm-up all take hours to days. See <b>docs/banners.md</b>.'))
		));
		//Counted from the list this page is already rendering, so the mix note
		//costs no extra query.
		$counts = array('small' => 0, 'wide' => 0);
		foreach(($dbresult ?: array()) as $row){ $counts[(int)$row[4] === 1 ? 'small' : 'wide']++; }
		$manageBanners .= $this->bannerSettingsForm($settings, $counts);
		$manageBanners .= "<div class=\"ghotiForm ghotiCrudList\">\n";
		foreach($dbresult as $x => $y){
			$id = (int)$y[0];
			$alt = htmlspecialchars((string)$y[1], ENT_QUOTES);
			$imgUrl = ghoti_safe_url_attribute($y[2]);
			$linkUrl = ghoti_safe_url_attribute($y[3]);
			$small = (int)$y[4];
			$manageBanners .= "<article class=\"ghotiCrudRow ghotiBannerRow\">\n";
			$manageBanners .= "<a href=\"".$linkUrl."\"><img src=\"".$imgUrl."\" alt=\"".$alt."\" class=\"ghotiPreviewImage\" /></a>\n";
			$manageBanners .= "<div class=\"ghotiFormGrid\">\n";
			$manageBanners .= "<label class=\"ghotiField\"><span>Description</span><input type=\"text\" id=\"alt-".$id."\" size=\"18\" value=\"".$alt."\" /></label>\n";
			$manageBanners .= "<label class=\"ghotiField ghotiFieldWide\"><span>Image URL</span><input type=\"text\" id=\"imgUrl-".$id."\" size=\"30\" value=\"".$imgUrl."\" /></label>\n";
			$manageBanners .= "<label class=\"ghotiField ghotiFieldWide\"><span>Link URL</span><input type=\"text\" id=\"linkUrl-".$id."\" size=\"30\" value=\"".$linkUrl."\" /></label>\n";
			if($y[4] == 1)
				$manageBanners .= "<label class=\"ghotiField\"><span>Size</span><button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" onclick=\"toggleSmallBanner(".$id.");\"><img id=\"smallBannerIcon-".$id."\" src=\"gfx/green-check.gif\" alt=\"\" />Small banner</button></label>\n";
			else
				$manageBanners .= "<label class=\"ghotiField\"><span>Size</span><button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" onclick=\"toggleSmallBanner(".$id.");\"><img id=\"smallBannerIcon-".$id."\" src=\"gfx/red-x.gif\" alt=\"\" />Small banner</button></label>\n";
			$manageBanners .= "<input type=\"hidden\" id=\"smallBanner-".$id."\" value=\"".$small."\" />\n";
			$manageBanners .= "</div>\n";
			$manageBanners .= "<div class=\"ghotiFormActions\"><button type=\"button\" class=\"ghotiButton ghotiButtonCompact\" onclick=\"saveBanner(".$id.");\"><img src=\"gfx/save.png\" alt=\"\" />Save</button>\n";
			$manageBanners .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonDanger\" onclick=\"deleteBanner(".$id.");\"><img src=\"gfx/delete.png\" alt=\"\" />Delete</button></div>\n";
			$manageBanners .= "</article>\n";
		}
		if(!$dbresult){
			$manageBanners .= "<p class=\"ghotiEmptyState\">No banners of your own. "
				.($settings['source'] === 'local'
					? "Nothing is shown in the theme's banner positions until you add one."
					: "Google ads will fill the banner positions on their own.")."</p>\n";
		}
		$manageBanners .= "</div>\n";
		$manageBanners .= $docs;
		$manageBanners .= "</section>\n";
		return $manageBanners;
	}
}
