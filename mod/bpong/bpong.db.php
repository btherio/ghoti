<?php
/*
 * bpong.db.php - the one settings row the pong module keeps.
 *
 * Same shape as every other *.db.php: extends ghotidb, loads its own SQL in the
 * constructor, and never throws at its callers.
 *
 * There is deliberately nothing else in here. The match runs in the visitor's
 * browser and no result is sent back, so there is no score table and no write
 * endpoint a visitor can reach - which is the whole reason this module can be
 * embedded in a public page without becoming an attack surface.
 */

class bpongdb extends ghotidb{
	public function __construct(){
		parent::__construct();
		parent::loadModuleSql("bpong");
	}

	public function __destruct(){
		parent::__destruct();
	}

	//Memoized for the same reason bannersdb memoizes: a page can hold more than
	//one [bpong:game], and each one asks for the settings. The object is dropped
	//from the session by ghoti_free_request_objects() before the session is
	//written, so the cache cannot outlive its request.
	private $settingsCache = null;

	public static function defaultSettings(){
		//The defaults are the terminal game's constants, so an install that has
		//never opened the settings screen plays exactly what bitcoin_pong.py
		//played: seven points, a five-cell paddle, a CPU that closes 15.5 cells
		//per second.
		return array(
			'winningScore' => 7,
			'paddleHeight' => 5,
			'cpuSpeed'     => 155,
			'showControls' => true,
			'updatedAt'    => 0,
		);
	}

	/* Bounds for each tunable. The browser is not trusted with these - they are
	 * clamped here as well as in the endpoint, because they are printed into the
	 * page as a JSON config and a nonsensical value makes an unplayable board
	 * rather than an error anyone would notice. */
	public static function limits(){
		return array(
			'winningScore' => array(1, 21),
			'paddleHeight' => array(3, 11),
			'cpuSpeed'     => array(60, 300),
		);
	}

	public static function clamp($key, $value){
		$limits = self::limits();
		if(!isset($limits[$key])){ return (int)$value; }
		list($min, $max) = $limits[$key];
		return max($min, min($max, (int)$value));
	}

	public function getSettings(){
		if($this->settingsCache !== null){ return $this->settingsCache; }
		$settings = self::defaultSettings();
		try{
			$rows = $this->queryArray("select winningScore,paddleHeight,cpuSpeed,showControls,updatedAt from bpong where id = 1 limit 1");
			if(isset($rows[0])){
				$row = $rows[0];
				$settings = array(
					'winningScore' => self::clamp('winningScore', $row[0]),
					'paddleHeight' => self::clamp('paddleHeight', $row[1]),
					'cpuSpeed'     => self::clamp('cpuSpeed', $row[2]),
					'showControls' => (int)$row[3] === 1,
					'updatedAt'    => (int)$row[4],
				);
			}
		}catch (Throwable $e){
			ghoti::logException("bpong.db.php:getSettings", $e);
		}
		$this->settingsCache = $settings;
		return $settings;
	}

	/*
	 * Upsert rather than update: insert.sql only seeds a module the first time
	 * its table is created, so an install that gains this table during an
	 * upgrade has no row until something writes one.
	 */
	public function saveSettings($settings){
		try{
			$settings = array_merge($this->getSettings(), $settings);
			$this->query(
				"insert into bpong (id,winningScore,paddleHeight,cpuSpeed,showControls,updatedAt)"
				." values (1,?,?,?,?,?)"
				." on duplicate key update winningScore=values(winningScore),paddleHeight=values(paddleHeight),"
				." cpuSpeed=values(cpuSpeed),showControls=values(showControls),updatedAt=values(updatedAt)",
				array(
					self::clamp('winningScore', $settings['winningScore']),
					self::clamp('paddleHeight', $settings['paddleHeight']),
					self::clamp('cpuSpeed', $settings['cpuSpeed']),
					!empty($settings['showControls']) ? 1 : 0,
					time()
				)
			);
			$this->settingsCache = null;
			return true;
		}catch (Throwable $e){
			ghoti::logException("bpong.db.php:saveSettings", $e);
			return false;
		}
	}
}
?>
