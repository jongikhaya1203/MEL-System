<?php
/**
 * Super admin > Email & Notifications.
 *   POST action=smtp     — SMTP server, sender identity, Reply-To (password stored encrypted)
 *   POST action=routing  — per-notification on/off + CC, BCC archive, test-mode redirect
 * Test emails post to settings/test_email.php.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_super_admin();
$me = current_user();

$validEmailOrBlank = fn(string $v) => $v === '' || filter_var($v, FILTER_VALIDATE_EMAIL) !== false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'smtp') {
        $enabled = isset($_POST['smtp_enabled']) ? 1 : 0;
        $host = trim((string)($_POST['smtp_host'] ?? ''));
        $portRaw = trim((string)($_POST['smtp_port'] ?? ''));
        $encryption = in_array($_POST['smtp_encryption'] ?? '', ['none', 'tls', 'ssl'], true) ? $_POST['smtp_encryption'] : 'tls';
        $username = trim((string)($_POST['smtp_username'] ?? ''));
        $passwordInput = (string)($_POST['smtp_password'] ?? '');
        $fromEmail = trim((string)($_POST['smtp_from_email'] ?? ''));
        $fromName = trim((string)($_POST['smtp_from_name'] ?? ''));
        $replyTo = trim((string)($_POST['smtp_reply_to'] ?? ''));

        $errors = [];
        if ($enabled && ($host === '' || $fromEmail === '')) {
            $errors[] = 'SMTP host and from-address are required to turn email on.';
        }
        if ($host !== '' && !preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
            $errors[] = 'SMTP host must be a host name or IP address only (no scheme or port).';
        }
        if ($portRaw !== '' && (!ctype_digit($portRaw) || (int)$portRaw < 1 || (int)$portRaw > 65535)) {
            $errors[] = 'Port must be a number between 1 and 65535.';
        }
        if (!$validEmailOrBlank($fromEmail) || !$validEmailOrBlank($replyTo)) {
            $errors[] = 'From and Reply-To must be valid email addresses.';
        }
        if ($errors) {
            flash('error', implode(' ', $errors));
            redirect('/settings/email.php');
        }
        $current = $pdo->query('SELECT smtp_password FROM site_settings WHERE id = 1')->fetchColumn();
        if (isset($_POST['clear_password'])) {
            $storedPassword = null;
        } elseif ($passwordInput !== '') {
            $storedPassword = encrypt_secret($passwordInput);
        } else {
            // Keep the current one — and encrypt it if it is still legacy plaintext.
            $storedPassword = $current ? encrypt_secret(decrypt_secret($current)) : null;
        }
        $pdo->prepare('UPDATE site_settings SET smtp_enabled = ?, smtp_host = ?, smtp_port = ?, smtp_encryption = ?, smtp_username = ?, smtp_password = ?,
                       smtp_from_email = ?, smtp_from_name = ?, smtp_reply_to = ?, updated_by = ? WHERE id = 1')
            ->execute([$enabled, $host ?: null, $portRaw !== '' ? (int)$portRaw : 587, $encryption, $username ?: null, $storedPassword,
                       $fromEmail ?: null, $fromName ?: null, $replyTo ?: null, $me['id']]);
        flash('success', 'SMTP settings saved.' . ($enabled ? '' : ' Email is currently switched OFF — no notifications will be sent.'));
        redirect('/settings/email.php');
    }

    if ($action === 'routing') {
        $bcc = trim((string)($_POST['mail_bcc_archive'] ?? ''));
        $testTo = trim((string)($_POST['mail_test_mode_to'] ?? ''));
        if (!$validEmailOrBlank($bcc) || !$validEmailOrBlank($testTo)) {
            flash('error', 'Archive and test-mode addresses must be valid email addresses.');
            redirect('/settings/email.php#routing');
        }
        $bad = [];
        $stmt = $pdo->prepare('INSERT INTO notification_rules (event_key, enabled, cc_emails, updated_by) VALUES (?, ?, ?, ?)
                               ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), cc_emails = VALUES(cc_emails), updated_by = VALUES(updated_by)');
        foreach (notification_events() as $key => [$label]) {
            $raw = (string)($_POST['cc'][$key] ?? '');
            $valid = parse_email_list($raw);
            $tokens = preg_split('/[\s,;]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
            if (count($tokens) !== count($valid)) {
                $bad[] = $label;
            }
            $stmt->execute([$key, isset($_POST['enabled'][$key]) ? 1 : 0, $valid ? implode(', ', $valid) : null, $me['id']]);
        }
        $pdo->prepare('UPDATE site_settings SET mail_bcc_archive = ?, mail_test_mode_to = ?, updated_by = ? WHERE id = 1')
            ->execute([$bcc ?: null, $testTo ?: null, $me['id']]);
        flash($bad ? 'warning' : 'success', $bad ? 'Routing saved, but invalid CC addresses were dropped for: ' . implode('; ', $bad) . '.' : 'Notification routing saved.');
        redirect('/settings/email.php#routing');
    }
}

$settings = get_site_settings($pdo);
$rules = notification_rules($pdo);
$events = notification_events();
$filter = (string)($_GET['event'] ?? '');
$logSql = 'SELECT n.*, s.site_code FROM notification_log n LEFT JOIN sites s ON s.id = n.site_id';
$params = [];
if ($filter !== '') {
    $logSql .= ' WHERE n.event_key = ?';
    $params[] = $filter;
}
$stmt = $pdo->prepare($logSql . ' ORDER BY n.sent_at DESC, n.id DESC LIMIT 50');
$stmt->execute($params);
$log = $stmt->fetchAll();
$stats = $pdo->query("SELECT SUM(status = 'sent') AS sent, SUM(status = 'failed') AS failed FROM notification_log WHERE sent_at >= NOW() - INTERVAL 30 DAY")->fetch();
$myEmail = (string)($pdo->query('SELECT email FROM users WHERE id = ' . (int)$me['id'])->fetchColumn() ?: '');
$storedRaw = (string)$pdo->query('SELECT smtp_password FROM site_settings WHERE id = 1')->fetchColumn();
$testMode = trim((string)($settings['mail_test_mode_to'] ?? '')) !== '';

$pageTitle = 'Email & Notifications';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Email &amp; Notifications</h1>
        <p class="subtitle">SMTP server for outgoing mail, and how each automated notification is routed</p>
    </div>
    <div>
        <?= !empty($settings['smtp_enabled']) ? '<span class="badge badge-closed">Email ON</span>' : '<span class="badge badge-overdue">Email OFF</span>' ?>
        <?= $testMode ? ' <span class="badge badge-progress">Test mode</span>' : '' ?>
    </div>
</div>

<?php if ($testMode): ?>
    <div class="alert alert-warning"><strong>Test mode is on:</strong> every notification is delivered to <?= e($settings['mail_test_mode_to']) ?> instead of its real recipients. Clear the test-mode address under Routing to go live.</div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-tile"><div class="stat-num"><?= (int)($stats['sent'] ?? 0) ?></div><div class="stat-label">Sent (30 days)</div></div>
    <div class="stat-tile<?= (int)($stats['failed'] ?? 0) ? ' is-overdue' : '' ?>"><div class="stat-num"><?= (int)($stats['failed'] ?? 0) ?></div><div class="stat-label">Failed (30 days)</div></div>
    <div class="stat-tile"><div class="stat-num"><?= count(array_filter($rules, fn($r) => $r['enabled'])) ?>/<?= count($rules) ?></div><div class="stat-label">Notification types on</div></div>
</div>

<div class="card">
    <h2 style="margin-top:0;">SMTP server</h2>
    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="smtp">
        <div class="form-grid">
            <div class="field full">
                <label style="display:flex; align-items:center; gap:6px;">
                    <input type="checkbox" name="smtp_enabled" value="1" style="width:auto;" <?= !empty($settings['smtp_enabled']) ? 'checked' : '' ?>>
                    Send email notifications (master switch for every notification type)
                </label>
            </div>
            <div class="field">
                <label for="smtp_host">SMTP host</label>
                <input type="text" id="smtp_host" name="smtp_host" value="<?= e($settings['smtp_host'] ?? '') ?>" placeholder="smtp.office365.com">
            </div>
            <div class="field">
                <label for="smtp_port">Port</label>
                <input type="text" inputmode="numeric" id="smtp_port" name="smtp_port" value="<?= e((string)($settings['smtp_port'] ?? 587)) ?>">
            </div>
            <div class="field">
                <label for="smtp_encryption">Encryption</label>
                <select id="smtp_encryption" name="smtp_encryption">
                    <?php foreach (['tls' => 'STARTTLS (usually port 587)', 'ssl' => 'Implicit TLS/SSL (usually port 465)', 'none' => 'None (internal relay only, usually port 25)'] as $val => $label): ?>
                        <option value="<?= $val ?>" <?= ($settings['smtp_encryption'] ?? 'tls') === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="smtp_username">Username <span class="hint">(blank for an unauthenticated relay)</span></label>
                <input type="text" id="smtp_username" name="smtp_username" value="<?= e($settings['smtp_username'] ?? '') ?>" autocomplete="off">
            </div>
            <div class="field">
                <label for="smtp_password">Password</label>
                <input type="password" id="smtp_password" name="smtp_password" autocomplete="new-password"
                       placeholder="<?= $storedRaw !== '' ? 'Saved — leave blank to keep' : 'Not set' ?>">
                <?php if ($storedRaw !== ''): ?>
                    <label class="hint" style="display:flex; gap:6px; align-items:center; font-weight:400;"><input type="checkbox" name="clear_password" value="1" style="width:auto;"> Remove saved password</label>
                    <div class="hint"><?= strpos($storedRaw, SECRET_PREFIX) === 0 ? 'Stored encrypted (AES-256-GCM).' : 'Stored in plaintext from an earlier version — save this form to encrypt it.' ?></div>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="smtp_from_email">From address</label>
                <input type="email" id="smtp_from_email" name="smtp_from_email" value="<?= e($settings['smtp_from_email'] ?? '') ?>" placeholder="mel-system@usaasa.org.za">
            </div>
            <div class="field">
                <label for="smtp_from_name">From name</label>
                <input type="text" id="smtp_from_name" name="smtp_from_name" value="<?= e($settings['smtp_from_name'] ?? '') ?>" placeholder="USAASA MEL System">
            </div>
            <div class="field">
                <label for="smtp_reply_to">Reply-To address <span class="hint">(optional, e.g. a monitored helpdesk mailbox)</span></label>
                <input type="email" id="smtp_reply_to" name="smtp_reply_to" value="<?= e($settings['smtp_reply_to'] ?? '') ?>" placeholder="mel-support@usaasa.org.za">
            </div>
        </div>
        <button type="submit" class="btn">Save SMTP settings</button>
    </form>
</div>

<div class="card" id="routing">
    <h2 style="margin-top:0;">Notification routing</h2>
    <p class="hint" style="margin-top:0;">Choose which notifications are sent, and copy extra people (comma-separated) on each type in addition to its normal recipients.</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="routing">
        <div class="table-wrap">
            <table>
                <thead><tr><th style="width:70px;">Send</th><th>Notification</th><th>Normally goes to</th><th style="min-width:260px;">Also CC</th></tr></thead>
                <tbody>
                <?php foreach ($events as $key => [$label, $who]): ?>
                    <tr>
                        <td><input type="checkbox" name="enabled[<?= e($key) ?>]" value="1" style="width:auto;" aria-label="Send <?= e($label) ?>" <?= $rules[$key]['enabled'] ? 'checked' : '' ?>></td>
                        <td><strong><?= e($label) ?></strong></td>
                        <td class="hint" style="font-size:13px;"><?= e($who) ?></td>
                        <td><input type="text" name="cc[<?= e($key) ?>]" value="<?= e($rules[$key]['cc']) ?>" placeholder="e.g. pmo@usaasa.org.za" aria-label="CC for <?= e($label) ?>"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="form-grid" style="margin-top:14px;">
            <div class="field">
                <label for="mail_bcc_archive">Archive copy (BCC on every notification)</label>
                <input type="email" id="mail_bcc_archive" name="mail_bcc_archive" value="<?= e($settings['mail_bcc_archive'] ?? '') ?>" placeholder="mel-archive@usaasa.org.za">
            </div>
            <div class="field">
                <label for="mail_test_mode_to">Test mode: send everything to <span class="hint">(leave blank to go live)</span></label>
                <input type="email" id="mail_test_mode_to" name="mail_test_mode_to" value="<?= e($settings['mail_test_mode_to'] ?? '') ?>" placeholder="uat-inbox@usaasa.org.za">
            </div>
        </div>
        <button type="submit" class="btn">Save routing</button>
    </form>
</div>

<div class="card">
    <h2 style="margin-top:0;">Send a test email</h2>
    <p class="hint" style="margin-top:0;">Uses the SMTP settings saved above (save first if you just changed them). Test mode and routing rules don't apply to this message.</p>
    <form method="post" action="<?= APP_BASE_URL ?>/settings/test_email.php" class="form-grid">
        <?= csrf_field() ?>
        <div class="field">
            <label for="test_email_to">Send test to</label>
            <input type="email" id="test_email_to" name="to" required value="<?= e($myEmail) ?>" placeholder="you@usaasa.org.za">
        </div>
        <div class="field" style="align-self:end;"><button type="submit" class="btn btn-secondary">Send test email</button></div>
    </form>
</div>

<div class="card">
    <div class="page-header" style="margin-bottom:8px;">
        <h2 style="margin:0;">Delivery log</h2>
        <form method="get">
            <select name="event" onchange="this.form.submit()" aria-label="Filter by notification">
                <option value="">All notifications</option>
                <?php foreach ($events + ['test' => ['Test emails']] as $key => $ev): ?>
                    <option value="<?= e($key) ?>" <?= $filter === $key ? 'selected' : '' ?>><?= e($ev[0]) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>When</th><th>Notification</th><th>To</th><th>Subject</th><th>Result</th></tr></thead>
            <tbody>
            <?php foreach ($log as $n): ?>
                <tr>
                    <td style="white-space:nowrap;"><?= format_datetime($n['sent_at']) ?></td>
                    <td class="hint" style="font-size:13px;"><?= e($n['event_key'] === 'test' ? 'Test email' : ($events[$n['event_key']][0] ?? ($n['site_code'] ? 'Site status' : '—'))) ?></td>
                    <td><?= e($n['recipient_email']) ?></td>
                    <td><?= e($n['subject']) ?></td>
                    <td>
                        <?= $n['status'] === 'sent' ? '<span class="badge badge-closed">Sent</span>' : '<span class="badge badge-overdue">Failed</span>' ?>
                        <?php if ($n['error_message']): ?><br><span class="hint"><?= e($n['error_message']) ?></span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$log): ?><tr><td colspan="5">No email has been attempted yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
