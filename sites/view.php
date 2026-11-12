<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT s.*, p.project_name, p.project_code,
            sub.full_name AS submitted_by_name,
            ver.full_name AS verified_by_name,
            app.full_name AS approved_by_name,
            rej.full_name AS rejected_by_name
     FROM sites s
     JOIN projects p ON p.id = s.project_id
     JOIN users sub ON sub.id = s.submitted_by
     LEFT JOIN users ver ON ver.id = s.verified_by
     LEFT JOIN users app ON app.id = s.approved_by
     LEFT JOIN users rej ON rej.id = s.rejected_by
     WHERE s.id = ?"
);
$stmt->execute([$id]);
$site = $stmt->fetch();

if (!$site) {
    flash('error', 'Site not found.');
    redirect('/sites/list.php');
}

$docsStmt = $pdo->prepare(
    "SELECT d.*, u.full_name AS uploaded_by_name
     FROM site_poe_documents d
     JOIN users u ON u.id = d.uploaded_by
     WHERE d.site_id = ?
     ORDER BY d.uploaded_at ASC"
);
$docsStmt->execute([$id]);
$docs = $docsStmt->fetchAll();

$user = current_user();
$role = $user['role'];
$isOwner = (int)$site['submitted_by'] === (int)$user['id'];

$canUpload = in_array($role, ['admin', 'field_worker', 'back_office'], true) && $site['status'] !== 'Approved';
$canEdit = in_array($site['status'], ['Submitted', 'Rejected'], true) && ($role === 'admin' || ($role === 'field_worker' && $isOwner));
$canVerify = $site['status'] === 'Submitted' && in_array($role, ['admin', 'back_office'], true);
$canApprove = $site['status'] === 'Verified' && in_array($role, ['admin', 'approver'], true);
$canResubmit = $site['status'] === 'Rejected' && ($role === 'admin' || ($role === 'field_worker' && $isOwner));

$pageTitle = $site['site_code'];
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1><?= e($site['site_code']) ?> <?= site_status_badge($site['status']) ?></h1>
        <p class="subtitle"><?= e($site['site_name']) ?> &mdash; <?= e($site['project_name']) ?> (<?= e($site['project_code']) ?>)</p>
    </div>
    <div class="actions-row">
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/sites/preview.php?id=<?= $id ?>" target="_blank">Preview &amp; print</a>
        <?php if ($canEdit): ?>
            <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/sites/form.php?id=<?= $id ?>">Edit</a>
        <?php endif; ?>
        <?php if ($canResubmit): ?>
            <a class="btn" href="<?= APP_BASE_URL ?>/sites/resubmit.php?id=<?= $id ?>">Resubmit for verification</a>
        <?php endif; ?>
        <?php if ($canVerify): ?>
            <a class="btn" href="<?= APP_BASE_URL ?>/sites/verify.php?id=<?= $id ?>">Verify / Reject</a>
        <?php endif; ?>
        <?php if ($canApprove): ?>
            <a class="btn" href="<?= APP_BASE_URL ?>/sites/approve.php?id=<?= $id ?>">Approve / Reject</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($site['status'] === 'Rejected'): ?>
    <div class="alert alert-error">
        Rejected by <?= e($site['rejected_by_name']) ?> on <?= format_datetime($site['rejected_at']) ?>.<br>
        Reason: <?= nl2br(e($site['rejection_reason'])) ?>
    </div>
<?php elseif ($site['status'] === 'Approved'): ?>
    <div class="alert alert-success">Approved for payment by <?= e($site['approved_by_name']) ?> on <?= format_datetime($site['approved_at']) ?>.</div>
<?php endif; ?>

<div class="card">
    <h2>Site details</h2>
    <dl class="detail-grid">
        <dt>Location</dt>
        <dd><?= e($site['location']) ?></dd>

        <dt>Households connected</dt>
        <dd><?= number_format((int)$site['households_connected']) ?></dd>

        <dt>Completion date</dt>
        <dd><?= format_date($site['completed_date']) ?></dd>

        <dt>Submitted by</dt>
        <dd><?= e($site['submitted_by_name']) ?>, <?= format_datetime($site['submitted_at']) ?></dd>

        <?php if ($site['verified_by']): ?>
        <dt>Verified by</dt>
        <dd><?= e($site['verified_by_name']) ?>, <?= format_datetime($site['verified_at']) ?>
            <?php if ($site['verification_notes']): ?><br><?= nl2br(e($site['verification_notes'])) ?><?php endif; ?></dd>
        <?php endif; ?>

        <?php if ($site['approved_by']): ?>
        <dt>Approved by</dt>
        <dd><?= e($site['approved_by_name']) ?>, <?= format_datetime($site['approved_at']) ?>
            <?php if ($site['approval_notes']): ?><br><?= nl2br(e($site['approval_notes'])) ?><?php endif; ?></dd>
        <?php endif; ?>
    </dl>
</div>

<div class="card">
    <h2>Proof of evidence (POE)</h2>
    <?php if (!$docs): ?>
        <p class="hint">No POE documents attached yet.</p>
    <?php else: ?>
        <ul class="file-list">
        <?php foreach ($docs as $d): ?>
            <li>
                <span>
                    <a href="<?= APP_BASE_URL ?>/sites/download_poe.php?id=<?= (int)$d['id'] ?>"><?= e($d['file_name']) ?></a>
                    <?php if ($d['description']): ?> &mdash; <?= e($d['description']) ?><?php endif; ?>
                </span>
                <span class="file-meta"><?= number_format($d['file_size'] / 1024, 0) ?> KB &middot; <?= e($d['uploaded_by_name']) ?>, <?= format_datetime($d['uploaded_at']) ?></span>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($canUpload): ?>
    <h3 style="margin-top:20px;">Attach a POE document</h3>
    <form method="post" action="<?= APP_BASE_URL ?>/sites/upload_poe.php" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="form-grid">
            <div class="field">
                <label for="poe_file">File (PDF, JPG or PNG, max 10 MB)</label>
                <input type="file" id="poe_file" name="poe_file" accept=".pdf,.jpg,.jpeg,.png" required>
            </div>
            <div class="field">
                <label for="description">Description</label>
                <input type="text" id="description" name="description" placeholder="e.g. Completion certificate, site photos">
            </div>
        </div>
        <button type="submit" class="btn">Upload</button>
    </form>
    <?php endif; ?>
</div>

<p><a href="<?= APP_BASE_URL ?>/sites/list.php">&larr; Back to site completions</a></p>

<?php include __DIR__ . '/../includes/footer.php'; ?>
