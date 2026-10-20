<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$familyId = currentParentFamilyId();
if ($familyId === null) {
    header('Location: parent-login.php');
    exit;
}

$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'add_behavior') {
        $label = trim((string)($_POST['label'] ?? ''));
        $emoji = trim((string)($_POST['emoji'] ?? '⭐'));
        $basePoints = (int)($_POST['base_points'] ?? 0);

        if ($label === '' || $basePoints <= 0) {
            $notice = '行为名称和积分必须填写。';
        } else {
            $stmt = db()->prepare(
                'INSERT INTO behaviors (child_id, label, emoji, base_points, is_active, created_at)
                 VALUES (?, ?, ?, ?, 1, NOW())'
            );
            $stmt->execute([(int)($_POST['child_id'] ?? 0), $label, $emoji !== '' ? $emoji : '⭐', $basePoints,]);
            $notice = '已添加行为。';
        }
    }

    if ($action === 'toggle_behavior') {
        $behaviorId = (int)($_POST['behavior_id'] ?? 0);
        $isActive = (int)($_POST['is_active'] ?? 0);
        $check = db()->prepare('SELECT b.id FROM behaviors b JOIN children c ON c.id = b.child_id WHERE b.id = ? AND c.family_id = ?');
        $check->execute([$behaviorId, $familyId]);

        if (! $check->fetch()) {
            http_response_code(403);
            exit('无权修改此行为。');
        }

        $update = db()->prepare('UPDATE behaviors SET is_active = ? WHERE id = ?');
        $update->execute([$isActive ? 1 : 0, $behaviorId]);
        $notice = '已更新行为状态。';
    }

    if ($action === 'add_reward') {
        $childId = (int)($_POST['child_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $emoji = trim((string)($_POST['emoji'] ?? '🎁'));
        $cost = (int)($_POST['cost'] ?? 0);

        if ($childId <= 0 || $title === '' || $cost <= 0) {
            $notice = '孩子、名称和成本不能为空。';
        } else {
            $check = db()->prepare('SELECT 1 FROM children WHERE id = ? AND family_id = ?');
            $check->execute([$childId, $familyId]);
            if (! $check->fetch()) {
                http_response_code(403);
                exit('无权管理此孩子的奖励。');
            }

            $stmt = db()->prepare(
                'INSERT INTO rewards (child_id, title, emoji, cost, is_active, requires_parent_confirmation, created_at)
                 VALUES (?, ?, ?, ?, 1, 1, NOW())'
            );
            $stmt->execute([$childId, $title, $emoji !== '' ? $emoji : '🎁', $cost]);
            $notice = '已添加奖励。';
        }
    }

    if ($action === 'toggle_reward') {
        $rewardId = (int)($_POST['reward_id'] ?? 0);
        $isActive = (int)($_POST['is_active'] ?? 0);
        $check = db()->prepare('SELECT r.id FROM rewards r JOIN children c ON c.id = r.child_id WHERE r.id = ? AND c.family_id = ?');
        $check->execute([$rewardId, $familyId]);

        if (! $check->fetch()) {
            http_response_code(403);
            exit('无权修改此奖励。');
        }

        $update = db()->prepare('UPDATE rewards SET is_active = ? WHERE id = ?');
        $update->execute([$isActive ? 1 : 0, $rewardId]);
        $notice = '已更新奖励状态。';
    }

    if ($action === 'switch_dev_family') {
        if (! isAppCreator()) {
            http_response_code(403);
            exit('此操作仅限应用创建者。');
        }

        $targetFamilyId = (int)($_POST['dev_family_id'] ?? 0);
        $familyCheck = db()->prepare('SELECT id FROM families WHERE id = ?');
        $familyCheck->execute([$targetFamilyId]);

        if (! $familyCheck->fetch()) {
            http_response_code(403);
            exit('无效的家庭视角。');
        }

        $_SESSION['dev_family_id'] = $targetFamilyId;
        $redirectTo = trim((string)($_POST['redirect_to'] ?? 'point-reward-settings.php'));
        $safeRedirect = in_array($redirectTo, ['index.php', 'parent-dashboard.php', 'parent-record.php', 'parent-rewards.php', 'child-settings.php', 'point-reward-settings.php', 'family-settings.php', 'shop.php'], true)
            ? $redirectTo
            : 'point-reward-settings.php';
        header('Location: ' . $safeRedirect);
        exit;
    }
}

$childrenStmt = db()->prepare('SELECT * FROM children WHERE family_id = ? ORDER BY id');
$childrenStmt->execute([$familyId]);
$children = $childrenStmt->fetchAll();

$behaviorsByChild = [];
$rewardsByChild = [];

foreach ($children as $child) {
    $behaviorStmt = db()->prepare('SELECT * FROM behaviors WHERE child_id = ? ORDER BY id');
    $behaviorStmt->execute([$child['id']]);
    $behaviorsByChild[(int)$child['id']] = $behaviorStmt->fetchAll();

    $rewardStmt = db()->prepare('SELECT * FROM rewards WHERE child_id = ? ORDER BY cost');
    $rewardStmt->execute([$child['id']]);
    $rewardsByChild[(int)$child['id']] = $rewardStmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>积分规则</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <div class="parent-shell">
    <header class="topbar">
      <div class="topbar-brand">家庭积分世界</div>
      <?= renderCreatorDevTool() ?>
      <nav class="topbar-nav" aria-label="家长导航">
        <a class="nav-link" href="parent-dashboard.php">控制台</a>
        <a class="nav-link" href="parent-record.php">记录积分</a>
        <a class="nav-link active" href="point-reward-settings.php">积分规则</a>
        <a class="nav-link" href="child-settings.php">孩子设置</a>
        <a class="nav-link" href="family-settings.php">家庭设置</a>
        <a class="nav-link nav-link-logout" href="parent-logout.php">退出</a>
      </nav>
    </header>

    <main class="parent">
      <div class="page-header">
        <span class="eyebrow">家长端</span>
        <h1>积分规则</h1>
      </div>

      <?php if ($notice !== ''): ?>
        <p class="success"><?= htmlspecialchars($notice) ?></p>
      <?php endif; ?>

      <?php if (! $children): ?>
        <section class="summary-card">
          <p class="muted">先添加孩子，再设置行为和奖励规则。</p>
        </section>
      <?php else: ?>
        <?php foreach ($children as $child): ?>
          <section class="summary-card">
            <h2><?= htmlspecialchars($child['name']) ?>（孩子 ID: <?= (int)$child['id'] ?>）</h2>

            <div style="margin-bottom:18px;">
              <h3 style="margin:0 0 10px;">新增行为</h3>
              <form method="post" action="point-reward-settings.php" class="record-form">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add_behavior">
                <input type="hidden" name="child_id" value="<?= (int)$child['id'] ?>">

                <label for="behavior_label_<?= (int)$child['id'] ?>">行为名称</label>
                <input id="behavior_label_<?= (int)$child['id'] ?>" type="text" name="label" placeholder="例如：整理书桌" required>

                <label for="behavior_emoji_<?= (int)$child['id'] ?>">Emoji</label>
                <input id="behavior_emoji_<?= (int)$child['id'] ?>" type="text" name="emoji" value="⭐" maxlength="4">

                <label for="behavior_points_<?= (int)$child['id'] ?>">基础积分</label>
                <input id="behavior_points_<?= (int)$child['id'] ?>" type="number" name="base_points" min="1" value="5" required>

                <button type="submit" class="button secondary">添加行为</button>
              </form>
            </div>

            <div style="margin-bottom:18px;">
              <h3 style="margin:0 0 10px;">行为列表</h3>
              <?php $behaviorItems = $behaviorsByChild[(int)$child['id']] ?? []; ?>
              <?php if (! $behaviorItems): ?>
                <p class="muted">还没有行为记录。</p>
              <?php else: ?>
                <ul>
                  <?php foreach ($behaviorItems as $item): ?>
                    <li style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:8px 0;border-bottom:1px solid #e2e8f0;">
                      <span><?= htmlspecialchars((string)($item['emoji'] ?? '⭐') . ' ' . ($item['label'] ?? '')) ?> · <?= (int)$item['base_points'] ?> 分</span>
                      <form method="post" action="point-reward-settings.php" style="margin:0;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="toggle_behavior">
                        <input type="hidden" name="behavior_id" value="<?= (int)$item['id'] ?>">
                        <input type="hidden" name="is_active" value="<?= (int)$item['is_active'] === 1 ? 0 : 1 ?>">
                        <button type="submit" class="button secondary"><?= (int)$item['is_active'] === 1 ? '停用' : '启用' ?></button>
                      </form>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>

            <div>
              <h3 style="margin:0 0 10px;">新增奖励</h3>
              <form method="post" action="point-reward-settings.php" class="record-form">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add_reward">
                <input type="hidden" name="child_id" value="<?= (int)$child['id'] ?>">

                <label for="reward_title_<?= (int)$child['id'] ?>">奖励名称</label>
                <input id="reward_title_<?= (int)$child['id'] ?>" type="text" name="title" placeholder="例如：额外故事时间" required>

                <label for="reward_emoji_<?= (int)$child['id'] ?>">Emoji</label>
                <input id="reward_emoji_<?= (int)$child['id'] ?>" type="text" name="emoji" value="🎁" maxlength="4">

                <label for="reward_cost_<?= (int)$child['id'] ?>">所需积分</label>
                <input id="reward_cost_<?= (int)$child['id'] ?>" type="number" name="cost" min="1" value="20" required>

                <button type="submit" class="button secondary">添加奖励</button>
              </form>
            </div>

            <div style="margin-top:18px;">
              <h3 style="margin:0 0 10px;">奖励列表</h3>
              <?php $rewardItems = $rewardsByChild[(int)$child['id']] ?? []; ?>
              <?php if (! $rewardItems): ?>
                <p class="muted">还没有奖励记录。</p>
              <?php else: ?>
                <ul>
                  <?php foreach ($rewardItems as $item): ?>
                    <li style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:8px 0;border-bottom:1px solid #e2e8f0;">
                      <span><?= htmlspecialchars((string)($item['emoji'] ?? '🎁') . ' ' . ($item['title'] ?? '')) ?> · <?= (int)$item['cost'] ?> 分</span>
                      <form method="post" action="point-reward-settings.php" style="margin:0;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="toggle_reward">
                        <input type="hidden" name="reward_id" value="<?= (int)$item['id'] ?>">
                        <input type="hidden" name="is_active" value="<?= (int)$item['is_active'] === 1 ? 0 : 1 ?>">
                        <button type="submit" class="button secondary"><?= (int)$item['is_active'] === 1 ? '停用' : '启用' ?></button>
                      </form>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>
          </section>
        <?php endforeach; ?>
      <?php endif; ?>

      <?= renderCreatorDevTool() ?>
    </main>
  </div>
</body>
</html>
