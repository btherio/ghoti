<?php
/*
 * Database connection configuration for the PDO-based data layer.
 *
 * Every value can be overridden with an environment variable of the same
 * name (e.g. set via Apache SetEnv/php-fpm pool config), so real deployments
 * can supply credentials without editing tracked source files.
 *
 * For a fully untracked override, create db.config.local.php next to this
 * file returning the same shape - it is picked up automatically and is
 * excluded from version control via .gitignore.
 */

$config = array(
    'driver'   => getenv('GHOTI_DB_DRIVER')   ?: 'mysql',
    'host'     => getenv('GHOTI_DB_HOST')     ?: '10.0.0.17',
    'port'     => getenv('GHOTI_DB_PORT')     ?: '3306',
    'database' => getenv('GHOTI_DB_NAME')     ?: 'ghoti',
    'username' => getenv('GHOTI_DB_USER')     ?: 'ghoti',
    // NO password fallback is shipped here: a literal credential in a tracked
    // source file ends up in git history. The password must come from either
    // the GHOTI_DB_PASSWORD environment variable or the untracked local
    // override (db.config.local.php) below. See db.config.local.php for the
    // per-install credential file.
    'password' => getenv('GHOTI_DB_PASSWORD') ?: '',
    'charset'  => getenv('GHOTI_DB_CHARSET')  ?: 'utf8mb4',
);

$localOverride = __DIR__.'/db.config.local.php';
if (is_file($localOverride)) {
    $config = array_merge($config, require $localOverride);
}

return $config;
