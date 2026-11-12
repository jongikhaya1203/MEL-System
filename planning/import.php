<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'manager', 'planner']);

// Covers dispatch creation only — Reference, Province (Project Code), Region,
// Installer Username, Target Installations, Deadline, Notes. Deadlines and
// status always go through the normal workflow (planning/revise_deadline.php,
// progress updates) — update mode here only ever touches region/installer/
// target/notes, never the deadline or status.
const PLANNING_IMPORT_COLUMNS = [
    'reference_code', 'project_code', 'region', 'installer_username', 'target_installations', 'deadline', 'notes',
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
        redirect('/planning/import.php');
    }

    $projectsByCode = array_flip($pdo->query('SELECT id, project_code FROM projects WHERE province IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR));

    $created = 0; $updated = 0; $duplicates = 0; $invalid = 0;
    $rowDetails = [];
    $userId = current_user()['id'];

    foreach ($dataRows as $rowNum => $row) {
        $lineLabel = 'Row ' . ($rowNum + 2);
        if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) {
            continue;
        }

        $get = fn($i) => trim((string)($row[$i] ?? ''));

        $deadline = parse_import_date($get(5));
        if (!import_date_in_range($deadline, $periodStart, $periodEnd)) {
            continue;
        }

        $refCode = $get(0);
        $projectCode = $get(1);
        $region = $get(2);
        $installerUsername = $get(3);
        $targetRaw = $get(4);
        $notes = $get(6) !== '' ? $get(6) : null;

        if ($projectCode === '' || $region === '' || $installerUsername === '' || $deadline === null) {
            $invalid++;
            $rowDetails[] = ['row' => $lineLabel, 'outcome' => 'invalid', 'reason' => 'missing project code, region, installer username, or a valid deadline.'];
            continue;
        }
        if (!isset($projectsByCode[$projectCode])) {
            $invalid++;
            $rowDetails[] = ['row' => $lineLabel, 'outcome' => 'invalid', 'reason' => "project code \"$projectCode\" was not found (must be province-tagged)."];
            continue;
        }
        if (!ctype_digit($targetRaw) || (int)$targetRaw <= 0) {
            $invalid++;
            $rowDetails[] = ['row' => $lineLabel, 'outcome' => 'invalid', 'reason' => 'target installations must be a positive whole number.'];
            continue;
        }
        $projectId = (int)$projectsByCode[$projectCode];
        $provinceStmt = $pdo->prepare('SELECT province FROM projects WHERE id = ?');
        $provinceStmt->execute([$projectId]);
        $province = $provinceStmt->fetchColumn();
        if (!sa_validate_district($province, $region)) {
            $invalid++;
            $rowDetails[] = ['row' => $lineLabel, 'outcome' => 'invalid', 'reason' => "\"$region\" is not a valid district/metro for $province."];
            continue;
        }
        $instStmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND role = 'field_worker' AND is_active = 1");
        $instStmt->execute([$installerUsername]);
        $installerId = $instStmt->fetchColumn();
        if (!$installerId) {
            $invalid++;
            $rowDetails[] = ['row' => $lineLabel, 'outcome' => 'invalid', 'reason' => "installer username \"$installerUsername\" was not found (must be an active field_worker account)."];
            continue;
        }
        $installerId = (int)$installerId;
        $target = (int)$targetRaw;

        $existingId = null;
        if ($refCode !== '') {
            $stmt = $pdo->prepare('SELECT id FROM installer_allocations WHERE reference_code = ?');
            $stmt->execute([$refCode]);
            $existingId = $stmt->fetchColumn() ?: null;
        }
        if (!$existingId) {
            $stmt = $pdo->prepare('SELECT id FROM installer_allocations WHERE project_id = ? AND region = ? AND installer_id = ?');
            $stmt->execute([$projectId, $region, $installerId]);
            $existingId = $stmt->fetchColumn() ?: null;
        }

        if ($existingId && $mode === 'reject_duplicates') {
            $duplicates++;
            $rowDetails[] = ['row' => $lineLabel, 'outcome' => 'duplicate_rejected', 'reason' => "an allocation for this project/region/installer (or reference \"$refCode\") already exists — flagged, not imported."];
            continue;
        }

        if ($existingId && $mode === 'update') {
            $pdo->prepare('UPDATE installer_allocations SET region = ?, installer_id = ?, target_installations = ?, notes = ? WHERE id = ?')
                ->execute([$region, $installerId, $target, $notes, $existingId]);
            $updated++;
            $rowDetails[] = ['row' => $lineLabel, 'outcome' => 'updated', 'reason' => 'matched an existing allocation — region/installer/target/notes updated (deadline and status untouched).'];
            continue;
        }

        $finalRef = $refCode !== '' ? $refCode : next_allocation_reference_code($pdo);
        try {
            $pdo->prepare(
                "INSERT INTO installer_allocations (reference_code, project_id, region, installer_id, target_installations, original_deadline, current_deadline, status, notes, dispatched_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'Planned', ?, ?)"
            )->execute([$finalRef, $projectId, $region, $installerId, $target, $deadline, $deadline, $notes, $userId]);
            $created++;
            $rowDetails[] = ['row' => $lineLabel, 'outcome' => 'created', 'reason' => "new allocation, reference $finalRef."];
        } catch (PDOException $e) {
            $invalid++;
            $rowDetails[] = ['row' => $lineLabel, 'outcome' => 'invalid', 'reason' => $e->getCode() === '23000' ? "reference \"$finalRef\" clashed with another row in this file." : 'database error.'];
        }
    }

    $totalProcessed = $created + $updated + $duplicates + $invalid;
    log_import_run(
        $pdo, 'installer_allocations', $mode, $file['name'], $periodStart, $periodEnd,
        $totalProcessed, $created, $updated, $duplicates, $invalid, $rowDetails, $userId
    );

    $results = ['created' => $created, 'updated' => $updated, 'duplicates' => $duplicates, 'invalid' => $invalid, 'rowDetails' => $rowDetails, 'mode' => $mode];
}

$pageTitle = 'Import allocations';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Import allocations</h1>
        <p class="subtitle">Bulk-dispatch installer allocations from a CSV or Excel (.xlsx) file</p>
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
    <a class="btn" href="<?= APP_BASE_URL ?>/planning/list.php">View allocations</a>
    <?php if (current_user()['role'] === 'admin'): ?>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/reports/data_captured.php">View on Data Captured</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h2>Upload a file</h2>
    <p class="hint" style="margin-top:0;">
        Expected columns, in order: Reference (optional, auto-generated if blank), Project Code (e.g. USAASA-EC,
        must be province-tagged), Region (a valid district municipality or metro for that province), Installer
        Username (must be an active Installer/field_worker account), Target Installations, Deadline, Notes
        (optional). New rows are created with status <strong>Planned</strong>. Updating an existing row (by
        reference, or project + region + installer) only changes region/installer/target/notes — the deadline and
        status always go through <a href="<?= APP_BASE_URL ?>/planning/list.php">Planning &amp; Dispatch</a>'s
        normal workflow, never bulk import.
    </p>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="field full">
                <label for="import_file">CSV or Excel (.xlsx) file</label>
                <input type="file" id="import_file" name="import_file" accept=".csv,.xlsx" required>
            </div>
            <div class="field">
                <label for="mode">If a row matches an existing allocation</label>
                <select id="mode" name="mode">
                    <option value="reject_duplicates">Flag it as a duplicate and reject it (default)</option>
                    <option value="update">Update the existing record instead</option>
                </select>
            </div>
            <div class="field"></div>
            <div class="field">
                <label for="period_start">Only import rows with a deadline on/after (optional)</label>
                <input type="date" id="period_start" name="period_start">
            </div>
            <div class="field">
                <label for="period_end">Only import rows with a deadline on/before (optional)</label>
                <input type="date" id="period_end" name="period_end">
            </div>
        </div>
        <p class="hint">The date range is matched against each row's Deadline — rows outside it are skipped without being counted as duplicates or invalid. Leave both blank to import every row.</p>
        <button type="submit" class="btn">Import</button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/planning/list.php">Cancel</a>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
