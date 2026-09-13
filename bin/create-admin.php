<?php
// First administrator provisioning is an operator action, never public signup.
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
chdir(dirname(__DIR__));
require_once 'ghoti.php';
require_once 'mod/login/login.db.php';
class GhotiAdminBootstrap extends logindb{
    public function createFirstAdmin($name, $email, $password){
        $name = ghoti_validate()->username($name);
        $email = ghoti_validate()->email($email);
        $password = ghoti_validate()->password($password);
        $lock = 'ghoti-bootstrap:'.substr(hash('sha256', (string)$this->queryArray('select database()')[0][0]), 0, 32);
        $result = $this->queryArray('select GET_LOCK(?, 5)', array($lock));
        if((int)($result[0][0] ?? 0) !== 1){ throw new RuntimeException('Another bootstrap is running.'); }
        try{
            if((int)$this->queryArray('select count(*) from users')[0][0] !== 0){
                throw new RuntimeException('Bootstrap requires an empty users table. Manage existing accounts through an administrator.');
            }
            $this->query('insert into users(userName,password,email,admin) values(?,?,?,1)', array($name, $this->hashPassword($password), $email));
        }finally{ $this->queryArray('select RELEASE_LOCK(?)', array($lock)); }
    }
}
if($argc !== 3){ fwrite(STDERR, "Usage: php bin/create-admin.php USERNAME EMAIL (password on stdin)\n"); exit(1); }
try{
    $password = rtrim((string)fgets(STDIN, validate::MAX_PASSWORD + 3), "\r\n");
    (new GhotiAdminBootstrap())->createFirstAdmin($argv[1], $argv[2], $password);
    fwrite(STDOUT, "Administrator created.\n");
}catch(Throwable $e){
    ghoti::logException('create-admin.php', $e);
    fwrite(STDERR, "Administrator not created. Check the server log and bootstrap requirements.\n");
    exit(1);
}
