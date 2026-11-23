<?php
// First administrator provisioning remains available to shell operators.
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
chdir(dirname(__DIR__));
require_once 'ghoti.php';
require_once 'lib/first-admin.php';

if($argc !== 3){ fwrite(STDERR, "Usage: php bin/create-admin.php USERNAME EMAIL (password on stdin)\n"); exit(1); }
try{
    $password = rtrim((string)fgets(STDIN, validate::MAX_PASSWORD + 3), "\r\n");
    (new GhotiFirstAdmin())->createFirstAdmin($argv[1], $argv[2], $password);
    fwrite(STDOUT, "Administrator created.\n");
}catch(Throwable $e){
    ghoti::logException('create-admin.php', $e);
    fwrite(STDERR, "Administrator not created. Check the server log and bootstrap requirements.\n");
    exit(1);
}
