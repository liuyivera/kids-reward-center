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
            $safeRedirect = in_array($redirectTo, ['index.php', 'parent-dashboard.php', 'shop.php', 'family-settings.php', 'family-list.php', 'child-settings.php', 'point-reward-settings.php'], true)
                ? $redirectTo
                : 'parent-dashboard.php';
            header('Location: ' . $safeRedirect);
            exit;
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
}

$familyStmt = db()->prepare('SELECT name FROM families WHERE id = ?');
$familyStmt->execute([$familyId]);
$family = $familyStmt->fetch();
$familyName = $family['name'] ?? 'Default Family';

$childCountStmt = db()->prepare('SELECT COUNT(*) FROM children WHERE family_id = ?');
$childCountStmt->execute([$familyId]);
$childCount = (int)$childCountStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>家庭设置</title>
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
        <a class="nav-link" href="child-settings.php">孩子设置</a>
        <a class="nav-link active" href="family-settings.php">家庭设置</a>
        <a class="nav-link nav-link-logout" href="parent-logout.php">退出</a>
      </nav>
    </header>

    <main class="parent">
      <div class="page-header">
        <span class="eyebrow">家长端</span>
        <h1>家庭设置</h1>
      </div>

      <?php if ($notice !== ''): ?>
        <p class="success"><?= htmlspecialchars($notice) ?></p>
      <?php endif; ?>

      <section class="summary-card compact-card">
        <h2>家庭信息</h2>
        <p class="muted">家庭名称：<?= htmlspecialchars($familyName) ?></p>
        <p class="muted">成员数量：<?= $childCount ?> 位孩子</p>

        <form method="post" action="family-settings.php" class="record-form compact-form">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="update_family_name">

          <label for="family_name">更新家庭名称</label>
          <input id="family_name" type="text" name="family_name" value="<?= htmlspecialchars($familyName) ?>" required>

          <button type="submit" class="button secondary">保存</button>
        </form>
      </section>

      <div class="compact-actions" style="margin-top:12px;">
        <a class="button secondary" href="parent-dashboard.php">返回控制台</a>
        <a class="button secondary" href="child-settings.php">孩子设置</a>
      </div>
    </main>
  </div>
</body>
</html>
