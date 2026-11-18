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

    if ($siteName === '') {
        flash('error', 'Site name is required.');
        redirect('/settings/edit.php');
    }
    $current = $pdo->query('SELECT logo_filename FROM site_settings WHERE id = 1')->fetch();
    $logoFilename = $current['logo_filename'] ?? null;

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

    // Branding only — SMTP and notification routing live in settings/email.php.
    $stmt = $pdo->prepare(
        'INSERT INTO site_settings (id, site_name, tagline, footer_text, logo_filename, updated_by)
         VALUES (1, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE site_name = VALUES(site_name), tagline = VALUES(tagline),
             footer_text = VALUES(footer_text), logo_filename = VALUES(logo_filename), updated_by = VALUES(updated_by)'
    );
    $stmt->execute([
        $siteName, $tagline !== '' ? $tagline : null, $footerText !== '' ? $footerText : null,
        $logoFilename, current_user()['id'],
    ]);

    flash('success', 'Settings updated.');
    redirect('/settings/edit.php');
}

$settings = get_site_settings($pdo);

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

        </div>
        <button type="submit" class="btn">Save settings</button>
    </form>
</div>

<div class="card">
    <h2 style="margin-top:0;">Email &amp; notifications</h2>
    <p class="hint" style="margin-top:0;">SMTP server settings, notification routing, test emails and the delivery log have moved to their own page.</p>
    <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/settings/email.php">Open Email &amp; Notifications</a>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
