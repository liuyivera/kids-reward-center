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

if (empty($_SESSION['parent_id'])) {
    $hasChildAccess = validChildAccessRequest();
    $hasChildRequestParams = ! empty($_GET['child_id'])
        || ! empty($_GET['access_code'])
        || ! empty($_POST['child_id'])
        || ! empty($_POST['access_code']);

    if (! $hasChildAccess) {
        if ($hasChildRequestParams) {
            http_response_code(403);
            exit('无效的孩子访问码或链接已过期。请联系家长重新获取。');
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
        http_response_code(403);
        exit('此操作仅限应用创建者。');
    }
}

function requireParentAccessToChild(int $childId): void
{
    $familyId = currentParentFamilyId();

    if ($familyId === null) {
        http_response_code(403);
        exit('无权访问此数据。');
    }

    $stmt = db()->prepare('SELECT 1 FROM children WHERE id = ? AND family_id = ?');
    $stmt->execute([$childId, $familyId]);

    if (! $stmt->fetch()) {
        http_response_code(403);
        exit('无权访问此数据。');
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
        http_response_code(403);
        exit('会话已过期，已为您刷新安全令牌。请返回重试。');
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