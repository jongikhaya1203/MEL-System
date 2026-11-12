<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

sync_overdue_statuses($pdo);

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT f.*, p.project_name, p.project_code,
            owner.full_name AS owner_name,
            creator.full_name AS creator_name,
            closer.full_name AS closer_name
     FROM findings_register f
     JOIN projects p ON p.id = f.project_id
     JOIN users owner ON owner.id = f.owner_user_id
     JOIN users creator ON creator.id = f.created_by
     LEFT JOIN users closer ON closer.id = f.closed_by
     WHERE f.id = ?"
);
$stmt->execute([$id]);
$finding = $stmt->fetch();

if (!$finding) {
    flash('error', 'Finding not found.');
    redirect('/findings/list.php');
}

$revStmt = $pdo->prepare(
    "SELECT dr.*, u.full_name AS revised_by_name
     FROM deadline_revisions dr
     JOIN users u ON u.id = dr.revised_by
     WHERE dr.finding_id = ?
     ORDER BY dr.revised_at ASC"
);
$revStmt->execute([$id]);
$revisions = $revStmt->fetchAll();

$canManage = in_array(current_user()['role'], ['admin', 'manager'], true);
$isClosed = $finding['status'] === 'Closed';
$isOverdueUnconfirmed = $finding['status'] === 'Overdue';

$pageTitle = $finding['reference_code'];
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1><?= e($finding['reference_code']) ?> <?= status_badge($finding['status']) ?></h1>
        <p class="subtitle"><?= e($finding['project_name']) ?> (<?= e($finding['project_code']) ?>)</p>
    </div>
    <?php if ($canManage && !$isClosed): ?>
        <div class="actions-row">
            <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/findings/form.php?id=<?= $id ?>">Edit</a>
            <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/findings/revise_deadline.php?id=<?= $id ?>">Revise deadline</a>
            <a class="btn" href="<?= APP_BASE_URL ?>/findings/close.php?id=<?= $id ?>">Confirm closure</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($isOverdueUnconfirmed): ?>
    <div class="alert alert-error">
        The deadline for this item (<?= format_date($finding['current_deadline']) ?>) has passed without confirmed closure.
        It has been automatically flagged <strong>Overdue</strong>. Revise the deadline (with a reason) or confirm closure once the action is complete.
    </div>
<?php endif; ?>

<div class="card">
    <h2>Finding, lesson &amp; response</h2>
    <dl class="detail-grid">
        <dt>Source and date</dt>
        <dd><?= e($finding['source']) ?> &mdash; <?= format_date($finding['source_date']) ?></dd>

        <dt>Evidence-based finding</dt>
        <dd><?= nl2br(e($finding['evidence_based_finding'])) ?></dd>

        <dt>Lesson drawn</dt>
        <dd><?= nl2br(e($finding['lesson_drawn'])) ?></dd>

        <dt>Evidence and limitations</dt>
        <dd><?= nl2br(e($finding['evidence_and_limitations'])) ?></dd>

        <dt>Implication</dt>
        <dd><?= nl2br(e($finding['implication'])) ?></dd>

        <dt>Agreed management response</dt>
        <dd><?= nl2br(e($finding['management_response'])) ?></dd>

        <dt>Owner</dt>
        <dd><?= e($finding['owner_name']) ?></dd>

        <dt>Original deadline</dt>
        <dd><?= format_date($finding['original_deadline']) ?></dd>

        <dt>Current deadline</dt>
        <dd><?= format_date($finding['current_deadline']) ?></dd>

        <dt>Follow-up outcome</dt>
        <dd><?= $finding['follow_up_outcome'] ? nl2br(e($finding['follow_up_outcome'])) : '<span class="hint">Not yet recorded.</span>' ?></dd>

        <?php if ($isClosed): ?>
        <dt>Closed</dt>
        <dd><?= format_datetime($finding['closed_at']) ?> by <?= e($finding['closer_name']) ?><br>
            <?= $finding['closure_notes'] ? nl2br(e($finding['closure_notes'])) : '' ?></dd>
        <?php endif; ?>

        <dt>Recorded by</dt>
        <dd><?= e($finding['creator_name']) ?> on <?= format_date($finding['created_at']) ?></dd>
    </dl>
</div>

<div class="card">
    <h2>Deadline revision history</h2>
    <?php if (!$revisions): ?>
        <p class="hint">No revisions. The current deadline is still the original deadline agreed when this item was created.</p>
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
        <p class="hint">Original deadline on file: <strong><?= format_date($finding['original_deadline']) ?></strong> (never overwritten).</p>
    <?php endif; ?>
</div>

<p><a href="<?= APP_BASE_URL ?>/findings/list.php">&larr; Back to register</a></p>

<?php include __DIR__ . '/../includes/footer.php'; ?>
