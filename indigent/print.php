<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$province = $_GET['province'] ?? '';
$search = trim($_GET['q'] ?? '');
$singleId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$batchIds = (isset($_GET['ids']) && is_array($_GET['ids']))
    ? array_values(array_unique(array_filter(array_map('intval', $_GET['ids']))))
    : [];

$where = [];
$params = [];
if ($batchIds) {
    $where[] = 'a.id IN (' . implode(',', array_fill(0, count($batchIds), '?')) . ')';
    $params = array_merge($params, $batchIds);
} elseif ($singleId) {
    $where[] = 'a.id = ?';
    $params[] = $singleId;
} else {
    if ($province !== '' && in_array($province, sa_provinces(), true)) {
        $where[] = 'a.province = ?';
        $params[] = $province;
    }
    if ($search !== '') {
        $where[] = '(a.reference_code LIKE ? OR a.first_name LIKE ? OR a.surname LIKE ? OR a.id_number LIKE ? OR a.village_name LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $dateFrom = parse_import_date($_GET['date_from'] ?? '');
    $dateTo = parse_import_date($_GET['date_to'] ?? '');
    if ($dateFrom !== null) {
        $where[] = 'DATE(a.captured_at) >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo !== null) {
        $where[] = 'DATE(a.captured_at) <= ?';
        $params[] = $dateTo;
    }
}
$sql = "SELECT a.*, u.full_name AS captured_by_name
        FROM indigent_applications a
        JOIN users u ON u.id = a.captured_by";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY a.captured_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$settings = get_site_settings($pdo);
$pageTitle = 'Indigent Applications — Report';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?></title>
<link rel="stylesheet" href="<?= APP_BASE_URL ?>/assets/css/style.css">
<style>
    body { background: #e9eaea; }
    .preview-toolbar {
        position: sticky; top: 0; z-index: 10; background: var(--brand); color: #fff;
        padding: 12px 24px; display: flex; justify-content: space-between; align-items: center; gap: 12px;
    }
    .preview-toolbar .btn-secondary { background: rgba(255,255,255,.12); color: #fff; border-color: rgba(255,255,255,.3); }
    .preview-sheet { max-width: 1100px; margin: 28px auto 60px; background: #fff; border: 1px solid var(--border); border-radius: 6px; padding: 40px 48px; box-shadow: 0 2px 10px rgba(0,0,0,.06); }
    .preview-letterhead { display: flex; align-items: center; gap: 14px; border-bottom: 3px solid var(--orange); padding-bottom: 18px; margin-bottom: 24px; }
    .preview-letterhead img { height: 40px; width: auto; object-fit: contain; }
    .preview-letterhead .org-name { font-weight: 700; font-size: 16px; color: var(--brand); }
    .preview-letterhead .doc-title { margin-left: auto; text-align: right; }
    .preview-letterhead .doc-title h1 { font-size: 18px; margin: 0; }
    table.report { width: 100%; border-collapse: collapse; font-size: 11.5px; }
    table.report th, table.report td { border: 1px solid var(--border); padding: 5px 7px; text-align: left; white-space: nowrap; }
    table.report th { background: #eef2f5; }
    .report-meta { font-size: 12px; color: #5b6b78; margin-bottom: 14px; }
    @media print {
        body { background: #fff; }
        .preview-toolbar, .no-print { display: none !important; }
        .preview-sheet { box-shadow: none; border: none; margin: 0; padding: 0; max-width: 100%; }
        table.report { font-size: 9.5px; }
        @page { size: landscape; margin: 12mm; }
    }
</style>
</head>
<body>

<div class="preview-toolbar no-print">
    <div><strong>Indigent Applications</strong> — report (<?= count($rows) ?> record<?= count($rows) === 1 ? '' : 's' ?>)</div>
    <div class="actions-row" style="margin:0;">
        <button type="button" class="btn" onclick="window.print()">Print / Save as PDF</button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indigent/list.php">Back to list</a>
    </div>
</div>

<div class="preview-sheet">
    <div class="preview-letterhead">
        <?php if ($settings['logo_url']): ?><img src="<?= e($settings['logo_url']) ?>" alt=""><?php endif; ?>
        <span class="org-name"><?= e($settings['site_name']) ?></span>
        <div class="doc-title">
            <h1>Indigent Applications Report</h1>
            <div class="hint">Generated <?= format_datetime(date('Y-m-d H:i:s')) ?></div>
        </div>
    </div>

    <p class="report-meta">
        <?= count($rows) ?> application<?= count($rows) === 1 ? '' : 's' ?>
        <?php if ($batchIds): ?> &middot; selected records<?php endif; ?>
        <?php if ($province): ?> &middot; Province: <?= e($province) ?><?php endif; ?>
        <?php if ($search): ?> &middot; Search: "<?= e($search) ?>"<?php endif; ?>
    </p>

    <div class="table-wrap">
    <table class="report">
        <thead>
        <tr>
            <th>Reference</th><th>Name</th><th>ID Number</th><th>Province</th><th>District Mun.</th><th>Local Mun.</th>
            <th>Village</th><th>Pupils</th><th>Income (R)</th><th>TV</th><th>SA Citizen</th><th>SABC Concession</th>
            <th>Grant+Proof</th><th>Captured by</th><th>Captured</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $a): ?>
            <tr>
                <td><?= e($a['reference_code']) ?></td>
                <td><?= e($a['first_name']) ?> <?= e($a['surname']) ?></td>
                <td><?= e($a['id_number']) ?></td>
                <td><?= e($a['province']) ?></td>
                <td><?= e($a['district_municipality']) ?></td>
                <td><?= e($a['local_municipality']) ?></td>
                <td><?= e($a['village_name']) ?></td>
                <td><?= (int)$a['number_of_pupils'] ?></td>
                <td><?= number_format((float)$a['monthly_income'], 2) ?></td>
                <td><?= $a['has_functional_tv'] ? 'Y' : 'N' ?></td>
                <td><?= $a['declares_south_african'] ? 'Y' : 'N' ?></td>
                <td><?= $a['declares_sabc_concession'] ? 'Y' : 'N' ?></td>
                <td><?= $a['declares_grant_with_proof'] ? 'Y' : 'N' ?></td>
                <td><?= e($a['captured_by_name']) ?></td>
                <td><?= format_date($a['captured_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="15">No applications match these filters.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

</body>
</html>
