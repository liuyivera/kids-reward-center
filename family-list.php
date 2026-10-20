<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$creatorMode = isAppCreator();
$currentParentId = (int)($_SESSION['parent_id'] ?? 0);

if ($creatorMode) {
    $familiesStmt = db()->prepare(
        'SELECT f.id, f.name, COUNT(DISTINCT p.id) AS parent_count, COUNT(DISTINCT c.id) AS child_count
         FROM families f
         LEFT JOIN parent_users p ON p.family_id = f.id
         LEFT JOIN children c ON c.family_id = f.id
         GROUP BY f.id, f.name
         ORDER BY f.id'
    );
    $familiesStmt->execute();
    $families = $familiesStmt->fetchAll();
} else {
    $familyId = currentParentFamilyId();
    if ($familyId === null) {
        header('Location: parent-login.php');
        exit;
    }

    $familyStmt = db()->prepare('SELECT id, name FROM families WHERE id = ?');
    $familyStmt->execute([$familyId]);
    $families = $familyStmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>家庭列表</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <main class="parent">
    <a class="back" href="parent-dashboard.php">← 返回控制台</a>
    <h1>家庭列表</h1>

    <?php if ($creatorMode): ?>
      <section class="summary-card">
        <h2>所有家庭</h2>
        <?php if (! $families): ?>
          <p class="muted">暂无家庭数据。</p>
        <?php else: ?>
          <ul>
            <?php foreach ($families as $family): ?>
              <li style="display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap;">
                <span>
                  <?= htmlspecialchars($family['name']) ?>
                  <small>家长：<?= (int)$family['parent_count'] ?> | 儿童：<?= (int)$family['child_count'] ?></small>
                </span>
                <a class="button secondary" href="family-settings.php?family_id=<?= (int)$family['id'] ?>">查看家庭</a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
    <?php else: ?>
      <section class="summary-card">
        <h2>当前家庭</h2>
        <?php if (! $families): ?>
          <p class="muted">未找到当前家庭。</p>
        <?php else: ?>
          <ul>
            <?php foreach ($families as $family): ?>
              <li>
                <?= htmlspecialchars($family['name']) ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?= renderCreatorDevTool() ?>
  </main>
</body>
</html>
