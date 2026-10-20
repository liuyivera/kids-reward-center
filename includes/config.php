<?php

$localConfigFile = __DIR__ . '/config.local.php';
$runtimeConfig = [];

if (file_exists($localConfigFile)) {
    $runtimeConfig = require $localConfigFile;
}

if (is_array($runtimeConfig) && ! empty($runtimeConfig)) {
    $appEnv = $runtimeConfig['app_env'] ?? getenv('APP_ENV') ?: 'local';
    $db = $runtimeConfig['db'] ?? [];
    $creatorParentIds = $runtimeConfig['creator_parent_ids'] ?? [];
    $creatorParentId = $runtimeConfig['creator_parent_id'] ?? 0;

    define('APP_ENV', $appEnv);
    define('DB_HOST', $db['host'] ?? getenv('DB_HOST') ?: 'localhost');
    define('DB_PORT', $db['port'] ?? getenv('DB_PORT') ?: '3306');
    define('DB_NAME', $db['name'] ?? getenv('DB_NAME') ?: 'family_points');
    define('DB_USER', $db['user'] ?? getenv('DB_USER') ?: 'root');
    define('DB_PASS', $db['pass'] ?? getenv('DB_PASS') ?: '');
    define('APP_CREATOR_PARENT_IDS', is_array($creatorParentIds) ? $creatorParentIds : []);
    define('APP_CREATOR_PARENT_ID', (int) ($creatorParentId ?: getenv('APP_CREATOR_PARENT_ID') ?: 0));
    return;
}

$envApp = getenv('APP_ENV');
$envDbHost = getenv('DB_HOST');
$envDbPort = getenv('DB_PORT');
$envDbName = getenv('DB_NAME');
$envDbUser = getenv('DB_USER');
$envDbPass = getenv('DB_PASS');
$envCreatorParentIds = getenv('APP_CREATOR_PARENT_IDS');
$envCreatorParentId = getenv('APP_CREATOR_PARENT_ID');

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

if (! defined('APP_CREATOR_PARENT_IDS')) {
    $parsedCreatorParentIds = [];
    if (is_string($envCreatorParentIds) && $envCreatorParentIds !== '') {
        $parsedCreatorParentIds = array_values(array_filter(array_map('trim', explode(',', $envCreatorParentIds))));
    }
    define('APP_CREATOR_PARENT_IDS', $parsedCreatorParentIds);
}

if (! defined('APP_CREATOR_PARENT_ID')) {
    define('APP_CREATOR_PARENT_ID', (int) ($envCreatorParentId ?: 0));
}
