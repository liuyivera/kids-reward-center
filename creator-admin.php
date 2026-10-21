<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

requireAppCreator();

$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = $_POST['action'] ?? '';

    // 1. 重置所有 Creator
    if ($action === 'reset_all_creators') {
        $stmt = db()->prepare('UPDATE parent_users SET is_creator = 0');
        $stmt->execute();

        $ownerStmt = db()->prepare('UPDATE parent_users SET is_creator = 1 WHERE id = ?');
        $ownerStmt->execute([$_SESSION['parent_id']]);

        $notice = '已重设 creator 状态，并保留当前登录账户为唯一 creator。';
    }

    // 2. 重置家长密码
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
                $error = '未找到该家长，或新密码与原来相同。';
            }
        }
    }

    // 3. 新增家庭
    if ($action === 'add_family') {
        $familyName = trim((string)($_POST['family_name'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($familyName === '' || $username === '' || strlen($password) < 6) {
            $error = '请完整填写：家庭名称、家长用户名、以及至少6位的密码。';
        } else {
            // 检查用户名是否存在
            $checkUser = db()->prepare('SELECT 1 FROM parent_users WHERE username = ?');
            $checkUser->execute([$username]);
            // 检查家庭名是否存在 (如果设了 UNIQUE)
            $checkFam = db()->prepare('SELECT 1 FROM families WHERE name = ?');
            $checkFam->execute([$familyName]);

            if ($checkUser->fetch()) {
                $error = '该用户名已被注册，请换一个。';
            } elseif ($checkFam->fetch()) {
                $error = '该家庭名称已存在，请换一个。';
            } else {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    // 建家庭
                    $stmt = $pdo->prepare('INSERT INTO families (name) VALUES (?)');
                    $stmt->execute([$familyName]);
                    $newFamilyId = $pdo->lastInsertId();

                    // 建家长
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt2 = $pdo->prepare('INSERT INTO parent_users (family_id, username, password_hash, is_creator) VALUES (?, ?, ?, 0)');
                    $stmt2->execute([$newFamilyId, $username, $hash]);

                    $pdo->commit();
                    $notice = "已成功开通新家庭【{$familyName}】，初始家长账号：{$username}。";
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = '开通失败：' . $e->getMessage();
                }
            }
        }
    }
}

// 统计数据
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

// 家庭及成员详情 (为了避免 N+1 查询，一次性拉出来然后分组)
$familiesStmt = db()->query('SELECT id, name FROM families ORDER BY id');
$families = $familiesStmt->fetchAll();

$allParentsStmt = db()->query('SELECT id, username, is_creator, family_id FROM parent_users ORDER BY id');
$allParents = $allParentsStmt->fetchAll();

$allChildrenStmt = db()->query('SELECT id, name, family_id FROM children ORDER BY id');
$allChildren = $allChildrenStmt->fetchAll();

// 数据分组
$parentsByFamily = [];
foreach ($allParents as $p) {
    $parentsByFamily[(int)$p['family_id']][] = $p;
}

$childrenByFamily = [];
foreach ($allChildren as $c) {
    $childrenByFamily[(int)$c['family_id']][] = $c;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Creator Admin</title>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    /* ===== Creator Admin 专属样式 ===== */
    body {
      background: #f4f5f7;
    }

    .ca-page {
      max-width: 900px;
      margin: 0 auto;
      padding: 28px 16px 80px;
    }

    .ca-header {
      display: flex;
      align-items: flex-end;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 24px;
      flex-wrap: wrap;
    }

    .ca-header-left {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .ca-header-left .back-link {
      font-size: 13px;
      color: #64748b;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }

    .ca-header-left .back-link:hover {
      color: #2563eb;
    }

    .ca-header h1 {
      margin: 0;
      font-size: 24px;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .ca-header h1 .badge {
      font-size: 11px;
      background: #fef3c7;
      color: #92400e;
      padding: 3px 8px;
      border-radius: 6px;
      font-weight: 700;
      letter-spacing: 0.02em;
    }

    /* 统计卡片 */
    .ca-stats {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 12px;
      margin-bottom: 24px;
    }

    @media (max-width: 600px) {
      .ca-stats { grid-template-columns: 1fr; }
    }

    .ca-stat {
      background: #fff;
      border-radius: 12px;
      border: 1px solid #e5e7eb;
      padding: 16px;
      display: flex;
      flex-direction: column;
      gap: 8px;
      box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
    }

    .ca-stat-label {
      font-size: 13px;
      color: #64748b;
      font-weight: 600;
    }

    .ca-stat-value {
      font-size: 28px;
      font-weight: 800;
      color: #0f172a;
      line-height: 1;
      font-variant-numeric: tabular-nums;
    }

    /* 主卡片 */
    .ca-card {
      background: #fff;
      border-radius: 16px;
      border: 1px solid #e5e7eb;
      padding: 24px;
      box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04);
      margin-bottom: 20px;
    }

    .ca-card-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 16px;
      flex-wrap: wrap;
    }

    .ca-card h2 {
      margin: 0;
      font-size: 1.1rem;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .ca-card p.desc {
      margin: 0 0 16px;
      font-size: 13px;
      color: #64748b;
      line-height: 1.5;
    }

    /* 列表 */
    .ca-list {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .ca-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 12px 16px;
      background: #f8fafc;
      border-radius: 10px;
      border: 1px solid #e2e8f0;
      gap: 16px;
      flex-wrap: wrap;
    }

    .ca-item-info {
      flex: 1;
      min-width: 180px;
      display: flex;
      flex-direction: column;
      gap: 3px;
    }

    .ca-item-info strong {
      font-size: 14px;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .ca-item-info span {
      font-size: 12px;
      color: #64748b;
    }

    .ca-reset-form {
      display: flex;
      gap: 8px;
      align-items: center;
      width: 100%;
      max-width: 300px;
    }

    .ca-reset-form input {
      flex: 1;
      min-width: 120px;
      padding: 8px 12px;
      border-radius: 8px;
      border: 1px solid #cbd5e1;
      font-size: 13px;
      font-family: inherit;
    }

    .ca-reset-form button {
      padding: 8px 16px;
      background: #2563eb;
      color: white;
      border: none;
      border-radius: 8px;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
    }

    /* 详情展示块 */
    .ca-family-block {
      background: #f8fafc;
      border-radius: 10px;
      border: 1px solid #e2e8f0;
      padding: 16px;
      margin-bottom: 12px;
    }
    .ca-family-title {
      font-weight: 700;
      color: #0f172a;
      font-size: 15px;
      margin-bottom: 12px;
      padding-bottom: 8px;
      border-bottom: 1px solid #e2e8f0;
    }
    .ca-family-row {
      display: flex;
      gap: 24px;
      font-size: 13px;
      flex-wrap: wrap;
    }
    .ca-family-col {
      flex: 1;
      min-width: 200px;
    }
    .ca-family-col strong {
      display: block;
      color: #64748b;
      font-size: 12px;
      margin-bottom: 6px;
      text-transform: uppercase;
    }
    .ca-family-col ul {
      margin: 0;
      padding: 0;
      list-style: none;
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
    }
    .ca-family-col li {
      background: #fff;
      border: 1px solid #cbd5e1;
      padding: 4px 8px;
      border-radius: 6px;
      color: #334155;
    }

    /* 最高权限区 */
    .ca-danger-card {
      border-color: #fca5a5;
      background: #fef2f2;
    }

    .ca-danger-card h2 { color: #991b1b; }
    .ca-danger-card .desc { color: #b91c1c; }

    .ca-danger-check {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 14px;
      color: #991b1b;
      font-weight: 600;
      margin-bottom: 16px;
      cursor: pointer;
    }

    .ca-danger-btn {
      background: #fff;
      border: 1px solid #fca5a5;
      color: #dc2626;
      padding: 10px 20px;
      border-radius: 8px;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      width: 100%;
      transition: background 0.15s;
    }

    .ca-danger-btn:hover:not(:disabled) { background: #fee2e2; }
    .ca-danger-btn:disabled {
      opacity: 0.5;
      cursor: not-allowed;
      background: #f1f5f9;
      border-color: #cbd5e1;
      color: #94a3b8;
    }

    /* Modal 样式 */
    dialog#addFamilyModal {
      border: none;
      border-radius: 16px;
      padding: 0;
      box-shadow: 0 20px 50px rgba(15, 23, 42, 0.2);
      max-width: 420px;
      width: calc(100% - 32px);
    }
    dialog#addFamilyModal::backdrop {
      background: rgba(15, 23, 42, 0.5);
      backdrop-filter: blur(2px);
    }
    .modal-header {
      padding: 20px 24px;
      border-bottom: 1px solid #e2e8f0;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .modal-header h2 { margin: 0; font-size: 18px; }
    .modal-close {
      background: transparent; border: none; font-size: 24px; color: #94a3b8; cursor: pointer;
    }
    .modal-body { padding: 24px; display: grid; gap: 10px; }
    .modal-body label { font-size: 13px; color: #475569; margin-top: 4px; }
    .modal-body input { padding: 10px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 14px; }
    .modal-footer {
      padding: 16px 24px;
      border-top: 1px solid #e2e8f0;
      display: flex;
      gap: 12px;
      justify-content: flex-end;
    }
    .modal-footer button { flex: 1; margin: 0; padding: 10px; border-radius: 8px; font-weight: 600; cursor: pointer; border: none; }
    .modal-footer .btn-cancel { background: #e2e8f0; color: #0f172a; }
    .modal-footer .btn-confirm { background: #2563eb; color: white; }
  </style>
</head>
<body>
  <div class="ca-page">
    
    <header class="ca-header">
      <div class="ca-header-left">
        <a href="family-settings.php" class="back-link">← 返回家庭设置</a>
        <h1>
          Creator Admin
          <span class="badge">系统级</span>
        </h1>
      </div>
      <button class="button" onclick="document.getElementById('addFamilyModal').showModal()" style="margin:0; padding:10px 20px;">
        + 开通新家庭
      </button>
    </header>

    <?php if ($notice !== ''): ?>
      <p class="success" style="margin-bottom: 20px;"><?= htmlspecialchars($notice) ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
      <p class="error" style="margin-bottom: 20px; background:#fee2e2; padding:12px; border-radius:8px;"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <!-- 统计区 -->
    <div class="ca-stats">
      <div class="ca-stat">
        <span class="ca-stat-label">家庭总数</span>
        <span class="ca-stat-value"><?= $familyCount ?></span>
      </div>
      <div class="ca-stat">
        <span class="ca-stat-label">家长总数</span>
        <span class="ca-stat-value"><?= $parentCount ?></span>
      </div>
      <div class="ca-stat">
        <span class="ca-stat-label">儿童总数</span>
        <span class="ca-stat-value"><?= $childrenCount ?></span>
      </div>
    </div>

    <!-- 家庭详情概览 -->
    <section class="ca-card">
      <div class="ca-card-header">
        <h2>🏠 系统家庭概览</h2>
      </div>
      <p class="desc">所有家庭及其下属的家长和儿童（只读，请勿在此修改数据）。</p>
      
      <?php if (! $families): ?>
        <p class="muted">暂无家庭数据。</p>
      <?php else: ?>
        <?php foreach ($families as $family):
            $fid = (int)$family['id'];
            $fParents = $parentsByFamily[$fid] ?? [];
            $fChildren = $childrenByFamily[$fid] ?? [];
        ?>
          <div class="ca-family-block">
            <div class="ca-family-title">
              <?= htmlspecialchars($family['name']) ?> 
              <span style="font-size:12px;color:#94a3b8;font-weight:normal;margin-left:8px;">ID: <?= $fid ?></span>
            </div>
            <div class="ca-family-row">
              <div class="ca-family-col">
                <strong>家长账号 (<?= count($fParents) ?>)</strong>
                <?php if ($fParents): ?>
                  <ul>
                    <?php foreach ($fParents as $p): ?>
                      <li><?= htmlspecialchars($p['username']) ?> <?= $p['is_creator'] ? '👑' : '' ?></li>
                    <?php endforeach; ?>
                  </ul>
                <?php else: ?>
                  <span style="color:#94a3b8;font-size:13px;">暂无</span>
                <?php endif; ?>
              </div>
              <div class="ca-family-col">
                <strong>儿童 (<?= count($fChildren) ?>)</strong>
                <?php if ($fChildren): ?>
                  <ul>
                    <?php foreach ($fChildren as $c): ?>
                      <li><?= htmlspecialchars($c['name']) ?></li>
                    <?php endforeach; ?>
                  </ul>
                <?php else: ?>
                  <span style="color:#94a3b8;font-size:13px;">暂无</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <!-- 重置任意家长密码 -->
    <section class="ca-card">
      <div class="ca-card-header">
        <h2>🔑 重置任意家长密码</h2>
      </div>
      <p class="desc">如果有朋友忘记密码，你可以在这里直接为他们生成一个新密码。请务必通过安全渠道（如微信私聊）告知他们。</p>
      
      <div class="ca-list">
        <?php foreach ($allParents as $p): ?>
          <div class="ca-item">
            <div class="ca-item-info">
              <strong>
                <?= htmlspecialchars($p['username']) ?>
                <?php if ((int)$p['id'] === (int)$_SESSION['parent_id']): ?>
                  <span style="font-size:10px;background:#dbeafe;color:#1e40af;padding:2px 6px;border-radius:4px;">当前登录</span>
                <?php endif; ?>
              </strong>
              <span>Family ID: <?= (int)$p['family_id'] ?> (用户 ID: <?= (int)$p['id'] ?>)</span>
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

    <!-- 最高权限操作 (危险区) -->
    <section class="ca-card ca-danger-card">
      <div class="ca-card-header">
        <h2>⚠️ 最高权限操作</h2>
      </div>
      <p class="desc">重置所有的 Creator 标志，并强制将当前登录的账号设为唯一的 Creator。这是一个非常危险的操作，请谨慎执行。</p>
      
      <form method="post" action="creator-admin.php">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="reset_all_creators">
        
        <label class="ca-danger-check">
          <input type="checkbox" id="confirm_reset_creators" onchange="document.getElementById('btn_reset_creator').disabled = !this.checked;">
          我确认了解此操作的风险，并将执行重置
        </label>
        
        <button type="submit" id="btn_reset_creator" class="ca-danger-btn" disabled onclick="return confirm('警告：确认剥夺所有其他人的 Creator 权限，并仅保留当前账号吗？');">
          重置所有 Creator 状态
        </button>
      </form>
    </section>

  </div>

  <!-- 新增家庭 Modal -->
  <dialog id="addFamilyModal">
    <div class="modal-header">
      <h2>开通新家庭</h2>
      <button type="button" class="modal-close" onclick="document.getElementById('addFamilyModal').close()" aria-label="关闭">&times;</button>
    </div>
    <form method="post" action="creator-admin.php">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_family">
      <div class="modal-body">
        <label for="new_family_name">家庭名称（如：李雷家）</label>
        <input id="new_family_name" type="text" name="family_name" required placeholder="例如：张家">

        <label for="new_parent_username">初始家长用户名（用于登录）</label>
        <input id="new_parent_username" type="text" name="username" required placeholder="例如：zhangba">

        <label for="new_parent_password">初始密码（至少6位）</label>
        <input id="new_parent_password" type="password" name="password" required minlength="6" placeholder="例如：123456">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="document.getElementById('addFamilyModal').close()">取消</button>
        <button type="submit" class="btn-confirm">开通并创建账号</button>
      </div>
    </form>
  </dialog>
</body>
</html>