<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

sync_overdue_statuses($pdo);

$status = $_GET['status'] ?? '';
$projectId = $_GET['project_id'] ?? '';
$search = trim($_GET['q'] ?? '');

$where = [];
$params = [];

if ($status !== '' && in_array($status, ['Open', 'In Progress', 'Overdue', 'Closed'], true)) {
    $where[] = 'f.status = ?';
    $params[] = $status;
}
if ($projectId !== '') {
    $where[] = 'f.project_id = ?';
    $params[] = (int)$projectId;
}
if ($search !== '') {
    $where[] = '(f.reference_code LIKE ? OR f.evidence_based_finding LIKE ? OR f.lesson_drawn LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}

$sql = "SELECT f.id, f.reference_code, f.status, f.original_deadline, f.current_deadline,
               p.project_name, u.full_name AS owner_name,
               (SELECT COUNT(*) FROM deadline_revisions dr WHERE dr.finding_id = f.id) AS revision_count
        FROM findings_register f
        JOIN projects p ON p.id = f.project_id
        JOIN users u ON u.id = f.owner_user_id";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY (f.status = "Overdue") DESC, f.current_deadline ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$findings = $stmt->fetchAll();

$projects = $pdo->query('SELECT id, project_name FROM projects ORDER BY project_name')->fetchAll();

$canCreate = in_array(current_user()['role'], ['admin', 'manager'], true);
$canManageData = $canCreate;
$queryString = http_build_query(array_filter(['status' => $status, 'project_id' => $projectId, 'q' => $search]));

$pageTitle = 'Evaluation Findings & Lessons Register';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>C3.5 Evaluation Findings &amp; Lessons Register</h1>
        <p class="subtitle">Evidence-based findings, lessons drawn, and agreed management actions</p>
    </div>
    <?php if ($canCreate): ?>
        <a class="btn" href="<?= APP_BASE_URL ?>/findings/form.php">+ New finding / lesson</a>
    <?php endif; ?>
</div>

<div class="card">
    <form method="get" class="form-grid">
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status" onchange="this.form.submit()">
                <option value="">All statuses</option>
                <?php foreach (['Open', 'In Progress', 'Overdue', 'Closed'] as $s): ?>
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
            <label for="q">Search reference / finding / lesson</label>
            <input type="text" id="q" name="q" value="<?= e($search) ?>" placeholder="e.g. USAASA-EVAL-2026-0001">
        </div>
        <div class="field">
            <button type="submit" class="btn btn-secondary">Filter</button>
            <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/findings/list.php">Reset</a>
        </div>
    </form>
</div>

<?php if ($canManageData): ?>
<div class="card">
    <h2>Export</h2>
    <div class="actions-row">
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/findings/export.php?format=csv&<?= $queryString ?>">Export CSV</a>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/findings/export.php?format=excel&<?= $queryString ?>">Export Excel</a>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/findings/print.php?<?= $queryString ?>" target="_blank">Export / print PDF</a>
    </div>
    <p class="hint" style="margin-top:8px;">These export the current filter above (every row matching it).</p>

    <h3 style="margin-top:18px;">Batch export selected rows</h3>
    <div class="actions-row">
        <button type="button" class="btn btn-secondary" id="batchExportCsv">Export selected &mdash; CSV</button>
        <button type="button" class="btn btn-secondary" id="batchExportExcel">Export selected &mdash; Excel</button>
        <button type="button" class="btn btn-secondary" id="batchExportPdf">Export selected &mdash; PDF</button>
    </div>
    <p class="hint" style="margin-top:8px;">Tick the checkbox next to the findings you want below, then pick a format here. Ignores the filter above.</p>
</div>
<?php endif; ?>

<div class="card">
    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <?php if ($canManageData): ?><th><input type="checkbox" id="selectAllFindings" title="Select all"></th><?php endif; ?>
            <th>Reference</th><th>Project</th><th>Owner</th>
            <th>Original deadline</th><th>Current deadline</th>
            <th>Status</th><th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($findings as $f): ?>
            <tr>
                <?php if ($canManageData): ?><td><input type="checkbox" class="finding-checkbox" value="<?= (int)$f['id'] ?>"></td><?php endif; ?>
                <td><?= e($f['reference_code']) ?></td>
                <td><?= e($f['project_name']) ?></td>
                <td><?= e($f['owner_name']) ?></td>
                <td><?= format_date($f['original_deadline']) ?></td>
                <td>
                    <?= format_date($f['current_deadline']) ?>
                    <?php if ($f['revision_count'] > 0): ?>
                        <span class="hint">(revised <?= (int)$f['revision_count'] ?>x)</span>
                    <?php endif; ?>
                </td>
                <td><?= status_badge($f['status']) ?></td>
                <td><a href="<?= APP_BASE_URL ?>/findings/view.php?id=<?= (int)$f['id'] ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$findings): ?>
            <tr><td colspan="<?= $canManageData ? 8 : 7 ?>">No findings match these filters.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php if ($canManageData): ?>
<script>
(function () {
    var selectAll = document.getElementById('selectAllFindings');
    var checkboxes = function () { return Array.prototype.slice.call(document.querySelectorAll('.finding-checkbox')); };

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
            alert('Please tick at least one finding below before exporting.');
            return;
        }
        window.location.href = '<?= APP_BASE_URL ?>/findings/export.php?format=' + format + '&' + idsQueryString(ids);
    }

    document.getElementById('batchExportCsv').addEventListener('click', function () { batchExport('csv'); });
    document.getElementById('batchExportExcel').addEventListener('click', function () { batchExport('excel'); });
    document.getElementById('batchExportPdf').addEventListener('click', function () {
        var ids = selectedIds();
        if (!ids.length) {
            alert('Please tick at least one finding below before exporting.');
            return;
        }
        window.open('<?= APP_BASE_URL ?>/findings/print.php?' + idsQueryString(ids), '_blank');
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
