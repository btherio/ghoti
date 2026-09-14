<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
/*
 * Run: php tests/schema-upgrade.php - no database.
 *
 * `create table if not exists` does nothing to a table that already exists, so
 * upgrading a live site left older module tables missing every column added
 * since - "Unknown column 'tlsVerify'" on every mail settings read. The fix
 * reads each module's .sql and adds what the live table is missing; the parser
 * that reads those files is what this covers, against the real files.
 */
chdir(dirname(__DIR__));
require_once 'ghoti.php';
$checks = 0;
function schemaCheck($ok, $label){ global $checks; if(!$ok){throw new RuntimeException($label);} $checks++; }

/* ---------------- the file that caused the bug ---------------- */

$mail = ghotidb::parseModuleColumns(file_get_contents('mod/mail/mail.sql'));
schemaCheck(isset($mail['mail']), 'mail.sql produced no table');
foreach(array('id','smtpHost','smtpPort','encryption','smtpUsername','smtpPassword',
	'tlsVerify','tlsCaFile','tlsPeerName','fromAddress','fromName','enabled','updatedAt') as $column){
	schemaCheck(isset($mail['mail'][$column]), "mail.$column was not parsed");
}
//The definition has to be usable as the tail of an ALTER TABLE ADD COLUMN, so
//the trailing comma and the explanatory comment must both be gone.
schemaCheck($mail['mail']['tlsVerify'] === 'int(1) not null default 1', 'tlsVerify definition wrong: '.$mail['mail']['tlsVerify']);
schemaCheck(strpos($mail['mail']['smtpPassword'], '--') === false, 'A trailing SQL comment survived parsing');
schemaCheck(substr($mail['mail']['fromName'], -1) !== ',', 'A trailing comma survived parsing');
schemaCheck(!isset($mail['mail']['PRIMARY']), 'A PRIMARY KEY line was read as a column');

/* ---------------- every module ships a parsable schema ---------------- */

$expected = array(
	'pages'     => array('pages' => array('id','title','content','groupName')),
	'links'     => array('links' => array('id','userId','name','url','grp')),
	'gallery'   => array('gallery' => array('galleryId','name','title'),
	                     'gallery_photos' => array('photoId','galleryId','imageUrl')),
	'analytics' => array('analytics' => array('id')),
	//banners is the shape the loader is most easily wrong about: an install
	//from before ad support has `banners` but no `banner_settings`, so the
	//upgrade has to CREATE a table rather than only ALTER existing ones.
	'banners'   => array('banners' => array('id','alt','imgUrl','linkUrl','smallBanner'),
	                     'banner_settings' => array('source','adClient','adSlotSmall','adSlotLarge','adFormat','adTest')),
	'store'     => array('store' => array('paypalClientId','paypalSecret','currency'),
	                     'store_products' => array('productId','sku','priceCents','kind','downloadPath'),
	                     'store_orders' => array('orderId','reference','status','paypalOrderId','totalCents'),
	                     'store_order_items' => array('itemId','orderId','unitCents'),
	                     'store_downloads' => array('downloadId','token','expiresAt')),
);
foreach(glob('mod/*/') as $dir){
	$module = basename($dir);
	$file = $dir.$module.'.sql';
	if(!is_file($file)){ continue; }
	$tables = ghotidb::parseModuleColumns(file_get_contents($file));
	schemaCheck(!empty($tables), "$module.sql parsed to nothing");
	foreach($tables as $table => $columns){
		schemaCheck(!empty($columns), "$module.$table parsed with no columns");
		foreach($columns as $name => $definition){
			schemaCheck(preg_match('/^[A-Za-z0-9_]+$/', $name) === 1, "$module.$table has a bogus column name: $name");
			schemaCheck($definition !== '' && strpos($definition, '--') === false, "$module.$table.$name has an unusable definition: $definition");
			schemaCheck(stripos($name, 'key') !== 0 || $name === 'key', "$module.$table read a KEY line as a column: $name");
		}
	}
	if(isset($expected[$module])){
		foreach($expected[$module] as $table => $columns){
			schemaCheck(isset($tables[$table]), "$module.sql did not yield table $table");
			foreach($columns as $column){
				schemaCheck(isset($tables[$table][$column]), "$module.$table.$column was not parsed");
			}
		}
	}
}

/* ---------------- shapes the parser must refuse or survive ---------------- */

//An auto_increment column cannot be added by ALTER without a key, so the caller
//skips it; it still has to be parsed, or that decision never gets made.
$store = ghotidb::parseModuleColumns(file_get_contents('mod/store/store.sql'));
schemaCheck(stripos($store['store_products']['productId'], 'auto_increment') !== false, 'auto_increment not visible to the caller');

schemaCheck(ghotidb::parseModuleColumns('') === array(), 'Empty SQL produced tables');
schemaCheck(ghotidb::parseModuleColumns('this is not sql at all') === array(), 'Nonsense SQL produced tables');
schemaCheck(ghotidb::parseModuleColumns('create table if not exists x(`a` int(11) not null) ENGINE=InnoDB;') === array('x' => array('a' => 'int(11) not null')),
	'A one-line create table was not parsed');

//Keys, constraints and blank lines are not columns.
$mixed = ghotidb::parseModuleColumns("create table if not exists t(\n\t`a` int(11) not null,\n\n  PRIMARY KEY  (`a`),\n  UNIQUE KEY `uq` (`a`),\n  KEY `idx` (`a`)\n) ENGINE=InnoDB ;");
schemaCheck($mixed === array('t' => array('a' => 'int(11) not null')), 'Key definitions leaked in as columns: '.json_encode($mixed));

/* ---------------- the upgrade pass itself ----------------
 * A stand-in for the database: it reports the columns an older install would
 * have and records the ALTER statements the upgrade decides to issue. This is
 * the decision the live site got wrong, so it is worth testing even though the
 * SQL cannot be executed here. */

class FakeUpgradePdo{
	public $owner;
	public function __construct($owner){ $this->owner = $owner; }
	public function exec($sql){
		$this->owner->statements[] = $sql;
		if(preg_match('/ALTER TABLE `([A-Za-z0-9_]+)` ADD COLUMN `([A-Za-z0-9_]+)`/', $sql, $m)){
			if(isset($this->owner->existing[$m[1]][$m[2]])){
				throw new RuntimeException("SQLSTATE[42S21]: Duplicate column name '".$m[2]."'");
			}
			$this->owner->existing[$m[1]][$m[2]] = true;
		}
		return 1;
	}
}

class FakeUpgradeDb extends ghotidb{
	public $existing;      //table => column => true
	public $statements = array();
	public function __construct($existing){ $this->existing = $existing; }
	public function __destruct(){}
	protected function db(){ return new FakeUpgradePdo($this); }
	protected function tableExists($table){ return isset($this->existing[$table]); }
	protected function queryArray($sql, array $params = array()){
		if(preg_match('/SHOW COLUMNS FROM `([A-Za-z0-9_]+)`/', $sql, $m)){
			$rows = array();
			foreach(array_keys($this->existing[$m[1]]) as $column){ $rows[] = array($column); }
			return $rows;
		}
		return array();
	}
}

function runUpgrade($existing, $sqlFile){
	$db = new FakeUpgradeDb($existing);
	$method = new ReflectionMethod('ghotidb', 'ensureModuleSchema');
	$method->setAccessible(true);
	$method->invoke($db, 'mail', file_get_contents($sqlFile));
	return $db;
}

//Keep the upgrade's log lines out of the application log.
$log = tempnam(sys_get_temp_dir(), 'ghoti-schema-test-');
ghoti::$ghotiLog = $log;
try {
	//The exact shape of the reported bug: a mail table from before the TLS
	//columns existed.
	$old = array('mail' => array('id'=>true,'smtpHost'=>true,'smtpPort'=>true,'encryption'=>true,
		'smtpUsername'=>true,'smtpPassword'=>true,'fromAddress'=>true,'fromName'=>true,'enabled'=>true,'updatedAt'=>true));
	$db = runUpgrade($old, 'mod/mail/mail.sql');
	$added = array();
	foreach($db->statements as $sql){
		if(preg_match('/ADD COLUMN `([A-Za-z0-9_]+)` (.+)$/', $sql, $m)){ $added[$m[1]] = $m[2]; }
	}
	schemaCheck(count($db->statements) === 3, 'Wrong number of ALTERs: '.count($db->statements));
	foreach(array('tlsVerify','tlsCaFile','tlsPeerName') as $column){
		schemaCheck(isset($added[$column]), "Missing column $column was not added");
	}
	schemaCheck($added['tlsVerify'] === 'int(1) not null default 1', 'tlsVerify added with the wrong definition: '.$added['tlsVerify']);
	schemaCheck(!isset($added['smtpHost']), 'An existing column was re-added');

	//A table that already matches is left completely alone.
	$current = array('mail' => array());
	foreach(ghotidb::parseModuleColumns(file_get_contents('mod/mail/mail.sql'))['mail'] as $column => $definition){
		$current['mail'][$column] = true;
	}
	$db = runUpgrade($current, 'mod/mail/mail.sql');
	schemaCheck($db->statements === array(), 'An up-to-date table was altered anyway');

	//A table the module has gained since is left to the create-table pass, not
	//half-built one column at a time.
	$db = runUpgrade(array('store' => array('id'=>true)), 'mod/store/store.sql');
	foreach($db->statements as $sql){
		schemaCheck(strpos($sql, 'ALTER TABLE `store`') === 0, 'Altered a table that does not exist yet: '.$sql);
	}

	//An auto_increment column cannot be added by ALTER; it must be skipped
	//rather than issued as SQL that is certain to fail.
	$db = runUpgrade(array('store_products' => array('sku'=>true)), 'mod/store/store.sql');
	foreach($db->statements as $sql){
		schemaCheck(stripos($sql, 'auto_increment') === false, 'Issued an unusable auto_increment ALTER: '.$sql);
	}
	schemaCheck(count($db->statements) > 0, 'Nothing was added to an outdated table');

	//Two requests upgrading at once: the loser sees "Duplicate column name",
	//which must not escape (an ERROR line e-mails every administrator).
	$db = new FakeUpgradeDb($old);
	$method = new ReflectionMethod('ghotidb', 'ensureModuleSchema');
	$method->setAccessible(true);
	$method->invoke($db, 'mail', file_get_contents('mod/mail/mail.sql'));
	$raced = $method->invoke($db, 'mail', file_get_contents('mod/mail/mail.sql'));
	schemaCheck($raced === false, 'A second upgrade pass reported changes it did not make');

	echo "PASS: $checks module schema assertions; no database\n";
} finally {
	if(is_file($log)){ unlink($log); }
}
