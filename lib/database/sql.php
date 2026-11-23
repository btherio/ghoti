<?php
/** Parser for the application's SQL dialect. Values stay separate from query text. */
final class GhotiSql {
    private $tokens;
    private $pos = 0;
    private $params;
    private $param = 0;

    public static function tokens($sql){
        $out = array();
        for($i = 0, $length = strlen($sql); $i < $length;){
            if(preg_match('/\G(?:\s+|--[^\r\n]*|\/\*.*?\*\/)/s', $sql, $m, 0, $i)){ $i += strlen($m[0]); continue; }
            $char = $sql[$i];
            if($char === "'" || $char === '"' || $char === '`'){
                $quote = $char; $value = ''; $closed = false; $i++;
                while($i < $length){
                    $char = $sql[$i++];
                    if($char === $quote){
                        if($i < $length && $sql[$i] === $quote){ $value .= $quote; $i++; continue; }
                        $closed = true; break;
                    }
                    if($char === '\\' && $quote !== '`' && $i < $length){
                        $next = $sql[$i++];
                        $value .= array('n'=>"\n",'r'=>"\r",'t'=>"\t",'0'=>"\0",'Z'=>"\x1a")[$next] ?? $next;
                    }else{ $value .= $char; }
                }
                if(!$closed){ throw new InvalidArgumentException('Unterminated SQL literal.'); }
                $out[] = array($quote === '`' ? 'identifier' : 'string', $value);
            }elseif(preg_match('/\G(?:[0-9]+(?:\.[0-9]+)?|[A-Za-z_][A-Za-z0-9_]*|<>|!=|<=|>=|[?(),.;=*+\/<>-])/A', $sql, $m, 0, $i)){
                $value = $m[0]; $i += strlen($value);
                $out[] = array(is_numeric($value) ? 'number' : (preg_match('/^[A-Za-z_]/', $value) ? 'word' : 'symbol'), $value);
            }else{ throw new InvalidArgumentException('Unsupported SQL token at offset '.$i.'.'); }
        }
        return $out;
    }

    public static function render($tokens, $mysql = true){
        return implode(' ', array_map(function($token) use ($mysql){
            if($token[0] === 'string'){ $value = $mysql ? str_replace(array('\\', "\0"), array('\\\\', '\\0'), $token[1]) : $token[1]; return "'".str_replace("'", "''", $value)."'"; }
            if($token[0] === 'identifier'){ return '`'.str_replace('`', '``', $token[1]).'`'; }
            return $token[1];
        }, $tokens));
    }

    public static function split($sql, $separator = ';'){
        $parts = array(); $part = array(); $depth = 0;
        foreach(self::tokens($sql) as $token){
            if($token[0] === 'symbol'){
                if($token[1] === '('){ $depth++; }
                if($token[1] === ')'){ $depth--; }
                if($token[1] === $separator && $depth === 0){
                    if($part){ $parts[] = self::render($part); $part = array(); }
                    continue;
                }
            }
            $part[] = $token;
        }
        if($part){ $parts[] = self::render($part); }
        return $parts;
    }

    public function __construct($sql, array $params = array()){
        $this->tokens = self::tokens($sql); $this->params = array_values($params);
    }
    private function peek($word = null){
        $token = $this->tokens[$this->pos] ?? null;
        if($word === null){ return $token ? strtolower($token[1]) : ''; }
        return $token && $token[0] !== 'string' && strtolower($token[1]) === $word;
    }
    private function take($word){ if($this->peek($word)){ $this->pos++; return true; } return false; }
    private function need($word){ if(!$this->take($word)){ throw new InvalidArgumentException('Expected SQL '.$word.' at token '.$this->pos.'.'); } }
    private function identifier(){
        $token = $this->tokens[$this->pos++] ?? null;
        if(!$token || !in_array($token[0], array('word','identifier'), true) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $token[1])){
            throw new InvalidArgumentException('Invalid SQL identifier.');
        }
        return $token[1];
    }
    private function items($reader){
        $out = array(); do { $out[] = $reader(); }while($this->take(',')); return $out;
    }
    public function parse(){
        $kind = $this->peek(); $this->pos++;
        if($kind === 'select'){ $query = $this->select(); }
        elseif($kind === 'insert'){
            $this->need('into'); $table = $this->identifier(); $this->need('(');
            $columns = $this->items(fn() => $this->identifier()); $this->need(')'); $this->need('values');
            $rows = $this->items(function(){ $this->need('('); $row = $this->items(fn() => $this->expr()); $this->need(')'); return $row; });
            $updates = array();
            if($this->take('on')){ $this->need('duplicate'); $this->need('key'); $this->need('update'); $updates = $this->assignments(); }
            $query = compact('kind','table','columns','rows','updates');
        }elseif($kind === 'update'){
            $table = $this->identifier(); $this->need('set'); $updates = $this->assignments();
            $where = $this->take('where') ? $this->expr() : null;
            $query = compact('kind','table','updates','where');
        }elseif($kind === 'delete'){
            $this->need('from'); $table = $this->identifier(); $where = $this->take('where') ? $this->expr() : null;
            $query = compact('kind','table','where');
        }else{ throw new InvalidArgumentException('Unsupported SQL statement: '.$kind); }
        $this->take(';');
        if($this->pos !== count($this->tokens) || $this->param !== count($this->params)){ throw new InvalidArgumentException('Unsupported SQL suffix or incorrect parameter count.'); }
        return $query;
    }
    private function assignments(){
        $out = array();
        do{ $column = $this->identifier(); $this->need('='); $out[$column] = $this->expr(); }while($this->take(','));
        return $out;
    }
    private function table(){
        $table = $this->identifier(); $alias = $table;
        if($this->take('as')){ $alias = $this->identifier(); }
        elseif(isset($this->tokens[$this->pos]) && in_array($this->tokens[$this->pos][0], array('word','identifier'), true)
            && !in_array($this->peek(), array('left','inner','join','where','group','order','limit','offset','on'), true)){ $alias = $this->identifier(); }
        return compact('table','alias');
    }
    private function select(){
        $distinct = $this->take('distinct');
        $columns = $this->items(function(){ $expr = $this->expr(); $alias = $this->take('as') ? $this->identifier() : null; return compact('expr','alias'); });
        $from = null; $joins = array(); $where = null; $group = array(); $order = array(); $limit = null; $offset = 0;
        if($this->take('from')){
            $from = $this->table();
            while(in_array($this->peek(), array('left','inner','join'), true)){
                $left = $this->take('left'); if(!$left){ $this->take('inner'); }
                $this->need('join'); $join = $this->table(); $this->need('on');
                $join['on'] = $this->expr(); $join['left'] = $left; $joins[] = $join;
            }
        }
        if($this->take('where')){ $where = $this->expr(); }
        if($this->take('group')){ $this->need('by'); $group = $this->items(fn() => $this->expr()); }
        if($this->take('order')){
            $this->need('by'); $order = $this->items(function(){
                $expr = $this->expr(); $direction = $this->take('desc') ? -1 : 1; $this->take('asc'); return compact('expr','direction');
            });
        }
        if($this->take('limit')){
            $limit = $this->expr();
            if($this->take('offset')){ $offset = $this->expr(); }
            elseif($this->take(',')){ $offset = $limit; $limit = $this->expr(); }
        }
        return array('kind'=>'select') + compact('distinct','columns','from','joins','where','group','order','limit','offset');
    }
    private function expr($minimum = 0){
        $token = $this->tokens[$this->pos++] ?? null;
        if(!$token){ throw new InvalidArgumentException('Incomplete SQL expression.'); }
        $word = strtolower($token[1]);
        if($token[0] === 'string'){ $left = array('k'=>'value','v'=>$token[1]); }
        elseif($token[0] === 'number'){ $left = array('k'=>'value','v'=>0 + $token[1]); }
        elseif($word === '?'){
            if(!array_key_exists($this->param, $this->params)){ throw new InvalidArgumentException('Missing SQL parameter.'); }
            $left = array('k'=>'value','v'=>$this->params[$this->param++]);
        }elseif(in_array($word, array('null','true','false'), true)){ $left = array('k'=>'value','v'=>array('null'=>null,'true'=>1,'false'=>0)[$word]); }
        elseif($word === '('){
            if($this->take('select')){ $left = array('k'=>'subquery','query'=>$this->select()); }
            else{ $left = $this->expr(); }
            $this->need(')');
        }elseif($word === '-' || $word === 'not'){ $left = array('k'=>'unary','op'=>$word,'arg'=>$this->expr(6)); }
        elseif($word === 'case'){
            $base = $this->peek('when') ? null : $this->expr(); $branches = array();
            while($this->take('when')){
                $when = $this->expr(); $this->need('then'); $then = $this->expr();
                $branches[] = array($base === null ? $when : array('k'=>'binary','op'=>'=','a'=>$base,'b'=>$when), $then);
            }
            $else = $this->take('else') ? $this->expr() : array('k'=>'value','v'=>null); $this->need('end');
            $left = array('k'=>'case','branches'=>$branches,'else'=>$else);
        }elseif($word === '*'){ $left = array('k'=>'star'); }
        elseif(in_array($token[0], array('word','identifier'), true)){
            if($this->take('(')){
                $distinct = $this->take('distinct'); $args = array();
                if(!$this->peek(')')){ $args = $this->items(fn() => $this->expr()); }
                $this->need(')'); $left = array('k'=>'call','name'=>$word,'args'=>$args,'distinct'=>$distinct);
            }else{
                $name = $token[1]; if($this->take('.')){ $name .= '.'.$this->identifier(); }
                $left = array('k'=>'column','name'=>$name);
            }
        }else{ throw new InvalidArgumentException('Unsupported SQL expression.'); }
        $precedence = array('or'=>1,'and'=>2,'='=>3,'!='=>3,'<>'=>3,'<'=>3,'>'=>3,'<='=>3,'>='=>3,'is'=>3,'in'=>3,'+'=>4,'-'=>4,'*'=>5,'/'=>5);
        while(isset($precedence[$this->peek()]) && $precedence[$this->peek()] >= $minimum){
            $op = $this->peek(); $this->pos++;
            if($op === 'is'){
                $not = $this->take('not'); $this->need('null'); $left = array('k'=>'null','arg'=>$left,'not'=>$not); continue;
            }
            if($op === 'in'){
                $this->need('('); $values = $this->items(fn() => $this->expr()); $this->need(')');
                $left = array('k'=>'in','arg'=>$left,'values'=>$values); continue;
            }
            $left = array('k'=>'binary','op'=>$op,'a'=>$left,'b'=>$this->expr($precedence[$op]+1));
        }
        return $left;
    }
}
