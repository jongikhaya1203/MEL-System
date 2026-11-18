<?php
/** Programmes > Digital Transformation — overview of every workstream's deliverables and sign-off progress. */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();
$user = current_user();

$projects = $pdo->query(
    "SELECT p.*, COUNT(d.id) AS total,
            SUM(d.status = 'Signed Off') AS signed_off,
            SUM(d.status = 'Awaiting Sign-off') AS awaiting,
            SUM(d.status = 'Rejected') AS rejected
     FROM dt_projects p LEFT JOIN dt_deliverables d ON d.project_id = p.id
     GROUP BY p.id ORDER BY p.sort_order, p.name"
)->fetchAll();

$totals = ['total' => 0, 'signed_off' => 0, 'awaiting' => 0, 'rejected' => 0];
foreach ($projects as $p) {
    foreach ($totals as $k => $_) {
        $totals[$k] += (int)$p[$k];
    }
}
$myPending = dt_pending_count_for_user($pdo, (int)$user['id']);

$recent = $pdo->query(
    "SELECT s.*, d.code, d.title, d.id AS deliverable_id
     FROM dt_signoffs s JOIN dt_deliverables d ON d.id = s.deliverable_id
     ORDER BY s.signed_at DESC LIMIT 8"
)->fetchAll();

$pageTitle = 'Digital Transformation';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Digital Transformation</h1>
        <p class="subtitle">Programme workstreams, their deliverables (POE) and governance sign-off</p>
    </div>
    <a class="btn" href="<?= APP_BASE_URL ?>/digital/my_signoffs.php">My sign-offs<?= $myPending ? ' (' . $myPending . ')' : '' ?></a>
</div>

<div class="stats-grid">
    <div class="stat-tile"><div class="stat-num"><?= $totals['total'] ?></div><div class="stat-label">Deliverables</div></div>
    <div class="stat-tile"><div class="stat-num"><?= $totals['signed_off'] ?></div><div class="stat-label">Signed off</div></div>
    <div class="stat-tile"><div class="stat-num"><?= $totals['awaiting'] ?></div><div class="stat-label">Awaiting sign-off</div></div>
    <div class="stat-tile<?= $totals['rejected'] ? ' is-overdue' : '' ?>"><div class="stat-num"><?= $totals['rejected'] ?></div><div class="stat-label">Rejected</div></div>
    <div class="stat-tile<?= $myPending ? ' is-overdue' : '' ?>"><div class="stat-num"><?= $myPending ?></div><div class="stat-label">Waiting on me</div></div>
</div>

<h2>Workstreams</h2>
<div class="dt-cards">
    <?php foreach ($projects as $p): $pct = dt_percent((int)$p['signed_off'], (int)$p['total']); ?>
        <a class="dt-card" href="<?= APP_BASE_URL ?>/digital/project.php?p=<?= urlencode($p['slug']) ?>">
            <h3><?= e($p['name']) ?></h3>
            <p><?= e($p['description']) ?></p>
            <?php if ((int)$p['total'] > 0): ?>
                <?= progress_bar($pct) ?>
                <div class="dt-card-meta">
                    <span><strong><?= (int)$p['signed_off'] ?></strong> / <?= (int)$p['total'] ?> signed off</span>
                    <span><?= (int)$p['awaiting'] ?> awaiting</span>
                    <?php if ((int)$p['rejected']): ?><span style="color:var(--red);"><?= (int)$p['rejected'] ?> rejected</span><?php endif; ?>
                </div>
            <?php else: ?>
                <div class="dt-card-meta"><span>No deliverables registered yet</span></div>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="card">
    <h2 style="margin-top:0;">Recent sign-off activity</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>When</th><th>Deliverable</th><th>Governance body</th><th>Signed by</th><th>Decision</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $r): ?>
                <tr>
                    <td><?= format_datetime($r['signed_at']) ?></td>
                    <td><a href="<?= APP_BASE_URL ?>/digital/deliverable.php?id=<?= (int)$r['deliverable_id'] ?>"><?= e($r['code']) ?></a> &mdash; <?= e($r['title']) ?></td>
                    <td><?= e($r['body_name']) ?></td>
                    <td><?= e($r['signer_name']) ?></td>
                    <td><?= $r['decision'] === 'Endorsed' ? '<span class="badge badge-closed">Endorsed</span>' : '<span class="badge badge-overdue">Rejected</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recent): ?>
                <tr><td colspan="5">No sign-offs recorded yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
