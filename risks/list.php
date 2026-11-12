<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

sync_overdue_statuses($pdo);

$status = $_GET['status'] ?? '';
$projectId = $_GET['project_id'] ?? '';
$rating = $_GET['rating'] ?? '';
$search = trim($_GET['q'] ?? '');

$where = [];
$params = [];

if ($status !== '' && in_array($status, ['Open', 'In Progress', 'Overdue', 'Closed'], true)) {
    $where[] = 'r.status = ?';
    $params[] = $status;
}
if ($projectId !== '') {
    $where[] = 'r.project_id = ?';
    $params[] = (int)$projectId;
}
if ($rating !== '' && in_array($rating, ['Low', 'Medium', 'High', 'Critical'], true)) {
    $where[] = 'r.risk_rating = ?';
    $params[] = $rating;
}
if ($search !== '') {
    $where[] = '(r.reference_code LIKE ? OR r.risk_description LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like;
}

$sql = "SELECT r.id, r.reference_code, r.category, r.status, r.risk_rating,
               r.original_deadline, r.current_deadline,
               p.project_name, u.full_name AS owner_name,
               (SELECT COUNT(*) FROM risk_deadline_revisions dr WHERE dr.risk_id = r.id) AS revision_count
        FROM risks r
        JOIN projects p ON p.id = r.project_id
        JOIN users u ON u.id = r.owner_user_id";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY (r.status = "Overdue") DESC, (r.risk_rating = "Critical") DESC, r.current_deadline ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$risks = $stmt->fetchAll();

$projects = $pdo->query('SELECT id, project_name FROM projects ORDER BY project_name')->fetchAll();

$canCreate = in_array(current_user()['role'], ['admin', 'manager'], true);
$canManageData = $canCreate;
$queryString = http_build_query(array_filter(['status' => $status, 'rating' => $rating, 'project_id' => $projectId, 'q' => $search]));

$pageTitle = 'Risk Register';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Risk Register</h1>
        <p class="subtitle">Identified risks, ratings, mitigation actions and treatment deadlines</p>
    </div>
    <?php if ($canCreate): ?>
        <a class="btn" href="<?= APP_BASE_URL ?>/risks/form.php">+ New risk</a>
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
            <label for="rating">Risk rating</label>
            <select id="rating" name="rating" onchange="this.form.submit()">
                <option value="">All ratings</option>
                <?php foreach (['Low', 'Medium', 'High', 'Critical'] as $r): ?>
                    <option value="<?= $r ?>" <?= $rating === $r ? 'selected' : '' ?>><?= $r ?></option>
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
            <label for="q">Search reference / description</label>
            <input type="text" id="q" name="q" value="<?= e($search) ?>" placeholder="e.g. USAASA-RISK-2026-0001">
        </div>
        <div class="field">
            <button type="submit" class="btn btn-secondary">Filter</button>
            <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/risks/list.php">Reset</a>
        </div>
    </form>
</div>

<?php if ($canManageData): ?>
<div class="card">
    <h2>Export</h2>
    <div class="actions-row">
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/risks/export.php?format=csv&<?= $queryString ?>">Export CSV</a>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/risks/export.php?format=excel&<?= $queryString ?>">Export Excel</a>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/risks/print.php?<?= $queryString ?>" target="_blank">Export / print PDF</a>
    </div>
    <p class="hint" style="margin-top:8px;">These export the current filter above (every row matching it).</p>

    <h3 style="margin-top:18px;">Batch export selected rows</h3>
    <div class="actions-row">
        <button type="button" class="btn btn-secondary" id="batchExportCsv">Export selected &mdash; CSV</button>
        <button type="button" class="btn btn-secondary" id="batchExportExcel">Export selected &mdash; Excel</button>
        <button type="button" class="btn btn-secondary" id="batchExportPdf">Export selected &mdash; PDF</button>
    </div>
    <p class="hint" style="margin-top:8px;">Tick the checkbox next to the risks you want below, then pick a format here. Ignores the filter above.</p>
</div>
<?php endif; ?>

<div class="card">
    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <?php if ($canManageData): ?><th><input type="checkbox" id="selectAllRisks" title="Select all"></th><?php endif; ?>
            <th>Reference</th><th>Project</th><th>Category</th><th>Rating</th><th>Owner</th>
            <th>Original deadline</th><th>Current deadline</th>
            <th>Status</th><th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($risks as $r): ?>
            <tr>
                <?php if ($canManageData): ?><td><input type="checkbox" class="risk-checkbox" value="<?= (int)$r['id'] ?>"></td><?php endif; ?>
                <td><?= e($r['reference_code']) ?></td>
                <td><?= e($r['project_name']) ?></td>
                <td><?= e($r['category']) ?></td>
                <td><?= risk_rating_badge($r['risk_rating']) ?></td>
                <td><?= e($r['owner_name']) ?></td>
                <td><?= format_date($r['original_deadline']) ?></td>
                <td>
                    <?= format_date($r['current_deadline']) ?>
                    <?php if ($r['revision_count'] > 0): ?>
                        <span class="hint">(revised <?= (int)$r['revision_count'] ?>x)</span>
                    <?php endif; ?>
                </td>
                <td><?= status_badge($r['status']) ?></td>
                <td><a href="<?= APP_BASE_URL ?>/risks/view.php?id=<?= (int)$r['id'] ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$risks): ?>
            <tr><td colspan="<?= $canManageData ? 10 : 9 ?>">No risks match these filters.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php if ($canManageData): ?>
<script>
(function () {
    var selectAll = document.getElementById('selectAllRisks');
    var checkboxes = function () { return Array.prototype.slice.call(document.querySelectorAll('.risk-checkbox')); };

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
            alert('Please tick at least one risk below before exporting.');
            return;
        }
        window.location.href = '<?= APP_BASE_URL ?>/risks/export.php?format=' + format + '&' + idsQueryString(ids);
    }

    document.getElementById('batchExportCsv').addEventListener('click', function () { batchExport('csv'); });
    document.getElementById('batchExportExcel').addEventListener('click', function () { batchExport('excel'); });
    document.getElementById('batchExportPdf').addEventListener('click', function () {
        var ids = selectedIds();
        if (!ids.length) {
            alert('Please tick at least one risk below before exporting.');
            return;
        }
        window.open('<?= APP_BASE_URL ?>/risks/print.php?' + idsQueryString(ids), '_blank');
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
