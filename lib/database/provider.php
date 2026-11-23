<?php
/** One factory for normal requests, setup probes and command-line tools. */
final class GhotiDatabaseProvider {
    public const ENVIRONMENT_FIELDS = array(
        'driver'=>'DRIVER', 'host'=>'HOST', 'port'=>'PORT', 'database'=>'NAME', 'username'=>'USER',
        'password'=>'PASSWORD', 'charset'=>'CHARSET', 'sqlite_path'=>'SQLITE_PATH', 'authSource'=>'AUTH_SOURCE',
        'replicaSet'=>'REPLICA_SET', 'tls'=>'TLS', 'tlsCAFile'=>'TLS_CA_FILE'
    );

    public static function drivers(){ return array('mysql','sqlite','mongodb'); }
    public static function defaults($driver){
        return array('driver'=>$driver, 'host'=>'127.0.0.1',
            'port'=>$driver === 'mongodb' ? '27017' : '3306', 'database'=>'ghoti',
            'username'=>$driver === 'mysql' ? 'ghoti' : '', 'password'=>'', 'charset'=>'utf8mb4',
            'sqlite_path'=>dirname(__DIR__, 2).'/.ghoti-data/ghoti.sqlite', 'authSource'=>'admin', 'replicaSet'=>'', 'tls'=>$driver === 'mongodb', 'tlsCAFile'=>'');
    }
    public static function normalize(array $config){
        $driver = strtolower(trim((string)($config['driver'] ?? 'mysql')));
        if(!in_array($driver, self::drivers(), true)){ throw new InvalidArgumentException('Unsupported database provider.'); }
        $config = array_merge(self::defaults($driver), $config, array('driver'=>$driver));
        if($driver === 'sqlite'){
            $path = (string)$config['sqlite_path'];
            if($path !== ':memory:' && ($path === '' || $path[0] !== '/' || preg_match('/[\x00-\x1f]/', $path))){ throw new InvalidArgumentException('SQLite requires an absolute file path.'); }
            return $config;
        }
        if(!preg_match('/^[A-Za-z0-9_.-]{1,255}$/', (string)$config['host'])){ throw new InvalidArgumentException('Enter a valid database host.'); }
        if(!ctype_digit((string)$config['port']) || (int)$config['port'] < 1 || (int)$config['port'] > 65535){ throw new InvalidArgumentException('Enter a valid database port.'); }
        foreach(array('database','authSource') as $field){
            if(!preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string)$config[$field])){ throw new InvalidArgumentException('Enter a valid '.$field.'.'); }
        }
        if(!preg_match('/^[A-Za-z0-9_-]{1,32}$/', (string)$config['charset'])){ throw new InvalidArgumentException('Invalid database charset.'); }
        if(!preg_match('/^[A-Za-z0-9_.-]{0,128}$/', (string)$config['replicaSet'])){ throw new InvalidArgumentException('Invalid replica set name.'); }
        if($driver === 'mongodb'){
            $tls = filter_var($config['tls'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if($tls === null){ throw new InvalidArgumentException('MongoDB TLS must be true or false.'); }
            $config['tls'] = $tls;
            $caFile = (string)$config['tlsCAFile'];
            if($caFile !== '' && ($caFile[0] !== '/' || preg_match('/[\x00-\x1f]/', $caFile))){
                throw new InvalidArgumentException('MongoDB CA certificate requires an absolute file path.');
            }
            if($caFile !== '' && !$tls){ throw new InvalidArgumentException('Enable MongoDB TLS when specifying a CA certificate.'); }
            $config['tlsCAFile'] = $caFile;
        }
        return $config;
    }
    public static function connect(array $config){
        $config = self::normalize($config);
        if($config['driver'] === 'sqlite'){
            require_once __DIR__.'/sqlite.php'; return new GhotiSqliteConnection($config);
        }
        if($config['driver'] === 'mongodb'){
            require_once __DIR__.'/mongodb.php'; return new GhotiMongoConnection($config);
        }
        if(!in_array('mysql', PDO::getAvailableDrivers(), true)){ throw new RuntimeException('MySQL requires the PHP pdo_mysql extension.'); }
        $options = array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_NUM,
            PDO::ATTR_EMULATE_PREPARES=>true, PDO::ATTR_STRINGIFY_FETCHES=>true, PDO::ATTR_PERSISTENT=>false, PDO::ATTR_TIMEOUT=>4);
        if(defined('PDO::MYSQL_ATTR_INIT_COMMAND')){ $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET sql_mode=''"; }
        if(defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')){ $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true; }
        return new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset={$config['charset']}", $config['username'], $config['password'], $options);
    }
    public static function identity(array $config){
        $config = self::normalize($config);
        $fields = $config['driver'] === 'sqlite' ? array('driver','sqlite_path') : array('driver','host','port','database','username','authSource','replicaSet');
        return hash('sha256', json_encode(array_intersect_key($config, array_flip($fields))));
    }
}
