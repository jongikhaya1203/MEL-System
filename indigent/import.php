<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'manager']);

// Expected column order — matches indigent/export.php's CSV/Excel output
// exactly, so an exported file re-imports cleanly. Signature and photo
// aren't columns here; those stay capture-only via the form, since binary
// files don't fit a spreadsheet cell. Column 24 (Captured At) is used only
// to apply the optional date-range filter below — it's otherwise ignored,
// since captured_at is always server-set.
const INDIGENT_IMPORT_COLUMNS = [
    'reference_code', 'first_name', 'surname', 'id_number', 'id_capture_method', 'latitude', 'longitude',
    'province', 'district_municipality', 'local_municipality', 'village_name', 'physical_address',
    'number_of_pupils', 'distance_north_km', 'distance_south_km', 'distance_east_km', 'distance_west_km',
    'monthly_income', 'has_functional_tv', 'declares_south_african', 'declares_income_under_3200',
    'declares_sabc_concession', 'declares_grant_with_proof', 'captured_by_name_ignored', 'captured_at',
];

$results = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $mode = ($_POST['mode'] ?? '') === 'update' ? 'update' : 'reject_duplicates';
    $periodStart = parse_import_date($_POST['period_start'] ?? '');
    $periodEnd = parse_import_date($_POST['period_end'] ?? '');

    $file = $_FILES['import_file'] ?? null;
    $readError = null;
    $dataRows = read_import_rows($file, $readError);
    if ($dataRows === null) {
        flash('error', $readError);
        redirect('/indigent/import.php');
    }

    $created = 0; $updated = 0; $duplicates = 0; $invalid = 0;
    $rowDetails = [];
    $userId = current_user()['id'];

    foreach ($dataRows as $rowNum => $row) {
        $lineLabel = 'Row ' . ($rowNum + 2); // +2: 1-based, plus the header row
        if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) {
            continue; // skip fully blank rows — not counted either way
        }

        $get = fn($i) => trim((string)($row[$i] ?? ''));

        $rowDate = parse_import_date($get(23));
        if (!import_date_in_range($rowDate, $periodStart, $periodEnd)) {
            continue; // outside the requested date range — not counted either way
        }

        $refCode = $get(0) !== '' ? $get(0) : next_application_reference_code($pdo);
        $firstName = $get(1);
        $surname = $get(2);
        $idNumberRaw = $get(3);
        $idCaptureMethod = strtolower($get(4)) === 'scanned' ? 'scanned' : 'manual';
        $latitude = $get(5) !== '' && is_numeric($get(5)) ? $get(5) : null;
        $longitude = $get(6) !== '' && is_numeric($get(6)) ? $get(6) : null;
        $province = $get(7);
        $districtMun = $get(8);
        $localMun = $get(9);
        $village = $get(10);
        $address = $get(11) !== '' ? $get(11) : null;
        $pupils = ctype_digit($get(12)) ? (int)$get(12) : 0;
        $distN = $get(13) !== '' && is_numeric($get(13)) ? $get(13) : null;
        $distS = $get(14) !== '' && is_numeric($get(14)) ? $get(14) : null;
        $distE = $get(15) !== '' && is_numeric($get(15)) ? $get(15) : null;
        $distW = $get(16) !== '' && is_numeric($get(16)) ? $get(16) : null;
        $income = is_numeric($get(17)) ? $get(17) : null;
        $hasTv = yn_to_bool($get(18));
        $decSA = yn_to_bool($get(19));
        $decIncome = yn_to_bool($get(20));
        $decConcession = yn_to_bool($get(21));
        $decGrant = yn_to_bool($get(22));

        if ($firstName === '' || $surname === '' || $province === '' || $districtMun === '' || $localMun === '' || $village === '') {
            $invalid++;
            $rowDetails[] = ['row' => $lineLabel, 'outcome' => 'invalid', 'reason' => 'missing a required field (name, surname, province, district/local municipality or village).'];
            continue;
        }
        if (!in_array($province, sa_provinces(), true)) {
            $invalid++;
            $rowDetails[] = ['row' => $lineLabel, 'outcome' => 'invalid', 'reason' => "\"$province\" is not a recognised province."];
            continue;
        }
        $idCheck = validate_sa_id_number($idNumberRaw);
        if (!$idCheck['valid']) {
            $invalid++;
            $rowDetails[] = ['row' => $lineLabel, 'outcome' => 'invalid', 'reason' => "invalid ID number \"$idNumberRaw\"."];
            continue;
        }
        if ($income === null) {
            $invalid++;
            $rowDetails[] = ['row' => $lineLabel, 'outcome' => 'invalid', 'reason' => 'monthly income must be numeric.'];
            continue;
        }

        $idNumber = preg_replace('/\D/', '', $idNumberRaw);

        $existingStmt = $pdo->prepare('SELECT id FROM indigent_applications WHERE reference_code = ? OR id_number = ?');
        $existingStmt->execute([$refCode, $idNumber]);
        $existingId = $existingStmt->fetchColumn();

        if ($existingId && $mode === 'reject_duplicates') {
            $duplicates++;
            $rowDetails[] = ['row' => $lineLabel, 'outcome' => 'duplicate_rejected', 'reason' => "reference \"$refCode\" or ID number \"$idNumber\" already exists — flagged, not imported."];
            continue;
        }

        if ($existingId && $mode === 'update') {
            $pdo->prepare(
                "UPDATE indigent_applications SET
                    first_name=?, surname=?, id_capture_method=?, latitude=?, longitude=?,
                    province=?, district_municipality=?, local_municipality=?, village_name=?, physical_address=?,
                    number_of_pupils=?, distance_north_km=?, distance_south_km=?, distance_east_km=?, distance_west_km=?,
                    monthly_income=?, has_functional_tv=?,
                    declares_south_african=?, declares_income_under_3200=?, declares_sabc_concession=?, declares_grant_with_proof=?
                 WHERE id=?"
            )->execute([
                $firstName, $surname, $idCaptureMethod, $latitude, $longitude,
                $province, $districtMun, $localMun, $village, $address,
                $pupils, $distN, $distS, $distE, $distW,
                $income, $hasTv, $decSA, $decIncome, $decConcession, $decGrant,
                $existingId,
            ]);
            $updated++;
            $rowDetails[] = ['row' => $lineLabel, 'outcome' => 'updated', 'reason' => "matched existing record by reference/ID number — updated in place."];
            continue;
        }

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO indigent_applications (
                    reference_code, first_name, surname, id_number, id_capture_method,
                    latitude, longitude, province, district_municipality, local_municipality, village_name, physical_address,
                    number_of_pupils, distance_north_km, distance_south_km, distance_east_km, distance_west_km,
                    monthly_income, has_functional_tv,
                    declares_south_african, declares_income_under_3200, declares_sabc_concession, declares_grant_with_proof,
                    captured_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $refCode, $firstName, $surname, $idNumber, $idCaptureMethod,
                $latitude, $longitude, $province, $districtMun, $localMun, $village, $address,
                $pupils, $distN, $distS, $distE, $distW,
                $income, $hasTv, $decSA, $decIncome, $decConcession, $decGrant,
                $userId,
            ]);
            $created++;
            $rowDetails[] = ['row' => $lineLabel, 'outcome' => 'created', 'reason' => "new record, reference $refCode."];
        } catch (PDOException $e) {
            $invalid++;
            $rowDetails[] = ['row' => $lineLabel, 'outcome' => 'invalid', 'reason' => $e->getCode() === '23000'
                ? "reference \"$refCode\" or ID number \"$idNumber\" clashed with another row in this file."
                : 'database error.'];
        }
    }

    $totalProcessed = $created + $updated + $duplicates + $invalid;
    log_import_run(
        $pdo, 'indigent_applications', $mode, $file['name'], $periodStart, $periodEnd,
        $totalProcessed, $created, $updated, $duplicates, $invalid, $rowDetails, $userId
    );

    $results = ['created' => $created, 'updated' => $updated, 'duplicates' => $duplicates, 'invalid' => $invalid, 'rowDetails' => $rowDetails, 'mode' => $mode];
}

$pageTitle = 'Import applications';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Import applications</h1>
        <p class="subtitle">Bulk-load indigent applications from a CSV or Excel (.xlsx) file</p>
    </div>
</div>

<?php if ($results): ?>
<div class="card">
    <h2>Import results (<?= $results['mode'] === 'update' ? 'update existing records' : 'reject duplicates' ?> mode)</h2>
    <p>
        <strong><?= $results['created'] ?></strong> created,
        <strong><?= $results['updated'] ?></strong> updated,
        <strong><?= $results['duplicates'] ?></strong> flagged as duplicates (rejected),
        <strong><?= $results['invalid'] ?></strong> invalid.
    </p>
    <?php if ($results['rowDetails']): ?>
        <ul class="history-list">
            <?php foreach ($results['rowDetails'] as $d): ?>
                <li><strong><?= e($d['row']) ?> (<?= e($d['outcome']) ?>):</strong> <?= e($d['reason']) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <a class="btn" href="<?= APP_BASE_URL ?>/indigent/list.php">View applications</a>
    <?php if (current_user()['role'] === 'admin'): ?>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/reports/data_captured.php">View on Data Captured</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h2>Upload a file</h2>
    <p class="hint" style="margin-top:0;">
        Expected columns, in order: Reference, First Name, Surname, ID Number, ID Capture Method, Latitude, Longitude,
        Province, District Municipality, Local Municipality, Village/Area, Physical Address, Number of Pupils,
        Distance North/South/East/West (km), Monthly Income, Has Functional TV (Yes/No), Declares South African (Yes/No),
        Declares Income Under R3200 (Yes/No), Declares SABC Concession (Yes/No), Declares Grant With Proof (Yes/No),
        Captured By, Captured At. This is exactly what <a href="<?= APP_BASE_URL ?>/indigent/export.php?format=csv">Export CSV</a>
        produces, so an exported file round-trips cleanly. Reference may be left blank to auto-generate one.
    </p>
    <p class="hint">
        <strong>PDF is not a supported import source</strong> — it isn't a structured data format, so there's nothing
        reliable to extract rows from. PDF here is export/print only.
    </p>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="field full">
                <label for="import_file">CSV or Excel (.xlsx) file</label>
                <input type="file" id="import_file" name="import_file" accept=".csv,.xlsx" required>
            </div>
            <div class="field">
                <label for="mode">If a row matches an existing record (by reference or ID number)</label>
                <select id="mode" name="mode">
                    <option value="reject_duplicates">Flag it as a duplicate and reject it (default)</option>
                    <option value="update">Update the existing record instead</option>
                </select>
            </div>
            <div class="field"></div>
            <div class="field">
                <label for="period_start">Only import rows captured on/after (optional)</label>
                <input type="date" id="period_start" name="period_start">
            </div>
            <div class="field">
                <label for="period_end">Only import rows captured on/before (optional)</label>
                <input type="date" id="period_end" name="period_end">
            </div>
        </div>
        <p class="hint">The date range is matched against each row's "Captured At" column (if present) — rows outside it are skipped without being counted as duplicates or invalid. Leave both blank to import every row.</p>
        <button type="submit" class="btn">Import</button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indigent/list.php">Cancel</a>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
