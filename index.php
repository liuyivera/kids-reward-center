<?php
require_once __DIR__ . '/includes/db.php';

$rewardExpiry = db()->query(
    'SELECT UNIX_TIMESTAMP(CURRENT_DATE() + INTERVAL 1 DAY)'
)->fetchColumn();

$children = db()->query('SELECT * FROM children ORDER BY id')->fetchAll();

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

$shopFile = [
    'minecraft' => 'shop-minecraft.php',
    'music' => 'shop-music.php'
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>家庭积分世界</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body data-page-title="homeTitle">
  <main class="home">
    <div class="language-toolbar">
  <button
    type="button"
    class="language-toggle"
    data-language-toggle
  >
    EN
  </button>
</div>
    <h1 data-i18n="homeTitle">家庭积分世界</h1>

    <?php foreach ($children as $child):
        $pendingStmt->execute([$child['id']]);
        $pending = $pendingStmt->fetchAll();

        $dailyShown = min($child['daily_points_today'], $child['daily_cap']);
        $overflow = max(0, $child['daily_points_today'] - $child['daily_cap']);
        $shop = $shopFile[$child['theme']] ?? 'index.php';
        ?>
      <section
        class="child-card <?= htmlspecialchars($child['theme']) ?>"
        data-child-id="<?= (int)$child['id'] ?>"
      >
        <div class="card-head">
          <h2 data-i18n="<?= (int)$child['id'] === 1 ? 'boy' : 'girl' ?>"><?= htmlspecialchars($child['name']) ?></h2>
          <div class="balance-right">
            <span data-i18n="availablePoints">可用积分</span>
            <strong data-balance><?= (int)$child['normal_balance'] ?></strong>
          </div>
        </div>

        <div class="daily-row">
          <span data-i18n="earnedToday">今日已得</span>
          <strong data-daily>
            <?= $dailyShown ?> / <?= (int)$child['daily_cap'] ?>
          </strong>
        </div>

        <div class="progress">
          <div
            class="bar"
            data-daily-bar
            style="width: <?= $dailyShown / $child['daily_cap'] * 100 ?>%"
          ></div>
        </div>

        <?php if ($overflow > 0): ?>
          <p
            class="overflow-note"
            data-i18n="overflow"
            data-count="<?= (int)$overflow ?>"
          >
            ⭐ 超额 <?= $overflow ?> 分已进入季度奖池
          </p>
        <?php endif; ?>

        <p class="pool-line">
          ⭐ <span data-i18n="quarterlyPool">季度奖池</span>：
          <span data-pool><?= (int)$child['quarterly_pool'] ?></span>
          / <?= (int)$child['quarterly_goal'] ?>
        </p>

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

        <a
  class="button shop-entry <?= htmlspecialchars($child['theme']) ?>"
  href="<?= htmlspecialchars($shop) ?>"
  data-i18n="<?= $child['theme'] === 'minecraft'
                ? 'openBoyShop'
                : 'openGirlShop' ?>"
>
  <?= $child['theme'] === 'minecraft'
                ? '📦 打开奖励箱子'
                : '🎵 进入奖励商店' ?>
</a>
      </section>
    <?php endforeach; ?>

    <a class="parent-link" href="parent-login.php" data-i18n="parentEntrance">🔒 家长入口</a>
  </main>

  <script src="assets/live.js"></script>
  <script>
  window.rewardExpiryAt = <?= (int)$rewardExpiry ?> * 1000;
</script>
<script src="assets/reward-expiry.js"></script>
<script src="assets/i18n.js"></script>
</body>
</html>