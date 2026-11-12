<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

sync_overdue_statuses($pdo);

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT i.*, p.project_name, p.project_code,
            owner.full_name AS owner_name,
            creator.full_name AS creator_name,
            confirmer.full_name AS confirmer_name
     FROM indicators i
     JOIN projects p ON p.id = i.project_id
     JOIN users owner ON owner.id = i.responsible_user_id
     JOIN users creator ON creator.id = i.created_by
     LEFT JOIN users confirmer ON confirmer.id = i.confirmed_by
     WHERE i.id = ?"
);
$stmt->execute([$id]);
$indicator = $stmt->fetch();

if (!$indicator) {
    flash('error', 'Indicator not found.');
    redirect('/indicators/list.php');
}

$actualsStmt = $pdo->prepare(
    "SELECT a.*, u.full_name AS reported_by_name
     FROM indicator_actuals a
     JOIN users u ON u.id = a.reported_by
     WHERE a.indicator_id = ?
     ORDER BY a.period_date ASC"
);
$actualsStmt->execute([$id]);
$actuals = $actualsStmt->fetchAll();
$latestActual = $actuals ? end($actuals) : null;

$revStmt = $pdo->prepare(
    "SELECT tr.*, u.full_name AS revised_by_name
     FROM indicator_target_revisions tr
     JOIN users u ON u.id = tr.revised_by
     WHERE tr.indicator_id = ?
     ORDER BY tr.revised_at ASC"
);
$revStmt->execute([$id]);
$revisions = $revStmt->fetchAll();

$canManage = in_array(current_user()['role'], ['admin', 'manager'], true);
$isAchieved = $indicator['status'] === 'Achieved';
$isOverdueUnconfirmed = $indicator['status'] === 'Overdue';

$progress = compute_indicator_progress(
    (float)$indicator['baseline_value'],
    (float)$indicator['target_value'],
    $latestActual ? (float)$latestActual['actual_value'] : null
);

$pageTitle = $indicator['reference_code'];
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1><?= e($indicator['reference_code']) ?> <?= status_badge($indicator['status']) ?> <?= result_level_badge($indicator['result_level']) ?></h1>
        <p class="subtitle"><?= e($indicator['project_name']) ?> (<?= e($indicator['project_code']) ?>)</p>
    </div>
    <?php if ($canManage): ?>
        <div class="actions-row">
            <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indicators/form.php?id=<?= $id ?>">Edit</a>
            <?php if (!$isAchieved): ?>
                <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indicators/revise_target.php?id=<?= $id ?>">Revise target date</a>
                <a class="btn" href="<?= APP_BASE_URL ?>/indicators/achieve.php?id=<?= $id ?>">Confirm achievement</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($isOverdueUnconfirmed): ?>
    <div class="alert alert-error">
        The target date for this indicator (<?= format_date($indicator['current_target_date']) ?>) has passed without confirmed achievement.
        It has been automatically flagged <strong>Overdue</strong>. Revise the target date (with a reason) or confirm achievement once the target is met.
    </div>
<?php endif; ?>

<div class="card">
    <h2>Result &amp; indicator definition</h2>
    <dl class="detail-grid">
        <dt>Result statement</dt>
        <dd><?= nl2br(e($indicator['result_statement'])) ?></dd>

        <dt>Indicator</dt>
        <dd><?= e($indicator['indicator_name']) ?></dd>

        <dt>Definition</dt>
        <dd><?= nl2br(e($indicator['definition'])) ?></dd>

        <dt>Unit of measure</dt>
        <dd><?= e($indicator['unit_of_measure']) ?></dd>

        <dt>Data source</dt>
        <dd><?= e($indicator['data_source']) ?></dd>

        <dt>Collection frequency</dt>
        <dd><?= e($indicator['collection_frequency']) ?></dd>

        <dt>Baseline</dt>
        <dd><?= format_number($indicator['baseline_value']) ?> (<?= e((string)$indicator['baseline_year']) ?>)</dd>

        <dt>Target</dt>
        <dd><?= format_number($indicator['target_value']) ?></dd>

        <dt>Latest actual</dt>
        <dd>
            <?php if ($latestActual): ?>
                <?= format_number($latestActual['actual_value']) ?> (<?= e($latestActual['period_label']) ?>, <?= format_date($latestActual['period_date']) ?>)
            <?php else: ?>
                <span class="hint">No actual values reported yet.</span>
            <?php endif; ?>
        </dd>

        <dt>Progress towards target</dt>
        <dd><?= progress_bar($progress) ?></dd>

        <dt>Responsible person</dt>
        <dd><?= e($indicator['owner_name']) ?></dd>

        <dt>Original target date</dt>
        <dd><?= format_date($indicator['original_target_date']) ?></dd>

        <dt>Current target date</dt>
        <dd><?= format_date($indicator['current_target_date']) ?></dd>

        <dt>Performance commentary</dt>
        <dd><?= $indicator['achievement_notes'] ? nl2br(e($indicator['achievement_notes'])) : '<span class="hint">None recorded.</span>' ?></dd>

        <?php if ($isAchieved): ?>
        <dt>Achievement confirmed</dt>
        <dd><?= format_datetime($indicator['confirmed_at']) ?> by <?= e($indicator['confirmer_name']) ?><br>
            <?= $indicator['confirmation_notes'] ? nl2br(e($indicator['confirmation_notes'])) : '' ?></dd>
        <?php endif; ?>

        <dt>Recorded by</dt>
        <dd><?= e($indicator['creator_name']) ?> on <?= format_date($indicator['created_at']) ?></dd>
    </dl>
</div>

<div class="card">
    <h2>Reported actual values</h2>
    <?php if (!$actuals): ?>
        <p class="hint">No actual values have been reported for this indicator yet.</p>
    <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Period</th><th>Period end date</th><th>Actual value</th><th>Progress</th><th>Comments</th><th>Reported by</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($actuals) as $a): ?>
                <?php $rowProgress = compute_indicator_progress((float)$indicator['baseline_value'], (float)$indicator['target_value'], (float)$a['actual_value']); ?>
                <tr>
                    <td><?= e($a['period_label']) ?></td>
                    <td><?= format_date($a['period_date']) ?></td>
                    <td><?= format_number($a['actual_value']) ?></td>
                    <td><?= progress_bar($rowProgress) ?></td>
                    <td><?= $a['comments'] ? nl2br(e($a['comments'])) : '<span class="hint">&mdash;</span>' ?></td>
                    <td><?= e($a['reported_by_name']) ?>, <?= format_datetime($a['reported_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>

    <?php if ($canManage): ?>
    <h3 style="margin-top:20px;">Report a new period</h3>
    <form method="post" action="<?= APP_BASE_URL ?>/indicators/add_actual.php">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="form-grid">
            <div class="field">
                <label for="period_label">Period label</label>
                <input type="text" id="period_label" name="period_label" required placeholder="e.g. Q2 2026/27">
            </div>
            <div class="field">
                <label for="period_date">Period end date</label>
                <input type="date" id="period_date" name="period_date" required>
            </div>
            <div class="field">
                <label for="actual_value">Actual value</label>
                <input type="text" inputmode="decimal" id="actual_value" name="actual_value" required>
            </div>
            <div class="field full">
                <label for="comments">Comments</label>
                <textarea id="comments" name="comments" placeholder="Context or variance explanation for this period's result."></textarea>
            </div>
        </div>
        <button type="submit" class="btn">Add reported period</button>
    </form>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Target date revision history</h2>
    <?php if (!$revisions): ?>
        <p class="hint">No revisions. The current target date is still the original target date agreed when this indicator was created.</p>
    <?php else: ?>
        <ul class="history-list">
        <?php foreach ($revisions as $r): ?>
            <li>
                <strong><?= format_date($r['previous_target_date']) ?> &rarr; <?= format_date($r['new_target_date']) ?></strong>
                &nbsp;&mdash;&nbsp; <?= e($r['revised_by_name']) ?>, <?= format_datetime($r['revised_at']) ?><br>
                <span class="hint">Reason: <?= nl2br(e($r['reason'])) ?></span>
            </li>
        <?php endforeach; ?>
        </ul>
        <p class="hint">Original target date on file: <strong><?= format_date($indicator['original_target_date']) ?></strong> (never overwritten).</p>
    <?php endif; ?>
</div>

<p><a href="<?= APP_BASE_URL ?>/indicators/list.php">&larr; Back to indicators</a></p>

<?php include __DIR__ . '/../includes/footer.php'; ?>
