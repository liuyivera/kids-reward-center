<?php
require_once __DIR__ . '/includes/db.php';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>家庭积分世界</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <main style="max-width:780px;margin:48px auto;padding:20px;">
    <h1>家庭积分世界</h1>
    <p>欢迎来到家庭积分世界。这是公开入口页面。要管理孩子或查看家庭完整信息，请使用"家长入口"登录。</p>

    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:20px;align-items:center;">
      <a class="button" href="parent-login.php">🔒 家长入口</a>
      <span style="color:#6b7280;font-size:14px;">家长控制台（需登录）</span>
    </div>

    <hr style="margin:32px 0;">

    <section>
      <h2>孩子快速访问</h2>
      <p>孩子只需记住家长给的 <strong>6 位数字 PIN</strong>，输入即可进入自己的积分和商店。</p>

      <div style="margin-top:16px;">
        <a class="button" href="shop-login.php" style="display:inline-block;max-width:260px;">🔢 输入 PIN 进入</a>
      </div>

      <p style="margin-top:20px;color:#6b7280;font-size:13px;">
        如果家长给你发了完整链接（比如 <em>.../shop.php?child_id=12&amp;access_code=482913</em>），也可以直接点开，无需输入 PIN。
      </p>
    </section>

    <footer style="margin-top:40px;color:#9ca3af;font-size:13px;">
      此页面不会显示任何家庭的具体积分或孩子信息。
    </footer>
  </main>
</body>
</html>