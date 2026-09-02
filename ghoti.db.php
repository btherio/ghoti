<?php
/*
 * Created on Mar 1, 2009
 *
 * Database layer migrated from ADOdb to PDO.
 */

/*
 * Thin wrapper around a PDOStatement so callers written against ADOdb's
 * recordset shape keep working unchanged: ->fields[n] for the first row,
 * and foreach($rs as $row) yielding each row as a numeric array.
 */
class GhotiRecordSet implements IteratorAggregate, Countable {
    public $fields = array();
    private $rows = array();

    public function __construct(PDOStatement $stmt){
        $this->rows = $stmt->fetchAll(PDO::FETCH_NUM);
        $this->fields = isset($this->rows[0]) ? $this->rows[0] : array();
        try {
            $stmt->closeCursor();
        } catch (Throwable $e) {
            // Ignore cursor-close failures; the rows have already been materialized.
        }
    }

    public function getIterator(): Iterator {
        return new ArrayIterator($this->rows);
    }

    public function count(): int {
        return count($this->rows);
    }
}

class ghotidb{
    /*This is where you setup your database.
     *Connection settings live in db.config.php (see that file for how to
     *override host/credentials via environment variables or a local,
     *untracked override file).
     */

    /*
     * Shared PDO connection. Every ghotidb (and subclass) instance created
     * during a request reuses this single connection instead of each one
     * opening/closing its own, which is what the old ADOdb-based version did.
     */
    private static $pdo = null;

    /* Module names allowed to be auto-provisioned via loadModuleSql(). */
    private static $validModules = array('pages','banners','comments','links','login','analytics','gallery');
    private static $moduleInitState = array();
    private static $pageSchemaReady = false;
    const PAGE_SCHEMA_VERSION = 1;

    /*
     * Persistent "this module's table already exists" cache. Without it every
     * request runs a `SELECT 1 FROM <table>` existence probe for all ~6 modules
     * (the per-request $moduleInitState memo resets each request). Once a module
     * is confirmed/provisioned we record it in a small JSON marker file and skip
     * the probe on subsequent requests. It is strictly a cache: if it is
     * missing, unreadable, or unwritable we fall back to the live probe, so the
     * app still works. If you ever drop a table, delete db.provisioned.json to
     * force re-provisioning.
     */
    private static $provisioned = null; // array(module => true), lazy-loaded

    //declarations. for typing practice.
    public $m_id,$m_title,$m_content,$m_pageList,$m_group;

    function __construct(){
        try{
            $this->connect();
            $this->loadModuleSql("pages");
            $this->ensurePageSchema();
        }catch (Throwable $e){
            ghoti::logError("ghoti.db.php:__construct", "DB Connection Error!");
            ghoti::logException("ghoti.db.php:__construct", $e);
        }
    }

    function __destruct(){
        // Intentionally do NOT tear down the shared connection here.
        //
        // self::$pdo is a single static connection shared by EVERY ghotidb (and
        // subclass) instance in a request - and this app creates many of them,
        // including ones stored in $_SESSION and recreated on every request.
        // Destroying any one of them (e.g. when index.php overwrites the old
        // $_SESSION['ghotiObj'] with a fresh one) used to null the static and
        // drop the live connection mid-request. The next query then had to
        // reconnect, which under load surfaced as "SQLSTATE[HY000] [2002]
        // Connection refused" and a stream of aborted-connection warnings in
        // MariaDB. Because isAdmin() (and other checks) fail closed on a DB
        // error, that also produced spurious "admin access required" for a
        // genuine admin. PHP closes the non-persistent PDO cleanly on its own
        // when the request ends, so there is nothing to do here.
    }

    private function disconnect(){
        if (self::$pdo instanceof PDO) {
            self::$pdo = null;
        }
    }

    private function resetConnection(){
        $this->disconnect();
    }

    /* Load the connection config array (db.config.php + optional local override).
     * Uses require (not require_once) so a db.config.local.php written by the
     * setup screen mid-run is picked up on the next call. */
    public static function loadConfig(){
        return require __DIR__.'/db.config.php';
    }

    /* Build the PDO DSN string from a config array. */
    private static function buildDsn($config){
        $driver   = isset($config['driver'])   ? $config['driver']   : 'mysql';
        $host     = isset($config['host'])      ? $config['host']     : '';
        $port     = isset($config['port'])      ? $config['port']     : '3306';
        $database = isset($config['database'])  ? $config['database'] : '';
        $charset  = isset($config['charset'])   ? $config['charset']  : 'utf8mb4';
        return "{$driver}:host={$host};port={$port};dbname={$database};charset={$charset}";
    }

    /* The PDO options this app requires. Shared by connect() and the config
     * probe so a connection opened by either behaves identically. */
    private static function pdoOptions($driver){
        $pdoOptions = array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
            //Emulated prepares are kept on intentionally: the app relies on
            //fetched values always being strings (eg. `$row[0] === '1'`).
            //Turning emulation off makes mysqlnd return native ints/floats
            //for numeric columns and would silently break those checks.
            //Parameters are still always bound, never concatenated, so this
            //remains fully safe against SQL injection.
            PDO::ATTR_EMULATE_PREPARES   => true,
            PDO::ATTR_PERSISTENT         => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode=''"
        );
        if ($driver === 'mysql' && defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            $pdoOptions[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
        }
        return $pdoOptions;
    }

    private function connect(){
        if (self::$pdo instanceof PDO){
            return self::$pdo;
        }
        $config = self::loadConfig();
        try {
            self::$pdo = new PDO(
                self::buildDsn($config),
                isset($config['username']) ? $config['username'] : '',
                isset($config['password']) ? $config['password'] : '',
                self::pdoOptions(isset($config['driver']) ? $config['driver'] : 'mysql')
            );
            ghoti::logDebug("ghoti.db.php:connect", "Connected to ".(isset($config['host']) ? $config['host'] : '?')."/".(isset($config['database']) ? $config['database'] : '?'));
        } catch (Throwable $e) {
            self::$pdo = null;
            ghoti::logException("ghoti.db.php:connect", $e);
            throw $e;
        }
        return self::$pdo;
    }

    /*
     * Non-fatal probe: can we actually reach the database with the current
     * config? Returns true (and keeps the live connection for the rest of the
     * request) or false. Used by index.php to decide whether to divert to the
     * setup screen (see ghoti.setup.php). A short connect timeout keeps the
     * setup page snappy when the configured host is dead/unroutable.
     */
    public static function isConfigured(){
        if (self::$pdo instanceof PDO){
            return true;
        }
        try {
            $config = self::loadConfig();
            if (!is_array($config) || empty($config['host']) || empty($config['database'])){
                return false;
            }
            $options = self::pdoOptions(isset($config['driver']) ? $config['driver'] : 'mysql');
            $options[PDO::ATTR_TIMEOUT] = 4; // don't hang the page on an unreachable host
            self::$pdo = new PDO(
                self::buildDsn($config),
                isset($config['username']) ? $config['username'] : '',
                isset($config['password']) ? $config['password'] : '',
                $options
            );
            return true;
        } catch (Throwable $e) {
            self::$pdo = null;
            ghoti::logError("ghoti.db.php:isConfigured", "probe failed: ".$e->getMessage());
            return false;
        }
    }

    protected function db(){
        return $this->connect();
    }

    /* Execute() equivalent: for SELECTs and for INSERT/UPDATE/DELETE alike. */
    protected function query($sql, array $params = array()){
        $stmt = null;
        try {
            ghoti::logDebug("ghoti.db.php:query", $sql);
            $stmt = $this->db()->prepare($sql);
            $stmt->execute($params);
            return new GhotiRecordSet($stmt);
        } catch (Throwable $e) {
            if ($stmt instanceof PDOStatement) {
                try {
                    $stmt->closeCursor();
                } catch (Throwable $cursorError) {
                    // Ignore cursor-close failures; the statement is already failing.
                }
            }
            $this->resetConnection();
            ghoti::logException("ghoti.db.php:query", $e, $sql);
            throw $e;
        }
    }

    /* GetArray() equivalent: returns a plain array of numeric-indexed rows. */
    protected function queryArray($sql, array $params = array()){
        $stmt = null;
        try {
            ghoti::logDebug("ghoti.db.php:queryArray", $sql);
            $stmt = $this->db()->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
            return $rows;
        } catch (Throwable $e) {
            if ($stmt instanceof PDOStatement) {
                try {
                    $stmt->closeCursor();
                } catch (Throwable $cursorError) {
                    // Ignore cursor-close failures; the statement is already failing.
                }
            }
            $this->resetConnection();
            ghoti::logException("ghoti.db.php:queryArray", $e, $sql);
            throw $e;
        } finally {
            if ($stmt instanceof PDOStatement) {
                try {
                    $stmt->closeCursor();
                } catch (Throwable $e) {
                    // Ignore cursor-close failures; the rows have already been materialized.
                }
            }
        }
    }

    /* ErrorMsg() equivalent, kept for parity with the old logging calls. */
    protected function errorInfo(){
        $info = $this->db()->errorInfo();
        return isset($info[2]) ? $info[2] : '';
    }

    /* Path of the persistent provisioning marker. */
    private function provisionedMarkerFile(){
        return __DIR__.'/db.provisioned.json';
    }

    /* Lazily load the persistent provisioning cache (best-effort). */
    private function loadProvisioned(){
        if (self::$provisioned !== null) {
            return;
        }
        self::$provisioned = array();
        try{
            $file = $this->provisionedMarkerFile();
            if (is_file($file)) {
                $data = json_decode(@file_get_contents($file), true);
                if (is_array($data)) {
                    self::$provisioned = $data;
                }
            }
        }catch (Throwable $e){
            self::$provisioned = array();
        }
    }

    /* Record a module as provisioned in the persistent cache (best-effort). */
    private function markProvisioned($moduleName){
        $this->loadProvisioned();
        if (!empty(self::$provisioned[$moduleName])) {
            return;
        }
        self::$provisioned[$moduleName] = true;
        try{
            @file_put_contents($this->provisionedMarkerFile(), json_encode(self::$provisioned), LOCK_EX);
        }catch (Throwable $e){
            //Cache write is best-effort; on failure we simply probe again next time.
        }
    }

    /* Add page-management columns once for databases created before v1. */
    private function ensurePageSchema(){
        if(self::$pageSchemaReady){ return true; }

        $this->loadProvisioned();
        if(isset(self::$provisioned['pagesSchemaVersion'])
            && (int)self::$provisioned['pagesSchemaVersion'] >= self::PAGE_SCHEMA_VERSION){
            try{
                $this->query("select sortOrder,isDefault from pages limit 1");
                self::$pageSchemaReady = true;
                return true;
            }catch(Throwable $e){
                //The marker is only a cache. Repair stale markers instead of
                //letting every page query fail because a column is absent.
                unset(self::$provisioned['pagesSchemaVersion']);
            }
        }

        try{
            $columns = $this->queryArray("SHOW COLUMNS FROM pages");
            $columnNames = array();
            foreach($columns as $column){
                if(isset($column[0])){ $columnNames[(string)$column[0]] = true; }
            }
            if(!isset($columnNames['sortOrder'])){
                $this->db()->exec("ALTER TABLE pages ADD COLUMN sortOrder int(11) NOT NULL DEFAULT 0");
            }
            if(!isset($columnNames['isDefault'])){
                $this->db()->exec("ALTER TABLE pages ADD COLUMN isDefault bool NOT NULL DEFAULT false");
            }

            $this->query("update pages set sortOrder=id where sortOrder <= 0");
            $defaultRows = $this->queryArray("select id from pages where isDefault=1 order by sortOrder,id limit 1");
            if(empty($defaultRows)){
                $candidate = $this->queryArray(
                    "select id from pages where title=? and groupName='public' order by sortOrder,id limit 1",
                    array(ghoti::$defaultPageTitle)
                );
                if(empty($candidate)){
                    $candidate = $this->queryArray("select id from pages where groupName='public' order by sortOrder,id limit 1");
                }
                if(!empty($candidate)){
                    $this->query("update pages set isDefault=case when id=? then 1 else 0 end",array((int)$candidate[0][0]));
                }
            }

            self::$provisioned['pagesSchemaVersion'] = self::PAGE_SCHEMA_VERSION;
            @file_put_contents($this->provisionedMarkerFile(), json_encode(self::$provisioned), LOCK_EX);
            self::$pageSchemaReady = true;
            return true;
        }catch(Throwable $e){
            ghoti::logException("ghoti.db.php:ensurePageSchema", $e, "page schema upgrade failed");
            return false;
        }
    }

    function loadModuleSql($moduleName="default"){
        if(!in_array($moduleName, self::$validModules, true)){
            ghoti::logError("ghoti.db.php:loadModuleSql", "Can't load module '$moduleName'");
            return false;
        }

        if (isset(self::$moduleInitState[$moduleName]) && self::$moduleInitState[$moduleName] !== 'pending') {
            return true;
        }

        //Persistent fast path: if we have already confirmed this table exists on
        //a previous request, skip the existence probe entirely.
        $this->loadProvisioned();
        if (!empty(self::$provisioned[$moduleName])) {
            self::$moduleInitState[$moduleName] = 'done';
            return true;
        }

        self::$moduleInitState[$moduleName] = 'in-progress';

        $tableExisted = true;

        //Check to see if our table exists in the database. If it does, skip provisioning.
        try{
            $this->query("SELECT 1 FROM `$moduleName` LIMIT 1");
        }catch (Throwable $e){
            $tableExisted = false;
        }

        if ($tableExisted) {
            self::$moduleInitState[$moduleName] = 'done';
            $this->markProvisioned($moduleName);
            return true;
        }

        try{
            $tableSqlPath = __DIR__."/mod/$moduleName/$moduleName.sql";
            $tableSql = @file_get_contents($tableSqlPath);
            if($tableSql === false){
                throw new Exception('Failed to open table sql file.');
            }
            //File should contain 'create table if not exists' statements only.
            $this->db()->exec($tableSql);

            //Perform initial seed data only the first time the table is created.
            $insertSqlPath = __DIR__."/mod/$moduleName/insert.sql";
            if (is_file($insertSqlPath)) {
                $file = fopen($insertSqlPath, "r");
                if($file !== false){
                    while(!feof($file)) {
                        //read file line by line, dumping each line into the db as it's read.
                        $line = fgets($file, 4096);
                        if($line !== false && strlen(trim($line)) > 0){
                            try {
                                $this->db()->exec($line);
                            } catch(Throwable $e){ /***??***/ }
                        }
                    }
                    fclose($file);
                }
            }
        }catch (Throwable $e){
            self::$moduleInitState[$moduleName] = 'failed';
            ghoti::logException("ghoti.db.php:loadModuleSql", $e);
            return false;
        }

        self::$moduleInitState[$moduleName] = 'done';
        $this->markProvisioned($moduleName);
        return true;
    }
    function addPage($m_title,$m_content="Under Construction"){
        try{
            $orderRows = $this->queryArray("select coalesce(max(sortOrder),0)+1 from pages");
            $sortOrder = isset($orderRows[0][0]) ? (int)$orderRows[0][0] : 1;
            $this->query("insert into pages (title,content,sortOrder) values(?,?,?)",array($m_title,$m_content,$sortOrder));
        }catch (Throwable $e){
            ghoti::logException("ghoti.db.php:addPage", $e);
            return false;
        }
        return true;
    }
    function deletePage($m_id){
        try{
            $this->query("delete from pages where id=?",array($m_id));
            $this->query("delete from comments where pageId=?",array($m_id));
        }catch (Throwable $e){
            ghoti::logException("ghoti.db.php:deletePage", $e);
            return false;
        }
        return true;
    }
    function getPageList($group="public"){
        try{
            $m_pageList = $this->query("select id,title from pages where groupName=? order by sortOrder,id",array($group));
        }catch (Throwable $e){
            ghoti::logException("ghoti.db.php:getPageList", $e);
            return false;
        }
        return $m_pageList;
    }
    function getDefaultPage(){
        try{
            $m_content = $this->queryArray("select content,id from pages where isDefault=1 and groupName='public' order by sortOrder,id limit 1",array());
            if(!$m_content){
                $m_content = $this->queryArray("select content,id from pages where groupName='public' order by sortOrder,id limit 1",array());
            }
            if(!$m_content) throw new Exception($this->errorInfo());
        }catch (Throwable $e){
            ghoti::logException("ghoti.db.php:getDefaultPage", $e);
            return false;
        }
        return $m_content;
    }
    function savePage($m_id,$m_content,$m_title){
        try{
            $this->query("update pages set content=?,title=? where id=?",array($m_content,$m_title,$m_id));
        }catch (Throwable $e){
            ghoti::logException("ghoti.db.php:savePage", $e);
            return false;
        }
        return true;
    }
    function savePageByTitle($m_content,$m_title){
        try{
            $this->query("update pages set content=? where title=?",array($m_content,$m_title));
        }catch (Throwable $e){
            ghoti::logException("ghoti.db.php:savePageByTitle", $e);
            return false;
        }
        return true;
    }
    function getPageById($m_id){
        try{
            $m_content = $this->queryArray("select content,title,groupName from pages where id=?",array($m_id));
        }catch (Throwable $e){
            ghoti::logException("ghoti.db.php:getPageById", $e);
            return false;
        }
        return $m_content;
    }
    function getPageByTitle($m_title){
        try{
            $m_content = $this->queryArray("select content,id,title,groupName from pages where title=? order by sortOrder,id limit 1",array($m_title));
        }catch (Throwable $e){
            ghoti::logException("ghoti.db.php:getPageByTitle", $e);
            return false;
        }
        return $m_content;
    }
    function getPageGroup($m_id){
        try{
            $m_group = $this->queryArray("select groupName from pages where id=?",array($m_id));
        }catch (Throwable $e){
            ghoti::logException("ghoti.db.php:getPageGroup", $e);
            return false;
        }
        return $m_group;
    }
    function setPageGroup($m_id,$m_group){
        try{
            $this->query("update pages set groupName=? where id=?",array($m_group,$m_id));
        }catch (Throwable $e){
            ghoti::logException("ghoti.db.php:setPageGroup", $e);
            return false;
        }
        return true;
    }

    function getPageManagementList(){
        try{
            return $this->queryArray("select id,title,groupName,sortOrder,isDefault from pages order by sortOrder,id");
        }catch(Throwable $e){
            ghoti::logException("ghoti.db.php:getPageManagementList", $e);
            return false;
        }
    }

    function savePageManagement($pages,$defaultPageId){
        if(!is_array($pages) || empty($pages)){ return false; }
        try{
            $sortCases = array();
            $groupCases = array();
            $ids = array();
            $params = array();
            foreach($pages as $page){
                $sortCases[] = "when ? then ?";
                $params[] = (int)$page['id'];
                $params[] = (int)$page['sortOrder'];
            }
            foreach($pages as $page){
                $groupCases[] = "when ? then ?";
                $params[] = (int)$page['id'];
                $params[] = (string)$page['groupName'];
            }
            $params[] = (int)$defaultPageId;
            foreach($pages as $page){
                $ids[] = "?";
                $params[] = (int)$page['id'];
            }
            $sql = "update pages set "
                ."sortOrder=case id ".implode(' ',$sortCases)." else sortOrder end, "
                ."groupName=case id ".implode(' ',$groupCases)." else groupName end, "
                ."isDefault=case when id=? then 1 else 0 end "
                ."where id in (".implode(',',$ids).")";
            $this->query($sql,$params);
            return true;
        }catch(Throwable $e){
            ghoti::logException("ghoti.db.php:savePageManagement", $e);
            return false;
        }
    }

    function setDefaultPageByTitle($title){
        try{
            $rows = $this->queryArray("select id from pages where title=? and groupName='public' order by sortOrder,id limit 1",array($title));
            if(empty($rows)){ return false; }
            $this->query("update pages set isDefault=case when id=? then 1 else 0 end",array((int)$rows[0][0]));
            return true;
        }catch(Throwable $e){
            ghoti::logException("ghoti.db.php:setDefaultPageByTitle", $e);
            return false;
        }
    }
}
?>
