<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'manager']);

sync_overdue_statuses($pdo);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM indicators WHERE id = ?');
$stmt->execute([$id]);
$indicator = $stmt->fetch();

if (!$indicator) {
    flash('error', 'Indicator not found.');
    redirect('/indicators/list.php');
}
if ($indicator['status'] === 'Achieved') {
    flash('error', 'This indicator has been marked Achieved; its target date can no longer be revised.');
    redirect('/indicators/view.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $newDate = $_POST['new_target_date'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    if (!$newDate || !strtotime($newDate)) {
        flash('error', 'Please provide a valid new target date.');
        redirect('/indicators/revise_target.php?id=' . $id);
    }
    if ($reason === '') {
        flash('error', 'A reason for the change is required so the original date and rationale remain on record.');
        redirect('/indicators/revise_target.php?id=' . $id);
    }

    $pdo->beginTransaction();
    try {
        // original_target_date is intentionally never touched here.
        $ins = $pdo->prepare(
            'INSERT INTO indicator_target_revisions (indicator_id, previous_target_date, new_target_date, reason, revised_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $ins->execute([$id, $indicator['current_target_date'], $newDate, $reason, current_user()['id']]);

        $newStatus = (strtotime($newDate) < strtotime(date('Y-m-d'))) ? 'Overdue' : 'In Progress';
        $upd = $pdo->prepare(
            "UPDATE indicators SET current_target_date = ?, status = ? WHERE id = ?"
        );
        $upd->execute([$newDate, $newStatus, $id]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    flash('success', 'Target date revised. The original target date and reason for this change are preserved in the history.');
    redirect('/indicators/view.php?id=' . $id);
}

$pageTitle = 'Revise target date';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Revise target date &mdash; <?= e($indicator['reference_code']) ?></h1>
        <p class="subtitle">The original target date is preserved; this records a new current target date and the reason for the change.</p>
    </div>
</div>

<div class="card">
    <dl class="detail-grid">
        <dt>Original target date</dt>
        <dd><?= format_date($indicator['original_target_date']) ?> (fixed, never changes)</dd>
        <dt>Current target date</dt>
        <dd><?= format_date($indicator['current_target_date']) ?></dd>
    </dl>
</div>

<div class="card">
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="field">
            <label for="new_target_date">New target date</label>
            <input type="date" id="new_target_date" name="new_target_date" required value="<?= e($indicator['current_target_date']) ?>">
        </div>
        <div class="field">
            <label for="reason">Reason for change</label>
            <textarea id="reason" name="reason" required placeholder="Why the target date is being revised."></textarea>
        </div>
        <button type="submit" class="btn">Save revised target date</button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indicators/view.php?id=<?= $id ?>">Cancel</a>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
