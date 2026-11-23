<?php

// The installer runs before normal module startup. A configured operator key
// and a session CSRF token are required before it can make changes.
function ghoti_setup_state(): string {
    $path = __DIR__.'/.ghoti-install';
    clearstatcache(true, $path);
    return is_file($path) ? trim((string)@file_get_contents($path)) : '';
}

function ghoti_setup_mode(): string {
    $connected = ghotidb::isConfigured();
    $state = ghoti_setup_state();
    if($state === 'complete'){ return $connected ? 'ready' : 'recovery'; }
    if($connected && $state === ''){
        try{
            if(ghotidb::hasUsers()){ return 'ready'; }
        }catch(PDOException $e){
            ghoti::logException('installation: detect existing site', $e);
        }
    }
    return 'install';
}

function ghoti_setup_access_ok($keyHash, $token): bool {
    $key = getenv('GHOTI_SETUP_KEY');
    return $key !== false && $key !== '' && is_string($keyHash)
        && hash_equals(hash('sha256', $key), $keyHash) && ghoti_csrf_verify($token);
}

function ghoti_setup_headers(int $status): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
}

function ghoti_setup_redirect(string $step = ''): void {
    header('Location: index.php'.($step === '' ? '' : '?step='.$step), true, 303);
    exit;
}

function ghoti_setup_fingerprint(): string {
    try{
        return hash('sha256', serialize(ghotidb::loadConfig()));
    }catch(Throwable $e){
        return '';
    }
}

function ghoti_setup_db_managed(): bool {
    $path = getenv('GHOTI_DB_CONFIG');
    return $path !== false && $path !== '' && $path !== __DIR__.'/db.config.local.php';
}

function ghoti_setup_database_input(array $input): array {
    $host = trim(ghoti_setup_input($input, 'host'));
    if(!preg_match('/^[A-Za-z0-9_.-]{1,255}$/D', $host) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)){
        throw new InvalidArgumentException('Enter a valid database hostname or IP address.');
    }
    $port = filter_var(trim(ghoti_setup_input($input, 'port')), FILTER_VALIDATE_INT,
        array('options' => array('min_range' => 1, 'max_range' => 65535)));
    if($port === false){
        throw new InvalidArgumentException('Enter a database port between 1 and 65535.');
    }
    $database = trim(ghoti_setup_input($input, 'database'));
    if(!preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $database)){
        throw new InvalidArgumentException('Enter a database name using letters, numbers, dots, underscores or hyphens.');
    }
    $username = trim(ghoti_setup_input($input, 'username'));
    if($username === '' || strlen($username) > 128){
        throw new InvalidArgumentException('Enter a database username of up to 128 bytes.');
    }
    $password = ghoti_setup_input($input, 'password');
    if(strlen($password) > 512){ throw new InvalidArgumentException('The database password is too long.'); }
    $charset = trim(ghoti_setup_input($input, 'charset')) ?: 'utf8mb4';
    if(!preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $charset)){
        throw new InvalidArgumentException('Enter a valid database character set.');
    }
    return array('driver' => 'mysql', 'host' => $host, 'port' => (string)$port, 'database' => $database,
        'username' => $username, 'password' => $password, 'charset' => $charset);
}

function ghoti_setup_input(array $input, string $key): string {
    $value = $input[$key] ?? '';
    if(!is_string($value)){ throw new InvalidArgumentException('Invalid installation settings.'); }
    return $value;
}

function ghoti_setup_site_input(array $input): array {
    $title = trim(ghoti_setup_input($input, 'siteTitle'));
    if($title === '' || strlen($title) > 120 || !preg_match('//u', $title) || preg_match('/[<>"\x00-\x1f\x7f]/', $title)){
        throw new InvalidArgumentException('Enter a site title of up to 120 bytes, without markup or double quotes.');
    }
    $theme = ghoti_setup_input($input, 'defaultTheme');
    if(!ghoti::isValidTheme($theme)){ throw new InvalidArgumentException('Choose an installed theme.'); }
    $url = trim(ghoti_setup_input($input, 'seoSiteUrl'));
    if($url !== '' && ghoti_seo_clean_url($url) === ''){
        throw new InvalidArgumentException('Enter a full HTTPS site URL without a query string or fragment, or leave it blank.');
    }
    return array('siteTitle' => $title, 'defaultTheme' => $theme, 'seoSiteUrl' => $url,
        'seoAllowIndexing' => ghoti_setup_input($input, 'seoAllowIndexing') === '1');
}

function ghoti_setup_save_database(array $config): void {
    $temporary = @tempnam(__DIR__, '.ghoti-db-');
    if($temporary === false){ throw new RuntimeException('PHP could not create the local database configuration. Check the installation directory permissions.'); }
    try{
        $contents = "<?php\nreturn ".var_export($config, true).";\n";
        if(dirname($temporary) !== __DIR__ || !@chmod($temporary, 0600)
            || @file_put_contents($temporary, $contents) !== strlen($contents)
            || !@rename($temporary, __DIR__.'/db.config.local.php')){
            throw new RuntimeException('PHP could not save db.config.local.php. Check the installation directory permissions.');
        }
        if(function_exists('opcache_invalidate')){ @opcache_invalidate(__DIR__.'/db.config.local.php', true); }
    }finally{
        if(is_file($temporary)){ @unlink($temporary); }
    }
}

// Keep the lock file in place so concurrent setup requests always lock the
// same inode. A pending state prevents bypass if setup stops after creating
// the administrator and before recording completion.
function ghoti_setup_with_lock(callable $operation): void {
    $lock = @fopen(__DIR__.'/.ghoti-install', 'c+');
    if($lock === false){ throw new RuntimeException('PHP could not write the installation state. Check the installation directory permissions.'); }
    try{
        if(!flock($lock, LOCK_EX)){ throw new RuntimeException('PHP could not lock the installation state. Retry setup.'); }
        $operation($lock);
    }finally{
        fclose($lock);
    }
}

function ghoti_setup_write_state($lock, string $state): void {
    // These fixed-width values replace one another without truncating the file.
    $value = str_pad($state, 8);
    if(!rewind($lock) || fwrite($lock, $value) !== strlen($value) || !fflush($lock)){
        throw new RuntimeException('PHP could not record installation progress. Check the installation directory permissions.');
    }
}

function ghoti_setup_check_directory(string $directory): bool {
    if(!is_dir($directory)){ return false; }
    $path = $directory.'/.ghoti-check-'.bin2hex(random_bytes(8));
    $file = @fopen($path, 'x');
    if($file === false){ return false; }
    $wrote = @fwrite($file, 'ok') === 2 && @fflush($file);
    fclose($file);
    return @unlink($path) && $wrote;
}

function ghoti_setup_checks(): array {
    $checks = array();
    $checks['PHP 8.1 or newer'] = PHP_VERSION_ID >= 80100 ? null : 'Select PHP 8.1 or newer for this site.';
    foreach(array('pdo_mysql', 'dom', 'fileinfo', 'mbstring', 'xml') as $extension){
        $checks['PHP extension '.$extension] = extension_loaded($extension) ? null : 'Enable '.$extension.' for the web server’s PHP runtime.';
    }
    $checks['PHP session'] = session_status() === PHP_SESSION_ACTIVE ? null : 'Check PHP session storage and permissions.';
    $checks['Database connection'] = ghotidb::isConfigured() ? null : 'Return to Database and test the connection again.';
    foreach(array('.' => 'Installation directory', 'files' => 'Upload directory (files/)',
        'files/gallery' => 'Gallery directory (files/gallery/)') as $relative => $label){
        $checks[$label] = ghoti_setup_check_directory(__DIR__.'/'.$relative)
            ? null : 'Ensure this directory exists and PHP can create, write and remove files in it.';
    }
    $uploadTemp = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
    $checks['PHP upload temporary directory'] = ghoti_setup_check_directory($uploadTemp)
        ? null : 'Configure a writable upload_tmp_dir for PHP.';
    foreach(array(basename(ghoti::settingsPath()), ghoti::$ghotiLog, '.ghoti-install',
        'db.provisioned.json', 'login.throttle.json', 'security.blacklist.json', 'critical-alerts.json',
        'critical-alerts.pending.json', 'critical-alerts.history.json') as $name){
        $path = __DIR__.'/'.$name;
        if(file_exists($path) && (!is_file($path) || !is_readable($path) || !is_writable($path))){
            $checks[$name] = 'Give the PHP service account read and write access to this file.';
        }else{
            $checks[$name] = null;
        }
    }
    return $checks;
}

function ghoti_setup_finish($lock, array $site, array $input): void {
    try{
        if(!ghotidb::provisionInstallation()){
            throw new RuntimeException('Core schema or public home page is missing.');
        }
    }catch(Throwable $e){
        ghoti::logException('installation: database provisioning', $e);
        throw new RuntimeException('Ghoti could not prepare its database tables and public home page. Check the database privileges and server log.');
    }

    require_once __DIR__.'/lib/first-admin.php';
    $credentials = null;
    if(!ghotidb::hasUsers(null, true)){
        try{
            $credentials = GhotiFirstAdmin::validateCredentials(ghoti_setup_input($input, 'adminName'),
                ghoti_setup_input($input, 'adminEmail'), ghoti_setup_input($input, 'adminPassword'));
        }catch(Exception $e){
            throw new InvalidArgumentException($e->getMessage());
        }
    }

    ghoti_setup_write_state($lock, 'pending');
    $saved = ghoti::saveSettings($site);
    if($saved !== true){ throw new RuntimeException($saved); }
    if($credentials !== null){
        try{
            (new GhotiFirstAdmin())->createFirstAdmin(...$credentials);
        }catch(Throwable $e){
            ghoti::logException('installation: administrator', $e);
            throw new RuntimeException('Ghoti could not create the first administrator. Check the server log and confirm the users table is empty.');
        }
    }
    ghoti_setup_write_state($lock, 'complete');
}

function ghoti_setup_dispatch(string $mode = 'install'): void {
    ghoti_setup_headers(503);
    $key = getenv('GHOTI_SETUP_KEY');
    if($mode === 'ready'){
        ghoti_setup_headers(409);
        ghoti_setup_render(array('message' => 'Setup has already finished.', 'title' => 'Ghoti is ready'));
        exit;
    }
    if($key === false || $key === ''){
        ghoti_setup_render(array('message' => 'Set GHOTI_SETUP_KEY in the PHP environment, then open this installation with ?k=YOUR_SETUP_KEY.',
            'title' => 'Ghoti setup is locked'));
        exit;
    }
    $keyHash = hash('sha256', $key);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if($method === 'GET' && isset($_GET['k']) && is_string($_GET['k']) && hash_equals($key, $_GET['k'])){
        if(session_status() !== PHP_SESSION_ACTIVE || !session_regenerate_id(true)){
            ghoti_setup_render(array('message' => 'Check PHP session storage and permissions, then open the setup link again.',
                'title' => 'Ghoti could not start a setup session'));
            exit;
        }
        $_SESSION['ghoti_setup_key'] = $keyHash;
        ghoti_setup_redirect();
    }
    if(!hash_equals($keyHash, $_SESSION['ghoti_setup_key'] ?? '')){
        ghoti_setup_headers(403);
        ghoti_setup_render(array('message' => 'Open this installation with the operator setup key in ?k=YOUR_SETUP_KEY.',
            'title' => 'Ghoti setup is locked'));
        exit;
    }

    $connected = ghotidb::isConfigured();
    $managed = ghoti_setup_db_managed();
    $fingerprint = ghoti_setup_fingerprint();
    $databaseReady = $mode === 'install' && $connected && $fingerprint !== ''
        && $fingerprint === ($_SESSION['ghoti_setup_database'] ?? '');
    $site = $databaseReady ? ($_SESSION['ghoti_setup_site'] ?? null) : null;
    $requested = $_GET['step'] ?? '';
    $step = $databaseReady && $requested === 'checks' && $site !== null ? 'checks'
        : ($databaseReady && ($requested === 'site' || $requested === 'checks') ? 'site' : 'database');
    $error = $notice = '';
    $checks = null;
    $databaseValues = array();
    if($method === 'POST'){
        if(!ghoti_setup_access_ok($_SESSION['ghoti_setup_key'], $_POST['token'] ?? null)){
            ghoti_setup_headers(403);
            ghoti_setup_render(array('message' => 'Reload setup to get a fresh session and try again.', 'title' => 'Setup session expired'));
            exit;
        }
        try{
            $action = ghoti_setup_input($_POST, 'ghoti_setup_action');
            if($action === 'run-checks'){
                $checks = ghoti_setup_checks();
            }elseif($step === 'database' && in_array($action, array('test-database', 'save-database', 'use-database'), true)){
                if($action === 'use-database'){
                    if(!$connected || $mode !== 'install'){ throw new RuntimeException('The current database connection is unavailable.'); }
                    if(!$databaseReady){ unset($_SESSION['ghoti_setup_site']); }
                    $_SESSION['ghoti_setup_database'] = $fingerprint;
                    ghoti_setup_redirect('site');
                }
                if($managed){ throw new RuntimeException('Update the database settings in the file selected by GHOTI_DB_CONFIG.'); }
                $databaseValues = $_POST;
                $config = ghoti_setup_database_input($_POST);
                try{
                    $candidate = ghotidb::openConnection($config, 4);
                }catch(Throwable $e){
                    throw new RuntimeException('Connection failed. Check the PDO MySQL extension, host, port, database name and credentials.');
                }
                if($mode === 'recovery' && !ghotidb::hasUsers($candidate)){
                    throw new RuntimeException('Select the existing site database or restore it from backup before reconnecting.');
                }
                if($action === 'test-database'){
                    $notice = 'Database connection succeeded.';
                }else{
                    ghoti_setup_with_lock(function() use ($config, $fingerprint, $mode){
                        if(ghoti_setup_fingerprint() !== $fingerprint || ghoti_setup_mode() !== $mode){
                            throw new RuntimeException('Installation changed in another request. Reload setup and try again.');
                        }
                        ghoti_setup_save_database($config);
                    });
                    unset($_SESSION['ghoti_setup_site']);
                    $_SESSION['ghoti_setup_database'] = ghoti_setup_fingerprint();
                    ghoti_setup_redirect($mode === 'recovery' ? '' : 'site');
                }
            }elseif($step === 'site' && $action === 'save-site'){
                $_SESSION['ghoti_setup_site'] = ghoti_setup_site_input($_POST);
                ghoti_setup_redirect('checks');
            }elseif($step === 'checks' && $action === 'finish'){
                $checks = ghoti_setup_checks();
                if(array_filter($checks)){ throw new RuntimeException('Fix the failed installation checks and try again.'); }
                ghoti_setup_with_lock(function($lock) use ($site, $fingerprint){
                    if(ghoti_setup_fingerprint() !== $fingerprint || ghoti_setup_mode() !== 'install'){
                        throw new RuntimeException('Installation changed in another request. Reload setup and try again.');
                    }
                    ghoti_setup_finish($lock, $site, $_POST);
                });
                unset($_SESSION['ghoti_setup_key'], $_SESSION['ghoti_setup_database'], $_SESSION['ghoti_setup_site']);
                ghoti_setup_redirect();
            }else{
                throw new InvalidArgumentException('Complete the current setup step before continuing.');
            }
        }catch(PDOException $e){
            ghoti::logException('installation: database check', $e);
            $error = 'Ghoti could not read the database. Check the account permissions and server log.';
        }catch(InvalidArgumentException | RuntimeException $e){
            $error = $e->getMessage();
        }catch(Throwable $e){
            ghoti::logException('installation: request', $e);
            $error = 'Setup could not complete this request. Check the server log and try again.';
        }
    }

    $hasAdmin = false;
    if($step === 'checks'){
        try{
            $hasAdmin = ghotidb::hasUsers(null, true);
        }catch(Throwable $e){
            ghoti::logException('installation: account check', $e);
        }
    }
    try{
        $config = ghotidb::loadConfig();
    }catch(Throwable $e){
        $config = array();
    }
    $fields = array('host' => '127.0.0.1', 'port' => '3306', 'database' => 'ghoti', 'username' => 'ghoti', 'charset' => 'utf8mb4');
    foreach($fields as $name => $default){
        $value = $databaseValues[$name] ?? $config[$name] ?? $default;
        if($name === 'host' && $databaseValues === array() && !is_file(__DIR__.'/db.config.local.php')
            && !$managed && getenv('GHOTI_DB_HOST') === false){ $value = $default; }
        $fields[$name] = is_string($value) ? $value : $default;
    }
    if($step === 'site' && $error !== ''){
        $site = array_intersect_key($_POST, array_flip(array('siteTitle', 'defaultTheme', 'seoSiteUrl', 'seoAllowIndexing')));
        $site['seoAllowIndexing'] = ($site['seoAllowIndexing'] ?? '') === '1';
    }
    $site = ($site ?? array()) + ghoti::currentSettings();
    $status = $error !== '' || ($checks !== null && array_filter($checks)) ? 422 : 200;
    ghoti_setup_headers($status);
    ghoti_setup_render(compact('mode', 'step', 'connected', 'managed', 'fields', 'site', 'checks', 'error', 'notice', 'hasAdmin'));
    exit;
}

function ghoti_setup_render(array $view): void {
    require __DIR__.'/lib/install-view.php';
}
