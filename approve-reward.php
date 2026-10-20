<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: parent-dashboard.php');
    exit;
}

verifyCsrf();

$redemptionId = (int)($_POST['redemption_id'] ?? 0);
$action = $_POST['action'] ?? '';
$familyId = currentParentFamilyId();

// 接收返回路径，默认为控制台
$redirectTo = trim((string)($_POST['redirect_to'] ?? 'parent-dashboard.php'));
$safeRedirect = in_array($redirectTo, ['parent-dashboard.php', 'parent-rewards.php'], true)
    ? $redirectTo
    : 'parent-dashboard.php';

if ($familyId === null || ! in_array($action, ['approve', 'reject'], true)) {
    http_response_code(403);
    exit('无权执行此操作。');
}

$pdo = db();
$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare(
        'SELECT rd.*, r.cost, r.title, c.id AS child_id, c.normal_balance, c.theme, c.family_id
         FROM redemptions rd
         JOIN rewards r ON r.id = rd.reward_id
         JOIN children c ON c.id = rd.child_id
         WHERE rd.id = ? AND rd.status = "pending" AND c.family_id = ?
         FOR UPDATE'
    );
    $stmt->execute([$redemptionId, $familyId]);
    $redemption = $stmt->fetch();

    if (! $redemption) {
        throw new Exception('兑换请求不存在，或不属于当前家庭。');
    }

    if ($action === 'reject') {
        $update = $pdo->prepare(
            'UPDATE redemptions
             SET status = "rejected", approved_at = NOW()
             WHERE id = ?'
        );
        $update->execute([$redemptionId]);

        $pdo->commit();
        header('Location: ' . $safeRedirect . '?rejected=1');
        exit;
    }

    if ($redemption['normal_balance'] < $redemption['cost']) {
        throw new Exception('积分不足，无法确认兑换。');
    }

    $deduct = $pdo->prepare(
        'UPDATE children
         SET normal_balance = normal_balance - ?
         WHERE id = ? AND normal_balance >= ? AND family_id = ?'
    );
    $deduct->execute([
        $redemption['cost'],
        $redemption['child_id'],
        $redemption['cost'],
        $familyId
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

    header('Location: ' . $safeRedirect . '?approved=1');
    exit;
} catch (Exception $e) {
    $pdo->rollBack();
    exit(htmlspecialchars($e->getMessage()));
}