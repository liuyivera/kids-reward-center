<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$children = db()->query('SELECT * FROM children ORDER BY id')->fetchAll();

$pendingStmt = db()->prepare(
    'SELECT rd.id, rd.cost, r.title, r.emoji
     FROM redemptions rd
     JOIN rewards r ON r.id = rd.reward_id
     WHERE rd.child_id = ? AND rd.status = "pending"'
);

$eventsStmt = db()->prepare(
    "SELECT
        pe.behavior_label,
        COALESCE(b.emoji, '⭐') AS emoji,
        pe.awarded_points,
        pe.overflow_points,
        pe.completion_type,
        pe.event_date,
        pe.created_at
     FROM point_events pe
     LEFT JOIN behaviors b ON b.id = pe.behavior_id
     WHERE pe.child_id = ?
     ORDER BY pe.event_date DESC, pe.created_at DESC, pe.id DESC
     LIMIT 5"
);

$childEmoji = [1 => '👦', 2 => '👧'];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>家长控制台</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <main class="parent">
    <h1>家长控制台</h1>

    <?php foreach ($children as $child):
        $pendingStmt->execute([$child['id']]);
        $pending = $pendingStmt->fetchAll();

        $eventsStmt->execute([$child['id']]);
        $events = $eventsStmt->fetchAll();

        $dailyShown = min($child['daily_points_today'], $child['daily_cap']);
        $overflow = max(0, $child['daily_points_today'] - $child['daily_cap']);
        ?>
      <section class="summary-card">
        <div class="card-head">
          <h2>
            <span class="child-emoji">
              <?= $childEmoji[$child['id']] ?? '🧒' ?>
            </span>
            <?= htmlspecialchars($child['name']) ?>
          </h2>
          <div class="balance-right">
            可用积分
            <strong><?= (int)$child['normal_balance'] ?></strong>
          </div>
        </div>

        <div class="daily-row">
          <span>今日已得</span>
          <strong><?= $dailyShown ?> / <?= (int)$child['daily_cap'] ?></strong>
        </div>
        <div class="progress">
          <div class="bar" style="width: <?= $dailyShown / $child['daily_cap'] * 100 ?>%"></div>
        </div>

        <?php if ($overflow > 0): ?>
          <p class="overflow-note">
            超额 <?= $overflow ?> 分已进入季度奖池
          </p>
        <?php endif; ?>

        <p class="pool-line">
          ⭐ 季度奖池：<?= (int)$child['quarterly_pool'] ?> / <?= (int)$child['quarterly_goal'] ?>
        </p>

        <?php if ($pending): ?>
          <div class="pending-box">
            <h3>已提交兑换申请</h3>
            <?php foreach ($pending as $item): ?>
              <div class="pending-item">
                <span>
                  <?= htmlspecialchars($item['emoji'] . ' ' . $item['title']) ?>
                  · <?= (int)$item['cost'] ?> 分
                </span>
                <div class="approval-actions">
                  <form method="post" action="approve-reward.php">
                    <?= csrfField() ?>
                    <input type="hidden" name="redemption_id" value="<?= (int)$item['id'] ?>">
                    <input type="hidden" name="action" value="approve">
                    <button class="approve">同意</button>
                  </form>
                  <form method="post" action="approve-reward.php">
                    <?= csrfField() ?>
                    <input type="hidden" name="redemption_id" value="<?= (int)$item['id'] ?>">
                    <input type="hidden" name="action" value="reject">
                    <button class="reject">拒绝</button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="history">
          <h3>行为记录</h3>
          <?php if (! $events): ?>
            <p class="muted">暂无记录</p>
          <?php else: ?>
            <ul>
              <?php foreach ($events as $event): ?>
                <li>
                  <span>
                    <?= htmlspecialchars($event['emoji'] . ' ' . $event['behavior_label']) ?>
                  </span>
                  <strong>+<?= (int)$event['awarded_points'] ?></strong>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <a class="button" href="parent-record.php?child_id=<?= (int)$child['id'] ?>">
          <span class="plus">+</span> 记录积分
        </a>
      </section>
    <?php endforeach; ?>

    <a class="button secondary" href="parent-rewards.php">🎁 兑换确认</a>
    <a class="button secondary" href="index.php">查看孩子端</a>
    <a class="logout" href="parent-logout.php">退出登录</a>
  </main>
</body>
</html>