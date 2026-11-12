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
$rating = $_GET['rating'] ?? '';
$projectId = $_GET['project_id'] ?? '';
$search = trim($_GET['q'] ?? '');
$where = [];
$params = [];
if ($batchIds) {
    $where[] = 'r.id IN (' . implode(',', array_fill(0, count($batchIds), '?')) . ')';
    $params = array_merge($params, $batchIds);
} elseif ($singleId) {
    $where[] = 'r.id = ?';
    $params[] = $singleId;
} else {
    if ($status !== '' && in_array($status, ['Open', 'In Progress', 'Overdue', 'Closed'], true)) {
        $where[] = 'r.status = ?';
        $params[] = $status;
    }
    if ($rating !== '' && in_array($rating, ['Low', 'Medium', 'High', 'Critical'], true)) {
        $where[] = 'r.risk_rating = ?';
        $params[] = $rating;
    }
    if ($projectId !== '') {
        $where[] = 'r.project_id = ?';
        $params[] = (int)$projectId;
    }
    if ($search !== '') {
        $where[] = '(r.reference_code LIKE ? OR r.risk_description LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like; $params[] = $like;
    }
}

$sql = "SELECT r.*, p.project_name, u.full_name AS owner_name
        FROM risks r
        JOIN projects p ON p.id = r.project_id
        JOIN users u ON u.id = r.owner_user_id";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY (r.status = "Overdue") DESC, (r.risk_rating = "Critical") DESC, r.current_deadline ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$headers = [
    'Reference', 'Project', 'Category', 'Date Identified', 'Risk Description', 'Existing Controls',
    'Likelihood', 'Impact', 'Risk Rating', 'Mitigation Action', 'Owner',
    'Original Deadline', 'Current Deadline', 'Status', 'Follow-up Outcome', 'Closure Notes', 'Created At',
];

function risks_export_row(array $r): array
{
    return [
        $r['reference_code'], $r['project_name'], $r['category'], $r['date_identified'],
        $r['risk_description'], $r['existing_controls'],
        $r['likelihood'], $r['impact'], $r['risk_rating'], $r['mitigation_action'], $r['owner_name'],
        $r['original_deadline'], $r['current_deadline'], $r['status'],
        $r['follow_up_outcome'], $r['closure_notes'], $r['created_at'],
    ];
}

if ($singleId && $rows) {
    $filenameBase = 'risk_' . $rows[0]['reference_code'];
} elseif ($batchIds) {
    $filenameBase = 'risks_selected_' . count($rows) . '_' . date('Y-m-d');
} else {
    $filenameBase = 'risk_register_' . date('Y-m-d');
}

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $r) {
        fputcsv($out, risks_export_row($r));
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
<?php foreach ($rows as $r): ?>
<tr><?php foreach (risks_export_row($r) as $cell): ?><td><?= e((string)($cell ?? '')) ?></td><?php endforeach; ?></tr>
<?php endforeach; ?>
</table>
