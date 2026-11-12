<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'manager']);

// Covers the initial capture only — Site Code, Site Name, Project Code,
// Location, Households Connected, Completed Date, Installer Username.
// Verification/approval stay a proper workflow action (sites/verify.php,
// sites/approve.php), never a bulk-import field — update mode only ever
// touches the core capture fields below, never status or the approval chain.
const SITES_IMPORT_COLUMNS = [
    'site_code', 'site_name', 'project_code', 'location', 'households_connected', 'completed_date', 'installer_username',
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
        redirect('/sites/import.php');
    }

    $projectsByCode = $pdo->query('SELECT id, project_code FROM projects')->fetchAll(PDO::FETCH_KEY_PAIR);
    $projectsByCode = array_flip($projectsByCode); // project_code => id

    $created = 0; $updated = 0; $duplicates = 0; $invalid = 0;
    $rowDetails = [];
    $userId = current_user()['id'];

    foreach ($dataRows as $rowNum => $row) {
        $lineLabel = 'Row ' . ($rowNum + 2);
        if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) {
            continue;
        }

        $get = fn($i) => trim((string)($row[$i] ?? ''));

        $completedDate = parse_import_date($get(5));
        if (!import_date_in_range($completedDate, $periodStart, $periodEnd)) {
            continue;
        }

        $siteCode = $get(0);
        $siteName = $get(1);
        $projectCode = $get(2);
        $location = $get(3);
        $householdsRaw = $get(4);
        $installerUsername = $get(6);

        if ($siteName === '' || $projectCode === '' || $location === '' || $completedDate === null) {
            $invalid++;
            $rowDetails[] = ['row' => $lineLabel, 'outcome' => 'invalid', 'reason' => 'missing site name, project code, location, or a valid completed date.'];
            continue;
        }
        if (!isset($projectsByCode[$projectCode])) {
            $invalid++;
            $rowDetails[] = ['row' => $lineLabel, 'outcome' => 'invalid', 'reason' => "project code \"$projectCode\" was not found."];
            continue;
        }
        if (!ctype_digit($householdsRaw)) {
            $invalid++;
            $rowDetails[] = ['row' => $lineLabel, 'outcome' => 'invalid', 'reason' => 'households connected must be a whole number.'];
            continue;
        }
        $projectId = (int)$projectsByCode[$projectCode];
        $households = (int)$householdsRaw;

        $installerId = $userId;
        if ($installerUsername !== '') {
            $instStmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND role = 'field_worker'");
            $instStmt->execute([$installerUsername]);
            $foundInstaller = $instStmt->fetchColumn();
            if (!$foundInstaller) {
                $invalid++;
                $rowDetails[] = ['row' => $lineLabel, 'outcome' => 'invalid', 'reason' => "installer username \"$installerUsername\" was not found (must be an active field_worker account)."];
                continue;
            }
            $installerId = (int)$foundInstaller;
        }

        $existingId = null;
        if ($siteCode !== '') {
            $stmt = $pdo->prepare('SELECT id FROM sites WHERE site_code = ?');
            $stmt->execute([$siteCode]);
            $existingId = $stmt->fetchColumn() ?: null;
        }
        if (!$existingId) {
            $stmt = $pdo->prepare('SELECT id FROM sites WHERE site_name = ? AND project_id = ?');
            $stmt->execute([$siteName, $projectId]);
            $existingId = $stmt->fetchColumn() ?: null;
        }

        if ($existingId && $mode === 'reject_duplicates') {
            $duplicates++;
            $rowDetails[] = ['row' => $lineLabel, 'outcome' => 'duplicate_rejected', 'reason' => "a site named \"$siteName\" (or site code \"$siteCode\") already exists for this project — flagged, not imported."];
            continue;
        }

        if ($existingId && $mode === 'update') {
            $pdo->prepare('UPDATE sites SET site_name = ?, location = ?, households_connected = ?, completed_date = ? WHERE id = ?')
                ->execute([$siteName, $location, $households, $completedDate, $existingId]);
            $updated++;
            $rowDetails[] = ['row' => $lineLabel, 'outcome' => 'updated', 'reason' => 'matched an existing site — core capture fields updated (status/approval untouched).'];
            continue;
        }

        $finalSiteCode = $siteCode !== '' ? $siteCode : next_site_reference_code($pdo);
        try {
            $pdo->prepare(
                "INSERT INTO sites (site_code, project_id, site_name, location, households_connected, completed_date, status, submitted_by)
                 VALUES (?, ?, ?, ?, ?, ?, 'Submitted', ?)"
            )->execute([$finalSiteCode, $projectId, $siteName, $location, $households, $completedDate, $installerId]);
            $created++;
            $rowDetails[] = ['row' => $lineLabel, 'outcome' => 'created', 'reason' => "new site, code $finalSiteCode, status Submitted."];
        } catch (PDOException $e) {
            $invalid++;
            $rowDetails[] = ['row' => $lineLabel, 'outcome' => 'invalid', 'reason' => $e->getCode() === '23000' ? "site code \"$finalSiteCode\" clashed with another row in this file." : 'database error.'];
        }
    }

    $totalProcessed = $created + $updated + $duplicates + $invalid;
    log_import_run(
        $pdo, 'sites', $mode, $file['name'], $periodStart, $periodEnd,
        $totalProcessed, $created, $updated, $duplicates, $invalid, $rowDetails, $userId
    );

    $results = ['created' => $created, 'updated' => $updated, 'duplicates' => $duplicates, 'invalid' => $invalid, 'rowDetails' => $rowDetails, 'mode' => $mode];
}

$pageTitle = 'Import site completions';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Import site completions</h1>
        <p class="subtitle">Bulk-load installer-captured site completions from a CSV or Excel (.xlsx) file</p>
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
    <a class="btn" href="<?= APP_BASE_URL ?>/sites/list.php">View site completions</a>
    <?php if (current_user()['role'] === 'admin'): ?>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/reports/data_captured.php">View on Data Captured</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h2>Upload a file</h2>
    <p class="hint" style="margin-top:0;">
        Expected columns, in order: Site Code (optional, auto-generated if blank), Site Name, Project Code
        (e.g. USAASA-EC), Location, Households Connected, Completed Date, Installer Username (optional —
        defaults to you if blank; must be an existing Installer/field_worker account). New rows are created
        with status <strong>Submitted</strong> — verification and approval always go through the normal workflow,
        never bulk import. Updating an existing row (by Site Code, or Site Name + Project) only changes the core
        capture fields; status and approval history are never touched by import.
    </p>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="field full">
                <label for="import_file">CSV or Excel (.xlsx) file</label>
                <input type="file" id="import_file" name="import_file" accept=".csv,.xlsx" required>
            </div>
            <div class="field">
                <label for="mode">If a row matches an existing site</label>
                <select id="mode" name="mode">
                    <option value="reject_duplicates">Flag it as a duplicate and reject it (default)</option>
                    <option value="update">Update the existing record instead</option>
                </select>
            </div>
            <div class="field"></div>
            <div class="field">
                <label for="period_start">Only import rows completed on/after (optional)</label>
                <input type="date" id="period_start" name="period_start">
            </div>
            <div class="field">
                <label for="period_end">Only import rows completed on/before (optional)</label>
                <input type="date" id="period_end" name="period_end">
            </div>
        </div>
        <p class="hint">The date range is matched against each row's Completed Date — rows outside it are skipped without being counted as duplicates or invalid. Leave both blank to import every row.</p>
        <button type="submit" class="btn">Import</button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/sites/list.php">Cancel</a>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
