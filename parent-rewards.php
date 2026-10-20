<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$familyId = currentParentFamilyId();
if ($familyId === null) {
    header('Location: parent-login.php');
    exit;
}

$pending = db()->prepare(
    'SELECT rd.id, rd.cost, rd.requested_at,
            c.name AS child_name, c.theme,
            r.title, r.emoji
     FROM redemptions rd
     JOIN children c ON c.id = rd.child_id
     JOIN rewards r ON r.id = rd.reward_id
     WHERE c.family_id = ? AND rd.status = "pending"
     ORDER BY rd.requested_at'
);
$pending->execute([$familyId]);
$pending = $pending->fetchAll();
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
  <div class="parent-shell">
    <header class="topbar">
      <div class="topbar-brand">家庭积分世界</div>
      <nav class="topbar-nav" aria-label="家长导航">
        <a class="nav-link" href="parent-dashboard.php">控制台</a>
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
        <h1>兑换确认</h1>
      </div>

      <?php if (isset($_GET['approved'])): ?>
    <p class="success">✅ 兑换已确认，积分已扣除。</p>
  <?php endif; ?>

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
  </div>
</body>
</html>