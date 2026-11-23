<?php
require_once __DIR__.'/schema.php';
require_once __DIR__.'/connection.php';
require_once __DIR__.'/mongo-query.php';

final class GhotiMongoConnection extends GhotiDbConnection {
    private $manager;
    private $database;
    private $schemas = array();
    private $lastId = '0';
    private $session = null;
    private $lockName = null;
    // Case-insensitive comparisons match the existing MySQL text columns.
    private const COLLATION = array('locale'=>'en','strength'=>2);

    public function __construct(array $config){
        if(!class_exists('MongoDB\\Driver\\Manager')){ throw new RuntimeException('MongoDB requires the PHP mongodb extension.'); }
        $this->database = $config['database'];
        $options = array('serverSelectionTimeoutMS'=>4000,'connectTimeoutMS'=>4000,'socketTimeoutMS'=>10000,'tls'=>$config['tls']);
        if($config['tlsCAFile'] !== ''){
            if(!is_file($config['tlsCAFile']) || !is_readable($config['tlsCAFile'])){ throw new RuntimeException('MongoDB CA certificate is not readable by PHP.'); }
            $options['tlsCAFile'] = $config['tlsCAFile'];
        }
        // The driver verifies both the certificate chain and the hostname.

        if($config['username'] !== ''){
            $options['username'] = $config['username']; $options['password'] = $config['password']; $options['authSource'] = $config['authSource'];
        }
        if($config['replicaSet'] !== ''){ $options['replicaSet'] = $config['replicaSet']; }
        $this->manager = new MongoDB\Driver\Manager('mongodb://'.$config['host'].':'.$config['port'], $options);
        $hello = $this->command(array('hello'=>1))[0];
        if(empty($hello['setName']) && ($hello['msg'] ?? '') !== 'isdbgrid'){
            throw new RuntimeException('MongoDB must run as a replica set (a single-node replica set is sufficient) for store transactions and account locking.');
        }
    }
    public function command(array $command, $session = true){
        $options = $session && $this->session ? array('session'=>$this->session) : array();
        $cursor = $this->manager->executeCommand($this->database, new MongoDB\Driver\Command($command), $options);
        $cursor->setTypeMap(array('root'=>'array','document'=>'array','array'=>'array'));
        return $cursor->toArray();
    }
    private function find($table, $filter = array(), $options = array()){
        $cursor = $this->manager->executeQuery($this->database.'.'.$table, new MongoDB\Driver\Query((object)$filter, $options), $this->session ? array('session'=>$this->session) : array());
        $cursor->setTypeMap(array('root'=>'array','document'=>'array','array'=>'array'));
        return $cursor->toArray();
    }
    private function write($table, $bulk){
        return $this->manager->executeBulkWrite($this->database.'.'.$table, $bulk, $this->session ? array('session'=>$this->session) : array());
    }
    private function update($table, $filter, $update, $options = array()){
        $bulk = new MongoDB\Driver\BulkWrite();
        $bulk->update($filter, $update, $options + array('multi'=>true,'upsert'=>false,'collation'=>self::COLLATION));
        return $this->write($table, $bulk)->getModifiedCount();
    }
    public function schema($table){
        if(!isset($this->schemas[$table])){
            $rows = $this->find('_ghoti_schema', array('_id'=>$table));
            if(!$rows){ throw new RuntimeException('Unknown database table '.$table.'.'); }
            $this->schemas[$table] = $rows[0]['schema'];
        }
        return $this->schemas[$table];
    }
    private function tables(){
        $collections = array_column($this->command(array('listCollections'=>1,'nameOnly'=>true)), 'name');
        return array_values(array_intersect(array_column($this->find('_ghoti_schema'), '_id'), $collections));
    }
    private function create($sql){
        $schema = GhotiDbSchema::parse($sql); $table = $schema['table'];
        if($this->find('_ghoti_schema', array('_id'=>$table))
            && $this->command(array('listCollections'=>1,'nameOnly'=>true,'filter'=>array('name'=>$table)))){ return; }
        foreach(array($table,'_ghoti_schema','_ghoti_counters','_ghoti_locks') as $collection){
            try{ $this->command(array('create'=>$collection)); }
            catch(MongoDB\Driver\Exception\CommandException $e){ if($e->getCode() !== 48){ throw $e; } }
        }
        $indexes = array();
        foreach($schema['keys'] as $key){
            $index = array('key'=>(object)array_fill_keys($key['columns'],1), 'name'=>$key['name'], 'unique'=>$key['unique'], 'collation'=>self::COLLATION);
            if($key['unique']){
                // SQL unique keys allow multiple NULL values.
                $conditions = array();
                foreach($key['columns'] as $column){ if($schema['columns'][$column]['nullable']){ $conditions[$column] = array('$type'=>$schema['columns'][$column]['numeric'] ? 'number' : 'string'); } }
                if($conditions){ $index['partialFilterExpression'] = (object)$conditions; }
            }
            $indexes[] = $index;
        }
        if($indexes){ $this->command(array('createIndexes'=>$table,'indexes'=>$indexes)); }
        $this->update('_ghoti_schema', array('_id'=>$table), array('$set'=>array('schema'=>$schema)), array('multi'=>false,'upsert'=>true));
        $this->schemas[$table] = $schema;
    }
    public function beginTransaction(){
        if($this->session){ throw new RuntimeException('A database transaction is already active.'); }
        $this->session = $this->manager->startSession();
        $this->session->startTransaction(array('readConcern'=>new MongoDB\Driver\ReadConcern('snapshot'), 'writeConcern'=>new MongoDB\Driver\WriteConcern('majority'), 'maxCommitTimeMS'=>5000));
        return true;
    }
    public function commit(){
        if(!$this->session){ throw new RuntimeException('No database transaction is active.'); }
        try{ $this->session->commitTransaction(); }
        finally{ $this->session->endSession(); $this->session = null; $this->lockName = null; }
        return true;
    }
    public function rollBack(){
        if(!$this->session){ return false; }
        try{ $this->session->abortTransaction(); }
        finally{ $this->session->endSession(); $this->session = null; $this->lockName = null; }
        return true;
    }
    public function inTransaction(){ return $this->session !== null; }
    public function __destruct(){ if($this->session){ $this->session->endSession(); } }
    public function lastInsertId(){ return $this->lastId; }
    private function lock($name, $timeout){
        if($this->lockName === $name){ return 1; }
        if($this->session){ throw new RuntimeException('Cannot acquire an account lock inside a transaction.'); }
        $this->update('_ghoti_locks', array('_id'=>$name), array('$setOnInsert'=>array('version'=>0)), array('multi'=>false,'upsert'=>true));
        $deadline = microtime(true) + min(30, max(0, (int)$timeout));
        do{
            $this->beginTransaction();
            try{
                $this->update('_ghoti_locks', array('_id'=>$name), array('$inc'=>array('version'=>1)), array('multi'=>false));
                $this->lockName = $name; return 1;
            }catch(MongoDB\Driver\Exception\RuntimeException $e){
                $this->rollBack();
                if($e->getCode() !== 112 && !$e->hasErrorLabel('TransientTransactionError')){ throw $e; }
                usleep(10000);
            }
        }while(microtime(true) < $deadline);
        return 0;
    }
    private function nextId($table, $explicit = null){
        $update = $explicit === null ? array('$inc'=>array('seq'=>1)) : array('$max'=>array('seq'=>(int)$explicit));
        $row = $this->command(array('findAndModify'=>'_ghoti_counters','query'=>array('_id'=>$table),'update'=>$update,'upsert'=>true,'new'=>true))[0];
        return (int)$row['value']['seq'];
    }
    private function insert($query){
        $schema = $this->schema($query['table']); $count = 0;
        foreach($query['rows'] as $values){
            if(count($values) !== count($query['columns'])){ throw new InvalidArgumentException('INSERT column/value count mismatch.'); }
            $document = array();
            foreach($schema['columns'] as $name => $column){ $document[$name] = GhotiDbSchema::defaultValue($column); }
            foreach($query['columns'] as $i => $name){
                if(($values[$i]['k'] ?? '') === 'call' && $values[$i]['name'] === 'unix_timestamp' && !$values[$i]['args']){ $values[$i] = array('k'=>'value','v'=>time()); }
                if(!isset($schema['columns'][$name]) || $values[$i]['k'] !== 'value'){ throw new InvalidArgumentException('Unsupported INSERT column or expression.'); }
                $document[$name] = GhotiDbSchema::value($schema['columns'][$name], $values[$i]['v']);
                if($document[$name] === null && !$schema['columns'][$name]['nullable'] && $name !== $schema['auto']){ throw new InvalidArgumentException('Required column cannot be NULL.'); }
            }
            if($schema['auto']){
                $name = $schema['auto'];
                if(empty($document[$name])){ $document[$name] = $this->nextId($query['table']); }
                else{ $this->nextId($query['table'], $document[$name]); }
                $this->lastId = (string)$document[$name];
            }
            // Upsert by supplied unique keys before inserting, avoiding duplicate-key
            // exceptions that would abort an active MongoDB transaction.
            $filters = array();
            foreach($schema['keys'] as $key){
                if(!$key['unique']){ continue; } $filter = array();
                foreach($key['columns'] as $name){
                    if($document[$name] === null){ $filter = array(); break; }
                    $filter[$name] = $document[$name];
                }
                if($filter){ $filters[] = $filter; }
            }
            $filter = array('$or'=>$filters);
            if($query['updates'] && $filters){
                $existing = $this->find($query['table'], $filter, array('limit'=>1,'collation'=>self::COLLATION));
                if($existing){
                    $compiler = new GhotiMongoQuery($this, $query['table']); $compiler->filter(null);
                    $count += $this->update($query['table'], array('_id'=>$existing[0]['_id']), $compiler->updates($query['updates'], $document), array('multi'=>false));
                    continue;
                }
            }
            try{
                $bulk = new MongoDB\Driver\BulkWrite(); $bulk->insert($document);
                $count += $this->write($query['table'], $bulk)->getInsertedCount();
            }catch(MongoDB\Driver\Exception\BulkWriteException $e){
                if($e->getCode() !== 11000 || !$query['updates'] || !$filters || $this->session){ throw $e; }
                $compiler = new GhotiMongoQuery($this, $query['table']); $compiler->filter(null);
                $count += $this->update($query['table'], $filter, $compiler->updates($query['updates'], $document), array('multi'=>false));
            }
        }
        return $count;
    }
    private function emptyAggregate($expr){
        if($expr['k'] === 'value'){ return $expr['v']; }
        if($expr['k'] === 'call'){
            if($expr['name'] === 'count'){ return 0; }
            if(in_array($expr['name'], array('sum','min','max'), true)){ return null; }
            if($expr['name'] === 'coalesce'){
                foreach($expr['args'] as $arg){ $value = $this->emptyAggregate($arg); if($value !== null){ return $value; } }
            }
        }
        if($expr['k'] === 'binary'){
            $a = $this->emptyAggregate($expr['a']); $b = $this->emptyAggregate($expr['b']);
            if($a === null || $b === null){ return null; }
            return match($expr['op']){'+'=>$a+$b,'-'=>$a-$b,'*'=>$a*$b,default=>null};
        }
        return null;
    }
    public function execute($sql, array $params){
        $sql = trim($sql, " \t\n\r;");
        if(preg_match('/^CREATE TABLE\b/i', $sql)){ $this->create($sql); return array(array(),0); }
        if(preg_match('/^SET (NAMES|FOREIGN_KEY_CHECKS)\b/i', $sql)){ return array(array(),0); }
        if(preg_match('/^SHOW (?:FULL )?TABLES/i', $sql)){
            $tables = $this->tables(); if($params){ $tables = array_values(array_intersect($tables, array($params[0]))); }
            return array(array_map(fn($name) => array($name), $tables),0);
        }
        if(preg_match('/^SHOW COLUMNS FROM `?([A-Za-z0-9_]+)`?$/i', $sql, $m)){
            $schema = $this->schema($m[1]); $rows = array();
            foreach($schema['columns'] as $name => $column){ $rows[] = array($name,$column['type'],$column['nullable']?'YES':'NO','',$column['default'],''); }
            return array($rows,0);
        }
        if(preg_match('/^SHOW CREATE TABLE `?([A-Za-z0-9_]+)`?$/i', $sql, $m)){
            $schema = $this->schema($m[1]);
            return array(array(array($m[1], preg_replace('/^CREATE TABLE (?:IF NOT EXISTS )?`?[A-Za-z0-9_]+`?\s*\(/i', 'CREATE TABLE `'.$m[1].'` (', $schema['sql']))),0);
        }
        if(preg_match('/^DROP TABLE IF EXISTS `?([A-Za-z0-9_]+)`?$/i', $sql, $m)){
            try{ $this->command(array('drop'=>$m[1])); }
            catch(MongoDB\Driver\Exception\CommandException $e){ if($e->getCode() !== 26){ throw $e; } }
            foreach(array('_ghoti_schema','_ghoti_counters') as $table){
                $bulk = new MongoDB\Driver\BulkWrite(); $bulk->delete(array('_id'=>$m[1]), array('limit'=>1)); $this->write($table,$bulk);
            }
            unset($this->schemas[$m[1]]); return array(array(),0);
        }
        if(preg_match('/^ALTER TABLE `?([A-Za-z0-9_]+)`? ADD COLUMN `?([A-Za-z0-9_]+)`? (.+)$/is', $sql, $m)){
            $schema = $this->schema($m[1]);
            if(isset($schema['columns'][$m[2]])){ return array(array(),0); }
            $column = GhotiDbSchema::column($m[3]);
            $this->update($m[1], array($m[2]=>array('$exists'=>false)), array('$set'=>array($m[2]=>GhotiDbSchema::defaultValue($column))));
            $schema['columns'][$m[2]] = $column;
            $schema['sql'] = preg_replace('/\)\s*ENGINE/i', ', `'.$m[2].'` '.$m[3].') ENGINE', $schema['sql']);
            $this->update('_ghoti_schema', array('_id'=>$m[1]), array('$set'=>array('schema'=>$schema)), array('multi'=>false));
            $this->schemas[$m[1]] = $schema; return array(array(),0);
        }
        $query = (new GhotiSql($sql,$params))->parse();
        if($query['kind'] === 'select'){
            if($query['from'] === null){
                $expr = $query['columns'][0]['expr'];
                if(count($query['columns']) === 1 && $expr['k'] === 'value'){ return array(array(array($expr['v'])),0); }
                if(count($query['columns']) !== 1 || $expr['k'] !== 'call'){ throw new InvalidArgumentException('Unsupported SELECT without a table.'); }
                $args = array_map(fn($e) => $e['v'], $expr['args']);
                $value = match($expr['name']){
                    'database'=>$this->database,
                    'get_lock'=>$this->lock($args[0],$args[1]),
                    'release_lock'=>$this->lockName === $args[0] ? (int)$this->commit() : null,
                    default=>throw new InvalidArgumentException('Unsupported connection function.')
                };
                return array(array(array($value)),0);
            }
            $compiler = new GhotiMongoQuery($this, $query['from']['table'], $query['from']['alias']);
            $pipeline = $compiler->select($query);
            $documents = $this->command(array('aggregate'=>$query['from']['table'],'pipeline'=>$pipeline,'cursor'=>(object)array(),'collation'=>self::COLLATION));
            $rows = array();
            foreach($documents as $document){
                $row = array(); foreach($document as $name => $value){ if(preg_match('/^c[0-9]+$/', $name)){ $row[(int)substr($name,1)] = $value; } }
                ksort($row); $rows[] = array_values($row);
            }
            if(!$rows && !$query['group'] && $query['limit'] !== array('k'=>'value','v'=>0) && !$query['offset']
                && preg_match('/"name":"(?:count|sum|min|max)"/', json_encode($query['columns']))){
                $rows[] = array_map(fn($column) => $this->emptyAggregate($column['expr']), $query['columns']);
            }
            return array($rows,0);
        }
        if($query['kind'] === 'insert'){ return array(array(),$this->insert($query)); }
        $compiler = new GhotiMongoQuery($this, $query['table']); $this->schema($query['table']);
        $filter = $compiler->filter($query['where']);
        if($query['kind'] === 'update'){ return array(array(),$this->update($query['table'], $filter, $compiler->updates($query['updates']))); }
        $bulk = new MongoDB\Driver\BulkWrite(); $bulk->delete($filter, array('limit'=>0,'collation'=>self::COLLATION));
        return array(array(),$this->write($query['table'], $bulk)->getDeletedCount());
    }
}
