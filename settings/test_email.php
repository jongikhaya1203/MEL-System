<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/settings/edit.php');
}
csrf_verify();

$to = trim($_POST['to'] ?? '');
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    flash('error', 'Enter a valid email address to send the test to.');
    redirect('/settings/edit.php');
}

$settings = get_site_settings($pdo);
if (empty($settings['smtp_enabled'])) {
    flash('error', 'Email notifications are not enabled. Turn on "Email notifications" and save before sending a test.');
    redirect('/settings/edit.php');
}

$subject = 'USAASA MEL System — test email';
$body = "This is a test message from the USAASA MEL System.\n\n"
    . "If you're reading this, SMTP notifications are configured correctly.\n\n"
    . 'Sent at ' . date('Y-m-d H:i:s') . " by " . current_user()['full_name'] . ".\n";

$result = send_smtp_email($settings, $to, $to, $subject, $body);

$pdo->prepare(
    'INSERT INTO notification_log (site_id, recipient_email, subject, status, error_message) VALUES (NULL, ?, ?, ?, ?)'
)->execute([$to, $subject, $result['success'] ? 'sent' : 'failed', $result['error']]);

if ($result['success']) {
    flash('success', "Test email sent to $to.");
} else {
    flash('error', 'Test email failed: ' . $result['error']);
}
redirect('/settings/edit.php');
