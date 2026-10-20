<?php
require_once __DIR__ . '/includes/db.php';

// Simple public landing page - no family/child data shown
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
    <p>欢迎来到家庭积分世界。这是公开入口页面。要管理孩子或查看家庭完整信息，请使用“家长入口”登录。</p>

    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:20px;align-items:center;">
      <a class="button" href="parent-login.php">🔒 家长入口</a>
      <span style="color:#6b7280;font-size:14px;">家长控制台（需登录）</span>
    </div>

    <hr style="margin:24px 0;">

    <section>
      <h2>孩子快速访问</h2>
      <p>如果家长为孩子生成了访问链接或访问码，你可以在下面输入以直接查看该孩子的积分和商店：</p>

      <form method="get" action="shop.php" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <label for="child_id">孩子 ID</label>
        <input id="child_id" name="child_id" type="number" min="1" style="width:100px;padding:6px;border-radius:6px;border:1px solid #cbd5e1;">
        <label for="access_code">访问码</label>
        <input id="access_code" name="access_code" type="text" style="padding:6px;border-radius:6px;border:1px solid #cbd5e1;">
        <button type="submit" class="button">进入孩子商店</button>
      </form>

      <p style="margin-top:12px;color:#6b7280;font-size:13px;">或者直接使用家长发给你的完整链接，例如：<em>https://yourhost/family-points/shop.php?child_id=12&access_code=ABCD1234</em></p>
    </section>

    <footer style="margin-top:32px;color:#9ca3af;font-size:13px;">此页面不会显示任何家庭的具体积分或孩子信息。</footer>
  </main>
</body>
</html>
