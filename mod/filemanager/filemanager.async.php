<?php
/*
 * filemanager.async.php - simple GUI file manager (admin only).
 *
 * Lets the admin browse, upload, create, rename, delete and text-edit files
 * under the site root - the gfx/ and css/ trees and other site assets. No
 * database: this module works purely on the filesystem, so it has no .sql or
 * .db.php file.
 *
 * Security model (all enforced server-side on every endpoint):
 *   - every endpoint is gated by ghoti_require_admin() and the global CSRF
 *     token, and every mutation is written to ghoti.log with the actor;
 *   - all paths are confined to the web root via realpath() - ".." can never
 *     escape it, and symlinks that leave the root are rejected;
 *   - a deny list keeps configuration, logs, session/throttle state and VCS
 *     directories out of reach (same names the root .htaccess blocks);
 *   - the text editor only opens small, text-whitelisted files;
 *   - uploads go through the multipart RPC path (see ghoti_async_read_request)
 *     with a size cap and the same deny list.
 *
 * Note on trust: editing css/ theme files (php) is full code-execution
 * capability for the account that does it - that is the intended admin feature
 * (same trust level as a WordPress theme editor), but it means this module
 * must never be reachable by non-admins. Downloads go through a separate
 * navigable URL (filemanager.download.php) with its own admin + token checks.
 */

/* ---------------------------------------------------------------- *
 *  Constants + shared helpers
 * ---------------------------------------------------------------- */

const FM_MAX_UPLOAD     = 26214400; // 25MB
const FM_MAX_TEXT       = 262144;   // 256KB for the text editor
const FM_MAX_NAME       = 200;

/* Basenames that are never listed, edited, uploaded, renamed or deleted.
 * Mirrors the web-root .htaccess deny list plus VCS/agent dirs. */
const FM_DENY_BASENAMES = array(
	'.git','.svn','.hg','.idea','.reasonix','.claude',
	'.htaccess','db.config.php','db.config.local.php',
	'ghoti.settings.json','db.provisioned.json','login.throttle.json'
);

/* Extensions the text editor may open (everything else is binary/download). */
const FM_TEXT_EXTENSIONS = array(
	'php','css','js','json','xml','txt','md','html','htm',
	'sql','svg','ini','conf','yaml','yml','csv','tsv'
);

//The web root this module manages. The module lives at <root>/mod/filemanager/,
//so the root is TWO levels up - dirname(__DIR__, 2), not dirname(__DIR__).
function fm_root(){
	return realpath(dirname(__DIR__, 2));
}

//True when a basename is off-limits (also covers ghoti.log + rotations).
function fm_is_denied($name){
	$name = (string)$name;
	$low  = strtolower($name);
	if(in_array($low, FM_DENY_BASENAMES, true)){ return true; }
	if(strpos($low, 'ghoti.log') === 0){ return true; }
	return false;
}

/* Normalize + validate a relative directory path from the client. Returns the
 * cleaned path ('' for the root) or false on anything suspicious. */
function fm_clean_dir($dir){
	if(!is_string($dir)){ return false; }
	$dir = trim($dir, "/ \t\r\n");
	if($dir === '' || $dir === '.'){ return ''; }
	if(!preg_match('#^[A-Za-z0-9_./\x20-]+$#', $dir)){ return false; } // safe charset
	if(strlen($dir) > 512){ return false; }
	//Per-segment checks: no empty segments (a//b), no '.'/'..', no dot-segments
	//(.hidden) - a name that merely ENDS in a dot (e.g. "gfx.") is fine.
	foreach(explode('/', $dir) as $seg){
		if($seg === '' || $seg === '.' || $seg === '..'){ return false; }
		if($seg[0] === '.'){ return false; }
	}
	return $dir;
}

/* Validate a single basename (file/folder name). Rejects separators, dot
 * names, control chars and anything on the deny list. */
function fm_clean_name($name){
	if(!is_string($name)){ return false; }
	$name = trim($name);
	if($name === '' || $name === '.' || $name === '..'){ return false; }
	if(strlen($name) > FM_MAX_NAME){ return false; }
	//No separators, control chars or double quotes (a " would break the
	//Content-Disposition filename header on download).
	if(preg_match('#[/\\\\"\x00-\x1F]#', $name)){ return false; }
	if($name[0] === '.'){ return false; }          // no dotfiles via the UI
	if(fm_is_denied($name)){ return false; }
	return $name;
}

/* Resolve a validated relative dir to its realpath inside the web root.
 * Returns array($root, $cleanDir, $fullPath) or false. */
function fm_resolve_dir($dir){
	$dir = fm_clean_dir($dir);
	if($dir === false){ return false; }
	$root = fm_root();
	if($root === false){ return false; }
	$full = realpath($root.($dir === '' ? '' : '/'.$dir));
	if($full === false){ return false; }
	if($full !== $root && strpos($full, $root.'/') !== 0){ return false; }
	return array($root, $dir, $full);
}

/* Absolute path of a (validated) target inside a resolved directory. */
function fm_target_path($resolved, $name){
	return $resolved[2].'/'.$name;
}

/*
 * Resolve a target FILE inside a resolved directory to its realpath and
 * re-verify confinement + the deny list against the RESOLVED path. This is the
 * critical check: fm_resolve_dir() confines the directory, but a symlinked
 * file (logo.png -> /etc/passwd or -> db.config.php) would otherwise pass the
 * basename checks and be read/written/deleted through the link. realpath()
 * canonicalizes the link, so the escape is caught and the real target's
 * basename is deny-checked. Returns the canonical absolute path or false.
 */
function fm_resolve_target($resolved, $name){
	$name = fm_clean_name($name);
	if($resolved === false || $name === false){ return false; }
	$path = fm_target_path($resolved, $name);
	if(!file_exists($path) && !is_link($path)){ return false; }
	$real = realpath($path);
	if($real === false){ return false; }
	$root = $resolved[0];
	if($real !== $root && strpos($real, $root.'/') !== 0){ return false; }
	if(fm_is_denied(basename($real))){ return false; }
	return $real;
}

//Effective upload cap, clamped by PHP's own limits so errors are honest.
function fm_max_upload_bytes(){
	$limit = FM_MAX_UPLOAD;
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

//Human-readable byte size.
function fm_format_size($bytes){
	$bytes = (int)$bytes;
	if($bytes >= 1073741824){ return round($bytes/1073741824, 1).' GB'; }
	if($bytes >= 1048576){ return round($bytes/1048576, 1).' MB'; }
	if($bytes >= 1024){ return round($bytes/1024, 1).' KB'; }
	return $bytes.' B';
}

//Basic content-type for forced downloads.
function fm_mime_type($path){
	$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
	$map = array(
		'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
		'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
		'ico' => 'image/x-icon', 'css' => 'text/css', 'js' => 'text/javascript',
		'json' => 'application/json', 'xml' => 'application/xml', 'txt' => 'text/plain',
		'html' => 'text/html', 'htm' => 'text/html', 'php' => 'application/octet-stream',
		'zip' => 'application/zip', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
		'ttf' => 'font/ttf', 'otf' => 'font/otf', 'eot' => 'application/vnd.ms-fontobject',
		'pdf' => 'application/pdf', 'mp4' => 'video/mp4', 'webm' => 'video/webm',
		'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav'
	);
	return isset($map[$ext]) ? $map[$ext] : 'application/octet-stream';
}

/* ---------------------------------------------------------------- *
 *  Listing
 * ---------------------------------------------------------------- */

function fm_list_entries($resolved){
	$entries = array();
	$handle = @opendir($resolved[2]);
	if($handle === false){ return false; }
	while(($entry = readdir($handle)) !== false){
		if($entry === '.' || $entry === '..'){ continue; }
		if($entry[0] === '.'){ continue; }         // no dotfiles in the UI
		if(fm_is_denied($entry)){ continue; }
		$path = $resolved[2].'/'.$entry;
		$isDir = is_dir($path);
		$entries[] = array(
			'name'   => $entry,
			'isDir'  => $isDir,
			'size'   => $isDir ? 0 : (is_file($path) ? filesize($path) : 0),
			'mtime'  => filemtime($path),
			'text'   => (!$isDir && fm_is_text_editable($entry))
		);
	}
	closedir($handle);
	//Directories first, then files, each alphabetical.
	usort($entries, function($a, $b){
		if($a['isDir'] !== $b['isDir']){ return $a['isDir'] ? -1 : 1; }
		return strcasecmp($a['name'], $b['name']);
	});
	return $entries;
}

function fm_is_text_editable($name){
	$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
	return in_array($ext, FM_TEXT_EXTENSIONS, true);
}

/* ---------------------------------------------------------------- *
 *  Endpoints (all admin-only)
 * ---------------------------------------------------------------- */

function getFileManager($dir = ''){
	if(!ghoti_require_admin()){ return "<h1>Files</h1><p>Admin access required.</p>"; }
	$resolved = fm_resolve_dir($dir);
	if($resolved === false){
		return "<h1>Files</h1><p>Invalid directory.</p>";
	}
	$entries = fm_list_entries($resolved);
	if($entries === false){
		return "<h1>Files</h1><p>Could not read this directory.</p>";
	}
	return $_SESSION['filemanagerObj']->filemanagerui->printManager($resolved, $entries);
}

function fmCreateDir($dir, $name){
	if(!ghoti_require_admin()){ return "Admin access required."; }
	$resolved = fm_resolve_dir($dir);
	$name = fm_clean_name($name);
	if($resolved === false || $name === false){ return "Invalid directory or name."; }
	if(fm_is_denied($name)){ return "That name is not allowed."; }
	$path = fm_target_path($resolved, $name);
	if(is_dir($path)){ return "A folder with that name already exists."; }
	if(!@mkdir($path, 0755)){
		return "Could not create the folder.";
	}
	ghoti::logInfo("filemanager.async.php:fmCreateFolder", "created folder '".$resolved[1].'/'.$name."' by uid ".ghoti_current_user_id());
	return true;
}

function fmRename($dir, $oldName, $newName){
	if(!ghoti_require_admin()){ return "Admin access required."; }
	$resolved = fm_resolve_dir($dir);
	$oldName = fm_clean_name($oldName);
	$newName = fm_clean_name($newName);
	if($resolved === false || $oldName === false || $newName === false){
		return "Invalid directory or name.";
	}
	if(fm_is_denied($oldName) || fm_is_denied($newName)){ return "That file is not allowed."; }
	if(strtolower($oldName) === strtolower($newName)){ return "No change."; }
	$from = fm_target_path($resolved, $oldName);
	$to   = fm_target_path($resolved, $newName);
	if(!file_exists($from)){ return "File not found."; }
	if(file_exists($to)){ return "A file with that name already exists."; }
	if(!@rename($from, $to)){
		return "Could not rename the file.";
	}
	ghoti::logInfo("filemanager.async.php:fmRename", "renamed '".$resolved[1].'/'.$oldName."' to '".$resolved[1].'/'.$newName."' by uid ".ghoti_current_user_id());
	return true;
}

/* Recursively delete a file or folder. Only ever touches paths inside the web
 * root (fm_resolve_dir + fm_resolve_target enforce that) and skips nothing on
 * the deny list. Symlinks are UNLINKED, never followed - a symlinked directory
 * must not cause recursion into its target. */
function fm_delete_path($root, $path){
	if(is_link($path)){ return @unlink($path); }
	if(!is_dir($path)){
		return @unlink($path);
	}
	$handle = @opendir($path);
	if($handle === false){ return false; }
	$ok = true;
	while(($entry = readdir($handle)) !== false){
		if($entry === '.' || $entry === '..'){ continue; }
		$child = $path.'/'.$entry;
		//Defense in depth: deny list + confinement re-checked per entry.
		if(fm_is_denied($entry)){ continue; }
		if(is_link($child)){
			//Never follow: remove the link itself, not whatever it points at.
			if(!@unlink($child)){ $ok = false; }
			continue;
		}
		$real = realpath($child);
		if($real === false || ($real !== $root && strpos($real, $root.'/') !== 0)){ $ok = false; continue; }
		if(!fm_delete_path($root, $real)){ $ok = false; }
	}
	closedir($handle);
	return $ok && @rmdir($path);
}

function fmDelete($dir, $name){
	if(!ghoti_require_admin()){ return "Admin access required."; }
	$resolved = fm_resolve_dir($dir);
	if($resolved === false){ return "Invalid directory or name."; }
	$name = fm_clean_name($name);
	if($name === false){ return "Invalid directory or name."; }
	//A top-level symlink is removed as a link, never acted on through its
	//target (consistent with fm_delete_path's child handling).
	$raw = fm_target_path($resolved, $name);
	if(is_link($raw)){
		if(!@unlink($raw)){
			return "Could not delete '".$name."'.";
		}
		ghoti::logInfo("filemanager.async.php:fmDelete", "deleted (symlink) '".$resolved[1].'/'.$name."' by uid ".ghoti_current_user_id());
		return true;
	}
	//Resolve the target too: a benign-looking link that realpaths outside the
	//root (or at a denied file) is refused, not deleted-through.
	$path = fm_resolve_target($resolved, $name);
	if($path === false){ return "File not found."; }
	if(!fm_delete_path($resolved[0], $path)){
		return "Could not delete '".$name."'.";
	}
	ghoti::logInfo("filemanager.async.php:fmDelete", "deleted '".$resolved[1].'/'.$name."' by uid ".ghoti_current_user_id());
	return true;
}

//Upload: args[0] = dir, multipart field 'file' (see ghoti_async_read_request).
function fmUpload($dir){
	if(!ghoti_require_admin()){ return "Admin access required."; }
	$resolved = fm_resolve_dir($dir);
	if($resolved === false){ return "Invalid directory."; }
	ghoti::logDebug("filemanager.async.php:fmUpload", "start dir='".$resolved[1]."' by uid ".ghoti_current_user_id());
	if(!is_writable($resolved[2])){ return "That directory is not writable."; }
	if(!isset($_FILES['file'])){
		return "No file was uploaded.";
	}
	$file = $_FILES['file'];
	if(!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK){
		$code = isset($file['error']) ? (int)$file['error'] : -1;
		ghoti::logWarn("filemanager.async.php:fmUpload", "PHP upload error $code for dir '".$resolved[1]."' by uid ".ghoti_current_user_id());
		return "Upload failed (error $code).";
	}
	if(!is_uploaded_file($file['tmp_name'])){
		ghoti::logError("filemanager.async.php:fmUpload", "is_uploaded_file() check failed (possible tampering) for dir '".$resolved[1]."' by uid ".ghoti_current_user_id());
		return "Upload failed.";
	}
	$maxUpload = fm_max_upload_bytes();
	//Re-check the real size on disk (the client-supplied size field is not
	//authoritative).
	if((int)$file['size'] > $maxUpload || @filesize($file['tmp_name']) > $maxUpload){
		$human = $maxUpload >= 1048576
			? floor($maxUpload/1048576)."MB"
			: max(1, floor($maxUpload/1024))."KB";
		ghoti::logWarn("filemanager.async.php:fmUpload", "rejected oversize upload (".$file['size']." bytes > $maxUpload) for dir '".$resolved[1]."' by uid ".ghoti_current_user_id());
		return "File is too large (max ".$human.").";
	}
	$name = fm_clean_name($file['name']);
	if($name === false){
		ghoti::logWarn("filemanager.async.php:fmUpload", "rejected filename '".$file['name']."' for dir '".$resolved[1]."' by uid ".ghoti_current_user_id());
		return "That filename is not allowed.";
	}
	if(fm_is_denied($name)){
		ghoti::logWarn("filemanager.async.php:fmUpload", "rejected denied-extension filename '$name' for dir '".$resolved[1]."' by uid ".ghoti_current_user_id());
		return "That filename is not allowed.";
	}
	$dest = fm_target_path($resolved, $name);
	if(file_exists($dest)){ return "A file with that name already exists."; }
	if(!move_uploaded_file($file['tmp_name'], $dest)){
		ghoti::logError("filemanager.async.php:fmUpload", "move_uploaded_file() failed for '".$resolved[1].'/'.$name."' by uid ".ghoti_current_user_id());
		return "Could not save the file.";
	}
	@chmod($dest, 0644);
	ghoti::logInfo("filemanager.async.php:fmUpload", "uploaded '".$resolved[1].'/'.$name."' (".$file['size']." bytes) by uid ".ghoti_current_user_id());
	return true;
}

//Open a small text file for editing (returns editor HTML).
function fmGetTextFile($dir, $name){
	if(!ghoti_require_admin()){ return "<p>Admin access required.</p>"; }
	$resolved = fm_resolve_dir($dir);
	if($resolved === false){ return "<p>Invalid file.</p>"; }
	$name = fm_clean_name($name);
	if($name === false){ return "<p>Invalid file.</p>"; }
	if(!fm_is_text_editable($name)){ return "<p>That file type cannot be edited here.</p>"; }
	//fm_resolve_target realpaths the final path so a symlinked file can't smuggle
	//reads outside the web root, and deny-checks the resolved basename.
	$path = fm_resolve_target($resolved, $name);
	if($path === false){ return "<p>File not found.</p>"; }
	if(filesize($path) > FM_MAX_TEXT){ return "<p>File is too large to edit (max ".floor(FM_MAX_TEXT/1024)."KB).</p>"; }
	$content = @file_get_contents($path);
	if($content === false){ return "<p>Could not read the file.</p>"; }
	if(strpos($content, "\0") !== false){ return "<p>This looks like a binary file and cannot be edited as text.</p>"; }
	return $_SESSION['filemanagerObj']->filemanagerui->printEditor($resolved, $name, $content);
}

//Save a text file opened with fmGetTextFile.
function fmSaveTextFile($dir, $name, $content){
	if(!ghoti_require_admin()){ return "Admin access required."; }
	$resolved = fm_resolve_dir($dir);
	if($resolved === false){ return "Invalid file."; }
	$name = fm_clean_name($name);
	if($name === false){ return "Invalid file."; }
	if(!fm_is_text_editable($name)){ return "That file type cannot be edited here."; }
	//Same realpath treatment as fmGetTextFile - never write through a symlink
	//that leaves the web root or points at a denied file.
	$path = fm_resolve_target($resolved, $name);
	if($path === false){ return "File not found."; }
	if(!is_string($content) || strlen($content) > FM_MAX_TEXT){
		return "File is too large to save (max ".floor(FM_MAX_TEXT/1024)."KB).";
	}
	if(!is_writable($path)){ return "The file is not writable."; }
	if(@file_put_contents($path, $content, LOCK_EX) === false){
		return "Could not save the file.";
	}
	ghoti::logInfo("filemanager.async.php:fmSaveTextFile", "saved '".$resolved[1].'/'.$name."' by uid ".ghoti_current_user_id());
	return true;
}

ghoti_async_register(
	"getFileManager",
	"fmCreateDir",
	"fmRename",
	"fmDelete",
	"fmUpload",
	"fmGetTextFile",
	"fmSaveTextFile"
);

/* ---------------------------------------------------------------- *
 *  UI renderer (class filemanagerui)
 * ---------------------------------------------------------------- */

class filemanagerui{
	public $output;

	//Main listing: breadcrumbs, toolbar (upload / new folder), table of entries.
	public function printManager($resolved, $entries){
		$esc = function($value){ return htmlspecialchars((string)$value, ENT_QUOTES); };
		$dir = $resolved[1];
		$root = $resolved[0];

		$o  = "<section id=\"ghotiFileManager\" class=\"ghotiAdminPanel\">\n";
		$o .= "<div class=\"ghotiCrudHeader\"><div><h1>Files</h1><p class=\"ghotiHelpText\">Site assets: gfx/, css/ and other files under the web root.</p></div>";
		$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonSecondary\" onclick=\"fmNewFolder();\">New Folder</button>";
		$o .= "<button type=\"button\" class=\"ghotiButton\" onclick=\"fmToggleUpload();\">Upload</button></div>\n";

		//Breadcrumbs.
		$o .= "<nav class=\"ghotiBreadcrumbs\" aria-label=\"Location\">\n";
		$o .= "<a href=\"#\" class=\"fmDirLink\" data-dir=\"\">root</a>\n";
		$crumbs = array();
		$parts = ($dir === '') ? array() : explode('/', $dir);
		$walk = '';
		foreach($parts as $part){
			$walk = ($walk === '') ? $part : $walk.'/'.$part;
			$crumbs[] = array('label' => $part, 'dir' => $walk);
		}
		foreach($crumbs as $crumb){
			$o .= "<span class=\"ghotiBreadcrumbSep\">/</span>";
			if($crumb['dir'] === $dir){
				$o .= "<span class=\"ghotiBreadcrumbCurrent\">".$esc($crumb['label'])."</span>\n";
			}else{
				$o .= "<a href=\"#\" class=\"fmDirLink\" data-dir=\"".$esc($crumb['dir'])."\">".$esc($crumb['label'])."</a>\n";
			}
		}
		$o .= "</nav>\n";

		//Upload zone (hidden until "Upload" is pressed).
		$o .= "<div id=\"fmUploadZone\" class=\"fmUploadZone\" hidden=\"hidden\">\n";
		$o .= "<div class=\"fmUploadZoneInner\"><span class=\"fmUploadIcon\">&#8682;</span><span><b>Drop files here</b> or click to browse</span><input type=\"file\" id=\"fmUploadInput\" multiple /></div>\n";
		$o .= "<p id=\"fmUploadProgress\" class=\"ghotiHelpText\"></p>\n";
		$o .= "</div>\n";

		//Entry table.
		$o .= "<table class=\"ghotiManageTable fmTable\">\n";
		$o .= "<thead><tr><th>Name</th><th>Size</th><th>Modified</th><th>Actions</th></tr></thead>\n<tbody>\n";
		if(empty($entries)){
			$o .= "<tr><td colspan=\"4\" class=\"ghotiEmptyState\">This folder is empty.</td></tr>\n";
		}
		foreach($entries as $entry){
			$name = $esc($entry['name']);
			$date = $entry['mtime'] ? date('Y-m-d H:i', $entry['mtime']) : '';
			$o .= "<tr class=\"".($entry['isDir'] ? 'fmRowDir' : 'fmRowFile')."\">\n";
			if($entry['isDir']){
				$o .= "<td><a href=\"#\" class=\"fmDirLink fmEntryName\" data-dir=\"".$esc(($dir === '' ? '' : $dir.'/').$entry['name'])."\">&#128193; ".$name."</a></td>\n";
			}else{
				$o .= "<td><span class=\"fmEntryName\">&#128196; ".$name."</span></td>\n";
			}
			$o .= "<td>".($entry['isDir'] ? '&mdash;' : $esc(fm_format_size($entry['size'])))."</td>\n";
			$o .= "<td>".$esc($date)."</td>\n";
			$o .= "<td class=\"ghotiCrudActions\">\n";
			if($entry['text']){
				$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" data-fm-edit data-dir=\"".$esc($dir)."\" data-name=\"".$name."\">Edit</button>";
			}
			if(!$entry['isDir']){
				$o .= "<a class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" data-fm-download data-dir=\"".$esc($dir)."\" data-name=\"".$name."\" href=\"#\">Get</a>";
			}
			$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" data-fm-rename data-dir=\"".$esc($dir)."\" data-name=\"".$name."\">Rename</button>";
			$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonDanger\" data-fm-delete data-dir=\"".$esc($dir)."\" data-name=\"".$name."\">Delete</button>";
			$o .= "</td>\n";
			$o .= "</tr>\n";
		}
		$o .= "</tbody></table>\n";
		$o .= "</section>\n";
		return $o;
	}

	//Text editor for one file.
	public function printEditor($resolved, $name, $content){
		$esc = function($value){ return htmlspecialchars((string)$value, ENT_QUOTES); };
		$dir = $resolved[1];
		$o  = "<section id=\"ghotiFileManager\" class=\"ghotiAdminPanel\">\n";
		$o .= "<div class=\"ghotiCrudHeader\"><div><h1>Edit file</h1><p class=\"ghotiHelpText\"><code>".$esc(($dir === '' ? '' : $dir.'/').$name)."</code> &mdash; <a href=\"#\" class=\"fmBackLink\">back to files</a></p></div></div>\n";
		$o .= "<form id=\"fmEditForm\" class=\"ghotiForm\" action=\"#\" onsubmit=\"fmSaveText(); return false;\">\n";
		$o .= "<textarea id=\"fmEditor\" class=\"fmEditor\" rows=\"26\" spellcheck=\"false\">".$esc($content)."</textarea>\n";
		$o .= "<div class=\"ghotiFormActions\"><button type=\"submit\" class=\"ghotiButton\">Save file</button>";
		$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonSecondary\" onclick=\"fmBack();\">Cancel</button></div>\n";
		$o .= "</form>\n";
		$o .= "</section>\n";
		return $o;
	}
}
?>
