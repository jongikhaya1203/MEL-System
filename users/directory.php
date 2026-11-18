<?php
/**
 * Super admin > Directory Access (Active Directory).
 * Tabs (?tab=): users (default) | grant | mappings | connection | audit
 * POST actions: save_config, test_connection, test_login, add_mapping, delete_mapping,
 *               grant, revoke, restore, role_source, sync_all, sync_user
 * Logic lives in includes/directory.php.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_super_admin();
$me = current_user();

$ROLES = ['admin', 'manager', 'viewer', 'field_worker', 'back_office', 'approver', 'indigent_capturer', 'planner'];
$tabs = ['users' => 'Directory users', 'grant' => 'Search & grant access', 'mappings' => 'Group → role mappings', 'connection' => 'Connection', 'audit' => 'Audit log'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'users';
$back = fn(string $t, string $extra = '') => redirect('/users/directory.php?tab=' . $t . $extra);
$cfg = ad_settings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_config') {
        $hosts = trim((string)($_POST['hosts'] ?? ''));
        $baseDn = trim((string)($_POST['base_dn'] ?? ''));
        $bindDn = trim((string)($_POST['bind_dn'] ?? ''));
        $filter = trim((string)($_POST['user_filter'] ?? '')) ?: '(&(objectCategory=person)(objectClass=user))';
        $port = (int)($_POST['port'] ?? 0);
        $enc = in_array($_POST['encryption'] ?? '', ['ldaps', 'starttls', 'none'], true) ? $_POST['encryption'] : 'ldaps';
        $enabled = isset($_POST['enabled']) ? 1 : 0;
        $errors = [];
        if ($hosts !== '' && !preg_match('/^[A-Za-z0-9.\-\s,;]+$/', $hosts)) {
            $errors[] = 'Domain controllers must be host names or IP addresses (no ldap:// prefix or port).';
        }
        if ($port < 1 || $port > 65535) {
            $errors[] = 'Port must be between 1 and 65535.';
        }
        if (substr($filter, 0, 1) !== '(' || substr_count($filter, '(') !== substr_count($filter, ')')) {
            $errors[] = 'The user filter must be a valid, parenthesised LDAP filter.';
        }
        if ($enabled && ($hosts === '' || $baseDn === '' || $bindDn === '')) {
            $errors[] = 'Domain controller, base DN and service account are required before directory sign-in can be switched on.';
        }
        if ($errors) {
            flash('error', implode(' ', $errors));
            $back('connection');
        }
        $pw = (string)($_POST['bind_password'] ?? '');
        $storedPw = $pdo->query('SELECT bind_password FROM ad_settings WHERE id = 1')->fetchColumn();
        $newPw = $pw !== '' ? encrypt_secret($pw) : $storedPw;
        $pdo->prepare('UPDATE ad_settings SET enabled = ?, hosts = ?, port = ?, encryption = ?, verify_cert = ?, base_dn = ?, bind_dn = ?, bind_password = ?,
                       user_filter = ?, upn_suffix = ?, nested_groups = ?, auto_provision = ?, sync_deactivate = ?, timeout_seconds = ?, updated_by = ? WHERE id = 1')
            ->execute([$enabled, $hosts ?: null, $port, $enc, isset($_POST['verify_cert']) ? 1 : 0, $baseDn ?: null, $bindDn ?: null, $newPw,
                       $filter, trim((string)($_POST['upn_suffix'] ?? ''), " @") ?: null, isset($_POST['nested_groups']) ? 1 : 0,
                       isset($_POST['auto_provision']) ? 1 : 0, isset($_POST['sync_deactivate']) ? 1 : 0, max(1, min(30, (int)($_POST['timeout_seconds'] ?? 5))), $me['id']]);
        access_audit($pdo, 'ad_config', null, 'Directory settings saved; sign-in ' . ($enabled ? 'ON' : 'OFF') . ($pw !== '' ? '; service account password changed' : ''));
        flash('success', 'Directory settings saved.' . ($enabled ? '' : ' Directory sign-in is OFF.'));
        $back('connection');
    }

    if ($action === 'test_connection') {
        $err = null;
        $t0 = microtime(true);
        $link = ad_connect($cfg, $err);
        if (!$link) {
            flash('error', 'Connection test failed: ' . $err);
        } else {
            $sample = ad_search($link, $cfg, $cfg['user_filter'], 5);
            flash('success', sprintf('Connected and bound as the service account in %.0f ms. Base DN is searchable (%d sample user%s found).',
                (microtime(true) - $t0) * 1000, count($sample), count($sample) === 1 ? '' : 's'));
            @ldap_unbind($link);
        }
        $back('connection');
    }

    if ($action === 'test_login') {
        $login = trim((string)($_POST['test_username'] ?? ''));
        $pw = (string)($_POST['test_password'] ?? '');
        $result = ['login' => $login, 'steps' => []];
        $err = null;
        $link = ad_connect($cfg, $err);
        if (!$link) {
            $result['steps'][] = ['fail', 'Service account bind: ' . $err];
        } else {
            $result['steps'][] = ['ok', 'Service account bind succeeded'];
            $entry = ad_find_user($link, $cfg, $login);
            if (!$entry) {
                $result['steps'][] = ['fail', 'User not found (or more than one match) under the base DN with the user filter'];
            } else {
                $result['steps'][] = ['ok', "Found {$entry['name']} ({$entry['username']}) — {$entry['dn']}"];
                $result['steps'][] = [$entry['disabled'] ? 'fail' : 'ok', $entry['disabled'] ? 'Account is DISABLED in AD' : 'Account is enabled'];
                if ($pw === '') {
                    $result['steps'][] = ['skip', 'Password not supplied — credential check skipped'];
                } else {
                    $ul = ad_connect(['bind_dn' => $entry['dn'], 'bind_password' => $pw] + $cfg, $err);
                    $result['steps'][] = $ul ? ['ok', 'Password accepted by AD'] : ['fail', 'Password rejected: ' . $err];
                    if ($ul) {
                        @ldap_unbind($ul);
                    }
                }
                $access = ad_resolve_access(ad_matching_mappings($link, $cfg, $entry, ad_mappings($pdo)));
                $result['steps'][] = $access['role']
                    ? ['ok', 'Mapped access: ' . role_label($access['role']) . ' (via ' . $access['group'] . ')']
                    : ['fail', 'In no mapped AD group — would get no access unless granted a manual role'];
            }
            @ldap_unbind($link);
        }
        $_SESSION['ad_test_result'] = $result;
        $back('connection', '#test-login');
    }

    if ($action === 'add_mapping') {
        $dn = trim((string)($_POST['group_dn'] ?? ''));
        $role = $_POST['role'] ?? '';
        $body = (int)($_POST['dt_body_id'] ?? 0);
        if (!preg_match('/^(CN|OU)=.+,DC=.+/i', $dn) || !in_array($role, $ROLES, true)) {
            flash('error', 'Enter the group\'s full distinguished name (e.g. CN=MEL-Managers,OU=Groups,DC=usaasa,DC=local) and choose a role.');
            $back('mappings');
        }
        $name = trim((string)($_POST['group_name'] ?? '')) ?: (preg_match('/^CN=([^,]+)/i', $dn, $m) ? $m[1] : $dn);
        $pdo->prepare('INSERT INTO ad_group_mappings (group_dn, group_name, role, dt_body_id, priority, created_by) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([mb_substr($dn, 0, 500), mb_substr($name, 0, 255), $role, $body ?: null, (int)($_POST['priority'] ?? 100), $me['id']]);
        access_audit($pdo, 'ad_mapping', null, "Added: $name → " . role_label($role));
        flash('success', "Mapping added: members of $name get " . role_label($role) . ' access. Run a sync to apply it to existing directory users.');
        $back('mappings');
    }

    if ($action === 'delete_mapping') {
        $stmt = $pdo->prepare('SELECT group_name, role FROM ad_group_mappings WHERE id = ?');
        $stmt->execute([(int)($_POST['mapping_id'] ?? 0)]);
        if ($m = $stmt->fetch()) {
            $pdo->prepare('DELETE FROM ad_group_mappings WHERE id = ?')->execute([(int)$_POST['mapping_id']]);
            access_audit($pdo, 'ad_mapping', null, "Removed: {$m['group_name']} → " . role_label($m['role']));
            flash('success', 'Mapping removed. Users who relied on it lose access at their next sign-in or sync.');
        }
        $back('mappings');
    }

    if ($action === 'grant') {
        $guid = (string)($_POST['guid'] ?? '');
        $choice = (string)($_POST['role'] ?? '');
        $err = null;
        $link = ad_connect($cfg, $err);
        if (!$link) {
            flash('error', $err);
            $back('grant');
        }
        $entry = ad_find_by_guid($link, $cfg, $guid); // re-read from AD; never trust posted details
        if (!$entry) {
            flash('error', 'That person could not be found in the directory any more.');
            $back('grant');
        }
        $q = '&q=' . urlencode((string)($_POST['q'] ?? ''));
        if ($entry['disabled']) {
            flash('error', "{$entry['name']}'s directory account is disabled — access not granted.");
            $back('grant', $q);
        }
        $access = ad_resolve_access(ad_matching_mappings($link, $cfg, $entry, ad_mappings($pdo)));
        if ($choice === 'directory') {
            if (!$access['role']) {
                flash('error', "{$entry['name']} is in no mapped AD group. Choose a specific role, or add a group mapping first.");
                $back('grant', $q);
            }
            $role = $access['role'];
            $source = 'directory';
        } elseif (in_array($choice, $ROLES, true)) {
            $role = $choice;
            $source = 'manual';
        } else {
            flash('error', 'Choose a role.');
            $back('grant', $q);
        }
        // An existing, unlinked local account with the same username/email is converted rather than duplicated.
        $stmt = $pdo->prepare("SELECT id FROM users WHERE ad_guid IS NULL AND auth_source = 'local' AND (username = ? OR (email IS NOT NULL AND email <> '' AND email = ?)) LIMIT 1");
        $stmt->execute([$entry['username'], $entry['email']]);
        $localId = (int)$stmt->fetchColumn();
        $note = '';
        if ($localId) {
            if ($localId === (int)$me['id']) {
                flash('error', 'That directory account matches your own local account. Ask another super admin to link it, so you keep a local sign-in.');
                $back('grant', $q);
            }
            $pdo->prepare("UPDATE users SET ad_guid = ?, auth_source = 'ad', must_change_password = 0 WHERE id = ?")->execute([$entry['guid'], $localId]);
            $note = ' (existing local account linked to AD)';
        }
        $id = ad_upsert_user($pdo, $entry, $role, $source, (int)$me['id']);
        ad_apply_governance($pdo, $id, $access['bodies'], (int)$me['id']);
        access_audit($pdo, 'ad_granted', $id, "Granted {$entry['name']} access as " . role_label($role) . ($source === 'directory' ? ' (follows AD groups)' : ' (manual role)') . $note);
        $site = get_site_settings($pdo);
        send_notification($pdo, 'ad_access_granted', [['email' => $entry['email'], 'name' => $entry['name']]],
            'You have been granted access to the ' . ($site['site_name'] ?? APP_NAME),
            "Hello {$entry['name']},\n\nYou now have access to the " . ($site['site_name'] ?? APP_NAME) . ' as ' . role_label($role) . ".\n\n"
            . "Sign in with your usual network (Windows) username and password.\n");
        flash('success', "{$entry['name']} can now sign in as " . role_label($role) . $note . '.');
        $back('grant', $q);
    }

    $targetId = (int)($_POST['user_id'] ?? 0);
    $target = null;
    if ($targetId) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND auth_source = 'ad'");
        $stmt->execute([$targetId]);
        $target = $stmt->fetch() ?: null;
    }
    if (in_array($action, ['revoke', 'restore', 'role_source', 'sync_user'], true) && !$target) {
        flash('error', 'Directory user not found.');
        $back('users');
    }
    if ($action === 'revoke') {
        if ($targetId === (int)$me['id']) {
            flash('error', 'You cannot revoke your own access.');
            $back('users');
        }
        $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$targetId]);
        access_audit($pdo, 'ad_revoked', $targetId, 'Access revoked for ' . $target['full_name']);
        flash('success', "{$target['full_name']}'s access has been revoked. Sync will not restore it.");
        $back('users');
    }
    if ($action === 'restore') {
        $pdo->prepare('UPDATE users SET is_active = 1, failed_login_attempts = 0, locked_until = NULL WHERE id = ?')->execute([$targetId]);
        access_audit($pdo, 'ad_restored', $targetId, 'Access restored for ' . $target['full_name']);
        flash('success', "{$target['full_name']}'s access has been restored.");
        $back('users');
    }
    if ($action === 'role_source') {
        $source = ($_POST['role_source'] ?? '') === 'directory' ? 'directory' : 'manual';
        $role = $_POST['role'] ?? $target['role'];
        if (!in_array($role, $ROLES, true)) {
            $role = $target['role'];
        }
        $pdo->prepare("UPDATE users SET role_source = ?, role = ?, is_super_admin = IF(? = 'admin', is_super_admin, 0) WHERE id = ?")->execute([$source, $role, $role, $targetId]);
        access_audit($pdo, 'ad_role_source', $targetId, "{$target['full_name']}: " . ($source === 'directory' ? 'role now follows AD groups' : 'manual role ' . role_label($role)));
        if ($source === 'directory') {
            ad_sync_users($pdo, $targetId, (int)$me['id']);
        }
        flash('success', 'Role updated for ' . $target['full_name'] . '.');
        $back('users');
    }
    if ($action === 'sync_user' || $action === 'sync_all') {
        $s = ad_sync_users($pdo, $action === 'sync_user' ? $targetId : null, (int)$me['id']);
        flash($s['ok'] ? 'success' : 'error', $s['ok']
            ? "Sync complete — checked {$s['checked']}, updated {$s['updated']}, role changes {$s['role_changes']}, deactivated {$s['deactivated']}."
            : 'Sync failed: ' . $s['error']);
        $back('users');
    }
}

// ---- view data ----
$mappings = ad_mappings($pdo);
$bodies = dt_bodies($pdo);
$configured = !empty($cfg['hosts']) && !empty($cfg['base_dn']) && !empty($cfg['bind_dn']);
$counts = $pdo->query("SELECT SUM(auth_source = 'ad' AND is_active = 1) AS ad_active, SUM(auth_source = 'ad' AND is_active = 0) AS ad_inactive,
                              SUM(auth_source = 'local' AND is_active = 1) AS local_active FROM users")->fetch();

$pageTitle = 'Directory Access';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Directory Access</h1>
        <p class="subtitle">Grant users access through Active Directory: network sign-in, group-based roles, and automatic deprovisioning</p>
    </div>
    <div>
        <?php if (!function_exists('ldap_connect')): ?><span class="badge badge-overdue">PHP LDAP extension missing</span>
        <?php elseif (!empty($cfg['enabled'])): ?><span class="badge badge-closed">Directory sign-in ON</span>
        <?php else: ?><span class="badge badge-overdue">Directory sign-in OFF</span><?php endif; ?>
    </div>
</div>

<?php if (!$configured && $tab !== 'connection'): ?>
    <div class="alert alert-info">Start under <a href="?tab=connection">Connection</a>: enter your domain controllers, base DN and a read-only service account, then test it.</div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-tile"><div class="stat-num"><?= (int)$counts['ad_active'] ?></div><div class="stat-label">Directory users with access</div></div>
    <div class="stat-tile"><div class="stat-num"><?= (int)$counts['ad_inactive'] ?></div><div class="stat-label">Directory users revoked</div></div>
    <div class="stat-tile"><div class="stat-num"><?= (int)$counts['local_active'] ?></div><div class="stat-label">Local accounts</div></div>
    <div class="stat-tile"><div class="stat-num"><?= count($mappings) ?></div><div class="stat-label">Group mappings</div></div>
</div>

<nav class="dt-tabs" aria-label="Directory access">
    <?php foreach ($tabs as $k => $label): ?><a href="?tab=<?= $k ?>" class="<?= $tab === $k ? 'is-active' : '' ?>"><?= e($label) ?></a><?php endforeach; ?>
</nav>

<?php if ($tab === 'users'):
    $users = $pdo->query("SELECT * FROM users WHERE auth_source = 'ad' ORDER BY is_active DESC, full_name")->fetchAll(); ?>
    <div class="card">
        <div class="page-header" style="margin-bottom:10px;">
            <div>
                <h2 style="margin:0;">Users signing in with Active Directory</h2>
                <p class="hint" style="margin:4px 0 0;">Last sync: <?= $cfg['last_sync_at'] ? format_datetime($cfg['last_sync_at']) . ' — ' . e($cfg['last_sync_summary']) : 'never' ?>.
                    Schedule <code>cron/ad_sync.php</code> to run nightly.</p>
            </div>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sync_all"><button type="submit" class="btn" <?= $configured ? '' : 'disabled' ?>>Sync all now</button></form>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Role source</th><th>Status</th><th>Last sync / sign-in</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><strong><?= e($u['full_name']) ?></strong><div class="hint"><?= e($u['email'] ?: 'no email in AD') ?></div></td>
                        <td><?= e($u['username']) ?></td>
                        <td><?= user_role_badge($u) ?></td>
                        <td>
                            <form method="post" style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="role_source">
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                <select name="role_source" aria-label="Role source" style="width:auto;">
                                    <option value="directory" <?= $u['role_source'] === 'directory' ? 'selected' : '' ?>>AD groups</option>
                                    <option value="manual" <?= $u['role_source'] === 'manual' ? 'selected' : '' ?>>Manual</option>
                                </select>
                                <select name="role" aria-label="Manual role" style="width:auto;">
                                    <?php foreach ($ROLES as $r): ?><option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= e(role_label($r)) ?></option><?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-secondary btn-sm">Apply</button>
                            </form>
                        </td>
                        <td><?= $u['is_active'] ? '<span class="badge badge-closed">Active</span>' : '<span class="badge badge-overdue">Revoked</span>' ?></td>
                        <td class="hint" style="font-size:12.5px;">Sync: <?= format_datetime($u['ad_synced_at']) ?><br>Sign-in: <?= format_datetime($u['last_login_at']) ?></td>
                        <td class="dt-actions">
                            <form method="post" class="dt-inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="sync_user"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button class="btn btn-secondary btn-sm">Sync</button></form>
                            <?php if ($u['is_active']): ?>
                                <form method="post" class="dt-inline-form" onsubmit="return confirm('Revoke <?= e($u['full_name']) ?>\'s access?');"><?= csrf_field() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button class="btn btn-danger btn-sm">Revoke</button></form>
                            <?php else: ?>
                                <form method="post" class="dt-inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button class="btn btn-secondary btn-sm">Restore</button></form>
                            <?php endif; ?>
                            <a class="btn btn-secondary btn-sm" href="<?= APP_BASE_URL ?>/users/list.php?edit=<?= (int)$u['id'] ?>">Module access</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?><tr><td colspan="7">No directory users yet. Grant access under <a href="?tab=grant">Search &amp; grant access</a>, or turn on automatic provisioning.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($tab === 'grant'):
    $q = trim((string)($_GET['q'] ?? ''));
    $results = [];
    $searchError = null;
    if ($q !== '') {
        $link = ad_connect($cfg, $searchError);
        if ($link) {
            $results = ad_search_people($link, $cfg, $q);
            foreach ($results as &$r) {
                $r['access'] = ad_resolve_access(ad_matching_mappings($link, $cfg, $r, $mappings));
            }
            unset($r);
            @ldap_unbind($link);
            $linked = [];
            if ($results) {
                $guids = array_column($results, 'guid');
                $stmt = $pdo->prepare('SELECT ad_guid, role, is_active, is_super_admin FROM users WHERE ad_guid IN (' . implode(',', array_fill(0, count($guids), '?')) . ')');
                $stmt->execute($guids);
                $linked = array_column($stmt->fetchAll(), null, 'ad_guid');
            }
        }
    } ?>
    <div class="card">
        <form method="get" class="form-grid">
            <input type="hidden" name="tab" value="grant">
            <div class="field">
                <label for="q">Find people in Active Directory</label>
                <input type="text" id="q" name="q" value="<?= e($q) ?>" placeholder="Name, username or email" required minlength="2">
            </div>
            <div class="field" style="align-self:end;"><button type="submit" class="btn" <?= $configured ? '' : 'disabled' ?>>Search directory</button></div>
        </form>
        <?php if ($searchError): ?><div class="alert alert-error"><?= e($searchError) ?></div><?php endif; ?>
    </div>
    <?php if ($q !== '' && !$searchError): ?>
        <div class="card">
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Person</th><th>Username</th><th>Title / department</th><th>AD account</th><th>Access via groups</th><th>MEL access</th><th style="min-width:260px;">Grant</th></tr></thead>
                    <tbody>
                    <?php foreach ($results as $r): $l = $linked[$r['guid']] ?? null; ?>
                        <tr>
                            <td><strong><?= e($r['name']) ?></strong><div class="hint"><?= e($r['email'] ?: 'no email') ?></div></td>
                            <td><?= e($r['username']) ?></td>
                            <td><?= e($r['title']) ?><div class="hint"><?= e($r['department']) ?></div></td>
                            <td><?= $r['disabled'] ? '<span class="badge badge-overdue">Disabled</span>' : '<span class="badge badge-closed">Enabled</span>' ?></td>
                            <td><?= $r['access']['role'] ? e(role_label($r['access']['role'])) . '<div class="hint">' . e($r['access']['group']) . '</div>' : '<span class="hint">No mapped group</span>' ?></td>
                            <td><?= $l ? user_role_badge($l) . ($l['is_active'] ? '' : ' <span class="badge badge-overdue">Revoked</span>') : '<span class="hint">None</span>' ?></td>
                            <td>
                                <?php if ($r['disabled']): ?>
                                    <span class="hint">Cannot grant a disabled account</span>
                                <?php else: ?>
                                    <form method="post" style="display:flex; gap:6px; flex-wrap:wrap;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="grant">
                                        <input type="hidden" name="guid" value="<?= e($r['guid']) ?>">
                                        <input type="hidden" name="q" value="<?= e($q) ?>">
                                        <select name="role" aria-label="Role for <?= e($r['name']) ?>" style="width:auto;">
                                            <?php if ($r['access']['role']): ?><option value="directory">Follow AD groups (<?= e(role_label($r['access']['role'])) ?>)</option><?php endif; ?>
                                            <?php foreach ($ROLES as $role): ?><option value="<?= $role ?>" <?= !$r['access']['role'] && $role === 'viewer' ? 'selected' : '' ?>><?= e(role_label($role)) ?> (manual)</option><?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-sm"><?= $l ? 'Update' : 'Grant access' ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$results): ?><tr><td colspan="7">No one in the directory matches "<?= e($q) ?>".</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="hint">Granting a user creates their MEL account linked to AD. They sign in with their network password; no MEL password is created or emailed. If a local account with the same username or email already exists, it is linked instead of duplicated, so its history is kept.</p>
        </div>
    <?php endif; ?>

<?php elseif ($tab === 'mappings'):
    $gq = trim((string)($_GET['gq'] ?? ''));
    $groups = [];
    $gErr = null;
    if ($gq !== '' && ($link = ad_connect($cfg, $gErr))) {
        $groups = ad_search_groups($link, $cfg, $gq);
        @ldap_unbind($link);
    } ?>
    <div class="card">
        <h2 style="margin-top:0;">Group → role mappings</h2>
        <p class="hint" style="margin-top:0;">Members of a mapped AD security group (including nested members<?= empty($cfg['nested_groups']) ? ' — currently OFF' : '' ?>) get its MEL role. When someone is in several mapped groups, the lowest priority number wins. A mapping can also make members signatories of a Digital Transformation governance body. Mappings never grant Super Admin.</p>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Priority</th><th>AD group</th><th>MEL role</th><th>Governance body</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($mappings as $m): ?>
                    <tr>
                        <td><?= (int)$m['priority'] ?></td>
                        <td><strong><?= e($m['group_name']) ?></strong><div class="hint" style="word-break:break-all;"><?= e($m['group_dn']) ?></div></td>
                        <td><?= role_badge($m['role']) ?></td>
                        <td><?= e($m['body_name'] ?? '—') ?></td>
                        <td><form method="post" onsubmit="return confirm('Remove this mapping?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete_mapping"><input type="hidden" name="mapping_id" value="<?= (int)$m['id'] ?>"><button class="btn-link">remove</button></form></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$mappings): ?><tr><td colspan="5">No mappings yet — until you add one, users can only be granted a manual role.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="dt-two-col">
        <div class="card">
            <h2 style="margin-top:0;">Add a mapping</h2>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_mapping">
                <div class="field"><label for="group_dn">Group distinguished name</label>
                    <input type="text" id="group_dn" name="group_dn" value="<?= e($_GET['dn'] ?? '') ?>" placeholder="CN=MEL-Managers,OU=Groups,DC=usaasa,DC=local" required></div>
                <div class="field"><label for="group_name">Display name <span class="hint">(optional)</span></label>
                    <input type="text" id="group_name" name="group_name" value="<?= e($_GET['name'] ?? '') ?>"></div>
                <div class="form-grid">
                    <div class="field"><label for="m_role">MEL role</label>
                        <select id="m_role" name="role" required>
                            <?php foreach ($ROLES as $r): ?><option value="<?= $r ?>" <?= $r === 'viewer' ? 'selected' : '' ?>><?= e(role_label($r)) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="field"><label for="priority">Priority</label><input type="number" id="priority" name="priority" value="100" min="1" max="999"></div>
                    <div class="field full"><label for="dt_body_id">Also signatory for <span class="hint">(optional)</span></label>
                        <select id="dt_body_id" name="dt_body_id"><option value="">— None —</option>
                            <?php foreach ($bodies as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?>
                        </select></div>
                </div>
                <button type="submit" class="btn">Add mapping</button>
            </form>
        </div>
        <div class="card">
            <h2 style="margin-top:0;">Find a group</h2>
            <form method="get" style="display:flex; gap:8px;">
                <input type="hidden" name="tab" value="mappings">
                <input type="text" name="gq" value="<?= e($gq) ?>" placeholder="e.g. MEL, Finance, ICT" aria-label="Group name">
                <button type="submit" class="btn btn-secondary" <?= $configured ? '' : 'disabled' ?>>Search</button>
            </form>
            <?php if ($gErr): ?><div class="alert alert-error" style="margin-top:10px;"><?= e($gErr) ?></div><?php endif; ?>
            <?php if ($gq !== '' && !$gErr): ?>
                <ul class="dt-member-list" style="margin-top:12px;">
                    <?php foreach ($groups as $g): ?>
                        <li><a href="?tab=mappings&amp;dn=<?= urlencode($g['dn']) ?>&amp;name=<?= urlencode($g['name']) ?>&amp;gq=<?= urlencode($gq) ?>"><strong><?= e($g['name']) ?></strong></a>
                            <span class="hint" style="word-break:break-all;"><?= e($g['dn']) ?></span></li>
                    <?php endforeach; ?>
                    <?php if (!$groups): ?><li class="hint">No groups match.</li><?php endif; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($tab === 'connection'):
    $test = $_SESSION['ad_test_result'] ?? null;
    unset($_SESSION['ad_test_result']);
    $storedPw = (string)$pdo->query('SELECT bind_password FROM ad_settings WHERE id = 1')->fetchColumn(); ?>
    <div class="card">
        <h2 style="margin-top:0;">Active Directory connection</h2>
        <form method="post" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_config">
            <div class="form-grid">
                <div class="field full">
                    <label style="display:flex; gap:6px; align-items:center;"><input type="checkbox" name="enabled" value="1" style="width:auto;" <?= !empty($cfg['enabled']) ? 'checked' : '' ?>>
                        Allow sign-in with Active Directory credentials</label>
                    <div class="hint">Local accounts (including yours) always keep working, so you can still get in if AD is unreachable.</div>
                </div>
                <div class="field"><label for="hosts">Domain controllers</label>
                    <input type="text" id="hosts" name="hosts" value="<?= e($cfg['hosts'] ?? '') ?>" placeholder="dc01.usaasa.local, dc02.usaasa.local">
                    <div class="hint">Tried in order; the next is used if one is down.</div></div>
                <div class="field"><label for="encryption">Security</label>
                    <select id="encryption" name="encryption">
                        <?php foreach (['ldaps' => 'LDAPS (port 636) — recommended', 'starttls' => 'LDAP + STARTTLS (port 389)', 'none' => 'Unencrypted LDAP (389) — testing only'] as $v => $l): ?>
                            <option value="<?= $v ?>" <?= ($cfg['encryption'] ?? 'ldaps') === $v ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="field"><label for="port">Port</label><input type="number" id="port" name="port" value="<?= (int)($cfg['port'] ?? 636) ?>" min="1" max="65535"></div>
                <div class="field"><label for="timeout_seconds">Timeout (seconds)</label><input type="number" id="timeout_seconds" name="timeout_seconds" value="<?= (int)($cfg['timeout_seconds'] ?? 5) ?>" min="1" max="30"></div>
                <div class="field full"><label for="base_dn">Base DN (where users are searched)</label>
                    <input type="text" id="base_dn" name="base_dn" value="<?= e($cfg['base_dn'] ?? '') ?>" placeholder="OU=Staff,DC=usaasa,DC=local"></div>
                <div class="field"><label for="bind_dn">Service account (bind DN or UPN)</label>
                    <input type="text" id="bind_dn" name="bind_dn" value="<?= e($cfg['bind_dn'] ?? '') ?>" placeholder="svc-mel-ldap@usaasa.local"></div>
                <div class="field"><label for="bind_password">Service account password</label>
                    <input type="password" id="bind_password" name="bind_password" autocomplete="new-password" placeholder="<?= $storedPw !== '' ? 'Saved (encrypted) — leave blank to keep' : 'Not set' ?>">
                    <div class="hint">Use a read-only account with no other rights.</div></div>
                <div class="field"><label for="upn_suffix">UPN suffix <span class="hint">(lets users type just "jsmith")</span></label>
                    <input type="text" id="upn_suffix" name="upn_suffix" value="<?= e($cfg['upn_suffix'] ?? '') ?>" placeholder="usaasa.org.za"></div>
                <div class="field"><label for="user_filter">User filter</label>
                    <input type="text" id="user_filter" name="user_filter" value="<?= e($cfg['user_filter'] ?? '') ?>"></div>
                <div class="field full" style="display:grid; gap:6px;">
                    <label style="display:flex; gap:6px; align-items:center; font-weight:400;"><input type="checkbox" name="verify_cert" value="1" style="width:auto;" <?= !empty($cfg['verify_cert']) ? 'checked' : '' ?>> Verify the domain controller's TLS certificate (turn off only for testing)</label>
                    <label style="display:flex; gap:6px; align-items:center; font-weight:400;"><input type="checkbox" name="nested_groups" value="1" style="width:auto;" <?= !empty($cfg['nested_groups']) ? 'checked' : '' ?>> Include nested group membership when matching mappings</label>
                    <label style="display:flex; gap:6px; align-items:center; font-weight:400;"><input type="checkbox" name="auto_provision" value="1" style="width:auto;" <?= !empty($cfg['auto_provision']) ? 'checked' : '' ?>> Create accounts automatically on first sign-in for members of a mapped group</label>
                    <label style="display:flex; gap:6px; align-items:center; font-weight:400;"><input type="checkbox" name="sync_deactivate" value="1" style="width:auto;" <?= !empty($cfg['sync_deactivate']) ? 'checked' : '' ?>> Sync deactivates users who are disabled, deleted, or no longer in a mapped group</label>
                </div>
            </div>
            <button type="submit" class="btn">Save connection settings</button>
        </form>
    </div>
    <div class="dt-two-col">
        <div class="card">
            <h2 style="margin-top:0;">Test connection</h2>
            <p class="hint" style="margin-top:0;">Connects to the saved domain controllers, binds as the service account and searches the base DN.</p>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_connection"><button type="submit" class="btn btn-secondary" <?= $configured ? '' : 'disabled' ?>>Test connection</button></form>
        </div>
        <div class="card" id="test-login">
            <h2 style="margin-top:0;">Test a user</h2>
            <p class="hint" style="margin-top:0;">Checks how a user would be resolved, without signing them in or creating an account. The password is optional and is never stored.</p>
            <form method="post" autocomplete="off" style="display:grid; gap:8px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="test_login">
                <input type="text" name="test_username" placeholder="jsmith or jsmith@usaasa.org.za" required aria-label="Username">
                <input type="password" name="test_password" placeholder="Password (optional)" autocomplete="new-password" aria-label="Password">
                <button type="submit" class="btn btn-secondary" <?= $configured ? '' : 'disabled' ?>>Test user</button>
            </form>
            <?php if ($test): ?>
                <ul class="dt-member-list" style="margin-top:12px;">
                    <?php foreach ($test['steps'] as [$state, $msg]): ?>
                        <li><span class="badge <?= $state === 'ok' ? 'badge-closed' : ($state === 'fail' ? 'badge-overdue' : 'badge-role') ?>"><?= $state === 'ok' ? 'OK' : ($state === 'fail' ? 'Fail' : 'Skip') ?></span> <?= e($msg) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

<?php else:
    $audit = $pdo->query('SELECT a.*, u.full_name AS user_name, x.full_name AS actor_name FROM access_audit_log a
                          LEFT JOIN users u ON u.id = a.user_id LEFT JOIN users x ON x.id = a.actor_id
                          ORDER BY a.created_at DESC, a.id DESC LIMIT 150')->fetchAll(); ?>
    <div class="card">
        <h2 style="margin-top:0;">Access audit log</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>When</th><th>Event</th><th>User</th><th>By</th><th>Details</th><th>IP</th></tr></thead>
                <tbody>
                <?php foreach ($audit as $a): ?>
                    <tr>
                        <td style="white-space:nowrap;"><?= format_datetime($a['created_at']) ?></td>
                        <td><?= in_array($a['action'], ['ad_login_denied', 'ad_revoked', 'ad_sync_deactivated', 'ad_login_error'], true)
                                ? '<span class="badge badge-overdue">' . e(access_action_label($a['action'])) . '</span>'
                                : '<span class="badge badge-role">' . e(access_action_label($a['action'])) . '</span>' ?></td>
                        <td><?= e($a['user_name'] ?? '—') ?></td>
                        <td><?= e($a['actor_name'] ?? 'System') ?></td>
                        <td><?= e($a['details'] ?? '') ?></td>
                        <td class="hint"><?= e($a['ip_address'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$audit): ?><tr><td colspan="6">Nothing recorded yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
