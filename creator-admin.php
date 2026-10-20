<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

requireAppCreator();

$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'reset_all_creators') {
        $stmt = db()->prepare('UPDATE parent_users SET is_creator = 0');
        $stmt->execute();

        $ownerStmt = db()->prepare('UPDATE parent_users SET is_creator = 1 WHERE id = ?');
        $ownerStmt->execute([$_SESSION['parent_id']]);

        $notice = '已重设 creator 状态，并保留当前登录账户为唯一 creator。';
    }

    if ($action === 'preview_family_data') {
        $familyName = trim((string)($_POST['family_name'] ?? ''));
        $familyStmt = db()->prepare('SELECT id, name FROM families WHERE name LIKE ? ORDER BY id LIMIT 10');
        $familyStmt->execute(['%' . $familyName . '%']);
        $notice = '已生成家庭筛选预览。';
    }
}

$familyCountStmt = db()->prepare('SELECT COUNT(*) FROM families');
$familyCountStmt->execute();
$familyCount = (int)$familyCountStmt->fetchColumn();

$parentCountStmt = db()->prepare('SELECT COUNT(*) FROM parent_users');
$parentCountStmt->execute();
$parentCount = (int)$parentCountStmt->fetchColumn();

$childrenCountStmt = db()->prepare('SELECT COUNT(*) FROM children');
$childrenCountStmt->execute();
$childrenCount = (int)$childrenCountStmt->fetchColumn();

$creatorAccountsStmt = db()->prepare('SELECT id, username, family_id FROM parent_users WHERE is_creator = 1 ORDER BY id');
$creatorAccountsStmt->execute();
$creatorAccounts = $creatorAccountsStmt->fetchAll();

$familiesStmt = db()->prepare('SELECT f.id, f.name, COUNT(p.id) AS parent_count, COUNT(c.id) AS child_count FROM families f LEFT JOIN parent_users p ON p.family_id = f.id LEFT JOIN children c ON c.family_id = f.id GROUP BY f.id, f.name ORDER BY f.id LIMIT 20');
$familiesStmt->execute();
$families = $familiesStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Creator Admin</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <main class="parent">
    <a class="back" href="family-settings.php">← 返回家庭设置</a>
    <h1>Creator Admin</h1>

    <?php if ($notice !== ''): ?>
      <p class="success"><?= htmlspecialchars($notice) ?></p>
    <?php endif; ?>

    <section class="summary-card">
      <h2>系统总览</h2>
      <p>家庭总数：<?= $familyCount ?></p>
      <p>家长总数：<?= $parentCount ?></p>
      <p>儿童总数：<?= $childrenCount ?></p>
    </section>

    <section class="summary-card">
      <h2>Creator 账号</h2>
      <?php if (! $creatorAccounts): ?>
        <p class="muted">暂无 creator。</p>
      <?php else: ?>
        <ul>
          <?php foreach ($creatorAccounts as $creator): ?>
            <li><?= htmlspecialchars($creator['username']) ?> (family_id: <?= (int)$creator['family_id'] ?>)</li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="summary-card">
      <h2>最高权限操作</h2>
      <form method="post" action="creator-admin.php" class="record-form">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="reset_all_creators">
        <button type="submit" class="button secondary" onclick="return confirm('确认将所有 creator 置空，并保留当前登录账户为唯一 creator？');">重设所有 creator</button>
      </form>
    </section>

    <section class="summary-card">
      <h2>家庭概览</h2>
      <?php if (! $families): ?>
        <p class="muted">暂无家庭数据。</p>
      <?php else: ?>
        <ul>
          <?php foreach ($families as $family): ?>
            <li>
              <?= htmlspecialchars($family['name']) ?>
              <small>家长：<?= (int)$family['parent_count'] ?> | 儿童：<?= (int)$family['child_count'] ?></small>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
