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

    if ($action === 'switch_dev_family') {
        if (! isAppCreator()) {
            http_response_code(403);
            exit('此操作仅限应用创建者。');
        }

        $targetFamilyId = (int)($_POST['dev_family_id'] ?? 0);
        $familyCheck = db()->prepare('SELECT id FROM families WHERE id = ?');
        $familyCheck->execute([$targetFamilyId]);

        if (! $familyCheck->fetch()) {
            $notice = '无效的家庭视角。';
        } else {
            $_SESSION['dev_family_id'] = $targetFamilyId;
            $redirectTo = trim((string)($_POST['redirect_to'] ?? 'parent-dashboard.php'));
            $safeRedirect = in_array($redirectTo, ['index.php', 'parent-dashboard.php', 'shop.php', 'family-settings.php', 'family-list.php'], true)
                ? $redirectTo
                : 'parent-dashboard.php';
            header('Location: ' . $safeRedirect);
            exit;
        }
    }

    if ($action === 'generate_child_access_code') {
        $childId = (int)($_POST['child_id'] ?? 0);
        if ($childId <= 0) {
            $notice = '无效的孩子记录。';
        } else {
            $check = db()->prepare('SELECT 1 FROM children WHERE id = ? AND family_id = ?');
            $check->execute([$childId, $familyId]);
            if (! $check->fetch()) {
                http_response_code(403);
                exit('无权管理此孩子。');
            }

            $code = issueChildAccessCode($childId);
            $notice = '已生成新访问码：' . $code;
        }
    }

    if ($action === 'update_family_name') {
        $familyName = trim((string)($_POST['family_name'] ?? ''));
        if ($familyName === '') {
            $notice = '家庭名称不能为空。';
        } else {
            $stmt = db()->prepare('UPDATE families SET name = ? WHERE id = ?');
            $stmt->execute([$familyName, $familyId]);
            $notice = '已更新家庭名称。';
        }
    }

    if ($action === 'add_child') {
        $childName = trim((string)($_POST['child_name'] ?? ''));
        $theme = in_array($_POST['theme'] ?? '', ['minecraft', 'music'], true)
            ? $_POST['theme']
            : 'minecraft';

        if ($childName === '') {
            $notice = '孩子姓名不能为空。';
        } else {
            $stmt = db()->prepare(
                'INSERT INTO children (name, theme, family_id, normal_balance, daily_points_today, last_score_date, quarterly_pool, daily_cap, quarterly_goal, created_at)
                 VALUES (?, ?, ?, 0, 0, NULL, 0, 25, 150, NOW())'
            );
            $stmt->execute([$childName, $theme, $familyId]);

            $notice = '已添加孩子：' . $childName;
            header('Location: parent-dashboard.php?added=1');
            exit;
        }
    }

    if ($action === 'update_child') {
        $childId = (int)($_POST['child_id'] ?? 0);
        $childName = trim((string)($_POST['child_name'] ?? ''));
        $theme = in_array($_POST['theme'] ?? '', ['minecraft', 'music'], true)
            ? $_POST['theme']
            : 'minecraft';

        if ($childId <= 0 || $childName === '') {
            $notice = '孩子信息不完整。';
        } else {
            $check = db()->prepare('SELECT 1 FROM children WHERE id = ? AND family_id = ?');
            $check->execute([$childId, $familyId]);

            if (! $check->fetch()) {
                http_response_code(403);
                exit('无权修改此孩子。');
            }

            $stmt = db()->prepare('UPDATE children SET name = ?, theme = ? WHERE id = ? AND family_id = ?');
            $stmt->execute([$childName, $theme, $childId, $familyId]);

            $notice = '已更新孩子信息。';
        }
    }

    if ($action === 'delete_child') {
        $childId = (int)($_POST['child_id'] ?? 0);

        if ($childId <= 0) {
            $notice = '无效的孩子记录。';
        } else {
            $check = db()->prepare('SELECT 1 FROM children WHERE id = ? AND family_id = ?');
            $check->execute([$childId, $familyId]);
            if (! $check->fetch()) {
                http_response_code(403);
                exit('无权删除此孩子。');
            }

            $pending = db()->prepare('SELECT COUNT(*) FROM redemptions WHERE child_id = ? AND status = "pending"');
            $pending->execute([$childId]);
            if ((int)$pending->fetchColumn() > 0) {
                $notice = '请先处理此孩子的待确认奖励。';
            } else {
                $events = db()->prepare('SELECT COUNT(*) FROM point_events WHERE child_id = ?');
                $events->execute([$childId]);
                if ((int)$events->fetchColumn() > 0) {
                    $notice = '此孩子已有积分记录，不能直接删除。';
                } else {
                    $delete = db()->prepare('DELETE FROM children WHERE id = ? AND family_id = ?');
                    $delete->execute([$childId, $familyId]);
                    $notice = '已删除孩子记录。';
                }
            }
        }
    }
}

$familyStmt = db()->prepare('SELECT name FROM families WHERE id = ?');
$familyStmt->execute([$familyId]);
$family = $familyStmt->fetch();
$familyName = $family['name'] ?? 'Default Family';

$childrenStmt = db()->prepare('SELECT * FROM children WHERE family_id = ? ORDER BY id');
$childrenStmt->execute([$familyId]);
$children = $childrenStmt->fetchAll();

$pendingStmt = db()->prepare(
    'SELECT rd.id, rd.cost, r.title, r.emoji
     FROM redemptions rd
     JOIN rewards r ON r.id = rd.reward_id
     JOIN children c ON c.id = rd.child_id
     WHERE c.family_id = ? AND rd.child_id = ? AND rd.status = "pending"'
);

$eventsStmt = db()->prepare(
    "SELECT
        pe.behavior_label,
        COALESCE(b.emoji, '⭐') AS emoji,
        pe.awarded_points,
        pe.overflow_points,
        pe.completion_type,
        pe.event_date,
        pe.created_at
     FROM point_events pe
     LEFT JOIN behaviors b ON b.id = pe.behavior_id
     JOIN children c ON c.id = pe.child_id
     WHERE c.family_id = ? AND pe.child_id = ?
     ORDER BY pe.event_date DESC, pe.created_at DESC, pe.id DESC
     LIMIT 5"
);

$childEmoji = [1 => '👦', 2 => '👧'];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>家长控制台</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <div class="parent-shell">
    <header class="topbar">
      <div class="topbar-brand">家庭积分世界</div>
      <?= renderCreatorDevTool() ?>
      <nav class="topbar-nav" aria-label="家长导航">
        <a class="nav-link active" href="parent-dashboard.php">控制台</a>
        <a class="nav-link" href="parent-record.php">记录积分</a>
        <a class="nav-link" href="point-reward-settings.php">积分规则</a>
        <a class="nav-link" href="child-settings.php">孩子设置</a>
        <a class="nav-link" href="family-settings.php">家庭设置</a>
        <a class="nav-link nav-link-logout" href="parent-logout.php">退出</a>
      </nav>
    </header>

    <main class="parent">
      <div class="page-header">
        <span class="eyebrow">家长端</span>
        <h1>家长控制台</h1>
      </div>

      <?php $visibleChildren = array_slice($children, 0, 2); ?>
      <?php if (! $visibleChildren): ?>
        <p class="muted">目前还没有孩子记录。</p>
      <?php else: ?>
        <div class="dashboard-child-list">
          <?php foreach ($visibleChildren as $child):
            $dailyCap = max(1, (int)($child['daily_cap'] ?? 25));
            $dailyShown = min($dailyCap, max(0, (int)($child['daily_points_today'] ?? 0)));
            $dailyPercent = $dailyCap > 0 ? ($dailyShown / $dailyCap) * 100 : 0;
          ?>
            <div class="child-card dashboard-child-card">
              <div class="dashboard-child-head">
                <div class="dashboard-child-name-wrap">
                  <span class="child-emoji" style="font-size:20px;">
                    <?= $childEmoji[$child['id']] ?? '🧒' ?>
                  </span>
                  <div>
                    <strong><?= htmlspecialchars($child['name']) ?></strong>
                    <div class="muted" style="margin-top:2px;">孩子 ID: <?= (int)$child['id'] ?></div>
                  </div>
                </div>
                <span class="badge">可用积分 <?= (int)($child['normal_balance'] ?? 0) ?> 分</span>
              </div>

              <div class="dashboard-stat-row">
                <span>今日已得</span>
                <strong><?= $dailyShown ?> / <?= $dailyCap ?></strong>
              </div>
              <div class="progress dashboard-progress">
                <div class="bar" style="width: <?= (float)$dailyPercent ?>%"></div>
              </div>

              <div class="dashboard-stat-row dashboard-stat-row-secondary">
                <span>季度奖池</span>
                <strong><?= (int)($child['quarterly_pool'] ?? 0) ?> 分</strong>
              </div>

              <div class="dashboard-child-actions">
                <a class="button secondary" href="parent-record.php?child_id=<?= (int)$child['id'] ?>">记录积分</a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="dashboard-actions">
        <a class="button secondary" href="point-reward-settings.php">积分规则</a>
        <a class="button secondary" href="child-settings.php">孩子设置</a>
        <a class="button secondary" href="family-settings.php">家庭设置</a>
      </div>
    </main>
  </div>
</body>
</html>