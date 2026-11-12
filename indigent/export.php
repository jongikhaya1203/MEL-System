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
// A batch request that resolves to zero valid ids must fail, not silently
// fall through to an unfiltered export of every record.
if ($isBatchRequest && !$batchIds) {
    http_response_code(400);
    die('No valid records were selected to export.');
}

// Exporting one record you can already view (e.g. the one you just
// captured) only needs to be logged in — same bar as the single-record
// PDF preview/print. Exporting several selected records or the whole
// filtered list is bulk PII export and stays admin/manager only, same as
// the "Import & export" card on the list page that links here.
$targetCount = $batchIds ? count($batchIds) : ($singleId ? 1 : null);
if ($targetCount === 1) {
    require_login();
} else {
    require_role(['admin', 'manager']);
}

$province = $_GET['province'] ?? '';
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

$headers = [
    'Reference', 'First Name', 'Surname', 'ID Number', 'ID Capture Method', 'Latitude', 'Longitude',
    'Province', 'District Municipality', 'Local Municipality', 'Village/Area', 'Physical Address',
    'Number of Pupils', 'Distance North (km)', 'Distance South (km)', 'Distance East (km)', 'Distance West (km)',
    'Monthly Income', 'Has Functional TV', 'Declares South African', 'Declares Income Under R3200',
    'Declares SABC Concession', 'Declares Grant With Proof', 'Captured By', 'Captured At',
];

function indigent_export_row(array $a): array
{
    $yn = fn($v) => $v ? 'Yes' : 'No';
    return [
        $a['reference_code'], $a['first_name'], $a['surname'], $a['id_number'], $a['id_capture_method'],
        $a['latitude'], $a['longitude'], $a['province'], $a['district_municipality'], $a['local_municipality'],
        $a['village_name'], $a['physical_address'], $a['number_of_pupils'],
        $a['distance_north_km'], $a['distance_south_km'], $a['distance_east_km'], $a['distance_west_km'],
        $a['monthly_income'], $yn($a['has_functional_tv']), $yn($a['declares_south_african']),
        $yn($a['declares_income_under_3200']), $yn($a['declares_sabc_concession']), $yn($a['declares_grant_with_proof']),
        $a['captured_by_name'], $a['captured_at'],
    ];
}

if ($singleId && $rows) {
    $filenameBase = 'indigent_' . $rows[0]['reference_code'];
} elseif ($batchIds) {
    $filenameBase = 'indigent_selected_' . count($rows) . '_' . date('Y-m-d');
} else {
    $filenameBase = 'indigent_applications_' . date('Y-m-d');
}

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel opens accented characters correctly
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $a) {
        fputcsv($out, indigent_export_row($a));
    }
    fclose($out);
    exit;
}

// Excel: an HTML table served with an .xls extension and the Excel MIME
// type — Excel opens this reliably (it understands HTML tables), with a
// one-time "format differs from extension" prompt. This avoids needing a
// spreadsheet library to hand-build real OOXML, consistent with this
// app having zero external dependencies.
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filenameBase . '.xls"');
echo "\xEF\xBB\xBF";
?>
<table border="1">
<tr><?php foreach ($headers as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr>
<?php foreach ($rows as $a): ?>
<tr><?php foreach (indigent_export_row($a) as $cell): ?><td><?= e((string)($cell ?? '')) ?></td><?php endforeach; ?></tr>
<?php endforeach; ?>
</table>
