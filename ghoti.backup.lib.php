<?php
/* Backup primitives shared by the standalone transfer endpoint and tests. */

const GHOTI_BACKUP_MANIFEST = '.ghoti-backup.json';
const GHOTI_BACKUP_MAX_FILES = 20000;
const GHOTI_BACKUP_MAX_UNCOMPRESSED = 1073741824; // 1 GiB

function ghoti_backup_temp_path($prefix){
    $path = tempnam(sys_get_temp_dir(), $prefix);
    if($path === false){ throw new RuntimeException('Could not create a temporary backup file.'); }
    @chmod($path, 0600);
    return $path;
}

function ghoti_backup_remove_tree($path){
    if(!file_exists($path) && !is_link($path)){ return; }
    if(is_link($path) || is_file($path)){ @unlink($path); return; }
    $items = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
    foreach($items as $item){ ghoti_backup_remove_tree($item->getPathname()); }
    @rmdir($path);
}

function ghoti_backup_excluded_path($relative){
    $relative = str_replace('\\', '/', trim((string)$relative, '/'));
    if($relative === ''){ return false; }
    $parts = explode('/', strtolower($relative));
    foreach($parts as $part){
        if(in_array($part, array('.git','.reasonix','.claude','node_modules'), true)){ return true; }
    }
    $base = end($parts);
    if(in_array($base, array(
        'db.config.local.php','db.provisioned.json','ghoti.settings.json.tmp',
        'login.throttle.json','security.blacklist.json','critical-alerts.json',
        'agents.md','agent.md','claude.md', GHOTI_BACKUP_MANIFEST
    ), true)){ return true; }
    return $base === 'ghoti.log' || strpos($base, 'ghoti.log.') === 0 || substr($base, -4) === '.tmp';
}

function ghoti_backup_valid_relative_path($name){
    if(!is_string($name) || $name === '' || strlen($name) > 1024 || strpos($name, "\0") !== false
        || strpos($name, '\\') !== false || preg_match('/[\x00-\x1f\x7f]/', $name)
        || $name[0] === '/' || preg_match('/^[A-Za-z]:/', $name)){
        return false;
    }
    $trimmed = rtrim($name, '/');
    if($trimmed === ''){ return false; }
    foreach(explode('/', $trimmed) as $part){
        if($part === '' || $part === '.' || $part === '..'){ return false; }
    }
    return $trimmed;
}

function ghoti_backup_create_site_archive($root){
    if(!class_exists('ZipArchive')){ throw new RuntimeException('The PHP Zip extension is required.'); }
    $root = realpath($root);
    if($root === false || !is_dir($root)){ throw new RuntimeException('The site directory is unavailable.'); }
    $output = ghoti_backup_temp_path('ghoti-site-');
    $zip = new ZipArchive();
    $opened = $zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if($opened !== true){ @unlink($output); throw new RuntimeException('Could not create the site archive.'); }
    $manifest = array(
        'format' => 'ghoticms-site-backup',
        'version' => 1,
        'createdAt' => gmdate('c'),
        'ghotiVersion' => trim((string)@file_get_contents($root.'/VERSION')),
        'scope' => 'site-files-without-runtime-state-or-database-credentials',
    );
    $zip->addFromString(GHOTI_BACKUP_MANIFEST, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $count = 0;
    $total = 0;
    try{
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach($iterator as $item){
            $path = $item->getPathname();
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
            if(ghoti_backup_excluded_path($relative) || $item->isLink()){ continue; }
            if($item->isDir()){
                $zip->addEmptyDir($relative);
                continue;
            }
            if(!$item->isFile() || !$item->isReadable()){ continue; }
            $count++;
            $total += (int)$item->getSize();
            if($count > GHOTI_BACKUP_MAX_FILES || $total > GHOTI_BACKUP_MAX_UNCOMPRESSED){
                throw new RuntimeException('The site exceeds the archive safety limit.');
            }
            if(!$zip->addFile($path, $relative)){ throw new RuntimeException('Could not add a site file to the archive.'); }
        }
        if(!$zip->close()){ throw new RuntimeException('Could not finalize the site archive.'); }
    }catch(Throwable $e){
        $zip->close();
        @unlink($output);
        throw $e;
    }
    return array('path'=>$output, 'files'=>$count, 'bytes'=>$total);
}

function ghoti_backup_zip_entries(ZipArchive $zip){
    if($zip->numFiles < 2 || $zip->numFiles > GHOTI_BACKUP_MAX_FILES + 1){ throw new RuntimeException('Archive file count is outside the safety limit.'); }
    $entries = array();
    $total = 0;
    for($i = 0; $i < $zip->numFiles; $i++){
        $name = $zip->getNameIndex($i);
        $relative = ghoti_backup_valid_relative_path($name);
        if($relative === false){ throw new RuntimeException('Archive contains an unsafe path.'); }
        $stat = $zip->statIndex($i);
        if(!is_array($stat)){ throw new RuntimeException('Archive entry could not be inspected.'); }
        $isDirectory = substr($name, -1) === '/';
        $opsys = 0; $attributes = 0;
        if($zip->getExternalAttributesIndex($i, $opsys, $attributes)){
            $mode = ($attributes >> 16) & 0170000;
            if($mode === 0120000){ throw new RuntimeException('Archive links are not permitted.'); }
        }
        if(!$isDirectory){
            $size = (int)($stat['size'] ?? 0);
            $compressed = (int)($stat['comp_size'] ?? 0);
            $total += $size;
            if($size > 268435456 || $total > GHOTI_BACKUP_MAX_UNCOMPRESSED
                || ($size > 10485760 && $compressed > 0 && $size / $compressed > 200)){
                throw new RuntimeException('Archive exceeds the extraction safety limit.');
            }
        }
        if($relative !== GHOTI_BACKUP_MANIFEST && ghoti_backup_excluded_path($relative)){
            throw new RuntimeException('Archive contains a protected runtime or credential path.');
        }
        $entries[] = array('index'=>$i, 'name'=>$name, 'relative'=>$relative, 'directory'=>$isDirectory);
    }
    return $entries;
}

function ghoti_backup_safe_target($root, $relative){
    $parts = explode('/', $relative);
    $current = $root;
    foreach(array_slice($parts, 0, -1) as $part){
        $current .= DIRECTORY_SEPARATOR.$part;
        if(is_link($current)){ throw new RuntimeException('Restore path crosses a symbolic link.'); }
        if(file_exists($current) && !is_dir($current)){ throw new RuntimeException('Restore path conflicts with an existing file.'); }
        if(!file_exists($current) && !mkdir($current, 0755)){ throw new RuntimeException('Could not create a restore directory.'); }
    }
    $target = $root.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $parts);
    if(is_link($target) || is_dir($target)){ throw new RuntimeException('Restore target is not a regular file.'); }
    return $target;
}

function ghoti_backup_restore_site_archive($archivePath, $root){
    if(!class_exists('ZipArchive')){ throw new RuntimeException('The PHP Zip extension is required.'); }
    $root = realpath($root);
    if($root === false || !is_dir($root)){ throw new RuntimeException('The site directory is unavailable.'); }
    $zip = new ZipArchive();
    if($zip->open($archivePath, ZipArchive::RDONLY) !== true){ throw new RuntimeException('The uploaded ZIP cannot be opened.'); }
    $stage = sys_get_temp_dir().'/ghoti-restore-'.bin2hex(random_bytes(10));
    $rollback = sys_get_temp_dir().'/ghoti-rollback-'.bin2hex(random_bytes(10));
    if(!mkdir($stage, 0700) || !mkdir($rollback, 0700)){ $zip->close(); throw new RuntimeException('Could not create restore staging directories.'); }
    $changed = array();
    try{
        $manifestRaw = $zip->getFromName(GHOTI_BACKUP_MANIFEST);
        $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
        if(!is_array($manifest) || ($manifest['format'] ?? '') !== 'ghoticms-site-backup' || (int)($manifest['version'] ?? 0) !== 1){
            throw new RuntimeException('This is not a supported Ghoti site backup.');
        }
        $entries = ghoti_backup_zip_entries($zip);
        foreach($entries as $entry){
            if($entry['relative'] === GHOTI_BACKUP_MANIFEST || $entry['directory']){ continue; }
            $source = $zip->getStream($entry['name']);
            if($source === false){ throw new RuntimeException('Could not read an archive entry.'); }
            $staged = $stage.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $entry['relative']);
            $parent = dirname($staged);
            if(!is_dir($parent) && !mkdir($parent, 0700, true)){ fclose($source); throw new RuntimeException('Could not stage an archive directory.'); }
            $destination = fopen($staged, 'xb');
            if($destination === false){ fclose($source); throw new RuntimeException('Could not stage an archive file.'); }
            $copied = stream_copy_to_stream($source, $destination, 268435457);
            fclose($source); fclose($destination);
            if($copied === false || $copied > 268435456){ throw new RuntimeException('Could not safely stage an archive file.'); }
            @chmod($staged, 0600);
        }
        foreach($entries as $entry){
            if($entry['relative'] === GHOTI_BACKUP_MANIFEST || $entry['directory']){ continue; }
            $target = ghoti_backup_safe_target($root, $entry['relative']);
            $staged = $stage.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $entry['relative']);
            $existed = is_file($target);
            if($existed){
                $saved = $rollback.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $entry['relative']);
                if(!is_dir(dirname($saved)) && !mkdir(dirname($saved), 0700, true)){ throw new RuntimeException('Could not prepare file rollback.'); }
                if(!copy($target, $saved)){ throw new RuntimeException('Could not preserve an existing site file.'); }
            }
            $temporary = tempnam(dirname($target), '.ghoti-restore-');
            if($temporary === false || !copy($staged, $temporary) || !chmod($temporary, 0644) || !rename($temporary, $target)){
                if(is_string($temporary)){ @unlink($temporary); }
                throw new RuntimeException('Could not replace a site file.');
            }
            $changed[] = array('relative'=>$entry['relative'], 'target'=>$target, 'existed'=>$existed);
        }
        if(function_exists('opcache_reset')){ @opcache_reset(); }
        return count($changed);
    }catch(Throwable $error){
        foreach(array_reverse($changed) as $change){
            if(!$change['existed']){ @unlink($change['target']); continue; }
            $saved = $rollback.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $change['relative']);
            if(is_file($saved)){ @copy($saved, $change['target']); }
        }
        throw $error;
    }finally{
        $zip->close();
        ghoti_backup_remove_tree($stage);
        ghoti_backup_remove_tree($rollback);
    }
}

class GhotiBackupDatabase extends ghotidb {
    public function connection(){ return $this->db(); }
}

function ghoti_backup_sql_write($handle, $text){
    if(fwrite($handle, $text) !== strlen($text)){ throw new RuntimeException('Could not write the SQL backup.'); }
}

function ghoti_backup_create_sql_dump($outputPath = null){
    $outputPath = $outputPath ?: ghoti_backup_temp_path('ghoti-db-');
    $handle = fopen($outputPath, 'wb');
    if($handle === false){ throw new RuntimeException('Could not create the SQL backup.'); }
    try{
        $pdo = (new GhotiBackupDatabase())->connection();
        $tables = array();
        $statement = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        while($row = $statement->fetch(PDO::FETCH_NUM)){
            if(isset($row[0]) && preg_match('/^[A-Za-z0-9_]+$/', $row[0])){ $tables[] = $row[0]; }
        }
        sort($tables, SORT_STRING);
        ghoti_backup_sql_write($handle, "-- GHOTICMS SQL BACKUP V1\n-- Created: ".gmdate('c')."\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        foreach($tables as $table){
            $quotedTable = '`'.str_replace('`', '``', $table).'`';
            $createRow = $pdo->query('SHOW CREATE TABLE '.$quotedTable)->fetch(PDO::FETCH_NUM);
            if(!isset($createRow[1])){ throw new RuntimeException('Could not read a table definition.'); }
            ghoti_backup_sql_write($handle, "DROP TABLE IF EXISTS ".$quotedTable.";\n".$createRow[1].";\n");
            $columnRows = $pdo->query('SHOW COLUMNS FROM '.$quotedTable)->fetchAll(PDO::FETCH_NUM);
            $columns = array(); $binary = array();
            foreach($columnRows as $index => $column){
                $name = (string)$column[0];
                if(!preg_match('/^[A-Za-z0-9_]+$/', $name)){ throw new RuntimeException('A table contains an unsupported column name.'); }
                $columns[] = '`'.$name.'`';
                $binary[$index] = preg_match('/\b(binary|blob|bit|geometry)\b/i', (string)($column[1] ?? '')) === 1;
            }
            $rows = $pdo->query('SELECT * FROM '.$quotedTable);
            while($row = $rows->fetch(PDO::FETCH_NUM)){
                $values = array();
                foreach($row as $index => $value){
                    if($value === null){ $values[] = 'NULL'; }
                    elseif(!empty($binary[$index])){ $values[] = '0x'.bin2hex((string)$value); }
                    else{
                        $quoted = $pdo->quote((string)$value, PDO::PARAM_STR);
                        if($quoted === false){ throw new RuntimeException('Could not encode a database value.'); }
                        $values[] = $quoted;
                    }
                }
                ghoti_backup_sql_write($handle, 'INSERT INTO '.$quotedTable.' ('.implode(',', $columns).') VALUES ('.implode(',', $values).");\n");
            }
            ghoti_backup_sql_write($handle, "\n");
        }
        ghoti_backup_sql_write($handle, "SET FOREIGN_KEY_CHECKS=1;\n-- END GHOTICMS SQL BACKUP\n");
        fclose($handle);
        return array('path'=>$outputPath, 'tables'=>count($tables), 'bytes'=>filesize($outputPath));
    }catch(Throwable $e){
        fclose($handle);
        @unlink($outputPath);
        throw $e;
    }
}

function ghoti_backup_split_sql($sql){
    if(!is_string($sql) || strpos($sql, '-- GHOTICMS SQL BACKUP V1') !== 0){ throw new RuntimeException('This is not a supported Ghoti SQL backup.'); }
    $statements = array(); $buffer = ''; $state = 'normal'; $length = strlen($sql);
    for($i = 0; $i < $length; $i++){
        $char = $sql[$i]; $next = $i + 1 < $length ? $sql[$i + 1] : '';
        if($state === 'line-comment'){
            if($char === "\n"){ $state = 'normal'; $buffer .= ' '; }
            continue;
        }
        if($state === 'block-comment'){
            if($char === '*' && $next === '/'){ $state = 'normal'; $i++; $buffer .= ' '; }
            continue;
        }
        if($state === 'normal'){
            if($char === '#' || ($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2])))){ $state = 'line-comment'; if($char === '-'){ $i++; } continue; }
            if($char === '/' && $next === '*'){ $state = 'block-comment'; $i++; continue; }
            if($char === "'" || $char === '"' || $char === '`'){ $state = $char; $buffer .= $char; continue; }
            if($char === ';'){
                $statement = trim($buffer);
                if($statement !== ''){ $statements[] = $statement; }
                $buffer = '';
                if(count($statements) > 1000000){ throw new RuntimeException('SQL backup contains too many statements.'); }
                continue;
            }
            $buffer .= $char;
        }else{
            $buffer .= $char;
            if($char === '\\' && $next !== ''){ $buffer .= $next; $i++; continue; }
            if($char === $state){
                if($next === $state){ $buffer .= $next; $i++; }
                else{ $state = 'normal'; }
            }
        }
        if(strlen($buffer) > 33554432){ throw new RuntimeException('SQL statement exceeds the safety limit.'); }
    }
    if($state !== 'normal' && $state !== 'line-comment'){ throw new RuntimeException('SQL backup is truncated.'); }
    if(trim($buffer) !== ''){ throw new RuntimeException('SQL backup has an unterminated statement.'); }
    return $statements;
}

function ghoti_backup_validate_sql_statements($statements){
    $creates = array(); $references = array();
    foreach($statements as $statement){
        if(preg_match('/^SET\s+NAMES\s+utf8mb4$/i', $statement) || preg_match('/^SET\s+FOREIGN_KEY_CHECKS\s*=\s*[01]$/i', $statement)){ continue; }
        if(preg_match('/^DROP\s+TABLE\s+IF\s+EXISTS\s+`([A-Za-z0-9_]+)`$/i', $statement, $match)){
            $references[] = $match[1]; continue;
        }
        if(preg_match('/^CREATE\s+TABLE\s+`([A-Za-z0-9_]+)`\s*\(/is', $statement, $match)){
            $creates[$match[1]] = true; continue;
        }
        if(preg_match('/^INSERT\s+INTO\s+`([A-Za-z0-9_]+)`\s*\([^;]+\)\s+VALUES\s*\(/is', $statement, $match)){
            $references[] = $match[1]; continue;
        }
        throw new RuntimeException('SQL backup contains a statement outside the Ghoti backup format.');
    }
    if(!isset($creates['pages'], $creates['users'])){ throw new RuntimeException('SQL backup does not contain the required Ghoti tables.'); }
    foreach($references as $table){ if(!isset($creates[$table])){ throw new RuntimeException('SQL backup references a table it does not define.'); } }
    return array_keys($creates);
}

function ghoti_backup_apply_sql($pdo, $statements){
    foreach($statements as $statement){ $pdo->exec($statement); }
}

function ghoti_backup_restore_sql($path){
    $sql = file_get_contents($path);
    if($sql === false){ throw new RuntimeException('Could not read the SQL backup.'); }
    $statements = ghoti_backup_split_sql($sql);
    $tables = ghoti_backup_validate_sql_statements($statements);
    $rollback = ghoti_backup_temp_path('ghoti-db-rollback-');
    $pdo = (new GhotiBackupDatabase())->connection();
    try{
        ghoti_backup_create_sql_dump($rollback);
        try{
            ghoti_backup_apply_sql($pdo, $statements);
        }catch(Throwable $restoreError){
            $rollbackSql = file_get_contents($rollback);
            $rolledBack = false;
            try{
                $rollbackStatements = ghoti_backup_split_sql((string)$rollbackSql);
                ghoti_backup_validate_sql_statements($rollbackStatements);
                ghoti_backup_apply_sql($pdo, $rollbackStatements);
                $rolledBack = true;
            }catch(Throwable $rollbackError){
                if(class_exists('ghoti')){ ghoti::logException('ghoti.backup.lib.php:rollback', $rollbackError); }
            }
            throw new RuntimeException($rolledBack ? 'Database restore failed and the previous database was restored.' : 'Database restore failed and automatic rollback also failed.', 0, $restoreError);
        }
        @unlink(__DIR__.'/db.provisioned.json');
        return count($tables);
    }finally{ @unlink($rollback); }
}
?>
