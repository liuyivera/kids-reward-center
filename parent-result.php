<?php
require_once __DIR__ . '/includes/auth.php';

$result = $_SESSION['last_result'] ?? null;
$behavior = $_SESSION['last_behavior'] ?? '';

if (! $result) {
    header('Location: parent-dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>记录成功</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <main class="parent result">
    <h1>✅ 已记录</h1>

    <p class="behavior"><?= htmlspecialchars($behavior) ?></p>

    <div class="result-card">
      <p>本次获得：<strong><?= (int)$result['awarded'] ?> 分</strong></p>
      <p>进入可用余额：<strong><?= (int)$result['to_balance'] ?> 分</strong></p>
      <p>进入季度奖池：<strong><?= (int)$result['to_pool'] ?> 分</strong></p>
    </div>

    <a class="button" href="parent-record.php">继续记录</a>
    <a class="button secondary" href="index.php">查看孩子端</a>
    <a class="button secondary" href="parent-dashboard.php">返回控制台</a>
  </main>
</body>
</html>