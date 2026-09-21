<?php
/*
 * mail.db.php - storage for the mail module's SMTP settings.
 *
 * Follows the same shape as every other *.db.php: a class extending ghotidb,
 * loading its own SQL in the constructor via loadModuleSql(), and exposing a
 * handful of typed methods. The `mail` table holds exactly one row (id=1) of
 * admin-edited SMTP connection settings for your local Arch Linux mail
 * server (Postfix/Exim/etc. listening on localhost or on the LAN).
 *
 * NOTE on smtpPassword: most local mail servers reachable from the same host
 * or LAN are configured to accept authenticated submission from this app
 * without a password (trusted network / milter, or no auth at all - see
 * "none" encryption + blank username below). If your server *does* require
 * AUTH, the password is stored in the database as-is (not hashed - it must
 * be recoverable to authenticate an outbound SMTP session, same tradeoff any
 * mailer config faces). Treat DB access/backups accordingly; this mirrors
 * how db.config.php already holds the app's own DB credentials.
 */

class maildb extends ghotidb{
	public function __construct(){
		parent::__construct();
		parent::loadModuleSql("mail");
	}

	public function __destruct(){
		parent::__destruct();
	}

	//Returns the settings row as an associative array, or safe defaults if the
	//row is somehow missing (fresh install race, manual table edit, etc.).
	public function getSettings($throwOnError = false){
		try{
			$rows = $this->queryArray("select smtpHost,smtpPort,encryption,smtpUsername,smtpPassword,tlsVerify,tlsCaFile,tlsPeerName,fromAddress,fromName,enabled,updatedAt,successfulTestAt from mail where id = 1 limit 1");
			if(isset($rows[0])){
				$row = $rows[0];
				return array(
					'smtpHost'     => (string)$row[0],
					'smtpPort'     => (int)$row[1],
					'encryption'   => (string)$row[2],
					'smtpUsername' => (string)$row[3],
					'smtpPassword' => (string)$row[4],
					'tlsVerify'    => (int)$row[5] === 1,
					'tlsCaFile'    => (string)$row[6],
					'tlsPeerName'  => (string)$row[7],
					'fromAddress'  => (string)$row[8],
					'fromName'     => (string)$row[9],
					'enabled'      => (int)$row[10] === 1,
					'updatedAt'    => (int)$row[11],
					'successfulTestAt' => (int)$row[12],
				);
			}
		}catch (Throwable $e){
			ghoti::logException("mail.db.php:getSettings", $e);
			if($throwOnError){ throw $e; }
		}
		return self::defaultSettings();
	}

	public static function defaultSettings(){
		return array(
			'smtpHost' => '127.0.0.1', 'smtpPort' => 25, 'encryption' => 'none',
			'smtpUsername' => '', 'smtpPassword' => '',
			'tlsVerify' => true, 'tlsCaFile' => '', 'tlsPeerName' => '',
			'fromAddress' => '', 'fromName' => '', 'enabled' => false, 'updatedAt' => 0, 'successfulTestAt' => 0,
		);
	}

	// This marker cannot be set by the settings form or ordinary outbound mail.
	public function recordSuccessfulTest(){
		try{
			$this->query("update mail set successfulTestAt = ? where id = 1", array(time()));
			return (int)$this->getSettings()['successfulTestAt'] > 0;
		}catch(Throwable $e){
			ghoti::logException('mail.db.php:recordSuccessfulTest', $e);
			return false;
		}
	}

	//Upserts the single settings row. $settings keys mirror getSettings()'s
	//output; missing keys keep their previous value. Returns true or false.
	public function saveSettings($settings){
		$current = $this->getSettings();
		$merged = array_merge($current, $settings);
		try{
			$this->query(
				"insert into mail (id,smtpHost,smtpPort,encryption,smtpUsername,smtpPassword,tlsVerify,tlsCaFile,tlsPeerName,fromAddress,fromName,enabled,updatedAt) values (1,?,?,?,?,?,?,?,?,?,?,?,?)
				 on duplicate key update smtpHost=values(smtpHost), smtpPort=values(smtpPort), encryption=values(encryption),
				 smtpUsername=values(smtpUsername), smtpPassword=values(smtpPassword), tlsVerify=values(tlsVerify),
				 tlsCaFile=values(tlsCaFile), tlsPeerName=values(tlsPeerName), fromAddress=values(fromAddress),
				 fromName=values(fromName), enabled=values(enabled), updatedAt=values(updatedAt)",
				array(
					$merged['smtpHost'], $merged['smtpPort'], $merged['encryption'],
					$merged['smtpUsername'], $merged['smtpPassword'],
					$merged['tlsVerify'] ? 1 : 0, $merged['tlsCaFile'], $merged['tlsPeerName'],
					$merged['fromAddress'], $merged['fromName'],
					$merged['enabled'] ? 1 : 0, time(),
				)
			);
			return true;
		}catch (Throwable $e){
			ghoti::logException("mail.db.php:saveSettings", $e);
			return false;
		}
	}
}
?>
