<?php

$localConfigFile = __DIR__ . '/config.local.php';
$runtimeConfig = [];

if (file_exists($localConfigFile)) {
    $runtimeConfig = require $localConfigFile;
}

if (is_array($runtimeConfig) && ! empty($runtimeConfig)) {
    $appEnv = $runtimeConfig['app_env'] ?? getenv('APP_ENV') ?: 'local';
    $db = $runtimeConfig['db'] ?? [];

    define('APP_ENV', $appEnv);
    define('DB_HOST', $db['host'] ?? getenv('DB_HOST') ?: 'localhost');
    define('DB_PORT', $db['port'] ?? getenv('DB_PORT') ?: '3306');
    define('DB_NAME', $db['name'] ?? getenv('DB_NAME') ?: 'family_points');
    define('DB_USER', $db['user'] ?? getenv('DB_USER') ?: 'root');
    define('DB_PASS', $db['pass'] ?? getenv('DB_PASS') ?: '');
    return;
}

$envApp = getenv('APP_ENV');
$envDbHost = getenv('DB_HOST');
$envDbPort = getenv('DB_PORT');
$envDbName = getenv('DB_NAME');
$envDbUser = getenv('DB_USER');
$envDbPass = getenv('DB_PASS');

if (! defined('APP_ENV')) {
    define('APP_ENV', $envApp ?: 'local');
}

if (! defined('DB_HOST')) {
    define('DB_HOST', $envDbHost ?: 'localhost');
}

if (! defined('DB_PORT')) {
    define('DB_PORT', $envDbPort ?: '3306');
}

if (! defined('DB_NAME')) {
    define('DB_NAME', $envDbName ?: 'family_points');
}

if (! defined('DB_USER')) {
    define('DB_USER', $envDbUser ?: 'root');
}

if (! defined('DB_PASS')) {
    define('DB_PASS', $envDbPass ?: '');
}
