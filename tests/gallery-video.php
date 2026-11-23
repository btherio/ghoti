<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
// Run with php tests/gallery-video.php. No database or real media files needed.
require dirname(__DIR__).'/ghoti.php';

function isAdmin($uid){ return $uid === 1; }

function galleryCheck($condition, $message){
	if(!$condition){ throw new RuntimeException($message); }
}

$fixture = sys_get_temp_dir().'/ghoti-gallery-'.bin2hex(random_bytes(8));
$module = $fixture.'/mod/gallery/gallery.async.php';
$videoDir = $fixture.'/files/gallery/videos';
$photoDir = $fixture.'/files/gallery/1';
$filename = 'Trip #2 & café.MP4';
$video = $videoDir.'/'.$filename;
$photo = $photoDir.'/photo.jpg';
mkdir(dirname($module), 0700, true);
mkdir($videoDir, 0700, true);
mkdir($photoDir, 0700);
ghoti::$ghotiLog = $fixture.'/test.log';

try{
	copy(dirname(__DIR__).'/mod/gallery/gallery.async.php', $module);
	require $module;
	$handle = fopen($video, 'wb');
	ftruncate($handle, 64 * 1024 * 1024);
	fclose($handle);
	touch($photo);
	symlink($video, $videoDir.'/link.mp4');

	$db = new class {
		public $items = array(
			1 => array('photoId' => 1, 'imageUrl' => 'files/gallery/1/photo.jpg', 'caption' => 'Photo')
		);
		public function getGalleryById($id){ return $id === 1 ? $this->getGalleryByName('trip') : false; }
		public function getGalleryByName($name){
			return $name === 'trip' ? array('galleryId' => 1, 'name' => 'trip', 'title' => 'Trip', 'description' => '') : false;
		}
		public function getPhotos($id){ return $this->items; }
		public function addPhoto($id, $url){
			$this->items[2] = array('photoId' => 2, 'imageUrl' => $url, 'caption' => '');
			return true;
		}
		public function deletePhoto($id){
			$item = $this->items[$id];
			unset($this->items[$id]);
			return $item;
		}
		public function deleteGallery($id){ $this->items = array(); return true; }
	};
	$_SESSION = array('galleryObj' => (object)array('gallerydb' => $db, 'galleryui' => new galleryui()));
	$_SERVER['SCRIPT_NAME'] = '/ghoti/index.php';

	galleryCheck(ghoti_async_is_registered('addGalleryVideo'), 'The add-video endpoint is missing');
	galleryCheck(addGalleryVideo(1, $filename) === 'Admin access required.', 'A visitor can add videos');
	$_SESSION['loggedIn'] = true;
	$_SESSION['userId'] = 2;
	galleryCheck(addGalleryVideo(1, $filename) === 'Admin access required.', 'A non-admin can add videos');
	$_SESSION['userId'] = 1;
	foreach(array('../Trip.mp4', 'folder\\Trip.mp4', '.hidden.mp4', 'photo.jpg', 'missing.webm', 'link.mp4', array($filename)) as $invalid){
		galleryCheck(addGalleryVideo(1, $invalid) !== true, 'An invalid or unreadable file was added');
	}
	galleryCheck(addGalleryVideo(99, $filename) !== true, 'A video was added to a missing gallery');
	galleryCheck(addGalleryVideo(1, $filename) === true, 'A 64 MiB server video could not be added');
	$url = '/ghoti/files/gallery/videos/'.rawurlencode($filename);
	foreach(array(getGalleryById(1), ghoti_expand_shortcodes('[gallery:trip]')) as $html){
		galleryCheck(strpos($html, '<video controls playsinline preload="none" src="'.$url.'"') !== false, 'No inline video in the gallery');
		galleryCheck(strpos($html, 'href="'.$url.'?download=1" download="'.htmlspecialchars($filename, ENT_QUOTES).'"') !== false, 'No download with the original filename');
		galleryCheck(substr_count($html, 'class="ghotiGalleryItem"') === 1 && strpos($html, '<img src="/ghoti/files/gallery/1/photo.jpg"') !== false, 'The photo lightbox markup changed');
	}
	galleryCheck(strpos(getGalleryEditor(1), htmlspecialchars($filename, ENT_QUOTES)) !== false, 'The editor does not identify the video');
	galleryCheck(deletePhoto(2) === true && is_file($video), 'Removing a gallery entry deleted its server video');
	addGalleryVideo(1, $filename);
	galleryCheck(deleteGallery(1) === true && is_file($video), 'Deleting a gallery deleted its server video');
	galleryCheck(!is_file($photo), 'Deleting a gallery left its uploaded photo behind');
	echo "PASS: gallery server video registration, rendering and removal\n";
}finally{
	foreach(array($videoDir.'/link.mp4', $video, $photo, $module, ghoti::$ghotiLog) as $file){
		if(is_link($file) || is_file($file)){ unlink($file); }
	}
	foreach(array($photoDir, $videoDir, dirname($videoDir), $fixture.'/files', dirname($module), $fixture.'/mod', $fixture) as $dir){
		rmdir($dir);
	}
}
