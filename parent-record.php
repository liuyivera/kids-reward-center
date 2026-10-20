<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'switch_dev_family') {
        if (! isAppCreator()) {
            http_response_code(403);
            exit('此操作仅限应用创建者。');
        }

        $targetFamilyId = (int)($_POST['dev_family_id'] ?? 0);
        $familyCheck = db()->prepare('SELECT id FROM families WHERE id = ?');
        $familyCheck->execute([$targetFamilyId]);

        if (! $familyCheck->fetch()) {
            http_response_code(403);
            exit('无效的家庭视角。');
        }

        $_SESSION['dev_family_id'] = $targetFamilyId;
        $redirectTo = trim((string)($_POST['redirect_to'] ?? 'parent-record.php'));
        $safeRedirect = in_array($redirectTo, ['index.php', 'parent-dashboard.php', 'parent-record.php', 'parent-rewards.php', 'child-settings.php', 'point-reward-settings.php', 'family-settings.php', 'shop.php'], true)
            ? $redirectTo
            : 'parent-record.php';
        header('Location: ' . $safeRedirect);
        exit;
    }
}

$familyId = currentParentFamilyId();
if ($familyId === null) {
    header('Location: parent-login.php');
    exit;
}

$children = db()->prepare('SELECT * FROM children WHERE family_id = ? ORDER BY id');
$children->execute([$familyId]);
$children = $children->fetchAll();
$selectedChildId = isset($_GET['child_id']) ? (int)$_GET['child_id'] : ($children[0]['id'] ?? 0);

$selectedChild = null;
if ($selectedChildId > 0) {
    $childCheck = db()->prepare('SELECT 1 FROM children WHERE id = ? AND family_id = ?');
    $childCheck->execute([$selectedChildId, $familyId]);
    if (! $childCheck->fetch()) {
        http_response_code(403);
        exit('无权访问此孩子。');
    }

    foreach ($children as $c) {
        if ((int)$c['id'] === $selectedChildId) {
            $selectedChild = $c;
            break;
        }
    }
}

$dailyCap = max(1, (int)($selectedChild['daily_cap'] ?? 25));
$dailyPointsToday = max(0, (int)($selectedChild['daily_points_today'] ?? 0));
$lastScoreDate = (string)($selectedChild['last_score_date'] ?? '');
if ($lastScoreDate !== date('Y-m-d')) {
    $dailyPointsToday = 0;
}
$dailyRemaining = max(0, $dailyCap - $dailyPointsToday);

$stmt = db()->prepare(
    'SELECT * FROM behaviors
     WHERE child_id = ? AND is_active = 1
     ORDER BY id'
);
$stmt->execute([$selectedChildId]);
$behaviors = $stmt->fetchAll();

$emojiPool = ['👦', '👧', '🧒', '👶', '🐻', '🦊', '🐯', '🐼', '🐨', '🦁', '🐰', '🐷'];
$childEmoji = [];
foreach ($children as $i => $child) {
    $childEmoji[(int)$child['id']] = $emojiPool[$i % count($emojiPool)];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>记录积分</title>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    /* ===== 孩子选择卡片 ===== */
    .child-buttons {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      gap: 12px;
    }

    .child-button {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 18px 12px;
      border-radius: 16px;
      background: #f8fafc;
      border: 2px solid #e5e7eb;
      color: #0f172a;
      text-decoration: none;
      font-weight: 700;
      font-size: 15px;
      transition: border-color 0.2s ease, background 0.2s ease, transform 0.15s ease;
      min-height: 130px;
    }

    .child-button:hover {
      border-color: #93c5fd;
      background: #eff6ff;
    }

    .child-button.active {
      background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
      border-color: #2563eb;
      color: #1e3a8a;
      box-shadow: 0 6px 16px rgba(37, 99, 235, 0.15);
    }

    .child-button .child-emoji {
      font-size: 44px;
      line-height: 1;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .child-button .child-name {
      font-size: 15px;
      line-height: 1.2;
      max-width: 100%;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    /* ===== 必填标记 ===== */
    .required-star {
      color: #dc2626;
      margin-left: 2px;
      font-weight: 700;
    }

    .section-title .required-star {
      font-size: 1em;
    }

    /* ===== 行为卡片重排 ===== */
    .behavior-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 12px;
    }

    .behavior-card {
      display: grid;
      grid-template-columns: auto 1fr auto;
      grid-template-rows: auto auto;
      align-items: center;
      gap: 6px 10px;
      padding: 14px;
      border: 2px solid #e5e7eb;
      border-radius: 14px;
      cursor: pointer;
      background: #fff;
      position: relative;
      transition: border-color 0.2s ease, background 0.2s ease;
    }

    .behavior-card:hover {
      border-color: #93c5fd;
    }

    .behavior-card.selected,
    .behavior-card:has(input:checked) {
      border-color: #2563eb;
      background: #eff6ff;
    }

    .behavior-card input[type="radio"] {
      position: absolute;
      opacity: 0;
      pointer-events: none;
    }

    .behavior-card .behavior-emoji {
      grid-column: 1;
      grid-row: 1;
      font-size: 32px;
      line-height: 1;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 40px;
      min-height: 40px;
    }

    .behavior-card .behavior-points {
      grid-column: 3;
      grid-row: 1;
      justify-self: end;
      font-size: 15px;
      font-weight: 800;
      color: #16a34a;
      background: #dcfce7;
      padding: 4px 10px;
      border-radius: 999px;
      line-height: 1;
      white-space: nowrap;
    }

    .behavior-card .behavior-label {
      grid-column: 1 / -1;
      grid-row: 2;
      font-size: 14px;
      line-height: 1.4;
      color: #0f172a;
      font-weight: 600;
      word-break: break-word;
    }

    .behavior-card.selected .behavior-label,
    .behavior-card:has(input:checked) .behavior-label {
      color: #1e3a8a;
    }
  </style>
</head>
<body>
  <div class="parent-shell">
    <header class="topbar">
      <div class="topbar-brand">家庭积分世界</div>
      <?= renderCreatorDevTool() ?>
      <nav class="topbar-nav" aria-label="家长导航">
        <a class="nav-link" href="parent-dashboard.php">控制台</a>
        <a class="nav-link active" href="parent-record.php">记录积分</a>
        <a class="nav-link" href="point-reward-settings.php">积分规则</a>
        <a class="nav-link" href="child-settings.php">孩子设置</a>
        <a class="nav-link" href="family-settings.php">家庭设置</a>
        <a class="nav-link nav-link-logout" href="parent-logout.php">退出</a>
      </nav>
    </header>

    <main class="parent">
      <div class="page-header">
        <span class="eyebrow">家长端</span>
        <h1>记录积分</h1>
      </div>

      <section class="selector">
        <p class="section-title">选择孩子<span class="required-star">*</span></p>
        <div class="child-buttons">
          <?php foreach ($children as $child): ?>
            <a
              class="child-button <?= $child['id'] === $selectedChildId ? 'active' : '' ?>"
              href="?child_id=<?= (int)$child['id'] ?>"
            >
              <span class="child-emoji" aria-hidden="true">
                <?= $childEmoji[(int)$child['id']] ?? '🧒' ?>
              </span>
              <span class="child-name"><?= htmlspecialchars($child['name']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </section>

      <form method="post" action="record-point.php" class="record-form">
        <?= csrfField() ?>
        <input type="hidden" name="child_id" value="<?= (int)$selectedChildId ?>">

        <p class="section-title">选择行为<span class="required-star">*</span></p>
        <div class="behavior-grid">
          <?php foreach ($behaviors as $index => $behavior): ?>
            <label class="behavior-card">
              <input
                type="radio"
                name="behavior_id"
                value="<?= (int)$behavior['id'] ?>"
                data-base-points="<?= (int)$behavior['base_points'] ?>"
                <?= $index === 0 ? 'required' : '' ?>
              >
              <span class="behavior-emoji" aria-hidden="true"><?= htmlspecialchars($behavior['emoji']) ?></span>
              <span class="behavior-points">+<?= (int)$behavior['base_points'] ?></span>
              <span class="behavior-label"><?= htmlspecialchars($behavior['label']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>

        <fieldset class="quality-group">
          <legend>完成方式<span class="required-star">*</span></legend>

          <div class="quality-radios">
            <label class="choice-card">
              <input type="radio" name="completion_base" value="reminder" required>
              <span>提醒后完成</span>
            </label>

            <label class="choice-card">
              <input type="radio" name="completion_base" value="self_initiated">
              <span>自觉完成 ×2</span>
            </label>
          </div>

          <label class="toggle-row">
            <span>特别好 +1</span>
            <span class="toggle">
              <input type="checkbox" name="excellent" value="1">
              <span class="slider"></span>
            </span>
          </label>
        </fieldset>

        <label for="event_date">发生日期</label>
        <input type="date" name="event_date" id="event_date"
               value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>

        <label for="note">备注（可选）</label>
        <input type="text" name="note" id="note"
               placeholder="例如：主动把客厅玩具全部收好">

        <section class="score-preview" aria-live="polite" aria-atomic="true">
          <div class="score-preview-heading">
            <span>本次积分预览</span>
            <strong id="score-preview-total">—</strong>
          </div>

          <p id="score-preview-formula">请选择行为和完成方式</p>

          <div
            id="score-preview-breakdown"
            hidden
            style="margin-top:12px;padding-top:10px;border-top:1px dashed #e5e7eb;"
          >
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;font-size:13px;margin:4px 0;">
              <span style="color:#374151;">→ 进入可用余额</span>
              <strong id="breakdown-balance" style="color:#16a34a;">+0 分</strong>
            </div>

            <div id="breakdown-pool-row" style="display:flex;justify-content:space-between;align-items:center;gap:12px;font-size:13px;margin:4px 0;">
              <span style="color:#6b7280;">→ 进入季度奖池</span>
              <strong id="breakdown-pool" style="color:#9ca3af;">+0 分</strong>
            </div>

            <p id="breakdown-remaining" style="margin:8px 0 0;font-size:12px;color:#64748b;"></p>
          </div>

          <small>点击下方按钮后才会提交；超出每日上限的部分自动进入季度奖池。</small>
        </section>

        <button type="submit" class="record-submit">
          <span class="plus">+</span> 记录积分
        </button>
      </form>
    </main>
  </div>

  <script>
    document.querySelectorAll('.behavior-card input').forEach(input => {
      input.addEventListener('change', () => {
        document.querySelectorAll('.behavior-card')
          .forEach(card => card.classList.remove('selected'));
        input.closest('.behavior-card').classList.add('selected');
      });
    });
  </script>

  <script>
    window.scoreContext = {
      dailyCap: <?= (int)$dailyCap ?>,
      dailyPointsToday: <?= (int)$dailyPointsToday ?>,
      dailyRemaining: <?= (int)$dailyRemaining ?>
    };
  </script>

  <script>
(() => {
  const form = document.querySelector('.record-form');
  const totalElement = document.getElementById('score-preview-total');
  const formulaElement = document.getElementById('score-preview-formula');
  const breakdownElement = document.getElementById('score-preview-breakdown');
  const balanceElement = document.getElementById('breakdown-balance');
  const poolElement = document.getElementById('breakdown-pool');
  const poolRowElement = document.getElementById('breakdown-pool-row');
  const remainingElement = document.getElementById('breakdown-remaining');

  if (!form || !totalElement || !formulaElement) return;

  function updateScorePreview() {
    const behavior = form.querySelector('input[name="behavior_id"]:checked');
    const completion = form.querySelector('input[name="completion_base"]:checked');
    const excellent = form.querySelector('input[name="excellent"]')?.checked ?? false;

    if (!behavior || !completion) {
      totalElement.textContent = '—';
      formulaElement.textContent = !behavior ? '请选择行为' : '请选择完成方式';
      if (breakdownElement) breakdownElement.hidden = true;
      return;
    }

    const basePoints = Number(behavior.dataset.basePoints);
    const isSelf = completion.value === 'self_initiated';
    const multiplier = isSelf ? 2 : 1;
    const bonus = excellent ? 1 : 0;
    const awarded = basePoints * multiplier + bonus;

    const ctx = window.scoreContext || { dailyCap: 25, dailyRemaining: 25, dailyPointsToday: 0 };
    const remaining = Math.max(0, ctx.dailyRemaining);
    const toBalance = Math.min(awarded, remaining);
    const toPool = awarded - toBalance;

    totalElement.textContent = `+${awarded} 分`;

    let formula = isSelf
      ? `基础 ${basePoints} 分 × 2（自觉完成）`
      : `基础 ${basePoints} 分（提醒后完成）`;

    if (excellent) {
      formula += ' + 1 分（特别好）';
    }

    formulaElement.textContent = `${formula} = ${awarded} 分`;

    if (breakdownElement && balanceElement && poolElement && remainingElement) {
      balanceElement.textContent = `+${toBalance} 分`;
      poolElement.textContent = `+${toPool} 分`;

      if (toPool > 0) {
        poolElement.style.color = '#b45309';
        poolElement.style.fontWeight = '800';
        if (poolRowElement) poolRowElement.style.background = '#fff7ed';
      } else {
        poolElement.style.color = '#9ca3af';
        poolElement.style.fontWeight = 'normal';
        if (poolRowElement) poolRowElement.style.background = 'transparent';
      }

      if (remaining === 0) {
        remainingElement.textContent = `今日余额额度已用完（上限 ${ctx.dailyCap} 分），本次全部进入季度奖池`;
        remainingElement.style.color = '#b45309';
      } else {
        remainingElement.textContent = `今日余额额度剩余 ${remaining} / ${ctx.dailyCap} 分`;
        remainingElement.style.color = '#64748b';
      }

      breakdownElement.hidden = false;
    }
  }

  form.addEventListener('change', updateScorePreview);
  updateScorePreview();
})();
</script>
</body>
</html>