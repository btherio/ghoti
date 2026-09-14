<?php
/*
 * banners.db.php - the banner list, and the ad settings that decide whether the
 * list is the only thing shown.
 *
 * Created on May 1, 2010. The `banner_settings` half is newer: one row, id 1,
 * read on every page render (twice or three times, in themes with more than one
 * banner position) and so memoized for the life of the request.
 */

class bannersdb extends ghotidb{
	public function __construct(){
		parent::__construct(); //this establishes our connection to the database.
		parent::loadModuleSql("banners");	//makes sure our sql is loaded for this module

	}

	public function __destruct(){
		parent::__destruct();
	}

	/* ---------------- ad settings ---------------- */

	//Memoized because index.php reads these to decide how wide the
	//content-security policy has to be, and then every banner position on the
	//page reads them again. One query per request, not four.
	//
	//Per-request is guaranteed rather than hoped for: this object lives in
	//$_SESSION, but ghoti_free_request_objects() unsets `bannersObj` on the
	//page-render path (index.php) and on every async path before the session is
	//written, so the cache cannot outlive the request that filled it. If that
	//ever stops being true, this cache has to move - a stale one would serve the
	//previous request's ad settings.
	private $settingsCache = null;

	public static function defaultSettings(){
		return array(
			'source'      => 'local',
			'adClient'    => '',
			'adSlotSmall' => '',
			'adSlotLarge' => '',
			'adFormat'    => 'auto',
			'adFullWidth' => true,
			'adTest'      => false,
			'adLabel'     => '',
			'updatedAt'   => 0,
		);
	}

	public static function validSources(){
		return array(
			'local'   => 'My own banners only',
			'adsense' => 'Google AdSense only',
			'both'    => 'Both, chosen at random per position',
		);
	}

	/*
	 * Always returns a usable array. A database that is unreachable, or an
	 * install whose banner_settings row has never been written, both land on
	 * the defaults - which mean "local banners, no ads". Failing closed matters
	 * here: the alternative would be emitting ad markup the policy then blocks.
	 */
	public function getSettings(){
		if($this->settingsCache !== null){ return $this->settingsCache; }
		$settings = self::defaultSettings();
		try{
			$rows = $this->queryArray("select source,adClient,adSlotSmall,adSlotLarge,adFormat,adFullWidth,adTest,adLabel,updatedAt from banner_settings where id = 1 limit 1");
			if(isset($rows[0])){
				$row = $rows[0];
				$source = (string)$row[0];
				$settings = array(
					'source'      => array_key_exists($source, self::validSources()) ? $source : 'local',
					'adClient'    => (string)$row[1],
					'adSlotSmall' => (string)$row[2],
					'adSlotLarge' => (string)$row[3],
					'adFormat'    => (string)$row[4],
					'adFullWidth' => (int)$row[5] === 1,
					'adTest'      => (int)$row[6] === 1,
					'adLabel'     => (string)$row[7],
					'updatedAt'   => (int)$row[8],
				);
			}
		}catch (Throwable $e){
			ghoti::logException("banners.db.php:getSettings", $e);
		}
		$this->settingsCache = $settings;
		return $settings;
	}

	/*
	 * Upsert, for the same reason store.db.php does: insert.sql only seeds a
	 * module the first time its table is created, so an install that gains this
	 * table during an upgrade has no row until something writes one.
	 */
	public function saveSettings($settings){
		try{
			$settings = array_merge($this->getSettings(), $settings);
			$this->query(
				"insert into banner_settings (id,source,adClient,adSlotSmall,adSlotLarge,adFormat,adFullWidth,adTest,adLabel,updatedAt)"
				." values (1,?,?,?,?,?,?,?,?,?)"
				." on duplicate key update source=values(source),adClient=values(adClient),adSlotSmall=values(adSlotSmall),"
				." adSlotLarge=values(adSlotLarge),adFormat=values(adFormat),adFullWidth=values(adFullWidth),"
				." adTest=values(adTest),adLabel=values(adLabel),updatedAt=values(updatedAt)",
				array(
					$settings['source'], $settings['adClient'], $settings['adSlotSmall'], $settings['adSlotLarge'],
					$settings['adFormat'], !empty($settings['adFullWidth']) ? 1 : 0, !empty($settings['adTest']) ? 1 : 0,
					$settings['adLabel'], time()
				)
			);
			$this->settingsCache = null;
			return true;
		}catch (Throwable $e){
			ghoti::logException("banners.db.php:saveSettings", $e);
			return false;
		}
	}

	/* ---------------- banner list ---------------- */

	/*
	 * How many of this site's own banners exist at this size. The "both" source
	 * draws one winner from the local banners plus the ad unit, and it can only
	 * do that fairly if it knows how big the local half of the pool is.
	 */
	public function countBanners($smallBanner=true){
		try{
			$rows = $this->queryArray("SELECT count(*) FROM `banners` WHERE smallBanner=?", array($smallBanner ? 1 : 0));
			return isset($rows[0][0]) ? (int)$rows[0][0] : 0;
		}catch (Throwable $e){
			ghoti::logException("banners.db.php:countBanners", $e);
			return 0;
		}
	}

	public function getAllBanners(){
		try{
			$banner = $this->query("SELECT id,alt,imgUrl,linkUrl,smallBanner FROM `banners` order by smallBanner,linkUrl;");
		}catch (Throwable $e){
			ghoti::logException("banners.db.php:getAllBanners", $e);
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
			ghoti::logException("banners.db.php:getRandomBanner", $e);
			return false;
		}
		return $banner;
	}
	function addBanner($alt,$imgUrl,$linkUrl,$smallBanner){
		try{
			$this->query("insert into banners(alt,imgUrl,linkUrl,smallBanner) values(?,?,?,?)",array($alt,$imgUrl,$linkUrl,(int)$smallBanner));
		}catch (Throwable $e){
			ghoti::logException("banners.db.php:addBanner", $e);
			return false;
		}
		return true;
	}
	function deleteBanner($id){
		try{
			$this->query("delete from banners where id=?",array($id));
		}catch (Throwable $e){
			ghoti::logException("banners.db.php:deleteBanner", $e);
			return false;
		}
		return true;
	}
	function editBanner($id,$alt,$imgUrl,$linkUrl,$smallBanner){
		try{
			$this->query("update banners set alt=?,imgUrl=?,linkUrl=?,smallBanner=? where id=?",array($alt,$imgUrl,$linkUrl,$smallBanner,$id));
		}catch (Throwable $e){
			ghoti::logException("banners.db.php:editBanner", $e);
			return false;
		}
		return true;
	}
}
?>
