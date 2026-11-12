<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$format = $_GET['format'] ?? 'csv';
if (!in_array($format, ['csv', 'excel'], true)) {
    http_response_code(400);
    die('Unknown export format.');
}

$singleId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$isBatchRequest = isset($_GET['ids']);
$batchIds = ($isBatchRequest && is_array($_GET['ids']))
    ? array_values(array_unique(array_filter(array_map('intval', $_GET['ids']))))
    : [];
if ($isBatchRequest && !$batchIds) {
    http_response_code(400);
    die('No valid records were selected to export.');
}

// Exporting exactly one record only needs to be logged in; exporting
// several selected records or the whole filtered list is bulk export and
// stays admin/manager only — same split as the other registers' exports.
$targetCount = $batchIds ? count($batchIds) : ($singleId ? 1 : null);
if ($targetCount === 1) {
    require_login();
} else {
    require_role(['admin', 'manager']);
}

$status = $_GET['status'] ?? '';
$projectId = $_GET['project_id'] ?? '';
$level = $_GET['level'] ?? '';
$search = trim($_GET['q'] ?? '');
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

$headers = [
    'Reference', 'Project', 'Result Level', 'Result Statement', 'Indicator Name', 'Definition', 'Unit of Measure',
    'Baseline Value', 'Baseline Year', 'Target Value', 'Latest Actual', 'Data Source', 'Collection Frequency',
    'Owner', 'Original Target Date', 'Current Target Date', 'Status', 'Achievement Notes', 'Created At',
];

function indicators_export_row(array $i): array
{
    return [
        $i['reference_code'], $i['project_name'], $i['result_level'], $i['result_statement'],
        $i['indicator_name'], $i['definition'], $i['unit_of_measure'],
        $i['baseline_value'], $i['baseline_year'], $i['target_value'], $i['latest_actual'],
        $i['data_source'], $i['collection_frequency'], $i['owner_name'],
        $i['original_target_date'], $i['current_target_date'], $i['status'],
        $i['achievement_notes'], $i['created_at'],
    ];
}

if ($singleId && $rows) {
    $filenameBase = 'indicator_' . $rows[0]['reference_code'];
} elseif ($batchIds) {
    $filenameBase = 'indicators_selected_' . count($rows) . '_' . date('Y-m-d');
} else {
    $filenameBase = 'indicators_' . date('Y-m-d');
}

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $i) {
        fputcsv($out, indicators_export_row($i));
    }
    fclose($out);
    exit;
}

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filenameBase . '.xls"');
echo "\xEF\xBB\xBF";
?>
<table border="1">
<tr><?php foreach ($headers as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr>
<?php foreach ($rows as $i): ?>
<tr><?php foreach (indicators_export_row($i) as $cell): ?><td><?= e((string)($cell ?? '')) ?></td><?php endforeach; ?></tr>
<?php endforeach; ?>
</table>
