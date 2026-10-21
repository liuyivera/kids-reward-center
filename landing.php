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
  <style>
    body {
      margin: 0;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, #f0f9ff 0%, #e0e7ff 100%);
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      padding: 20px;
      box-sizing: border-box;
    }

    .landing-container {
      width: 100%;
      max-width: 800px;
      text-align: center;
    }

    .landing-header {
      margin-bottom: 40px;
    }

    .landing-header h1 {
      font-size: 32px;
      color: #1e3a8a;
      margin: 0 0 12px;
      letter-spacing: 0.02em;
    }

    .landing-header p {
      font-size: 16px;
      color: #64748b;
      margin: 0;
    }

    .landing-cards {
      display: grid;
      grid-template-columns: 1fr;
      gap: 20px;
      align-items: stretch;
    }

    @media (min-width: 640px) {
      .landing-cards {
        grid-template-columns: 1fr 1fr;
      }
    }

    .landing-card {
      background: #fff;
      border-radius: 24px;
      padding: 40px 30px;
      text-decoration: none;
      color: inherit;
      box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05);
      border: 2px solid transparent;
      transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
      display: flex;
      flex-direction: column;
      align-items: center;
      height: 100%;
      box-sizing: border-box;
    }

    .landing-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08);
    }

    .landing-card.parent:hover { border-color: #bfdbfe; }
    .landing-card.child:hover { border-color: #fde68a; }

    .landing-icon {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 40px;
      margin-bottom: 20px;
    }

    .landing-card.parent .landing-icon { background: #eff6ff; }
    .landing-card.child .landing-icon { background: #fffbeb; }

    .landing-card h2 {
      margin: 0 0 12px;
      font-size: 20px;
      color: #0f172a;
    }

    .landing-card p {
      margin: 0 0 24px;
      font-size: 14px;
      color: #64748b;
      line-height: 1.6;
      flex: 1; /* 让文字区域撑开，保持底部按钮对齐 */
    }

    .landing-btn {
      width: 100%;
      padding: 14px 24px;
      border-radius: 999px;
      font-size: 15px;
      font-weight: 700;
      color: white;
      transition: opacity 0.2s;
      box-sizing: border-box;
    }

    .landing-card.parent .landing-btn { background: #2563eb; }
    .landing-card.child .landing-btn { background: #f59e0b; }
  </style>
</head>
<body>
  <main class="landing-container">
    <header class="landing-header">
      <h1>家庭积分世界</h1>
      <p>记录每一分努力，兑换每一份快乐。</p>
    </header>

    <div class="landing-cards">
      <!-- 家长入口 -->
      <a href="parent-login.php" class="landing-card parent">
        <div class="landing-icon">🔒</div>
        <h2>家长入口</h2>
        <p>管理孩子的积分、审批奖励兑换，<br>以及设置家庭积分规则。</p>
        <div class="landing-btn">登录家长控制台</div>
      </a>

      <!-- 孩子入口 -->
      <a href="shop-login.php" class="landing-card child">
        <div class="landing-icon">🎁</div>
        <h2>孩子入口</h2>
        <p>输入家长告诉你的 6 位 PIN 密码，<br>进入你的专属奖励商店。</p>
        <div class="landing-btn">输入 PIN 进入</div>
      </a>
    </div>
  </main>
</body>
</html>