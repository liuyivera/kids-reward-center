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
        $error = '登录会话已过期，已为您刷新安全令牌。请重新填写。';
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
  <title>家长登录 - 家庭积分世界</title>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    body {
      margin: 0;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #f4f5f7;
      padding: 20px;
      box-sizing: border-box;
    }

    .login-card {
      background: #fff;
      width: 100%;
      max-width: 400px;
      border-radius: 24px;
      padding: 40px 32px;
      box-shadow: 0 10px 40px rgba(15, 23, 42, 0.08);
    }

    .brand-header {
      text-align: center;
      margin-bottom: 24px;
    }

    .brand-icon {
      font-size: 32px;
      margin-bottom: 12px;
      display: inline-block;
      background: #eff6ff;
      width: 64px;
      height: 64px;
      line-height: 64px;
      border-radius: 50%;
    }

    .brand-title {
      margin: 0;
      font-size: 24px;
      color: #0f172a;
      font-weight: 800;
    }

    .brand-subtitle {
      margin: 6px 0 0;
      font-size: 14px;
      color: #64748b;
      font-weight: 500;
    }

    .divider {
      height: 1px;
      background: #e2e8f0;
      margin: 24px 0;
    }

    .login-form {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    .form-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .form-group label {
      font-size: 14px;
      font-weight: 600;
      color: #475569;
    }

    .form-group input {
      padding: 14px 16px;
      border-radius: 10px;
      border: 1px solid #cbd5e1;
      font-size: 16px;
      transition: border-color 0.2s, box-shadow 0.2s;
    }

    .form-group input:focus {
      outline: none;
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }

    .login-btn {
      margin-top: 8px;
      padding: 14px;
      border-radius: 10px;
      background: #2563eb;
      color: white;
      font-size: 16px;
      font-weight: 700;
      border: none;
      cursor: pointer;
      transition: background 0.2s;
    }

    .login-btn:hover {
      background: #1d4ed8;
    }

    .error-msg {
      background: #fef2f2;
      color: #dc2626;
      padding: 12px;
      border-radius: 8px;
      font-size: 14px;
      margin-bottom: 16px;
      text-align: center;
      border: 1px solid #fecaca;
    }

    .back-link {
      display: block;
      text-align: center;
      margin-top: 24px;
      font-size: 14px;
      color: #64748b;
      text-decoration: none;
      transition: color 0.2s;
    }

    .back-link:hover {
      color: #0f172a;
    }
  </style>
</head>
<body>
  <main class="login-card">
    <div class="brand-header">
      <div class="brand-icon">🏠</div>
      <h1 class="brand-title">家庭积分世界</h1>
      <p class="brand-subtitle">家长管理系统</p>
    </div>
    
    <div class="divider"></div>

    <?php if ($error): ?>
      <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" class="login-form">
      <div class="form-group">
        <label>用户名</label>
        <input type="text" name="username" required autofocus autocomplete="username">
      </div>

      <div class="form-group">
        <label>密码</label>
        <input type="password" name="password" required autocomplete="current-password">
      </div>

      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
      
      <button type="submit" class="login-btn">登录</button>
    </form>

    <a href="landing.php" class="back-link">← 返回首页</a>
  </main>
</body>
</html>