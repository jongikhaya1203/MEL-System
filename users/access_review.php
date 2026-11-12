<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_super_admin();

const ACCESS_REVIEW_DUE_DAYS = 90;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        flash('error', 'User not found.');
        redirect('/users/access_review.php');
    }
    $pdo->prepare('UPDATE users SET last_access_review_at = NOW(), last_access_reviewed_by = ? WHERE id = ?')
        ->execute([current_user()['id'], $id]);
    flash('success', 'Access confirmed as reviewed.');
    redirect('/users/access_review.php');
}

$users = $pdo->query(
    "SELECT u.*, r.full_name AS reviewed_by_name
     FROM users u
     LEFT JOIN users r ON r.id = u.last_access_reviewed_by
     ORDER BY (u.last_access_review_at IS NULL) DESC, u.last_access_review_at ASC, u.full_name ASC"
)->fetchAll();

$dueThreshold = date('Y-m-d H:i:s', strtotime('-' . ACCESS_REVIEW_DUE_DAYS . ' days'));
$dormantThreshold = $dueThreshold; // same window used for "hasn't logged in recently" too

$pageTitle = 'Access Review';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>User Access Review</h1>
        <p class="subtitle">Super admin only &mdash; what each role can access, and when each account's access was last confirmed</p>
    </div>
    <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/users/list.php">Manage users</a>
</div>

<div class="doc-callout">
    Access in this system is role-based, enforced on every page server-side &mdash; there is no separate per-user
    permission override. The way to change what a user can access is to change their role (via
    <strong>Manage users</strong>). This page exists to make that role-to-module mapping fully visible, and to
    track when an administrator last confirmed each account's access is still appropriate.
</div>

<div class="card">
    <h2>Role &rarr; module access matrix</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Module</th><th>Access level</th><th>Who</th></tr></thead>
        <tbody>
        <?php foreach (module_access_matrix() as $module => $levels): ?>
            <?php $first = true; foreach ($levels as $level => $who): ?>
                <tr>
                    <?php if ($first): ?><td rowspan="<?= count($levels) ?>"><strong><?= e($module) ?></strong></td><?php endif; ?>
                    <td><?= e($level) ?></td>
                    <td><?= e($who) ?></td>
                </tr>
            <?php $first = false; endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="card">
    <h2>Accounts</h2>
    <p class="hint" style="margin-top:0;">
        Rows needing attention (no review on file, a review older than <?= ACCESS_REVIEW_DUE_DAYS ?> days, or no
        login in that time) are listed first and flagged below.
    </p>
    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>User</th><th>Role</th><th>Status</th><th>Last login</th><th>Last access review</th><th>Module access</th><th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <?php
                $reviewDue = !$u['last_access_review_at'] || $u['last_access_review_at'] < $dueThreshold;
                $dormant = $u['is_active'] && (!$u['last_login_at'] || $u['last_login_at'] < $dormantThreshold);
                $isLocked = $u['locked_until'] && strtotime($u['locked_until']) > time();
            ?>
            <tr>
                <td><?= e($u['full_name']) ?><br><span class="hint"><?= e($u['username']) ?></span></td>
                <td><?= user_role_badge($u) ?></td>
                <td>
                    <?= $u['is_active'] ? '<span class="hint">Active</span>' : '<span class="badge badge-overdue">Deactivated</span>' ?>
                    <?php if ($isLocked): ?><br><span class="badge badge-overdue">Locked</span><?php endif; ?>
                    <?php if ($u['must_change_password']): ?><br><span class="badge badge-progress">Must change password</span><?php endif; ?>
                    <?php if ($dormant): ?><br><span class="badge badge-overdue">No recent login</span><?php endif; ?>
                </td>
                <td><?= $u['last_login_at'] ? format_datetime($u['last_login_at']) : '<span class="hint">Never</span>' ?></td>
                <td>
                    <?php if ($u['last_access_review_at']): ?>
                        <?= format_datetime($u['last_access_review_at']) ?><br>
                        <span class="hint">by <?= e($u['reviewed_by_name'] ?? '—') ?></span>
                    <?php else: ?>
                        <span class="hint">Never reviewed</span>
                    <?php endif; ?>
                    <?php if ($reviewDue): ?><br><span class="badge badge-overdue">Review due</span><?php endif; ?>
                </td>
                <td>
                    <?php
                        $userDeniedKeys = user_denied_module_keys($pdo, (int)$u['id']);
                        $access = user_accessible_modules($u['role'], (bool)$u['is_super_admin'], $userDeniedKeys);
                        $deniedCount = count(array_filter($access, fn($a) => $a['denied']));
                    ?>
                    <details>
                        <summary class="hint" style="cursor:pointer;">
                            <?= count($access) ?> module<?= count($access) === 1 ? '' : 's' ?><?= $deniedCount ? ", $deniedCount denied" : '' ?>
                        </summary>
                        <ul class="history-list">
                            <?php foreach ($access as $a): ?>
                                <li>
                                    <a href="<?= APP_BASE_URL . e($a['href']) ?>"><?= e($a['module']) ?></a>
                                    <?php if ($a['denied']): ?><span class="badge badge-overdue">Denied</span><?php endif; ?>
                                    &mdash; <span class="hint"><?= e(implode('; ', $a['levels'])) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                </td>
                <td>
                    <div class="actions-row">
                        <a class="btn btn-secondary btn-sm" href="<?= APP_BASE_URL ?>/users/list.php?edit=<?= (int)$u['id'] ?>">Edit</a>
                        <form method="post" style="margin:0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                            <button type="submit" class="btn btn-sm">Confirm reviewed</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$users): ?>
            <tr><td colspan="7">No users found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
