<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$familyId = currentParentFamilyId();
if ($familyId === null) {
    header('Location: parent-login.php');
    exit;
}

$notice = '';

// 获取当前家庭的所有孩子
$childrenStmt = db()->prepare('SELECT * FROM children WHERE family_id = ? ORDER BY id');
$childrenStmt->execute([$familyId]);
$children = $childrenStmt->fetchAll();

// 获取当前选中的孩子（通过 URL 参数，默认第一个）
$selectedChildId = isset($_GET['child_id']) ? (int)$_GET['child_id'] : ($children[0]['id'] ?? 0);
$selectedChild = null;
foreach ($children as $c) {
    if ((int)$c['id'] === $selectedChildId) {
        $selectedChild = $c;
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    
    // 接收重定向回来的 child_id 保持状态
    $postChildId = (int)($_POST['child_id'] ?? $selectedChildId);
    $redirectUrl = 'point-reward-settings.php' . ($postChildId > 0 ? '?child_id=' . $postChildId : '');

    // --- 行为相关 ---
    if ($action === 'add_behavior') {
        $label = trim((string)($_POST['label'] ?? ''));
        $emoji = trim((string)($_POST['emoji'] ?? '⭐'));
        $basePoints = (int)($_POST['base_points'] ?? 0);

        $childCheck = db()->prepare('SELECT 1 FROM children WHERE id = ? AND family_id = ?');
        $childCheck->execute([$postChildId, $familyId]);

        if ($postChildId <= 0 || ! $childCheck->fetch()) {
            http_response_code(403);
            exit('无权管理此孩子的行为。');
        }

        if ($label !== '' && $basePoints > 0) {
            $stmt = db()->prepare('INSERT INTO behaviors (child_id, label, emoji, base_points, is_active) VALUES (?, ?, ?, ?, 1)');
            $stmt->execute([$postChildId, $label, $emoji !== '' ? $emoji : '⭐', $basePoints]);
            header('Location: ' . $redirectUrl);
            exit;
        }
    }

    if ($action === 'edit_behavior') {
        $behaviorId = (int)($_POST['behavior_id'] ?? 0);
        $label = trim((string)($_POST['label'] ?? ''));
        $emoji = trim((string)($_POST['emoji'] ?? '⭐'));
        $basePoints = (int)($_POST['base_points'] ?? 0);

        $check = db()->prepare('SELECT b.id FROM behaviors b JOIN children c ON c.id = b.child_id WHERE b.id = ? AND c.family_id = ?');
        $check->execute([$behaviorId, $familyId]);

        if ($check->fetch() && $label !== '' && $basePoints > 0) {
            $update = db()->prepare('UPDATE behaviors SET label = ?, emoji = ?, base_points = ? WHERE id = ?');
            $update->execute([$label, $emoji !== '' ? $emoji : '⭐', $basePoints, $behaviorId]);
            header('Location: ' . $redirectUrl);
            exit;
        }
    }

    if ($action === 'toggle_behavior') {
        $behaviorId = (int)($_POST['behavior_id'] ?? 0);
        $isActive = (int)($_POST['is_active'] ?? 0);
        $check = db()->prepare('SELECT b.id FROM behaviors b JOIN children c ON c.id = b.child_id WHERE b.id = ? AND c.family_id = ?');
        $check->execute([$behaviorId, $familyId]);

        if ($check->fetch()) {
            $update = db()->prepare('UPDATE behaviors SET is_active = ? WHERE id = ?');
            $update->execute([$isActive ? 1 : 0, $behaviorId]);
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // --- 奖励相关 ---
    if ($action === 'add_reward') {
        $title = trim((string)($_POST['title'] ?? ''));
        $emoji = trim((string)($_POST['emoji'] ?? '🎁'));
        $cost = (int)($_POST['cost'] ?? 0);

        if ($postChildId > 0 && $title !== '' && $cost > 0) {
            $check = db()->prepare('SELECT 1 FROM children WHERE id = ? AND family_id = ?');
            $check->execute([$postChildId, $familyId]);
            if ($check->fetch()) {
                $stmt = db()->prepare('INSERT INTO rewards (child_id, title, emoji, cost, is_active, requires_parent_confirmation) VALUES (?, ?, ?, ?, 1, 1)');
                $stmt->execute([$postChildId, $title, $emoji !== '' ? $emoji : '🎁', $cost]);
                header('Location: ' . $redirectUrl);
                exit;
            }
        }
    }

    if ($action === 'edit_reward') {
        $rewardId = (int)($_POST['reward_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $emoji = trim((string)($_POST['emoji'] ?? '🎁'));
        $cost = (int)($_POST['cost'] ?? 0);

        $check = db()->prepare('SELECT r.id FROM rewards r JOIN children c ON c.id = r.child_id WHERE r.id = ? AND c.family_id = ?');
        $check->execute([$rewardId, $familyId]);

        if ($check->fetch() && $title !== '' && $cost > 0) {
            $update = db()->prepare('UPDATE rewards SET title = ?, emoji = ?, cost = ? WHERE id = ?');
            $update->execute([$title, $emoji !== '' ? $emoji : '🎁', $cost, $rewardId]);
            header('Location: ' . $redirectUrl);
            exit;
        }
    }

    if ($action === 'toggle_reward') {
        $rewardId = (int)($_POST['reward_id'] ?? 0);
        $isActive = (int)($_POST['is_active'] ?? 0);
        $check = db()->prepare('SELECT r.id FROM rewards r JOIN children c ON c.id = r.child_id WHERE r.id = ? AND c.family_id = ?');
        $check->execute([$rewardId, $familyId]);

        if ($check->fetch()) {
            $update = db()->prepare('UPDATE rewards SET is_active = ? WHERE id = ?');
            $update->execute([$isActive ? 1 : 0, $rewardId]);
        }
        header('Location: ' . $redirectUrl);
        exit;
    }
    
    // 切换 Creator 视角
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

// 查询选中孩子的行为和奖励
$behaviors = [];
$rewards = [];
if ($selectedChild) {
    $stmt = db()->prepare('SELECT * FROM behaviors WHERE child_id = ? ORDER BY is_active DESC, id ASC');
    $stmt->execute([$selectedChildId]);
    $behaviors = $stmt->fetchAll();

    $stmt = db()->prepare('SELECT * FROM rewards WHERE child_id = ? ORDER BY is_active DESC, cost ASC');
    $stmt->execute([$selectedChildId]);
    $rewards = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>积分规则 - 家庭积分世界</title>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    main.parent { max-width: 1000px; }

    .child-tabs {
      display: flex;
      gap: 12px;
      overflow-x: auto;
      padding-bottom: 8px;
      margin-bottom: 24px;
      scrollbar-width: none;
    }
    .child-tabs::-webkit-scrollbar { display: none; }
    
    .child-tab {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 12px 24px;
      background: #fff;
      border: 2px solid #e2e8f0;
      border-radius: 12px;
      text-decoration: none;
      color: #0f172a;
      font-weight: 700;
      font-size: 15px;
      white-space: nowrap;
      transition: all 0.2s;
    }
    .child-tab:hover { border-color: #93c5fd; }
    .child-tab.active {
      background: #eff6ff;
      border-color: #2563eb;
      color: #1e3a8a;
    }

    .economy-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 24px;
    }
    @media (min-width: 800px) {
      .economy-grid { grid-template-columns: 1fr 1fr; }
    }

    .panel {
      background: #fff;
      border-radius: 16px;
      border: 1px solid #e5e7eb;
      box-shadow: 0 2px 10px rgba(15,23,42,0.04);
      display: flex;
      flex-direction: column;
    }

    .panel-header {
      padding: 20px;
      border-bottom: 1px solid #f1f5f9;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .panel-header h2 {
      margin: 0;
      font-size: 18px;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .panel-body {
      padding: 16px;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .item-card {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 16px;
      background: #f8fafc;
      border-radius: 12px;
      border: 1px solid #e2e8f0;
      transition: opacity 0.2s;
    }
    .item-card.is-inactive {
      opacity: 0.5;
      background: #f1f5f9;
    }

    .item-emoji {
      font-size: 24px;
      line-height: 1;
      flex-shrink: 0;
    }
    .item-info {
      flex: 1;
      min-width: 0;
    }
    .item-title {
      font-weight: 700;
      color: #0f172a;
      font-size: 14px;
      margin-bottom: 2px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .item-points {
      font-weight: 800;
      font-size: 16px;
      white-space: nowrap;
      margin-right: 6px;
    }
    .item-points.earn { color: #16a34a; }
    .item-points.spend { color: #7c5cff; }

    .btn-small {
      padding: 6px 10px;
      border-radius: 6px;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      border: 1px solid #cbd5e1;
      background: #fff;
      color: #475569;
      transition: all 0.2s;
      font-family: inherit;
    }
    .btn-small:hover { background: #e2e8f0; }
    .btn-small.is-on { color: #dc2626; border-color: #fca5a5; background: #fef2f2; }
    
    .btn-add {
      padding: 6px 12px;
      border-radius: 8px;
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      border: none;
      background: #2563eb;
      color: white;
      font-family: inherit;
    }
    .btn-add:hover { background: #1d4ed8; }

    .item-actions {
      display: flex;
      gap: 6px;
      margin-left: 4px;
    }

    /* Modal 原生 Dialog */
    dialog {
      border: none; border-radius: 16px; padding: 0;
      box-shadow: 0 20px 50px rgba(15, 23, 42, 0.2);
      max-width: 400px; width: calc(100% - 32px);
    }
    dialog::backdrop { background: rgba(15, 23, 42, 0.5); backdrop-filter: blur(2px); }
    .modal-header { padding: 20px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
    .modal-header h2 { margin: 0; font-size: 18px; }
    .modal-close { background: transparent; border: none; font-size: 24px; color: #94a3b8; cursor: pointer; }
    .modal-body { padding: 24px; display: grid; gap: 12px; }
    .modal-body label { font-size: 13px; font-weight: 600; color: #475569; }
    .modal-body input { padding: 10px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 14px; }
    .modal-footer { padding: 16px 24px; border-top: 1px solid #e2e8f0; display: flex; gap: 12px; }
    .modal-footer button { flex: 1; padding: 12px; border-radius: 8px; font-weight: 700; cursor: pointer; border: none; font-family: inherit; }
    .modal-btn-cancel { background: #e2e8f0; color: #0f172a; }
    .modal-btn-confirm { background: #2563eb; color: white; }
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
        <a class="nav-link active" href="point-reward-settings.php">积分规则</a>
        <a class="nav-link" href="child-settings.php">孩子设置</a>
        <a class="nav-link" href="family-settings.php">家庭设置</a>
        <a class="nav-link nav-link-logout" href="parent-logout.php">退出</a>
      </nav>
    </header>

    <main class="parent">
      <div class="page-header">
        <h1>积分规则</h1>
      </div>

      <?php if (empty($children)): ?>
        <p class="muted">还没有孩子，请先去“孩子设置”中添加。</p>
      <?php else: ?>
        
        <div class="child-tabs">
          <?php foreach ($children as $c): ?>
            <a href="?child_id=<?= (int)$c['id'] ?>" class="child-tab <?= $c['id'] === $selectedChildId ? 'active' : '' ?>">
              👦 <?= htmlspecialchars($c['name']) ?>
            </a>
          <?php endforeach; ?>
        </div>

        <?php if ($selectedChild): ?>
          <div class="economy-grid">
            
            <!-- 左侧：行为（赚分） -->
            <section class="panel">
              <div class="panel-header">
                <h2>📈 赚分行为</h2>
                <button class="btn-add" onclick="document.getElementById('addBehaviorModal').showModal()">+ 添加行为</button>
              </div>
              <div class="panel-body">
                <?php if (empty($behaviors)): ?>
                  <p class="muted" style="text-align:center; padding:20px 0;">还没有配置行为。</p>
                <?php else: ?>
                  <?php foreach ($behaviors as $b): ?>
                    <div class="item-card <?= $b['is_active'] ? '' : 'is-inactive' ?>">
                      <span class="item-emoji"><?= htmlspecialchars($b['emoji']) ?></span>
                      <div class="item-info">
                        <div class="item-title"><?= htmlspecialchars($b['label']) ?></div>
                      </div>
                      <div class="item-points earn">+<?= (int)$b['base_points'] ?></div>
                      <div class="item-actions">
                        <button 
                          type="button" 
                          class="btn-small" 
                          onclick="openEditBehavior(this)" 
                          data-id="<?= (int)$b['id'] ?>" 
                          data-label="<?= htmlspecialchars($b['label']) ?>" 
                          data-emoji="<?= htmlspecialchars($b['emoji']) ?>" 
                          data-points="<?= (int)$b['base_points'] ?>"
                        >编辑</button>
                        <form method="post" style="margin:0;">
                          <?= csrfField() ?>
                          <input type="hidden" name="action" value="toggle_behavior">
                          <input type="hidden" name="child_id" value="<?= $selectedChildId ?>">
                          <input type="hidden" name="behavior_id" value="<?= (int)$b['id'] ?>">
                          <input type="hidden" name="is_active" value="<?= $b['is_active'] ? 0 : 1 ?>">
                          <button type="submit" class="btn-small <?= $b['is_active'] ? 'is-on' : '' ?>"><?= $b['is_active'] ? '停用' : '启用' ?></button>
                        </form>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </section>

            <!-- 右侧：奖励（花分） -->
            <section class="panel">
              <div class="panel-header">
                <h2>🛍️ 兑换奖励</h2>
                <button class="btn-add" onclick="document.getElementById('addRewardModal').showModal()">+ 添加奖励</button>
              </div>
              <div class="panel-body">
                <?php if (empty($rewards)): ?>
                  <p class="muted" style="text-align:center; padding:20px 0;">还没有配置奖励。</p>
                <?php else: ?>
                  <?php foreach ($rewards as $r): ?>
                    <div class="item-card <?= $r['is_active'] ? '' : 'is-inactive' ?>">
                      <span class="item-emoji"><?= htmlspecialchars($r['emoji']) ?></span>
                      <div class="item-info">
                        <div class="item-title"><?= htmlspecialchars($r['title']) ?></div>
                      </div>
                      <div class="item-points spend"><?= (int)$r['cost'] ?> 分</div>
                      <div class="item-actions">
                        <button 
                          type="button" 
                          class="btn-small" 
                          onclick="openEditReward(this)" 
                          data-id="<?= (int)$r['id'] ?>" 
                          data-title="<?= htmlspecialchars($r['title']) ?>" 
                          data-emoji="<?= htmlspecialchars($r['emoji']) ?>" 
                          data-cost="<?= (int)$r['cost'] ?>"
                        >编辑</button>
                        <form method="post" style="margin:0;">
                          <?= csrfField() ?>
                          <input type="hidden" name="action" value="toggle_reward">
                          <input type="hidden" name="child_id" value="<?= $selectedChildId ?>">
                          <input type="hidden" name="reward_id" value="<?= (int)$r['id'] ?>">
                          <input type="hidden" name="is_active" value="<?= $r['is_active'] ? 0 : 1 ?>">
                          <button type="submit" class="btn-small <?= $r['is_active'] ? 'is-on' : '' ?>"><?= $r['is_active'] ? '停用' : '启用' ?></button>
                        </form>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </section>
            
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </main>
  </div>

  <!-- 添加行为 Modal -->
  <dialog id="addBehaviorModal">
    <div class="modal-header">
      <h2>添加赚分行为</h2>
      <button type="button" class="modal-close" onclick="document.getElementById('addBehaviorModal').close()">&times;</button>
    </div>
    <form method="post" action="point-reward-settings.php?child_id=<?= $selectedChildId ?>">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_behavior">
      <input type="hidden" name="child_id" value="<?= $selectedChildId ?>">
      <div class="modal-body">
        <label>行为名称</label>
        <input type="text" name="label" placeholder="例如：整理房间" required autocomplete="off">
        <div style="display:flex; gap:12px;">
          <div style="flex:1;">
            <label>图标 (Emoji)</label>
            <input type="text" name="emoji" value="⭐" maxlength="4" required>
          </div>
          <div style="flex:1;">
            <label>基础积分</label>
            <input type="number" name="base_points" value="5" min="1" required>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="modal-btn-cancel" onclick="document.getElementById('addBehaviorModal').close()">取消</button>
        <button type="submit" class="modal-btn-confirm">添加行为</button>
      </div>
    </form>
  </dialog>

  <!-- 编辑行为 Modal -->
  <dialog id="editBehaviorModal">
    <div class="modal-header">
      <h2>编辑赚分行为</h2>
      <button type="button" class="modal-close" onclick="document.getElementById('editBehaviorModal').close()">&times;</button>
    </div>
    <form method="post" action="point-reward-settings.php?child_id=<?= $selectedChildId ?>">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="edit_behavior">
      <input type="hidden" name="child_id" value="<?= $selectedChildId ?>">
      <input type="hidden" name="behavior_id" id="edit_behavior_id">
      <div class="modal-body">
        <label>行为名称</label>
        <input type="text" name="label" id="edit_behavior_label" required>
        <div style="display:flex; gap:12px;">
          <div style="flex:1;">
            <label>图标 (Emoji)</label>
            <input type="text" name="emoji" id="edit_behavior_emoji" maxlength="4" required>
          </div>
          <div style="flex:1;">
            <label>基础积分</label>
            <input type="number" name="base_points" id="edit_behavior_points" min="1" required>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="modal-btn-cancel" onclick="document.getElementById('editBehaviorModal').close()">取消</button>
        <button type="submit" class="modal-btn-confirm">保存修改</button>
      </div>
    </form>
  </dialog>

  <!-- 添加奖励 Modal -->
  <dialog id="addRewardModal">
    <div class="modal-header">
      <h2>添加兑换奖励</h2>
      <button type="button" class="modal-close" onclick="document.getElementById('addRewardModal').close()">&times;</button>
    </div>
    <form method="post" action="point-reward-settings.php?child_id=<?= $selectedChildId ?>">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_reward">
      <input type="hidden" name="child_id" value="<?= $selectedChildId ?>">
      <div class="modal-body">
        <label>奖励名称</label>
        <input type="text" name="title" placeholder="例如：看电视 20 分钟" required autocomplete="off">
        <div style="display:flex; gap:12px;">
          <div style="flex:1;">
            <label>图标 (Emoji)</label>
            <input type="text" name="emoji" value="🎁" maxlength="4" required>
          </div>
          <div style="flex:1;">
            <label>所需积分</label>
            <input type="number" name="cost" value="20" min="1" required>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="modal-btn-cancel" onclick="document.getElementById('addRewardModal').close()">取消</button>
        <button type="submit" class="modal-btn-confirm">添加奖励</button>
      </div>
    </form>
  </dialog>

  <!-- 编辑奖励 Modal -->
  <dialog id="editRewardModal">
    <div class="modal-header">
      <h2>编辑兑换奖励</h2>
      <button type="button" class="modal-close" onclick="document.getElementById('editRewardModal').close()">&times;</button>
    </div>
    <form method="post" action="point-reward-settings.php?child_id=<?= $selectedChildId ?>">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="edit_reward">
      <input type="hidden" name="child_id" value="<?= $selectedChildId ?>">
      <input type="hidden" name="reward_id" id="edit_reward_id">
      <div class="modal-body">
        <label>奖励名称</label>
        <input type="text" name="title" id="edit_reward_title" required>
        <div style="display:flex; gap:12px;">
          <div style="flex:1;">
            <label>图标 (Emoji)</label>
            <input type="text" name="emoji" id="edit_reward_emoji" maxlength="4" required>
          </div>
          <div style="flex:1;">
            <label>所需积分</label>
            <input type="number" name="cost" id="edit_reward_cost" min="1" required>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="modal-btn-cancel" onclick="document.getElementById('editRewardModal').close()">取消</button>
        <button type="submit" class="modal-btn-confirm">保存修改</button>
      </div>
    </form>
  </dialog>

  <script>
    function openEditBehavior(btn) {
      document.getElementById('edit_behavior_id').value = btn.dataset.id;
      document.getElementById('edit_behavior_label').value = btn.dataset.label;
      document.getElementById('edit_behavior_emoji').value = btn.dataset.emoji;
      document.getElementById('edit_behavior_points').value = btn.dataset.points;
      document.getElementById('editBehaviorModal').showModal();
    }

    function openEditReward(btn) {
      document.getElementById('edit_reward_id').value = btn.dataset.id;
      document.getElementById('edit_reward_title').value = btn.dataset.title;
      document.getElementById('edit_reward_emoji').value = btn.dataset.emoji;
      document.getElementById('edit_reward_cost').value = btn.dataset.cost;
      document.getElementById('editRewardModal').showModal();
    }
  </script>
</body>
</html>