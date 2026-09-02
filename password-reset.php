<?php
declare(strict_types=1);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '1');
session_name('ghoti_password_reset');
session_set_cookie_params(array('lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Strict'));
session_start();
if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

$message = '';
$success = false;
$complete = false;
$limited = false;

function resetConnection(): PDO {
    $config = require __DIR__.'/db.config.php';
    $dsn = $config['driver'].':host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'].';charset='.$config['charset'];
    return new PDO($dsn,$config['username'],$config['password'],array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false));
}

function resetRateLimitTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_attempts (
      attemptId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ipHash CHAR(64) NOT NULL,
      identityHash CHAR(64) NOT NULL, attemptedAt DATETIME NOT NULL, successful TINYINT(1) NOT NULL DEFAULT 0,
      PRIMARY KEY (attemptId), KEY reset_ip_time (ipHash,attemptedAt),
      KEY reset_identity_time (identityHash,attemptedAt), KEY reset_attempted_at (attemptedAt)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function resetIsLimited(PDO $pdo,string $ipHash,string $identityHash): bool {
    $q=$pdo->prepare("SELECT SUM(attemptedAt >= DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)) recentAttempts,
      SUM(attemptedAt >= DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)) dailyAttempts
      FROM password_reset_attempts WHERE ipHash=? OR identityHash=?");
    $q->execute(array($ipHash,$identityHash)); $c=$q->fetch()?:array();
    return (int)($c['recentAttempts']??0)>=5 || (int)($c['dailyAttempts']??0)>=12;
}

function resetRecordAttempt(PDO $pdo,string $ipHash,string $identityHash,bool $successful): void {
    $q=$pdo->prepare('INSERT INTO password_reset_attempts (ipHash,identityHash,attemptedAt,successful) VALUES (?,?,UTC_TIMESTAMP(),?)');
    $q->execute(array($ipHash,$identityHash,$successful?1:0));
    if (random_int(1,50)===1) $pdo->exec('DELETE FROM password_reset_attempts WHERE attemptedAt < DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)');
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $email=strtolower(trim((string)($_POST['email']??'')));
    $password=(string)($_POST['password']??''); $confirm=(string)($_POST['confirm']??'');
    try {
        if (!hash_equals((string)$_SESSION['csrf'],(string)($_POST['csrf']??''))) throw new RuntimeException('Your form expired. Refresh the page and try again.');
        if (strlen($email)>254 || filter_var($email,FILTER_VALIDATE_EMAIL)===false) throw new RuntimeException('Enter the email address saved on your account.');
        if (strlen($password)<12 || strlen($password)>4096) throw new RuntimeException('Use a password of at least 12 characters.');
        if (!hash_equals($password,$confirm)) throw new RuntimeException('The passwords do not match.');
        $pdo=resetConnection(); resetRateLimitTable($pdo);
        $config=require __DIR__.'/db.config.php'; $key=hash('sha256',(string)$config['password'].'|ghoti-password-reset');
        $ipHash=hash_hmac('sha256',(string)($_SERVER['REMOTE_ADDR']??''),$key); $identityHash=hash_hmac('sha256',$email,$key);
        if (resetIsLimited($pdo,$ipHash,$identityHash)) { $limited=true; throw new RuntimeException('Too many reset attempts. Try again later.'); }
        // Hash before lookup so valid and invalid identities take similar time.
        $algo=defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_DEFAULT; $hash=password_hash($password,$algo);
        if ($hash===false) throw new RuntimeException('The password could not be secured. Try again.');
        $q=$pdo->prepare('SELECT userId FROM users WHERE LOWER(TRIM(email))=? LIMIT 2'); $q->execute(array($email)); $matches=$q->fetchAll();
        if (count($matches)===1) {
            $q=$pdo->prepare('UPDATE users SET password=? WHERE userId=?');
            $q->execute(array($hash,(int)$matches[0]['userId'])); $success=$q->rowCount()===1;
        }
        resetRecordAttempt($pdo,$ipHash,$identityHash,$success);
        // Deliberately identical for existing, missing, and duplicate addresses.
        $message='If that email address matches one account, its password has been reset. You can now try signing in.';
        $complete=true;
        $_SESSION['csrf']=bin2hex(random_bytes(32));
    } catch (Throwable $e) { $message=$e->getMessage(); }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reset password</title><style>
:root{color-scheme:light dark}body{font:16px/1.5 system-ui,sans-serif;background:#eef2f6;color:#18202a;margin:0;padding:2rem}main{max-width:30rem;margin:7vh auto;background:#fff;padding:clamp(1.4rem,5vw,2.25rem);border-radius:.8rem;box-shadow:0 8px 30px #0002}label{display:block;margin:1rem 0;font-weight:600}.field{display:block;width:100%;box-sizing:border-box;padding:.75rem;margin-top:.35rem;font:inherit}button{padding:.75rem 1rem;font:inherit;cursor:pointer}.message{padding:.8rem;background:#f7e8e8;border-radius:.4rem}.success{background:#e4f4e7}.help{color:#536273;font-size:.94rem}.actions{display:flex;gap:1rem;align-items:center;flex-wrap:wrap;margin-top:1.25rem}@media(prefers-color-scheme:dark){body{background:#121820;color:#e9eef4}main{background:#202a35}.help{color:#b9c5d2}.message{background:#512d32}.success{background:#204a2a}}
</style></head><body><main><h1>Reset password</h1>
<p class="help">Enter the exact email address saved on your account. The address is used only to verify your identity and is never displayed.</p>
<?php if($message!==''):?><p role="alert" class="message<?= $complete?' success':'' ?>"><?=htmlspecialchars($message,ENT_QUOTES,'UTF-8')?></p><?php endif;?>
<?php if(!$complete&&!$limited):?><form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=htmlspecialchars((string)$_SESSION['csrf'],ENT_QUOTES,'UTF-8')?>">
<label>Email address<input class="field" type="email" name="email" maxlength="254" required autocomplete="email"></label>
<label>New password<input class="field" type="password" name="password" minlength="12" maxlength="4096" required autocomplete="new-password"></label>
<label>Confirm new password<input class="field" type="password" name="confirm" minlength="12" maxlength="4096" required autocomplete="new-password"></label>
<div class="actions"><button type="submit">Reset password</button><a href="/">Cancel</a></div></form><?php else:?><p><a href="/">Return to the site</a></p><?php endif;?>
</main></body></html>
