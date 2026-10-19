<?php

require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$stmt = db()->query('SELECT MAX(updated_at) AS v FROM children');
$row = $stmt->fetch();

echo json_encode([
    'v' => $row['v'] ?? '0'
]);
