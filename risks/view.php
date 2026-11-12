<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

sync_overdue_statuses($pdo);

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT r.*, p.project_name, p.project_code,
            owner.full_name AS owner_name,
            creator.full_name AS creator_name,
            closer.full_name AS closer_name
     FROM risks r
     JOIN projects p ON p.id = r.project_id
     JOIN users owner ON owner.id = r.owner_user_id
     JOIN users creator ON creator.id = r.created_by
     LEFT JOIN users closer ON closer.id = r.closed_by
     WHERE r.id = ?"
);
$stmt->execute([$id]);
$risk = $stmt->fetch();

if (!$risk) {
    flash('error', 'Risk not found.');
    redirect('/risks/list.php');
}

$revStmt = $pdo->prepare(
    "SELECT dr.*, u.full_name AS revised_by_name
     FROM risk_deadline_revisions dr
     JOIN users u ON u.id = dr.revised_by
     WHERE dr.risk_id = ?
     ORDER BY dr.revised_at ASC"
);
$revStmt->execute([$id]);
$revisions = $revStmt->fetchAll();

$canManage = in_array(current_user()['role'], ['admin', 'manager'], true);
$isClosed = $risk['status'] === 'Closed';
$isOverdueUnconfirmed = $risk['status'] === 'Overdue';

$pageTitle = $risk['reference_code'];
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1><?= e($risk['reference_code']) ?> <?= status_badge($risk['status']) ?> <?= risk_rating_badge($risk['risk_rating']) ?></h1>
        <p class="subtitle"><?= e($risk['project_name']) ?> (<?= e($risk['project_code']) ?>) &mdash; <?= e($risk['category']) ?></p>
    </div>
    <?php if ($canManage && !$isClosed): ?>
        <div class="actions-row">
            <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/risks/form.php?id=<?= $id ?>">Edit</a>
            <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/risks/revise_deadline.php?id=<?= $id ?>">Revise deadline</a>
            <a class="btn" href="<?= APP_BASE_URL ?>/risks/close.php?id=<?= $id ?>">Confirm closure</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($isOverdueUnconfirmed): ?>
    <div class="alert alert-error">
        The mitigation deadline for this risk (<?= format_date($risk['current_deadline']) ?>) has passed without confirmed closure.
        It has been automatically flagged <strong>Overdue</strong>. Revise the deadline (with a reason) or confirm closure once the mitigation action is complete.
    </div>
<?php endif; ?>

<div class="card">
    <h2>Risk, controls &amp; mitigation</h2>
    <dl class="detail-grid">
        <dt>Date identified</dt>
        <dd><?= format_date($risk['date_identified']) ?></dd>

        <dt>Risk description</dt>
        <dd><?= nl2br(e($risk['risk_description'])) ?></dd>

        <dt>Existing controls</dt>
        <dd><?= nl2br(e($risk['existing_controls'])) ?></dd>

        <dt>Likelihood / Impact</dt>
        <dd><?= e($risk['likelihood']) ?> / <?= e($risk['impact']) ?> &rarr; <?= risk_rating_badge($risk['risk_rating']) ?></dd>

        <dt>Agreed mitigation action</dt>
        <dd><?= nl2br(e($risk['mitigation_action'])) ?></dd>

        <dt>Owner</dt>
        <dd><?= e($risk['owner_name']) ?></dd>

        <dt>Original deadline</dt>
        <dd><?= format_date($risk['original_deadline']) ?></dd>

        <dt>Current deadline</dt>
        <dd><?= format_date($risk['current_deadline']) ?></dd>

        <dt>Follow-up outcome</dt>
        <dd><?= $risk['follow_up_outcome'] ? nl2br(e($risk['follow_up_outcome'])) : '<span class="hint">Not yet recorded.</span>' ?></dd>

        <?php if ($isClosed): ?>
        <dt>Closed</dt>
        <dd><?= format_datetime($risk['closed_at']) ?> by <?= e($risk['closer_name']) ?><br>
            <?= $risk['closure_notes'] ? nl2br(e($risk['closure_notes'])) : '' ?></dd>
        <?php endif; ?>

        <dt>Recorded by</dt>
        <dd><?= e($risk['creator_name']) ?> on <?= format_date($risk['created_at']) ?></dd>
    </dl>
</div>

<div class="card">
    <h2>Deadline revision history</h2>
    <?php if (!$revisions): ?>
        <p class="hint">No revisions. The current deadline is still the original deadline agreed when this risk was logged.</p>
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
        <p class="hint">Original deadline on file: <strong><?= format_date($risk['original_deadline']) ?></strong> (never overwritten).</p>
    <?php endif; ?>
</div>

<p><a href="<?= APP_BASE_URL ?>/risks/list.php">&larr; Back to risk register</a></p>

<?php include __DIR__ . '/../includes/footer.php'; ?>
