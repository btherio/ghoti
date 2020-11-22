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
			$banner = $this->adodb->Execute("SELECT id,alt,imgUrl,linkUrl,smallBanner FROM `banners` order by smallBanner,linkUrl;");
		}catch (exception $e){
			ghoti::log("banners.db.php $e");
			return false;
		}
		return $banner;
	}
	public function getRandomBanner($smallBanner=true){
		try{	
			if($smallBanner){
				$banner = $this->adodb->Execute("SELECT id,alt,imgUrl,linkUrl FROM `banners` WHERE smallBanner=1 ORDER BY RAND() LIMIT 1;");
			}else{
				$banner = $this->adodb->Execute("SELECT id,alt,imgUrl,linkUrl FROM `banners` WHERE smallBanner=0 ORDER BY RAND() LIMIT 1;");
			}
		}catch (exception $e){
			ghoti::log("banners.db.php $e");
			return false;
		}
		return $banner;
	}
	function addBanner($alt,$imgUrl,$linkUrl,$smallBanner){
		addslashes($linkUrl);
		try{
			$nonQuery = $this->adodb->Execute("insert into banners(alt,imgUrl,linkUrl,smallBanner) values(?,?,?,?)",array($alt,$imgUrl,$linkUrl,(integer)$smallBanner));
			if (!$nonQuery){
				mylogerr($this->adodb->ErrorMsg());	
				ghoti::log("banners.db.php: $this->adodb->ErrorMsg()");
				throw new Exception ('Database error. Check ghoti logs.');
			}
		}catch (exception $e){
			ghoti::log("banners.db.php $e");
			return false;
		}
		return true;
	}
	function deleteBanner($id){
		try{
			$nonQuery = $this->adodb->Execute("delete from banners where id=?",array($id));
			if (!$nonQuery){
				mylogerr($this->adodb->ErrorMsg());	
				ghoti::log("banners.db.php: $this->adodb->ErrorMsg()");
				throw new Exception ('Database error. Check ghoti logs.');
			}
		}catch (exception $e){
			ghoti::log("banners.db.php $e");
			return false;
		}
		return true;
	}
	function editBanner($id,$alt,$imgUrl,$linkUrl,$smallBanner){
		try{
			$nonQuery = $this->adodb->Execute("update banners set alt=?,imgUrl=?,linkUrl=?,smallBanner=? where id=?",array($alt,$imgUrl,$linkUrl,$smallBanner,$id));
			if (!$nonQuery){
				mylogerr($this->adodb->ErrorMsg());	
				
				ghoti::log("banners.db.php: $this->adodb->ErrorMsg()");
				throw new Exception ('Database error. Check ghoti logs.');
			}	
		}catch (exception $e){
			ghoti::log("banners.db.php $e");
			return false;
		}
		return true;
	}
}
?>
