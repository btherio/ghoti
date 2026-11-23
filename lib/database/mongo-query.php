<?php
/** Compile Ghoti's SELECT/UPDATE expressions to native MongoDB pipelines.
 * Unsupported SQL fails explicitly; no query runs through eval or string interpolation. */
final class GhotiMongoQuery {
    private $connection;
    private $aliases = array();
    private $base;
    private $prefix = '';
    private $outer = array();
    private $aggregates = array();
    private $outputAliases = array();
    private $subqueries = array();

    public function __construct($connection, $table, $alias = null){
        $this->connection = $connection; $this->base = $alias ?? $table;
        $this->aliases[$this->base] = $table;
    }
    public function column($name){
        $parts = explode('.', $name, 2);
        $alias = count($parts) === 2 ? $parts[0] : $this->base;
        $column = end($parts);
        if(isset($this->outer[$alias])){
            $schema = $this->connection->schema($this->outer[$alias]);
            if(!isset($schema['columns'][$column])){ throw new InvalidArgumentException('Unknown database column '.$name.'.'); }
            return array('$$outer.'.$alias.'.'.$column, $schema['columns'][$column]);
        }
        if(!isset($this->aliases[$alias])){ throw new InvalidArgumentException('Unknown database table alias '.$alias.'.'); }
        $schema = $this->connection->schema($this->aliases[$alias]);
        if(!isset($schema['columns'][$column])){ throw new InvalidArgumentException('Unknown database column '.$name.'.'); }
        $path = $this->prefix.($alias === '' ? '' : $alias.'.').$column;
        return array('$'.$path, $schema['columns'][$column]);
    }
    private function comparable($expr, $other){
        if($expr['k'] === 'value' && $other['k'] === 'column'){
            list(, $column) = $this->column($other['name']);
            $expr['v'] = GhotiDbSchema::value($column, $expr['v']);
        }
        return $this->expression($expr);
    }
    public function expression($expr){
        if($expr === null){ return array('$literal'=>true); }
        switch($expr['k']){
            case 'value': return array('$literal'=>$expr['v']);
            case 'column':
                if(isset($this->outputAliases[$expr['name']])){ return $this->expression($this->outputAliases[$expr['name']]); }
                return $this->column($expr['name'])[0];
            case 'binary':
                $a = $this->comparable($expr['a'], $expr['b']); $b = $this->comparable($expr['b'], $expr['a']);
                $op = array('='=>'$eq','!='=>'$ne','<>'=>'$ne','<'=>'$lt','>'=>'$gt','<='=>'$lte','>='=>'$gte','and'=>'$and','or'=>'$or','+'=>'$add','-'=>'$subtract','*'=>'$multiply','/'=>'$divide')[$expr['op']];
                $value = array($op=>array($a,$b));
                if(in_array($expr['op'], array('=','!=','<>','<','>','<=','>='), true)){
                    // SQL comparisons involving NULL have unknown truth value.
                    return array('$cond'=>array(array('$or'=>array($this->isNull($a),$this->isNull($b))), null, $value));
                }
                return $value;
            case 'null':
                $value = $this->isNull($this->expression($expr['arg']));
                return $expr['not'] ? array('$not'=>array($value)) : $value;
            case 'in':
                $arg = $this->expression($expr['arg']);
                return array('$and'=>array(array('$not'=>array($this->isNull($arg))), array('$in'=>array($arg, array_map(fn($e) => $this->comparable($e, $expr['arg']), $expr['values'])))));
            case 'unary': return $expr['op'] === '-' ? array('$multiply'=>array(-1,$this->expression($expr['arg']))) : array('$not'=>array($this->expression($expr['arg'])));
            case 'case':
                return array('$switch'=>array('branches'=>array_map(fn($pair) => array('case'=>$this->expression($pair[0]), 'then'=>$this->expression($pair[1])), $expr['branches']), 'default'=>$this->expression($expr['else'])));
            case 'subquery':
                $key = json_encode($expr);
                if(!isset($this->subqueries[$key])){ throw new InvalidArgumentException('Subquery outside SELECT output.'); }
                return array('$ifNull'=>array(array('$arrayElemAt'=>array('$'.$this->prefix.$this->subqueries[$key].'.c0',0)),0));
            case 'call':
                $name = $expr['name'];
                if(in_array($name, array('count','sum','max','min'), true)){
                    $key = json_encode($expr);
                    if(!isset($this->aggregates[$key])){ throw new InvalidArgumentException('Aggregate outside SELECT.'); }
                    $field = '$'.$this->aggregates[$key];
                    return $name === 'count' && $expr['distinct'] ? array('$size'=>array('$setDifference'=>array($field,array(null)))) : $field;
                }
                $args = array_map(fn($e) => $this->expression($e), $expr['args']);
                return match($name){
                    'coalesce' => array('$ifNull'=>$args),
                    'lower' => array('$toLower'=>$args[0]),
                    'trim' => array('$trim'=>array('input'=>$args[0])),
                    'date' => array('$substrBytes'=>array($args[0],0,10)),
                    'hour' => array('$convert'=>array('input'=>array('$substrBytes'=>array($args[0],11,2)),'to'=>'int','onError'=>null,'onNull'=>null)),
                    'rand' => array('$rand'=>(object)array()),
                    default => throw new InvalidArgumentException('Unsupported SQL function '.$name.'.')
                };
        }
        throw new InvalidArgumentException('Unsupported SQL expression kind.');
    }
    private function isNull($value){ return array('$eq'=>array(array('$ifNull'=>array($value,null)),null)); }
    private function collect($expr, &$pipeline){
        if(!is_array($expr)){ return; }
        if(($expr['k'] ?? '') === 'call' && in_array($expr['name'], array('count','sum','max','min'), true)){
            $key = json_encode($expr); $this->aggregates[$key] = $this->aggregates[$key] ?? '__agg'.count($this->aggregates); return;
        }
        if(($expr['k'] ?? '') === 'subquery'){
            $key = json_encode($expr); if(isset($this->subqueries[$key])){ return; }
            $query = $expr['query'];
            if(count($query['columns']) !== 1 || $query['columns'][0]['expr']['k'] !== 'call' || $query['columns'][0]['expr']['name'] !== 'count'){
                throw new InvalidArgumentException('Only scalar count subqueries are supported.');
            }
            $compiler = new self($this->connection, $query['from']['table'], $query['from']['alias']);
            $compiler->outer = $this->aliases;
            $name = '__sub'.count($this->subqueries); $this->subqueries[$key] = $name;
            $pipeline[] = array('$lookup'=>array('from'=>$query['from']['table'], 'let'=>array('outer'=>'$$ROOT'), 'pipeline'=>$compiler->select($query), 'as'=>$name));
            return;
        }
        foreach($expr as $value){ if(is_array($value)){ $this->collect($value, $pipeline); } }
    }
    /** Indexable base-table predicates; complex expressions use $expr instead. */
    private function nativeFilter($expr){
        if($expr === null){ return (object)array(); }
        if($expr['k'] === 'binary' && in_array($expr['op'], array('and','or'), true)){
            $a = $this->nativeFilter($expr['a']); $b = $this->nativeFilter($expr['b']);
            return $a === null || $b === null ? null : array('$'.$expr['op']=>array($a,$b));
        }
        if($expr['k'] === 'binary' && $expr['a']['k'] === 'column' && $expr['b']['k'] === 'value'){
            $parts = explode('.', $expr['a']['name']);
            if(count($parts) > 1 && $parts[0] !== $this->base){ return null; }
            $ops = array('='=>'$eq','!='=>'$ne','<>'=>'$ne','>'=>'$gt','<'=>'$lt','>='=>'$gte','<='=>'$lte');
            if(!isset($ops[$expr['op']])){ return null; }
            list(, $column) = $this->column($expr['a']['name']);
            $value = GhotiDbSchema::value($column,$expr['b']['v']);
            if($value === null){ return array('$expr'=>false); }
            $condition = array($ops[$expr['op']]=>$value);
            if(in_array($expr['op'], array('!=','<>'), true)){ return array(end($parts)=>array('$nin'=>array($value,null),'$exists'=>true)); }
            return array(end($parts)=>$condition);
        }
        return null;
    }
    public function select($query){
        $nativeWhere = $query['where'] ? $this->nativeFilter($query['where']) : null;
        $pipeline = $nativeWhere !== null ? array(array('$match'=>$nativeWhere)) : array();
        $pipeline[] = array('$project'=>array('_id'=>0,$this->base=>'$$ROOT'));
        foreach($query['joins'] as $join){
            $this->aliases[$join['alias']] = $join['table'];
            $compiler = new self($this->connection, $join['table'], $join['alias']);
            $compiler->outer = array_diff_key($this->aliases, array($join['alias']=>true));
            $pipeline[] = array('$lookup'=>array('from'=>$join['table'],'let'=>array('outer'=>'$$ROOT'), 'pipeline'=>array(
                array('$project'=>array('_id'=>0,$join['alias']=>'$$ROOT')),
                array('$match'=>array('$expr'=>$compiler->expression($join['on']))),
                array('$replaceRoot'=>array('newRoot'=>'$'.$join['alias']))
            ), 'as'=>$join['alias']));
            $pipeline[] = array('$unwind'=>array('path'=>'$'.$join['alias'], 'preserveNullAndEmptyArrays'=>$join['left']));
        }
        if($query['where'] && $nativeWhere === null){ $pipeline[] = array('$match'=>array('$expr'=>$this->expression($query['where']))); }
        foreach($query['columns'] as $column){
            $this->collect($column['expr'], $pipeline);
            if($column['alias']){ $this->outputAliases[$column['alias']] = $column['expr']; }
        }
        if($query['group'] || $this->aggregates){
            $group = array('_id'=>$query['group'] ? array_map(fn($e) => $this->expression($e), $query['group']) : null, '__row'=>array('$first'=>'$$ROOT'));
            foreach($this->aggregates as $key => $name){
                $expr = json_decode($key, true); $arg = $expr['args'][0];
                $value = $arg['k'] === 'star' ? 1 : $this->expression($arg);
                if($expr['name'] === 'count'){
                    $group[$name] = $expr['distinct'] ? array('$addToSet'=>array('$ifNull'=>array($value,null)))
                        : array('$sum'=>$arg['k'] === 'star' ? 1 : array('$cond'=>array($this->isNull($value),0,1)));
                }else{ $group[$name] = array('$'.$expr['name']=>$value); }
            }
            $pipeline[] = array('$group'=>$group); $this->prefix = '__row.';
        }
        $project = array('_id'=>0);
        foreach($query['columns'] as $index => $column){
            if($column['expr']['k'] === 'star'){
                $schema = $this->connection->schema($query['from']['table']);
                foreach(array_keys($schema['columns']) as $i => $name){ $project['c'.$i] = $this->column($name)[0]; }
            }else{ $project['c'.$index] = $this->expression($column['expr']); }
        }
        foreach($query['order'] as $index => $order){ $project['s'.$index] = $this->expression($order['expr']); }
        $pipeline[] = array('$project'=>$project);
        if($query['distinct']){
            $id = array(); foreach($project as $name => $value){ if($name[0] === 'c'){ $id[$name] = '$'.$name; } }
            $pipeline[] = array('$group'=>array('_id'=>$id,'row'=>array('$first'=>'$$ROOT')));
            $pipeline[] = array('$replaceRoot'=>array('newRoot'=>'$row'));
        }
        if($query['order']){
            $sort = array(); foreach($query['order'] as $index => $order){ $sort['s'.$index] = $order['direction']; }
            $pipeline[] = array('$sort'=>$sort);
        }
        $offset = is_array($query['offset']) ? $query['offset']['v'] : 0;
        if($offset > 0){ $pipeline[] = array('$skip'=>(int)$offset); }
        if($query['limit'] !== null){
            $limit = $query['limit']['v'] ?? -1;
            if(!is_int($limit) || $limit < 0){ throw new InvalidArgumentException('Invalid SELECT limit.'); }
            $pipeline[] = $limit === 0 ? array('$match'=>array('$expr'=>false)) : array('$limit'=>$limit);
        }
        return $pipeline;
    }
    public function filter($expr){
        // Writes use unwrapped documents, whereas SELECT uses alias-qualified roots.
        $this->aliases[''] = $this->aliases[$this->base]; unset($this->aliases[$this->base]); $this->base = '';
        return $this->nativeFilter($expr) ?? array('$expr'=>$this->expression($expr));
    }
    public function updates($updates, $insert = null){
        $schema = $this->connection->schema($this->aliases[$this->base]); $set = array();
        foreach($updates as $name => $expr){
            if(!isset($schema['columns'][$name])){ throw new InvalidArgumentException('Unknown update column.'); }
            if($expr['k'] === 'value'){ $expr['v'] = GhotiDbSchema::value($schema['columns'][$name], $expr['v']); }
            if($expr['k'] === 'call' && $expr['name'] === 'values'){
                $column = $expr['args'][0]['name'];
                if($insert === null || !array_key_exists($column, $insert)){ throw new InvalidArgumentException('Invalid upsert value.'); }
                $expr = array('k'=>'value','v'=>$insert[$column]);
            }
            $set[$name] = $this->expression($expr);
        }
        return array(array('$set'=>(object)$set));
    }
}
