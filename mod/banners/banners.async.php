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

function getRandomBanner(){
	return $_SESSION["bannersObj"]->bannersdb->getRandomBanner();
}

function manageBanners(){
	if(!bannersRequireAdmin()){ return "<h1>Banners</h1><p>Admin access required.</p>"; }
	return $_SESSION["bannersObj"]->bannersui->manageBanners(getAllBanners());
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

ghoti_async_register(
	"addBannerForm",
	"getRandomBanner",
	"manageBanners",
	"getAllBanners",
	"addBanner",
	"editBanner",
	"deleteBanner"
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
				$linkUrl = htmlspecialchars((string)$y[3], ENT_QUOTES);
				$imgUrl  = htmlspecialchars((string)$y[2], ENT_QUOTES);
				$alt     = htmlspecialchars((string)$y[1], ENT_QUOTES);
				$this->banner .= "<a href=\"".$linkUrl."\"><img src=\"".$imgUrl."\" alt=\"".$alt."\" class=\"ghotiBanner\" /></a>\n";
		}
		return $this->banner;
	}
	public function manageBanners($dbresult){
		$manageBanners = "<section id=\"ghotiManageBanners\" class=\"ghotiAdminPanel\">\n";
		$manageBanners .= "<div class=\"ghotiCrudHeader\"><h1>Manage Banners</h1><button type=\"button\" class=\"ghotiButton ghotiButtonSecondary\" onclick=\"addBannerForm();\">Add Banner</button></div>\n";
		$docs = ghoti_docs_panel("How to use banners", "add, edit, remove", array(
			array('heading' => 'Add a banner',
				'list' => array('Press <b>Add Banner</b> and fill in a description, image URL and link URL.', 'Use direct http(s) image links; tick <b>Small banner</b> for the compact size.')),
			array('heading' => 'Edit or remove',
				'list' => array('Change any field and press <b>Save</b>.', '<b>Delete</b> removes the banner everywhere.')),
			array('heading' => 'Where banners appear',
				'list' => array('Banners are picked at random from this list and shown to every visitor by the theme.'))
		));
		$manageBanners .= "<div class=\"ghotiForm ghotiCrudList\">\n";
		foreach($dbresult as $x => $y){
			$id = (int)$y[0];
			$alt = htmlspecialchars((string)$y[1], ENT_QUOTES);
			$imgUrl = htmlspecialchars((string)$y[2], ENT_QUOTES);
			$linkUrl = htmlspecialchars((string)$y[3], ENT_QUOTES);
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
			$manageBanners .= "<p class=\"ghotiEmptyState\">No banners found.</p>\n";
		}
		$manageBanners .= "</div>\n";
		$manageBanners .= $docs;
		$manageBanners .= "</section>\n";
		return $manageBanners;
	}
}
