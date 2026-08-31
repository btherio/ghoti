<?php
/*
 * gallery.async.php - gallery module async layer.
 *
 * Endpoints + class galleryui in one file, registered through the ghoti async
 * wrapper, mirroring the other modules' layout.
 *
 * Two ways a gallery reaches the page:
 *   - standalone: getGalleryByName() renders the gallery as a full page
 *     (hero header + grid) into #ghotiContent;
 *   - inline:    [gallery:NAME] or [gallery NAME] inside any page body is
 *     expanded by the shortcode handler registered below (see
 *     ghoti_expand_shortcodes() in ghoti.async.php).
 *
 * Uploaded photos are stored under files/gallery/<galleryId>/ with a random
 * name and a strict image-only whitelist (no PHP, no SVG) - the .htaccess in
 * that folder blocks script execution as a second layer. URL-added photos go
 * through the same scheme validation as links/banners.
 */

/* ---------------------------------------------------------------- *
 *  Module constants + shared helpers
 * ---------------------------------------------------------------- */

const GALLERY_MAX_NAME      = 80;
const GALLERY_MAX_TITLE     = 120;
const GALLERY_MAX_DESC      = 500;
const GALLERY_MAX_CAPTION   = 255;
const GALLERY_MAX_UPLOAD    = 12582912;              // 12MB
const GALLERY_ALLOWED_EXT   = array('jpg','jpeg','png','gif','webp');
const GALLERY_ALLOWED_MIME  = array('image/jpeg','image/png','image/gif','image/webp');

//Gallery slug used by the shortcode: letters, numbers, _ . - only.
function gallery_name($var){
	$v = trim((string)$var);
	if(!preg_match('/^[A-Za-z0-9_.-]{1,'.GALLERY_MAX_NAME.'}$/', $v)){
		throw new Exception("Gallery name may only contain letters, numbers, and _.- (max ".GALLERY_MAX_NAME." characters).");
	}
	return $v;
}

//Web-root prefix, same rule ghoti.header.php uses for asset URLs, so uploaded
//(relative) image paths resolve correctly even when the app lives in a
//subdirectory.
function gallery_asset_base(){
	$base = '/';
	if(!empty($_SERVER['SCRIPT_NAME'])){
		$dir = str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME']));
		$dir = rtrim($dir,'/');
		if($dir !== '' && $dir !== '.'){ $base = $dir.'/'; }
	}
	return $base;
}

//Turn a stored image reference into a usable src: remote/scheme URLs and
//root-absolute paths pass through; relative upload paths get the base prefix.
//Deliberately mirrors validate::url()'s allowed schemes (no data:/javascript:).
function gallery_image_src($url){
	$url = (string)$url;
	if($url === '' || preg_match('#^(https?:|mailto:)#i', $url) || $url[0] === '/'){
		return $url;
	}
	return gallery_asset_base().$url;
}

//Best-effort removal of a locally-uploaded image file. Only ever touches paths
//inside files/gallery/ (realpath-confined to that exact directory) - never
//anything else on disk.
function gallery_unlink_if_local($imageUrl){
	if(!is_string($imageUrl) || !preg_match('#^files/gallery/[0-9]+/[A-Za-z0-9._-]+$#', $imageUrl)){
		return;
	}
	$base = realpath(dirname(__DIR__, 2).'/files/gallery');
	$path = realpath(dirname(__DIR__, 2).'/'.$imageUrl);
	if($base !== false && $path !== false && strpos($path, $base.'/') === 0 && is_file($path)){
		@unlink($path);
	}
}

/* Effective upload cap: our own constant clamped down to whatever PHP's
 * upload_max_filesize / post_max_size allow, so the error message is honest
 * ("error 1" from PHP's limit is confusing). */
function gallery_max_upload_bytes(){
	$limit = GALLERY_MAX_UPLOAD;
	foreach(array('upload_max_filesize','post_max_size') as $key){
		$value = trim((string)ini_get($key));
		if($value === ''){ continue; }
		$bytes = (int)$value;
		$unit = strtoupper(substr($value, -1));
		if($unit === 'G'){ $bytes *= 1073741824; }
		elseif($unit === 'M'){ $bytes *= 1048576; }
		elseif($unit === 'K'){ $bytes *= 1024; }
		if($bytes > 0 && $bytes < $limit){ $limit = $bytes; }
	}
	return $limit;
}

/* ---------------------------------------------------------------- *
 *  Public endpoint: standalone gallery page
 * ---------------------------------------------------------------- */

function getGalleryByName($name){
	try{
		$name = gallery_name($name);
	}catch (Exception $e){
		return "<p>Gallery not found.</p>";
	}
	$gallery = $_SESSION['galleryObj']->gallerydb->getGalleryByName($name);
	if(!$gallery){
		return "<p>Gallery not found.</p>";
	}
	return $_SESSION['galleryObj']->galleryui->renderGalleryPage($gallery);
}

function getGalleryById($id){
	try{
		$id = ghoti_validate()->id($id, "gallery id");
	}catch (Exception $e){
		return "<p>Gallery not found.</p>";
	}
	$gallery = $_SESSION['galleryObj']->gallerydb->getGalleryById($id);
	if(!$gallery){
		return "<p>Gallery not found.</p>";
	}
	return $_SESSION['galleryObj']->galleryui->renderGalleryPage($gallery);
}

/* ---------------------------------------------------------------- *
 *  Admin endpoints
 * ---------------------------------------------------------------- */

function getGalleryManager(){
	if(!ghoti_require_admin()){ return "<h1>Galleries</h1><p>Admin access required.</p>"; }
	$galleries = $_SESSION['galleryObj']->gallerydb->getAllGalleries();
	if(!is_array($galleries)){ $galleries = array(); }
	return $_SESSION['galleryObj']->galleryui->printManager($galleries);
}

function getGalleryEditor($id){
	if(!ghoti_require_admin()){ return "<h1>Edit Gallery</h1><p>Admin access required.</p>"; }
	try{
		$id = ghoti_validate()->id($id, "gallery id");
	}catch (Exception $e){
		return "<p>Invalid gallery.</p>";
	}
	$gallery = $_SESSION['galleryObj']->gallerydb->getGalleryById($id);
	if(!$gallery){ return "<p>Gallery not found.</p>"; }
	$photos = $_SESSION['galleryObj']->gallerydb->getPhotos($id);
	if(!is_array($photos)){ $photos = array(); }
	return $_SESSION['galleryObj']->galleryui->printEditor($gallery,$photos);
}

function addGallery($name,$title,$description){
	if(!ghoti_require_admin()){ return "Admin access required."; }
	$v = ghoti_validate();
	try{
		$name        = gallery_name($name);
		$title       = $v->text($title, GALLERY_MAX_TITLE, true, "gallery title");
		$description = $v->text($description, GALLERY_MAX_DESC, false, "description");
	}catch (Exception $e){
		return $e->getMessage();
	}
	if($_SESSION['galleryObj']->gallerydb->nameInUse($name)){
		return "A gallery with that name already exists.";
	}
	ghoti::log("Adding gallery '$name' from ".ghoti_remote_addr());
	$id = $_SESSION['galleryObj']->gallerydb->addGallery($name,$title,$description);
	return $id ? $id : "Could not create the gallery.";
}

function editGallery($id,$name,$title,$description){
	if(!ghoti_require_admin()){ return "Admin access required."; }
	$v = ghoti_validate();
	try{
		$id          = $v->id($id, "gallery id");
		$name        = gallery_name($name);
		$title       = $v->text($title, GALLERY_MAX_TITLE, true, "gallery title");
		$description = $v->text($description, GALLERY_MAX_DESC, false, "description");
	}catch (Exception $e){
		return $e->getMessage();
	}
	if($_SESSION['galleryObj']->gallerydb->nameInUse($name,$id)){
		return "A gallery with that name already exists.";
	}
	if(!$_SESSION['galleryObj']->gallerydb->getGalleryById($id)){
		return "Gallery not found.";
	}
	return $_SESSION['galleryObj']->gallerydb->editGallery($id,$name,$title,$description)
		? true : "Could not save the gallery.";
}

function deleteGallery($id){
	if(!ghoti_require_admin()){ return false; }
	try{
		$id = ghoti_validate()->id($id, "gallery id");
	}catch (Exception $e){
		return false;
	}
	$gallery = $_SESSION['galleryObj']->gallerydb->getGalleryById($id);
	if(!$gallery){ return false; }
	$photos = $_SESSION['galleryObj']->gallerydb->getPhotos($id);
	if(is_array($photos)){
		foreach($photos as $photo){
			gallery_unlink_if_local($photo['imageUrl']);
		}
	}
	$_SESSION['galleryObj']->gallerydb->deleteGallery($id);
	ghoti::log("Deleted gallery '".$gallery['name']."' (id $id) from ".ghoti_remote_addr());
	return true;
}

//Add a photo from a URL (scheme-checked like links/banners).
function addPhoto($galleryId,$imageUrl,$caption = ''){
	if(!ghoti_require_admin()){ return "Admin access required."; }
	$v = ghoti_validate();
	try{
		$galleryId = $v->id($galleryId, "gallery id");
		$imageUrl  = $v->url($imageUrl, true, "image URL");
		$caption   = $v->text($caption, GALLERY_MAX_CAPTION, false, "caption");
	}catch (Exception $e){
		return $e->getMessage();
	}
	//url() allows mailto: for links; an <img src> can't use it - reject here so
	//the stored value always matches what the renderer can actually emit.
	//(Control chars are stripped first - mirroring validate::url()'s probe - so
	//"mailto :x" / "mailto\x01:x" can't dodge the check.)
	if(preg_match('#^mailto:#i', preg_replace('/[\x00-\x20]+/', '', $imageUrl))){
		return "Image URLs must use http:// or https://.";
	}
	if(!$_SESSION['galleryObj']->gallerydb->getGalleryById($galleryId)){
		return "Gallery not found.";
	}
	return $_SESSION['galleryObj']->gallerydb->addPhoto($galleryId,$imageUrl,$caption)
		? true : "Could not add the photo.";
}

//Photo upload: called with args[0] = galleryId and a multipart 'file' field
//(the async layer accepts form-encoded POSTs for exactly this reason). The
//client re-renders the editor on success.
function uploadPhoto($galleryId){
	if(!ghoti_require_admin()){ return "Admin access required."; }
	try{
		$galleryId = ghoti_validate()->id($galleryId, "gallery id");
	}catch (Exception $e){
		return $e->getMessage();
	}
	if(!$_SESSION['galleryObj']->gallerydb->getGalleryById($galleryId)){
		return "Gallery not found.";
	}
	if(!isset($_FILES['file'])){
		return "No file was uploaded.";
	}
	$file = $_FILES['file'];
	if(!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK){
		$code = isset($file['error']) ? (int)$file['error'] : -1;
		return "Upload failed (error $code).";
	}
	if(!is_uploaded_file($file['tmp_name'])){ return "Upload failed."; }
	$maxUpload = gallery_max_upload_bytes();
	if((int)$file['size'] > $maxUpload){
		$human = $maxUpload >= 1048576
			? floor($maxUpload/1048576)."MB"
			: max(1, floor($maxUpload/1024))."KB";
		return "Image is too large (max ".$human.").";
	}
	$ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
	if(!in_array($ext, GALLERY_ALLOWED_EXT, true)){
		return "Only ".implode(', ', GALLERY_ALLOWED_EXT)." images are allowed.";
	}
	//MIME double-check when available - extension alone is too easy to spoof.
	if(function_exists('finfo_open')){
		$finfo = finfo_open(FILEINFO_MIME_TYPE);
		$mime  = $finfo ? (string)finfo_file($finfo, $file['tmp_name']) : '';
		if($finfo){ finfo_close($finfo); }
		if($mime !== '' && !in_array($mime, GALLERY_ALLOWED_MIME, true)){
			return "That file is not a valid image.";
		}
	}
	$dir = dirname(__DIR__, 2).'/files/gallery/'.$galleryId;
	if(!is_dir($dir) && !@mkdir($dir, 0755, true)){
		return "Could not create the upload directory.";
	}
	if(!is_dir($dir) || !is_writable($dir)){
		return "The upload directory is not writable.";
	}
	$newName = bin2hex(random_bytes(16)).'.'.$ext;
	$dest    = $dir.'/'.$newName;
	if(!move_uploaded_file($file['tmp_name'], $dest)){
		return "Could not save the uploaded file.";
	}
	@chmod($dest, 0644);
	$imageUrl = 'files/gallery/'.$galleryId.'/'.$newName;
	$added = $_SESSION['galleryObj']->gallerydb->addPhoto($galleryId, $imageUrl, '');
	if(!$added){
		@unlink($dest); // don't leave an orphan on disk the client was told about
		return "The image was uploaded but could not be added to the gallery - please try again.";
	}
	return $imageUrl;
}

function deletePhoto($photoId){
	if(!ghoti_require_admin()){ return false; }
	try{
		$photoId = ghoti_validate()->id($photoId, "photo id");
	}catch (Exception $e){
		return false;
	}
	$result = $_SESSION['galleryObj']->gallerydb->deletePhoto($photoId);
	if(is_array($result)){
		gallery_unlink_if_local($result['imageUrl']);
		return true;
	}
	return false;
}

function setPhotoCaption($photoId,$caption){
	if(!ghoti_require_admin()){ return false; }
	try{
		$photoId = ghoti_validate()->id($photoId, "photo id");
		$caption = ghoti_validate()->text($caption, GALLERY_MAX_CAPTION, false, "caption");
	}catch (Exception $e){
		return $e->getMessage();
	}
	return $_SESSION['galleryObj']->gallerydb->setPhotoCaption($photoId,$caption);
}

function movePhoto($photoId,$direction){
	if(!ghoti_require_admin()){ return false; }
	try{
		$photoId = ghoti_validate()->id($photoId, "photo id");
	}catch (Exception $e){
		return false;
	}
	$direction = ((int)$direction < 0) ? -1 : 1;
	return $_SESSION['galleryObj']->gallerydb->movePhoto($photoId,$direction);
}

ghoti_async_register(
	"getGalleryByName",
	"getGalleryById",
	"getGalleryManager",
	"getGalleryEditor",
	"addGallery",
	"editGallery",
	"deleteGallery",
	"addPhoto",
	"uploadPhoto",
	"deletePhoto",
	"setPhotoCaption",
	"movePhoto"
);

/* ---------------------------------------------------------------- *
 *  Shortcode: [gallery:NAME] / [gallery NAME] inside page content.
 *  Registered with the core shortcode system (ghoti.async.php); expansion
 *  happens in getPage() so every public page view picks it up.
 * ---------------------------------------------------------------- */

function gallery_shortcode_expand($matches){
	$name = isset($matches[1]) ? trim($matches[1]) : '';
	if($name === '' || !isset($_SESSION['galleryObj'])){ return ''; }
	$gallery = $_SESSION['galleryObj']->gallerydb->getGalleryByName($name);
	if(!$gallery){
		return '<p class="ghotiGalleryMissing">Gallery "'.htmlspecialchars($name, ENT_QUOTES).'" not found.</p>';
	}
	return $_SESSION['galleryObj']->galleryui->renderGalleryInline($gallery);
}
ghoti_register_shortcode('gallery', 'gallery_shortcode_expand');

/* ---------------------------------------------------------------- *
 *  UI renderer (class galleryui)
 * ---------------------------------------------------------------- */

class galleryui{
	public $output;

	//Standalone page: hero header + grid + lightbox wiring data.
	public function renderGalleryPage($gallery){
		$photos = $_SESSION['galleryObj']->gallerydb->getPhotos($gallery['galleryId']);
		if(!is_array($photos)){ $photos = array(); }
		$esc = function($value){ return htmlspecialchars((string)$value, ENT_QUOTES); };
		$o  = "<div class=\"ghotiGalleryPage\" data-gallery=\"".$esc($gallery['name'])."\">\n";
		$o .= "<header class=\"ghotiGalleryHero\">\n";
		$o .= "<h1 class=\"ghotiGalleryTitle\">".$esc($gallery['title'])."</h1>\n";
		if($gallery['description'] !== ''){
			$o .= "<p class=\"ghotiGalleryBlurb\">".$esc($gallery['description'])."</p>\n";
		}
		$o .= "<span class=\"ghotiGalleryCount\">".count($photos)." photo".(count($photos) === 1 ? '' : 's')."</span>\n";
		$o .= "</header>\n";
		$o .= $this->renderGrid($gallery, $photos, $esc);
		$o .= "</div>\n";
		return $o;
	}

	//Inline embed used by the shortcode: compact header + grid. The lightbox
	//is global (gallery.js listens for clicks anywhere), so no extra JS needed.
	public function renderGalleryInline($gallery){
		$photos = $_SESSION['galleryObj']->gallerydb->getPhotos($gallery['galleryId']);
		if(!is_array($photos)){ $photos = array(); }
		$esc = function($value){ return htmlspecialchars((string)$value, ENT_QUOTES); };
		$o  = "<div class=\"ghotiGallery ghotiGalleryInline\" data-gallery=\"".$esc($gallery['name'])."\">\n";
		$o .= "<h2 class=\"ghotiGalleryTitle\">".$esc($gallery['title'])."</h2>\n";
		if($gallery['description'] !== ''){
			$o .= "<p class=\"ghotiGalleryBlurb\">".$esc($gallery['description'])."</p>\n";
		}
		$o .= $this->renderGrid($gallery, $photos, $esc);
		$o .= "</div>\n";
		return $o;
	}

	private function renderGrid($gallery, $photos, $esc){
		if(empty($photos)){
			return "<div class=\"ghotiGalleryEmpty\">This gallery has no photos yet.</div>\n";
		}
		$o  = "<div class=\"ghotiGalleryGrid\">\n";
		$index = 0;
		foreach($photos as $photo){
			$index++;
			$src = gallery_image_src($photo['imageUrl']);
			$cap = ($photo['caption'] !== '') ? $photo['caption'] : $gallery['title'];
			$o .= "<figure class=\"ghotiGalleryItem\" data-photo-id=\"".(int)$photo['photoId']."\" style=\"--i:".$index."\">\n";
			$o .= "<img src=\"".$esc($src)."\" alt=\"".$esc($cap)."\" loading=\"lazy\" />\n";
			if($photo['caption'] !== ''){
				$o .= "<figcaption>".$esc($photo['caption'])."</figcaption>\n";
			}
			$o .= "</figure>\n";
		}
		$o .= "</div>\n";
		return $o;
	}

	//Admin: gallery list + "new gallery" entry point.
	public function printManager($galleries){
		$esc = function($value){ return htmlspecialchars((string)$value, ENT_QUOTES); };
		$o  = "<section id=\"ghotiGalleryManager\" class=\"ghotiAdminPanel\">\n";
		$o .= "<div class=\"ghotiCrudHeader\"><div><h1>Galleries</h1><p class=\"ghotiHelpText\">Standalone photo pages that can also be embedded inside any other page.</p></div>";
		$o .= "<button type=\"button\" class=\"ghotiButton\" onclick=\"galleryNewForm();\">New Gallery</button></div>\n";
		$docs = ghoti_docs_panel("How to use galleries", "embed, add photos, manage", array(
			array('heading' => 'Display a gallery',
				'list' => array('<b>Standalone page</b> &mdash; press <i>View</i> on a gallery card (or link to it from anywhere). It renders as its own page with the title header and the full photo grid.',
					'<b>Embedded in another page</b> &mdash; edit any page and put the gallery&rsquo;s embed code in the body: <code class="ghotiDocCode">[gallery:NAME]</code>',
					'The gallery renders in place of that code whenever the page is viewed. Use the same code on as many pages as you like &mdash; change the gallery once and every page using it updates automatically.')),
			array('heading' => 'Add photos',
				'list' => array('<b>Image URL</b> &mdash; paste a direct link to an image file (http:// or https://).',
					'<b>Upload</b> &mdash; drag and drop files onto the upload zone, or click it to browse. Only jpg, png, gif and webp are accepted; file size is limited by the server.')),
			array('heading' => 'Manage photos',
				'list' => array('<b>Caption</b> &mdash; type into the caption box; it saves as soon as you leave the field.',
					'<b>Order</b> &mdash; use the up/down arrows to reorder photos.',
					'<b>Delete</b> &mdash; removes the photo and its uploaded file. Deleting a whole gallery deletes all of its photos too (this cannot be undone).')),
			array('heading' => 'Viewing on the site',
				'list' => array('Visitors click any photo to open the lightbox. Arrow keys step through the gallery, Esc closes it, and captions appear under each photo.'))
		));
		$o .= "<div class=\"ghotiGalleryManagerList\">\n";
		if(empty($galleries)){
			$o .= "<p class=\"ghotiEmptyState\">No galleries yet. Create your first one.</p>\n";
		}
		foreach($galleries as $g){
			$id = (int)$g['galleryId'];
			$o .= "<article class=\"ghotiGalleryCard\">\n";
			$o .= "<div class=\"ghotiGalleryCardBody\">\n";
			$o .= "<h2>".$esc($g['title'])."</h2>\n";
			$o .= "<p class=\"ghotiCrudMeta\"><code>[gallery:".$esc($g['name'])."]</code> &middot; ".$g['photoCount']." photo".($g['photoCount'] === 1 ? '' : 's')."</p>\n";
			if($g['description'] !== ''){
				$o .= "<p class=\"ghotiGalleryCardDesc\">".$esc($g['description'])."</p>\n";
			}
			$o .= "</div>\n";
			$o .= "<div class=\"ghotiCrudActions\">\n";
			$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" onclick=\"galleryView(".(int)$id.");\">View</button>";
			$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact\" onclick=\"galleryEditor(".(int)$id.");\">Edit</button>";
			$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonDanger\" onclick=\"galleryDelete(".(int)$id.");\">Delete</button>";
			$o .= "</div>\n";
			$o .= "</article>\n";
		}
		$o .= "</div>\n";
		$o .= $docs;
		$o .= "</section>\n";
		return $o;
	}

	//Admin: edit one gallery - fields up top, photos below, add/upload controls.
	public function printEditor($gallery,$photos){
		$esc = function($value){ return htmlspecialchars((string)$value, ENT_QUOTES); };
		$id = (int)$gallery['galleryId'];
		$o  = "<section id=\"ghotiGalleryEditor\" class=\"ghotiAdminPanel\">\n";
		$o .= "<div class=\"ghotiCrudHeader\"><div><h1>Edit Gallery</h1><p class=\"ghotiHelpText\">Embed this gallery anywhere with <code>[gallery:".$esc($gallery['name'])."]</code>.</p></div>";
		$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonSecondary\" onclick=\"galleryManager();\">&larr; All galleries</button></div>\n";

		$o .= "<form id=\"galleryEditForm\" class=\"ghotiForm\" action=\"#\" onsubmit=\"gallerySaveDetails(); return false;\">\n";
		$o .= "<div class=\"ghotiFormGrid\">\n";
		$o .= "<label class=\"ghotiField\"><span>Name (slug)</span><input type=\"text\" id=\"galleryName\" maxlength=\"80\" value=\"".$esc($gallery['name'])."\" autocomplete=\"off\" /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>Title</span><input type=\"text\" id=\"galleryTitle\" maxlength=\"120\" value=\"".$esc($gallery['title'])."\" /></label>\n";
		$o .= "<label class=\"ghotiField ghotiFieldWide\"><span>Description</span><input type=\"text\" id=\"galleryDescription\" maxlength=\"500\" value=\"".$esc($gallery['description'])."\" /></label>\n";
		$o .= "</div>\n";
		$o .= "<div class=\"ghotiFormActions\"><button type=\"submit\" class=\"ghotiButton\">Save details</button></div>\n";
		$o .= "</form>\n";

		$o .= "<div class=\"ghotiGalleryAdd\">\n";
		$o .= "<div class=\"ghotiCrudHeader\"><h2>Add photos</h2></div>\n";
		$o .= "<form id=\"galleryAddUrlForm\" class=\"ghotiForm ghotiInlineForm\" action=\"#\" onsubmit=\"galleryAddPhotoUrl(".$id."); return false;\">\n";
		$o .= "<label class=\"ghotiField\"><span>Image URL</span><input type=\"text\" id=\"galleryPhotoUrl\" size=\"40\" placeholder=\"https://example.com/photo.jpg\" /></label>\n";
		$o .= "<div class=\"ghotiFormActions\"><button type=\"submit\" class=\"ghotiButton\">Add from URL</button></div>\n";
		$o .= "</form>\n";
		$o .= "<div id=\"galleryUploadZone\" class=\"ghotiGalleryUploadZone\"><span class=\"ghotiGalleryUploadIcon\">&#8682;</span><span><b>Drop images here</b> or click to browse &middot; jpg, png, gif, webp</span><input type=\"file\" id=\"galleryUploadInput\" multiple accept=\".jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/gif,image/webp\" /></div>\n";
		$o .= "<p id=\"galleryUploadProgress\" class=\"ghotiHelpText\"></p>\n";
		$o .= "</div>\n";

		$o .= "<div id=\"galleryPhotoList\" class=\"ghotiGalleryPhotoList\">\n";
		if(empty($photos)){
			$o .= "<p class=\"ghotiEmptyState\">No photos yet - add one above.</p>\n";
		}
		foreach($photos as $photo){
			$pid = (int)$photo['photoId'];
			$src = gallery_image_src($photo['imageUrl']);
			$o .= "<article class=\"ghotiGalleryPhotoRow\" data-photo-id=\"".$pid."\">\n";
			$o .= "<img class=\"ghotiGalleryPhotoThumb\" src=\"".$esc($src)."\" alt=\"\" />\n";
			$o .= "<div class=\"ghotiGalleryPhotoFields\">\n";
			$o .= "<label class=\"ghotiField\"><span>Caption</span><input type=\"text\" maxlength=\"255\" id=\"photoCaption-".$pid."\" value=\"".$esc($photo['caption'])."\" onchange=\"gallerySaveCaption(".$pid.");\" /></label>\n";
			$o .= "</div>\n";
			$o .= "<div class=\"ghotiCrudActions\">\n";
			$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" onclick=\"galleryMovePhoto(".$pid.",-1);\" title=\"Move up\">&#8593;</button>";
			$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" onclick=\"galleryMovePhoto(".$pid.",1);\" title=\"Move down\">&#8595;</button>";
			$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonDanger\" onclick=\"galleryDeletePhoto(".$pid.");\">Delete</button>";
			$o .= "</div>\n";
			$o .= "</article>\n";
		}
		$o .= "</div>\n";
		$o .= "</section>\n";
		return $o;
	}
}
?>
