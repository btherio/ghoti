<?php

if(!class_exists('ghotidb', false)){ http_response_code(404); return; }
require_once __DIR__.'/../mod/login/login.db.php';

class GhotiFirstAdmin extends logindb {
    public static function validateCredentials(string $name, string $email, string $password): array {
        return array(ghoti_validate()->username($name), ghoti_validate()->email($email),
            ghoti_validate()->password($password));
    }

    public function createFirstAdmin(string $name, string $email, string $password): void {
        list($name, $email, $password) = self::validateCredentials($name, $email, $password);
        $lock = 'ghoti-bootstrap:'.substr(hash('sha256', (string)$this->queryArray('SELECT DATABASE()')[0][0]), 0, 32);
        $result = $this->queryArray('SELECT GET_LOCK(?, 5)', array($lock));
        if((int)($result[0][0] ?? 0) !== 1){ throw new RuntimeException('Another bootstrap is running.'); }
        try{
            if((int)$this->queryArray('SELECT COUNT(*) FROM users')[0][0] !== 0){
                throw new RuntimeException('Bootstrap requires an empty users table. Manage existing accounts through an administrator.');
            }
            $this->query('INSERT INTO users(userName,password,email,admin) VALUES(?,?,?,1)',
                array($name, $this->hashPassword($password), $email));
        }finally{
            $this->queryArray('SELECT RELEASE_LOCK(?)', array($lock));
        }
    }
}
