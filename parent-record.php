<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$children = db()->query('SELECT * FROM children ORDER BY id')->fetchAll();
$selectedChildId = isset($_GET['child_id']) ? (int)$_GET['child_id'] : ($children[0]['id'] ?? 0);

$stmt = db()->prepare(
    'SELECT * FROM behaviors
     WHERE child_id = ? AND is_active = 1
     ORDER BY id'
);
$stmt->execute([$selectedChildId]);
$behaviors = $stmt->fetchAll();

$childEmoji = [
    1 => '👦',
    2 => '👧'
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>记录积分</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <main class="parent">
    <a class="back" href="parent-dashboard.php">← 返回控制台</a>
    <h1>记录积分</h1>

    <section class="selector">
      <p class="section-title">选择孩子</p>
      <div class="child-buttons">
        <?php foreach ($children as $child): ?>
          <a
            class="child-button <?= $child['id'] === $selectedChildId ? 'active' : '' ?>"
            href="?child_id=<?= (int)$child['id'] ?>"
          >
            <span class="child-emoji">
              <?= $childEmoji[$child['id']] ?? '🧒' ?>
            </span>
            <?= htmlspecialchars($child['name']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </section>

    <form method="post" action="record-point.php" class="record-form">
      <?= csrfField() ?>
      <input type="hidden" name="child_id" value="<?= (int)$selectedChildId ?>">

      <p class="section-title">选择行为</p>
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
            <span class="behavior-emoji"><?= htmlspecialchars($behavior['emoji']) ?></span>
            <span class="behavior-label"><?= htmlspecialchars($behavior['label']) ?></span>
            <span class="behavior-points">+<?= (int)$behavior['base_points'] ?></span>
          </label>
        <?php endforeach; ?>
      </div>

      <fieldset class="quality-group">
        <legend>完成方式</legend>

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

  <small>点击下方按钮后才会提交；超出每日上限的部分自动进入季度奖池。</small>
</section>

<button type="submit" class="record-submit">
  <span class="plus">+</span> 记录积分
</button>
    </form>
  </main>

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
(() => {
  const form = document.querySelector('.record-form');
  const totalElement = document.getElementById('score-preview-total');
  const formulaElement = document.getElementById('score-preview-formula');

  if (!form || !totalElement || !formulaElement) return;

  function updateScorePreview() {
    const behavior = form.querySelector(
      'input[name="behavior_id"]:checked'
    );

    const completion = form.querySelector(
      'input[name="completion_base"]:checked'
    );

    const excellent = form.querySelector(
      'input[name="excellent"]'
    )?.checked ?? false;

    if (!behavior || !completion) {
      totalElement.textContent = '—';

      formulaElement.textContent = !behavior
        ? '请选择行为'
        : '请选择完成方式';

      return;
    }

    const basePoints = Number(behavior.dataset.basePoints);
    const isSelf = completion.value === 'self_initiated';
    const multiplier = isSelf ? 2 : 1;
    const bonus = excellent ? 1 : 0;
    const total = basePoints * multiplier + bonus;

    totalElement.textContent = `+${total} 分`;

    let formula = isSelf
      ? `基础 ${basePoints} 分 × 2（自觉完成）`
      : `基础 ${basePoints} 分（提醒后完成）`;

    if (excellent) {
      formula += ' + 1 分（特别好）';
    }

    formulaElement.textContent = `${formula} = ${total} 分`;
  }

  form.addEventListener('change', updateScorePreview);
  updateScorePreview();
})();
</script>
</body>
</html>