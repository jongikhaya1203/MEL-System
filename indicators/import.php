<?php
/**
 * Bulk import of Results Framework indicators from CSV, Excel (.xlsx) or this system's own exported .xls.
 * Columns are matched by heading (any order) and follow the indicator export layout, so an exported file
 * can be edited and imported back. Same rules as the New Indicator form (indicators/save.php):
 *   - new indicators start as status Open, with original = current target date;
 *   - updating an existing one changes its core fields only — never its target date (that goes through
 *     revise_target.php so the revision is logged), status, actuals or achievement confirmation.
 * Export-only columns (Latest Actual, Status, Achievement Notes, Created At) are ignored.
 * ?template=1 downloads a blank CSV template.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'manager']);

const IND_TEMPLATE_HEADERS = [
    'Reference', 'Project', 'Result Level', 'Result Statement', 'Indicator Name', 'Definition', 'Unit of Measure',
    'Baseline Value', 'Baseline Year', 'Target Value', 'Data Source', 'Collection Frequency', 'Owner', 'Target Date',
];
const IND_LEVELS = ['Impact', 'Outcome', 'Output'];
const IND_FREQUENCIES = ['Monthly', 'Quarterly', 'Biannual', 'Annual'];

if (!empty($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="indicators_import_template.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, IND_TEMPLATE_HEADERS);
    fputcsv($out, ['', 'USAASA-EC', 'Output', 'Households in the project area connected to broadband',
        'Number of households connected', 'Count of households with an active, installed broadband connection', 'Households',
        '0', date('Y'), '5000', 'Installer site completion reports', 'Quarterly', 'admin', date('Y') . '-12-31']);
    fclose($out);
    exit;
}

/** Heading -> field. Accepts the export's headings plus common variants. */
function indicator_import_field(string $header): ?string
{
    $k = preg_replace('/[^a-z]/', '', strtolower($header));
    $map = [
        'reference_code'       => ['reference', 'referencecode', 'ref', 'code', 'indicatorcode', 'indicatorref'],
        'project'              => ['project', 'projectname', 'projectcode'],
        'result_level'         => ['resultlevel', 'level'],
        'result_statement'     => ['resultstatement', 'result', 'statement'],
        'indicator_name'       => ['indicatorname', 'indicator', 'name'],
        'definition'           => ['definition', 'indicatordefinition', 'description'],
        'unit_of_measure'      => ['unitofmeasure', 'unit', 'uom', 'unitofmeasurement'],
        'baseline_value'       => ['baselinevalue', 'baseline'],
        'baseline_year'        => ['baselineyear'],
        'target_value'         => ['targetvalue', 'target'],
        'data_source'          => ['datasource', 'source', 'sourceofdata', 'meansofverification'],
        'collection_frequency' => ['collectionfrequency', 'frequency', 'reportingfrequency'],
        'owner'                => ['owner', 'responsibleperson', 'responsible', 'indicatorowner'],
        'target_date'          => ['targetdate', 'currenttargetdate', 'duedate', 'deadline'],
        'original_target_date' => ['originaltargetdate'], // only used when there's no current/target date column
    ];
    foreach ($map as $field => $names) {
        if (in_array($k, $names, true)) {
            return $field;
        }
    }
    return null;
}

/** "5 000", "1,250.5", "75%", "R 10 000" -> numeric string, or null. */
function indicator_import_number(string $raw): ?string
{
    $s = str_replace(["\xC2\xA0", ' ', 'R', 'r', '%'], '', trim($raw));
    if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $s)) {
        $s = str_replace(',', '', $s);
    } elseif (preg_match('/^-?\d+,\d+$/', $s)) {
        $s = str_replace(',', '.', $s); // decimal comma
    }
    return is_numeric($s) ? $s : null;
}

function indicator_import_frequency(string $raw): ?string
{
    $k = preg_replace('/[^a-z]/', '', strtolower($raw));
    $map = ['monthly' => 'Monthly', 'month' => 'Monthly', 'quarterly' => 'Quarterly', 'quarter' => 'Quarterly',
            'biannual' => 'Biannual', 'biannually' => 'Biannual', 'semiannual' => 'Biannual', 'halfyearly' => 'Biannual', 'sixmonthly' => 'Biannual',
            'annual' => 'Annual', 'annually' => 'Annual', 'yearly' => 'Annual'];
    return $map[$k] ?? null;
}

$projects = $pdo->query('SELECT id, project_code, project_name FROM projects ORDER BY project_name')->fetchAll();
$users = $pdo->query('SELECT id, username, full_name, email FROM users WHERE is_active = 1 ORDER BY full_name')->fetchAll();
$results = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $mode = ($_POST['mode'] ?? '') === 'update' ? 'update' : 'reject_duplicates';
    $defaultProjectId = (int)($_POST['default_project_id'] ?? 0);
    $defaultOwnerId = (int)($_POST['default_owner_id'] ?? 0);

    $file = $_FILES['import_file'] ?? [];
    $readError = null;
    $allRows = read_import_rows($file, $readError, true);
    if ($allRows === null || !$allRows) {
        flash('error', $readError ?? 'That file is empty.');
        redirect('/indicators/import.php');
    }

    $header = array_shift($allRows);
    $cols = [];
    foreach ($header as $i => $h) {
        $f = indicator_import_field((string)$h);
        if ($f !== null && !isset($cols[$f])) {
            $cols[$f] = $i;
        }
    }
    if (!isset($cols['target_date']) && isset($cols['original_target_date'])) {
        $cols['target_date'] = $cols['original_target_date'];
    }
    if (!isset($cols['indicator_name'])) {
        flash('error', 'Could not find an "Indicator Name" column in the first row. Download the template to see the expected headings.');
        redirect('/indicators/import.php');
    }

    // Lookups: project by code or name; owner by username, email or full name.
    $projectLookup = [];
    foreach ($projects as $p) {
        $projectLookup[strtolower($p['project_code'])] = (int)$p['id'];
        $projectLookup[strtolower($p['project_name'])] = (int)$p['id'];
    }
    $ownerLookup = [];
    foreach ($users as $u) {
        foreach (['username', 'email', 'full_name'] as $k) {
            if (!empty($u[$k])) {
                $ownerLookup[strtolower(trim($u[$k]))][] = (int)$u['id'];
            }
        }
    }

    $created = 0; $updated = 0; $duplicates = 0; $invalid = 0;
    $rowDetails = [];
    $userId = (int)current_user()['id'];
    $seen = [];

    foreach ($allRows as $idx => $row) {
        $line = 'Row ' . ($idx + 2);
        if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) {
            continue;
        }
        $get = fn(string $f) => isset($cols[$f]) ? trim((string)($row[$cols[$f]] ?? '')) : '';
        $problems = [];

        // Project
        $projectRaw = $get('project');
        $projectId = $projectRaw !== '' ? ($projectLookup[strtolower($projectRaw)] ?? null) : ($defaultProjectId ?: null);
        if ($projectId === null) {
            $problems[] = $projectRaw !== '' ? "project \"$projectRaw\" not found (use the project code or exact name)" : 'no project (fill the Project column or choose a default project)';
        }

        // Owner
        $ownerRaw = $get('owner');
        $ownerId = null;
        if ($ownerRaw !== '') {
            $hits = array_unique($ownerLookup[strtolower($ownerRaw)] ?? []);
            if (count($hits) === 1) {
                $ownerId = $hits[0];
            } else {
                $problems[] = count($hits) > 1 ? "owner \"$ownerRaw\" matches more than one user — use their username" : "owner \"$ownerRaw\" is not an active user";
            }
        } elseif ($defaultOwnerId) {
            $ownerId = $defaultOwnerId;
        } else {
            $problems[] = 'no owner (fill the Owner column or choose a default owner)';
        }

        $levelRaw = $get('result_level');
        $level = null;
        foreach (IND_LEVELS as $l) {
            if (strcasecmp($levelRaw, $l) === 0) {
                $level = $l;
            }
        }
        if ($level === null) {
            $problems[] = $levelRaw === '' ? 'Result Level is blank' : "Result Level \"$levelRaw\" must be Impact, Outcome or Output";
        }
        $freqRaw = $get('collection_frequency');
        $frequency = indicator_import_frequency($freqRaw);
        if ($frequency === null) {
            $problems[] = $freqRaw === '' ? 'Collection Frequency is blank' : "Collection Frequency \"$freqRaw\" must be Monthly, Quarterly, Biannual or Annual";
        }

        $text = [];
        foreach (['result_statement' => 'Result Statement', 'indicator_name' => 'Indicator Name', 'definition' => 'Definition',
                  'unit_of_measure' => 'Unit of Measure', 'data_source' => 'Data Source'] as $f => $label) {
            $text[$f] = $get($f);
            if ($text[$f] === '') {
                $problems[] = "$label is blank";
            }
        }
        $text['indicator_name'] = mb_substr($text['indicator_name'], 0, 255);
        $text['unit_of_measure'] = mb_substr($text['unit_of_measure'], 0, 100);
        $text['data_source'] = mb_substr($text['data_source'], 0, 255);

        $baseline = indicator_import_number($get('baseline_value'));
        $target = indicator_import_number($get('target_value'));
        $yearRaw = $get('baseline_year');
        if ($baseline === null) {
            $problems[] = 'Baseline Value must be a number';
        }
        if ($target === null) {
            $problems[] = 'Target Value must be a number';
        }
        if (!preg_match('/^(19|20)\d{2}$/', $yearRaw)) {
            $problems[] = 'Baseline Year must be a 4-digit year';
        }

        $reference = mb_substr($get('reference_code'), 0, 50);
        $targetDateRaw = $get('target_date');
        $targetDate = $targetDateRaw !== '' ? parse_import_date_or_serial($targetDateRaw) : null;
        if ($targetDateRaw !== '' && $targetDate === null) {
            $problems[] = "Target Date \"$targetDateRaw\" is not a valid date";
        }

        // Existing match: by reference, else same indicator name in the same project.
        $existing = false;
        if ($reference !== '') {
            $s = $pdo->prepare('SELECT id, reference_code, current_target_date FROM indicators WHERE reference_code = ?');
            $s->execute([$reference]);
            $existing = $s->fetch();
        }
        if (!$existing && $projectId && $text['indicator_name'] !== '') {
            $s = $pdo->prepare('SELECT id, reference_code, current_target_date FROM indicators WHERE project_id = ? AND indicator_name = ?');
            $s->execute([$projectId, $text['indicator_name']]);
            $existing = $s->fetch();
        }
        if (!$existing && $targetDateRaw === '') {
            $problems[] = 'Target Date is required for a new indicator';
        }

        if ($problems) {
            $invalid++;
            $rowDetails[] = ['row' => $line, 'outcome' => 'invalid', 'reason' => ucfirst(implode('; ', $problems)) . '.'];
            continue;
        }

        $dupKey = $projectId . '|' . strtolower($text['indicator_name']) . '|' . strtolower($reference);
        if (isset($seen[$dupKey])) {
            $duplicates++;
            $rowDetails[] = ['row' => $line, 'outcome' => 'duplicate_rejected', 'reason' => "\"{$text['indicator_name']}\" appears more than once in this file (first on {$seen[$dupKey]})."];
            continue;
        }
        $seen[$dupKey] = $line;

        if ($existing && $mode === 'reject_duplicates') {
            $duplicates++;
            $rowDetails[] = ['row' => $line, 'outcome' => 'duplicate_rejected', 'reason' => "already exists as {$existing['reference_code']} — not imported."];
            continue;
        }

        if ($existing) {
            $pdo->prepare(
                'UPDATE indicators SET project_id = ?, result_level = ?, result_statement = ?, indicator_name = ?, definition = ?,
                    unit_of_measure = ?, data_source = ?, collection_frequency = ?, baseline_value = ?, baseline_year = ?,
                    target_value = ?, responsible_user_id = ? WHERE id = ?'
            )->execute([$projectId, $level, $text['result_statement'], $text['indicator_name'], $text['definition'],
                $text['unit_of_measure'], $text['data_source'], $frequency, $baseline, (int)$yearRaw, $target, $ownerId, $existing['id']]);
            $updated++;
            $note = ($targetDate !== null && $targetDate !== $existing['current_target_date'])
                ? ' Target date NOT changed (' . format_date($existing['current_target_date']) . ' kept) — use “Revise target” on the indicator so the change is logged.'
                : '';
            $rowDetails[] = ['row' => $line, 'outcome' => 'updated', 'reason' => "updated {$existing['reference_code']} (status, actuals and target date untouched).$note"];
            continue;
        }

        $finalRef = $reference !== '' ? $reference : next_indicator_reference_code($pdo);
        try {
            $pdo->prepare(
                "INSERT INTO indicators (reference_code, project_id, result_level, result_statement, indicator_name, definition,
                    unit_of_measure, data_source, collection_frequency, baseline_value, baseline_year, target_value,
                    responsible_user_id, original_target_date, current_target_date, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Open', ?)"
            )->execute([$finalRef, $projectId, $level, $text['result_statement'], $text['indicator_name'], $text['definition'],
                $text['unit_of_measure'], $text['data_source'], $frequency, $baseline, (int)$yearRaw, $target,
                $ownerId, $targetDate, $targetDate, $userId]);
            $created++;
            $rowDetails[] = ['row' => $line, 'outcome' => 'created', 'reason' => "created as $finalRef (status Open, target " . format_date($targetDate) . ').'];
        } catch (PDOException $e) {
            $invalid++;
            $rowDetails[] = ['row' => $line, 'outcome' => 'invalid', 'reason' => $e->getCode() === '23000' ? "reference \"$finalRef\" is already in use." : 'database error.'];
        }
    }

    log_import_run($pdo, 'indicators', $mode, (string)($file['name'] ?? ''), null, null,
        $created + $updated + $duplicates + $invalid, $created, $updated, $duplicates, $invalid, $rowDetails, $userId);

    $results = compact('created', 'updated', 'duplicates', 'invalid', 'rowDetails', 'mode');
    $results['ignored'] = array_values(array_filter(array_map(fn($h) => trim((string)$h), $header),
        fn($h) => $h !== '' && indicator_import_field($h) === null));
}

$pageTitle = 'Import indicators';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Import indicators</h1>
        <p class="subtitle">Bulk-load Results Framework indicators from a CSV or Excel file</p>
    </div>
    <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indicators/framework.php">Back to Results Framework</a>
</div>

<?php if ($results): ?>
<div class="card">
    <h2>Import results (<?= $results['mode'] === 'update' ? 'update existing indicators' : 'reject duplicates' ?>)</h2>
    <p>
        <strong><?= $results['created'] ?></strong> created,
        <strong><?= $results['updated'] ?></strong> updated,
        <strong><?= $results['duplicates'] ?></strong> duplicates rejected,
        <strong><?= $results['invalid'] ?></strong> invalid.
    </p>
    <?php if ($results['ignored']): ?>
        <p class="hint">Columns ignored (export-only or not recognised): <?= e(implode(', ', $results['ignored'])) ?>.</p>
    <?php endif; ?>
    <?php if ($results['rowDetails']): ?>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Row</th><th>Outcome</th><th>Details</th></tr></thead>
            <tbody>
            <?php foreach ($results['rowDetails'] as $d): ?>
                <tr>
                    <td><?= e($d['row']) ?></td>
                    <td><?= e(['created' => 'Created', 'updated' => 'Updated', 'duplicate_rejected' => 'Duplicate', 'invalid' => 'Invalid'][$d['outcome']] ?? $d['outcome']) ?></td>
                    <td><?= e($d['reason']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
    <div class="actions-row">
        <a class="btn" href="<?= APP_BASE_URL ?>/indicators/framework.php">View Results Framework</a>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indicators/list.php">View indicator register</a>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <h2>Upload a file</h2>
    <p class="hint" style="margin-top:0;">
        The first row must be the column headings &mdash; the same headings as the indicator export, in any order:
        <?= e(implode(', ', IND_TEMPLATE_HEADERS)) ?>.
    </p>
    <ul class="hint">
        <li><strong>Reference</strong> is optional &mdash; left blank, one is generated (USAASA-IND-<?= date('Y') ?>-…).</li>
        <li><strong>Project</strong>: project code (e.g. USAASA-EC) or exact name. <strong>Owner</strong>: username, email or full name of an active user.
            Either can be left out of the file and set with the defaults below.</li>
        <li><strong>Result Level</strong>: Impact, Outcome or Output. <strong>Collection Frequency</strong>: Monthly, Quarterly, Biannual or Annual
            (“semi-annual”, “yearly” etc. are understood).</li>
        <li><strong>Target Date</strong> is required for new indicators (e.g. 2027-03-31 or 31/03/2027). An exported file's “Current Target Date” column works too.</li>
        <li>New indicators start as <strong>Open</strong>. Updating an existing indicator (matched by Reference, or same name in the same project)
            changes its core fields only &mdash; its target date, status, actuals and confirmation are never changed by import.</li>
        <li>An Excel file exported from the indicator register (.xls) can be edited and imported straight back.</li>
    </ul>
    <p><a href="?template=1">Download the CSV template</a></p>

    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="field full">
                <label for="import_file">CSV or Excel file (.csv, .xlsx, or an exported .xls)</label>
                <input type="file" id="import_file" name="import_file" accept=".csv,.xlsx,.xls" required>
            </div>
            <div class="field">
                <label for="default_project_id">Default project (for rows with no Project)</label>
                <select id="default_project_id" name="default_project_id">
                    <option value="">None &mdash; every row must name its project</option>
                    <?php foreach ($projects as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"><?= e($p['project_name']) ?> (<?= e($p['project_code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="default_owner_id">Default owner (for rows with no Owner)</label>
                <select id="default_owner_id" name="default_owner_id">
                    <option value="">None &mdash; every row must name its owner</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"><?= e($u['full_name']) ?> (<?= e($u['username']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="mode">If an indicator already exists</label>
                <select id="mode" name="mode">
                    <option value="reject_duplicates">Flag it as a duplicate and skip it (default)</option>
                    <option value="update">Update it with the values in the file</option>
                </select>
            </div>
        </div>
        <button type="submit" class="btn">Import</button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indicators/framework.php">Cancel</a>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
