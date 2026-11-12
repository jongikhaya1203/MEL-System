<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'manager']);

sync_overdue_statuses($pdo);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM findings_register WHERE id = ?');
$stmt->execute([$id]);
$finding = $stmt->fetch();

if (!$finding) {
    flash('error', 'Finding not found.');
    redirect('/findings/list.php');
}
if ($finding['status'] === 'Closed') {
    flash('info', 'This item is already closed.');
    redirect('/findings/view.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $outcome = trim($_POST['follow_up_outcome'] ?? '');
    $notes = trim($_POST['closure_notes'] ?? '');

    if ($outcome === '') {
        flash('error', 'Please record the follow-up outcome before confirming closure.');
        redirect('/findings/close.php?id=' . $id);
    }

    $stmt = $pdo->prepare(
        "UPDATE findings_register
         SET status = 'Closed', follow_up_outcome = ?, closure_notes = ?, closed_by = ?, closed_at = NOW()
         WHERE id = ? AND status <> 'Closed'"
    );
    $stmt->execute([$outcome, $notes, current_user()['id'], $id]);

    if ($stmt->rowCount() === 0) {
        flash('error', 'This item could not be closed (it may already be closed).');
    } else {
        flash('success', 'Closure confirmed.');
    }
    redirect('/findings/view.php?id=' . $id);
}

$pageTitle = 'Confirm closure';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Confirm closure &mdash; <?= e($finding['reference_code']) ?></h1>
        <p class="subtitle">Status only changes to Closed through this explicit, recorded action.</p>
    </div>
</div>

<?php if ($finding['status'] === 'Overdue'): ?>
    <div class="alert alert-info">
        This item's deadline (<?= format_date($finding['current_deadline']) ?>) has already passed. Closing it now will record who confirmed closure and when, alongside the final follow-up outcome.
    </div>
<?php endif; ?>

<div class="card">
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="field">
            <label for="follow_up_outcome">Follow-up outcome</label>
            <textarea id="follow_up_outcome" name="follow_up_outcome" required
                      placeholder="What was done and what resulted."><?= e($finding['follow_up_outcome'] ?? '') ?></textarea>
        </div>
        <div class="field">
            <label for="closure_notes">Closure notes (optional)</label>
            <textarea id="closure_notes" name="closure_notes" placeholder="Any additional context for the closure."></textarea>
        </div>
        <button type="submit" class="btn">Confirm closure</button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/findings/view.php?id=<?= $id ?>">Cancel</a>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
