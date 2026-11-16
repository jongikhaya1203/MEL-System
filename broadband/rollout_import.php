<?php
/**
 * Bulk import of new broadband sites awaiting rollout, from CSV or Excel (.xlsx).
 * Columns are matched by their header names (any order), expected:
 *   Site Name | District Municipality | Local Municipality | GPS Coordinates [| (longitude)] | Village/Town | Contact Details
 * GPS may be one "lat, long" cell, or two cells (GPS Coordinates + an unnamed or "Longitude" column after it).
 * Province is worked out from the district / local municipality. ?template=1 downloads a blank CSV.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'manager', 'planner']);

if (!empty($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="broadband_rollout_sites_template.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Site Name', 'District Municipality', 'Local Municipality', 'GPS Coordinates', 'Village/Town', 'Contact Details']);
    fputcsv($out, ['Example Primary School', 'OR Tambo District Municipality', 'King Sabata Dalindyebo', '-31.5889, 28.7844', 'Mthatha', 'Mr J Dlamini (Principal) 082 123 4567']);
    fclose($out);
    exit;
}

/** Maps a header cell to one of our fields by name. */
function rollout_import_field(string $header): ?string
{
    $k = preg_replace('/[^a-z]/', '', strtolower($header));
    $map = [
        'site_name'  => ['sitename', 'site', 'name'],
        'district'   => ['districtmunicipality', 'districtmun', 'district', 'dm'],
        'local'      => ['localmunicipality', 'localmun', 'local', 'lm', 'municipality'],
        'gps'        => ['gpscoordinates', 'gpscoordinate', 'gps', 'coordinates', 'latitude', 'lat', 'gpslatitude'],
        'lng'        => ['longitude', 'long', 'lng', 'lon', 'gpslongitude'],
        'town'       => ['villagetown', 'townvillage', 'village', 'town', 'townandvillage', 'villageortown', 'townorvillage'],
        'contact'    => ['contactdetails', 'contact', 'contacts', 'contactdetail', 'contactperson'],
    ];
    foreach ($map as $field => $names) {
        if (in_array($k, $names, true)) {
            return $field;
        }
    }
    return null;
}

/**
 * Finds the local municipality named in a section heading such as "Unverified Sites Elias Motsoaledi" or
 * "Not verified Makhuduthama Municipality" (a shortened / misspelt name still matches by prefix).
 * Returns ['province', 'district', 'local'] or null.
 */
function rollout_import_section_municipality(string $heading): ?array
{
    $stripped = preg_replace('/\b(un|not\s+)?verified\b|\bsites?\b|\blocal\b|\bmunicipality\b|\bdistrict\b/i', ' ', $heading);
    $key = sa_municipality_key($stripped);
    if (strlen($key) < 5) {
        return null;
    }
    $hits = [];
    foreach (sa_municipal_structure() as $province => $rows) {
        foreach ($rows as $row) {
            foreach ($row['locals'] as $lm) {
                $lk = sa_municipality_key($lm);
                if ($lk === $key || strpos($lk, $key) === 0 || strpos($key, $lk) !== false) {
                    $hits[] = ['province' => $province, 'district' => $row['district'], 'local' => $lm];
                }
            }
        }
    }
    return count($hits) === 1 ? $hits[0] : null;
}

$results = null;
$projects =$pdo->query('SELECT id, project_name FROM projects ORDER BY project_name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $mode = ($_POST['mode'] ?? '') === 'update' ? 'update' : 'reject_duplicates';
    // 'auto' = put each site under its own province's "<Province> Connectivity Rollout" project.
    $autoProject = ($_POST['project_id'] ?? '') === 'auto';
    $projectId = $autoProject ? 0 : (int)($_POST['project_id'] ?? 0);
    $projectName = $autoProject ? "each site's own province (Connectivity Rollout projects)" : null;
    $provinceProject = [];
    foreach ($projects as $p) {
        if ((int)$p['id'] === $projectId) {
            $projectName = $p['project_name'];
        }
        foreach (sa_provinces() as $prov) {
            if (strcasecmp($p['project_name'], "$prov Connectivity Rollout") === 0) {
                $provinceProject[$prov] = (int)$p['id'];
            }
        }
    }
    if ($projectName === null) {
        flash('error', 'Please choose the project these sites belong to.');
        redirect('/broadband/rollout_import.php');
    }

    $file = $_FILES['import_file'] ?? [];
    $readError = null;
    // An Excel workbook may hold one list per sheet (e.g. a sheet per district): every sheet is imported.
    if (strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION)) === 'xlsx' && ($file['error'] ?? 1) === UPLOAD_ERR_OK) {
        try {
            $sheets = parse_xlsx_all_sheets($file['tmp_name']);
        } catch (Throwable $e) {
            $sheets = null;
            $readError = 'Could not read that file: ' . $e->getMessage();
        }
    } else {
        $rowsOne = read_import_rows($file, $readError, true);
        $sheets = $rowsOne === null ? null : ['' => $rowsOne];
    }
    if ($sheets === null) {
        flash('error', $readError);
        redirect('/broadband/rollout_import.php');
    }
    $sheets = array_filter($sheets, fn($rows) => count($rows) > 0);
    if (!$sheets) {
        flash('error', 'That file is empty.');
        redirect('/broadband/rollout_import.php');
    }

    $created = 0; $updated = 0; $duplicates = 0; $invalid = 0;
    $rowDetails = [];
    $userId = (int)current_user()['id'];
    $seenInFile = [];
    $unmapped = [];
    $sheetSummary = [];
    $multiSheet = count($sheets) > 1;

  foreach ($sheets as $sheetName => $allRows) {
    $prefix = $multiSheet ? "Sheet \"$sheetName\" " : '';
    // Header -> column index. An unnamed column straight after GPS is its longitude half.
    $header = array_shift($allRows);
    $cols = [];
    foreach ($header as $i => $h) {
        $field = rollout_import_field((string)$h);
        if ($field === null && trim((string)$h) === '' && isset($cols['gps']) && $cols['gps'] === $i - 1 && !isset($cols['lng'])) {
            $field = 'lng';
        }
        if ($field !== null && !isset($cols[$field])) {
            $cols[$field] = $i;
        }
    }
    if (!isset($cols['site_name'])) {
        if (!$multiSheet) {
            flash('error', 'Could not find a "Site Name" column in the first row. Use the template, or check the header row: Site Name, District Municipality, Local Municipality, GPS Coordinates, Village/Town, Contact Details.');
            redirect('/broadband/rollout_import.php');
        }
        $sheetSummary[] = "\"$sheetName\": skipped — no Site Name column in its first row";
        continue;
    }
    $sheetSummary[] = "\"$sheetName\": " . count($allRows) . ' rows';
    foreach ($header as $h) {
        $h = trim((string)$h);
        if ($h !== '' && rollout_import_field($h) === null) {
            $unmapped[$h] = true;
        }
    }
    $sectionMuni = null; // set by a section heading row, applies to the rows under it

    foreach ($allRows as $idx => $row) {
        $line = $prefix . 'Row ' . ($idx + 2);
        if (count(array_filter($row, fn($val) => trim((string)$val) !== '')) === 0) {
            continue;
        }
        $get = fn(string $f) => isset($cols[$f]) ? trim((string)($row[$cols[$f]] ?? '')) : '';

        $siteName = mb_substr($get('site_name'), 0, 255);
        // Summary / footer lines ("Total verified sites 153", a lone "23") are skipped, not counted as invalid.
        $filled = count(array_filter($row, fn($val) => trim((string)$val) !== ''));
        if (preg_match('/^(grand\s+)?totals?\b/i', $siteName) || ($siteName === '' && $filled <= 1)) {
            $rowDetails[] = ['row' => $line, 'outcome' => 'skipped', 'reason' => 'summary line, not a site — skipped.'];
            continue;
        }
        // Section headings ("Unverified Sites Elias Motsoaledi", "Fetakgomo-Tubatse unverified sites"): skipped, and
        // a municipality named in the heading is applied to the sites listed under it that have none of their own.
        if ($filled === 1 && preg_match('/\b(un|not\s+)?verified\b/i', $siteName)) {
            $sectionMuni = rollout_import_section_municipality($siteName);
            $rowDetails[] = ['row' => $line, 'outcome' => 'skipped', 'reason' => 'section heading, not a site — skipped'
                . ($sectionMuni ? "; sites below it without a municipality are placed in {$sectionMuni['local']}." : '.')];
            continue;
        }
        if ($siteName === '') {
            $invalid++;
            $rowDetails[] = ['row' => $line, 'outcome' => 'invalid', 'reason' => 'Site Name is blank.'];
            continue;
        }

        // GPS: two cells (lat + lng) or one "lat, long" cell.
        $notes = [];
        $lat = $lng = null;
        $gpsRaw = $get('gps');
        $lngRaw = $get('lng');
        if ($gpsRaw !== '' || $lngRaw !== '') {
            $pair = ($gpsRaw !== '' && $lngRaw !== '') ? parse_gps_pair($gpsRaw . ' ' . $lngRaw) : parse_gps_pair($gpsRaw . $lngRaw);
            if ($pair !== null) {
                [$lat, $lng] = $pair;
            } else {
                // Bad GPS never costs the whole site: register it without coordinates and flag it.
                $notes[] = 'GPS "' . trim("$gpsRaw $lngRaw") . '" could not be read — left blank, please capture it on the site';
            }
        }
        if ($lat !== null) {
            // South Africa: latitude is -22..-35, longitude 16..33. Fix the two common slips.
            if ($lat >= 16 && $lat <= 33 && $lng <= -22 && $lng >= -35) {
                [$lat, $lng] = [$lng, $lat];
                $notes[] = 'latitude and longitude were swapped — corrected';
            }
            if ($lat >= 22 && $lat <= 35 && $lng >= 16 && $lng <= 33) {
                $lat = -$lat;
                $notes[] = 'latitude made negative (South Africa is south of the equator)';
            }
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                $notes[] = 'GPS coordinates are out of range — left blank';
                $lat = $lng = null;
            } else {
                if (!($lat <= -22 && $lat >= -35 && $lng >= 16 && $lng <= 33)) {
                    $notes[] = 'GPS point looks to be outside South Africa — please check';
                }
                $lat = (string)round($lat, 6);
                $lng = (string)round($lng, 6);
            }
        }

        if ($get('district') === '' && $get('local') === '' && $sectionMuni) {
            $muni = $sectionMuni + ['notes' => []];
            $notes[] = 'municipality taken from the section heading above it';
        } else {
            $muni = sa_match_municipality($get('district'), $get('local'));
        }
        $notes = array_merge($notes, $muni['notes']);
        $town = $get('town') !== '' ? mb_substr($get('town'), 0, 255) : null;
        $contact = $get('contact') !== '' ? mb_substr($get('contact'), 0, 255) : null;

        // Duplicate = same site name in the same local municipality (or same project if no local municipality given).
        $dupKey = strtolower($siteName) . '|' . strtolower((string)$muni['local']);
        if (isset($seenInFile[$dupKey])) {
            $duplicates++;
            $rowDetails[] = ['row' => $line, 'outcome' => 'duplicate_rejected', 'reason' => "\"$siteName\" appears more than once in this file (first on {$seenInFile[$dupKey]})."];
            continue;
        }
        $seenInFile[$dupKey] = $line;

        $rowProjectId = $autoProject ? ($provinceProject[$muni['province'] ?? ''] ?? null) : $projectId;
        $existing = false;
        if ($muni['local'] !== null) {
            $stmt = $pdo->prepare('SELECT id, reference_code FROM broadband_rollout_sites WHERE site_name = ? AND local_municipality = ?');
            $stmt->execute([$siteName, $muni['local']]);
            $existing = $stmt->fetch();
        }
        if (!$existing && $rowProjectId) {
            // Same name already registered in this project without a municipality (e.g. from an earlier, sparser import).
            $stmt = $pdo->prepare('SELECT id, reference_code FROM broadband_rollout_sites WHERE site_name = ? AND project_id = ? AND local_municipality IS NULL');
            $stmt->execute([$siteName, $rowProjectId]);
            $existing = $stmt->fetch();
        }
        $noteText = $notes ? ' Note: ' . implode('; ', $notes) . '.' : '';

        if ($existing && $mode === 'reject_duplicates') {
            $duplicates++;
            $rowDetails[] = ['row' => $line, 'outcome' => 'duplicate_rejected', 'reason' => "\"$siteName\" is already registered as {$existing['reference_code']} — not imported."];
            continue;
        }

        $fields = [
            'site_name' => $siteName, 'province' => $muni['province'], 'district_municipality' => $muni['district'],
            'local_municipality' => $muni['local'], 'latitude' => $lat, 'longitude' => $lng,
            'town_village' => $town, 'contact_details' => $contact,
        ];

        if ($existing) {
            // Update mode: blank cells never wipe out values already captured.
            $set = array_filter($fields, fn($val) => $val !== null);
            $pdo->prepare('UPDATE broadband_rollout_sites SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($set))) . ' WHERE id = ?')
                ->execute([...array_values($set), $existing['id']]);
            $updated++;
            $rowDetails[] = ['row' => $line, 'outcome' => 'updated', 'reason' => "updated {$existing['reference_code']} (status and pre-assessment untouched).$noteText"];
            continue;
        }

        if (!$rowProjectId) {
            $invalid++;
            $rowDetails[] = ['row' => $line, 'outcome' => 'invalid', 'reason' => ($muni['province']
                ? "there is no \"{$muni['province']} Connectivity Rollout\" project"
                : 'the province could not be worked out from the municipality')
                . ' — not imported; fix the municipality, or import this row with a specific project chosen.' . $noteText];
            continue;
        }
        $fields += ['project_id' => $rowProjectId, 'reference_code' => next_rollout_reference_code($pdo),
                    'status' => 'Awaiting Pre-assessment', 'created_by' => $userId];
        $pdo->prepare('INSERT INTO broadband_rollout_sites (' . implode(', ', array_keys($fields)) . ') VALUES ('
            . implode(', ', array_fill(0, count($fields), '?')) . ')')->execute(array_values($fields));
        $created++;
        $rowDetails[] = ['row' => $line, 'outcome' => 'created', 'reason' => "registered as {$fields['reference_code']}" . ($muni['province'] ? " ({$muni['province']})" : '') . ".$noteText"];
    }
  }

    $total = $created + $updated + $duplicates + $invalid;
    log_import_run($pdo, 'broadband_rollout', $mode, (string)($file['name'] ?? ''), null, null,
        $total, $created, $updated, $duplicates, $invalid, $rowDetails, $userId);

    $results = compact('created', 'updated', 'duplicates', 'invalid', 'rowDetails', 'mode', 'projectName');
    $results['unmapped'] = array_keys($unmapped);
    $results['sheets'] = $multiSheet ? $sheetSummary : [];
}

$pageTitle = 'Import new broadband sites';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Import new sites awaiting rollout</h1>
        <p class="subtitle">Bulk-register broadband sites from a CSV or Excel (.xlsx) file</p>
    </div>
    <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/broadband/rollout_list.php">Back to the list</a>
</div>

<?php if ($results): ?>
<div class="card">
    <h2>Import results &mdash; <?= e($results['projectName']) ?> (<?= $results['mode'] === 'update' ? 'update existing sites' : 'reject duplicates' ?>)</h2>
    <p>
        <strong><?= $results['created'] ?></strong> registered,
        <strong><?= $results['updated'] ?></strong> updated,
        <strong><?= $results['duplicates'] ?></strong> duplicates rejected,
        <strong><?= $results['invalid'] ?></strong> invalid.
    </p>
    <?php if ($results['sheets']): ?>
        <p class="hint">Sheets read: <?= e(implode('; ', $results['sheets'])) ?>.</p>
    <?php endif; ?>
    <?php if ($results['unmapped']): ?>
        <div class="alert alert-warning">These columns were not recognised and were ignored: <?= e(implode(', ', $results['unmapped'])) ?>.</div>
    <?php endif; ?>
    <?php if ($results['rowDetails']): ?>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Row</th><th>Outcome</th><th>Details</th></tr></thead>
            <tbody>
            <?php foreach ($results['rowDetails'] as $d): ?>
                <tr>
                    <td><?= e($d['row']) ?></td>
                    <td><?= e(['created' => 'Registered', 'updated' => 'Updated', 'duplicate_rejected' => 'Duplicate', 'invalid' => 'Invalid', 'skipped' => 'Skipped'][$d['outcome']] ?? $d['outcome']) ?></td>
                    <td><?= e($d['reason']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
    <div class="actions-row">
        <a class="btn" href="<?= APP_BASE_URL ?>/broadband/rollout_list.php">View new sites awaiting rollout</a>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <h2>Upload a file</h2>
    <p class="hint" style="margin-top:0;">
        The first row must be the column headings. Columns are matched by name, in any order:
        <strong>Site Name</strong> (required), <strong>District Municipality</strong>, <strong>Local Municipality</strong>,
        <strong>GPS Coordinates</strong>, <strong>Village/Town</strong>, <strong>Contact Details</strong>.
    </p>
    <ul class="hint">
        <li>GPS can be one cell (<code>-31.5889, 28.7844</code>, <code>S31.5889 E28.7844</code>, <code>31°35'20"S 28°47'04"E</code> or <code>24'34'56" 30'19'21"</code>),
            or split over two columns: latitude under “GPS Coordinates” and longitude in the next column (heading blank or “Longitude”).
            A site whose GPS can't be read is still registered, with GPS left blank and a note below.</li>
        <li>The province is filled in from the district / local municipality. Names are matched loosely
            (“OR Tambo DM” = “OR Tambo District Municipality”), and anything unrecognised is kept exactly as typed and flagged below.</li>
        <li>New sites start as <strong>Awaiting Pre-assessment</strong>. A row is a duplicate when the same site name is already registered in the same local municipality.</li>
    </ul>
    <p><a href="?template=1">Download the CSV template</a></p>

    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="field full">
                <label for="import_file">CSV or Excel (.xlsx) file</label>
                <input type="file" id="import_file" name="import_file" accept=".csv,.xlsx" required>
            </div>
            <div class="field">
                <label for="project_id">Project these sites belong to</label>
                <select id="project_id" name="project_id" required>
                    <option value="">Select project…</option>
                    <option value="auto">Automatic — each site's own province (e.g. Free State → Free State Connectivity Rollout)</option>
                    <?php foreach ($projects as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"><?= e($p['project_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="mode">If a site is already registered</label>
                <select id="mode" name="mode">
                    <option value="reject_duplicates">Flag it as a duplicate and skip it (default)</option>
                    <option value="update">Update it with the values in the file</option>
                </select>
                <p class="hint">Updating never clears a value with a blank cell, and never changes status or the pre-assessment.</p>
            </div>
        </div>
        <button type="submit" class="btn">Import</button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/broadband/rollout_list.php">Cancel</a>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
