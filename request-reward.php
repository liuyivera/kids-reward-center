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
    renderErrorPage(
        '请求无效', 'Invalid Request',
        '缺少奖励信息。', 'Missing reward information.',
        400, 'index.php', '返回首页', 'Back to Home'
    );
}

// === 身份判定：家长 or 孩子 ===
$viewerFamilyId = null;
$viewerChildId = null;

if (! empty($_SESSION['parent_id'])) {
    $viewerFamilyId = currentParentFamilyId();
} else {
    $sessionChildId = (int)($_SESSION['child_access_child_id'] ?? 0);
    $sessionCode = trim((string)($_SESSION['child_access_code'] ?? ''));

    $candidateChildId = $postChildId > 0 ? $postChildId : $sessionChildId;

    $candidateCodes = [];
    if ($postAccessCode !== '') $candidateCodes[] = $postAccessCode;
    if ($sessionCode !== '' && $sessionCode !== $postAccessCode) $candidateCodes[] = $sessionCode;

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
    renderErrorPage(
        '无权操作', 'Unauthorized Action',
        '无权访问此功能。请从家长提供的链接重新进入。', 'You do not have permission to access this feature. Please re-enter using the link provided by your parent.',
        403, 'index.php', '返回首页', 'Back to Home'
    );
}

// === 事务 ===
$pdo = db();
$pdo->beginTransaction();

try {
    $rewardStmt = $pdo->prepare(
        'SELECT r.*, c.id AS actual_child_id, c.theme
         FROM rewards r
         JOIN children c ON c.id = r.child_id
         WHERE r.id = ? AND r.is_active = 1 AND c.family_id = ?
         FOR UPDATE'
    );
    $rewardStmt->execute([$rewardId, $viewerFamilyId]);
    $reward = $rewardStmt->fetch();

    if (! $reward) {
        throw new Exception('reward_not_found');
    }

    if ($viewerChildId !== null && (int)$reward['child_id'] !== $viewerChildId) {
        throw new Exception('not_your_reward');
    }

    $childStmt = $pdo->prepare(
        'SELECT * FROM children WHERE id = ? AND family_id = ? FOR UPDATE'
    );
    $childStmt->execute([$reward['child_id'], $viewerFamilyId]);
    $child = $childStmt->fetch();

    if (! $child) {
        throw new Exception('child_not_found');
    }

    $pendingStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM redemptions WHERE child_id = ? AND status = "pending"'
    );
    $pendingStmt->execute([$child['id']]);

    if ((int)$pendingStmt->fetchColumn() > 0) {
        $pdo->rollBack();
        header('Location: shop.php?child_id=' . (int)$child['id'] . '&' . (empty($_SESSION['parent_id']) ? 'access_code=' . urlencode($_SESSION['child_access_code']) . '&' : '') . 'error=duplicate');
        exit;
    }

    if ((int)$child['normal_balance'] < (int)$reward['cost']) {
        throw new Exception('insufficient_points');
    }

    $insert = $pdo->prepare(
        'INSERT INTO redemptions (child_id, reward_id, cost, status)
         VALUES (?, ?, ?, "pending")'
    );
    $insert->execute([$child['id'], $reward['id'], $reward['cost']]);

    $pdo->commit();

    $url = 'shop.php?child_id=' . (int)$child['id'];
    if (empty($_SESSION['parent_id'])) {
        $url .= '&access_code=' . urlencode($_SESSION['child_access_code']);
    }
    header('Location: ' . $url . '&requested=1');
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    // 捕获具体的错误类型，重定向回店铺页面显示
    $errorCode = $e->getMessage();
    $url = 'shop.php?child_id=' . (int)$child['id'];
    if (empty($_SESSION['parent_id'])) {
        $url .= '&access_code=' . urlencode($_SESSION['child_access_code']);
    }
    
    // 将错误码带回去
    header('Location: ' . $url . '&error=' . urlencode($errorCode));
    exit;
}