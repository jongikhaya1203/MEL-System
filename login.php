<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    redirect('/index.php');
}

$error = '';

const MAX_LOGIN_ATTEMPTS = 5;
const LOCKOUT_MINUTES = 15;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && $user['locked_until'] && strtotime($user['locked_until']) > time()) {
        $minutesLeft = (int)ceil((strtotime($user['locked_until']) - time()) / 60);
        $error = "This account is temporarily locked after repeated failed attempts. Try again in $minutesLeft minute" . ($minutesLeft === 1 ? '' : 's') . ', or ask an admin to unlock it.';
    } elseif ($user && password_verify($password, $user['password_hash'])) {
        $pdo->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
        login_user($user);
        redirect('/index.php');
    } else {
        if ($user) {
            $attempts = (int)$user['failed_login_attempts'] + 1;
            if ($attempts >= MAX_LOGIN_ATTEMPTS) {
                $lockedUntil = date('Y-m-d H:i:s', strtotime('+' . LOCKOUT_MINUTES . ' minutes'));
                $pdo->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = ? WHERE id = ?')->execute([$lockedUntil, $user['id']]);
                $error = "This account has been locked for " . LOCKOUT_MINUTES . " minutes after $attempts failed attempts.";
            } else {
                $pdo->prepare('UPDATE users SET failed_login_attempts = ? WHERE id = ?')->execute([$attempts, $user['id']]);
                $error = 'Invalid username or password.';
            }
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

$pageTitle = 'Log in';
include __DIR__ . '/includes/header.php';
?>
<?php $settings = get_site_settings($pdo); ?>
<div class="login-wrap">
    <h1><?= e($settings['site_name']) ?></h1>
    <p class="subtitle"><?= e($settings['tagline'] ?? 'Monitoring, Evaluation & Learning') ?></p>
    <div class="card">
        <?php if ($error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" novalidate>
            <?= csrf_field() ?>
            <div class="field">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autofocus>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn" style="width:100%">Log in</button>
        </form>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
