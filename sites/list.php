<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$status = $_GET['status'] ?? '';
$projectId = $_GET['project_id'] ?? '';
$search = trim($_GET['q'] ?? '');
$dateFrom = parse_import_date($_GET['date_from'] ?? '');
$dateTo = parse_import_date($_GET['date_to'] ?? '');

$where = [];
$params = [];

if ($status !== '' && in_array($status, ['Submitted', 'Verified', 'Approved', 'Rejected'], true)) {
    $where[] = 's.status = ?';
    $params[] = $status;
}
if ($projectId !== '') {
    $where[] = 's.project_id = ?';
    $params[] = (int)$projectId;
}
if ($search !== '') {
    $where[] = '(s.site_code LIKE ? OR s.site_name LIKE ? OR s.location LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($dateFrom !== null) {
    $where[] = 's.completed_date >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== null) {
    $where[] = 's.completed_date <= ?';
    $params[] = $dateTo;
}

$sql = "SELECT s.id, s.site_code, s.site_name, s.location, s.households_connected, s.completed_date, s.status,
               p.project_name, sub.full_name AS submitted_by_name,
               (SELECT COUNT(*) FROM site_poe_documents d WHERE d.site_id = s.id) AS poe_count
        FROM sites s
        JOIN projects p ON p.id = s.project_id
        JOIN users sub ON sub.id = s.submitted_by";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY (s.status = "Submitted") DESC, (s.status = "Verified") DESC, s.submitted_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sites = $stmt->fetchAll();

$projects = $pdo->query('SELECT id, project_name FROM projects ORDER BY project_name')->fetchAll();

$role = current_user()['role'];
$canSubmit = in_array($role, ['admin', 'field_worker'], true);
$canManageData = in_array($role, ['admin', 'manager'], true);
$queryString = http_build_query(array_filter(['status' => $status, 'project_id' => $projectId, 'q' => $search, 'date_from' => $dateFrom, 'date_to' => $dateTo]));

$pageTitle = 'Site Completions';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Site Completions &amp; Payment Approval</h1>
        <p class="subtitle">Installer submits with proof of evidence (POE) &rarr; reviewer verifies &rarr; approver signs off as ready for payment</p>
    </div>
    <?php if ($canSubmit): ?>
        <a class="btn" href="<?= APP_BASE_URL ?>/sites/form.php">+ Submit completed site</a>
    <?php endif; ?>
</div>

<div class="card">
    <form method="get" class="form-grid">
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status" onchange="this.form.submit()">
                <option value="">All statuses</option>
                <?php foreach (['Submitted', 'Verified', 'Approved', 'Rejected'] as $s): ?>
                    <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="project_id">Project</label>
            <select id="project_id" name="project_id" onchange="this.form.submit()">
                <option value="">All projects</option>
                <?php foreach ($projects as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= (string)$projectId === (string)$p['id'] ? 'selected' : '' ?>><?= e($p['project_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field full">
            <label for="q">Search reference / site name / location</label>
            <input type="text" id="q" name="q" value="<?= e($search) ?>" placeholder="e.g. USAASA-SITE-2026-0001">
        </div>
        <div class="field">
            <label for="date_from">Completed from</label>
            <input type="date" id="date_from" name="date_from" value="<?= e($dateFrom ?? '') ?>" onchange="this.form.submit()">
        </div>
        <div class="field">
            <label for="date_to">Completed to</label>
            <input type="date" id="date_to" name="date_to" value="<?= e($dateTo ?? '') ?>" onchange="this.form.submit()">
        </div>
        <div class="field">
            <button type="submit" class="btn btn-secondary">Filter</button>
            <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/sites/list.php">Reset</a>
        </div>
    </form>
</div>

<?php if ($canManageData): ?>
<div class="card">
    <h2>Import &amp; export</h2>
    <div class="actions-row">
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/sites/export.php?format=csv&<?= $queryString ?>">Export CSV</a>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/sites/export.php?format=excel&<?= $queryString ?>">Export Excel</a>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/sites/print.php?<?= $queryString ?>" target="_blank">Export / print PDF</a>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/sites/import.php">Import site completions</a>
    </div>
    <p class="hint" style="margin-top:8px;">Export respects the current filter above, including the completed-date range. Import has its own date range and duplicate-handling options.</p>

    <h3 style="margin-top:18px;">Batch export selected rows</h3>
    <div class="actions-row">
        <button type="button" class="btn btn-secondary" id="batchExportCsv">Export selected &mdash; CSV</button>
        <button type="button" class="btn btn-secondary" id="batchExportExcel">Export selected &mdash; Excel</button>
        <button type="button" class="btn btn-secondary" id="batchExportPdf">Export selected &mdash; PDF</button>
    </div>
    <p class="hint" style="margin-top:8px;">Tick the checkbox next to the sites you want below, then pick a format here. Ignores the filter above.</p>
</div>
<?php endif; ?>

<div class="card">
    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <?php if ($canManageData): ?><th><input type="checkbox" id="selectAllSites" title="Select all"></th><?php endif; ?>
            <th>Reference</th><th>Site</th><th>Project</th><th>Location</th><th>Households</th>
            <th>Completed</th><th>POE</th><th>Submitted by</th><th>Status</th><th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($sites as $s): ?>
            <tr>
                <?php if ($canManageData): ?><td><input type="checkbox" class="site-checkbox" value="<?= (int)$s['id'] ?>"></td><?php endif; ?>
                <td><?= e($s['site_code']) ?></td>
                <td><?= e($s['site_name']) ?></td>
                <td><?= e($s['project_name']) ?></td>
                <td><?= e($s['location']) ?></td>
                <td><?= number_format((int)$s['households_connected']) ?></td>
                <td><?= format_date($s['completed_date']) ?></td>
                <td><?= (int)$s['poe_count'] ?> file<?= $s['poe_count'] == 1 ? '' : 's' ?></td>
                <td><?= e($s['submitted_by_name']) ?></td>
                <td><?= site_status_badge($s['status']) ?></td>
                <td>
                    <a href="<?= APP_BASE_URL ?>/sites/view.php?id=<?= (int)$s['id'] ?>">View</a> &middot;
                    <a href="<?= APP_BASE_URL ?>/sites/preview.php?id=<?= (int)$s['id'] ?>" target="_blank">Preview</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$sites): ?>
            <tr><td colspan="<?= $canManageData ? 11 : 10 ?>">No site completions match these filters.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php if ($canManageData): ?>
<script>
(function () {
    var selectAll = document.getElementById('selectAllSites');
    var checkboxes = function () { return Array.prototype.slice.call(document.querySelectorAll('.site-checkbox')); };

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            checkboxes().forEach(function (cb) { cb.checked = selectAll.checked; });
        });
    }

    function selectedIds() {
        return checkboxes().filter(function (cb) { return cb.checked; }).map(function (cb) { return cb.value; });
    }

    function idsQueryString(ids) {
        return ids.map(function (id) { return 'ids%5B%5D=' + encodeURIComponent(id); }).join('&');
    }

    function batchExport(format) {
        var ids = selectedIds();
        if (!ids.length) {
            alert('Please tick at least one site below before exporting.');
            return;
        }
        window.location.href = '<?= APP_BASE_URL ?>/sites/export.php?format=' + format + '&' + idsQueryString(ids);
    }

    document.getElementById('batchExportCsv').addEventListener('click', function () { batchExport('csv'); });
    document.getElementById('batchExportExcel').addEventListener('click', function () { batchExport('excel'); });
    document.getElementById('batchExportPdf').addEventListener('click', function () {
        var ids = selectedIds();
        if (!ids.length) {
            alert('Please tick at least one site below before exporting.');
            return;
        }
        window.open('<?= APP_BASE_URL ?>/sites/print.php?' + idsQueryString(ids), '_blank');
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
