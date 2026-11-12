<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'manager', 'planner']);

sync_overdue_statuses($pdo);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM installer_allocations WHERE id = ?');
$stmt->execute([$id]);
$allocation = $stmt->fetch();

if (!$allocation) {
    flash('error', 'Allocation not found.');
    redirect('/planning/list.php');
}
if ($allocation['status'] === 'Completed') {
    flash('error', 'This allocation is complete; its deadline can no longer be revised.');
    redirect('/planning/view.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $newDeadline = $_POST['new_deadline'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    if (!$newDeadline || !strtotime($newDeadline)) {
        flash('error', 'Please provide a valid new deadline.');
        redirect('/planning/revise_deadline.php?id=' . $id);
    }
    if ($reason === '') {
        flash('error', 'A reason for the change is required so the original date and rationale remain on record.');
        redirect('/planning/revise_deadline.php?id=' . $id);
    }

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            'INSERT INTO allocation_deadline_revisions (allocation_id, previous_deadline, new_deadline, reason, revised_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $ins->execute([$id, $allocation['current_deadline'], $newDeadline, $reason, current_user()['id']]);

        $newStatus = (strtotime($newDeadline) < strtotime(date('Y-m-d'))) ? 'Overdue' : 'In Progress';
        $pdo->prepare("UPDATE installer_allocations SET current_deadline = ?, status = ? WHERE id = ?")
            ->execute([$newDeadline, $newStatus, $id]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    flash('success', 'Deadline revised. The original deadline and reason for this change are preserved in the history.');
    redirect('/planning/view.php?id=' . $id);
}

$pageTitle = 'Revise deadline';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Revise deadline &mdash; <?= e($allocation['reference_code']) ?></h1>
        <p class="subtitle">The original deadline is preserved; this records a new current deadline and the reason for the change.</p>
    </div>
</div>

<div class="card">
    <dl class="detail-grid">
        <dt>Original deadline</dt>
        <dd><?= format_date($allocation['original_deadline']) ?> (fixed, never changes)</dd>
        <dt>Current deadline</dt>
        <dd><?= format_date($allocation['current_deadline']) ?></dd>
    </dl>
</div>

<div class="card">
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="field">
            <label for="new_deadline">New deadline</label>
            <input type="date" id="new_deadline" name="new_deadline" required value="<?= e($allocation['current_deadline']) ?>">
        </div>
        <div class="field">
            <label for="reason">Reason for change</label>
            <textarea id="reason" name="reason" required placeholder="Why the dispatch deadline is being revised."></textarea>
        </div>
        <button type="submit" class="btn">Save revised deadline</button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/planning/view.php?id=<?= $id ?>">Cancel</a>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
