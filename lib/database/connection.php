<?php
/** Minimal PDO-shaped result API shared by the non-MySQL providers. */
class GhotiDbStatement {
    private $connection;
    private $sql;
    private $rows = array();
    private $affected = 0;
    private $offset = 0;
    public function __construct($connection, $sql){ $this->connection = $connection; $this->sql = $sql; }
    public function execute(array $params = array()){
        list($rows, $this->affected) = $this->connection->execute($this->sql, $params);
        $this->rows = array_map(function($row){
            return array_map(fn($value) => $value === null ? null : (string)(is_bool($value) ? (int)$value : $value), array_values($row));
        }, $rows);
        $this->offset = 0; return true;
    }
    public function fetch($mode = PDO::FETCH_NUM){ return $this->rows[$this->offset++] ?? false; }
    public function fetchAll($mode = PDO::FETCH_NUM){ $rows = array_slice($this->rows, $this->offset); $this->offset = count($this->rows); return $rows; }
    public function rowCount(){ return $this->affected; }
    public function closeCursor(){ $this->rows = array(); return true; }
}

abstract class GhotiDbConnection {
    public function prepare($sql){ return new GhotiDbStatement($this, $sql); }
    public function query($sql){ $stmt = $this->prepare($sql); $stmt->execute(); return $stmt; }
    public function exec($sql){
        $count = 0;
        foreach(GhotiSql::split($sql) as $statement){ list(, $affected) = $this->execute($statement, array()); $count += $affected; }
        return $count;
    }
    public function errorInfo(){ return array('00000',null,null); }
    public function quote($value, $type = PDO::PARAM_STR){ return "'".str_replace(array('\\',"'","\0"), array('\\\\',"''",'\\0'), (string)$value)."'"; }
    abstract public function execute($sql, array $params);
}
