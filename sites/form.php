<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'field_worker']);

$editing = null;
if (isset($_GET['id'])) {
    $stmt = $pdo->prepare('SELECT * FROM sites WHERE id = ?');
    $stmt->execute([(int)$_GET['id']]);
    $editing = $stmt->fetch() ?: null;
    if (!$editing) {
        flash('error', 'Site not found.');
        redirect('/sites/list.php');
    }
    if (!in_array($editing['status'], ['Submitted', 'Rejected'], true)) {
        flash('error', 'This site can no longer be edited once it has been verified.');
        redirect('/sites/view.php?id=' . $editing['id']);
    }
    $user = current_user();
    if ($user['role'] !== 'admin' && (int)$editing['submitted_by'] !== (int)$user['id']) {
        flash('error', 'You can only edit your own submissions.');
        redirect('/sites/view.php?id=' . $editing['id']);
    }
}

$projects = $pdo->query('SELECT id, project_name FROM projects ORDER BY project_name')->fetchAll();
if (!$projects) {
    flash('error', 'Please ask an admin/manager to add at least one project before submitting a site.');
    redirect('/sites/list.php');
}

$suggestedRef = $editing['site_code'] ?? next_site_reference_code($pdo);

$pageTitle = $editing ? 'Edit site completion' : 'Submit completed site';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1><?= $editing ? 'Edit site completion' : 'Submit completed site' ?></h1>
        <p class="subtitle">Site Completions &amp; Payment Approval</p>
    </div>
</div>

<?php if ($editing && $editing['status'] === 'Rejected'): ?>
    <div class="alert alert-info">This submission was rejected. Update the details below, then use "Resubmit" on the record view to send it back for verification.</div>
<?php endif; ?>

<div class="card">
    <form method="post" action="<?= APP_BASE_URL ?>/sites/save.php">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">

        <div class="form-grid">
            <div class="field">
                <label for="site_code">Reference</label>
                <input type="text" id="site_code" name="site_code" required
                       value="<?= e($suggestedRef) ?>" <?= $editing ? 'readonly' : '' ?>>
            </div>
            <div class="field">
                <label for="project_id">Project</label>
                <select id="project_id" name="project_id" required>
                    <?php foreach ($projects as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= (int)($editing['project_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
                            <?= e($p['project_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="site_name">Site name</label>
                <input type="text" id="site_name" name="site_name" required value="<?= e($editing['site_name'] ?? '') ?>"
                       placeholder="e.g. Ward 12 Community Hall">
            </div>
            <div class="field">
                <label for="location">Location</label>
                <input type="text" id="location" name="location" required value="<?= e($editing['location'] ?? '') ?>"
                       placeholder="e.g. Mthatha, OR Tambo District">
            </div>

            <div class="field">
                <label for="households_connected">Households connected at this site</label>
                <input type="text" inputmode="numeric" id="households_connected" name="households_connected" required
                       value="<?= e($editing['households_connected'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="completed_date">Completion date</label>
                <input type="date" id="completed_date" name="completed_date" required
                       value="<?= e($editing['completed_date'] ?? '') ?>">
            </div>
        </div>

        <button type="submit" class="btn"><?= $editing ? 'Save changes' : 'Submit for verification' ?></button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/sites/list.php">Cancel</a>
        <?php if (!$editing): ?>
            <p class="hint" style="margin-top:10px;">After submitting, you'll be able to attach proof of evidence (POE) files on the record's page.</p>
        <?php endif; ?>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
