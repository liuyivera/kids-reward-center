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
            $notice = '已生成新 PIN：' . $code;
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
        $redirectTo = trim((string)($_POST['redirect_to'] ?? 'child-settings.php'));
        $safeRedirect = in_array($redirectTo, ['index.php', 'parent-dashboard.php', 'parent-record.php', 'parent-rewards.php', 'child-settings.php', 'point-reward-settings.php', 'family-settings.php', 'shop.php'], true)
            ? $redirectTo
            : 'child-settings.php';
        header('Location: ' . $safeRedirect);
        exit;
    }
}

$familyStmt = db()->prepare('SELECT name FROM families WHERE id = ?');
$familyStmt->execute([$familyId]);
$family = $familyStmt->fetch();
$familyName = $family['name'] ?? 'Default Family';

$childrenStmt = db()->prepare('SELECT * FROM children WHERE family_id = ? ORDER BY id');
$childrenStmt->execute([$familyId]);
$children = $childrenStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>孩子设置</title>
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
        <a class="nav-link" href="point-reward-settings.php">积分规则</a>
        <a class="nav-link active" href="child-settings.php">孩子设置</a>
        <a class="nav-link" href="family-settings.php">家庭设置</a>
        <a class="nav-link nav-link-logout" href="parent-logout.php">退出</a>
      </nav>
    </header>

    <main class="parent">
      <div class="page-header">
        <span class="eyebrow">家长端</span>
        <h1>孩子设置</h1>
      </div>

      <?php if ($notice !== ''): ?>
        <p class="success"><?= htmlspecialchars($notice) ?></p>
      <?php endif; ?>

      <h2 style="margin:0 0 12px;">孩子列表</h2>
      <?php if (! $children): ?>
        <p class="muted">目前还没有孩子记录。</p>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:12px;">
          <?php foreach ($children as $child):
            $accessCode = (string)($child['access_code'] ?? '');
            $accessUrl = $accessCode !== '' ? childAccessUrl((int)$child['id'], $accessCode) : null;
          ?>
            <div class="child-card" style="width:100%;padding:16px;border:1px solid #e2e8f0;border-radius:14px;background:#fff;box-shadow:none; margin: 0;">
              <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
                <div>
                  <strong><?= htmlspecialchars($child['name']) ?></strong>
                  <div class="muted">孩子 ID: <?= (int)$child['id'] ?> · 主题: <?= htmlspecialchars($child['theme'] ?? 'minecraft') ?></div>
                </div>
                <div class="badge" style="background:#f1f5f9;padding:4px 8px;border-radius:999px;color:#0f172a;"><?= (int)$child['normal_balance'] ?> 分</div>
              </div>

              <form method="post" action="child-settings.php" style="margin-top:12px;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_child">
                <input type="hidden" name="child_id" value="<?= (int)$child['id'] ?>">
                <label for="child_name_<?= (int)$child['id'] ?>">姓名</label>
                <input id="child_name_<?= (int)$child['id'] ?>" type="text" name="child_name" value="<?= htmlspecialchars($child['name']) ?>" required>

                <label for="theme_<?= (int)$child['id'] ?>">主题</label>
                <select id="theme_<?= (int)$child['id'] ?>" name="theme">
                  <option value="minecraft" <?= ($child['theme'] ?? 'minecraft') === 'minecraft' ? 'selected' : '' ?>>Minecraft</option>
                  <option value="music" <?= ($child['theme'] ?? 'minecraft') === 'music' ? 'selected' : '' ?>>Music</option>
                </select>

                <button type="submit" class="button secondary">保存修改</button>
              </form>

              <?php if ($accessUrl !== null): ?>
                <div style="margin-top:14px;padding:14px;background:#f8fafc;border-radius:12px;">
                  <div class="muted" style="font-size:12px;">孩子 PIN（6 位数字）</div>
                  <div style="font-size:34px;font-weight:800;letter-spacing:10px;font-family:monospace;color:#0f172a;line-height:1.2;margin:6px 0;">
                    <?= htmlspecialchars($accessCode) ?>
                  </div>
                  <p class="muted" style="margin:6px 0 0;font-size:13px;">
                    孩子可在 <strong>shop-login.php</strong> 输入这个 PIN 进入商店，也可以直接点家长发的完整链接。
                  </p>
                  <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <input type="text" readonly value="<?= htmlspecialchars($accessUrl) ?>" style="flex:1;min-width:200px;">
                    <form method="post" action="child-settings.php" style="margin:0;">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="generate_child_access_code">
                      <input type="hidden" name="child_id" value="<?= (int)$child['id'] ?>">
                      <button type="submit" class="button secondary" onclick="return confirm('确认重置 PIN？旧 PIN 和旧链接将失效。');">重置 PIN</button>
                    </form>
                  </div>
                </div>
              <?php else: ?>
                <form method="post" action="child-settings.php" style="margin-top:12px;">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="generate_child_access_code">
                  <input type="hidden" name="child_id" value="<?= (int)$child['id'] ?>">
                  <button type="submit" class="button secondary">生成 PIN</button>
                </form>
              <?php endif; ?>

              <form method="post" action="child-settings.php" style="margin-top:12px;" onsubmit="return confirm('确认删除这个孩子吗？若已有积分记录或待确认奖励，将无法删除。');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_child">
                <input type="hidden" name="child_id" value="<?= (int)$child['id'] ?>">
                <button type="submit" class="button secondary reject">删除孩子</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <section class="summary-card" style="margin-top:24px;">
        <h2>新增孩子</h2>
        <form method="post" action="child-settings.php" class="record-form">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="add_child">

          <label for="child_name">孩子姓名</label>
          <input type="text" id="child_name" name="child_name" placeholder="例如：小明" required>

          <label for="child_theme">主题</label>
          <select id="child_theme" name="theme">
            <option value="minecraft">Minecraft</option>
            <option value="music">Music</option>
          </select>

          <button type="submit" class="button">添加孩子</button>
        </form>
      </section>
    </main>
  </div>
</body>
</html>