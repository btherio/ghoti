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
    private static $validModules = array('pages','banners','comments','links','login','analytics');
    private static $moduleInitState = array();

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
        }catch (Throwable $e){
            ghoti::log("**DB Connection Error!**");
            ghoti::log("ghoti.db.php ".$e->getMessage());
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

    private function connect(){
        if (self::$pdo instanceof PDO){
            return self::$pdo;
        }
        $config = require __DIR__.'/db.config.php';
        $dsn = "{$config['driver']}:host={$config['host']};port={$config['port']};dbname={$config['database']};charset={$config['charset']}";
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
        if ($config['driver'] === 'mysql' && defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            $pdoOptions[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
        }
        try {
            self::$pdo = new PDO($dsn, $config['username'], $config['password'], $pdoOptions);
        } catch (Throwable $e) {
            self::$pdo = null;
            throw $e;
        }
        return self::$pdo;
    }

    protected function db(){
        return $this->connect();
    }

    /* Execute() equivalent: for SELECTs and for INSERT/UPDATE/DELETE alike. */
    protected function query($sql, array $params = array()){
        $stmt = null;
        try {
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
            throw $e;
        }
    }

    /* GetArray() equivalent: returns a plain array of numeric-indexed rows. */
    protected function queryArray($sql, array $params = array()){
        $stmt = null;
        try {
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

    function loadModuleSql($moduleName="default"){
        if(!in_array($moduleName, self::$validModules, true)){
            ghoti::log("ghoti.db.php Can't load module '$moduleName'");
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
            ghoti::log("ghoti.db.php ".$e->getMessage());
            return false;
        }

        self::$moduleInitState[$moduleName] = 'done';
        $this->markProvisioned($moduleName);
        return true;
    }
    function addPage($m_title,$m_content="Under Construction"){
        try{
            $this->query("insert into pages (title, content) values(?,?)",array($m_title,$m_content));
        }catch (Throwable $e){
            ghoti::log("ghoti.db.php ".$e->getMessage());
            return false;
        }
        return true;
    }
    function deletePage($m_id){
        try{
            $this->query("delete from pages where id=?",array($m_id));
            $this->query("delete from comments where pageId=?",array($m_id));
        }catch (Throwable $e){
            ghoti::log("ghoti.db.php ".$e->getMessage());
            return false;
        }
        return true;
    }
    function getPageList($group="public"){
        try{
            $m_pageList = $this->query("select id,title from pages where groupName=?",array($group));
        }catch (Throwable $e){
            ghoti::log("ghoti.db.php ".$e->getMessage());
            return false;
        }
        return $m_pageList;
    }
    function getDefaultPage(){
        try{
            $m_content = $this->queryArray("select content,id from pages where groupName ='public' limit 1;",array());
            if(!$m_content) throw new Exception($this->errorInfo());
        }catch (Throwable $e){
            ghoti::log("ghoti.db.php ".$e->getMessage());
            return false;
        }
        return $m_content;
    }
    function savePage($m_id,$m_content,$m_title){
        try{
            $this->query("update pages set content=?,title=? where id=?",array($m_content,$m_title,$m_id));
        }catch (Throwable $e){
            ghoti::log("ghoti.db.php ".$e->getMessage());
            return false;
        }
        return true;
    }
    function savePageByTitle($m_content,$m_title){
        try{
            $this->query("update pages set content=? where title=?",array($m_content,$m_title));
        }catch (Throwable $e){
            ghoti::log("ghoti.db.php ".$e->getMessage());
            return false;
        }
        return true;
    }
    function getPageById($m_id){
        try{
            $m_content = $this->queryArray("select content,title,groupName from pages where id=?",array($m_id));
        }catch (Throwable $e){
            ghoti::log("ghoti.db.php ".$e->getMessage());
            return false;
        }
        return $m_content;
    }
    function getPageByTitle($m_title){
        try{
            $m_content = $this->queryArray("select content,id,title from pages where title=?",array($m_title));
        }catch (Throwable $e){
            ghoti::log("ghoti.db.php ".$e->getMessage());
            return false;
        }
        return $m_content;
    }
    function getPageGroup($m_id){
        try{
            $m_group = $this->queryArray("select groupName from pages where id=?",array($m_id));
        }catch (Throwable $e){
            ghoti::log("ghoti.db.php ".$e->getMessage());
            return false;
        }
        return $m_group;
    }
    function setPageGroup($m_id,$m_group){
        try{
            $this->query("update pages set groupName=? where id=?",array($m_group,$m_id));
        }catch (Throwable $e){
            ghoti::log("ghoti.db.php ".$e->getMessage());
            return false;
        }
        return true;
    }
}
?>
