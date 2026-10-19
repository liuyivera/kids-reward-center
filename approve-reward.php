<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: parent-rewards.php');
    exit;
}

verifyCsrf();

$redemptionId = (int)($_POST['redemption_id'] ?? 0);
$action = $_POST['action'] ?? '';

if (! in_array($action, ['approve', 'reject'], true)) {
    exit('操作无效。');
}

$pdo = db();
$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare(
        'SELECT rd.*, r.cost, r.title, c.normal_balance, c.theme
         FROM redemptions rd
         JOIN rewards r ON r.id = rd.reward_id
         JOIN children c ON c.id = rd.child_id
         WHERE rd.id = ? AND rd.status = "pending"
         FOR UPDATE'
    );
    $stmt->execute([$redemptionId]);
    $redemption = $stmt->fetch();

    if (! $redemption) {
        throw new Exception('兑换请求不存在或已处理。');
    }

    if ($action === 'reject') {
        $update = $pdo->prepare(
            'UPDATE redemptions
             SET status = "rejected", approved_at = NOW()
             WHERE id = ?'
        );
        $update->execute([$redemptionId]);

        $pdo->commit();
        header('Location: parent-rewards.php');
        exit;
    }

    if ($redemption['normal_balance'] < $redemption['cost']) {
        throw new Exception('积分不足，无法确认兑换。');
    }

    $deduct = $pdo->prepare(
        'UPDATE children
         SET normal_balance = normal_balance - ?
         WHERE id = ? AND normal_balance >= ?'
    );
    $deduct->execute([
        $redemption['cost'],
        $redemption['child_id'],
        $redemption['cost']
    ]);

    if ($deduct->rowCount() !== 1) {
        throw new Exception('积分不足，无法确认兑换。');
    }

    $approve = $pdo->prepare(
        'UPDATE redemptions
         SET status = "approved", approved_at = NOW()
         WHERE id = ?'
    );
    $approve->execute([$redemptionId]);

    $pdo->commit();

    header('Location: parent-rewards.php');
    exit;
} catch (Exception $e) {
    $pdo->rollBack();
    exit(htmlspecialchars($e->getMessage()));
}
