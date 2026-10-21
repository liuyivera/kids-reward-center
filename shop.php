<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$requestedChildId = isset($_GET['child_id']) ? (int) $_GET['child_id'] : 0;
$accessCode = trim((string) ($_GET['access_code'] ?? ''));

if ($requestedChildId <= 0) {
    renderErrorPage(
        '访问出错', 'Access Error',
        '缺少孩子 ID。', 'Missing child ID.',
        400, 'shop-login.php', '重新输入 PIN', 'Re-enter PIN'
    );
}

// === 三选一身份验证 ===
$child = null;
$accessMode = null;

// A. 孩子用 access_code 访问
if ($accessCode !== '') {
    $stmt = db()->prepare(
        'SELECT * FROM children
         WHERE id = ? AND access_code = ?
           AND (access_code_expires_at IS NULL OR access_code_expires_at > NOW())'
    );
    $stmt->execute([$requestedChildId, $accessCode]);
    $child = $stmt->fetch();

    if ($child) {
        $accessMode = 'child';
        $_SESSION['child_access_child_id'] = $requestedChildId;
        $_SESSION['child_access_code'] = $accessCode;
    }
}

// B / C. 家长登录（含 creator dev 切换）
if (! $child && ! empty($_SESSION['parent_id'])) {
    $parentFamilyId = currentParentFamilyId();

    if ($parentFamilyId !== null) {
        $stmt = db()->prepare(
            'SELECT * FROM children WHERE id = ? AND family_id = ?'
        );
        $stmt->execute([$requestedChildId, $parentFamilyId]);
        $child = $stmt->fetch();

        if ($child) {
            $accessMode = (isAppCreator() && ! empty($_SESSION['dev_family_id']))
                ? 'creator'
                : 'parent';
        }
    }
}

if (! $child) {
    renderErrorPage(
        '无权访问', 'Access Denied',
        '无权访问此孩子的商店。请使用家长提供的完整访问链接。', 'You do not have permission to access this child\'s shop. Please use the complete link provided by your parent.',
        403, 'shop-login.php', '重新输入 PIN', 'Re-enter PIN'
    );
}

$familyName = '家庭';
$familyId = (int) ($child['family_id'] ?? 0);
if ($familyId > 0) {
    $familyStmt = db()->prepare('SELECT name FROM families WHERE id = ?');
    $familyStmt->execute([$familyId]);
    $name = $familyStmt->fetchColumn();
    if ($name !== false && $name !== null && $name !== '') {
        $familyName = (string) $name;
    }
}

$theme = strtolower((string) ($child['theme'] ?? 'minecraft'));

$rewardExpiry = db()->query(
    'SELECT UNIX_TIMESTAMP(CURRENT_DATE() + INTERVAL 1 DAY)'
)->fetchColumn();

$rewardStmt = db()->prepare(
    'SELECT * FROM rewards
     WHERE child_id = ? AND is_active = 1
     ORDER BY cost'
);
$rewardStmt->execute([$requestedChildId]);
$rewards = $rewardStmt->fetchAll();

$pendingStmt = db()->prepare(
    "SELECT
        rd.id,
        rd.cost,
        rd.status,
        rd.approved_at,
        r.title,
        r.emoji
     FROM redemptions rd
     JOIN rewards r ON r.id = rd.reward_id
     WHERE rd.child_id = ?
       AND (
         rd.status = 'pending'
         OR (rd.status = 'approved' AND rd.approved_at >= CURRENT_DATE())
       )
     ORDER BY rd.id DESC"
);
$pendingStmt->execute([$requestedChildId]);
$pending = $pendingStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($child['name']) ?> 的奖励商店</title>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    /* 全局重置与主题变量定义 */
    :root {
      --bg-color: #f4f5f7;
      --card-bg: #ffffff;
      --text-main: #1e293b;
      --text-muted: #64748b;
      --primary: #2563eb;
      --primary-hover: #1d4ed8;
      --border: #e2e8f0;
      --shadow: 0 10px 30px rgba(15, 23, 42, 0.05);
      --radius: 20px;
    }

    /* 主题覆盖 */
    body.music {
      --bg-color: #fdf7ff;
      --text-main: #2b2b3a;
      --primary: #7c5cff;
      --primary-hover: #6b4bea;
      --border: #e9e0ff;
      --shadow: 0 10px 30px rgba(124, 92, 255, 0.08);
      background: linear-gradient(180deg, #fdf7ff, #eef7f2);
    }

    body.minecraft {
      --bg-color: #2d372b;
      --card-bg: #3b3b3b;
      --text-main: #f8fafc;
      --text-muted: #cbd5e1;
      --primary: #55a630;
      --primary-hover: #4a9129;
      --border: #1f1f1f;
      --shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
      background:
        linear-gradient(rgba(0,0,0,.55), rgba(0,0,0,.75)),
        repeating-conic-gradient(#5b8c3a 0% 25%, #4e7a31 0% 50%);
      background-size: auto, 48px 48px;
      font-family: "Courier New", monospace;
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      background-color: var(--bg-color);
      color: var(--text-main);
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      -webkit-font-smoothing: antialiased;
    }

    .shop-wrapper {
      max-width: 600px;
      margin: 0 auto;
      padding: 20px 16px 60px;
    }

    /* 顶部导航 */
    .shop-topbar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 24px;
      gap: 12px;
    }

    .topbar-left {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }

    .back-button {
      display: inline-flex;
      align-items: center;
      padding: 8px 16px;
      border-radius: 999px;
      background: var(--card-bg);
      color: var(--text-main);
      text-decoration: none;
      font-size: 14px;
      font-weight: 700;
      box-shadow: 0 2px 10px rgba(0,0,0,0.05);
      border: 1px solid var(--border);
      white-space: nowrap;
    }

    .family-tag {
      font-size: 13px;
      font-weight: 600;
      color: var(--text-muted);
      background: var(--card-bg);
      padding: 6px 12px;
      border-radius: 999px;
      border: 1px solid var(--border);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 350px;
    }

    .topbar-right {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .language-toggle {
      padding: 8px 16px;
      border-radius: 999px;
      background: var(--card-bg);
      color: var(--text-main);
      font-size: 13px;
      font-weight: 700;
      border: 1px solid var(--border);
      cursor: pointer;
      box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }

    /* 状态主卡片 */
    .status-card {
      background: var(--card-bg);
      border-radius: var(--radius);
      padding: 24px;
      box-shadow: var(--shadow);
      border: 1px solid var(--border);
      margin-bottom: 28px;
      position: relative;
      overflow: hidden;
    }

    .status-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 20px;
    }

    .status-title {
      font-size: 20px;
      font-weight: 800;
      margin: 0;
    }

    .balance-box {
      text-align: right;
    }

    .balance-label {
      font-size: 13px;
      color: var(--text-muted);
      font-weight: 600;
      display: block;
      margin-bottom: 4px;
    }

    .balance-amount {
      font-size: 40px;
      font-weight: 900;
      line-height: 1;
      color: var(--primary);
      display: flex;
      align-items: baseline;
      gap: 4px;
    }

    .balance-amount span {
      font-size: 16px;
      color: var(--text-main);
    }

    /* 进度条 */
    .progress-block {
      margin-bottom: 16px;
    }

    .progress-header {
      display: flex;
      justify-content: space-between;
      font-size: 13px;
      font-weight: 600;
      color: var(--text-muted);
      margin-bottom: 8px;
    }

    .progress-track {
      height: 12px;
      background: var(--border);
      border-radius: 999px;
      overflow: hidden;
    }

    .progress-fill {
      height: 100%;
      background: var(--primary);
      border-radius: inherit;
      transition: width 0.4s ease;
    }

    /* 奖池 */
    .pool-line {
      margin: 0;
      font-size: 14px;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    /* 我的奖励 (Pending) */
    .section-title {
      font-size: 18px;
      font-weight: 800;
      margin: 0 0 16px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .pending-list {
      display: flex;
      flex-direction: column;
      gap: 12px;
      margin-bottom: 28px;
    }

    .pending-card {
      background: var(--card-bg);
      border-radius: 16px;
      padding: 16px;
      display: flex;
      align-items: center;
      gap: 16px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.03);
      border: 2px solid var(--border);
    }

    .pending-card.is-approved {
      border-color: #86efac;
      background: rgba(240, 253, 244, 0.5);
    }
    
    body.minecraft .pending-card.is-approved {
      border-color: #4e7a31;
      background: rgba(35, 104, 52, 0.2);
    }

    .pending-icon {
      font-size: 32px;
      width: 48px;
      height: 48px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: var(--bg-color);
      border-radius: 12px;
    }

    .pending-info {
      flex: 1;
    }

    .pending-info h4 {
      margin: 0 0 4px;
      font-size: 16px;
      font-weight: 800;
    }

    .pending-status {
      font-size: 13px;
      font-weight: 700;
    }

    .status-pending { color: #d97706; }
    .status-approved { color: #16a34a; }

    /* 奖励网格 */
    .reward-grid {
      display: grid;
      gap: 16px;
      grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    }

    .reward-card {
      background: var(--card-bg);
      border-radius: 20px;
      padding: 20px 16px;
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      box-shadow: 0 4px 16px rgba(0,0,0,0.04);
      border: 2px solid transparent;
      transition: transform 0.2s, border-color 0.2s;
    }

    .reward-card.available {
      border-color: var(--primary);
      box-shadow: 0 8px 24px rgba(37, 99, 235, 0.15);
    }

    body.music .reward-card.available {
      box-shadow: 0 8px 24px rgba(124, 92, 255, 0.15);
    }

    body.minecraft .reward-card.available {
      border: 3px solid #1f1f1f;
      box-shadow: inset -4px -4px 0 rgba(0,0,0,.35), inset 4px 4px 0 rgba(255,255,255,.12);
    }

    .reward-card.locked {
      opacity: 0.6;
      filter: grayscale(0.5);
    }

    .reward-icon {
      font-size: 48px;
      margin-bottom: 12px;
      line-height: 1;
    }

    .reward-title {
      font-size: 16px;
      font-weight: 800;
      margin: 0 0 8px;
      line-height: 1.3;
    }

    .reward-cost {
      font-size: 16px;
      font-weight: 800;
      color: var(--primary);
      margin: 0 0 16px;
    }

    .reward-btn {
      width: 100%;
      padding: 12px;
      border-radius: 12px;
      background: var(--primary);
      color: white;
      font-size: 15px;
      font-weight: 800;
      border: none;
      cursor: pointer;
      transition: background 0.2s, transform 0.1s;
      font-family: inherit;
    }

    .reward-btn:hover {
      background: var(--primary-hover);
    }

    .reward-btn:active {
      transform: scale(0.96);
    }

    .reward-missing {
      font-size: 13px;
      font-weight: 700;
      color: var(--text-muted);
      background: var(--bg-color);
      padding: 8px 12px;
      border-radius: 8px;
      width: 100%;
    }

    body.minecraft .reward-missing {
      background: rgba(0,0,0,0.2);
      color: #ffd166;
    }

    /* 底部返回按钮 */
    .bottom-return {
      text-align: center;
      margin-top: 40px;
      padding-bottom: 20px;
    }
    
    .bottom-return-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 14px 32px;
      border-radius: 999px;
      background: var(--card-bg);
      color: var(--text-main);
      text-decoration: none;
      font-size: 15px;
      font-weight: 700;
      box-shadow: 0 4px 12px rgba(0,0,0,0.05);
      border: 1px solid var(--border);
      transition: transform 0.15s, background 0.2s;
    }
    
    .bottom-return-btn:hover {
      background: var(--bg-color);
      transform: translateY(-2px);
    }
    body.minecraft .bottom-return-btn:hover {
      background: #4a4a4a;
    }

    /* Modal */
    .modal {
      position: fixed;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
      background: rgba(15, 23, 42, 0.6);
      z-index: 1000;
      backdrop-filter: blur(4px);
    }

    .modal.hidden {
      display: none;
    }

    .modal-card {
      width: min(100%, 360px);
      background: #fff;
      border-radius: 24px;
      padding: 28px 24px;
      box-shadow: 0 20px 50px rgba(15, 23, 42, 0.2);
      text-align: center;
    }

    .modal-card h2 {
      margin: 0 0 12px;
      font-size: 20px;
      color: #0f172a;
    }

    .modal-card p {
      margin: 0 0 24px;
      color: #475569;
      line-height: 1.5;
    }

    .modal-btn {
      width: 100%;
      padding: 14px;
      border-radius: 12px;
      background: #2563eb;
      color: white;
      font-size: 16px;
      font-weight: 700;
      border: none;
      cursor: pointer;
    }
  </style>
</head>
<body class="<?= htmlspecialchars($theme) ?>">

  <div class="shop-wrapper">
    <!-- 整合后的顶栏 -->
    <div class="shop-topbar">
      <div class="topbar-left">
        <span class="family-tag">【<?= htmlspecialchars($familyName) ?>】</span>
      </div>
      <div class="topbar-right">
        <?php if (! empty($_SESSION['parent_id'])): ?>
          <?= renderCreatorDevTool() ?>
        <?php endif; ?>
        <button type="button" class="language-toggle" data-language-toggle>EN</button>
      </div>
    </div>

    <!-- 主卡片 -->
    <?php
      $dailyCap = max(1, (int)$child['daily_cap']);
      $dailyShown = min($dailyCap, max(0, (int)$child['daily_points_today']));
      $dailyPercent = $dailyShown / $dailyCap * 100;
    ?>
    <section class="status-card">
      <div class="status-header">
        <h1 class="status-title"><?= htmlspecialchars($child['name']) ?></h1>
        <div class="balance-box">
          <span class="balance-label" data-i18n="availablePoints">可用积分</span>
          <div class="balance-amount">
            <?= (int)$child['normal_balance'] ?> <span>分</span>
          </div>
        </div>
      </div>

      <div class="progress-block">
        <div class="progress-header">
          <span data-i18n="earnedToday">今日已得</span>
          <strong><?= $dailyShown ?> / <?= $dailyCap ?></strong>
        </div>
        <div class="progress-track">
          <div class="progress-fill" style="width: <?= $dailyPercent ?>%"></div>
        </div>
      </div>

      <p class="pool-line">
        ⭐ <span data-i18n="quarterlyPool">季度奖池</span>：
        <span><?= (int)$child['quarterly_pool'] ?></span> / <?= (int)$child['quarterly_goal'] ?>
      </p>
    </section>

    <!-- 我的奖励 (Pending) -->
    <?php if (! empty($pending)): ?>
      <h2 class="section-title" data-i18n="myRewards">📦 我的奖励</h2>
      <section class="pending-list">
        <?php foreach ($pending as $pendingItem):
            $isApproved = $pendingItem['status'] === 'approved';
        ?>
          <article class="pending-card <?= $isApproved ? 'is-approved' : '' ?>" <?= $isApproved ? 'data-approved-reward' : '' ?>>
            <div class="pending-icon">
              <?= htmlspecialchars($pendingItem['emoji'] ?? '🎁') ?>
            </div>
            <div class="pending-info">
              <h4 data-reward-name="<?= htmlspecialchars($pendingItem['title'], ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($pendingItem['title']) ?>
              </h4>
              <span class="pending-status <?= $isApproved ? 'status-approved' : 'status-pending' ?>">
                <?= $isApproved ? '✓ 已收入囊中' : '⏳ 待家长确认' ?>
              </span>
            </div>
            <div style="font-weight:800;color:var(--text-muted);">
              <?= (int)$pendingItem['cost'] ?> 分
            </div>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>

    <!-- 商城列表 -->
    <h2 class="section-title" style="margin-top:32px;">🛍️ 奖励商店</h2>
    <section class="reward-grid">
      <?php foreach ($rewards as $reward):
          $canAfford = $child['normal_balance'] >= $reward['cost'];
          $missing = $reward['cost'] - $child['normal_balance'];
      ?>
        <article class="reward-card <?= $canAfford ? 'available' : 'locked' ?>">
          <div class="reward-icon"><?= htmlspecialchars($reward['emoji']) ?></div>
          <h2 class="reward-title" data-reward-name="<?= htmlspecialchars($reward['title'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($reward['title']) ?></h2>
          <p class="reward-cost" data-i18n="points" data-count="<?= (int)$reward['cost'] ?>"><?= (int)$reward['cost'] ?> 分</p>

          <?php if ($canAfford): ?>
            <form method="post" action="request-reward.php" style="width:100%;">
              <input type="hidden" name="reward_id" value="<?= (int)$reward['id'] ?>">
              <input type="hidden" name="child_id" value="<?= (int)$child['id'] ?>">
              <?php if ($accessMode === 'child' && $accessCode !== ''): ?>
                <input type="hidden" name="access_code" value="<?= htmlspecialchars($accessCode, ENT_QUOTES, 'UTF-8') ?>">
              <?php endif; ?>
              <button type="submit" class="reward-btn" data-i18n="redeem">兑换</button>
            </form>
          <?php else: ?>
            <div class="reward-missing" data-i18n="needMore" data-count="<?= $missing ?>">
              还差 <?= $missing ?> 分
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </section>

    <!-- 底部返回按钮 -->
    <div class="bottom-return">
      <a href="index.php" class="bottom-return-btn">⟵ 返回积分世界</a>
    </div>

  </div>

  <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.4/dist/confetti.browser.min.js"></script>
  <script src="assets/shop.js"></script>
  
  <!-- 重复申请错误弹窗 -->
  <div class="modal hidden" id="error-modal">
    <div class="modal-card">
      <h2>⏳ 已有兑换申请</h2>
      <p>请先等待家长确认当前的兑换请求。</p>
      <button class="modal-btn" onclick="document.getElementById('error-modal').classList.add('hidden')">知道了</button>
    </div>
  </div>

  <script>
    const params = new URLSearchParams(window.location.search);
    const error = params.get("error");
    const modal = document.getElementById("error-modal");
    const modalTitle = modal.querySelector("h2");
    const modalDesc = modal.querySelector("p");

    if (error) {
        if (error === "duplicate") {
        modalTitle.textContent = "⏳ 已有兑换申请";
        modalDesc.textContent = "请先等待家长确认当前的兑换请求。";
        modal.classList.remove("hidden");
        } else if (error === "insufficient_points") {
        modalTitle.textContent = "💸 积分不足";
        modalDesc.textContent = "你的可用积分不够兑换这个奖励哦，再去多赚点积分吧！";
        modal.classList.remove("hidden");
        } else if (error === "reward_not_found" || error === "not_your_reward") {
        modalTitle.textContent = "🚫 无法兑换";
        modalDesc.textContent = "这个奖励不存在，或者不属于你的商店。";
        modal.classList.remove("hidden");
        }
    }
  </script>
  <script>
    window.rewardExpiryAt = <?= (int)$rewardExpiry ?> * 1000;
  </script>
  <script src="assets/reward-expiry.js"></script>
  <script src="assets/i18n.js"></script>
</body>
</html>