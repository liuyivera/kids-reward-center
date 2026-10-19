<?php

require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$children = db()->query(
    'SELECT id, name, theme, normal_balance, daily_points_today,
            daily_cap, quarterly_pool, quarterly_goal
     FROM children
     ORDER BY id'
)->fetchAll();

echo json_encode([
    'children' => $children
]);
