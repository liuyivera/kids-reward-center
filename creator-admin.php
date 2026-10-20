<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

requireAppCreator();

$notice = '';
$error = '';

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

    // 新增：管理员重置家长密码
    if ($action === 'reset_parent_password') {
        $targetParentId = (int)($_POST['parent_id'] ?? 0);
        $newPassword = (string)($_POST['new_password'] ?? '');

        if ($targetParentId <= 0 || strlen($newPassword) < 6) {
            $error = '请选择有效的家长，且新密码至少需要 6 位。';
        } else {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $update = db()->prepare('UPDATE parent_users SET password_hash = ? WHERE id = ?');
            $update->execute([$hash, $targetParentId]);
            
            if ($update->rowCount() > 0) {
                $notice = '已成功重置该家长的密码。';
            } else {
                $error = '未找到该家长，或密码与原来相同。';
            }
        }
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

$familiesStmt = db()->prepare('SELECT f.id, f.name, COUNT(DISTINCT p.id) AS parent_count, COUNT(DISTINCT c.id) AS child_count FROM families f LEFT JOIN parent_users p ON p.family_id = f.id LEFT JOIN children c ON c.family_id = f.id GROUP BY f.id, f.name ORDER BY f.id LIMIT 20');
$familiesStmt->execute();
$families = $familiesStmt->fetchAll();

// 获取所有家长账号
$allParentsStmt = db()->prepare(
    'SELECT p.id, p.username, f.name AS family_name
     FROM parent_users p
     LEFT JOIN families f ON f.id = p.family_id
     ORDER BY p.id'
);
$allParentsStmt->execute();
$allParents = $allParentsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Creator Admin</title>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    .ca-card {
      background: #fff;
      border-radius: 16px;
      border: 1px solid #e5e7eb;
      padding: 20px;
      box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04);
      margin-bottom: 24px;
    }
    .ca-card h2 {
      margin-top: 0;
      font-size: 1.1rem;
      color: #0f172a;
    }
    .ca-list {
      display: flex;
      flex-direction: column;
      gap: 12px;
      margin-top: 16px;
    }
    .ca-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 12px;
      background: #f8fafc;
      border-radius: 8px;
      border: 1px solid #e2e8f0;
      gap: 16px;
      flex-wrap: wrap;
    }
    .ca-item-info {
      flex: 1;
      min-width: 200px;
    }
    .ca-item-info strong {
      display: block;
      font-size: 14px;
      color: #0f172a;
    }
    .ca-item-info span {
      font-size: 12px;
      color: #64748b;
    }
    .ca-reset-form {
      display: flex;
      gap: 8px;
    }
    .ca-reset-form input {
      width: 160px;
      padding: 8px 12px;
      border-radius: 6px;
      border: 1px solid #cbd5e1;
      font-size: 13px;
    }
    .ca-reset-form button {
      padding: 8px 16px;
      background: #2563eb;
      color: white;
      border: none;
      border-radius: 6px;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
    }
  </style>
</head>
<body>
  <main class="parent">
    <a class="back" href="family-settings.php">← 返回家庭设置</a>
    <h1>Creator Admin</h1>

    <?php if ($notice !== ''): ?>
      <p class="success"><?= htmlspecialchars($notice) ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
      <p class="error" style="background:#fee2e2; padding:12px; border-radius:8px;"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <section class="ca-card">
      <h2>系统总览</h2>
      <p>家庭总数：<?= $familyCount ?></p>
      <p>家长总数：<?= $parentCount ?></p>
      <p>儿童总数：<?= $childrenCount ?></p>
    </section>

    <section class="ca-card">
      <h2>🔑 重置任意家长密码</h2>
      <p class="muted">如果朋友忘记密码，你可以在这里帮他们直接设置一个新密码。</p>
      
      <div class="ca-list">
        <?php foreach ($allParents as $p): ?>
          <div class="ca-item">
            <div class="ca-item-info">
              <strong><?= htmlspecialchars($p['username']) ?></strong>
              <span>家庭：<?= htmlspecialchars($p['family_name'] ?? '未知') ?> (ID: <?= (int)$p['id'] ?>)</span>
            </div>
            <form method="post" action="creator-admin.php" class="ca-reset-form" onsubmit="return confirm('确认帮此账号重置密码吗？');">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="reset_parent_password">
              <input type="hidden" name="parent_id" value="<?= (int)$p['id'] ?>">
              <input type="password" name="new_password" placeholder="新密码(至少6位)" required minlength="6">
              <button type="submit">重置</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="ca-card">
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

    <section class="ca-card">
      <h2>最高权限操作</h2>
      <form method="post" action="creator-admin.php" class="record-form">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="reset_all_creators">
        <button type="submit" class="button secondary" onclick="return confirm('确认将所有 creator 置空，并保留当前登录账户为唯一 creator？');">重设所有 creator</button>
      </form>
    </section>

    <section class="ca-card">
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