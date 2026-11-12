<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'back_office']);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM sites WHERE id = ?');
$stmt->execute([$id]);
$site = $stmt->fetch();

if (!$site) {
    flash('error', 'Site not found.');
    redirect('/sites/list.php');
}
if ($site['status'] !== 'Submitted') {
    flash('error', 'This site is not awaiting verification.');
    redirect('/sites/view.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $user = current_user();

    if ($action === 'verify') {
        $notes = trim($_POST['verification_notes'] ?? '');
        $stmt = $pdo->prepare(
            "UPDATE sites SET status = 'Verified', verified_by = ?, verified_at = NOW(), verification_notes = ?
             WHERE id = ? AND status = 'Submitted'"
        );
        $stmt->execute([$user['id'], $notes !== '' ? $notes : null, $id]);
        if ($stmt->rowCount() === 0) {
            flash('error', 'This site could not be verified (its status may have changed).');
        } else {
            notify_installer_of_status_change($pdo, $id, 'Verified');
            flash('success', 'Site verified and passed to the approver.');
        }
        redirect('/sites/view.php?id=' . $id);
    }

    if ($action === 'reject') {
        $reason = trim($_POST['rejection_reason'] ?? '');
        if ($reason === '') {
            flash('error', 'A rejection reason is required.');
            redirect('/sites/verify.php?id=' . $id);
        }
        $stmt = $pdo->prepare(
            "UPDATE sites SET status = 'Rejected', rejected_by = ?, rejected_at = NOW(), rejection_reason = ?
             WHERE id = ? AND status = 'Submitted'"
        );
        $stmt->execute([$user['id'], $reason, $id]);
        notify_installer_of_status_change($pdo, $id, 'Rejected', $reason);
        flash('success', 'Site rejected and sent back to the installer.');
        redirect('/sites/view.php?id=' . $id);
    }

    flash('error', 'Unknown action.');
    redirect('/sites/view.php?id=' . $id);
}

$pageTitle = 'Verify site';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Verify &mdash; <?= e($site['site_code']) ?></h1>
        <p class="subtitle"><?= e($site['site_name']) ?></p>
    </div>
</div>

<div class="card">
    <h2>Verify</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="action" value="verify">
        <div class="field">
            <label for="verification_notes">Verification notes (optional)</label>
            <textarea id="verification_notes" name="verification_notes" placeholder="What was checked against the POE."></textarea>
        </div>
        <button type="submit" class="btn">Verify &amp; pass to approver</button>
    </form>
</div>

<div class="card">
    <h2>Reject</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="action" value="reject">
        <div class="field">
            <label for="rejection_reason">Rejection reason</label>
            <textarea id="rejection_reason" name="rejection_reason" required placeholder="Why this submission is being sent back to the installer."></textarea>
        </div>
        <button type="submit" class="btn btn-danger">Reject</button>
    </form>
</div>

<p><a href="<?= APP_BASE_URL ?>/sites/view.php?id=<?= $id ?>">&larr; Back to record</a></p>

<?php include __DIR__ . '/../includes/footer.php'; ?>
