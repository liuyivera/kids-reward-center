<?php
// 防止浏览器直接访问，只允许命令行运行
if (php_sapi_name() !== 'cli' && !isset($_GET['cron_secret'])) {
    die('Access Denied');
}

require_once __DIR__ . '/includes/db.php';

// 设定时区为美西时间（或你所在的时区，防止服务器时区差）
date_default_timezone_set('America/Los_Angeles');

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$twoDaysAgo = date('Y-m-d', strtotime('-2 days'));
$dayOfWeek = date('N'); // 1 (周一) 到 7 (周日)

$pdo = db();

// 获取所有开启了“小提琴打卡”的孩子（这里为了方便，我们暂时硬编码找名字里带“姐姐”的，或者你可以加一个字段）
// 建议你在 children 表加个字段 `has_violin_penalty`，这里我假设姐姐的 ID 是 2，或者名字包含“姐姐”
$stmt = $pdo->prepare("SELECT id, name, normal_balance FROM children WHERE name LIKE '%姐姐%' OR id = 2");
$stmt->execute();
$child = $stmt->fetch();

if (!$child) {
    exit("No child found for violin penalty.\n");
}

$childId = $child['id'];

// 1. 检查今天是否已经有一条“小提琴”的积分记录了
$checkToday = $pdo->prepare("SELECT COUNT(*) FROM point_events WHERE child_id = ? AND event_date = ? AND behavior_label LIKE '%小提琴%' AND awarded_points > 0");
$checkToday->execute([$childId, $today]);
if ($checkToday->fetchColumn() > 0) {
    exit("Child practiced violin today. No penalty.\n");
}

// 2. 检查今天是否已经被扣过“未练琴”的分数了（防止脚本重复执行）
$checkPenalty = $pdo->prepare("SELECT COUNT(*) FROM point_events WHERE child_id = ? AND event_date = ? AND completion_type = 'penalty_violin'");
$checkPenalty->execute([$childId, $today]);
if ($checkPenalty->fetchColumn() > 0) {
    exit("Penalty already applied today.\n");
}

// 3. 计算本周已扣次数（假设周一到周日为一周）
// 我们查出本周一的具体日期
$monday = date('Y-m-d', strtotime('monday this week'));
$checkWeekPenalties = $pdo->prepare("SELECT COUNT(*) FROM point_events WHERE child_id = ? AND event_date >= ? AND completion_type = 'penalty_violin'");
$checkWeekPenalties->execute([$childId, $monday]);
$weekPenaltiesCount = (int)$checkWeekPenalties->fetchColumn();

if ($weekPenaltiesCount >= 4) {
    exit("Weekly penalty limit reached (4). No more penalty this week.\n");
}

// 4. 判断是否连续 3 天（今天没练，昨天没练，前天也没练）
$checkYesterday = $pdo->prepare("SELECT COUNT(*) FROM point_events WHERE child_id = ? AND event_date = ? AND behavior_label LIKE '%小提琴%' AND awarded_points > 0");
$checkYesterday->execute([$childId, $yesterday]);
$practicedYesterday = $checkYesterday->fetchColumn() > 0;

$checkTwoDaysAgo = $pdo->prepare("SELECT COUNT(*) FROM point_events WHERE child_id = ? AND event_date = ? AND behavior_label LIKE '%小提琴%' AND awarded_points > 0");
$checkTwoDaysAgo->execute([$childId, $twoDaysAgo]);
$practicedTwoDaysAgo = $checkTwoDaysAgo->fetchColumn() > 0;

$penaltyPoints = 1;
$reason = "未完成每日小提琴任务";

if (!$practicedYesterday && !$practicedTwoDaysAgo) {
    $penaltyPoints = 2; // 连续 3 天没练，扣 2 分（包含原本的1分 + 额外1分）
    $reason = "连续3天未完成每日小提琴任务";
}

// 5. 执行扣分 (记录在 point_events 中)
// 注意：为了不把数据库的积分变成负数，如果余额不足，最多扣到 0
$deductAmount = min($penaltyPoints, $child['normal_balance']);
if ($deductAmount <= 0) {
    exit("Not enough balance to deduct, or balance is 0.\n");
}

$pdo->beginTransaction();
try {
    // 插入一条惩罚流水
    $insertEvent = $pdo->prepare(
        "INSERT INTO point_events (child_id, behavior_id, behavior_label, base_points, awarded_points, overflow_points, completion_type, event_date, note)
         VALUES (?, NULL, ?, ?, ?, 0, 'penalty_violin', ?, ?)"
    );
    // 注意：awarded_points 存为负数
    $insertEvent->execute([
        $childId, 
        '每日小提琴', 
        0, 
        -$deductAmount, 
        $today, 
        $reason . ($penaltyPoints > 1 ? ' (含连续3天额外扣分)' : '')
    ]);

    // 更新孩子余额
    $updateChild = $pdo->prepare("UPDATE children SET normal_balance = normal_balance - ? WHERE id = ?");
    $updateChild->execute([$deductAmount, $childId]);

    $pdo->commit();
    echo "Penalty applied: -{$deductAmount} points for child {$child['name']}. Reason: {$reason}\n";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "Error applying penalty: " . $e->getMessage() . "\n";
}