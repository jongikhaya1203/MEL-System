<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $siteName = trim($_POST['site_name'] ?? '');
    $tagline = trim($_POST['tagline'] ?? '');
    $footerText = trim($_POST['footer_text'] ?? '');
    $removeLogo = isset($_POST['remove_logo']);

    $smtpEnabled = isset($_POST['smtp_enabled']) ? 1 : 0;
    $smtpHost = trim($_POST['smtp_host'] ?? '');
    $smtpPortRaw = trim($_POST['smtp_port'] ?? '');
    $smtpEncryption = $_POST['smtp_encryption'] ?? 'tls';
    $smtpUsername = trim($_POST['smtp_username'] ?? '');
    $smtpPasswordInput = $_POST['smtp_password'] ?? '';
    $smtpFromEmail = trim($_POST['smtp_from_email'] ?? '');
    $smtpFromName = trim($_POST['smtp_from_name'] ?? '');

    if ($siteName === '') {
        flash('error', 'Site name is required.');
        redirect('/settings/edit.php');
    }
    if (!in_array($smtpEncryption, ['none', 'tls', 'ssl'], true)) {
        $smtpEncryption = 'tls';
    }
    $smtpPort = $smtpPortRaw !== '' && ctype_digit($smtpPortRaw) ? (int)$smtpPortRaw : 587;
    if ($smtpEnabled && ($smtpHost === '' || $smtpFromEmail === '')) {
        flash('error', 'SMTP host and from-address are required to enable email notifications.');
        redirect('/settings/edit.php');
    }

    $current = $pdo->query('SELECT logo_filename, smtp_password FROM site_settings WHERE id = 1')->fetch();
    $logoFilename = $current['logo_filename'] ?? null;
    // Leave blank to keep the current SMTP password — same pattern as resetting a user's password.
    $smtpPassword = $smtpPasswordInput !== '' ? $smtpPasswordInput : ($current['smtp_password'] ?? null);

    if ($removeLogo && $logoFilename) {
        $oldPath = LOGO_STORAGE_DIR . $logoFilename;
        if (is_file($oldPath)) {
            unlink($oldPath);
        }
        $logoFilename = null;
    }

    if (!empty($_FILES['logo']['name'])) {
        $error = null;
        $newFilename = store_logo_upload($_FILES['logo'], $error);
        if (!$newFilename) {
            flash('error', $error ?? 'Logo upload failed.');
            redirect('/settings/edit.php');
        }
        if ($logoFilename) {
            $oldPath = LOGO_STORAGE_DIR . $logoFilename;
            if (is_file($oldPath)) {
                unlink($oldPath);
            }
        }
        $logoFilename = $newFilename;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO site_settings (
            id, site_name, tagline, footer_text, logo_filename,
            smtp_enabled, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, smtp_from_email, smtp_from_name,
            updated_by
         )
         VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE site_name = VALUES(site_name), tagline = VALUES(tagline),
             footer_text = VALUES(footer_text), logo_filename = VALUES(logo_filename),
             smtp_enabled = VALUES(smtp_enabled), smtp_host = VALUES(smtp_host), smtp_port = VALUES(smtp_port),
             smtp_encryption = VALUES(smtp_encryption), smtp_username = VALUES(smtp_username),
             smtp_password = VALUES(smtp_password), smtp_from_email = VALUES(smtp_from_email),
             smtp_from_name = VALUES(smtp_from_name), updated_by = VALUES(updated_by)'
    );
    $stmt->execute([
        $siteName, $tagline !== '' ? $tagline : null, $footerText !== '' ? $footerText : null,
        $logoFilename,
        $smtpEnabled, $smtpHost !== '' ? $smtpHost : null, $smtpPort, $smtpEncryption,
        $smtpUsername !== '' ? $smtpUsername : null, $smtpPassword, $smtpFromEmail !== '' ? $smtpFromEmail : null,
        $smtpFromName !== '' ? $smtpFromName : null,
        current_user()['id'],
    ]);

    flash('success', 'Settings updated.');
    redirect('/settings/edit.php');
}

$settings = get_site_settings($pdo);
$myEmailStmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
$myEmailStmt->execute([current_user()['id']]);
$myEmail = $myEmailStmt->fetchColumn() ?: '';
$recentNotifications = $pdo->query(
    "SELECT n.*, s.site_code
     FROM notification_log n
     LEFT JOIN sites s ON s.id = n.site_id
     ORDER BY n.sent_at DESC
     LIMIT 20"
)->fetchAll();

$pageTitle = 'Settings';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Site Settings</h1>
        <p class="subtitle">Branding shown across the system: name, tagline, logo and footer text</p>
    </div>
</div>

<div class="card">
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="field">
                <label for="site_name">Site name</label>
                <input type="text" id="site_name" name="site_name" required value="<?= e($settings['site_name']) ?>">
            </div>
            <div class="field">
                <label for="tagline">Tagline</label>
                <input type="text" id="tagline" name="tagline" value="<?= e($settings['tagline'] ?? '') ?>">
            </div>
            <div class="field full">
                <label for="footer_text">Footer text</label>
                <input type="text" id="footer_text" name="footer_text" value="<?= e($settings['footer_text'] ?? '') ?>">
            </div>

            <div class="field full">
                <label>Logo</label>
                <?php if ($settings['logo_url']): ?>
                    <div style="margin-bottom:10px;">
                        <img src="<?= e($settings['logo_url']) ?>" alt="Current logo" style="max-height:60px; max-width:260px; display:block;">
                    </div>
                    <label style="font-weight:400; display:flex; align-items:center; gap:6px;">
                        <input type="checkbox" name="remove_logo" value="1" style="width:auto;"> Remove current logo
                    </label>
                <?php else: ?>
                    <p class="hint" style="margin-top:0;">No logo uploaded yet — the site name is shown on its own.</p>
                <?php endif; ?>
                <input type="file" name="logo" accept=".png,.jpg,.jpeg,.gif" style="margin-top:8px;">
                <p class="hint">PNG, JPG or GIF, max <?= LOGO_MAX_BYTES / 1024 / 1024 ?> MB. Uploading a new logo replaces the current one.</p>
            </div>

            <div class="field full">
                <label style="display:flex; align-items:center; gap:6px;">
                    <input type="checkbox" name="smtp_enabled" value="1" style="width:auto;" <?= $settings['smtp_enabled'] ? 'checked' : '' ?>>
                    Email notifications: notify the installer by email when a site they submitted is verified, approved or rejected
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
                    <?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'Implicit TLS/SSL (465)', 'none' => 'None'] as $val => $label): ?>
                        <option value="<?= $val ?>" <?= ($settings['smtp_encryption'] ?? 'tls') === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="smtp_username">SMTP username</label>
                <input type="text" id="smtp_username" name="smtp_username" value="<?= e($settings['smtp_username'] ?? '') ?>" autocomplete="off">
            </div>
            <div class="field">
                <label for="smtp_password">SMTP password</label>
                <input type="password" id="smtp_password" name="smtp_password" autocomplete="new-password"
                       placeholder="<?= $settings['smtp_password'] ? 'Leave blank to keep current password' : '' ?>">
            </div>
            <div class="field">
                <label for="smtp_from_email">From address</label>
                <input type="text" id="smtp_from_email" name="smtp_from_email" value="<?= e($settings['smtp_from_email'] ?? '') ?>" placeholder="mel-system@usaasa.org.za">
            </div>
            <div class="field">
                <label for="smtp_from_name">From name</label>
                <input type="text" id="smtp_from_name" name="smtp_from_name" value="<?= e($settings['smtp_from_name'] ?? '') ?>" placeholder="USAASA MEL System">
            </div>
        </div>
        <button type="submit" class="btn">Save settings</button>
    </form>
</div>

<div class="card">
    <h2>Send a test email</h2>
    <p class="hint" style="margin-top:0;">Sends a test message using the SMTP settings currently saved above (save first if you just changed them).</p>
    <form method="post" action="<?= APP_BASE_URL ?>/settings/test_email.php">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="field">
                <label for="test_email_to">Send test to</label>
                <input type="text" id="test_email_to" name="to" required value="<?= e($myEmail) ?>" placeholder="you@usaasa.org.za">
            </div>
        </div>
        <button type="submit" class="btn btn-secondary">Send test email</button>
    </form>
</div>

<div class="card">
    <h2>Recent notification attempts</h2>
    <?php if (!$recentNotifications): ?>
        <p class="hint">No email notifications have been attempted yet.</p>
    <?php else: ?>
    <div class="table-wrap">
    <table>
        <thead><tr><th>When</th><th>Site</th><th>To</th><th>Subject</th><th>Result</th></tr></thead>
        <tbody>
        <?php foreach ($recentNotifications as $n): ?>
            <tr>
                <td><?= format_datetime($n['sent_at']) ?></td>
                <td><?= $n['site_code'] ? e($n['site_code']) : '<span class="hint">—</span>' ?></td>
                <td><?= e($n['recipient_email']) ?></td>
                <td><?= e($n['subject']) ?></td>
                <td>
                    <?php if ($n['status'] === 'sent'): ?>
                        <span class="badge badge-closed">Sent</span>
                    <?php else: ?>
                        <span class="badge badge-overdue">Failed</span>
                        <?php if ($n['error_message']): ?><br><span class="hint"><?= e($n['error_message']) ?></span><?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
