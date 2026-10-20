<?php
// 已登录家长 → 直接进控制台；未登录 → 公开落地页
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (! empty($_SESSION['parent_id'])) {
    header('Location: parent-dashboard.php');
    exit;
}

require_once __DIR__ . '/landing.php';