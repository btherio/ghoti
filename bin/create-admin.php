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

function hasInternalSqliteConfig($path, $sqlitePath){
    clearstatcache(true, $path);
    if(!file_exists($path) && !is_link($path)){ return false; }
    if(!is_file($path) || !is_readable($path)){
        throw new RuntimeException('Could not read db.config.local.php. Check the installation permissions.');
    }
    $config = ghotidb::loadConfig();
    if($config['driver'] !== 'sqlite' || $config['sqlite_path'] !== $sqlitePath){
        throw new RuntimeException('A different database is already configured. Run without --sqlite to use it.');
    }
    return true;
}

function beginInternalSqliteSetup($path){
    $settings = array_merge(array('CONFIG'), array_values(GhotiDatabaseProvider::ENVIRONMENT_FIELDS));
    foreach($settings as $suffix){
        if(getenv('GHOTI_DB_'.$suffix) !== false){
            throw new RuntimeException('Database environment settings are present. Run without --sqlite to use them.');
        }
    }
    if(!in_array('sqlite', PDO::getAvailableDrivers(), true)){
        throw new RuntimeException('Enable the PHP pdo_sqlite extension before running setup.');
    }

    $sqlitePath = GhotiDatabaseProvider::defaults('sqlite')['sqlite_path'];
    if(hasInternalSqliteConfig($path, $sqlitePath)){ return array(null, null); }
    $directory = dirname($sqlitePath);
    if(!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory)){
        throw new RuntimeException('Could not create the SQLite data directory.');
    }
    $lock = @fopen($directory.'/.setup.lock', 'c');
    if($lock === false || !flock($lock, LOCK_EX)){
        throw new RuntimeException('Could not lock the SQLite data directory for setup.');
    }
    if(hasInternalSqliteConfig($path, $sqlitePath)){ return array(null, null); }

    $temporary = @tempnam(dirname($path), '.ghoti-config-');
    if($temporary === false){
        throw new RuntimeException('Could not prepare db.config.local.php. Check the installation permissions.');
    }
    register_shutdown_function(static function() use ($temporary){ unlink($temporary); });
    $contents = "<?php\nreturn array('driver' => 'sqlite');\n";
    // Keep SQLite private to this process until the schema and admin are ready.
    if(dirname($temporary) !== dirname($path) || @file_put_contents($temporary, $contents) !== strlen($contents)
        || !putenv('GHOTI_DB_CONFIG='.$temporary)){
        throw new RuntimeException('Could not prepare db.config.local.php. Check the installation permissions.');
    }
    return array($lock, $temporary);
}

$args = array_slice($argv, 1);
$setupSqlite = ($args[0] ?? '') === '--sqlite';
if($setupSqlite){ array_shift($args); }
if(count($args) !== 2){
    fwrite(STDERR, "Usage: php bin/create-admin.php [--sqlite] USERNAME EMAIL\n");
    exit(1);
}
$configPath = dirname(__DIR__).'/db.config.local.php';
try{
    list($name, $email, $password) = GhotiFirstAdmin::validateCredentials($args[0], $args[1], readAdminPassword());
    // Retain the lock through provisioning and config publication.
    list($setupLock, $pendingConfig) = $setupSqlite ? beginInternalSqliteSetup($configPath) : array(null, null);
}catch(Throwable $e){
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}
if(!ghotidb::isConfigured()){
    fwrite(STDERR, "Database unavailable. Check the connection settings and ghoti.log.\n");
    exit(1);
}
try{
    if($pendingConfig !== null){
        ghoti::loadSettings();
        if(!ghotidb::provisionInstallation()){ throw new RuntimeException('Could not provision the installation schema.'); }
    }
    $bootstrap = new GhotiFirstAdmin();
    $created = $bootstrap->createFirstAdmin($name, $email, $password);
}catch(Throwable $e){
    ghoti::logException('create-admin.php', $e);
    fwrite(STDERR, "Administrator not created. Check the server log and bootstrap requirements.\n");
    exit(1);
}
if($pendingConfig !== null && ($created || $bootstrap->countAdmins() > 0)){
    // Publish atomically and leave any file created by the operator in place.
    if(!@link($pendingConfig, $configPath)){
        fwrite(STDERR, "Could not save db.config.local.php. Check whether it already exists and verify the installation permissions.\n");
        exit(1);
    }
    ghoti::logInfo('create-admin.php', 'Internal SQLite configured from the server shell.');
    fwrite(STDOUT, "SQLite configured.\n");
    if(!$created){
        fwrite(STDOUT, "Sign in with an existing administrator.\n");
        exit;
    }
}
if(!$created){
    fwrite(STDERR, "The users table already contains accounts. Manage them through an existing administrator.\n");
    exit(1);
}
ghoti::logInfo('create-admin.php', 'First administrator created from the server shell for '.ghoti_validate()->logLine($name));
fwrite(STDOUT, "Administrator created.\n");
