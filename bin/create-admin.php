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

if($argc !== 3){
    fwrite(STDERR, "Usage: php bin/create-admin.php USERNAME EMAIL\n");
    exit(1);
}
try{
    $credentials = GhotiFirstAdmin::validateCredentials($argv[1], $argv[2], readAdminPassword());
}catch(Exception $e){
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}
if(!ghotidb::isConfigured()){
    fwrite(STDERR, "Database unavailable. Check the connection settings and ghoti.log.\n");
    exit(1);
}
try{
    $created = (new GhotiFirstAdmin())->createFirstAdmin(...$credentials);
}catch(Throwable $e){
    ghoti::logException('create-admin.php', $e);
    fwrite(STDERR, "Administrator not created. Check the server log and bootstrap requirements.\n");
    exit(1);
}
if(!$created){
    fwrite(STDERR, "The users table already contains accounts. Manage them through an existing administrator.\n");
    exit(1);
}
ghoti::logInfo('create-admin.php', 'First administrator created from the server shell for '.ghoti_validate()->logLine($credentials[0]));
fwrite(STDOUT, "Administrator created.\n");
