<?php
require_once __DIR__.'/schema.php';
require_once __DIR__.'/connection.php';

final class GhotiSqliteConnection extends GhotiDbConnection {
    private $pdo;
    private $path;
    private $locks = array();
    public function __construct(array $config){
        if(!in_array('sqlite', PDO::getAvailableDrivers(), true)){ throw new RuntimeException('SQLite requires the PHP pdo_sqlite extension.'); }
        $this->path = $config['sqlite_path'];
        if($this->path !== ':memory:'){
            $dir = dirname($this->path);
            if(!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)){ throw new RuntimeException('Could not create the SQLite directory.'); }
        }
        $pdoClass = class_exists(Pdo\Sqlite::class) ? Pdo\Sqlite::class : PDO::class;
        $this->pdo = new $pdoClass('sqlite:'.$this->path, null, null, array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES=>true, PDO::ATTR_TIMEOUT=>5));
        if($this->path !== ':memory:'){ chmod($this->path, 0600); }
        $this->pdo->exec('PRAGMA foreign_keys=ON');
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $createFunction = $this->pdo instanceof Pdo\Sqlite ? $this->pdo->createFunction(...) : $this->pdo->sqliteCreateFunction(...);
        $createFunction('hour', fn($value) => $value === null ? null : (int)substr($value, 11, 2), 1);
        $createFunction('unix_timestamp', fn() => time(), 0);
        $createFunction('database', fn() => $this->path, 0);
        $createFunction('rand', fn() => random_int(0, PHP_INT_MAX) / PHP_INT_MAX, 0);
        $createFunction('get_lock', fn($name, $timeout) => $this->lock($name, $timeout), 2);
        $createFunction('release_lock', fn($name) => $this->unlock($name), 1);
    }
    private function lock($name, $timeout){
        if(isset($this->locks[$name])){ return 1; }
        // Keep locks beside the database, so all local PHP workers share them.
        $directory = $this->path === ':memory:' ? sys_get_temp_dir() : dirname($this->path);
        $path = $directory.'/.ghoti-lock-'.hash('sha256', $this->path.':'.$name);
        $handle = fopen($path, 'c'); if(!$handle){ throw new RuntimeException('Could not open a database lock.'); }
        chmod($path, 0600); $deadline = microtime(true) + min(30, max(0, (int)$timeout));
        do{
            if(flock($handle, LOCK_EX | LOCK_NB)){ $this->locks[$name] = $handle; return 1; }
            usleep(10000);
        }while(microtime(true) < $deadline);
        fclose($handle); return 0;
    }
    private function unlock($name){
        if(!isset($this->locks[$name])){ return null; }
        flock($this->locks[$name], LOCK_UN); fclose($this->locks[$name]); unset($this->locks[$name]); return 1;
    }
    public function __destruct(){ foreach(array_keys($this->locks) as $name){ $this->unlock($name); } }
    public function beginTransaction(){ return $this->pdo->beginTransaction(); }
    public function commit(){ return $this->pdo->commit(); }
    public function rollBack(){ return $this->pdo->rollBack(); }
    public function inTransaction(){ return $this->pdo->inTransaction(); }
    public function lastInsertId(){ return $this->pdo->lastInsertId(); }
    public function backupIndexes($table){
        $stmt = $this->pdo->prepare("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name=? AND sql IS NOT NULL ORDER BY name");
        $stmt->execute(array($table)); return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    public function execute($sql, array $params){
        $sql = trim($sql, " \t\r\n;");
        if(preg_match('/^SHOW (?:FULL )?TABLES/i', $sql)){
            $query = "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'";
            if(stripos($sql, 'LIKE') !== false){ $query .= ' AND name = ?'; }
            $stmt = $this->pdo->prepare($query); $stmt->execute($params); return array($stmt->fetchAll(PDO::FETCH_NUM), 0);
        }
        if(preg_match('/^SHOW COLUMNS FROM `?([A-Za-z0-9_]+)`?$/i', $sql, $m)){
            $rows = $this->pdo->query('PRAGMA table_info(`'.$m[1].'`)')->fetchAll(PDO::FETCH_ASSOC);
            return array(array_map(fn($row) => array($row['name'],$row['type'],$row['notnull'] ? 'NO' : 'YES',$row['pk'] ? 'PRI' : '',$row['dflt_value'],''), $rows), 0);
        }
        if(preg_match('/^SHOW CREATE TABLE `?([A-Za-z0-9_]+)`?$/i', $sql, $m)){
            $stmt = $this->pdo->prepare("SELECT name,sql FROM sqlite_master WHERE type='table' AND name=?");
            $stmt->execute(array($m[1]));
            return array($stmt->fetchAll(PDO::FETCH_NUM),0);
        }
        if(preg_match('/^SET (NAMES|FOREIGN_KEY_CHECKS)\b/i', $sql)){ return array(array(),0); }
        if(preg_match('/^CREATE TABLE\b/i', $sql) && preg_match('/\bENGINE\s*=/i', $sql)){
            $schema = GhotiDbSchema::parse($sql);
            $existing = $this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
            $existing->execute(array($schema['table']));
            if($existing->fetchColumn()){ return array(array(),0); }
            $parts = array(); $indexes = array();
            foreach($schema['columns'] as $name => $column){ $parts[] = GhotiDbSchema::sqliteColumn($name, $column, $name === $schema['auto']); }
            foreach($schema['keys'] as $key){
                $columns = '`'.implode('`,`', $key['columns']).'`';
                if($key['name'] === 'PRIMARY'){
                    if(!$schema['auto']){ $parts[] = 'PRIMARY KEY ('.$columns.')'; }
                }else{
                    $indexes[] = 'CREATE '.($key['unique'] ? 'UNIQUE ' : '').'INDEX IF NOT EXISTS `'.$schema['table'].'_'.$key['name'].'` ON `'.$schema['table'].'` ('.$columns.')';
                }
            }
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS `'.$schema['table'].'` ('.implode(',', $parts).')');
            foreach($indexes as $index){ $this->pdo->exec($index); }
            return array(array(),0);
        }
        if(preg_match('/^ALTER TABLE `?([A-Za-z0-9_]+)`? ADD COLUMN `?([A-Za-z0-9_]+)`? (.+)$/is', $sql, $m)){
            $sql = 'ALTER TABLE `'.$m[1].'` ADD COLUMN '.GhotiDbSchema::sqliteColumn($m[2], GhotiDbSchema::column($m[3]));
        }else{
            // Rewrite keywords/functions only in token space, never inside a value.
            $tokens = GhotiSql::tokens($sql); $output = array(); $upsert = false;
            for($i = 0; $i < count($tokens); $i++){
                $token = $tokens[$i];
                if($token[0] === 'word' && strtolower($token[1]) === 'on'
                    && strtolower(implode(' ', array_column(array_slice($tokens, $i, 4), 1))) === 'on duplicate key update'){
                    foreach(array('ON','CONFLICT','DO','UPDATE','SET') as $word){ $output[] = array('word',$word); }
                    $i += 3; $upsert = true; continue;
                }
                if($upsert && $token[0] === 'word' && strtolower($token[1]) === 'values' && ($tokens[$i+1][1] ?? '') === '('){
                    $output[] = array('word','excluded'); $output[] = array('symbol','.'); $output[] = $tokens[$i+2]; $i += 3; continue;
                }
                $output[] = $token;
            }
            $sql = GhotiSql::render($output, false);
        }
        $stmt = $this->pdo->prepare($sql); $stmt->execute($params);
        return array($stmt->columnCount() ? $stmt->fetchAll(PDO::FETCH_NUM) : array(), $stmt->rowCount());
    }
}
