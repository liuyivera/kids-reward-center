<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/scoring.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: parent-record.php');
    exit;
}

verifyCsrf();

$childId = (int)($_POST['child_id'] ?? 0);
$behaviorId = (int)($_POST['behavior_id'] ?? 0);
$completionBase = $_POST['completion_base'] ?? '';
$excellent = isset($_POST['excellent']);
$eventDate = $_POST['event_date'] ?? date('Y-m-d');
$note = trim($_POST['note'] ?? '');

// 修复：验证 child 属于当前家长的家庭
requireParentAccessToChild($childId);

if (! in_array($completionBase, ['reminder', 'self_initiated'], true)) {
    exit('完成方式无效。');
}

$completionType = $excellent
    ? $completionBase . '_excellent'
    : $completionBase;

if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
    exit('日期无效。');
}

$stmt = db()->prepare('SELECT * FROM behaviors WHERE id = ? AND child_id = ?');
$stmt->execute([$behaviorId, $childId]);
$behavior = $stmt->fetch();

if (! $behavior) {
    exit('行为不存在。');
}

$result = recordPointEvent(
    $childId,
    $behaviorId,
    $behavior['label'],
    (int)$behavior['base_points'],
    $completionType,
    $eventDate,
    $note !== '' ? $note : null
);

$_SESSION['last_result'] = $result;
$_SESSION['last_behavior'] = $behavior['label'];

header('Location: parent-result.php');
exit;