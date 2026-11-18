<?php
/**
 * Digital Transformation > one workstream. ?p=<slug>[&section=<section>]
 * ERP with no section shows the ERP hub; otherwise the deliverables register
 * (checklist) for the section, with attach / sign-off buttons per document.
 * POST action=add adds a deliverable to the register (admin/manager).
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();
$user = current_user();

$project = dt_get_project($pdo, (string)($_GET['p'] ?? $_POST['p'] ?? ''));
if (!$project) {
    flash('error', 'Workstream not found.');
    redirect('/digital/index.php');
}
$sections = dt_project_sections($project['slug']);
$labels = dt_section_labels();
$section = (string)($_GET['section'] ?? $_POST['section'] ?? '');
$isHub = $project['slug'] === 'erp' && $section === '';
if (!$isHub && !in_array($section, $sections, true)) {
    $section = $sections[0];
}
$selfUrl = '/digital/project.php?p=' . urlencode($project['slug']) . ($isHub ? '' : '&section=' . urlencode($section));
$bodies = dt_bodies($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    csrf_verify();
    if (!dt_can_manage_register($user)) {
        http_response_code(403);
        die('You do not have permission to add deliverables.');
    }
    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $owner = (int)($_POST['owner_body_id'] ?? 0);
    if ($title === '') {
        flash('error', 'Please enter the deliverable title.');
        redirect($selfUrl);
    }
    $prefix = strtoupper($project['slug']) . '-' . (['blueprint' => 'BP-', 'data_migration' => 'DM-'][$section] ?? '');
    $stmt = $pdo->prepare('SELECT code FROM dt_deliverables WHERE code LIKE ?');
    $stmt->execute([$prefix . '%']);
    $max = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $c) {
        $max = max($max, (int)substr($c, strlen($prefix)));
    }
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM dt_deliverables WHERE project_id = ? AND section = ?');
    $stmt->execute([$project['id'], $section]);
    $sort = (int)$stmt->fetchColumn();

    $pdo->prepare('INSERT INTO dt_deliverables (project_id, code, section, title, description, owner_body_id, sort_order, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$project['id'], sprintf('%s%02d', $prefix, $max + 1), $section, mb_substr($title, 0, 255),
                   $description === '' ? null : $description, isset($bodies[$owner]) ? $owner : null, $sort, $user['id']]);
    flash('success', 'Deliverable added. ' . (is_super_admin() ? 'Set its sign-off route under Approval Workflows.' : 'A super admin must set its sign-off route before it can be signed off.'));
    redirect($selfUrl);
}

$myBodies = dt_user_body_ids($pdo, (int)$user['id']);
$canUpload = dt_can_upload($pdo, $user);

// Per-section counts (tabs, hub cards)
$stmt = $pdo->prepare("SELECT section, COUNT(*) AS total, SUM(status = 'Signed Off') AS signed_off, SUM(status = 'Awaiting Sign-off') AS awaiting,
                              SUM(status = 'Rejected') AS rejected, SUM(status = 'Not Started') AS not_started
                       FROM dt_deliverables WHERE project_id = ? GROUP BY section");
$stmt->execute([$project['id']]);
$sectionStats = array_column($stmt->fetchAll(), null, 'section');

$listSql = "SELECT d.*, doc.version_no, doc.file_name, doc.uploaded_at, doc.id AS doc_id
            FROM dt_deliverables d LEFT JOIN dt_documents doc ON doc.id = d.current_document_id
            WHERE d.project_id = ?";
if ($isHub) {
    $stmt = $pdo->prepare($listSql . " AND d.status IN ('Awaiting Sign-off', 'Rejected') ORDER BY d.status = 'Rejected' DESC, doc.uploaded_at");
    $stmt->execute([$project['id']]);
} else {
    $stmt = $pdo->prepare($listSql . ' AND d.section = ? ORDER BY d.sort_order, d.code');
    $stmt->execute([$project['id'], $section]);
}
$rows = $stmt->fetchAll();
$routes = dt_routes($pdo, $rows);

$pageTitle = $isHub ? $project['name'] : $labels[$section] . ' — ' . $project['name'];
include __DIR__ . '/../includes/header.php';

/** One register table (shared by the checklist view and the hub's "in progress" list). */
$renderTable = function (array $rows, bool $showSection) use ($routes, $bodies, $myBodies, $canUpload, $labels) {
    ?>
    <div class="table-wrap">
    <table class="dt-checklist">
        <thead>
        <tr><th>#</th><th>Deliverable</th><th>Owner</th><th>Sign-off route</th><th>Document (POE)</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $i => $d):
            $mine = $d['status'] === 'Awaiting Sign-off' && $d['pending_body_id'] && isset($myBodies[(int)$d['pending_body_id']]);
            $url = APP_BASE_URL . '/digital/deliverable.php?id=' . (int)$d['id']; ?>
            <tr<?= $mine ? ' class="is-mine"' : '' ?>>
                <td class="dt-num"><?= $showSection ? e($d['code']) : $i + 1 ?></td>
                <td>
                    <a class="dt-title" href="<?= $url ?>"><?= e($d['title']) ?></a>
                    <?php if ($showSection): ?><div class="dt-desc"><?= e($labels[$d['section']] ?? $d['section']) ?></div>
                    <?php elseif ($d['description']): ?><div class="dt-desc"><?= e($d['description']) ?></div><?php endif; ?>
                </td>
                <td><?= $d['owner_body_id'] && isset($bodies[$d['owner_body_id']]) ? e($bodies[$d['owner_body_id']]['short_name']) : '<span class="hint">Not set</span>' ?></td>
                <td><?= dt_route_chips($routes[(int)$d['id']] ?? []) ?></td>
                <td class="dt-doc-link">
                    <?php if ($d['doc_id']): ?>
                        <a href="<?= APP_BASE_URL ?>/digital/download.php?id=<?= (int)$d['doc_id'] ?>" title="<?= e($d['file_name']) ?>">v<?= (int)$d['version_no'] ?> &middot; <?= e(mb_strimwidth($d['file_name'], 0, 28, '…')) ?></a>
                        <div class="hint"><?= format_date($d['uploaded_at']) ?></div>
                    <?php else: ?>
                        <span class="hint">Not attached</span>
                    <?php endif; ?>
                </td>
                <td><?= dt_status_badge($d['status']) ?></td>
                <td class="dt-actions">
                    <?php if ($mine): ?>
                        <a class="btn btn-sm" href="<?= $url ?>#signoff">Sign off</a>
                    <?php endif; ?>
                    <?php if ($canUpload): ?>
                        <a class="btn btn-secondary btn-sm" href="<?= $url ?>#attach"><?= $d['doc_id'] ? 'New version' : 'Attach' ?></a>
                    <?php endif; ?>
                    <a class="btn btn-secondary btn-sm" href="<?= $url ?>">View</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="7"><?= $showSection ? 'Nothing is awaiting sign-off or rejected right now.' : 'No deliverables registered yet.' ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
    <?php
};
?>
<div class="page-header">
    <div>
        <h1><?= e($isHub ? $project['name'] : $labels[$section]) ?></h1>
        <p class="subtitle"><?= $isHub ? e($project['description']) : 'Digital Transformation &rsaquo; ' . e($project['name']) ?></p>
    </div>
    <?php if (is_super_admin()): ?>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/digital/workflows.php?p=<?= urlencode($project['slug']) ?>">Approval workflows</a>
    <?php endif; ?>
</div>

<?php if (count($sections) > 1 || $project['slug'] === 'erp'): ?>
    <nav class="dt-tabs" aria-label="<?= e($project['name']) ?> sections">
        <?php if ($project['slug'] === 'erp'): ?>
            <a href="<?= APP_BASE_URL ?>/digital/project.php?p=erp" class="<?= $isHub ? 'is-active' : '' ?>">Overview</a>
        <?php endif; ?>
        <?php foreach ($sections as $s): ?>
            <a href="<?= APP_BASE_URL ?>/digital/project.php?p=<?= urlencode($project['slug']) ?>&amp;section=<?= $s ?>" class="<?= !$isHub && $s === $section ? 'is-active' : '' ?>">
                <?= e($labels[$s]) ?> <span class="dt-tab-count"><?= (int)($sectionStats[$s]['signed_off'] ?? 0) ?>/<?= (int)($sectionStats[$s]['total'] ?? 0) ?></span></a>
        <?php endforeach; ?>
        <?php if ($project['slug'] === 'erp'): ?>
            <a href="<?= APP_BASE_URL ?>/digital/governance.php">Governance &amp; Accountability</a>
        <?php endif; ?>
    </nav>
<?php endif; ?>

<?php if ($isHub): ?>
    <div class="dt-cards">
        <?php foreach ($sections as $s): $st = $sectionStats[$s] ?? []; ?>
            <a class="dt-card" href="<?= APP_BASE_URL ?>/digital/project.php?p=erp&amp;section=<?= $s ?>">
                <h3><?= e($labels[$s]) ?></h3>
                <p><?= $s === 'blueprint'
                        ? 'The blueprint documents that must be approved before configuration starts — each attached as POE and signed off by its owners and approvers.'
                        : 'Specifies how legacy data is moved into the new ERP — sources, ownership, cleansing, mapping, balances, reconciliation, testing and archival.' ?></p>
                <?= progress_bar(dt_percent((int)($st['signed_off'] ?? 0), (int)($st['total'] ?? 0))) ?>
                <div class="dt-card-meta">
                    <span><strong><?= (int)($st['signed_off'] ?? 0) ?></strong> / <?= (int)($st['total'] ?? 0) ?> signed off</span>
                    <span><?= (int)($st['awaiting'] ?? 0) ?> awaiting</span>
                    <span><?= (int)($st['not_started'] ?? 0) ?> not attached</span>
                </div>
            </a>
        <?php endforeach; ?>
        <a class="dt-card" href="<?= APP_BASE_URL ?>/digital/governance.php">
            <h3>Governance &amp; Accountability</h3>
            <p>Who owns and approves each component — the governance structure, its signatories, and the accountability matrix across every deliverable.</p>
            <div class="dt-card-meta"><span><?= count($bodies) ?> governance bodies</span></div>
        </a>
    </div>
    <div class="card">
        <h2 style="margin-top:0;">In progress &mdash; awaiting sign-off or rejected</h2>
        <?php $renderTable($rows, true); ?>
    </div>
<?php else: ?>
    <div class="card">
        <?php if ($section === 'data_migration'): ?>
            <p class="hint" style="margin:0 0 12px;">Particularly important when replacing an existing ERP. Each element of the data migration blueprint is attached as evidence and routed for acceptance and sign-off.</p>
        <?php elseif ($section === 'blueprint'): ?>
            <p class="hint" style="margin:0 0 12px;">Attach each blueprint document and route it for sign-off. <span class="dt-chip is-done">&#10003; endorsed</span> <span class="dt-chip is-pending">&#9679; awaiting signature</span> <span class="dt-chip is-rejected">&#10005; rejected</span> <span class="dt-chip">still to sign</span></p>
        <?php endif; ?>
        <?php $renderTable($rows, false); ?>
    </div>

    <?php if (dt_can_manage_register($user)): ?>
        <details class="card">
            <summary style="cursor:pointer; font-weight:600;">Add a deliverable to this register</summary>
            <form method="post" class="form-grid" style="margin-top:14px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="p" value="<?= e($project['slug']) ?>">
                <input type="hidden" name="section" value="<?= e($section) ?>">
                <div class="field full">
                    <label for="title">Deliverable / document title</label>
                    <input type="text" id="title" name="title" maxlength="255" required>
                </div>
                <div class="field full">
                    <label for="description">Description <span class="hint">(optional)</span></label>
                    <textarea id="description" name="description" rows="2"></textarea>
                </div>
                <div class="field">
                    <label for="owner_body_id">Accountable owner</label>
                    <select id="owner_body_id" name="owner_body_id">
                        <option value="">— Not set —</option>
                        <?php foreach ($bodies as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="field" style="align-self:end;">
                    <button type="submit" class="btn">Add deliverable</button>
                </div>
            </form>
        </details>
    <?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
