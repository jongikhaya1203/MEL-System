<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

sync_overdue_statuses($pdo);

$status = $_GET['status'] ?? '';
$projectId = $_GET['project_id'] ?? '';
$installerId = $_GET['installer_id'] ?? '';
$search = trim($_GET['q'] ?? '');
$dateFrom = parse_import_date($_GET['date_from'] ?? '');
$dateTo = parse_import_date($_GET['date_to'] ?? '');

$where = [];
$params = [];

if ($status !== '' && in_array($status, ['Planned', 'In Progress', 'Completed', 'Overdue'], true)) {
    $where[] = 'a.status = ?';
    $params[] = $status;
}
if ($projectId !== '') {
    $where[] = 'a.project_id = ?';
    $params[] = (int)$projectId;
}
if ($installerId !== '') {
    $where[] = 'a.installer_id = ?';
    $params[] = (int)$installerId;
}
if ($search !== '') {
    $where[] = '(a.reference_code LIKE ? OR a.region LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like;
}
if ($dateFrom !== null) {
    $where[] = 'a.current_deadline >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== null) {
    $where[] = 'a.current_deadline <= ?';
    $params[] = $dateTo;
}

$sql = "SELECT a.id, a.reference_code, a.region, a.status, a.target_installations,
               a.original_deadline, a.current_deadline,
               p.project_name, p.province, inst.full_name AS installer_name,
               (SELECT ap.installations_completed FROM allocation_progress_updates ap
                WHERE ap.allocation_id = a.id ORDER BY ap.period_date DESC LIMIT 1) AS latest_completed,
               (SELECT COUNT(*) FROM allocation_deadline_revisions dr WHERE dr.allocation_id = a.id) AS revision_count
        FROM installer_allocations a
        JOIN projects p ON p.id = a.project_id
        JOIN users inst ON inst.id = a.installer_id";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY (a.status = "Overdue") DESC, a.current_deadline ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allocations = $stmt->fetchAll();

$projects = $pdo->query("SELECT id, project_name FROM projects WHERE province IS NOT NULL ORDER BY project_name")->fetchAll();
$installers = $pdo->query("SELECT id, full_name FROM users WHERE role = 'field_worker' AND is_active = 1 ORDER BY full_name")->fetchAll();

$canCreate = in_array(current_user()['role'], ['admin', 'manager', 'planner'], true);
$queryString = http_build_query(array_filter(['status' => $status, 'project_id' => $projectId, 'installer_id' => $installerId, 'q' => $search, 'date_from' => $dateFrom, 'date_to' => $dateTo]));

$pageTitle = 'Planning & Dispatch';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Planning &amp; Dispatch</h1>
        <p class="subtitle">Allocate installers to a province and region, with a target installation count and deadline &mdash; see the <a href="<?= APP_BASE_URL ?>/planning/dashboard.php">dashboard</a> for provincial rollups</p>
    </div>
    <?php if ($canCreate): ?>
        <a class="btn" href="<?= APP_BASE_URL ?>/planning/form.php">+ New allocation</a>
    <?php endif; ?>
</div>

<div class="card">
    <form method="get" class="form-grid">
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status" onchange="this.form.submit()">
                <option value="">All statuses</option>
                <?php foreach (['Planned', 'In Progress', 'Completed', 'Overdue'] as $s): ?>
                    <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="project_id">Province (project)</label>
            <select id="project_id" name="project_id" onchange="this.form.submit()">
                <option value="">All provinces</option>
                <?php foreach ($projects as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= (string)$projectId === (string)$p['id'] ? 'selected' : '' ?>><?= e($p['project_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="installer_id">Installer</label>
            <select id="installer_id" name="installer_id" onchange="this.form.submit()">
                <option value="">All installers</option>
                <?php foreach ($installers as $i): ?>
                    <option value="<?= (int)$i['id'] ?>" <?= (string)$installerId === (string)$i['id'] ? 'selected' : '' ?>><?= e($i['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field full">
            <label for="q">Search reference / region</label>
            <input type="text" id="q" name="q" value="<?= e($search) ?>" placeholder="e.g. USAASA-ALLOC-2026-0001 or OR Tambo">
        </div>
        <div class="field">
            <label for="date_from">Deadline from</label>
            <input type="date" id="date_from" name="date_from" value="<?= e($dateFrom ?? '') ?>" onchange="this.form.submit()">
        </div>
        <div class="field">
            <label for="date_to">Deadline to</label>
            <input type="date" id="date_to" name="date_to" value="<?= e($dateTo ?? '') ?>" onchange="this.form.submit()">
        </div>
        <div class="field">
            <button type="submit" class="btn btn-secondary">Filter</button>
            <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/planning/list.php">Reset</a>
        </div>
    </form>
</div>

<?php if ($canCreate): ?>
<div class="card">
    <h2>Import &amp; export</h2>
    <div class="actions-row">
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/planning/export.php?format=csv&<?= $queryString ?>">Export CSV</a>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/planning/export.php?format=excel&<?= $queryString ?>">Export Excel</a>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/planning/import.php">Import allocations</a>
    </div>
    <p class="hint" style="margin-top:8px;">Export respects the current filter above, including the deadline range. Import has its own date range and duplicate-handling options.</p>
</div>
<?php endif; ?>

<div class="card">
    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>Reference</th><th>Province</th><th>Region</th><th>Installer</th>
            <th>Target</th><th>Completed</th><th>Progress</th>
            <th>Deadline</th><th>Status</th><th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($allocations as $a): ?>
            <?php $progress = compute_allocation_progress((int)$a['target_installations'], $a['latest_completed'] !== null ? (float)$a['latest_completed'] : null); ?>
            <tr>
                <td><?= e($a['reference_code']) ?></td>
                <td><?= e($a['province'] ?? $a['project_name']) ?></td>
                <td><?= e($a['region']) ?></td>
                <td><?= e($a['installer_name']) ?></td>
                <td><?= number_format((int)$a['target_installations']) ?></td>
                <td><?= $a['latest_completed'] !== null ? number_format((int)$a['latest_completed']) : '—' ?></td>
                <td><?= progress_bar($progress) ?></td>
                <td>
                    <?= format_date($a['current_deadline']) ?>
                    <?php if ($a['revision_count'] > 0): ?>
                        <span class="hint">(revised <?= (int)$a['revision_count'] ?>x)</span>
                    <?php endif; ?>
                </td>
                <td><?= status_badge($a['status']) ?></td>
                <td><a href="<?= APP_BASE_URL ?>/planning/view.php?id=<?= (int)$a['id'] ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$allocations): ?>
            <tr><td colspan="10">No allocations match these filters.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
