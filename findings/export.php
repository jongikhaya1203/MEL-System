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
$search = trim($_GET['q'] ?? '');
$where = [];
$params = [];
if ($batchIds) {
    $where[] = 'f.id IN (' . implode(',', array_fill(0, count($batchIds), '?')) . ')';
    $params = array_merge($params, $batchIds);
} elseif ($singleId) {
    $where[] = 'f.id = ?';
    $params[] = $singleId;
} else {
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
}

$sql = "SELECT f.*, p.project_name, u.full_name AS owner_name
        FROM findings_register f
        JOIN projects p ON p.id = f.project_id
        JOIN users u ON u.id = f.owner_user_id";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY (f.status = "Overdue") DESC, f.current_deadline ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$headers = [
    'Reference', 'Project', 'Source', 'Source Date', 'Evidence-based Finding', 'Lesson Drawn',
    'Evidence and Limitations', 'Implication', 'Management Response', 'Owner',
    'Original Deadline', 'Current Deadline', 'Status', 'Follow-up Outcome', 'Closure Notes', 'Created At',
];

function findings_export_row(array $f): array
{
    return [
        $f['reference_code'], $f['project_name'], $f['source'], $f['source_date'],
        $f['evidence_based_finding'], $f['lesson_drawn'], $f['evidence_and_limitations'],
        $f['implication'], $f['management_response'], $f['owner_name'],
        $f['original_deadline'], $f['current_deadline'], $f['status'],
        $f['follow_up_outcome'], $f['closure_notes'], $f['created_at'],
    ];
}

if ($singleId && $rows) {
    $filenameBase = 'finding_' . $rows[0]['reference_code'];
} elseif ($batchIds) {
    $filenameBase = 'findings_selected_' . count($rows) . '_' . date('Y-m-d');
} else {
    $filenameBase = 'findings_register_' . date('Y-m-d');
}

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $f) {
        fputcsv($out, findings_export_row($f));
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
<?php foreach ($rows as $f): ?>
<tr><?php foreach (findings_export_row($f) as $cell): ?><td><?= e((string)($cell ?? '')) ?></td><?php endforeach; ?></tr>
<?php endforeach; ?>
</table>
