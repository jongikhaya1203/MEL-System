<?php
require_once __DIR__ . '/../config/config.php';

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: ' . APP_BASE_URL . '/login.php');
        exit;
    }
    $user = current_user();
    $current = basename($_SERVER['SCRIPT_NAME']);
    if (!empty($user['must_change_password']) && $current !== 'change_password.php' && $current !== 'logout.php') {
        header('Location: ' . APP_BASE_URL . '/change_password.php');
        exit;
    }

    // Per-user module denial override — enforced here so every existing
    // require_role() call (which always calls require_login() first) is
    // protected automatically, with no per-file sweep. $GLOBALS is used
    // rather than adding a $pdo parameter, so require_login()'s signature
    // (called with no arguments from ~50 existing pages) never changes.
    // See includes/access_matrix.php for module_key_for_path()/is_module_denied().
    $pdo = $GLOBALS['pdo'] ?? null;
    if ($pdo instanceof PDO) {
        $relPath = substr($_SERVER['SCRIPT_NAME'], strlen(APP_BASE_URL));
        $moduleKey = module_key_for_path($relPath);
        if ($moduleKey !== null && is_module_denied($pdo, $user['id'], $moduleKey)) {
            http_response_code(403);
            die('Your access to this module has been restricted by an administrator. Contact a super admin if you believe this is a mistake.');
        }
    }
}

/**
 * @param string[] $roles
 */
function require_role(array $roles): void
{
    require_login();
    $user = current_user();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        die('You do not have permission to access this page.');
    }
}

/** True only for role='admin' AND the is_super_admin flag — see schema.sql's note on users.is_super_admin. */
function is_super_admin(): bool
{
    $user = current_user();
    return $user !== null && $user['role'] === 'admin' && !empty($user['is_super_admin']);
}

/**
 * For the handful of actions MORE restrictive than regular admin: managing
 * other admin-tier accounts, system configuration, irreversible deletes.
 */
function require_super_admin(): void
{
    require_login();
    if (!is_super_admin()) {
        http_response_code(403);
        die('This action is restricted to a super admin account.');
    }
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'role' => $user['role'],
        'is_super_admin' => (bool)($user['is_super_admin'] ?? false),
        'must_change_password' => (bool)($user['must_change_password'] ?? false),
    ];
}

function logout_user(): void
{
    $_SESSION = [];
    session_destroy();
}
