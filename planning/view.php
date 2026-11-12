<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

sync_overdue_statuses($pdo);

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT a.*, p.project_name, p.province, inst.full_name AS installer_name,
            dispatcher.full_name AS dispatcher_name,
            ind.indicator_name, ind.reference_code AS indicator_reference_code
     FROM installer_allocations a
     JOIN projects p ON p.id = a.project_id
     JOIN users inst ON inst.id = a.installer_id
     JOIN users dispatcher ON dispatcher.id = a.dispatched_by
     LEFT JOIN indicators ind ON ind.id = a.indicator_id
     WHERE a.id = ?"
);
$stmt->execute([$id]);
$allocation = $stmt->fetch();

if (!$allocation) {
    flash('error', 'Allocation not found.');
    redirect('/planning/list.php');
}

$progressStmt = $pdo->prepare(
    "SELECT ap.*, u.full_name AS reported_by_name
     FROM allocation_progress_updates ap
     JOIN users u ON u.id = ap.reported_by
     WHERE ap.allocation_id = ?
     ORDER BY ap.period_date ASC"
);
$progressStmt->execute([$id]);
$progressUpdates = $progressStmt->fetchAll();
$latestUpdate = $progressUpdates ? end($progressUpdates) : null;

$revStmt = $pdo->prepare(
    "SELECT dr.*, u.full_name AS revised_by_name
     FROM allocation_deadline_revisions dr
     JOIN users u ON u.id = dr.revised_by
     WHERE dr.allocation_id = ?
     ORDER BY dr.revised_at ASC"
);
$revStmt->execute([$id]);
$revisions = $revStmt->fetchAll();

$role = current_user()['role'];
$canManage = in_array($role, ['admin', 'manager', 'planner'], true);
$canLogProgress = $canManage || $role === 'back_office'
    || ($role === 'field_worker' && (int)$allocation['installer_id'] === (int)current_user()['id']);
$isCompleted = $allocation['status'] === 'Completed';
$isOverdue = $allocation['status'] === 'Overdue';

$progress = compute_allocation_progress(
    (int)$allocation['target_installations'],
    $latestUpdate ? (float)$latestUpdate['installations_completed'] : null
);

$pageTitle = $allocation['reference_code'];
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1><?= e($allocation['reference_code']) ?> <?= status_badge($allocation['status']) ?></h1>
        <p class="subtitle"><?= e($allocation['province'] ?? $allocation['project_name']) ?> &mdash; <?= e($allocation['region']) ?></p>
    </div>
    <?php if ($canManage): ?>
        <div class="actions-row">
            <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/planning/form.php?id=<?= $id ?>">Edit</a>
            <?php if (!$isCompleted): ?>
                <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/planning/revise_deadline.php?id=<?= $id ?>">Revise deadline</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($isOverdue): ?>
    <div class="alert alert-error">
        The deadline for this allocation (<?= format_date($allocation['current_deadline']) ?>) has passed without reaching the target installation count.
        It has been automatically flagged <strong>Overdue</strong>. Revise the deadline (with a reason) or log progress once more installations are completed.
    </div>
<?php endif; ?>

<div class="card">
    <h2>Allocation</h2>
    <dl class="detail-grid">
        <dt>Province</dt>
        <dd><?= e($allocation['province'] ?? $allocation['project_name']) ?> (<?= e($allocation['project_name']) ?>)</dd>

        <dt>Region</dt>
        <dd><?= e($allocation['region']) ?></dd>

        <dt>Installer</dt>
        <dd><?= e($allocation['installer_name']) ?></dd>

        <dt>Linked indicator</dt>
        <dd>
            <?php if ($allocation['indicator_id']): ?>
                <a href="<?= APP_BASE_URL ?>/indicators/view.php?id=<?= (int)$allocation['indicator_id'] ?>"><?= e($allocation['indicator_name']) ?> (<?= e($allocation['indicator_reference_code']) ?>)</a>
                <p class="hint">Progress logged below also reports an actual value against this indicator.</p>
            <?php else: ?>
                <span class="hint">None linked.</span>
            <?php endif; ?>
        </dd>

        <dt>Target installations</dt>
        <dd><?= number_format((int)$allocation['target_installations']) ?></dd>

        <dt>Latest completed</dt>
        <dd>
            <?php if ($latestUpdate): ?>
                <?= number_format((int)$latestUpdate['installations_completed']) ?> (<?= e($latestUpdate['period_label']) ?>, <?= format_date($latestUpdate['period_date']) ?>)
            <?php else: ?>
                <span class="hint">No progress reported yet.</span>
            <?php endif; ?>
        </dd>

        <dt>Progress towards target</dt>
        <dd><?= progress_bar($progress) ?></dd>

        <dt>Original deadline</dt>
        <dd><?= format_date($allocation['original_deadline']) ?></dd>

        <dt>Current deadline</dt>
        <dd><?= format_date($allocation['current_deadline']) ?></dd>

        <dt>Notes</dt>
        <dd><?= $allocation['notes'] ? nl2br(e($allocation['notes'])) : '<span class="hint">None.</span>' ?></dd>

        <dt>Dispatched by</dt>
        <dd><?= e($allocation['dispatcher_name']) ?> on <?= format_datetime($allocation['dispatched_at']) ?></dd>
    </dl>
</div>

<div class="card">
    <h2>Progress updates</h2>
    <?php if (!$progressUpdates): ?>
        <p class="hint">No progress has been reported for this allocation yet.</p>
    <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Period</th><th>Period date</th><th>Installations completed</th><th>Progress</th><th>Comments</th><th>Reported by</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($progressUpdates) as $u): ?>
                <?php $rowProgress = compute_allocation_progress((int)$allocation['target_installations'], (float)$u['installations_completed']); ?>
                <tr>
                    <td><?= e($u['period_label']) ?></td>
                    <td><?= format_date($u['period_date']) ?></td>
                    <td><?= number_format((int)$u['installations_completed']) ?></td>
                    <td><?= progress_bar($rowProgress) ?></td>
                    <td><?= $u['comments'] ? nl2br(e($u['comments'])) : '<span class="hint">&mdash;</span>' ?></td>
                    <td><?= e($u['reported_by_name']) ?>, <?= format_datetime($u['reported_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>

    <?php if ($canLogProgress && !$isCompleted): ?>
    <h3 style="margin-top:20px;">Report progress</h3>
    <form method="post" action="<?= APP_BASE_URL ?>/planning/add_progress.php">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="form-grid">
            <div class="field">
                <label for="period_label">Period label</label>
                <input type="text" id="period_label" name="period_label" required placeholder="e.g. Week 12, Q2 2026/27">
            </div>
            <div class="field">
                <label for="period_date">Period date</label>
                <input type="date" id="period_date" name="period_date" required>
            </div>
            <div class="field">
                <label for="installations_completed">Installations completed (cumulative total so far)</label>
                <input type="text" inputmode="numeric" id="installations_completed" name="installations_completed" required>
            </div>
            <div class="field full">
                <label for="comments">Comments</label>
                <textarea id="comments" name="comments" placeholder="Context, blockers, or variance explanation for this period's progress."></textarea>
            </div>
        </div>
        <button type="submit" class="btn">Add progress update</button>
    </form>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Deadline revision history</h2>
    <?php if (!$revisions): ?>
        <p class="hint">No revisions. The current deadline is still the original deadline agreed when this allocation was dispatched.</p>
    <?php else: ?>
        <ul class="history-list">
        <?php foreach ($revisions as $r): ?>
            <li>
                <strong><?= format_date($r['previous_deadline']) ?> &rarr; <?= format_date($r['new_deadline']) ?></strong>
                &nbsp;&mdash;&nbsp; <?= e($r['revised_by_name']) ?>, <?= format_datetime($r['revised_at']) ?><br>
                <span class="hint">Reason: <?= nl2br(e($r['reason'])) ?></span>
            </li>
        <?php endforeach; ?>
        </ul>
        <p class="hint">Original deadline on file: <strong><?= format_date($allocation['original_deadline']) ?></strong> (never overwritten).</p>
    <?php endif; ?>
</div>

<p><a href="<?= APP_BASE_URL ?>/planning/list.php">&larr; Back to allocations</a></p>

<?php include __DIR__ . '/../includes/footer.php'; ?>
