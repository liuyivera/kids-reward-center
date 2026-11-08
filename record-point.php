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

// 获取孩子姓名，用于播报
$childStmt = db()->prepare('SELECT name FROM children WHERE id = ?');
$childStmt->execute([$childId]);
$childName = $childStmt->fetchColumn() ?: '孩子';

$result = recordPointEvent(
    $childId,
    $behaviorId,
    $behavior['label'],
    (int)$behavior['base_points'],
    $completionType,
    $eventDate,
    $note !== '' ? $note : null
);

// ==========================================
// 触发 Echo Show 语音播报 (Voice Monkey)
// ==========================================
$awarded = $result['awarded'];
if ($awarded > 0 && defined('VM_TOKEN') && VM_TOKEN !== '') {
    
    // 1. 定义翻译字典（如果以后加了新行为或新孩子，在这里加对应的拼音/英文）
    $dictionary = [
        '姐姐' => 'Wrenley', 
        '弟弟' => 'Charley',
        '小提琴' => 'violin',
        '好好刷牙' => 'brushing teeth',
        '收拾玩具' => 'cleaning up toys',
        '第一次提醒就照做' => 'following instructions',
        '主动练琴' => 'practicing violin'
    ];
    
    // 2. 将中文变量转换为英文/拼音
    $spokenName = $dictionary[$childName] ?? $childName;
    $spokenBehavior = $dictionary[$behavior['label']] ?? $behavior['label'];
    
    // 3. 组装全英文的播报句子
    $speechText = "Great job, {$spokenName}! You earned {$awarded} points for {$spokenBehavior}.";
    
    // 附加奖励的英文播报
    if ($excellent) {
        $speechText .= " Extra bonus point for doing excellent!";
    }
    if ($completionType === 'self_initiated' || $completionType === 'self_initiated_excellent') {
        $speechText .= " Double points for being self-initiated!";
    }

    // 4. 发送请求
    $url = "https://api-v3.voicemonkey.io/announce";
    
    $payload = [
        'token'  => VM_TOKEN,
        'device' => VM_DEVICE,
        'speech' => $speechText
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen(json_encode($payload))
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2); 
    curl_exec($ch);
    curl_close($ch);
}
// ==========================================

$_SESSION['last_result'] = $result;
$_SESSION['last_behavior'] = $behavior['label'];

header('Location: parent-result.php');
exit;