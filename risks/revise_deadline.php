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
    flash('error', 'This risk is closed; its deadline can no longer be revised.');
    redirect('/risks/view.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $newDeadline = $_POST['new_deadline'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    if (!$newDeadline || !strtotime($newDeadline)) {
        flash('error', 'Please provide a valid new deadline.');
        redirect('/risks/revise_deadline.php?id=' . $id);
    }
    if ($reason === '') {
        flash('error', 'A reason for the change is required so the original date and rationale remain on record.');
        redirect('/risks/revise_deadline.php?id=' . $id);
    }

    $pdo->beginTransaction();
    try {
        // original_deadline is intentionally never touched here.
        $ins = $pdo->prepare(
            'INSERT INTO risk_deadline_revisions (risk_id, previous_deadline, new_deadline, reason, revised_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $ins->execute([$id, $risk['current_deadline'], $newDeadline, $reason, current_user()['id']]);

        $newStatus = (strtotime($newDeadline) < strtotime(date('Y-m-d'))) ? 'Overdue' : 'In Progress';
        $upd = $pdo->prepare(
            "UPDATE risks SET current_deadline = ?, status = ? WHERE id = ?"
        );
        $upd->execute([$newDeadline, $newStatus, $id]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    flash('success', 'Deadline revised. The original deadline and reason for this change are preserved in the history.');
    redirect('/risks/view.php?id=' . $id);
}

$pageTitle = 'Revise deadline';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Revise deadline &mdash; <?= e($risk['reference_code']) ?></h1>
        <p class="subtitle">The original deadline is preserved; this records a new current deadline and the reason for the change.</p>
    </div>
</div>

<div class="card">
    <dl class="detail-grid">
        <dt>Original deadline</dt>
        <dd><?= format_date($risk['original_deadline']) ?> (fixed, never changes)</dd>
        <dt>Current deadline</dt>
        <dd><?= format_date($risk['current_deadline']) ?></dd>
    </dl>
</div>

<div class="card">
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="field">
            <label for="new_deadline">New deadline</label>
            <input type="date" id="new_deadline" name="new_deadline" required value="<?= e($risk['current_deadline']) ?>">
        </div>
        <div class="field">
            <label for="reason">Reason for change</label>
            <textarea id="reason" name="reason" required placeholder="Why the mitigation deadline is being revised."></textarea>
        </div>
        <button type="submit" class="btn">Save revised deadline</button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/risks/view.php?id=<?= $id ?>">Cancel</a>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
