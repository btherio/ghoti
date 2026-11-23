<?php
// First administrator provisioning remains available to shell operators.
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
chdir(dirname(__DIR__));
require_once 'ghoti.php';
require_once 'lib/first-admin.php';

function readAdminPassword(){
    if(stream_isatty(STDIN)){
        // Bash reads from the terminal with echo off and handles terminal restoration.
        $line = function_exists('shell_exec') ? shell_exec(<<<'SH'
bash -c 'IFS= read -r -s -p "Initial admin password: " password </dev/tty || exit 1; printf "\n" >&2; printf "%s\n" "$password"'
SH
        ) : null;
        if($line === null){ throw new RuntimeException('Could not read the password from the terminal. Pass it on stdin.'); }
    }else{
        $line = fgets(STDIN, validate::MAX_PASSWORD + 3);
    }
    return rtrim((string)$line, "\r\n");
}

$args = array_slice($argv, 1);
$setupSqlite = ($args[0] ?? '') === '--sqlite';
if($setupSqlite){ array_shift($args); }
if(count($args) !== 2){
    fwrite(STDERR, "Usage: php bin/create-admin.php [--sqlite] USERNAME EMAIL\n");
    exit(1);
}
if($setupSqlite && !in_array('sqlite', PDO::getAvailableDrivers(), true)){
    fwrite(STDERR, "Enable the PHP pdo_sqlite extension before running setup.\n");
    exit(1);
}
try{
    $credentials = GhotiFirstAdmin::validateCredentials($args[0], $args[1], readAdminPassword());
}catch(Exception $e){
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}
if(!$setupSqlite && !ghotidb::isConfigured()){
    fwrite(STDERR, "Database unavailable. Check the connection settings and ghoti.log.\n");
    exit(1);
}
try{
    if($setupSqlite){
        require_once 'lib/install.php';
        $created = GhotiInstaller::isFresh() && GhotiInstaller::installSqlite($credentials);
    }else{
        $created = (new GhotiFirstAdmin())->createFirstAdmin(...$credentials);
    }
}catch(Throwable $e){
    ghoti::logException('create-admin.php', $e);
    fwrite(STDERR, "Administrator not created. Check the server log and bootstrap requirements.\n");
    exit(1);
}
if(!$created){
    $message = $setupSqlite
        ? 'SQLite setup requires a fresh directory. For a configured database, run without --sqlite.'
        : 'The users table already contains accounts. Manage them through an existing administrator.';
    fwrite(STDERR, $message."\n");
    exit(1);
}
if($setupSqlite){ fwrite(STDOUT, "SQLite configured.\n"); }
ghoti::logInfo('create-admin.php', 'First administrator created from the server shell for '.ghoti_validate()->logLine($credentials[0]));
fwrite(STDOUT, "Administrator created.\n");
