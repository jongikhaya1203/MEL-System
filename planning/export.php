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

$targetCount = $batchIds ? count($batchIds) : ($singleId ? 1 : null);
if ($targetCount === 1) {
    require_login();
} else {
    require_role(['admin', 'manager', 'planner']);
}

$status = $_GET['status'] ?? '';
$projectId = $_GET['project_id'] ?? '';
$installerId = $_GET['installer_id'] ?? '';
$search = trim($_GET['q'] ?? '');
$where = [];
$params = [];
if ($batchIds) {
    $where[] = 'a.id IN (' . implode(',', array_fill(0, count($batchIds), '?')) . ')';
    $params = array_merge($params, $batchIds);
} elseif ($singleId) {
    $where[] = 'a.id = ?';
    $params[] = $singleId;
} else {
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
    $dateFrom = parse_import_date($_GET['date_from'] ?? '');
    $dateTo = parse_import_date($_GET['date_to'] ?? '');
    if ($dateFrom !== null) {
        $where[] = 'a.current_deadline >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo !== null) {
        $where[] = 'a.current_deadline <= ?';
        $params[] = $dateTo;
    }
}

$sql = "SELECT a.*, p.project_code, p.province, inst.username AS installer_username, inst.full_name AS installer_name,
               dispatcher.full_name AS dispatcher_name,
               (SELECT ap.installations_completed FROM allocation_progress_updates ap
                WHERE ap.allocation_id = a.id ORDER BY ap.period_date DESC LIMIT 1) AS latest_completed
        FROM installer_allocations a
        JOIN projects p ON p.id = a.project_id
        JOIN users inst ON inst.id = a.installer_id
        JOIN users dispatcher ON dispatcher.id = a.dispatched_by";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY (a.status = "Overdue") DESC, a.current_deadline ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$headers = [
    'Reference', 'Province (Project Code)', 'Region', 'Installer Username', 'Target Installations',
    'Latest Completed', 'Original Deadline', 'Current Deadline', 'Status', 'Notes', 'Dispatched By', 'Dispatched At',
];

function allocations_export_row(array $a): array
{
    return [
        $a['reference_code'], $a['project_code'], $a['region'], $a['installer_username'], $a['target_installations'],
        $a['latest_completed'], $a['original_deadline'], $a['current_deadline'], $a['status'], $a['notes'],
        $a['dispatcher_name'], $a['dispatched_at'],
    ];
}

if ($singleId && $rows) {
    $filenameBase = 'allocation_' . $rows[0]['reference_code'];
} elseif ($batchIds) {
    $filenameBase = 'allocations_selected_' . count($rows) . '_' . date('Y-m-d');
} else {
    $filenameBase = 'installer_allocations_' . date('Y-m-d');
}

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $a) {
        fputcsv($out, allocations_export_row($a));
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
<?php foreach ($rows as $a): ?>
<tr><?php foreach (allocations_export_row($a) as $cell): ?><td><?= e((string)($cell ?? '')) ?></td><?php endforeach; ?></tr>
<?php endforeach; ?>
</table>
