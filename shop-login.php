<?php
require_once __DIR__ . '/includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$now = time();

$lockedUntil = (int)($_SESSION['pin_lock_until'] ?? 0);
$isLocked = $lockedUntil > $now;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ! $isLocked) {
    $pin = trim((string)($_POST['pin'] ?? ''));

    if (! preg_match('/^\d{6}$/', $pin)) {
        $error = '请输入 6 位数字 PIN。';
    } else {
        $stmt = db()->prepare(
            'SELECT id FROM children
             WHERE access_code = ?
               AND (access_code_expires_at IS NULL OR access_code_expires_at > NOW())'
        );
        $stmt->execute([$pin]);
        $child = $stmt->fetch();

        if (! $child) {
            $failCount = (int)($_SESSION['pin_fail_count'] ?? 0) + 1;

            if ($failCount >= 5) {
                $_SESSION['pin_lock_until'] = $now + 60;
                $_SESSION['pin_fail_count'] = 0;
                $isLocked = true;
                $error = '尝试次数过多，请 1 分钟后再试。';
            } else {
                $_SESSION['pin_fail_count'] = $failCount;
                $error = 'PIN 不正确或已过期。';
            }
        } else {
            unset($_SESSION['pin_fail_count'], $_SESSION['pin_lock_until']);

            $_SESSION['child_access_child_id'] = (int)$child['id'];
            $_SESSION['child_access_code'] = $pin;

            header('Location: shop.php?child_id=' . (int)$child['id'] . '&access_code=' . urlencode($pin));
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>孩子登录 - 家庭积分世界</title>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    body {
      margin: 0;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
      padding: 20px;
      box-sizing: border-box;
    }

    .login-card {
      background: #fff;
      width: 100%;
      max-width: 400px;
      border-radius: 24px;
      padding: 40px 32px;
      box-shadow: 0 10px 40px rgba(180, 83, 9, 0.1);
    }

    .brand-header {
      text-align: center;
      margin-bottom: 24px;
    }

    .brand-icon {
      font-size: 32px;
      margin-bottom: 12px;
      display: inline-block;
      background: #fffbeb;
      width: 64px;
      height: 64px;
      line-height: 64px;
      border-radius: 50%;
    }

    .brand-title {
      margin: 0;
      font-size: 24px;
      color: #78350f;
      font-weight: 800;
    }

    .brand-subtitle {
      margin: 6px 0 0;
      font-size: 14px;
      color: #b45309;
      font-weight: 500;
    }

    .divider {
      height: 1px;
      background: #fef3c7;
      margin: 24px 0;
    }

    .pin-input {
      width: 100%;
      box-sizing: border-box;
      font-size: 40px;
      text-align: center;
      letter-spacing: 12px;
      font-family: 'SF Mono', 'Menlo', 'Consolas', monospace;
      padding: 16px 10px 16px 22px;
      border: 2px dashed #fcd34d;
      border-radius: 16px;
      background: #fffbeb;
      color: #78350f;
      outline: none;
      transition: all 0.2s;
      margin-bottom: 20px;
    }

    .pin-input:focus {
      border-color: #f59e0b;
      background: #fff;
      box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.2);
    }

    .pin-btn {
      width: 100%;
      padding: 16px;
      border-radius: 16px;
      background: #f59e0b;
      color: white;
      font-size: 18px;
      font-weight: 800;
      border: none;
      cursor: pointer;
      box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
      transition: transform 0.1s, background 0.2s;
    }

    .pin-btn:active {
      transform: scale(0.98);
    }

    .pin-btn:hover {
      background: #d97706;
    }

    .pin-btn:disabled {
      background: #cbd5e1;
      box-shadow: none;
      cursor: not-allowed;
    }

    .error-msg {
      background: #fef2f2;
      color: #dc2626;
      padding: 12px;
      border-radius: 10px;
      font-size: 14px;
      margin-bottom: 20px;
      font-weight: 600;
      text-align: center;
      border: 1px solid #fecaca;
    }

    .back-link {
      display: block;
      text-align: center;
      margin-top: 24px;
      font-size: 14px;
      color: #b45309;
      text-decoration: none;
      font-weight: 600;
      transition: color 0.2s;
    }
    
    .back-link:hover {
      color: #78350f;
    }
  </style>
</head>
<body>
  <main class="login-card">
    <div class="brand-header">
      <div class="brand-icon">🏠</div>
      <h1 class="brand-title">家庭积分世界</h1>
      <p class="brand-subtitle">儿童专属商城</p>
    </div>

    <div class="divider"></div>

    <?php if ($error): ?>
      <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <input
        type="text"
        name="pin"
        class="pin-input"
        inputmode="numeric"
        pattern="\d{6}"
        maxlength="6"
        placeholder="······"
        required
        autofocus
        <?= $isLocked ? 'disabled' : '' ?>
      >
      <button type="submit" class="pin-btn" <?= $isLocked ? 'disabled' : '' ?>>
        <?= $isLocked ? '等待解锁' : '进入商店' ?>
      </button>
    </form>

    <a href="landing.php" class="back-link">← 返回首页</a>
  </main>
</body>
</html>