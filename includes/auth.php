<?php

require_once __DIR__ . '/config.php';

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');

if (APP_ENV === 'production') {
    ini_set('session.cookie_secure', '1');
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

session_start();

if (empty($_SESSION['parent_id'])) {
    header('Location: /family-points/parent-login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars($_SESSION['csrf_token'])
        . '">';
}

function verifyCsrf(): void
{
    if (! hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('页面已过期，请返回重试。');
    }
}
