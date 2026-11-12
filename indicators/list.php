<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

sync_overdue_statuses($pdo);

$status = $_GET['status'] ?? '';
$projectId = $_GET['project_id'] ?? '';
$level = $_GET['level'] ?? '';
$search = trim($_GET['q'] ?? '');

$where = [];
$params = [];

if ($status !== '' && in_array($status, ['Open', 'In Progress', 'Overdue', 'Achieved'], true)) {
    $where[] = 'i.status = ?';
    $params[] = $status;
}
if ($projectId !== '') {
    $where[] = 'i.project_id = ?';
    $params[] = (int)$projectId;
}
if ($level !== '' && in_array($level, ['Impact', 'Outcome', 'Output'], true)) {
    $where[] = 'i.result_level = ?';
    $params[] = $level;
}
if ($search !== '') {
    $where[] = '(i.reference_code LIKE ? OR i.indicator_name LIKE ? OR i.result_statement LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}

$sql = "SELECT i.id, i.reference_code, i.result_level, i.indicator_name, i.status,
               i.baseline_value, i.target_value, i.original_target_date, i.current_target_date,
               p.project_name, u.full_name AS owner_name,
               (SELECT COUNT(*) FROM indicator_target_revisions tr WHERE tr.indicator_id = i.id) AS revision_count,
               (SELECT actual_value FROM indicator_actuals a WHERE a.indicator_id = i.id ORDER BY a.period_date DESC LIMIT 1) AS latest_actual
        FROM indicators i
        JOIN projects p ON p.id = i.project_id
        JOIN users u ON u.id = i.responsible_user_id";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY (i.status = "Overdue") DESC, i.current_target_date ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$indicators = $stmt->fetchAll();

$projects = $pdo->query('SELECT id, project_name FROM projects ORDER BY project_name')->fetchAll();

$canCreate = in_array(current_user()['role'], ['admin', 'manager'], true);
$canManageData = $canCreate;
$queryString = http_build_query(array_filter(['status' => $status, 'project_id' => $projectId, 'level' => $level, 'q' => $search]));

$pageTitle = 'Indicators';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Indicators</h1>
        <p class="subtitle">Flat register of all indicators &mdash; see the <a href="<?= APP_BASE_URL ?>/indicators/framework.php">Results Framework</a> for the hierarchical view</p>
    </div>
    <?php if ($canCreate): ?>
        <a class="btn" href="<?= APP_BASE_URL ?>/indicators/form.php">+ New indicator</a>
    <?php endif; ?>
</div>

<div class="card">
    <form method="get" class="form-grid">
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status" onchange="this.form.submit()">
                <option value="">All statuses</option>
                <?php foreach (['Open', 'In Progress', 'Overdue', 'Achieved'] as $s): ?>
                    <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="level">Result level</label>
            <select id="level" name="level" onchange="this.form.submit()">
                <option value="">All levels</option>
                <?php foreach (['Impact', 'Outcome', 'Output'] as $l): ?>
                    <option value="<?= $l ?>" <?= $level === $l ? 'selected' : '' ?>><?= $l ?></option>
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
            <label for="q">Search reference / indicator / result statement</label>
            <input type="text" id="q" name="q" value="<?= e($search) ?>" placeholder="e.g. USAASA-IND-2026-0001">
        </div>
        <div class="field">
            <button type="submit" class="btn btn-secondary">Filter</button>
            <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indicators/list.php">Reset</a>
        </div>
    </form>
</div>

<?php if ($canManageData): ?>
<div class="card">
    <h2>Export</h2>
    <div class="actions-row">
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indicators/export.php?format=csv&<?= $queryString ?>">Export CSV</a>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indicators/export.php?format=excel&<?= $queryString ?>">Export Excel</a>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indicators/print.php?<?= $queryString ?>" target="_blank">Export / print PDF</a>
    </div>
    <p class="hint" style="margin-top:8px;">These export the current filter above (every row matching it).</p>

    <h3 style="margin-top:18px;">Batch export selected rows</h3>
    <div class="actions-row">
        <button type="button" class="btn btn-secondary" id="batchExportCsv">Export selected &mdash; CSV</button>
        <button type="button" class="btn btn-secondary" id="batchExportExcel">Export selected &mdash; Excel</button>
        <button type="button" class="btn btn-secondary" id="batchExportPdf">Export selected &mdash; PDF</button>
    </div>
    <p class="hint" style="margin-top:8px;">Tick the checkbox next to the indicators you want below, then pick a format here. Ignores the filter above.</p>
</div>
<?php endif; ?>

<div class="card">
    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <?php if ($canManageData): ?><th><input type="checkbox" id="selectAllIndicators" title="Select all"></th><?php endif; ?>
            <th>Reference</th><th>Level</th><th>Indicator</th><th>Project</th><th>Owner</th>
            <th>Baseline &rarr; Target</th><th>Progress</th>
            <th>Original target date</th><th>Current target date</th>
            <th>Status</th><th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($indicators as $i): ?>
            <?php $progress = compute_indicator_progress((float)$i['baseline_value'], (float)$i['target_value'], $i['latest_actual'] !== null ? (float)$i['latest_actual'] : null); ?>
            <tr>
                <?php if ($canManageData): ?><td><input type="checkbox" class="indicator-checkbox" value="<?= (int)$i['id'] ?>"></td><?php endif; ?>
                <td><?= e($i['reference_code']) ?></td>
                <td><?= result_level_badge($i['result_level']) ?></td>
                <td><?= e($i['indicator_name']) ?></td>
                <td><?= e($i['project_name']) ?></td>
                <td><?= e($i['owner_name']) ?></td>
                <td><?= format_number($i['baseline_value']) ?> &rarr; <?= format_number($i['target_value']) ?></td>
                <td><?= progress_bar($progress) ?></td>
                <td><?= format_date($i['original_target_date']) ?></td>
                <td>
                    <?= format_date($i['current_target_date']) ?>
                    <?php if ($i['revision_count'] > 0): ?>
                        <span class="hint">(revised <?= (int)$i['revision_count'] ?>x)</span>
                    <?php endif; ?>
                </td>
                <td><?= status_badge($i['status']) ?></td>
                <td><a href="<?= APP_BASE_URL ?>/indicators/view.php?id=<?= (int)$i['id'] ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$indicators): ?>
            <tr><td colspan="<?= $canManageData ? 12 : 11 ?>">No indicators match these filters.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php if ($canManageData): ?>
<script>
(function () {
    var selectAll = document.getElementById('selectAllIndicators');
    var checkboxes = function () { return Array.prototype.slice.call(document.querySelectorAll('.indicator-checkbox')); };

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
            alert('Please tick at least one indicator below before exporting.');
            return;
        }
        window.location.href = '<?= APP_BASE_URL ?>/indicators/export.php?format=' + format + '&' + idsQueryString(ids);
    }

    document.getElementById('batchExportCsv').addEventListener('click', function () { batchExport('csv'); });
    document.getElementById('batchExportExcel').addEventListener('click', function () { batchExport('excel'); });
    document.getElementById('batchExportPdf').addEventListener('click', function () {
        var ids = selectedIds();
        if (!ids.length) {
            alert('Please tick at least one indicator below before exporting.');
            return;
        }
        window.open('<?= APP_BASE_URL ?>/indicators/print.php?' + idsQueryString(ids), '_blank');
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
