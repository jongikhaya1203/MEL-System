<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'field_worker']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    redirect('/sites/list.php');
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT s.*, rej.full_name AS rejected_by_name
     FROM sites s
     LEFT JOIN users rej ON rej.id = s.rejected_by
     WHERE s.id = ?'
);
$stmt->execute([$id]);
$site = $stmt->fetch();

if (!$site) {
    flash('error', 'Site not found.');
    redirect('/sites/list.php');
}
$user = current_user();
if ($user['role'] !== 'admin' && (int)$site['submitted_by'] !== (int)$user['id']) {
    flash('error', 'You can only resubmit your own submissions.');
    redirect('/sites/view.php?id=' . $id);
}
if ($site['status'] !== 'Rejected') {
    flash('error', 'Only rejected submissions can be resubmitted.');
    redirect('/sites/view.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $stmt = $pdo->prepare(
        "UPDATE sites SET status = 'Submitted', submitted_at = NOW() WHERE id = ? AND status = 'Rejected'"
    );
    $stmt->execute([$id]);
    flash('success', 'Resubmitted for verification.');
    redirect('/sites/view.php?id=' . $id);
}

$pageTitle = 'Resubmit site';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Resubmit &mdash; <?= e($site['site_code']) ?></h1>
        <p class="subtitle">This moves the record back to "Submitted" so the reviewer can verify it again. The previous rejection reason stays on record below.</p>
    </div>
</div>

<div class="alert alert-info">
    Previously rejected by <?= e($site['rejected_by_name'] ?? '') ?> on <?= format_datetime($site['rejected_at']) ?>:<br>
    <?= nl2br(e($site['rejection_reason'])) ?>
</div>

<div class="card">
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <button type="submit" class="btn">Resubmit for verification</button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/sites/view.php?id=<?= $id ?>">Cancel</a>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
