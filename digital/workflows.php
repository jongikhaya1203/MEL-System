<?php
/** Digital Transformation > Approval Workflows (super admin) — every deliverable's sign-off route. ?p=<slug> filters. */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_super_admin();

$projects = $pdo->query('SELECT * FROM dt_projects ORDER BY sort_order')->fetchAll();
$slug = (string)($_GET['p'] ?? '');
$project = $slug !== '' ? dt_get_project($pdo, $slug) : null;

$sql = 'SELECT d.*, p.name AS project_name, p.slug FROM dt_deliverables d JOIN dt_projects p ON p.id = d.project_id';
$params = [];
if ($project) {
    $sql .= ' WHERE d.project_id = ?';
    $params[] = $project['id'];
}
$stmt = $pdo->prepare($sql . ' ORDER BY p.sort_order, d.section, d.sort_order, d.code');
$stmt->execute($params);
$rows = $stmt->fetchAll();
$routes = dt_routes($pdo, $rows);
$bodies = dt_bodies($pdo);
$labels = dt_section_labels();
$unrouted = count(array_filter($rows, fn($r) => empty($routes[(int)$r['id']])));
$unstaffed = (int)$pdo->query('SELECT COUNT(*) FROM dt_governance_bodies b WHERE NOT EXISTS (SELECT 1 FROM dt_governance_members m WHERE m.body_id = b.id)')->fetchColumn();

$pageTitle = 'Approval Workflows';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Approval Workflows</h1>
        <p class="subtitle">Route each deliverable's POE for sign-off to the responsible governance bodies, in order</p>
    </div>
    <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/digital/governance.php">Governance bodies &amp; signatories</a>
</div>

<?php if ($unrouted): ?>
    <div class="alert alert-warning"><?= $unrouted ?> deliverable<?= $unrouted === 1 ? ' has' : 's have' ?> no sign-off route and cannot be signed off until one is set.</div>
<?php endif; ?>
<?php if ($unstaffed): ?>
    <div class="alert alert-info"><?= $unstaffed ?> governance bod<?= $unstaffed === 1 ? 'y has' : 'ies have' ?> no signatory assigned &mdash; documents routed to <?= $unstaffed === 1 ? 'it' : 'them' ?> will wait until one is assigned under <a href="<?= APP_BASE_URL ?>/digital/governance.php">Governance &amp; Accountability</a>.</div>
<?php endif; ?>

<div class="card">
    <nav class="dt-tabs" aria-label="Workstreams">
        <a href="<?= APP_BASE_URL ?>/digital/workflows.php" class="<?= !$project ? 'is-active' : '' ?>">All workstreams</a>
        <?php foreach ($projects as $p): ?>
            <a href="?p=<?= urlencode($p['slug']) ?>" class="<?= $project && $project['id'] === $p['id'] ? 'is-active' : '' ?>"><?= e($p['name']) ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="table-wrap">
        <table class="dt-checklist">
            <thead><tr><th>Code</th><th>Deliverable</th><th>Owner</th><th>Sign-off route (in order)</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="dt-num" style="width:auto;"><?= e($r['code']) ?></td>
                    <td><a class="dt-title" href="<?= APP_BASE_URL ?>/digital/deliverable.php?id=<?= (int)$r['id'] ?>"><?= e($r['title']) ?></a>
                        <div class="dt-desc"><?= e($r['project_name']) ?><?= $r['slug'] === 'erp' ? ' &rsaquo; ' . e($labels[$r['section']] ?? '') : '' ?></div></td>
                    <td><?= $r['owner_body_id'] && isset($bodies[$r['owner_body_id']]) ? e($bodies[$r['owner_body_id']]['short_name']) : '<span class="hint">Not set</span>' ?></td>
                    <td><?= dt_route_chips($routes[(int)$r['id']] ?? []) ?></td>
                    <td><?= dt_status_badge($r['status']) ?></td>
                    <td><a class="btn btn-secondary btn-sm" href="<?= APP_BASE_URL ?>/digital/workflow_edit.php?id=<?= (int)$r['id'] ?>">Edit route</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="6">No deliverables registered yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
