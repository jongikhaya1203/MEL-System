<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'manager']);

sync_overdue_statuses($pdo);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM risks WHERE id = ?');
$stmt->execute([$id]);
$risk = $stmt->fetch();

if (!$risk) {
    flash('error', 'Risk not found.');
    redirect('/risks/list.php');
}
if ($risk['status'] === 'Closed') {
    flash('info', 'This risk is already closed.');
    redirect('/risks/view.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $outcome = trim($_POST['follow_up_outcome'] ?? '');
    $notes = trim($_POST['closure_notes'] ?? '');

    if ($outcome === '') {
        flash('error', 'Please record the follow-up outcome before confirming closure.');
        redirect('/risks/close.php?id=' . $id);
    }

    $stmt = $pdo->prepare(
        "UPDATE risks
         SET status = 'Closed', follow_up_outcome = ?, closure_notes = ?, closed_by = ?, closed_at = NOW()
         WHERE id = ? AND status <> 'Closed'"
    );
    $stmt->execute([$outcome, $notes, current_user()['id'], $id]);

    if ($stmt->rowCount() === 0) {
        flash('error', 'This risk could not be closed (it may already be closed).');
    } else {
        flash('success', 'Closure confirmed.');
    }
    redirect('/risks/view.php?id=' . $id);
}

$pageTitle = 'Confirm closure';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Confirm closure &mdash; <?= e($risk['reference_code']) ?></h1>
        <p class="subtitle">Status only changes to Closed through this explicit, recorded action.</p>
    </div>
</div>

<?php if ($risk['status'] === 'Overdue'): ?>
    <div class="alert alert-info">
        This risk's mitigation deadline (<?= format_date($risk['current_deadline']) ?>) has already passed. Closing it now will record who confirmed closure and when, alongside the final follow-up outcome.
    </div>
<?php endif; ?>

<div class="card">
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="field">
            <label for="follow_up_outcome">Follow-up outcome</label>
            <textarea id="follow_up_outcome" name="follow_up_outcome" required
                      placeholder="What was done and what resulted."><?= e($risk['follow_up_outcome'] ?? '') ?></textarea>
        </div>
        <div class="field">
            <label for="closure_notes">Closure notes (optional)</label>
            <textarea id="closure_notes" name="closure_notes" placeholder="Any additional context for the closure."></textarea>
        </div>
        <button type="submit" class="btn">Confirm closure</button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/risks/view.php?id=<?= $id ?>">Cancel</a>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
