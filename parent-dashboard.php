<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$familyId = currentParentFamilyId();
if ($familyId === null) {
    header('Location: parent-login.php');
    exit;
}

$notice = '';
if (isset($_GET['approved'])) {
    $notice = '✅ 兑换已确认，积分已扣除。';
} elseif (isset($_GET['rejected'])) {
    $notice = '❌ 兑换已拒绝。';
}

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

// 获取当前家庭所有待审批的兑换，并按 child_id 分组
$pendingStmt = db()->prepare(
    'SELECT rd.id, rd.cost, r.title, r.emoji, rd.child_id
     FROM redemptions rd
     JOIN rewards r ON r.id = rd.reward_id
     JOIN children c ON c.id = rd.child_id
     WHERE c.family_id = ? AND rd.status = "pending"
     ORDER BY rd.requested_at'
);
$pendingStmt->execute([$familyId]);
$allPending = $pendingStmt->fetchAll();

$pendingByChild = [];
foreach ($allPending as $p) {
    $pendingByChild[(int)$p['child_id']][] = $p;
}

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
  <title>家长控制台</title>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    .dashboard-grid {
      display: grid;
      gap: 16px;
      margin-bottom: 24px;
    }

    .dcc {
      background: #fff;
      border-radius: 16px;
      border: 1px solid #e5e7eb;
      padding: 20px;
      box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04);
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    .dcc-head {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .dcc-avatar {
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

    .dcc-name {
      display: flex;
      flex-direction: column;
      gap: 2px;
      min-width: 0;
    }

    .dcc-name strong {
      font-size: 17px;
      color: #0f172a;
      line-height: 1.2;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .dcc-id {
      font-size: 12px;
      color: #94a3b8;
      letter-spacing: 0.02em;
    }

    .dcc-balance {
      display: flex;
      align-items: baseline;
      justify-content: space-between;
      gap: 12px;
      padding: 14px 18px;
      background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
      border: 1px solid #bfdbfe;
      border-radius: 12px;
    }

    .dcc-balance-label {
      font-size: 13px;
      font-weight: 600;
      color: #1e40af;
      letter-spacing: 0.02em;
    }

    .dcc-balance-value {
      display: inline-flex;
      align-items: baseline;
      gap: 4px;
    }

    .dcc-balance-value strong {
      font-size: 38px;
      line-height: 1;
      font-weight: 800;
      color: #1e3a8a;
      font-variant-numeric: tabular-nums;
    }

    .dcc-balance-value span {
      font-size: 14px;
      color: #1e40af;
      font-weight: 600;
    }

    .dcc-daily {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .dcc-daily-head {
      display: flex;
      align-items: baseline;
      justify-content: space-between;
      gap: 12px;
    }

    .dcc-daily-label {
      font-size: 13px;
      font-weight: 600;
      color: #475569;
    }

    .dcc-daily-count {
      font-size: 13px;
      color: #64748b;
      font-variant-numeric: tabular-nums;
    }

    .dcc-daily-count strong {
      font-size: 16px;
      color: #0f172a;
      font-weight: 700;
    }

    .dcc-daily-bar {
      height: 10px;
      background: #f1f5f9;
      border-radius: 999px;
      overflow: hidden;
      border: 1px solid #e2e8f0;
    }

    .dcc-daily-fill {
      height: 100%;
      background: linear-gradient(90deg, #34d399 0%, #10b981 100%);
      border-radius: inherit;
      transition: width 0.4s ease;
    }

    .dcc-daily-fill.is-full {
      background: linear-gradient(90deg, #fbbf24 0%, #f59e0b 100%);
    }

    .dcc-daily-hint {
      margin: 0;
      font-size: 12px;
      color: #94a3b8;
      line-height: 1.4;
    }

    .dcc-daily-hint.is-full {
      color: #b45309;
      font-weight: 600;
    }

    .dcc-pool {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 10px 14px;
      background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
      border: 1px solid #fde68a;
      border-radius: 10px;
      font-size: 13px;
      color: #92400e;
    }

    .dcc-pool-icon {
      font-size: 16px;
      line-height: 1;
    }

    .dcc-pool-label {
      font-weight: 600;
      letter-spacing: 0.02em;
    }

    .dcc-pool-value {
      margin-left: auto;
      font-size: 16px;
      font-weight: 800;
      color: #78350f;
      font-variant-numeric: tabular-nums;
    }

    .dcc-pool-goal {
      color: #b45309;
      font-size: 12px;
      font-variant-numeric: tabular-nums;
    }

    /* 待审批区 */
    .dcc-pending {
      background: #fff7ed;
      border: 1px solid #fdba74;
      border-radius: 12px;
      padding: 14px;
      margin-top: 4px;
    }

    .dcc-pending-title {
      margin: 0 0 10px;
      font-size: 13px;
      font-weight: 700;
      color: #9a3412;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .dcc-pending-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 8px 0;
      border-top: 1px solid #fed7aa;
    }

    .dcc-pending-item:first-of-type {
      border-top: none;
      padding-top: 0;
    }

    .dcc-pending-info {
      flex: 1;
      font-size: 14px;
      color: #431407;
    }

    .dcc-pending-info strong {
      font-weight: 700;
    }

    .dcc-pending-actions {
      display: flex;
      gap: 6px;
      flex-shrink: 0;
    }

    .dcc-pending-actions form {
      margin: 0;
    }

    .dcc-pending-actions button {
      padding: 6px 12px;
      font-size: 12px;
      border-radius: 6px;
      cursor: pointer;
      font-weight: 600;
      border: 1px solid transparent;
      font-family: inherit;
      transition: background 0.15s;
    }

    .btn-approve {
      background: #16a34a;
      color: #fff;
    }
    .btn-approve:hover {
      background: #15803d;
    }

    .btn-reject {
      background: #fff;
      color: #dc2626;
      border-color: #fca5a5;
    }
    .btn-reject:hover {
      background: #fef2f2;
    }

    .dcc-actions {
      display: flex;
      gap: 8px;
    }

    .dcc-actions .button {
      flex: 1;
      margin-top: 0;
    }

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
      margin: 0 0 12px;
    }
  </style>
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

      <?php if ($notice !== ''): ?>
        <p class="success"><?= htmlspecialchars($notice) ?></p>
      <?php endif; ?>

      <?php if (! $children): ?>
        <div class="dashboard-empty">
          <p>还没有孩子记录</p>
          <a class="button" href="child-settings.php" style="display:inline-block;text-decoration:none;">去添加第一个孩子</a>
        </div>
      <?php else: ?>
        <div class="dashboard-grid">
          <?php foreach ($children as $child):
            $dailyCap = max(1, (int)($child['daily_cap'] ?? 25));
            $dailyShown = min($dailyCap, max(0, (int)($child['daily_points_today'] ?? 0)));
            $dailyPercent = $dailyCap > 0 ? ($dailyShown / $dailyCap) * 100 : 0;
            $isDailyFull = $dailyShown >= $dailyCap;
            $emoji = $childEmoji[(int)$child['id']] ?? '🧒';
            $childPending = $pendingByChild[(int)$child['id']] ?? [];
          ?>
            <article class="dcc">
              <div class="dcc-head">
                <span class="dcc-avatar" aria-hidden="true"><?= $emoji ?></span>
                <div class="dcc-name">
                  <strong><?= htmlspecialchars($child['name']) ?></strong>
                  <span class="dcc-id">ID <?= (int)$child['id'] ?></span>
                </div>
              </div>

              <div class="dcc-balance">
                <span class="dcc-balance-label">可用积分</span>
                <div class="dcc-balance-value">
                  <strong><?= (int)($child['normal_balance'] ?? 0) ?></strong>
                  <span>分</span>
                </div>
              </div>

              <?php if (! empty($childPending)): ?>
                <div class="dcc-pending">
                  <div class="dcc-pending-title">⏳ 待审批兑换</div>
                  <?php foreach ($childPending as $p): ?>
                    <div class="dcc-pending-item">
                      <div class="dcc-pending-info">
                        <?= htmlspecialchars($p['emoji'] ?? '🎁') ?>
                        <strong><?= htmlspecialchars($p['title']) ?></strong>
                        <span style="font-size:13px;color:#9a3412;margin-left:4px;">(<?= (int)$p['cost'] ?> 分)</span>
                      </div>
                      <div class="dcc-pending-actions">
                        <form method="post" action="approve-reward.php">
                          <?= csrfField() ?>
                          <input type="hidden" name="redemption_id" value="<?= (int)$p['id'] ?>">
                          <input type="hidden" name="redirect_to" value="parent-dashboard.php">
                          <input type="hidden" name="action" value="reject">
                          <button type="submit" class="btn-reject">拒绝</button>
                        </form>
                        <form method="post" action="approve-reward.php">
                          <?= csrfField() ?>
                          <input type="hidden" name="redemption_id" value="<?= (int)$p['id'] ?>">
                          <input type="hidden" name="redirect_to" value="parent-dashboard.php">
                          <input type="hidden" name="action" value="approve">
                          <button type="submit" class="btn-approve">确认扣除</button>
                        </form>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <div class="dcc-daily">
                <div class="dcc-daily-head">
                  <span class="dcc-daily-label">今日已得</span>
                  <span class="dcc-daily-count"><strong><?= $dailyShown ?></strong> / <?= $dailyCap ?> 分</span>
                </div>
                <div class="dcc-daily-bar">
                  <div class="dcc-daily-fill <?= $isDailyFull ? 'is-full' : '' ?>" style="width: <?= (float)$dailyPercent ?>%"></div>
                </div>
                <p class="dcc-daily-hint <?= $isDailyFull ? 'is-full' : '' ?>">
                  <?= $isDailyFull
                    ? '今日已达上限，之后获得的积分将全部进入季度奖池'
                    : '超出每日上限的部分自动进入季度奖池' ?>
                </p>
              </div>

              <div class="dcc-pool">
                <span class="dcc-pool-icon" aria-hidden="true">⭐</span>
                <span class="dcc-pool-label">季度奖池</span>
                <strong class="dcc-pool-value"><?= (int)($child['quarterly_pool'] ?? 0) ?></strong>
                <span class="dcc-pool-goal">/ <?= (int)($child['quarterly_goal'] ?? 150) ?> 分</span>
              </div>

              <div class="dcc-actions">
                <a class="button secondary" href="parent-record.php?child_id=<?= (int)$child['id'] ?>">记录积分</a>
              </div>
            </article>
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