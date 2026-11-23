<?php
require_once __DIR__.'/sql.php';

/** Read the checked-in CREATE TABLE declarations for non-MySQL providers. */
final class GhotiDbSchema {
    public static function parse($sql){
        $sql = GhotiSql::render(GhotiSql::tokens($sql));
        if(!preg_match('/^create table (?:if not exists )?`?([A-Za-z0-9_]+)`?\s*\((.*)\)\s*(?:ENGINE\s*=.*)?$/is', $sql, $m)){
            throw new InvalidArgumentException('Unsupported table definition.');
        }
        $schema = array('table'=>$m[1], 'columns'=>array(), 'keys'=>array(), 'auto'=>null, 'sql'=>$sql);
        foreach(GhotiSql::split($m[2], ',') as $part){
            if(preg_match('/^(PRIMARY KEY|UNIQUE KEY|KEY)\s*(?:`?([A-Za-z0-9_]+)`?\s*)?\((.+)\)$/i', $part, $key)){
                $columns = array_map(fn($s) => trim($s, " `\t\n"), explode(',', $key[3]));
                $schema['keys'][] = array('name'=>strtoupper($key[1]) === 'PRIMARY KEY' ? 'PRIMARY' : $key[2], 'unique'=>strtoupper($key[1]) !== 'KEY', 'columns'=>$columns);
                continue;
            }
            if(!preg_match('/^`?([A-Za-z0-9_]+)`?\s+(.+)$/s', $part, $column)){ throw new InvalidArgumentException('Unsupported column declaration.'); }
            $schema['columns'][$column[1]] = self::column($column[2]);
            if(preg_match('/\bauto_increment\b/i', $column[2])){ $schema['auto'] = $column[1]; }
        }
        return $schema;
    }
    public static function column($definition){
        $tokens = GhotiSql::tokens($definition);
        $type = strtolower($tokens[0][1]);
        $numeric = in_array($type, array('int','integer','bigint','tinyint','smallint','bool','boolean'), true);
        $default = null; $hasDefault = false;
        foreach($tokens as $i => $token){
            if($token[0] === 'word' && strtolower($token[1]) === 'default'){
                $next = $tokens[$i+1]; $hasDefault = true;
                $default = $next[0] === 'string' ? $next[1] : match(strtolower($next[1])){
                    'null'=>null, 'true'=>1, 'false'=>0, 'current_timestamp'=>'CURRENT_TIMESTAMP', default=>0 + $next[1]
                };
                break;
            }
        }
        return array('definition'=>$definition,'type'=>$type,'numeric'=>$numeric,'nullable'=>!preg_match('/\bnot\s+null\b/i', $definition),'hasDefault'=>$hasDefault,'default'=>$default);
    }
    public static function value($column, $value){
        if($value === null){ return null; }
        return $column['numeric'] ? (int)$value : (string)$value;
    }
    public static function defaultValue($column){
        if($column['default'] === 'CURRENT_TIMESTAMP'){ return gmdate('Y-m-d H:i:s'); }
        if($column['hasDefault'] || $column['nullable']){ return self::value($column, $column['default']); }
        // Match the application's existing MySQL sql_mode='' defaults.
        return $column['numeric'] ? 0 : '';
    }
    public static function sqliteColumn($name, $column, $auto = false){
        $sql = '`'.$name.'` '.($column['numeric'] ? 'INTEGER' : 'TEXT COLLATE NOCASE');
        if($auto){ return $sql.' PRIMARY KEY AUTOINCREMENT'; }
        if(!$column['nullable']){ $sql .= ' NOT NULL'; }
        $value = $column['default'] === 'CURRENT_TIMESTAMP' ? 'CURRENT_TIMESTAMP' : self::value($column, $column['default']);
        if(!$column['hasDefault'] && !$column['nullable']){ $value = $column['numeric'] ? 0 : ''; }
        if($value === 'CURRENT_TIMESTAMP'){ $sql .= ' DEFAULT CURRENT_TIMESTAMP'; }
        elseif($value === null){ $sql .= ' DEFAULT NULL'; }
        elseif(is_int($value)){ $sql .= ' DEFAULT '.$value; }
        else{ $sql .= " DEFAULT '".str_replace("'", "''", $value)."'"; }
        return $sql;
    }
}
