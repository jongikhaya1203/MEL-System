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
// stays admin/manager only — same split as the indigent applications export.
$targetCount = $batchIds ? count($batchIds) : ($singleId ? 1 : null);
if ($targetCount === 1) {
    require_login();
} else {
    require_role(['admin', 'manager']);
}

$status = $_GET['status'] ?? '';
$projectId = $_GET['project_id'] ?? '';
$search = trim($_GET['q'] ?? '');
$where = [];
$params = [];
if ($batchIds) {
    $where[] = 's.id IN (' . implode(',', array_fill(0, count($batchIds), '?')) . ')';
    $params = array_merge($params, $batchIds);
} elseif ($singleId) {
    $where[] = 's.id = ?';
    $params[] = $singleId;
} else {
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
    $dateFrom = parse_import_date($_GET['date_from'] ?? '');
    $dateTo = parse_import_date($_GET['date_to'] ?? '');
    if ($dateFrom !== null) {
        $where[] = 's.completed_date >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo !== null) {
        $where[] = 's.completed_date <= ?';
        $params[] = $dateTo;
    }
}

$sql = "SELECT s.*, p.project_name, sub.full_name AS submitted_by_name,
               ver.full_name AS verified_by_name, app.full_name AS approved_by_name, rej.full_name AS rejected_by_name,
               (SELECT COUNT(*) FROM site_poe_documents d WHERE d.site_id = s.id) AS poe_count
        FROM sites s
        JOIN projects p ON p.id = s.project_id
        JOIN users sub ON sub.id = s.submitted_by
        LEFT JOIN users ver ON ver.id = s.verified_by
        LEFT JOIN users app ON app.id = s.approved_by
        LEFT JOIN users rej ON rej.id = s.rejected_by";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY (s.status = "Submitted") DESC, (s.status = "Verified") DESC, s.submitted_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$headers = [
    'Site Code', 'Site Name', 'Project', 'Location', 'Households Connected', 'Completed Date', 'Status',
    'POE Files', 'Submitted By', 'Submitted At', 'Verified By', 'Verified At', 'Verification Notes',
    'Approved By', 'Approved At', 'Approval Notes', 'Rejected By', 'Rejected At', 'Rejection Reason',
];

function sites_export_row(array $s): array
{
    return [
        $s['site_code'], $s['site_name'], $s['project_name'], $s['location'],
        $s['households_connected'], $s['completed_date'], $s['status'], $s['poe_count'],
        $s['submitted_by_name'], $s['submitted_at'],
        $s['verified_by_name'], $s['verified_at'], $s['verification_notes'],
        $s['approved_by_name'], $s['approved_at'], $s['approval_notes'],
        $s['rejected_by_name'], $s['rejected_at'], $s['rejection_reason'],
    ];
}

if ($singleId && $rows) {
    $filenameBase = 'site_' . $rows[0]['site_code'];
} elseif ($batchIds) {
    $filenameBase = 'sites_selected_' . count($rows) . '_' . date('Y-m-d');
} else {
    $filenameBase = 'site_completions_' . date('Y-m-d');
}

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $s) {
        fputcsv($out, sites_export_row($s));
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
<?php foreach ($rows as $s): ?>
<tr><?php foreach (sites_export_row($s) as $cell): ?><td><?= e((string)($cell ?? '')) ?></td><?php endforeach; ?></tr>
<?php endforeach; ?>
</table>
