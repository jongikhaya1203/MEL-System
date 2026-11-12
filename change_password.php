<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$user = current_user();
$forced = !empty($user['must_change_password']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $hash = $stmt->fetchColumn();

    if (!$hash || !password_verify($currentPassword, $hash)) {
        flash('error', 'Current password is incorrect.');
        redirect('/change_password.php');
    }
    if (strlen($newPassword) < 8) {
        flash('error', 'New password must be at least 8 characters.');
        redirect('/change_password.php');
    }
    if ($newPassword !== $confirmPassword) {
        flash('error', 'New password and confirmation do not match.');
        redirect('/change_password.php');
    }
    if (password_verify($newPassword, $hash)) {
        flash('error', 'New password must be different from your current password.');
        redirect('/change_password.php');
    }

    $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
        ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);

    $_SESSION['user']['must_change_password'] = false;

    flash('success', 'Password changed.');
    redirect('/index.php');
}

$pageTitle = 'Change password';
include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Change password</h1>
        <p class="subtitle"><?= $forced ? 'You must set a new password before continuing.' : 'Update your account password.' ?></p>
    </div>
</div>

<?php if ($forced): ?>
<div class="alert alert-info">This account was created with a temporary password. Please set your own before continuing.</div>
<?php endif; ?>

<div class="card" style="max-width:420px;">
    <form method="post">
        <?= csrf_field() ?>
        <div class="field">
            <label for="current_password">Current password</label>
            <input type="password" id="current_password" name="current_password" required autofocus>
        </div>
        <div class="field">
            <label for="new_password">New password</label>
            <input type="password" id="new_password" name="new_password" required minlength="8">
            <p class="hint">At least 8 characters.</p>
        </div>
        <div class="field">
            <label for="confirm_password">Confirm new password</label>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
        </div>
        <button type="submit" class="btn">Change password</button>
        <?php if (!$forced): ?>
            <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/index.php">Cancel</a>
        <?php endif; ?>
    </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
