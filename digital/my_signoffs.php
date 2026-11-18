<?php
/** Digital Transformation > My Sign-offs — documents routed to a governance body the current user signs for. */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();
$user = current_user();

$stmt = $pdo->prepare('SELECT b.*, m.designation FROM dt_governance_members m JOIN dt_governance_bodies b ON b.id = m.body_id WHERE m.user_id = ? ORDER BY b.sort_order');
$stmt->execute([$user['id']]);
$myBodies = $stmt->fetchAll();

$baseSql = "SELECT d.*, p.name AS project_name, b.name AS pending_name, doc.version_no, doc.file_name, doc.uploaded_at, doc.id AS doc_id
            FROM dt_deliverables d
            JOIN dt_projects p ON p.id = d.project_id
            JOIN dt_governance_bodies b ON b.id = d.pending_body_id
            JOIN dt_documents doc ON doc.id = d.current_document_id
            WHERE d.status = 'Awaiting Sign-off'";
$stmt = $pdo->prepare($baseSql . ' AND d.pending_body_id IN (SELECT body_id FROM dt_governance_members WHERE user_id = ?) ORDER BY doc.uploaded_at');
$stmt->execute([$user['id']]);
$mine = $stmt->fetchAll();

$all = is_super_admin() ? $pdo->query($baseSql . ' ORDER BY b.sort_order, doc.uploaded_at')->fetchAll() : [];

$stmt = $pdo->prepare('SELECT s.*, d.code, d.title FROM dt_signoffs s JOIN dt_deliverables d ON d.id = s.deliverable_id WHERE s.signed_by = ? ORDER BY s.signed_at DESC LIMIT 15');
$stmt->execute([$user['id']]);
$history = $stmt->fetchAll();

$pageTitle = 'My Sign-offs';
include __DIR__ . '/../includes/header.php';

$queue = function (array $rows, string $empty) {
    ?>
    <div class="table-wrap">
        <table class="dt-checklist">
            <thead><tr><th>Deliverable</th><th>Workstream</th><th>Awaiting</th><th>Document</th><th>Waiting since</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><span class="dt-title"><?= e($r['title']) ?></span><div class="dt-desc"><?= e($r['code']) ?></div></td>
                    <td><?= e($r['project_name']) ?></td>
                    <td><?= e($r['pending_name']) ?></td>
                    <td class="dt-doc-link"><a href="<?= APP_BASE_URL ?>/digital/download.php?id=<?= (int)$r['doc_id'] ?>">v<?= (int)$r['version_no'] ?> &middot; <?= e(mb_strimwidth($r['file_name'], 0, 32, '…')) ?></a></td>
                    <td><?= format_date($r['uploaded_at']) ?> <span class="hint">(<?= max(0, (int)floor((time() - strtotime($r['uploaded_at'])) / 86400)) ?> days)</span></td>
                    <td><a class="btn btn-sm" href="<?= APP_BASE_URL ?>/digital/deliverable.php?id=<?= (int)$r['id'] ?>#signoff">Review &amp; sign</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="6"><?= $empty ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
};
?>
<div class="page-header">
    <div>
        <h1>My Sign-offs</h1>
        <p class="subtitle">
            <?php if ($myBodies): ?>
                You sign for: <?= e(implode(', ', array_map(fn($b) => $b['name'] . ($b['designation'] ? ' (' . $b['designation'] . ')' : ''), $myBodies))) ?>
            <?php else: ?>
                You are not assigned as a signatory for any governance body.
            <?php endif; ?>
        </p>
    </div>
</div>

<div class="card">
    <h2 style="margin-top:0;">Awaiting my signature (<?= count($mine) ?>)</h2>
    <?php $queue($mine, $myBodies ? 'Nothing is waiting for your signature.' : 'A super admin assigns signatories under Governance &amp; Accountability.'); ?>
</div>

<?php if (is_super_admin()): ?>
    <div class="card">
        <h2 style="margin-top:0;">All documents awaiting sign-off (<?= count($all) ?>)</h2>
        <?php $queue($all, 'Nothing is awaiting sign-off.'); ?>
    </div>
<?php endif; ?>

<div class="card">
    <h2 style="margin-top:0;">My recent decisions</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Signed</th><th>Deliverable</th><th>As</th><th>Decision</th></tr></thead>
            <tbody>
            <?php foreach ($history as $h): ?>
                <tr>
                    <td><?= format_datetime($h['signed_at']) ?></td>
                    <td><a href="<?= APP_BASE_URL ?>/digital/deliverable.php?id=<?= (int)$h['deliverable_id'] ?>"><?= e($h['code']) ?></a> &mdash; <?= e($h['title']) ?></td>
                    <td><?= e($h['body_name']) ?></td>
                    <td><?= $h['decision'] === 'Endorsed' ? '<span class="badge badge-closed">Endorsed</span>' : '<span class="badge badge-overdue">Rejected</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$history): ?><tr><td colspan="4">You have not signed anything yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
