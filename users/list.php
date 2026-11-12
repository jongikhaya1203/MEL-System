<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin']);

$isSuperAdmin = is_super_admin();
$ALL_ROLES = ['admin', 'manager', 'viewer', 'field_worker', 'back_office', 'approver', 'indigent_capturer', 'planner'];
// A regular admin can manage every role except admin-tier accounts —
// managing other admins is super-admin-only (see schema.sql's note on
// users.is_super_admin).
$selectableRoles = $isSuperAdmin ? $ALL_ROLES : array_diff($ALL_ROLES, ['admin']);

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
    if ($editing && $editing['role'] === 'admin' && !$isSuperAdmin) {
        flash('error', 'Only a super admin account can manage admin-tier accounts.');
        redirect('/users/list.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (isset($_POST['update_denials'])) {
        $targetId = (int)($_POST['id'] ?? 0);
        if ($targetId === (int)current_user()['id']) {
            flash('error', "You can't deny your own account access to a module — ask another super admin to do this for you.");
            redirect('/users/list.php?edit=' . $targetId);
        }
        $targetStmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
        $targetStmt->execute([$targetId]);
        $targetRole = $targetStmt->fetchColumn();
        if (!$targetRole) {
            flash('error', 'User not found.');
            redirect('/users/list.php');
        }
        if ($targetRole === 'admin' && !$isSuperAdmin) {
            flash('error', 'Only a super admin account can manage admin-tier accounts.');
            redirect('/users/list.php');
        }
        $validKeys = array_column(module_access_definitions(), 'key');
        $submittedKeys = array_map('strval', $_POST['deny_modules'] ?? []);
        $deniedKeys = array_values(array_intersect($submittedKeys, $validKeys));
        sync_user_module_denials($pdo, $targetId, $deniedKeys, (int)current_user()['id']);
        flash('success', 'Module access updated for this profile.');
        redirect('/users/list.php?edit=' . $targetId);
    }

    $id = (int)($_POST['id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? 'viewer';
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $password = $_POST['password'] ?? '';
    $isSuperAdminFlag = ($isSuperAdmin && isset($_POST['is_super_admin'])) ? 1 : 0;

    if ($id > 0) {
        $existing = $pdo->prepare('SELECT role FROM users WHERE id = ?');
        $existing->execute([$id]);
        $existingRole = $existing->fetchColumn();
        if ($existingRole === 'admin' && !$isSuperAdmin) {
            flash('error', 'Only a super admin account can manage admin-tier accounts.');
            redirect('/users/list.php');
        }
    }

    if (!in_array($role, $selectableRoles, true)) {
        $role = 'viewer';
    }
    // is_super_admin only has any meaning on an admin-role account.
    if ($role !== 'admin') {
        $isSuperAdminFlag = 0;
    }

    if ($username === '' || $fullName === '') {
        flash('error', 'Username and full name are required.');
        redirect('/users/list.php');
    }

    if ($id > 0) {
        if ($password !== '') {
            // An admin-set password always forces a change at next login —
            // the admin only ever knows a temporary value for this account.
            // Editing an account also clears any login lockout, since an
            // admin touching the account is itself a form of verification.
            $stmt = $pdo->prepare('UPDATE users SET username=?, full_name=?, email=?, role=?, is_super_admin=?, is_active=?, password_hash=?, must_change_password=1, failed_login_attempts=0, locked_until=NULL WHERE id=?');
            $stmt->execute([$username, $fullName, $email, $role, $isSuperAdminFlag, $isActive, password_hash($password, PASSWORD_DEFAULT), $id]);
            flash('success', 'User updated. They will be asked to set a new password at next login.');
        } else {
            $stmt = $pdo->prepare('UPDATE users SET username=?, full_name=?, email=?, role=?, is_super_admin=?, is_active=?, failed_login_attempts=0, locked_until=NULL WHERE id=?');
            $stmt->execute([$username, $fullName, $email, $role, $isSuperAdminFlag, $isActive, $id]);
            flash('success', 'User updated.');
        }
    } else {
        if ($password === '') {
            flash('error', 'A password is required for new users.');
            redirect('/users/list.php');
        }
        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, email, role, is_super_admin, is_active, must_change_password) VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $email, $role, $isSuperAdminFlag, $isActive]);
        flash('success', 'User added. They will be asked to set a new password at first login.');
    }
    redirect('/users/list.php');
}

$users = $pdo->query('SELECT * FROM users ORDER BY created_at DESC')->fetchAll();

$pageTitle = 'Users';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Users</h1>
        <p class="subtitle">Manage accounts and roles (admin, manager, viewer, installer, reviewer, approver, data capturer, planner)</p>
    </div>
    <?php if ($isSuperAdmin): ?>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/users/access_review.php">Access Review</a>
    <?php endif; ?>
</div>

<?php if (!$isSuperAdmin): ?>
<div class="alert alert-info">Admin-tier accounts (including granting Super Admin) can only be managed by a super admin account.</div>
<?php endif; ?>

<div class="card">
    <h2><?= $editing ? 'Edit user' : 'Add a user' ?></h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">
        <div class="form-grid">
            <div class="field">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required value="<?= e($editing['username'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="full_name">Full name</label>
                <input type="text" id="full_name" name="full_name" required value="<?= e($editing['full_name'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= e($editing['email'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="role">Role</label>
                <select id="role" name="role">
                    <?php foreach ($selectableRoles as $r): ?>
                        <option value="<?= $r ?>" <?= ($editing['role'] ?? 'viewer') === $r ? 'selected' : '' ?>><?= e(role_label($r)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="password"><?= $editing ? 'Reset password (optional)' : 'Password' ?></label>
                <input type="password" id="password" name="password" <?= $editing ? '' : 'required' ?>>
            </div>
            <div class="field">
                <label for="is_active">Active</label>
                <select id="is_active" name="is_active">
                    <option value="1" <?= (!$editing || $editing['is_active']) ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= ($editing && !$editing['is_active']) ? 'selected' : '' ?>>No</option>
                </select>
            </div>
            <?php if ($isSuperAdmin): ?>
            <div class="field full">
                <label style="display:flex; align-items:center; gap:6px;">
                    <input type="checkbox" name="is_super_admin" value="1" style="width:auto;" <?= !empty($editing['is_super_admin']) ? 'checked' : '' ?>>
                    Super Admin (only meaningful when role is Admin) &mdash; can manage other admin accounts, system settings and irreversible deletes.
                </label>
            </div>
            <?php endif; ?>
        </div>
        <button type="submit" class="btn"><?= $editing ? 'Save changes' : 'Add user' ?></button>
        <?php if ($editing): ?>
            <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/users/list.php">Cancel</a>
        <?php endif; ?>
    </form>
</div>

<?php if ($editing): ?>
<div class="card">
    <h2>Module access for this profile</h2>
    <p class="hint" style="margin-top:0;">
        Access level is linked from <?= e(role_label($editing['role'])) ?><?= !empty($editing['is_super_admin']) ? ' + Super Admin' : '' ?>'s
        current role (change the role above to change it). Tick <strong>Deny</strong> on a module to explicitly
        block this one profile from it, regardless of role &mdash; enforced the same way as every other access
        rule in this system, on every page of that module.
    </p>
    <?php
        $isSelfProfile = (int)$editing['id'] === (int)current_user()['id'];
        $deniedKeys = user_denied_module_keys($pdo, (int)$editing['id']);
        $access = user_accessible_modules($editing['role'], (bool)$editing['is_super_admin'], $deniedKeys);
    ?>
    <?php if ($isSelfProfile): ?>
        <div class="alert alert-info">You can't deny your own account access to a module, to avoid locking yourself out. Ask another super admin to do this if it's ever needed.</div>
    <?php endif; ?>
    <?php if ($access): ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="update_denials" value="1">
        <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
        <div class="table-wrap">
        <table>
            <thead><tr><th>Module</th><th>Access level</th><th>Deny</th></tr></thead>
            <tbody>
            <?php foreach ($access as $a): ?>
                <tr>
                    <td>
                        <a href="<?= APP_BASE_URL . e($a['href']) ?>"><?= e($a['module']) ?></a>
                        <?php if ($a['denied']): ?><span class="badge badge-overdue">Denied</span><?php endif; ?>
                    </td>
                    <td><?= e(implode('; ', $a['levels'])) ?></td>
                    <td>
                        <?php if ($isSelfProfile): ?>
                            <span class="hint">&mdash;</span>
                        <?php else: ?>
                            <input type="checkbox" name="deny_modules[]" value="<?= e($a['key']) ?>" style="width:auto;" <?= $a['denied'] ? 'checked' : '' ?>>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if (!$isSelfProfile): ?>
            <button type="submit" class="btn btn-secondary" style="margin-top:10px;">Update module access</button>
        <?php endif; ?>
    </form>
    <?php else: ?>
        <p class="hint">This role has no access to any module (this shouldn't normally happen).</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="table-wrap">
    <table>
        <thead><tr><th>Username</th><th>Full name</th><th>Role</th><th>Active</th><th>Password</th><th>Login</th><th>Created</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): $isLocked = $u['locked_until'] && strtotime($u['locked_until']) > time(); $canEditRow = $isSuperAdmin || $u['role'] !== 'admin'; ?>
            <tr>
                <td><?= e($u['username']) ?></td>
                <td><?= e($u['full_name']) ?></td>
                <td><?= user_role_badge($u) ?></td>
                <td><?= $u['is_active'] ? 'Yes' : 'No' ?></td>
                <td><?= $u['must_change_password'] ? '<span class="badge badge-progress">Must change</span>' : '<span class="hint">OK</span>' ?></td>
                <td><?= $isLocked ? '<span class="badge badge-overdue">Locked until ' . e(format_datetime($u['locked_until'])) . '</span>' : '<span class="hint">OK</span>' ?></td>
                <td><?= format_date($u['created_at']) ?></td>
                <td><?= $canEditRow ? '<a href="?edit=' . (int)$u['id'] . '">Edit</a>' : '<span class="hint">Super admin only</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
