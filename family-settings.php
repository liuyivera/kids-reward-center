<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$familyId = currentParentFamilyId();
if ($familyId === null) {
    header('Location: parent-login.php');
    exit;
}

$user = currentParent();
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // 1. 修改家庭名称
    if ($action === 'update_family_name') {
        $familyName = trim((string)($_POST['family_name'] ?? ''));
        if ($familyName === '') {
            $error = '家庭名称不能为空。';
        } else {
            $stmt = db()->prepare('UPDATE families SET name = ? WHERE id = ?');
            $stmt->execute([$familyName, $familyId]);
            $notice = '已更新家庭名称。';
        }
    }

    // 2. 添加新家长
    if ($action === 'add_parent') {
        $newUsername = trim((string)($_POST['new_username'] ?? ''));
        $newPassword = (string)($_POST['new_password'] ?? '');

        if ($newUsername === '' || strlen($newPassword) < 6) {
            $error = '用户名不能为空，密码至少需要 6 位。';
        } else {
            $check = db()->prepare('SELECT 1 FROM parent_users WHERE username = ?');
            $check->execute([$newUsername]);
            if ($check->fetch()) {
                $error = '该用户名已被注册。';
            } else {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $insert = db()->prepare(
                    'INSERT INTO parent_users (family_id, username, password_hash, is_creator)
                     VALUES (?, ?, ?, 0)'
                );
                $insert->execute([$familyId, $newUsername, $hash]);
                $notice = '已添加新家长：' . $newUsername;
            }
        }
    }

    // 3. 移除家长
    if ($action === 'remove_parent') {
        $targetId = (int)($_POST['target_parent_id'] ?? 0);
        
        if ($targetId === (int)$user['id']) {
            $error = '不能移除当前登录的账号。';
        } elseif ($targetId <= 0) {
            $error = '无效的账号。';
        } else {
            $check = db()->prepare('SELECT 1 FROM parent_users WHERE id = ? AND family_id = ?');
            $check->execute([$targetId, $familyId]);
            if (! $check->fetch()) {
                $error = '无权移除此账号。';
            } else {
                $delete = db()->prepare('DELETE FROM parent_users WHERE id = ?');
                $delete->execute([$targetId]);
                $notice = '已移除该家长账号。';
            }
        }
    }

    // 4. 修改当前账号密码
    if ($action === 'update_my_password') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');

        if (! password_verify($currentPassword, $user['password_hash'])) {
            $error = '当前密码不正确。';
        } elseif (strlen($newPassword) < 6) {
            $error = '新密码至少需要 6 位。';
        } else {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $update = db()->prepare('UPDATE parent_users SET password_hash = ? WHERE id = ?');
            $update->execute([$hash, $user['id']]);
            $notice = '密码已修改，下次登录请使用新密码。';
        }
    }
}

// 获取当前家庭信息
$familyStmt = db()->prepare('SELECT name, created_at FROM families WHERE id = ?');
$familyStmt->execute([$familyId]);
$family = $familyStmt->fetch();
$familyName = $family['name'] ?? 'Default Family';

// 获取家庭中的家长列表
$membersStmt = db()->prepare('SELECT id, username, is_creator, created_at FROM parent_users WHERE family_id = ? ORDER BY id');
$membersStmt->execute([$familyId]);
$members = $membersStmt->fetchAll();

// 孩子数量
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
  <style>
    .fs-grid {
      display: grid;
      gap: 20px;
      grid-template-columns: 1fr;
    }
    @media (min-width: 768px) {
      .fs-grid {
        grid-template-columns: 1fr 1fr;
      }
      .fs-full {
        grid-column: 1 / -1;
      }
    }

    .fs-card {
      background: #fff;
      border-radius: 16px;
      border: 1px solid #e5e7eb;
      padding: 20px;
      box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04);
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    .fs-card h2 {
      margin: 0 0 4px;
      font-size: 1.1rem;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .fs-card p.muted {
      margin: 0;
      font-size: 13px;
      color: #64748b;
      line-height: 1.5;
    }

    .fs-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 10px 0;
      border-bottom: 1px solid #f1f5f9;
    }
    
    .fs-row:last-child {
      border-bottom: none;
    }

    .fs-row-label {
      font-size: 14px;
      color: #334155;
    }

    .fs-row-value {
      font-weight: 600;
      color: #0f172a;
    }

    .fs-member {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 10px 12px;
      background: #f8fafc;
      border-radius: 8px;
      border: 1px solid #e2e8f0;
      margin-bottom: 8px;
    }

    .fs-member-info {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }

    .fs-member-info strong {
      font-size: 14px;
      color: #0f172a;
    }

    .fs-badge {
      font-size: 11px;
      background: #dbeafe;
      color: #1e40af;
      padding: 2px 6px;
      border-radius: 4px;
      font-weight: 600;
    }

    .fs-badge.creator {
      background: #fef3c7;
      color: #92400e;
    }

    .fs-form {
      display: grid;
      gap: 10px;
      background: #f8fafc;
      padding: 16px;
      border-radius: 12px;
      border: 1px dashed #cbd5e1;
    }

    .fs-form label {
      font-size: 13px;
      font-weight: 600;
      color: #475569;
    }

    .fs-form input {
      padding: 10px 12px;
      border-radius: 8px;
      border: 1px solid #cbd5e1;
      font-size: 14px;
    }

    .fs-form button {
      margin-top: 6px;
      padding: 10px;
      border-radius: 8px;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      border: none;
      background: #2563eb;
      color: #fff;
      font-family: inherit;
      transition: background 0.15s;
    }

    .fs-form button:hover {
      background: #1d4ed8;
    }

    .fs-action-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 10px 16px;
      border-radius: 8px;
      font-size: 14px;
      font-weight: 600;
      text-decoration: none;
      cursor: pointer;
      border: 1px solid #cbd5e1;
      background: #fff;
      color: #0f172a;
      transition: background 0.15s;
      width: 100%;
    }

    .fs-action-btn:hover {
      background: #f1f5f9;
    }

    .fs-danger-zone {
      border: 1px solid #fca5a5;
      background: #fef2f2;
    }

    .fs-danger-zone h2 {
      color: #991b1b;
    }
  </style>
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
        <h1>家庭设置</h1>
      </div>

      <?php if ($notice !== ''): ?>
        <p class="success" style="margin-bottom: 20px;"><?= htmlspecialchars($notice) ?></p>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <p class="error" style="margin-bottom: 20px; background: #fee2e2; padding: 12px; border-radius: 8px;"><?= htmlspecialchars($error) ?></p>
      <?php endif; ?>

      <div class="fs-grid">
        
        <!-- 家庭信息 -->
        <section class="fs-card fs-full">
          <h2>🏠 家庭基础信息</h2>
          <div class="fs-row">
            <span class="fs-row-label">家庭成员</span>
            <span class="fs-row-value"><?= $childCount ?> 位孩子</span>
          </div>
          <div class="fs-row">
            <span class="fs-row-label">加入时间</span>
            <span class="fs-row-value"><?= date('Y-m-d', strtotime($family['created_at'] ?? 'now')) ?></span>
          </div>
          
          <form method="post" action="family-settings.php" class="fs-form" style="margin-top: 10px;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_family_name">
            <label for="family_name">修改家庭名称</label>
            <input id="family_name" type="text" name="family_name" value="<?= htmlspecialchars($familyName) ?>" required>
            <button type="submit">保存名称</button>
          </form>
        </section>

        <!-- 家长账号管理 -->
        <section class="fs-card fs-full">
          <h2>👨‍👩‍👧‍👦 家长账号</h2>
          <p class="muted">你可以邀请另一位家长（例如配偶）加入这个家庭。他们登录后，看到的孩子数据和积分记录是完全同步的。如果其中一人忘记密码，也可以让另一人登录后帮他重置（暂未开放），或者联系系统管理员。</p>
          
          <div style="margin-top: 10px;">
            <?php foreach ($members as $m): ?>
              <div class="fs-member">
                <div class="fs-member-info">
                  <div>
                    <strong><?= htmlspecialchars($m['username']) ?></strong>
                    <?php if ((int)$m['id'] === (int)$user['id']): ?>
                      <span class="fs-badge">当前登录</span>
                    <?php endif; ?>
                    <?php if ((int)$m['is_creator'] === 1): ?>
                      <span class="fs-badge creator">Creator</span>
                    <?php endif; ?>
                  </div>
                  <span style="font-size:12px;color:#94a3b8;">加入于 <?= date('Y-m-d', strtotime($m['created_at'] ?? 'now')) ?></span>
                </div>
                
                <?php if ((int)$m['id'] !== (int)$user['id']): ?>
                  <form method="post" action="family-settings.php" style="margin:0;" onsubmit="return confirm('确认移除该家长账号吗？');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="remove_parent">
                    <input type="hidden" name="target_parent_id" value="<?= (int)$m['id'] ?>">
                    <button type="submit" style="background:transparent;border:1px solid #fca5a5;color:#dc2626;padding:4px 10px;border-radius:6px;font-size:12px;cursor:pointer;">移除</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>

          <details style="margin-top: 10px;">
            <summary style="cursor:pointer;font-size:13px;font-weight:600;color:#2563eb;outline:none;">+ 添加新家长</summary>
            <form method="post" action="family-settings.php" class="fs-form" style="margin-top: 12px;">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="add_parent">
              
              <label for="new_username">新家长用户名</label>
              <input id="new_username" type="text" name="new_username" required autocomplete="off">
              
              <label for="new_password">初始密码（至少6位）</label>
              <input id="new_password" type="password" name="new_password" required minlength="6">
              
              <button type="submit">添加家长</button>
            </form>
          </details>
        </section>

        <!-- 修改密码 -->
        <section class="fs-card">
          <h2>🔒 修改我的密码</h2>
          <p class="muted">当前登录：<strong><?= htmlspecialchars($user['username']) ?></strong></p>
          
          <form method="post" action="family-settings.php" class="fs-form">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_my_password">
            
            <label for="current_password">当前密码</label>
            <input id="current_password" type="password" name="current_password" required autocomplete="current-password">
            
            <label for="my_new_password">新密码（至少6位）</label>
            <input id="my_new_password" type="password" name="new_password" required minlength="6" autocomplete="new-password">
            
            <button type="submit">更新密码</button>
          </form>
        </section>

        <!-- 忘记密码提示 -->
        <section class="fs-card" style="background: #f8fafc; border-style: dashed;">
          <h2>❓ 如果忘记了密码</h2>
          <p class="muted">由于本系统未接入邮件发送服务，如果你完全忘记了密码，无法通过自助方式找回。</p>
          <p class="muted" style="margin-top: 8px;">请联系系统创建者（管理员），管理员可以在后台直接为你重置一个新密码。</p>
        </section>

        <!-- Creator 入口 -->
        <?php if (isAppCreator()): ?>
          <section class="fs-card fs-full" style="border-color: #fde68a; background: #fffbeb;">
            <h2>👑 应用创建者专属</h2>
            <p class="muted">你现在拥有全局系统管理权限。</p>
            <div style="margin-top: 10px;">
              <a href="creator-admin.php" class="fs-action-btn" style="background:#fff; border-color:#fcd34d; color:#92400e;">进入 Creator Admin 面板</a>
            </div>
          </section>
        <?php endif; ?>

        <!-- 危险区（退出） -->
        <section class="fs-card fs-danger-zone fs-full">
          <h2>⚠️ 危险区</h2>
          <p class="muted" style="color:#991b1b;">退出登录后，如果你忘记了密码，请联系管理员。请确保你知道当前密码。</p>
          <div style="margin-top: 10px;">
            <a href="parent-logout.php" class="fs-action-btn" style="background:#fff; border-color:#fca5a5; color:#dc2626;">退出登录</a>
          </div>
        </section>

      </div>
    </main>
  </div>
</body>
</html>