<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin']);

$MODULE_LABELS = [
    'sites' => 'Site Completions',
    'indigent_applications' => 'Indigent Applications',
    'installer_allocations' => 'Planning & Dispatch',
];

// --- Import activity -------------------------------------------------------
$module = $_GET['module'] ?? '';
$importFrom = $_GET['import_from'] ?? '';
$importTo = $_GET['import_to'] ?? '';

$where = [];
$params = [];
if ($module !== '' && isset($MODULE_LABELS[$module])) {
    $where[] = 'module = ?';
    $params[] = $module;
}
if ($importFrom !== '' && strtotime($importFrom)) {
    $where[] = 'DATE(imported_at) >= ?';
    $params[] = $importFrom;
}
if ($importTo !== '' && strtotime($importTo)) {
    $where[] = 'DATE(imported_at) <= ?';
    $params[] = $importTo;
}
$sql = "SELECT il.*, u.full_name AS imported_by_name
        FROM import_log il
        JOIN users u ON u.id = il.imported_by";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY il.imported_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$importRuns = $stmt->fetchAll();

$importTotals = [
    'runs' => count($importRuns),
    'created' => array_sum(array_column($importRuns, 'created_count')),
    'updated' => array_sum(array_column($importRuns, 'updated_count')),
    'duplicates' => array_sum(array_column($importRuns, 'duplicate_count')),
    'invalid' => array_sum(array_column($importRuns, 'invalid_count')),
];

// --- Data captured by installers (site completions) ------------------------
$installerId = $_GET['installer_id'] ?? '';
$capturedFrom = $_GET['captured_from'] ?? '';
$capturedTo = $_GET['captured_to'] ?? '';

$siteWhere = ["sub.role = 'field_worker'"];
$siteParams = [];
if ($installerId !== '') {
    $siteWhere[] = 's.submitted_by = ?';
    $siteParams[] = (int)$installerId;
}
if ($capturedFrom !== '' && strtotime($capturedFrom)) {
    $siteWhere[] = 'DATE(s.submitted_at) >= ?';
    $siteParams[] = $capturedFrom;
}
if ($capturedTo !== '' && strtotime($capturedTo)) {
    $siteWhere[] = 'DATE(s.submitted_at) <= ?';
    $siteParams[] = $capturedTo;
}
$siteSql = "SELECT s.id, s.site_code, s.site_name, s.households_connected, s.status, s.submitted_at,
                   p.project_name, sub.full_name AS installer_name
            FROM sites s
            JOIN projects p ON p.id = s.project_id
            JOIN users sub ON sub.id = s.submitted_by
            WHERE " . implode(' AND ', $siteWhere) . "
            ORDER BY s.submitted_at DESC
            LIMIT 200";
$siteStmt = $pdo->prepare($siteSql);
$siteStmt->execute($siteParams);
$installerSites = $siteStmt->fetchAll();

$installerTotals = $pdo->query(
    "SELECT sub.id, sub.full_name, COUNT(*) AS site_count, COALESCE(SUM(s.households_connected), 0) AS households
     FROM sites s JOIN users sub ON sub.id = s.submitted_by
     WHERE sub.role = 'field_worker'
     GROUP BY sub.id, sub.full_name ORDER BY site_count DESC"
)->fetchAll();

$installers = $pdo->query("SELECT id, full_name FROM users WHERE role = 'field_worker' ORDER BY full_name")->fetchAll();

$pageTitle = 'Data Captured';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Data Captured</h1>
        <p class="subtitle">Admin-only: bulk import activity across every module, and data captured directly by installers</p>
    </div>
</div>

<div class="card">
    <h2>Import activity</h2>
    <form method="get" class="form-grid">
        <div class="field">
            <label for="module">Module</label>
            <select id="module" name="module" onchange="this.form.submit()">
                <option value="">All modules</option>
                <?php foreach ($MODULE_LABELS as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $module === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="import_from">Imported from</label>
            <input type="date" id="import_from" name="import_from" value="<?= e($importFrom) ?>" onchange="this.form.submit()">
        </div>
        <div class="field">
            <label for="import_to">Imported to</label>
            <input type="date" id="import_to" name="import_to" value="<?= e($importTo) ?>" onchange="this.form.submit()">
        </div>
        <div class="field">
            <button type="submit" class="btn btn-secondary">Filter</button>
            <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/reports/data_captured.php">Reset</a>
        </div>
    </form>
</div>

<div class="stats-grid">
    <div class="stat-tile">
        <div class="stat-num"><?= number_format($importTotals['runs']) ?></div>
        <div class="stat-label">Import runs</div>
    </div>
    <div class="stat-tile">
        <div class="stat-num"><?= number_format($importTotals['created']) ?></div>
        <div class="stat-label">Records created</div>
    </div>
    <div class="stat-tile">
        <div class="stat-num"><?= number_format($importTotals['updated']) ?></div>
        <div class="stat-label">Records updated</div>
    </div>
    <div class="stat-tile is-overdue">
        <div class="stat-num"><?= number_format($importTotals['duplicates']) ?></div>
        <div class="stat-label">Duplicates rejected</div>
    </div>
    <div class="stat-tile">
        <div class="stat-num"><?= number_format($importTotals['invalid']) ?></div>
        <div class="stat-label">Invalid rows</div>
    </div>
</div>

<div class="card">
    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>Module</th><th>Mode</th><th>File</th><th>Date range filter</th><th>Total rows</th>
            <th>Created</th><th>Updated</th><th>Duplicates</th><th>Invalid</th><th>Imported by</th><th>When</th><th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($importRuns as $r): ?>
            <tr>
                <td><?= e($MODULE_LABELS[$r['module']] ?? $r['module']) ?></td>
                <td><?= $r['mode'] === 'update' ? 'Update existing' : 'Reject duplicates' ?></td>
                <td><?= e($r['file_name']) ?></td>
                <td>
                    <?= $r['period_start'] || $r['period_end']
                        ? format_date($r['period_start']) . ' &ndash; ' . format_date($r['period_end'])
                        : '<span class="hint">No range (all rows)</span>' ?>
                </td>
                <td><?= number_format((int)$r['total_rows']) ?></td>
                <td><?= number_format((int)$r['created_count']) ?></td>
                <td><?= number_format((int)$r['updated_count']) ?></td>
                <td><?= (int)$r['duplicate_count'] > 0 ? '<span class="badge badge-overdue">' . (int)$r['duplicate_count'] . '</span>' : '0' ?></td>
                <td><?= number_format((int)$r['invalid_count']) ?></td>
                <td><?= e($r['imported_by_name']) ?></td>
                <td><?= format_datetime($r['imported_at']) ?></td>
                <td>
                    <?php $rowDetails = json_decode($r['details'] ?? '[]', true) ?: []; ?>
                    <?php if ($rowDetails): ?>
                        <details>
                            <summary class="hint" style="cursor:pointer;">Details</summary>
                            <ul class="history-list">
                                <?php foreach ($rowDetails as $d): ?>
                                    <li><strong><?= e($d['row'] ?? '') ?> (<?= e($d['outcome'] ?? '') ?>):</strong> <?= e($d['reason'] ?? '') ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$importRuns): ?>
            <tr><td colspan="12">No import runs match these filters.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="card">
    <h2>Data captured by installers</h2>
    <p class="hint" style="margin-top:0;">Site completions, by the Installer (field_worker) who submitted them.</p>
    <div class="table-wrap" style="margin-bottom:16px;">
    <table>
        <thead><tr><th>Installer</th><th>Sites submitted</th><th>Households connected</th></tr></thead>
        <tbody>
        <?php foreach ($installerTotals as $t): ?>
            <tr>
                <td><?= e($t['full_name']) ?></td>
                <td><?= number_format((int)$t['site_count']) ?></td>
                <td><?= number_format((int)$t['households']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$installerTotals): ?>
            <tr><td colspan="3">No installer-submitted sites yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>

    <form method="get" class="form-grid">
        <div class="field">
            <label for="installer_id">Installer</label>
            <select id="installer_id" name="installer_id" onchange="this.form.submit()">
                <option value="">All installers</option>
                <?php foreach ($installers as $i): ?>
                    <option value="<?= (int)$i['id'] ?>" <?= (string)$installerId === (string)$i['id'] ? 'selected' : '' ?>><?= e($i['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="captured_from">Submitted from</label>
            <input type="date" id="captured_from" name="captured_from" value="<?= e($capturedFrom) ?>" onchange="this.form.submit()">
        </div>
        <div class="field">
            <label for="captured_to">Submitted to</label>
            <input type="date" id="captured_to" name="captured_to" value="<?= e($capturedTo) ?>" onchange="this.form.submit()">
        </div>
        <div class="field">
            <button type="submit" class="btn btn-secondary">Filter</button>
            <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/reports/data_captured.php">Reset</a>
        </div>
    </form>

    <div class="table-wrap" style="margin-top:14px;">
    <table>
        <thead><tr><th>Site code</th><th>Site name</th><th>Project</th><th>Households</th><th>Status</th><th>Installer</th><th>Submitted</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($installerSites as $s): ?>
            <tr>
                <td><?= e($s['site_code']) ?></td>
                <td><?= e($s['site_name']) ?></td>
                <td><?= e($s['project_name']) ?></td>
                <td><?= number_format((int)$s['households_connected']) ?></td>
                <td><?= site_status_badge($s['status']) ?></td>
                <td><?= e($s['installer_name']) ?></td>
                <td><?= format_datetime($s['submitted_at']) ?></td>
                <td><a href="<?= APP_BASE_URL ?>/sites/view.php?id=<?= (int)$s['id'] ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$installerSites): ?>
            <tr><td colspan="8">No site completions match these filters.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
    <p class="hint" style="margin-top:8px;">Showing up to 200 most recent matching rows.</p>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
