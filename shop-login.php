<?php
require_once __DIR__ . '/includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$now = time();

// 简单的 session 级限流：5 次失败锁 1 分钟
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
  <title>输入 PIN 进入商店</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <main class="auth">
    <h1>🔢 输入 PIN</h1>
    <p class="muted" style="text-align:center;margin:-8px 0 20px;">
      输入家长给你的 6 位数字
    </p>

    <?php if ($error): ?>
      <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <input
        type="text"
        name="pin"
        inputmode="numeric"
        pattern="\d{6}"
        maxlength="6"
        placeholder="000000"
        required
        autofocus
        <?= $isLocked ? 'disabled' : '' ?>
        style="font-size:32px;text-align:center;letter-spacing:12px;font-family:monospace;padding:18px;"
      >
      <button type="submit" <?= $isLocked ? 'disabled' : '' ?>>进入我的商店</button>
    </form>

    <p style="text-align:center;margin-top:24px;">
      <a href="index.php" style="color:#2563eb;text-decoration:none;">← 返回首页</a>
    </p>
  </main>
</body>
</html>