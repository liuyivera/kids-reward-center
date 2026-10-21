<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');

    if (defined('APP_ENV') && APP_ENV === 'production') {
        ini_set('session.cookie_secure', '1');
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
    }

    session_start();
}

/**
 * 渲染友好的双语错误提示页面
 */
function renderErrorPage(
    string $titleZh, string $titleEn,
    string $msgZh, string $msgEn,
    int $statusCode = 400,
    string $backUrl = 'index.php',
    string $btnZh = '返回首页', string $btnEn = 'Back to Home'
): void {
    http_response_code($statusCode);
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title><?= htmlspecialchars($titleZh) ?> - 家庭积分世界</title>
      <style>
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; background:#f4f5f7; font-family:-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding:20px; box-sizing:border-box; }
        .error-card { background:#fff; width:100%; max-width:440px; border-radius:24px; padding:40px 32px; box-shadow:0 10px 40px rgba(15,23,42,0.08); text-align:center; position:relative; }
        .lang-toggle { position:absolute; top:20px; right:20px; background:#f1f5f9; border:1px solid #cbd5e1; border-radius:999px; padding:4px 12px; font-size:12px; font-weight:700; color:#475569; cursor:pointer; }
        .error-icon { font-size:48px; margin-bottom:16px; line-height:1; }
        .error-title { margin:0 0 12px; font-size:22px; color:#0f172a; font-weight:800; }
        .error-desc { margin:0 0 32px; font-size:15px; color:#64748b; line-height:1.6; }
        .error-btn { display:inline-flex; align-items:center; justify-content:center; width:100%; padding:14px; border-radius:12px; background:#2563eb; color:#fff; text-decoration:none; font-size:16px; font-weight:700; transition:background 0.2s; box-sizing:border-box; }
        .error-btn:hover { background:#1d4ed8; }
      </style>
    </head>
    <body>
      <main class="error-card">
        <button class="lang-toggle" id="langBtn" onclick="toggleLang()">EN</button>
        <div class="error-icon">🛑</div>
        <h1 class="error-title" id="errTitle"><?= htmlspecialchars($titleZh) ?></h1>
        <p class="error-desc" id="errMsg"><?= htmlspecialchars($msgZh) ?></p>
        <a href="<?= htmlspecialchars($backUrl) ?>" class="error-btn" id="errBtn"><?= htmlspecialchars($btnZh) ?></a>
      </main>

      <script>
        const texts = {
          zh: {
            title: <?= json_encode($titleZh, JSON_UNESCAPED_UNICODE) ?>,
            msg: <?= json_encode($msgZh, JSON_UNESCAPED_UNICODE) ?>,
            btn: <?= json_encode($btnZh, JSON_UNESCAPED_UNICODE) ?>
          },
          en: {
            title: <?= json_encode($titleEn, JSON_UNESCAPED_UNICODE) ?>,
            msg: <?= json_encode($msgEn, JSON_UNESCAPED_UNICODE) ?>,
            btn: <?= json_encode($btnEn, JSON_UNESCAPED_UNICODE) ?>
          }
        };

        let lang = localStorage.getItem("family-points-language") || "zh";

        function applyLang() {
          document.getElementById("errTitle").textContent = texts[lang].title;
          document.getElementById("errMsg").textContent = texts[lang].msg;
          document.getElementById("errBtn").textContent = texts[lang].btn;
          document.getElementById("langBtn").textContent = lang === "zh" ? "EN" : "中文";
          document.title = texts[lang].title + " - 家庭积分世界";
        }

        function toggleLang() {
          lang = lang === "zh" ? "en" : "zh";
          localStorage.setItem("family-points-language", lang);
          applyLang();
        }

        applyLang();
      </script>
    </body>
    </html>
    <?php
    exit;
}

if (empty($_SESSION['parent_id'])) {
    $hasChildAccess = validChildAccessRequest();
    $hasChildRequestParams = ! empty($_GET['child_id'])
        || ! empty($_GET['access_code'])
        || ! empty($_POST['child_id'])
        || ! empty($_POST['access_code']);

    if (! $hasChildAccess) {
            if ($hasChildRequestParams) {
            renderErrorPage(
                '无法访问', 'Access Denied',
                '无效的孩子访问码或链接已过期。请联系家长重新获取。', 'Invalid access code or link has expired. Please contact your parent.',
                403, 'shop-login.php',
                '重新输入 PIN', 'Re-enter PIN'
            );
        }

        header('Location: parent-login.php');
        exit;
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function currentParent(): ?array
{
    static $cached = false;
    static $user = null;

    if ($cached) {
        return $user;
    }

    $cached = true;

    if (empty($_SESSION['parent_id'])) {
        return $user = null;
    }

    $stmt = db()->prepare('SELECT * FROM parent_users WHERE id = ?');
    $stmt->execute([$_SESSION['parent_id']]);
    $row = $stmt->fetch();

    if ($row === false || $row === null) {
        return $user = null;
    }

    return $user = $row;
}

function currentParentFamilyId(): ?int
{
    $user = currentParent();
    if ($user === null) {
        return null;
    }

    if (isAppCreator() && ! empty($_SESSION['dev_family_id'])) {
        $requestedFamilyId = (int) $_SESSION['dev_family_id'];
        if ($requestedFamilyId > 0) {
            $familyCheck = db()->prepare('SELECT id FROM families WHERE id = ?');
            $familyCheck->execute([$requestedFamilyId]);
            if ($familyCheck->fetch()) {
                return $requestedFamilyId;
            }
        }
    }

    $familyId = $user['family_id'] ?? null;
    if ($familyId === null || $familyId === false) {
        return null;
    }

    return (int) $familyId;
}

function appCreatorParentIds(): array
{
    $values = [];

    if (defined('APP_CREATOR_PARENT_IDS') && is_array(APP_CREATOR_PARENT_IDS)) {
        foreach (APP_CREATOR_PARENT_IDS as $id) {
            $value = (int) $id;
            if ($value > 0) {
                $values[] = $value;
            }
        }
    }

    if (defined('APP_CREATOR_PARENT_ID') && (int) APP_CREATOR_PARENT_ID > 0) {
        $values[] = (int) APP_CREATOR_PARENT_ID;
    }

    if (! $values) {
        return [];
    }

    return array_values(array_unique($values));
}

function isAppCreator(?int $parentId = null): bool
{
    static $cache = [];

    $parentId = $parentId ?? (int)($_SESSION['parent_id'] ?? 0);

    if ($parentId <= 0) {
        return false;
    }

    if (array_key_exists($parentId, $cache)) {
        return $cache[$parentId];
    }

    if (in_array($parentId, appCreatorParentIds(), true)) {
        return $cache[$parentId] = true;
    }

    try {
        $stmt = db()->prepare('SELECT is_creator FROM parent_users WHERE id = ?');
        $stmt->execute([$parentId]);
        $value = $stmt->fetchColumn();

        if ($value === false || $value === null) {
            return $cache[$parentId] = false;
        }

        return $cache[$parentId] = (bool) $value;
    } catch (Throwable $e) {
        return $cache[$parentId] = false;
    }
}

function requireAppCreator(): void
{
        if (! isAppCreator()) {
        renderErrorPage(
            '权限不足', 'Insufficient Permissions',
            '此操作仅限应用创建者。', 'This action is restricted to the app creator.',
            403, 'index.php', '返回首页', 'Back to Home'
        );
    }
}

function requireParentAccessToChild(int $childId): void
{
    $familyId = currentParentFamilyId();

    if ($familyId === null) {
        renderErrorPage(
            '无权访问', 'Unauthorized',
            '您当前没有绑定任何家庭。', 'You are not currently associated with any family.',
            403, 'index.php', '返回首页', 'Back to Home'
        );
    }

    $stmt = db()->prepare('SELECT 1 FROM children WHERE id = ? AND family_id = ?');
    $stmt->execute([$childId, $familyId]);

    if (! $stmt->fetch()) {
        renderErrorPage(
            '无权访问', 'Unauthorized',
            '无权访问此数据。', 'You do not have permission to access this data.',
            403, 'parent-dashboard.php', '返回控制台', 'Back to Dashboard'
        );
    }
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars($_SESSION['csrf_token'])
        . '">';
}

function verifyCsrf(): void
{
        if (! hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        renderErrorPage(
            '会话已过期', 'Session Expired',
            '会话已过期，已为您刷新安全令牌。请返回重试。', 'Your session has expired. A new token has been issued. Please go back and try again.',
            403, 'javascript:history.back()', '返回重试', 'Go Back and Retry'
        );
    }
}

function creatorFamilyOptions(): array
{
    $stmt = db()->prepare('SELECT id, name FROM families ORDER BY id');
    $stmt->execute();
    return $stmt->fetchAll();
}

function renderCreatorDevTool(): string
{
    if (! isAppCreator()) {
        return '';
    }

    $selectedFamilyId = (int)($_SESSION['dev_family_id'] ?? currentParentFamilyId() ?? 0);
    $families = creatorFamilyOptions();
    $currentPage = basename((string)($_SERVER['PHP_SELF'] ?? 'parent-dashboard.php'));

    $options = '';
    foreach ($families as $family) {
        $familyId = (int)($family['id'] ?? 0);
        $selected = $selectedFamilyId === $familyId ? ' selected' : '';
        $options .= '<option value="' . (int)$familyId . '"' . $selected . '>'
            . htmlspecialchars($family['name'] ?? '家庭')
            . '</option>';
    }

    return '<form class="family-switcher" method="post" action="'
        . htmlspecialchars($currentPage, ENT_QUOTES, 'UTF-8') . '">'
        . csrfField()
        . '<input type="hidden" name="action" value="switch_dev_family">'
        . '<input type="hidden" name="redirect_to" value="'
        . htmlspecialchars($currentPage, ENT_QUOTES, 'UTF-8') . '">'
        . '<select class="family-switcher-select" name="dev_family_id" aria-label="切换家庭" onchange="this.form.submit()">'
        . $options
        . '</select></form>';
}

function renderFamilyWelcomeBanner(): string
{
    $familyName = currentFamilyName();

    if ($familyName === null || $familyName === '') {
        $familyName = '家庭';
    }

    return '<div class="family-welcome-banner">欢迎【' . htmlspecialchars($familyName) . '】</div>';
}

function currentFamilyName(): ?string
{
    $familyId = currentParentFamilyId();
    if ($familyId === null) {
        return null;
    }

    $stmt = db()->prepare('SELECT name FROM families WHERE id = ?');
    $stmt->execute([$familyId]);
    $name = $stmt->fetchColumn();

    return $name === false || $name === null ? null : (string) $name;
}

function validChildAccessRequest(): bool
{
    if (! empty($_SESSION['parent_id'])) {
        return true;
    }

    $childId = (int)($_GET['child_id'] ?? $_POST['child_id'] ?? 0);
    $code = trim((string)($_GET['access_code'] ?? $_POST['access_code'] ?? ''));

    if ($childId <= 0 || $code === '') {
        return false;
    }

    $stmt = db()->prepare(
        'SELECT 1 FROM children
         WHERE id = ?
           AND access_code = ?
           AND (access_code_expires_at IS NULL OR access_code_expires_at > NOW())'
    );
    $stmt->execute([$childId, $code]);

    return (bool) $stmt->fetchColumn();
}

function currentChildAccessCodeContext(?int $childId = null, ?string $code = null): ?array
{
    $childId = $childId ?? (int)($_GET['child_id'] ?? 0);
    $code = $code ?? trim((string)($_GET['access_code'] ?? ''));

    if ($childId <= 0 || $code === '') {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT id, family_id, name, theme, normal_balance, daily_points_today,
                daily_cap, quarterly_pool, quarterly_goal
         FROM children
         WHERE id = ?
           AND access_code = ?
           AND (access_code_expires_at IS NULL OR access_code_expires_at > NOW())'
    );
    $stmt->execute([$childId, $code]);
    $child = $stmt->fetch();

    if ($child === false || $child === null) {
        return null;
    }

    return $child;
}

/**
 * 生成 6 位数字 PIN（000000-999999）。
 * 比原来的 8 位 hex 更容易让孩子记住。
 */
function generateChildAccessCode(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * 为孩子生成唯一 PIN 并写入数据库。
 * 6 位数字只有 100 万种可能，所以要查重避免碰撞。
 */
function issueChildAccessCode(int $childId): string
{
    $pdo = db();
    $maxAttempts = 20;

    for ($i = 0; $i < $maxAttempts; $i++) {
        $code = generateChildAccessCode();

        $check = $pdo->prepare('SELECT 1 FROM children WHERE access_code = ? AND id != ?');
        $check->execute([$code, $childId]);
        if ($check->fetchColumn()) {
            continue;
        }

        $expiresAt = (new DateTimeImmutable('+365 days'))->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            'UPDATE children
             SET access_code = ?, access_code_expires_at = ?
             WHERE id = ?'
        );
        $stmt->execute([$code, $expiresAt, $childId]);

        return $code;
    }

    throw new RuntimeException('无法生成唯一 PIN，请稍后再试。');
}

function childAccessUrl(int $childId, string $code): string
{
    return 'shop.php?child_id=' . (int)$childId . '&access_code=' . rawurlencode($code);
}

if (empty($_SESSION['parent_id'])) {
    $childId = (int)($_GET['child_id'] ?? $_POST['child_id'] ?? 0);
    $accessCode = trim((string)($_GET['access_code'] ?? $_POST['access_code'] ?? ''));

    if ($childId > 0 && $accessCode !== '') {
        $_SESSION['child_access_child_id'] = $childId;
        $_SESSION['child_access_code'] = $accessCode;
    }
}