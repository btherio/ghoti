<?php
/*
 * vhosts.db.php - storage for the vhosts module's operating settings.
 *
 * Same shape as every other *.db.php: a class extending ghotidb that loads its
 * own SQL in the constructor via loadModuleSql(), and exposes typed methods.
 * The `vhosts` table holds exactly one row (id=1).
 *
 * What is deliberately NOT here: the vhost definitions themselves. Apache's
 * .conf files are the source of truth - certbot rewrites them, admins edit them
 * by hand, and a database mirror would silently drift out of date. The module
 * parses the live config on every request instead.
 */

class vhostsdb extends ghotidb{
	//Per-process: the column probe is worth doing once, not on every construct.
	private static $schemaReady = false;

	public function __construct(){
		parent::__construct();
		parent::loadModuleSql("vhosts");
		$this->ensureSchema();
	}

	/*
	 * Add columns introduced after the table first shipped.
	 *
	 * loadModuleSql() only ever runs "create table if not exists", so an
	 * install that provisioned the original table never sees new columns. Same
	 * shape as ghotidb::ensurePageSchema(): probe SHOW COLUMNS, add what is
	 * missing, and treat failure as non-fatal so a read-only DB user degrades
	 * to "notifications unavailable" rather than breaking every page load.
	 */
	private function ensureSchema(){
		if(self::$schemaReady){ return true; }
		try{
			$existing = array();
			foreach($this->queryArray("SHOW COLUMNS FROM vhosts") as $column){
				if(isset($column[0])){ $existing[(string)$column[0]] = true; }
			}
			$added = array(
				'notifyEmail'   => "ALTER TABLE vhosts ADD COLUMN notifyEmail varchar(255) NOT NULL DEFAULT ''",
				'notifyEnabled' => "ALTER TABLE vhosts ADD COLUMN notifyEnabled int(1) NOT NULL DEFAULT 0",
				'certState'     => "ALTER TABLE vhosts ADD COLUMN certState mediumtext NULL",
			);
			foreach($added as $column => $sql){
				if(!isset($existing[$column])){ $this->db()->exec($sql); }
			}
			self::$schemaReady = true;
			return true;
		}catch(Throwable $e){
			ghoti::logException("vhosts.db.php:ensureSchema", $e, "vhosts schema upgrade failed");
			return false;
		}
	}

	public function __destruct(){
		parent::__destruct();
	}

	//Returns the settings row as an associative array, or safe defaults if the
	//row is missing (fresh install race, manual table edit, etc.).
	public function getSettings(){
		try{
			$rows = $this->queryArray("select helperPath,dropInDir,readOnlyConf,docRootBase,logDir,certbotEmail,notifyEmail,notifyEnabled,enabled,updatedAt from vhosts where id = 1 limit 1");
			if(isset($rows[0])){
				$row = $rows[0];
				return array(
					'helperPath'   => (string)$row[0],
					'dropInDir'    => (string)$row[1],
					'readOnlyConf' => (string)$row[2],
					'docRootBase'  => (string)$row[3],
					'logDir'       => (string)$row[4],
					'certbotEmail' => (string)$row[5],
					'notifyEmail'  => (string)$row[6],
					'notifyEnabled'=> (int)$row[7] === 1,
					'enabled'      => (int)$row[8] === 1,
					'updatedAt'    => (int)$row[9],
				);
			}
		}catch (Throwable $e){
			ghoti::logException("vhosts.db.php:getSettings", $e);
		}
		return self::defaultSettings();
	}

	public static function defaultSettings(){
		return array(
			'helperPath'   => '/usr/local/sbin/ghoti-vhosts-helper',
			'dropInDir'    => '/etc/httpd/conf/conf.d',
			'readOnlyConf' => '/etc/httpd/conf/extra/httpd-vhosts.conf',
			'docRootBase'  => '/etc/httpd/docs',
			'logDir'       => '/var/log/httpd',
			'certbotEmail' => '',
			'notifyEmail'  => '',
			'notifyEnabled'=> false,
			'enabled'      => false,
			'updatedAt'    => 0,
		);
	}

	/*
	 * The last certificate state the certwatch script saw, as a map of
	 * certificate name => array('serial','expiry','daysLeft'). Kept out of
	 * getSettings() because only certwatch reads or writes it, and it is much
	 * larger than the rest of the row.
	 */
	public function getCertState(){
		try{
			$rows = $this->queryArray("select certState from vhosts where id = 1 limit 1");
			if(isset($rows[0][0]) && $rows[0][0] !== ''){
				$decoded = json_decode((string)$rows[0][0], true);
				if(is_array($decoded)){ return $decoded; }
			}
		}catch(Throwable $e){
			ghoti::logException("vhosts.db.php:getCertState", $e);
		}
		return array();
	}

	public function saveCertState($state){
		try{
			$json = json_encode($state, JSON_UNESCAPED_SLASHES);
			if($json === false){ return false; }
			$this->query("update vhosts set certState=? where id=1", array($json));
			return true;
		}catch(Throwable $e){
			ghoti::logException("vhosts.db.php:saveCertState", $e);
			return false;
		}
	}

	//Upserts the single settings row. $settings keys mirror getSettings()'s
	//output; missing keys keep their previous value. Returns true or false.
	public function saveSettings($settings){
		$merged = array_merge($this->getSettings(), $settings);
		try{
			$this->query(
				"insert into vhosts (id,helperPath,dropInDir,readOnlyConf,docRootBase,logDir,certbotEmail,notifyEmail,notifyEnabled,enabled,updatedAt) values (1,?,?,?,?,?,?,?,?,?,?)
				 on duplicate key update helperPath=values(helperPath), dropInDir=values(dropInDir), readOnlyConf=values(readOnlyConf),
				 docRootBase=values(docRootBase), logDir=values(logDir), certbotEmail=values(certbotEmail),
				 notifyEmail=values(notifyEmail), notifyEnabled=values(notifyEnabled),
				 enabled=values(enabled), updatedAt=values(updatedAt)",
				array(
					$merged['helperPath'], $merged['dropInDir'], $merged['readOnlyConf'],
					$merged['docRootBase'], $merged['logDir'], $merged['certbotEmail'],
					$merged['notifyEmail'], $merged['notifyEnabled'] ? 1 : 0,
					$merged['enabled'] ? 1 : 0, time(),
				)
			);
			return true;
		}catch (Throwable $e){
			ghoti::logException("vhosts.db.php:saveSettings", $e);
			return false;
		}
	}
}
?>
