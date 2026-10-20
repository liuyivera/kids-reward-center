<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$rewardId = (int)($_POST['reward_id'] ?? 0);
$postChildId = (int)($_POST['child_id'] ?? 0);
$postAccessCode = trim((string)($_POST['access_code'] ?? ''));

if ($rewardId <= 0) {
    http_response_code(400);
    exit('缺少奖励信息。');
}

// === 身份判定：家长 or 孩子 ===
$viewerFamilyId = null;
$viewerChildId = null;

if (! empty($_SESSION['parent_id'])) {
    // 家长：靠 session
    $viewerFamilyId = currentParentFamilyId();
} else {
    // 孩子：session 或 POST 里的 code，重新查库验证
    $sessionChildId = (int)($_SESSION['child_access_child_id'] ?? 0);
    $sessionCode = trim((string)($_SESSION['child_access_code'] ?? ''));

    $candidateChildId = $postChildId > 0 ? $postChildId : $sessionChildId;

    $candidateCodes = [];
    if ($postAccessCode !== '') {
        $candidateCodes[] = $postAccessCode;
    }
    if ($sessionCode !== '' && $sessionCode !== $postAccessCode) {
        $candidateCodes[] = $sessionCode;
    }

    if ($candidateChildId > 0) {
        foreach ($candidateCodes as $code) {
            $stmt = db()->prepare(
                'SELECT id, family_id FROM children
                 WHERE id = ? AND access_code = ?
                   AND (access_code_expires_at IS NULL OR access_code_expires_at > NOW())'
            );
            $stmt->execute([$candidateChildId, $code]);
            $childRow = $stmt->fetch();

            if ($childRow) {
                $viewerFamilyId = (int)$childRow['family_id'];
                $viewerChildId = (int)$childRow['id'];
                break;
            }
        }
    }
}

if ($viewerFamilyId === null) {
    http_response_code(403);
    exit('无权访问此功能。请从家长提供的链接重新进入。');
}

// === 事务 ===
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
    $rewardStmt->execute([$rewardId, $viewerFamilyId]);
    $reward = $rewardStmt->fetch();

    if (! $reward) {
        throw new Exception('奖励不存在或不属于当前家庭。');
    }

    // 孩子身份：只能兑自己的奖励
    if ($viewerChildId !== null && (int)$reward['child_id'] !== $viewerChildId) {
        throw new Exception('无权兑换其他孩子的奖励。');
    }

    $childStmt = $pdo->prepare(
        'SELECT * FROM children WHERE id = ? AND family_id = ? FOR UPDATE'
    );
    $childStmt->execute([$reward['child_id'], $viewerFamilyId]);
    $child = $childStmt->fetch();

    if (! $child) {
        throw new Exception('孩子不存在或不属于当前家庭。');
    }

    $pendingStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM redemptions WHERE child_id = ? AND status = "pending"'
    );
    $pendingStmt->execute([$child['id']]);

    if ((int)$pendingStmt->fetchColumn() > 0) {
        $pdo->rollBack();
        header('Location: ' . buildShopReturnUrl($child, 'error=duplicate'));
        exit;
    }

    if ((int)$child['normal_balance'] < (int)$reward['cost']) {
        throw new Exception('积分不足。');
    }

    $insert = $pdo->prepare(
        'INSERT INTO redemptions (child_id, reward_id, cost, status)
         VALUES (?, ?, ?, "pending")'
    );
    $insert->execute([$child['id'], $reward['id'], $reward['cost']]);

    $pdo->commit();

    header('Location: ' . buildShopReturnUrl($child, 'requested=1'));
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    exit(htmlspecialchars($e->getMessage()));
}

function buildShopReturnUrl(array $child, string $query): string
{
    $url = 'shop.php?child_id=' . (int)$child['id'];

    // 孩子身份 → 把 code 拼回 URL，让返回后仍能识别
    if (empty($_SESSION['parent_id'])) {
        $code = trim((string)($_SESSION['child_access_code'] ?? ''));
        if ($code !== '') {
            $url .= '&access_code=' . rawurlencode($code);
        }
    }

    return $url . '&' . $query;
}