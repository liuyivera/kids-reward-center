<?php
session_start();
require_once __DIR__ . '/includes/db.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');

    if (! hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $error = '登录会话已过期，已为您刷新安全令牌。请重新填写用户名和密码后再提交。';
    } else {
        $stmt = db()->prepare('SELECT * FROM parent_users WHERE username = ?');
        $stmt->execute([$_POST['username'] ?? '']);
        $user = $stmt->fetch();

        if ($user && password_verify($_POST['password'] ?? '', $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['parent_id'] = $user['id'];
            $_SESSION['parent_family_id'] = (int)($user['family_id'] ?? 0);
            header('Location: parent-dashboard.php');
            exit;
        }

        $error = '用户名或密码不正确。';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>家长入口</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <main class="auth">
    <h1>🔒 家长入口</h1>

    <?php if ($error): ?>
      <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="post">
      <label>用户名</label>
      <input type="text" name="username" required>

      <label>密码</label>
      <input type="password" name="password" required>

      <button type="submit">进入积分提交</button>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    </form>
  </main>
</body>
</html>