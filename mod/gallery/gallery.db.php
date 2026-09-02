<?php
/*
 * gallery.db.php - database layer for the gallery module.
 *
 * Data model:
 *   gallery         one row per album (name is a unique, shortcode-usable slug)
 *   gallery_photos  the images in an album, with a per-album sort order
 */
class gallerydb extends ghotidb{
	public function __construct(){
		parent::__construct();
		parent::loadModuleSql("gallery");
		$this->ensurePhotosTable();
	}

	public function __destruct(){
		parent::__destruct();
	}

	/*
	 * loadModuleSql("gallery") probes/creates the `gallery` table (see
	 * gallery.sql). The photos table lives in the same file, so it is created
	 * together on a fresh install. If someone drops just gallery_photos (the
	 * provisioning marker only tracks the primary table), recreate it lazily
	 * instead of failing every photo query.
	 */
	private static $photosChecked = false;
	private function ensurePhotosTable(){
		if(self::$photosChecked){ return; }
		self::$photosChecked = true;
		try{
			$this->query("SELECT 1 FROM `gallery_photos` LIMIT 1");
		}catch (Throwable $e){
			try{
				$sql = file_get_contents(__DIR__."/gallery.sql");
				if($sql !== false){
					//Run the file again - both CREATEs are IF NOT EXISTS.
					$this->db()->exec($sql);
				}
			}catch (Throwable $e2){
				ghoti::logException("gallery.db.php:ensurePhotosTable", $e2);
			}
		}
	}

	/* ---- galleries ---------------------------------------------------- */

	public function getGalleryByName($name){
		try{
			$rows = $this->queryArray("select galleryId,name,title,description,createdAt from gallery where name = ? limit 1",array($name));
		}catch (Throwable $e){
			ghoti::logException("gallery.db.php:getGalleryByName", $e);
			return false;
		}
		if(!isset($rows[0])){ return false; }
		return $this->formatGallery($rows[0]);
	}

	public function getGalleryById($id){
		try{
			$rows = $this->queryArray("select galleryId,name,title,description,createdAt from gallery where galleryId = ? limit 1",array((int)$id));
		}catch (Throwable $e){
			ghoti::logException("gallery.db.php:getGalleryById", $e);
			return false;
		}
		if(!isset($rows[0])){ return false; }
		return $this->formatGallery($rows[0]);
	}

	public function getAllGalleries(){
		try{
			$rows = $this->queryArray(
				"select g.galleryId,g.name,g.title,g.description,g.createdAt,count(p.photoId) as photoCount "
				."from gallery g left join gallery_photos p on p.galleryId = g.galleryId "
				."group by g.galleryId,g.name,g.title,g.description,g.createdAt "
				."order by g.createdAt desc, g.galleryId desc"
			);
		}catch (Throwable $e){
			ghoti::logException("gallery.db.php:getAllGalleries", $e);
			return false;
		}
		$out = array();
		foreach($rows as $row){
			$gallery = $this->formatGallery($row);
			$gallery['photoCount'] = isset($row[5]) ? (int)$row[5] : 0;
			$out[] = $gallery;
		}
		return $out;
	}

	public function nameInUse($name,$excludeId = 0){
		try{
			$rows = $this->queryArray("select galleryId from gallery where name = ? and galleryId <> ? limit 1",array($name,(int)$excludeId));
		}catch (Throwable $e){
			ghoti::logException("gallery.db.php:nameInUse", $e);
			return false;
		}
		return !empty($rows);
	}

	public function addGallery($name,$title,$description){
		try{
			$this->query(
				"insert into gallery (name,title,description,createdAt) values(?,?,?,?)",
				array($name,$title,$description,time())
			);
		}catch (Throwable $e){
			ghoti::logException("gallery.db.php:addGallery", $e);
			return false;
		}
		//lastInsertId() would need the statement; a plain SELECT is simplest here.
		$rows = $this->queryArray("select galleryId from gallery where name = ? limit 1",array($name));
		return isset($rows[0][0]) ? (int)$rows[0][0] : false;
	}

	public function editGallery($id,$name,$title,$description){
		try{
			$this->query(
				"update gallery set name=?,title=?,description=? where galleryId=?",
				array($name,$title,$description,(int)$id)
			);
		}catch (Throwable $e){
			ghoti::logException("gallery.db.php:editGallery", $e);
			return false;
		}
		return true;
	}

	public function deleteGallery($id){
		try{
			$this->query("delete from gallery where galleryId=?",array((int)$id));
			$this->query("delete from gallery_photos where galleryId=?",array((int)$id));
		}catch (Throwable $e){
			ghoti::logException("gallery.db.php:deleteGallery", $e);
			return false;
		}
		return true;
	}

	/* ---- photos ------------------------------------------------------- */

	public function getPhotos($galleryId){
		try{
			$rows = $this->queryArray(
				"select photoId,galleryId,imageUrl,caption,sortOrder from gallery_photos "
				."where galleryId = ? order by sortOrder asc, photoId asc",
				array((int)$galleryId)
			);
		}catch (Throwable $e){
			ghoti::logException("gallery.db.php:getPhotos", $e);
			return false;
		}
		$out = array();
		foreach($rows as $row){
			$out[] = array(
				'photoId'  => isset($row[0]) ? (int)$row[0] : 0,
				'galleryId'=> isset($row[1]) ? (int)$row[1] : 0,
				'imageUrl' => isset($row[2]) ? $row[2] : '',
				'caption'  => isset($row[3]) ? $row[3] : '',
				'sortOrder'=> isset($row[4]) ? (int)$row[4] : 0
			);
		}
		return $out;
	}

	public function addPhoto($galleryId,$imageUrl,$caption = ''){
		try{
			$rows = $this->queryArray(
				"select coalesce(max(sortOrder),0)+1 from gallery_photos where galleryId = ?",
				array((int)$galleryId)
			);
			$sortOrder = isset($rows[0][0]) ? (int)$rows[0][0] : 1;
			$this->query(
				"insert into gallery_photos (galleryId,imageUrl,caption,sortOrder,createdAt) values(?,?,?,?,?)",
				array((int)$galleryId,$imageUrl,$caption,$sortOrder,time())
			);
		}catch (Throwable $e){
			ghoti::logException("gallery.db.php:addPhoto", $e);
			return false;
		}
		return true;
	}

	public function deletePhoto($photoId){
		try{
			$rows = $this->queryArray("select imageUrl from gallery_photos where photoId = ? limit 1",array((int)$photoId));
			$imageUrl = isset($rows[0][0]) ? $rows[0][0] : '';
			$this->query("delete from gallery_photos where photoId=?",array((int)$photoId));
		}catch (Throwable $e){
			ghoti::logException("gallery.db.php:deletePhoto", $e);
			return false;
		}
		return array('deleted' => true, 'imageUrl' => $imageUrl);
	}

	public function setPhotoCaption($photoId,$caption){
		try{
			$this->query("update gallery_photos set caption=? where photoId=?",array($caption,(int)$photoId));
		}catch (Throwable $e){
			ghoti::logException("gallery.db.php:setPhotoCaption", $e);
			return false;
		}
		return true;
	}

	/* Swap a photo's sortOrder with its neighbour in the chosen direction. */
	public function movePhoto($photoId,$direction){
		$direction = ($direction === -1) ? -1 : 1;
		try{
			$rows = $this->queryArray(
				"select photoId,galleryId,sortOrder from gallery_photos where photoId = ? limit 1",
				array((int)$photoId)
			);
			if(!isset($rows[0])){ return false; }
			$galleryId = (int)$rows[0][1];
			$current   = (int)$rows[0][2];
			$cmp   = ($direction === -1) ? '<' : '>';
			$order = ($direction === -1) ? 'desc' : 'asc';
			$neighbours = $this->queryArray(
				"select photoId,sortOrder from gallery_photos "
				."where galleryId = ? and sortOrder $cmp ? order by sortOrder $order, photoId $order limit 1",
				array($galleryId,$current)
			);
			if(!isset($neighbours[0])){ return false; }
			$this->query(
				"update gallery_photos set sortOrder = case photoId when ? then ? when ? then ? else sortOrder end "
				."where photoId in (?,?)",
				array((int)$photoId,(int)$neighbours[0][1],(int)$neighbours[0][0],$current,(int)$photoId,(int)$neighbours[0][0])
			);
		}catch (Throwable $e){
			ghoti::logException("gallery.db.php:movePhoto", $e);
			return false;
		}
		return true;
	}

	/* ---- shared formatting -------------------------------------------- */

	private function formatGallery($row){
		return array(
			'galleryId'   => isset($row[0]) ? (int)$row[0] : 0,
			'name'        => isset($row[1]) ? $row[1] : '',
			'title'       => isset($row[2]) ? $row[2] : '',
			'description' => isset($row[3]) ? $row[3] : '',
			'createdAt'   => isset($row[4]) ? (int)$row[4] : 0
		);
	}
}
?>
