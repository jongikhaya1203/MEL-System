<?php
/**
 * Role -> module access reference data, PLUS the per-user module denial
 * override, enforced centrally in require_login() (includes/auth.php).
 *
 * Two layers, deliberately kept separate:
 *   1. Role-based access (module_access_definitions() / user_accessible_
 *      modules()) — the real, primary access control, enforced by every
 *      require_role()/require_login() check across the codebase. This
 *      part is still DISPLAY-ONLY here: it documents what those checks
 *      already enforce; changing this data changes nothing by itself.
 *   2. Per-user denial override (user_module_denials table / is_module_
 *      denied() / sync_user_module_denials()) — a REAL, ENFORCED
 *      restriction that revokes a specific user's access to a specific
 *      module regardless of what their role would otherwise grant.
 *      Enforced in require_login(), which every require_role() call
 *      already goes through first — so this protects every existing
 *      protected page automatically, with no per-file sweep needed.
 *
 * Role tokens used in each level's allowed-roles list:
 *   'all'         - every logged-in role
 *   'admin'       - role = admin (matches a super admin too, since a
 *                   super admin's role genuinely is 'admin')
 *   'super_admin' - specifically the is_super_admin flag, excluding a
 *                   regular admin account
 *   any other string - an exact role slug (manager, viewer, field_worker,
 *                   back_office, approver, indigent_capturer, planner)
 *
 * Each module also carries a stable 'key' (used as user_module_denials.
 * module_key) and a 'match' rule (mirrors header.php's nav_is_active())
 * used to work out which module the CURRENT request belongs to.
 */
function module_access_definitions(): array
{
    return [
        'Dashboard' => ['key' => 'dashboard', 'href' => '/index.php', 'match' => ['exact' => ['/index.php']], 'levels' => [
            'View' => ['roles' => ['all']],
        ]],
        'Projects & Programmes' => ['key' => 'projects', 'href' => '/projects/list.php', 'match' => ['dir' => 'projects'], 'levels' => [
            'View' => ['roles' => ['all']],
            'Add / edit' => ['roles' => ['admin', 'manager']],
        ]],
        'Findings & Lessons Register' => ['key' => 'findings', 'href' => '/findings/list.php', 'match' => ['dir' => 'findings'], 'levels' => [
            'View' => ['roles' => ['all']],
            'Log / edit / close / revise deadline' => ['roles' => ['admin', 'manager']],
        ]],
        'Risk Register' => ['key' => 'risks', 'href' => '/risks/list.php', 'match' => ['dir' => 'risks'], 'levels' => [
            'View' => ['roles' => ['all']],
            'Log / edit / close / revise deadline' => ['roles' => ['admin', 'manager']],
        ]],
        'Results Framework (Indicators)' => ['key' => 'indicators', 'href' => '/indicators/framework.php', 'match' => ['dir' => 'indicators'], 'levels' => [
            'View' => ['roles' => ['all']],
            'Log / edit / report actual / confirm achievement / revise target' => ['roles' => ['admin', 'manager']],
        ]],
        'Planning & Dispatch' => ['key' => 'planning', 'href' => '/planning/list.php', 'match' => ['dir' => 'planning'], 'levels' => [
            'View' => ['roles' => ['all']],
            'Dispatch / edit / revise deadline' => ['roles' => ['admin', 'manager', 'planner']],
            'Log progress on an allocation' => ['roles' => ['admin', 'manager', 'back_office', 'field_worker'], 'note' => 'Installer (field_worker): only on an allocation assigned to them'],
        ]],
        'Site Completions & Payment Approval' => ['key' => 'sites', 'href' => '/sites/list.php', 'match' => ['dir' => 'sites'], 'levels' => [
            'View' => ['roles' => ['all']],
            'Submit a completed site' => ['roles' => ['admin', 'field_worker']],
            'Verify a submitted site' => ['roles' => ['admin', 'back_office']],
            'Approve a verified site' => ['roles' => ['admin', 'approver']],
        ]],
        'Indigent Applications' => ['key' => 'indigent', 'href' => '/indigent/list.php', 'match' => ['dir' => 'indigent'], 'levels' => [
            'View' => ['roles' => ['all']],
            'Capture / edit' => ['roles' => ['admin', 'manager', 'field_worker', 'indigent_capturer']],
            'Bulk import' => ['roles' => ['admin', 'manager']],
            'Permanently delete' => ['roles' => ['super_admin']],
        ]],
        'AI Reports' => ['key' => 'ai_reports', 'href' => '/ai_reports/list.php', 'match' => ['dir' => 'ai_reports'], 'levels' => [
            'View / generate' => ['roles' => ['admin', 'manager']],
        ]],
        'Data Captured' => ['key' => 'data_captured', 'href' => '/reports/data_captured.php', 'match' => ['exact' => ['/reports/data_captured.php']], 'levels' => [
            'View' => ['roles' => ['admin']],
        ]],
        'Users' => ['key' => 'users', 'href' => '/users/list.php', 'match' => ['exact' => ['/users/list.php']], 'levels' => [
            'Manage non-admin-tier accounts' => ['roles' => ['admin']],
            'Manage admin-tier accounts, or grant Super Admin' => ['roles' => ['super_admin']],
        ]],
        'Access Review' => ['key' => 'access_review', 'href' => '/users/access_review.php', 'match' => ['exact' => ['/users/access_review.php']], 'levels' => [
            'View / confirm reviewed' => ['roles' => ['super_admin']],
        ]],
        'Settings & branding (incl. SMTP)' => ['key' => 'settings', 'href' => '/settings/edit.php', 'match' => ['dir' => 'settings'], 'levels' => [
            'Manage' => ['roles' => ['super_admin']],
        ]],
        'CMS Pages' => ['key' => 'cms', 'href' => '/cms/directory.php', 'match' => ['dir' => 'cms'], 'levels' => [
            'View published pages' => ['roles' => ['all']],
            'Add / edit' => ['roles' => ['admin']],
            'Permanently delete' => ['roles' => ['super_admin']],
        ]],
        'Documentation (Training Manual, Architecture docs)' => ['key' => 'docs', 'href' => '/docs/index.php', 'match' => ['dir' => 'docs'], 'levels' => [
            'View' => ['roles' => ['admin']],
        ]],
    ];
}

/** True if a user with this role (and super-admin flag) qualifies for one level's allowed-roles list. */
function role_matches_access(string $role, bool $isSuperAdmin, array $allowedRoles): bool
{
    foreach ($allowedRoles as $token) {
        if ($token === 'all') {
            return true;
        }
        if ($token === 'super_admin') {
            if ($isSuperAdmin) {
                return true;
            }
            continue;
        }
        if ($token === $role) {
            return true;
        }
    }
    return false;
}

/** Human-readable "who" text for one level, generated from its role tokens — used by the reference matrix display. */
function access_level_who_text(array $allowedRoles): string
{
    if (in_array('all', $allowedRoles, true)) {
        return 'Every logged-in role';
    }
    $labels = array_map(function (string $token) {
        return $token === 'super_admin' ? 'Super Admin only' : role_label($token);
    }, $allowedRoles);
    return implode(', ', $labels);
}

/**
 * Resolves exactly which modules, and which access levels within them, a
 * given role (+ super-admin flag) can reach — with each module's link and
 * key, so this can be shown directly on a user's profile or review row.
 * Only modules where at least one level matches are included. Pass
 * $deniedKeys (module_key => true) to mark which of those are currently
 * denied for display — denial is enforced elsewhere (require_login()),
 * this is just so the profile page can show it struck through.
 */
function user_accessible_modules(string $role, bool $isSuperAdmin, array $deniedKeys = []): array
{
    $accessible = [];
    foreach (module_access_definitions() as $module => $def) {
        $levels = [];
        foreach ($def['levels'] as $levelName => $level) {
            if (role_matches_access($role, $isSuperAdmin, $level['roles'])) {
                $levels[] = $levelName;
            }
        }
        if ($levels) {
            $accessible[] = [
                'module' => $module, 'href' => $def['href'], 'key' => $def['key'], 'levels' => $levels,
                'denied' => !empty($deniedKeys[$def['key']]),
            ];
        }
    }
    return $accessible;
}

/** Back-compat shape for the reference-matrix display: module => level => "who" prose. */
function module_access_matrix(): array
{
    $matrix = [];
    foreach (module_access_definitions() as $module => $def) {
        foreach ($def['levels'] as $levelName => $level) {
            $who = access_level_who_text($level['roles']);
            if (!empty($level['note'])) {
                $who .= ' (' . $level['note'] . ')';
            }
            $matrix[$module][$levelName] = $who;
        }
    }
    return $matrix;
}

/** Which module (by key) a request path belongs to, mirroring header.php's nav_is_active() matching. Null if none. */
function module_key_for_path(string $relPath): ?string
{
    foreach (module_access_definitions() as $def) {
        if (isset($def['match']['exact']) && in_array($relPath, $def['match']['exact'], true)) {
            return $def['key'];
        }
    }
    foreach (module_access_definitions() as $def) {
        if (isset($def['match']['dir']) && strpos($relPath, '/' . $def['match']['dir'] . '/') === 0) {
            return $def['key'];
        }
    }
    return null;
}

/** The real, enforced check — used by require_login(). */
function is_module_denied(PDO $pdo, int $userId, string $moduleKey): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM user_module_denials WHERE user_id = ? AND module_key = ?');
    $stmt->execute([$userId, $moduleKey]);
    return (bool)$stmt->fetchColumn();
}

/** module_key => true for every module currently denied for this user — for display on their profile. */
function user_denied_module_keys(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT module_key FROM user_module_denials WHERE user_id = ?');
    $stmt->execute([$userId]);
    return array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
}

/**
 * Replaces a user's full set of denied modules with exactly $moduleKeys
 * (only keys the role actually has access to are meaningful, but any
 * stored key is harmless even if the role later changes). Never call
 * this with the acting admin's own user id — self-denial is blocked by
 * the caller (users/list.php) to avoid an admin locking themselves out.
 */
function sync_user_module_denials(PDO $pdo, int $userId, array $moduleKeys, int $deniedBy): void
{
    $pdo->prepare('DELETE FROM user_module_denials WHERE user_id = ?')->execute([$userId]);
    $stmt = $pdo->prepare('INSERT INTO user_module_denials (user_id, module_key, denied_by) VALUES (?, ?, ?)');
    foreach (array_unique($moduleKeys) as $key) {
        $stmt->execute([$userId, $key, $deniedBy]);
    }
}
