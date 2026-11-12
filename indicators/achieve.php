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
    flash('info', 'This indicator is already marked Achieved.');
    redirect('/indicators/view.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $notes = trim($_POST['achievement_notes'] ?? '');
    $confirmationNotes = trim($_POST['confirmation_notes'] ?? '');

    if ($notes === '') {
        flash('error', 'Please record the performance commentary before confirming achievement.');
        redirect('/indicators/achieve.php?id=' . $id);
    }

    $stmt = $pdo->prepare(
        "UPDATE indicators
         SET status = 'Achieved', achievement_notes = ?, confirmation_notes = ?, confirmed_by = ?, confirmed_at = NOW()
         WHERE id = ? AND status <> 'Achieved'"
    );
    $stmt->execute([$notes, $confirmationNotes, current_user()['id'], $id]);

    if ($stmt->rowCount() === 0) {
        flash('error', 'This indicator could not be confirmed as achieved (it may already be confirmed).');
    } else {
        flash('success', 'Achievement confirmed.');
    }
    redirect('/indicators/view.php?id=' . $id);
}

$pageTitle = 'Confirm achievement';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Confirm achievement &mdash; <?= e($indicator['reference_code']) ?></h1>
        <p class="subtitle">Status only changes to Achieved through this explicit, recorded action.</p>
    </div>
</div>

<?php if ($indicator['status'] === 'Overdue'): ?>
    <div class="alert alert-info">
        This indicator's target date (<?= format_date($indicator['current_target_date']) ?>) has already passed. Confirming achievement now will record who confirmed it and when, alongside the final performance commentary.
    </div>
<?php endif; ?>

<div class="card">
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="field">
            <label for="achievement_notes">Performance commentary</label>
            <textarea id="achievement_notes" name="achievement_notes" required
                      placeholder="How the target was met."><?= e($indicator['achievement_notes'] ?? '') ?></textarea>
        </div>
        <div class="field">
            <label for="confirmation_notes">Confirmation notes (optional)</label>
            <textarea id="confirmation_notes" name="confirmation_notes" placeholder="Any additional context for the confirmation."></textarea>
        </div>
        <button type="submit" class="btn">Confirm achievement</button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indicators/view.php?id=<?= $id ?>">Cancel</a>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
