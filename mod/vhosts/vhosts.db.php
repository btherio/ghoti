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
	public function __construct(){
		parent::__construct();
		parent::loadModuleSql("vhosts");
	}

	public function __destruct(){
		parent::__destruct();
	}

	//Returns the settings row as an associative array, or safe defaults if the
	//row is missing (fresh install race, manual table edit, etc.).
	public function getSettings(){
		try{
			$rows = $this->queryArray("select helperPath,dropInDir,readOnlyConf,docRootBase,logDir,certbotEmail,enabled,updatedAt from vhosts where id = 1 limit 1");
			if(isset($rows[0])){
				$row = $rows[0];
				return array(
					'helperPath'   => (string)$row[0],
					'dropInDir'    => (string)$row[1],
					'readOnlyConf' => (string)$row[2],
					'docRootBase'  => (string)$row[3],
					'logDir'       => (string)$row[4],
					'certbotEmail' => (string)$row[5],
					'enabled'      => (int)$row[6] === 1,
					'updatedAt'    => (int)$row[7],
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
			'enabled'      => false,
			'updatedAt'    => 0,
		);
	}

	//Upserts the single settings row. $settings keys mirror getSettings()'s
	//output; missing keys keep their previous value. Returns true or false.
	public function saveSettings($settings){
		$merged = array_merge($this->getSettings(), $settings);
		try{
			$this->query(
				"insert into vhosts (id,helperPath,dropInDir,readOnlyConf,docRootBase,logDir,certbotEmail,enabled,updatedAt) values (1,?,?,?,?,?,?,?,?)
				 on duplicate key update helperPath=values(helperPath), dropInDir=values(dropInDir), readOnlyConf=values(readOnlyConf),
				 docRootBase=values(docRootBase), logDir=values(logDir), certbotEmail=values(certbotEmail),
				 enabled=values(enabled), updatedAt=values(updatedAt)",
				array(
					$merged['helperPath'], $merged['dropInDir'], $merged['readOnlyConf'],
					$merged['docRootBase'], $merged['logDir'], $merged['certbotEmail'],
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
