<?php
require_once __DIR__ . '/includes/db.php';

$rewardExpiry = db()->query(
    'SELECT UNIX_TIMESTAMP(CURRENT_DATE() + INTERVAL 1 DAY)'
)->fetchColumn();

$childId = 1;

$childStmt = db()->prepare('SELECT * FROM children WHERE id = ?');
$childStmt->execute([$childId]);
$child = $childStmt->fetch();

if (! $child) {
    exit('孩子不存在');
}

$rewardStmt = db()->prepare(
    'SELECT * FROM rewards
     WHERE child_id = ? AND is_active = 1
     ORDER BY cost'
);
$rewardStmt->execute([$childId]);
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
         OR (
           rd.status = 'approved'
           AND rd.approved_at >= CURRENT_DATE()
         )
       )
     ORDER BY rd.id DESC"
);

$pendingStmt->execute([$childId]);
$pending = $pendingStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>弟弟的奖励箱子</title>
  <link rel="stylesheet" href="assets/style.css">
  <link rel="stylesheet" href="assets/minecraft.css">
</head>
<body class="minecraft" data-page-title="boyShopTitle">
  <main class="shop">
    <div class="shop-topbar">
      <a class="back-button" href="index.php" data-i18n="backHome">⟵ 积分世界</a>
      <div class="language-toolbar">
        <button
          type="button"
          class="language-toggle"
          data-language-toggle
        >
          EN
        </button>
      </div>
    </div>

    <h1 data-i18n="boyShopTitle">📦 弟弟的奖励箱子</h1>

<?php
  $dailyCap = max(1, (int)$child['daily_cap']);

$dailyShown = min(
    $dailyCap,
    max(0, (int)$child['daily_points_today'])
);

$dailyPercent = $dailyShown / $dailyCap * 100;
?>

<section class="mc-status shop-summary">
  <div class="card-head">
    <h2 data-i18n="<?= (int)$child['id'] === 1 ? 'boy' : 'girl' ?>"><?= htmlspecialchars($child['name']) ?></h2>

    <div class="balance-right">
      <span data-i18n="availablePoints">可用积分</span>
      <strong><?= (int)$child['normal_balance'] ?> 💚</strong>
    </div>
  </div>

  <div class="daily-block">
    <div class="daily-row">
      <span data-i18n="earnedToday">今日已得</span>
      <strong><?= $dailyShown ?> / <?= $dailyCap ?></strong>
    </div>

    <div class="progress">
      <div
        class="bar"
        style="width: <?= $dailyPercent ?>%"
      ></div>
    </div>
  </div>

  <p class="pool-line">
    ⭐ <span data-i18n="quarterlyPool">季度奖池</span>：
    <span data-pool><?= (int)$child['quarterly_pool'] ?></span>
    / <?= (int)$child['quarterly_goal'] ?>
  </p>
</section>

    <?php if (! empty($pending)): ?>
  <section class="pending-cart" aria-label="我的奖励">
    <h2 class="pending-cart-title" data-i18n="myRewards">📦 我的奖励</h2>

    <?php foreach ($pending as $pendingItem):
        $isApproved = $pendingItem['status'] === 'approved';
        $stateClass = $isApproved
            ? 'reward-approved'
            : 'reward-pending';
        ?>
      <article
        class="cart-card <?= $stateClass ?>"
        <?= $isApproved ? 'data-approved-reward' : '' ?>
      >
        <div class="cart-icon" aria-hidden="true">
          <?= htmlspecialchars($pendingItem['emoji'] ?? '🎁') ?>
        </div>

        <div class="cart-info">
          <h3 data-reward-name="<?= htmlspecialchars(
              $pendingItem['title'],
              ENT_QUOTES,
              'UTF-8'
          ) ?>">
            <?= htmlspecialchars($pendingItem['title']) ?>
          </h3>

          <p data-i18n="points" data-count="<?= (int)$pendingItem['cost'] ?>"><?= (int)$pendingItem['cost'] ?> 分</p>

          <span
            class="cart-status"
            data-i18n="<?= $isApproved ? 'approved' : 'pending' ?>"
          >
            <?= $isApproved
                          ? '✓ 已收入囊中'
                          : '⏳ 待家长确认' ?>
          </span>

          <small data-i18n="<?= $isApproved ? 'approvedHelp' : 'pendingHelp' ?>">
            <?= $isApproved
                          ? '家长已同意，可以领取或使用啦！'
                          : '申请已发送，家长同意后才能领取或使用。' ?>
          </small>
        </div>
      </article>
    <?php endforeach; ?>
  </section>
<?php endif; ?>

    <section class="reward-grid">
      <?php foreach ($rewards as $reward):
          $canAfford = $child['normal_balance'] >= $reward['cost'];
          ?>
        <article class="reward-card <?= $canAfford ? 'available' : 'locked' ?>">
  <div class="reward-icon"><?= htmlspecialchars($reward['emoji']) ?></div>

  <div class="reward-body">
    <h2 data-reward-name="<?= htmlspecialchars($reward['title'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($reward['title']) ?></h2>
    <p class="cost" data-i18n="points" data-count="<?= (int)$reward['cost'] ?>"><?= (int)$reward['cost'] ?> 分</p>

    <?php if ($reward['requires_parent_confirmation']): ?>
      <p class="note" data-i18n="parentApproval">需要家长确认</p>
    <?php endif; ?>

    <?php if ($canAfford): ?>
      <form method="post" action="request-reward.php">
        <input type="hidden" name="reward_id" value="<?= (int)$reward['id'] ?>">
        <button type="submit" data-i18n="redeem">兑换</button>
      </form>
    <?php else: ?>
      <p class="missing" data-i18n="needMore" data-count="<?= (int)($reward['cost'] - $child['normal_balance']) ?>">还差 <?= (int)($reward['cost'] - $child['normal_balance']) ?> 分</p>
    <?php endif; ?>
  </div>
</article>
      <?php endforeach; ?>
    </section>
  </main>
  <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.4/dist/confetti.browser.min.js"></script>
<script src="assets/shop.js"></script>
<div class="modal hidden" id="error-modal">
  <div class="modal-card">
    <h2>⏳ 已有兑换申请</h2>
    <p>请先等待家长确认当前的兑换请求。</p>
    <button onclick="document.getElementById('error-modal').classList.add('hidden')">
      知道了
    </button>
  </div>
</div>

<script>
  const params = new URLSearchParams(window.location.search);

  if (params.get("error") === "duplicate") {
    document.getElementById("error-modal").classList.remove("hidden");
  }
</script>
<script>
  window.rewardExpiryAt = <?= (int)$rewardExpiry ?> * 1000;
</script>
<script src="assets/reward-expiry.js"></script>
<script src="assets/i18n.js"></script>
</body>
</html>