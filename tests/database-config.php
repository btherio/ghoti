<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
require_once __DIR__.'/../ghoti.db.php';
require_once __DIR__.'/../ghoti.validate.php';
require_once __DIR__.'/../lib/database/sql.php';
require_once __DIR__.'/../lib/database/schema.php';
require_once __DIR__.'/../ghoti.backup.lib.php';
$checks=0;
function configCheck($ok,$label){global $checks; if(!$ok){throw new RuntimeException($label);} $checks++;}
function configReject($callback,$label){try{$callback();}catch(Throwable $e){configCheck(true,$label);return;}throw new RuntimeException($label);}
configCheck(GhotiDbSchema::defaultValue(GhotiDbSchema::column("int(11) NOT NULL DEFAULT '0'")) === 0,'MySQL quoted integer default remains numeric in MongoDB');
configCheck(GhotiDbSchema::defaultValue(GhotiDbSchema::column("varchar(10) NOT NULL DEFAULT '0'")) === '0','Text default stays text');
configCheck(GhotiDbSchema::defaultValue(GhotiDbSchema::column('int(11) DEFAULT NULL')) === null,'Nullable integer default stays NULL');
$config = GhotiDatabaseProvider::normalize(array('driver'=>'mongodb'));
configCheck($config['host']==='127.0.0.1' && $config['port']==='27017' && $config['username']==='', 'Mongo defaults');
configCheck($config['tls'] === true && $config['tlsCAFile'] === '', 'MongoDB verifies TLS by default');
foreach(array('0','false',false) as $value){ configCheck(GhotiDatabaseProvider::normalize(array_merge($config,array('tls'=>$value)))['tls'] === false, 'Explicit plaintext test setting'); }
configCheck(GhotiDatabaseProvider::normalize(array_merge($config,array('tls'=>'true','tlsCAFile'=>'/etc/ghoti/ca.crt')))['tls'] === true, 'CA certificate and TLS accepted');
foreach(array(array('tls'=>'sometimes'),array('tlsCAFile'=>'relative.crt'),array('tlsCAFile'=>"/tmp/ca\0.crt"),array('tls'=>false,'tlsCAFile'=>'/etc/ca.crt')) as $invalid){
    configReject(fn()=>GhotiDatabaseProvider::normalize(array_merge($config,$invalid)), 'Invalid TLS configuration rejected');
}
configCheck(GhotiDatabaseProvider::drivers()===array('mysql','sqlite','mongodb'),'Supported providers');
$sqlite = GhotiDatabaseProvider::normalize(array('driver'=>'sqlite'));
configCheck($sqlite['sqlite_path']===dirname(__DIR__).'/.ghoti-data/ghoti.sqlite','Internal SQLite uses the installation data directory');
foreach(array('pgsql','mongodb;host=attacker') as $driver){ configReject(fn()=>GhotiDatabaseProvider::normalize(array('driver'=>$driver)), 'Unsupported driver'); }
foreach(array(array('host'=>'localhost;port=1'),array('port'=>'27017/x'),array('database'=>'ghoti/other'),array('authSource'=>'admin?x=y'),array('replicaSet'=>'rs&x=y')) as $invalid){
    configReject(fn()=>GhotiDatabaseProvider::normalize(array_merge($config,$invalid)),'Connection parameter validation');
}
configReject(fn()=>GhotiDatabaseProvider::normalize(array('driver'=>'sqlite','sqlite_path'=>'relative.sqlite')),'Relative SQLite path rejected');
configReject(fn()=>(new GhotiSql('delete from pages where id=?; delete from users',array(1)))->parse(),'Extra statement rejected');
configReject(fn()=>(new GhotiSql('select title from pages where id=?',array()))->parse(),'Missing binding rejected');
configReject(fn()=>(new GhotiSql('select title from pages',array(1)))->parse(),'Extra binding rejected');
configReject(fn()=>(new GhotiSql('update pages set title=? nonsense',array('x')))->parse(),'Unknown syntax rejected');
$payload = "' OR 1=1 -- \\n\n \$where";
$query = (new GhotiSql('select title from pages where title=?',array($payload)))->parse();
configCheck($query['where']['b']===array('k'=>'value','v'=>$payload),'Bindings remain literal AST values');
configCheck(ghoti_backup_excluded_path('.ghoti-data/ghoti.sqlite-wal'),'SQLite data excluded from site ZIP');
// Use a synthetic local override so this test never depends on or inspects
// the deployment's real credentials or provider-specific routing.
$fixture = sys_get_temp_dir().'/ghoti-config-test-'.bin2hex(random_bytes(8));
mkdir($fixture,0700);
$source = file_get_contents(__DIR__.'/../db.config.php');
$source = str_replace("require_once __DIR__.'/lib/database/provider.php';", 'require_once '.var_export(realpath(__DIR__.'/../lib/database/provider.php'),true).';', $source);
file_put_contents($fixture.'/db.config.php',$source);
file_put_contents($fixture.'/db.config.local.php', "<?php return array('driver'=>'mysql','host'=>'mysql.fixture','username'=>'fixture','password'=>'fixture-password');");
$loadFixture = function() use ($fixture){ return require $fixture.'/db.config.php'; };
$original = getenv('GHOTI_DB_DRIVER'); $originalUser = getenv('GHOTI_DB_USER'); $originalTls = getenv('GHOTI_DB_TLS'); $originalCa = getenv('GHOTI_DB_TLS_CA_FILE');
try{
    putenv('GHOTI_DB_DRIVER=mongodb'); putenv('GHOTI_DB_USER=');
    $loaded = $loadFixture();
    configCheck($loaded['driver']==='mongodb' && $loaded['host']==='127.0.0.1' && $loaded['username']==='' && $loaded['password']==='', 'Switching provider does not reuse local MySQL credentials');
    putenv('GHOTI_DB_TLS=false'); putenv('GHOTI_DB_TLS_CA_FILE=');
    configCheck($loadFixture()['tls'] === false, 'Environment false is a boolean, not a truthy string');
    putenv('GHOTI_DB_TLS=true'); putenv('GHOTI_DB_TLS_CA_FILE=/etc/ghoti/ca.crt');
    configCheck($loadFixture()['tlsCAFile'] === '/etc/ghoti/ca.crt', 'Environment CA certificate propagated');
    putenv('GHOTI_DB_DRIVER=sqlite');
    configCheck($loadFixture()['driver']==='sqlite','Environment overrides local provider');
}finally{
    putenv($original===false?'GHOTI_DB_DRIVER':'GHOTI_DB_DRIVER='.$original);
    putenv($originalUser===false?'GHOTI_DB_USER':'GHOTI_DB_USER='.$originalUser);
    putenv($originalTls===false?'GHOTI_DB_TLS':'GHOTI_DB_TLS='.$originalTls);
    putenv($originalCa===false?'GHOTI_DB_TLS_CA_FILE':'GHOTI_DB_TLS_CA_FILE='.$originalCa);
    unlink($fixture.'/db.config.php'); unlink($fixture.'/db.config.local.php'); rmdir($fixture);
}
configCheck(GhotiDatabaseProvider::identity($config)!==GhotiDatabaseProvider::identity(array_merge($config,array('database'=>'other'))),'Provisioning identity isolates databases');
echo "PASS: $checks provider configuration assertions\n";
