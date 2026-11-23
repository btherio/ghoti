<?php

// First-run setup is dispatched by index.php before normal module startup.
final class GhotiInstaller {
    private const ROOT = __DIR__.'/..';
    private const DATA_DIR = self::ROOT.'/.ghoti-data';
    private const CONFIG = self::ROOT.'/db.config.local.php';
    private const LOCK = '.setup.lock';

    public static function isFresh(): bool {
        if(getenv('GHOTI_DB_CONFIG')){
            return false;
        }
        foreach(GhotiDatabaseProvider::ENVIRONMENT_FIELDS as $suffix){
            if(getenv('GHOTI_DB_'.$suffix) !== false){
                return false;
            }
        }
        clearstatcache();
        foreach(array(
            'db.config.local.php', '.ghoti-install', 'db.provisioned.json',
            'ghoti.settings.json', 'login.throttle.json'
        ) as $path){
            if(file_exists(self::ROOT.'/'.$path) || is_link(self::ROOT.'/'.$path)){
                return false;
            }
        }
        if(is_link(self::DATA_DIR)){ return false; }
        if(!file_exists(self::DATA_DIR)){ return true; }

        // A setup lock may be left after a retry. A database or other
        // runtime state is evidence that this directory has already been used.
        $entries = @scandir(self::DATA_DIR);
        return $entries !== false && !array_diff($entries, array('.', '..', self::LOCK));
    }

    public static function dispatch(): void {
        $method = ghoti_request_method();
        $name = 'admin';
        $email = '';
        $error = '';
        $ready = true;
        $status = 200;

        if(!in_array('sqlite', PDO::getAvailableDrivers(), true)){
            $error = 'Enable the PHP pdo_sqlite extension, then reload this page.';
            $ready = false;
            $status = 503;
        }elseif(!is_writable(self::ROOT) || (is_dir(self::DATA_DIR) && !is_writable(self::DATA_DIR))){
            $error = 'Give PHP write access to the Ghoti directory and its .ghoti-data directory, then reload this page.';
            $ready = false;
            $status = 503;
        }elseif(session_status() !== PHP_SESSION_ACTIVE){
            $error = 'PHP could not start a session. Check session storage and reload this page.';
            $ready = false;
            $status = 503;
        }elseif($method === 'POST'){
            if(!ghoti_csrf_verify($_POST['token'] ?? null)){
                ghoti::logWarn('install:browser', 'Setup form rejected for an invalid session token from '
                    .ghoti_validate()->logLine(ghoti_remote_addr()));
                $error = 'This setup session expired. Reload the page and try again.';
                $status = 403;
            }else{
                $name = self::formValue('adminName', validate::MAX_USERNAME);
                $email = self::formValue('adminEmail', 254);
                require_once __DIR__.'/first-admin.php';
                try{
                    $credentials = GhotiFirstAdmin::validateCredentials($name, $email, self::formValue('adminPassword', validate::MAX_PASSWORD));
                }catch(Exception $e){
                    $error = $e->getMessage();
                    $status = 422;
                }
                if($error === ''){
                    try{
                        $installed = self::install($credentials);
                        if($installed || is_file(self::CONFIG)){
                            unset($_SESSION['csrf_token']);
                            session_regenerate_id(true);
                            header('Location: index.php?login', true, 303);
                            exit;
                        }
                        $error = 'Setup has already started. Reload this page to check the installation.';
                        $status = 409;
                    }catch(Throwable $e){
                        ghoti::logException('install:browser', $e);
                        $error = 'Ghoti could not finish setup. Check that PHP can write to the installation directory, then try again.';
                        $status = 503;
                    }
                }
            }
        }

        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        if($method === 'HEAD'){ return; }
        require __DIR__.'/install-view.php';
    }

    private static function formValue(string $key, int $limit): string {
        $value = $_POST[$key] ?? '';
        return is_string($value) && strlen($value) <= $limit ? $value : '';
    }

    private static function install(array $credentials): bool {
        if(!is_dir(self::DATA_DIR) && !@mkdir(self::DATA_DIR, 0700) && !is_dir(self::DATA_DIR)){
            throw new RuntimeException('Could not create the SQLite data directory.');
        }
        $lock = @fopen(self::DATA_DIR.'/'.self::LOCK, 'c');
        if($lock === false){ throw new RuntimeException('Could not open the installation lock.'); }

        $stage = null;
        $pending = null;
        $installed = false;
        try{
            if(!flock($lock, LOCK_EX)){ throw new RuntimeException('Could not lock the installation.'); }
            if(!self::isFresh()){ return false; }

            // Keep all SQLite files private until the schema and admin are
            // ready. Publishing this config is the only activation step.
            $folder = 'site-'.bin2hex(random_bytes(12));
            $directory = self::DATA_DIR.'/'.$folder;
            if(!@mkdir($directory, 0700)){ throw new RuntimeException('Could not prepare a staging directory.'); }
            $stage = $directory;
            $root = realpath(self::ROOT);
            $relativePath = '/.ghoti-data/'.$folder.'/ghoti.sqlite';
            $pending = @tempnam(self::ROOT, '.ghoti-config-');
            $contents = "<?php\nreturn array('driver' => 'sqlite', 'sqlite_path' => __DIR__."
                .var_export($relativePath, true).");\n";
            if($pending === false || dirname($pending) !== $root || @file_put_contents($pending, $contents) !== strlen($contents)){
                throw new RuntimeException('Could not stage the database configuration.');
            }
            $config = array('driver' => 'sqlite', 'sqlite_path' => $root.$relativePath);
            if(!ghotidb::initializeStagedInstallation($config, static function() use ($credentials){
                return (new GhotiFirstAdmin())->createFirstAdmin(...$credentials);
            })){
                throw new RuntimeException('Could not initialize the database and first administrator.');
            }
            if(!@link($pending, self::CONFIG)){
                throw new RuntimeException('Could not publish the database configuration.');
            }
            $installed = true;
            ghoti::logInfo('install:browser', 'First administrator created for '.ghoti_validate()->logLine($credentials[0])
                .' from '.ghoti_validate()->logLine($_SERVER['REMOTE_ADDR'] ?? ''));
            return true;
        }finally{
            if(is_string($pending)){ @unlink($pending); }
            if(!$installed && $stage !== null){
                foreach(array_diff(@scandir($stage) ?: array(), array('.', '..')) as $entry){
                    @unlink($stage.'/'.$entry);
                }
                @rmdir($stage);
            }
            fclose($lock);
        }
    }
}
