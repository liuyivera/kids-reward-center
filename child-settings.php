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

$emojiPool = ['👦', '👧', '🧒', '👶', '🐻', '🦊', '🐯', '🐼', '🐨', '🦁', '🐰', '🐷'];
$childEmoji = [];
foreach ($children as $i => $child) {
    $childEmoji[(int)$child['id']] = $emojiPool[$i % count($emojiPool)];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>孩子设置</title>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    /* ===== 孩子设置页 — 卡片体系 ===== */
    .child-list {
      display: flex;
      flex-direction: column;
      gap: 16px;
      margin-bottom: 24px;
    }

    .cc {
      background: #fff;
      border-radius: 16px;
      border: 1px solid #e5e7eb;
      box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04);
      overflow: hidden;
    }

    /* 头部 */
    .cc-head {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 16px 20px;
      border-bottom: 1px solid #f1f5f9;
    }

    .cc-avatar {
      width: 48px;
      height: 48px;
      border-radius: 50%;
      background: linear-gradient(135deg, #f8fafc, #e2e8f0);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 26px;
      line-height: 1;
      flex-shrink: 0;
      border: 1px solid #e5e7eb;
    }

    .cc-title {
      display: flex;
      flex-direction: column;
      gap: 3px;
      min-width: 0;
      flex: 1;
    }

    .cc-title strong {
      font-size: 17px;
      color: #0f172a;
      line-height: 1.2;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .cc-title span {
      font-size: 12px;
      color: #94a3b8;
      letter-spacing: 0.02em;
    }

    .cc-balance {
      flex-shrink: 0;
      font-size: 13px;
      font-weight: 700;
      color: #1e40af;
      background: #eff6ff;
      border: 1px solid #bfdbfe;
      padding: 5px 12px;
      border-radius: 999px;
      white-space: nowrap;
    }

    /* 分区 */
    .cc-section {
      padding: 16px 20px;
      border-bottom: 1px solid #f1f5f9;
    }

    .cc-section-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 12px;
    }

    .cc-section-title {
      font-size: 12px;
      font-weight: 700;
      color: #475569;
      text-transform: uppercase;
      letter-spacing: 0.06em;
    }

    /* PIN 行 */
    .cc-pin-row {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }

    .cc-pin {
      display: inline-block;
      font-family: 'SF Mono', 'Menlo', 'Consolas', monospace;
      font-size: 30px;
      font-weight: 800;
      color: #0f172a;
      letter-spacing: 8px;
      padding: 10px 18px 10px 24px;
      background: #f8fafc;
      border: 2px dashed #cbd5e1;
      border-radius: 10px;
      line-height: 1.1;
      user-select: all;
      -webkit-user-select: all;
    }

    .cc-link-row {
      display: flex;
      gap: 8px;
      margin-top: 14px;
      flex-wrap: wrap;
    }

    .cc-link-row input {
      flex: 1;
      min-width: 200px;
      padding: 10px 12px;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      font-size: 13px;
      color: #475569;
      background: #fff;
      font-family: 'SF Mono', 'Menlo', 'Consolas', monospace;
    }

    .cc-link-row button,
    .cc-copy-btn {
      padding: 10px 16px;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      background: #fff;
      color: #0f172a;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      white-space: nowrap;
      transition: background 0.15s;
      font-family: inherit;
    }

    .cc-link-row button:hover,
    .cc-copy-btn:hover {
      background: #f1f5f9;
    }

    .cc-link-row button:disabled,
    .cc-copy-btn:disabled {
      color: #16a34a;
      border-color: #86efac;
      background: #f0fdf4;
      cursor: default;
    }

    .cc-hint {
      margin: 10px 0 0;
      font-size: 12px;
      color: #94a3b8;
      line-height: 1.5;
    }

    .cc-actions-row {
      display: flex;
      gap: 8px;
      margin-top: 14px;
      flex-wrap: wrap;
    }

    /* 无 PIN 空状态 */
    .cc-pin-empty {
      padding: 16px;
      background: #f8fafc;
      border: 1px dashed #cbd5e1;
      border-radius: 10px;
      text-align: center;
      color: #64748b;
      font-size: 13px;
    }

    .cc-pin-empty p {
      margin: 0 0 12px;
      line-height: 1.5;
    }

    /* 折叠编辑 */
    .cc details {
      margin: 0;
    }

    .cc details > summary {
      padding: 14px 20px;
      cursor: pointer;
      font-size: 12px;
      font-weight: 700;
      color: #475569;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      list-style: none;
      display: flex;
      align-items: center;
      justify-content: space-between;
      user-select: none;
      border-bottom: 1px solid #f1f5f9;
    }

    .cc details > summary::-webkit-details-marker {
      display: none;
    }

    .cc details > summary::after {
      content: '▾';
      font-size: 12px;
      color: #94a3b8;
      transition: transform 0.2s;
    }

    .cc details[open] > summary::after {
      transform: rotate(180deg);
    }

    .cc details > summary:hover {
      background: #f8fafc;
    }

    .cc-details-body {
      padding: 16px 20px;
      border-bottom: 1px solid #f1f5f9;
    }

    .cc-details-body form {
      display: grid;
      gap: 8px;
    }

    .cc-details-body label {
      font-size: 13px;
      color: #475569;
      margin-top: 4px;
    }

    .cc-details-body input,
    .cc-details-body select {
      width: 100%;
    }

    /* 删除按钮区（在折叠区内部） */
    .cc-danger-block {
      margin-top: 24px;
      padding-top: 16px;
      border-top: 1px dashed #fca5a5;
    }

    .cc-delete-btn {
      width: 100%;
      background: #fef2f2;
      border: 1px solid #fecaca;
      color: #dc2626;
      padding: 10px 14px;
      border-radius: 8px;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      font-family: inherit;
      transition: background 0.15s;
    }

    .cc-delete-btn:hover {
      background: #fee2e2;
    }

    /* 空状态 */
    .dashboard-empty {
      background: #fff;
      border: 1px dashed #cbd5e1;
      border-radius: 16px;
      padding: 28px 20px;
      text-align: center;
      color: #64748b;
      margin-bottom: 24px;
    }

    .dashboard-empty p {
      margin: 0 0 6px;
    }

    /* 顶部添加按钮区 */
    .add-child-actions {
      display: flex;
      justify-content: flex-end;
      margin-bottom: 20px;
    }

    /* Modal 样式 */
    dialog#addChildModal {
      border: none;
      border-radius: 16px;
      padding: 0;
      box-shadow: 0 20px 50px rgba(15, 23, 42, 0.2);
      max-width: 400px;
      width: calc(100% - 32px);
      margin: auto;
    }
    dialog#addChildModal::backdrop {
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
    .modal-header h2 {
      margin: 0;
      font-size: 18px;
      color: #0f172a;
    }
    .modal-close {
      background: transparent;
      border: none;
      font-size: 24px;
      color: #94a3b8;
      cursor: pointer;
      padding: 0;
      line-height: 1;
    }
    .modal-close:hover {
      color: #0f172a;
    }
    .modal-body {
      padding: 24px;
      display: grid;
      gap: 8px;
    }
    .modal-body label {
      font-size: 13px;
      color: #475569;
      margin-top: 4px;
    }
    .modal-body input, .modal-body select {
      width: 100%;
    }
    .modal-footer {
      padding: 16px 24px;
      border-top: 1px solid #e2e8f0;
      display: flex;
      gap: 12px;
      justify-content: flex-end;
    }
    .modal-footer button {
      flex: 1;
      margin: 0;
    }

    /* 响应式 */
    @media (max-width: 480px) {
      .cc-pin {
        font-size: 24px;
        letter-spacing: 6px;
        padding: 8px 14px 8px 20px;
      }
      .cc-pin-row {
        gap: 8px;
      }
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
        <a class="nav-link active" href="child-settings.php">孩子设置</a>
        <a class="nav-link" href="family-settings.php">家庭设置</a>
        <a class="nav-link nav-link-logout" href="parent-logout.php">退出</a>
      </nav>
    </header>

    <main class="parent">
      <div class="page-header" style="display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap;">
        <div>
          <span class="eyebrow">家长端</span>
          <h1 style="margin: 4px 0 0;">孩子设置</h1>
        </div>
        <button class="button" onclick="document.getElementById('addChildModal').showModal()" style="margin: 0; padding: 10px 20px;">
          + 添加孩子
        </button>
      </div>

      <?php if ($notice !== ''): ?>
        <p class="success" style="margin-top: 16px;"><?= htmlspecialchars($notice) ?></p>
      <?php endif; ?>

      <?php if (! $children): ?>
        <div class="dashboard-empty" style="margin-top: 20px;">
          <p>还没有孩子</p>
          <p style="font-size:13px;color:#94a3b8;margin-top:4px;">点击右上角"添加孩子"开始</p>
        </div>
      <?php else: ?>
        <div class="child-list" style="margin-top: 20px;">
          <?php foreach ($children as $child):
            $accessCode = (string)($child['access_code'] ?? '');
            $accessUrl = $accessCode !== '' ? childAccessUrl((int)$child['id'], $accessCode) : null;
            $emoji = $childEmoji[(int)$child['id']] ?? '🧒';
          ?>
            <article class="cc">
              <div class="cc-head">
                <span class="cc-avatar" aria-hidden="true"><?= $emoji ?></span>
                <div class="cc-title">
                  <strong><?= htmlspecialchars($child['name']) ?></strong>
                  <span>ID <?= (int)$child['id'] ?> · <?= htmlspecialchars($child['theme'] ?? 'default') ?></span>
                </div>
                <span class="cc-balance"><?= (int)$child['normal_balance'] ?> 分</span>
              </div>

              <div class="cc-section">
                <div class="cc-section-head">
                  <span class="cc-section-title">孩子 PIN</span>
                </div>

                <?php if ($accessUrl !== null): ?>
                  <div class="cc-pin-row">
                    <span class="cc-pin"><?= htmlspecialchars($accessCode) ?></span>
                    <button
                      type="button"
                      class="cc-copy-btn"
                      onclick="copyPin('<?= htmlspecialchars($accessCode) ?>', this)"
                    >一键复制 PIN</button>
                  </div>

                  <div class="cc-link-row">
                    <input
                      type="text"
                      readonly
                      value="<?= htmlspecialchars($accessUrl) ?>"
                      id="link_<?= (int)$child['id'] ?>"
                      onclick="this.select()"
                    >
                    <button
                      type="button"
                      onclick="copyLink('link_<?= (int)$child['id'] ?>', this)"
                    >复制链接</button>
                  </div>

                  <p class="cc-hint">
                    孩子可以输入 PIN 进入商店，或直接打开上面的完整链接（免输 PIN）。
                  </p>

                  <div class="cc-actions-row">
                    <form method="post" action="child-settings.php" style="margin:0;">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="generate_child_access_code">
                      <input type="hidden" name="child_id" value="<?= (int)$child['id'] ?>">
                      <button type="submit" class="button secondary" style="margin:0; padding: 10px 16px; font-size: 13px;" onclick="return confirm('确认重置 PIN？旧 PIN 和旧链接将失效。');">重置 PIN</button>
                    </form>
                  </div>
                <?php else: ?>
                  <div class="cc-pin-empty">
                    <p>还没有 PIN。<br>生成后孩子才能进入自己的商店。</p>
                    <form method="post" action="child-settings.php" style="margin:0;">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="generate_child_access_code">
                      <input type="hidden" name="child_id" value="<?= (int)$child['id'] ?>">
                      <button type="submit" class="button" style="margin:0;">生成 PIN</button>
                    </form>
                  </div>
                <?php endif; ?>
              </div>

              <details>
                <summary>编辑基本信息</summary>
                <div class="cc-details-body">
                  <form method="post" action="child-settings.php">
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

                    <button type="submit" class="button secondary" style="margin-top:6px;">保存修改</button>
                  </form>

                  <div class="cc-danger-block">
                    <form method="post" action="child-settings.php" onsubmit="return confirm('确认删除这个孩子吗？若已有积分记录或待确认奖励，将无法删除。');">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="delete_child">
                      <input type="hidden" name="child_id" value="<?= (int)$child['id'] ?>">
                      <button type="submit" class="cc-delete-btn">删除这个孩子</button>
                    </form>
                  </div>
                </div>
              </details>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <!-- 新增孩子 Modal -->
      <dialog id="addChildModal">
        <div class="modal-header">
          <h2>新增孩子</h2>
          <button type="button" class="modal-close" onclick="document.getElementById('addChildModal').close()" aria-label="关闭">&times;</button>
        </div>
        <form method="post" action="child-settings.php">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="add_child">
          <div class="modal-body">
            <label for="new_child_name">孩子姓名</label>
            <input id="new_child_name" type="text" name="child_name" placeholder="例如：小明" required>

            <label for="new_child_theme">主题</label>
            <select id="new_child_theme" name="theme">
              <option value="minecraft">Minecraft</option>
              <option value="music">Music</option>
            </select>
          </div>
          <div class="modal-footer">
            <button type="button" class="button secondary" onclick="document.getElementById('addChildModal').close()">取消</button>
            <button type="submit" class="button">确认添加</button>
          </div>
        </form>
      </dialog>

    </main>
  </div>

  <script>
    function copyPin(pinText, btn) {
      const showDone = () => {
        const original = btn.textContent;
        btn.textContent = '已复制 ✓';
        btn.disabled = true;
        setTimeout(() => {
          btn.textContent = original;
          btn.disabled = false;
        }, 1500);
      };

      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(pinText).then(showDone).catch(() => fallbackCopy(pinText, showDone));
      } else {
        fallbackCopy(pinText, showDone);
      }
    }

    function copyLink(inputId, btn) {
      const input = document.getElementById(inputId);
      if (!input) return;

      const pathPrefix = window.location.pathname.replace(/[^/]*$/, '');
      const fullUrl = window.location.origin + pathPrefix + input.value;

      const showDone = () => {
        const original = btn.textContent;
        btn.textContent = '已复制 ✓';
        btn.disabled = true;
        setTimeout(() => {
          btn.textContent = original;
          btn.disabled = false;
        }, 1500);
      };

      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(fullUrl).then(showDone).catch(() => {
          input.select();
          try { document.execCommand('copy'); showDone(); } catch (e) {}
        });
      } else {
        input.select();
        try { document.execCommand('copy'); showDone(); } catch (e) {}
      }
    }

    function fallbackCopy(text, callback) {
      const textArea = document.createElement("textarea");
      textArea.value = text;
      textArea.style.position = "fixed";
      document.body.appendChild(textArea);
      textArea.focus();
      textArea.select();
      try {
        document.execCommand('copy');
        callback();
      } catch (err) {}
      document.body.removeChild(textArea);
    }
  </script>
</body>
</html>