<?php
/*
 * Created on May 1, 2010
 */

class bannersdb extends ghotidb{
	public function __construct(){
		parent::__construct(); //this establishes our connection to the database.
		parent::loadModuleSql("banners");	//makes sure our sql is loaded for this module

	}

	public function __destruct(){
		parent::__destruct();
	}
	public function getAllBanners(){
		try{
			$banner = $this->query("SELECT id,alt,imgUrl,linkUrl,smallBanner FROM `banners` order by smallBanner,linkUrl;");
		}catch (Throwable $e){
			ghoti::log("banners.db.php ".$e->getMessage());
			return false;
		}
		return $banner;
	}
	public function getRandomBanner($smallBanner=true){
		try{
			if($smallBanner){
				$banner = $this->query("SELECT id,alt,imgUrl,linkUrl FROM `banners` WHERE smallBanner=1 ORDER BY RAND() LIMIT 1;");
			}else{
				$banner = $this->query("SELECT id,alt,imgUrl,linkUrl FROM `banners` WHERE smallBanner=0 ORDER BY RAND() LIMIT 1;");
			}
		}catch (Throwable $e){
			ghoti::log("banners.db.php ".$e->getMessage());
			return false;
		}
		return $banner;
	}
	function addBanner($alt,$imgUrl,$linkUrl,$smallBanner){
		try{
			$this->query("insert into banners(alt,imgUrl,linkUrl,smallBanner) values(?,?,?,?)",array($alt,$imgUrl,$linkUrl,(int)$smallBanner));
		}catch (Throwable $e){
			ghoti::log("banners.db.php ".$e->getMessage());
			return false;
		}
		return true;
	}
	function deleteBanner($id){
		try{
			$this->query("delete from banners where id=?",array($id));
		}catch (Throwable $e){
			ghoti::log("banners.db.php ".$e->getMessage());
			return false;
		}
		return true;
	}
	function editBanner($id,$alt,$imgUrl,$linkUrl,$smallBanner){
		try{
			$this->query("update banners set alt=?,imgUrl=?,linkUrl=?,smallBanner=? where id=?",array($alt,$imgUrl,$linkUrl,$smallBanner,$id));
		}catch (Throwable $e){
			ghoti::log("banners.db.php ".$e->getMessage());
			return false;
		}
		return true;
	}
}
?>
