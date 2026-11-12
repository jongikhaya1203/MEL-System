<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$status = $_GET['status'] ?? '';
$projectId = $_GET['project_id'] ?? '';
$level = $_GET['level'] ?? '';
$search = trim($_GET['q'] ?? '');
$singleId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$batchIds = (isset($_GET['ids']) && is_array($_GET['ids']))
    ? array_values(array_unique(array_filter(array_map('intval', $_GET['ids']))))
    : [];

$where = [];
$params = [];
if ($batchIds) {
    $where[] = 'i.id IN (' . implode(',', array_fill(0, count($batchIds), '?')) . ')';
    $params = array_merge($params, $batchIds);
} elseif ($singleId) {
    $where[] = 'i.id = ?';
    $params[] = $singleId;
} else {
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
}

$sql = "SELECT i.*, p.project_name, u.full_name AS owner_name,
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
$rows = $stmt->fetchAll();

$settings = get_site_settings($pdo);
$pageTitle = 'Indicators — Report';
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
    <div><strong>Indicators</strong> — report (<?= count($rows) ?> record<?= count($rows) === 1 ? '' : 's' ?>)</div>
    <div class="actions-row" style="margin:0;">
        <button type="button" class="btn" onclick="window.print()">Print / Save as PDF</button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indicators/list.php">Back to list</a>
    </div>
</div>

<div class="preview-sheet">
    <div class="preview-letterhead">
        <?php if ($settings['logo_url']): ?><img src="<?= e($settings['logo_url']) ?>" alt=""><?php endif; ?>
        <span class="org-name"><?= e($settings['site_name']) ?></span>
        <div class="doc-title">
            <h1>Indicators Report</h1>
            <div class="hint">Generated <?= format_datetime(date('Y-m-d H:i:s')) ?></div>
        </div>
    </div>

    <p class="report-meta">
        <?= count($rows) ?> indicator<?= count($rows) === 1 ? '' : 's' ?>
        <?php if ($batchIds): ?> &middot; selected records<?php endif; ?>
        <?php if ($level): ?> &middot; Level: <?= e($level) ?><?php endif; ?>
        <?php if ($status): ?> &middot; Status: <?= e($status) ?><?php endif; ?>
        <?php if ($search): ?> &middot; Search: "<?= e($search) ?>"<?php endif; ?>
    </p>

    <div class="table-wrap">
    <table class="report">
        <thead>
        <tr>
            <th>Reference</th><th>Level</th><th>Indicator</th><th>Project</th><th>Owner</th>
            <th>Baseline</th><th>Target</th><th>Latest actual</th>
            <th>Original target date</th><th>Current target date</th><th>Status</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $i): ?>
            <tr>
                <td><?= e($i['reference_code']) ?></td>
                <td><?= e($i['result_level']) ?></td>
                <td><?= e($i['indicator_name']) ?></td>
                <td><?= e($i['project_name']) ?></td>
                <td><?= e($i['owner_name']) ?></td>
                <td><?= format_number($i['baseline_value']) ?></td>
                <td><?= format_number($i['target_value']) ?></td>
                <td><?= $i['latest_actual'] !== null ? format_number($i['latest_actual']) : '—' ?></td>
                <td><?= format_date($i['original_target_date']) ?></td>
                <td><?= format_date($i['current_target_date']) ?></td>
                <td><?= e($i['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="11">No indicators match these filters.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

</body>
</html>
