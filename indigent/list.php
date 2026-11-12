<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$province = $_GET['province'] ?? '';
$search = trim($_GET['q'] ?? '');
$dateFrom = parse_import_date($_GET['date_from'] ?? '');
$dateTo = parse_import_date($_GET['date_to'] ?? '');

$where = [];
$params = [];
if ($province !== '' && in_array($province, sa_provinces(), true)) {
    $where[] = 'a.province = ?';
    $params[] = $province;
}
if ($search !== '') {
    $where[] = '(a.reference_code LIKE ? OR a.first_name LIKE ? OR a.surname LIKE ? OR a.id_number LIKE ? OR a.village_name LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
if ($dateFrom !== null) {
    $where[] = 'DATE(a.captured_at) >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== null) {
    $where[] = 'DATE(a.captured_at) <= ?';
    $params[] = $dateTo;
}

$sql = "SELECT a.id, a.reference_code, a.first_name, a.surname, a.id_number, a.province, a.district_municipality,
               a.local_municipality, a.village_name, a.monthly_income, a.has_functional_tv, a.captured_at,
               u.full_name AS captured_by_name
        FROM indigent_applications a
        JOIN users u ON u.id = a.captured_by";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY a.captured_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$apps = $stmt->fetchAll();

$role = current_user()['role'];
$canCreate = in_array($role, ['admin', 'manager', 'field_worker', 'indigent_capturer'], true);
$canManageData = in_array($role, ['admin', 'manager'], true);
$provinces = sa_provinces();
$queryString = http_build_query(array_filter(['province' => $province, 'q' => $search, 'date_from' => $dateFrom, 'date_to' => $dateTo]));

$pageTitle = 'Indigent Applications';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Indigent Applications</h1>
        <p class="subtitle">SABC TV licence concession / set-top-box subsidy — household data capture, rural areas</p>
    </div>
    <?php if ($canCreate): ?>
        <a class="btn" href="<?= APP_BASE_URL ?>/indigent/form.php">+ Capture application</a>
    <?php endif; ?>
</div>

<div class="card">
    <form method="get" class="form-grid">
        <div class="field">
            <label for="province">Province</label>
            <select id="province" name="province" onchange="this.form.submit()">
                <option value="">All provinces</option>
                <?php foreach ($provinces as $p): ?>
                    <option value="<?= e($p) ?>" <?= $province === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field full">
            <label for="q">Search reference / name / ID number / village</label>
            <input type="text" id="q" name="q" value="<?= e($search) ?>">
        </div>
        <div class="field">
            <label for="date_from">Captured from</label>
            <input type="date" id="date_from" name="date_from" value="<?= e($dateFrom ?? '') ?>" onchange="this.form.submit()">
        </div>
        <div class="field">
            <label for="date_to">Captured to</label>
            <input type="date" id="date_to" name="date_to" value="<?= e($dateTo ?? '') ?>" onchange="this.form.submit()">
        </div>
        <div class="field">
            <button type="submit" class="btn btn-secondary">Filter</button>
            <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indigent/list.php">Reset</a>
        </div>
    </form>
</div>

<?php if ($canManageData): ?>
<div class="card">
    <h2>Import &amp; export</h2>
    <div class="actions-row">
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indigent/export.php?format=csv&<?= $queryString ?>">Export CSV</a>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indigent/export.php?format=excel&<?= $queryString ?>">Export Excel</a>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indigent/print.php?<?= $queryString ?>" target="_blank">Export / print PDF</a>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indigent/import.php">Import applications</a>
    </div>
    <p class="hint" style="margin-top:8px;">These export the current filter above, including the captured-date range (every row matching it). "Export / print PDF" opens a printable report — use your browser's Print &rarr; Save as PDF.</p>

    <h3 style="margin-top:18px;">Batch export selected rows</h3>
    <div class="actions-row">
        <button type="button" class="btn btn-secondary" id="batchExportCsv">Export selected &mdash; CSV</button>
        <button type="button" class="btn btn-secondary" id="batchExportExcel">Export selected &mdash; Excel</button>
        <button type="button" class="btn btn-secondary" id="batchExportPdf">Export selected &mdash; PDF</button>
    </div>
    <p class="hint" style="margin-top:8px;">Tick the checkbox next to the applications you want below, then pick a format here. Ignores the filter above — only the ticked rows are exported.</p>
</div>
<?php endif; ?>

<div class="card">
    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <?php if ($canManageData): ?><th><input type="checkbox" id="selectAllApps" title="Select all"></th><?php endif; ?>
            <th>Reference</th><th>Name</th><th>ID number</th><th>Province</th><th>Local Municipality</th>
            <th>Village</th><th>Income</th><th>TV</th><th>Captured</th><th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($apps as $a): ?>
            <tr>
                <?php if ($canManageData): ?><td><input type="checkbox" class="app-checkbox" value="<?= (int)$a['id'] ?>"></td><?php endif; ?>
                <td><?= e($a['reference_code']) ?></td>
                <td><?= e($a['first_name']) ?> <?= e($a['surname']) ?></td>
                <td><?= e($a['id_number']) ?></td>
                <td><?= e($a['province']) ?></td>
                <td><?= e($a['local_municipality']) ?></td>
                <td><?= e($a['village_name']) ?></td>
                <td>R<?= number_format((float)$a['monthly_income'], 2) ?></td>
                <td><?= $a['has_functional_tv'] ? 'Yes' : 'No' ?></td>
                <td><?= format_date($a['captured_at']) ?></td>
                <td><a href="<?= APP_BASE_URL ?>/indigent/view.php?id=<?= (int)$a['id'] ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$apps): ?>
            <tr><td colspan="<?= $canManageData ? 11 : 10 ?>">No applications match these filters.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php if ($canManageData): ?>
<script>
(function () {
    var selectAll = document.getElementById('selectAllApps');
    var checkboxes = function () { return Array.prototype.slice.call(document.querySelectorAll('.app-checkbox')); };

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
            alert('Please tick at least one application below before exporting.');
            return;
        }
        var url = '<?= APP_BASE_URL ?>/indigent/export.php?format=' + format + '&' + idsQueryString(ids);
        window.location.href = url;
    }

    document.getElementById('batchExportCsv').addEventListener('click', function () { batchExport('csv'); });
    document.getElementById('batchExportExcel').addEventListener('click', function () { batchExport('excel'); });
    document.getElementById('batchExportPdf').addEventListener('click', function () {
        var ids = selectedIds();
        if (!ids.length) {
            alert('Please tick at least one application below before exporting.');
            return;
        }
        window.open('<?= APP_BASE_URL ?>/indigent/print.php?' + idsQueryString(ids), '_blank');
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
