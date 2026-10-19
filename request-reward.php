<?php

require_once __DIR__ . '/includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$rewardId = (int)($_POST['reward_id'] ?? 0);

$pdo = db();
$pdo->beginTransaction();

try {
    $rewardStmt = $pdo->prepare(
        'SELECT * FROM rewards WHERE id = ? AND is_active = 1 FOR UPDATE'
    );
    $rewardStmt->execute([$rewardId]);
    $reward = $rewardStmt->fetch();

    if (! $reward) {
        throw new Exception('奖励不存在。');
    }

    $childStmt = $pdo->prepare(
        'SELECT * FROM children WHERE id = ? FOR UPDATE'
    );
    $childStmt->execute([$reward['child_id']]);
    $child = $childStmt->fetch();

    if (! $child) {
        throw new Exception('孩子不存在。');
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
