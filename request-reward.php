<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$rewardId = (int)($_POST['reward_id'] ?? 0);
$familyId = currentParentFamilyId();
if ($familyId === null) {
    http_response_code(403);
    exit('无权访问此功能。');
}

$pdo = db();
$pdo->beginTransaction();

try {
    $rewardStmt = $pdo->prepare(
        'SELECT r.*
         FROM rewards r
         JOIN children c ON c.id = r.child_id
         WHERE r.id = ? AND r.is_active = 1 AND c.family_id = ?
         FOR UPDATE'
    );
    $rewardStmt->execute([$rewardId, $familyId]);
    $reward = $rewardStmt->fetch();

    if (! $reward) {
        throw new Exception('奖励不存在或不属于当前家庭。');
    }

    $childStmt = $pdo->prepare(
        'SELECT * FROM children WHERE id = ? AND family_id = ? FOR UPDATE'
    );
    $childStmt->execute([$reward['child_id'], $familyId]);
    $child = $childStmt->fetch();

    if (! $child) {
        throw new Exception('孩子不存在或不属于当前家庭。');
    }

    $pendingStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM redemptions
         WHERE child_id = ? AND status = "pending"'
    );
    $pendingStmt->execute([$child['id']]);

    if ($pendingStmt->fetchColumn() > 0) {
        $shop = $child['theme'] === 'minecraft'
            ? 'shop-minecraft.php?error=duplicate'
            : 'shop-music.php?error=duplicate';

        header('Location: ' . $shop);
        exit;
    }

    if ($child['normal_balance'] < $reward['cost']) {
        throw new Exception('积分不足。');
    }

    $insert = $pdo->prepare(
        'INSERT INTO redemptions (child_id, reward_id, cost, status)
         VALUES (?, ?, ?, "pending")'
    );
    $insert->execute([
        $child['id'],
        $reward['id'],
        $reward['cost']
    ]);

    $pdo->commit();

    header('Location: ' . ($child['theme'] === 'minecraft'
    ? 'shop-minecraft.php?requested=1'
    : 'shop-music.php?requested=1'));
    exit;
} catch (Exception $e) {
    $pdo->rollBack();
    exit(htmlspecialchars($e->getMessage()));
}
