<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$pending = db()->query(
    'SELECT rd.id, rd.cost, rd.requested_at,
            c.name AS child_name, c.theme,
            r.title, r.emoji
     FROM redemptions rd
     JOIN children c ON c.id = rd.child_id
     JOIN rewards r ON r.id = rd.reward_id
     WHERE rd.status = "pending"
     ORDER BY rd.requested_at'
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>兑换确认</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <main class="parent">
    <a class="back" href="parent-dashboard.php">← 返回控制台</a>
    <?php if (isset($_GET['approved'])): ?>
  <p class="success">✅ 兑换已确认，积分已扣除。</p>
<?php endif; ?>
    <h1>兑换确认</h1>

    <?php if (! $pending): ?>
      <p>目前没有待确认的兑换请求。</p>
    <?php endif; ?>

    <?php foreach ($pending as $item): ?>
      <section class="approval-card">
        <h2><?= htmlspecialchars($item['emoji'] . ' ' . $item['title']) ?></h2>
        <p>孩子：<?= htmlspecialchars($item['child_name']) ?></p>
        <p>价格：<?= (int)$item['cost'] ?> 分</p>

        <div class="actions">
          <form method="post" action="approve-reward.php">
            <?= csrfField() ?>
            <input type="hidden" name="redemption_id" value="<?= (int)$item['id'] ?>">
            <input type="hidden" name="action" value="approve">
            <button class="approve">确认兑换</button>
          </form>

          <form method="post" action="approve-reward.php">
            <?= csrfField() ?>
            <input type="hidden" name="redemption_id" value="<?= (int)$item['id'] ?>">
            <input type="hidden" name="action" value="reject">
            <button class="reject">拒绝</button>
          </form>
        </div>
      </section>
    <?php endforeach; ?>
  </main>
</body>
</html>