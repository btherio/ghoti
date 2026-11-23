<?php
/* Provider defaults < local or GHOTI_DB_CONFIG file < explicitly set environment.
 * Empty environment values are intentional (e.g. unauthenticated MongoDB).
 * See docs/database-providers.md. Credentials must never be committed. */
require_once __DIR__.'/lib/database/provider.php';
$localPath = getenv('GHOTI_DB_CONFIG') ?: __DIR__.'/db.config.local.php';
$local = is_file($localPath) ? require $localPath : array();
if(!is_array($local)){ throw new RuntimeException('Database local config must return an array.'); }
$driver = getenv('GHOTI_DB_DRIVER');
$driver = $driver !== false ? strtolower($driver) : ($local['driver'] ?? 'mysql');
// Switching providers through the environment must not inherit another
// provider's host, port, username or password from a local MySQL installation.
$config = GhotiDatabaseProvider::defaults($driver);
if(($local['driver'] ?? 'mysql') === $driver){ $config = array_merge($config, $local); }
foreach(GhotiDatabaseProvider::ENVIRONMENT_FIELDS as $field => $suffix){
    $value = getenv('GHOTI_DB_'.$suffix);
    if($value !== false){ $config[$field] = $value; }
}
return GhotiDatabaseProvider::normalize($config);
