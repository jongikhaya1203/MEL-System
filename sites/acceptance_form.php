<?php
/**
 * Broadband Infrastructure Acceptance Form — one per site.
 * GET  ?id=<site id>  shows the form (pre-filled from the site record until first saved).
 * POST                saves it (roles in $editRoles only), then redirects back.
 * The page prints as a paper form for wet-ink signatures (Print button / Ctrl+P).
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$editRoles = ['admin', 'manager', 'field_worker', 'back_office', 'approver'];
$canEdit = in_array(current_user()['role'], $editRoles, true);

$siteId = (int)($_POST['site_id'] ?? $_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT s.id, s.site_code, s.site_name, s.location, p.project_name
     FROM sites s JOIN projects p ON p.id = s.project_id WHERE s.id = ?'
);
$stmt->execute([$siteId]);
$site = $stmt->fetch();
if (!$site) {
    flash('error', 'Site not found.');
    redirect('/sites/list.php');
}

$siteTypes = ['School', 'Hotspot', 'Clinic', 'Library', 'Other'];
$ynFields = [
    'installation_neat'       => 'Installation neat?',
    'dedicated_power_plug'    => 'Dedicated Power Plug',
    'poe_enclosure_installed' => 'PoE Enclosure Installed',
    'secure_room'             => 'In a secure room?',
    'adapter_mounted'         => 'Adapter Mounted',
    'mounted'                 => 'Mounted',
];
$wifiFields = [
    'wifi_vsat'        => 'VSAT',
    'wifi_terrestrial' => 'Terrestrial',
];
$textFields = [
    'project_name', 'site_name', 'site_type_other', 'official_site_id', 'province',
    'district_municipality', 'local_municipality', 'town_village', 'latitude', 'longitude',
    'service_provider', 'installing_company',
    'backup_power_type',
    'site_rep_name', 'site_rep_designation', 'site_rep_contact',
    'usaasa_rep_name', 'usaasa_rep_designation', 'usaasa_rep_contact',
];
$dateFields = ['site_rep_date', 'usaasa_rep_date'];
$signers = ['site_rep', 'usaasa_rep'];
$signerLabels = ['site_rep' => 'Site Representative', 'usaasa_rep' => 'USAASA Representative'];
// Proof image columns => [upload input name, label, file endpoint type]
$proofs = [
    'backup_power_proof' => ['label' => 'backup power proof', 'type' => 'power_proof'],
    'speed_proof'        => ['label' => 'connection speed proof', 'type' => 'speed_proof'],
];
$isOurFile = fn($f) => is_string($f) && preg_match('/^(signature|proof)_[0-9a-f]{32}\.(png|jpg)$/', $f);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) {
        http_response_code(403);
        die('You do not have permission to edit acceptance forms.');
    }
    csrf_verify();

    $data = [];
    foreach ($textFields as $f) {
        $v = trim((string)($_POST[$f] ?? ''));
        $data[$f] = $v === '' ? null : mb_substr($v, 0, 255);
    }
    $type = $_POST['site_type'] ?? '';
    $data['site_type'] = in_array($type, $siteTypes, true) ? $type : null;
    if ($data['site_type'] !== 'Other') {
        $data['site_type_other'] = null;
    }
    foreach (array_merge(array_keys($ynFields), array_keys($wifiFields), ['backup_power']) as $f) {
        $v = $_POST[$f] ?? '';
        $data[$f] = in_array($v, ['Y', 'N'], true) ? $v : null;
    }
    foreach ($dateFields as $f) {
        $data[$f] = parse_import_date((string)($_POST[$f] ?? ''));
    }

    $errors = [];
    foreach (['latitude' => [-90, 90], 'longitude' => [-180, 180]] as $f => [$min, $max]) {
        if ($data[$f] !== null && (!is_numeric($data[$f]) || (float)$data[$f] < $min || (float)$data[$f] > $max)) {
            $errors[] = ucfirst($f) . " must be a decimal number between $min and $max.";
        }
    }
    if ($data['backup_power'] !== 'Y') {
        $data['backup_power_type'] = null;
    }
    foreach (['download_speed_mbps' => 'Download speed', 'upload_speed_mbps' => 'Upload speed'] as $f => $label) {
        $raw = str_replace(',', '.', trim((string)($_POST[$f] ?? '')));
        if ($raw === '') {
            $data[$f] = null;
        } elseif (!is_numeric($raw) || (float)$raw < 0 || (float)$raw > 100000) {
            $errors[] = "$label must be a number of Mbps (e.g. 25 or 10.5).";
            $data[$f] = $raw;
        } else {
            $data[$f] = round((float)$raw, 2);
        }
    }

    // Signatures: a newly drawn pad replaces the stored one; ticking "remove" clears it;
    // otherwise the columns are left out of the upsert so the stored signature is kept.
    $existingStmt = $pdo->prepare('SELECT site_rep_signature, usaasa_rep_signature, backup_power_proof, speed_proof FROM site_acceptance_forms WHERE site_id = ?');
    $existingStmt->execute([$siteId]);
    $existing = $existingStmt->fetch() ?: [];
    $drawn = [];
    foreach ($signers as $who) {
        $dataUrl = (string)($_POST[$who . '_signature_data'] ?? '');
        if ($dataUrl !== '') {
            if (strlen($dataUrl) > 2 * 1024 * 1024) {
                $errors[] = 'Signature image is too large — please clear it and sign again.';
            } else {
                $drawn[$who] = $dataUrl;
            }
        }
    }

    $chosenUploads = array_filter(array_keys($proofs), fn($c) => ($_FILES[$c]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
    if ($errors) {
        foreach ($errors as $err) {
            flash('error', $err);
        }
        if ($chosenUploads) {
            flash('error', 'Nothing was saved, so please attach the proof image(s) again.');
        }
        $_SESSION['acceptance_form_old'] = $data + ['_drawn' => $drawn];
        redirect('/sites/acceptance_form.php?id=' . $siteId);
    }

    // Proof images: a newly attached file replaces the stored one; "remove" clears it; otherwise kept.
    $filesToDelete = [];
    $newlyStored = [];
    foreach ($proofs as $col => $meta) {
        $uploadError = null;
        $stored = isset($_FILES[$col]) ? store_acceptance_proof_upload($_FILES[$col], $uploadError) : null;
        if ($uploadError !== null) {
            foreach ($newlyStored as $f) {
                @unlink(ACCEPTANCE_STORAGE_DIR . $f);
            }
            flash('error', 'Could not attach the ' . $meta['label'] . ': ' . $uploadError);
            $_SESSION['acceptance_form_old'] = $data + ['_drawn' => $drawn];
            redirect('/sites/acceptance_form.php?id=' . $siteId);
        }
        if ($stored !== null) {
            $data[$col] = $stored;
            $newlyStored[] = $stored;
        } elseif (!empty($_POST[$col . '_clear'])) {
            $data[$col] = null;
        } else {
            continue;
        }
        if (!empty($existing[$col])) {
            $filesToDelete[] = $existing[$col];
        }
    }
    foreach ($signers as $who) {
        $col = $who . '_signature';
        if (isset($drawn[$who])) {
            $stored = store_signature_data_url($drawn[$who], ACCEPTANCE_STORAGE_DIR);
            if ($stored === null) {
                foreach ($newlyStored as $f) {
                    @unlink(ACCEPTANCE_STORAGE_DIR . $f);
                }
                flash('error', 'The ' . $signerLabels[$who] . ' signature could not be read — please clear it and sign again.');
                $_SESSION['acceptance_form_old'] = $data;
                redirect('/sites/acceptance_form.php?id=' . $siteId);
            }
            $data[$col] = $stored;
            $data[$who . '_signed_at'] = date('Y-m-d H:i:s');
        } elseif (!empty($_POST[$who . '_signature_clear'])) {
            $data[$col] = null;
            $data[$who . '_signed_at'] = null;
        } else {
            continue;
        }
        if (!empty($existing[$col])) {
            $filesToDelete[] = $existing[$col];
        }
    }

    $data['site_id'] = $siteId;
    $data['updated_by'] = (int)current_user()['id'];
    $cols = array_keys($data);
    $updates = implode(', ', array_map(fn($c) => "$c = VALUES($c)", array_diff($cols, ['site_id'])));
    $sql = 'INSERT INTO site_acceptance_forms (' . implode(', ', $cols) . ') VALUES ('
         . implode(', ', array_fill(0, count($cols), '?')) . ') ON DUPLICATE KEY UPDATE ' . $updates;
    $pdo->prepare($sql)->execute(array_values($data));

    foreach ($filesToDelete as $old) {
        if ($isOurFile($old)) {
            @unlink(ACCEPTANCE_STORAGE_DIR . $old);
        }
    }

    flash('success', 'Acceptance form saved.');
    // Non-blocking reminders so a partly completed form can still be saved.
    $after = $pdo->prepare('SELECT backup_power, backup_power_proof, download_speed_mbps, upload_speed_mbps, speed_proof FROM site_acceptance_forms WHERE site_id = ?');
    $after->execute([$siteId]);
    $now = $after->fetch();
    if ($now['backup_power'] === 'Y' && !$now['backup_power_proof']) {
        flash('warning', 'Backup power is marked "Yes" but no proof image is attached yet.');
    }
    if (($now['download_speed_mbps'] !== null || $now['upload_speed_mbps'] !== null) && !$now['speed_proof']) {
        flash('warning', 'A connection speed is recorded but no speed test proof image is attached yet.');
    }
    redirect('/sites/acceptance_form.php?id=' . $siteId);
}

$formStmt = $pdo->prepare(
    'SELECT f.*, u.full_name AS updated_by_name
     FROM site_acceptance_forms f LEFT JOIN users u ON u.id = f.updated_by
     WHERE f.site_id = ?'
);
$formStmt->execute([$siteId]);
$saved = $formStmt->fetch() ?: null;

// Values to show: re-posted data after a validation error > saved form > defaults from the site record.
$form = $_SESSION['acceptance_form_old'] ?? $saved ?? [
    'project_name'     => $site['project_name'],
    'site_name'        => $site['site_name'],
    'official_site_id' => $site['site_code'],
    'town_village'     => $site['location'],
];
$redrawn = $_SESSION['acceptance_form_old']['_drawn'] ?? []; // unsaved signatures to put back on the pads after a validation error
unset($_SESSION['acceptance_form_old']);
$v = fn(string $k) => e(isset($form[$k]) ? (string)$form[$k] : '');
$ro = $canEdit ? '' : 'disabled';

$settings = get_site_settings($pdo);
$pageTitle = 'Acceptance Form — ' . $site['site_name'];
include __DIR__ . '/../includes/header.php';
?>
<style>
    .af-sheet { background: #fff; max-width: 980px; }
    .af-head { display: flex; align-items: center; gap: 14px; border-bottom: 3px solid var(--orange); padding-bottom: 12px; margin-bottom: 16px; }
    .af-head img { height: 44px; width: auto; }
    .af-head h1 { margin: 0; font-size: 21px; }
    .af-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    .af-table th, .af-table td { border: 1px solid #9aa5ad; padding: 6px 8px; vertical-align: middle; font-size: 13.5px; text-align: left; }
    .af-table th { background: #f1f4f7; font-weight: 600; width: 22%; }
    .af-table .sec { background: var(--brand); color: #fff; font-size: 14px; text-transform: none; }
    .af-table .sec .num { display: inline-block; width: 22px; font-weight: 700; }
    .af-table input[type=text], .af-table input[type=date], .af-table select {
        width: 100%; box-sizing: border-box; border: 1px solid #d0d5d9; border-radius: 3px; padding: 5px 7px; font: inherit; background: #fff;
    }
    .af-choices { display: flex; flex-wrap: wrap; gap: 6px 18px; align-items: center; }
    .af-choices label { display: inline-flex; align-items: center; gap: 5px; font-weight: 400; margin: 0; white-space: nowrap; }
    .af-yn { display: inline-flex; gap: 12px; }
    .af-yn label { display: inline-flex; align-items: center; gap: 4px; font-weight: 400; margin: 0; }
    .af-assess { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0; }
    .af-assess > div { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 7px 8px; border-right: 1px solid #d0d5d9; border-bottom: 1px solid #d0d5d9; }
    .af-sign-line { height: 46px; border-bottom: 1px solid #1d2730; }
    .af-meta { font-size: 12px; color: #6b7a85; margin-top: 6px; }
    .af-proof-label { display: block; font-size: 12.5px; margin-bottom: 6px; }
    .af-proof img { display: block; max-width: 100%; max-height: 220px; border: 1px solid #d0d5d9; border-radius: 4px; }
    .af-proof-missing { font-size: 12.5px; color: #8c231d; font-style: italic; }
    .af-proof-tools { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 8px; }
    .af-proof-tools input[type=file] { max-width: 100%; font-size: 12.5px; }
    .af-inline { display: flex; align-items: center; gap: 8px; font-weight: 600; margin: 0; }
    .af-table .af-inline input[type=text] { width: 110px; flex: 0 0 auto; }
    @media (max-width: 760px) {
        .af-assess { grid-template-columns: 1fr; }
        .af-table th { width: 35%; }
    }
    @media print {
        .af-sheet { box-shadow: none; border: 0; padding: 0; }
        .page-header, .flash, .alert, .af-actions, .af-meta { display: none !important; }
        .af-table input[type=text], .af-table input[type=date], .af-table select {
            border: 0; padding: 0; appearance: none; -webkit-appearance: none; background: transparent; color: #000;
        }
        .af-table .sec { background: #e3e6e8 !important; color: #000 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .af-table th, .af-table td { font-size: 12px; padding: 5px 6px; }
        .af-sig-pad canvas { border: 0; border-bottom: 1px solid #1d2730; border-radius: 0; max-height: 70px; width: auto; }
        .af-proof img { max-height: 170px; }
        @page { size: A4; margin: 12mm; }
    }
</style>

<div class="page-header no-print">
    <div>
        <h1>Broadband Infrastructure Acceptance Form</h1>
        <p class="subtitle"><?= e($site['site_code']) ?> &middot; <?= e($site['site_name']) ?></p>
    </div>
    <div class="actions-row" style="margin:0;">
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print / save as PDF</button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/sites/list.php">Back to sites</a>
    </div>
</div>

<div class="card af-sheet">
<form method="post" action="<?= APP_BASE_URL ?>/sites/acceptance_form.php" enctype="multipart/form-data" id="acceptanceForm">
    <?= csrf_field() ?>
    <input type="hidden" name="site_id" value="<?= (int)$site['id'] ?>">

    <div class="af-head">
        <?php if (!empty($settings['logo_url'])): ?>
            <img src="<?= e($settings['logo_url']) ?>" alt="">
        <?php endif; ?>
        <h1>Broadband Infrastructure Acceptance Form</h1>
    </div>

    <table class="af-table">
        <tr>
            <th>Project Name</th>
            <td><input type="text" name="project_name" value="<?= $v('project_name') ?>" placeholder="Broadband Project (Name of the Municipality)" <?= $ro ?>></td>
        </tr>
    </table>

    <table class="af-table">
        <tr><th colspan="4" class="sec"><span class="num">1</span> Site Information</th></tr>
        <tr>
            <th>Site Name</th>
            <td colspan="3"><input type="text" name="site_name" value="<?= $v('site_name') ?>" <?= $ro ?>></td>
        </tr>
        <tr>
            <th>Site Type</th>
            <td colspan="3">
                <div class="af-choices">
                    <?php foreach ($siteTypes as $t): ?>
                        <label><input type="radio" name="site_type" value="<?= $t ?>" <?= ($form['site_type'] ?? '') === $t ? 'checked' : '' ?> <?= $ro ?>>
                            <?= $t === 'Other' ? 'Other (specify)' : $t ?></label>
                    <?php endforeach; ?>
                    <input type="text" name="site_type_other" id="siteTypeOther" value="<?= $v('site_type_other') ?>" placeholder="Specify other site type" style="max-width:240px;" <?= $ro ?>>
                </div>
            </td>
        </tr>
        <tr>
            <th>Official Site ID</th>
            <td><input type="text" name="official_site_id" value="<?= $v('official_site_id') ?>" <?= $ro ?>></td>
            <th>Province</th>
            <td>
                <select name="province" id="province" <?= $ro ?>>
                    <option value="">Select province…</option>
                    <?php foreach (sa_provinces() as $p): ?>
                        <option value="<?= e($p) ?>" <?= ($form['province'] ?? '') === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th>District Mun:</th>
            <td><select name="district_municipality" id="district_municipality" <?= $ro ?>></select></td>
            <th>Local Mun:</th>
            <td><select name="local_municipality" id="local_municipality" <?= $ro ?>></select></td>
        </tr>
        <tr>
            <th>Town &amp; Village</th>
            <td colspan="3"><input type="text" name="town_village" value="<?= $v('town_village') ?>" <?= $ro ?>></td>
        </tr>
        <tr>
            <th rowspan="2">GPS Coordinates</th>
            <th style="width:14%;">Latitude</th>
            <td colspan="2"><input type="text" inputmode="decimal" name="latitude" id="latitude" value="<?= $v('latitude') ?>" placeholder="e.g. -25.7461" <?= $ro ?>></td>
        </tr>
        <tr>
            <th>Longitude</th>
            <td colspan="2">
                <input type="text" inputmode="decimal" name="longitude" id="longitude" value="<?= $v('longitude') ?>" placeholder="e.g. 28.1881" <?= $ro ?>>
                <?php if ($canEdit): ?>
                    <button type="button" class="btn btn-secondary btn-sm no-print" id="useLocationBtn" style="margin-top:6px;">Use current location</button>
                    <span class="hint no-print" id="locationStatus"></span>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <table class="af-table">
        <tr><th colspan="2" class="sec"><span class="num">2</span> Installation Details</th></tr>
        <tr>
            <th>Service Provider</th>
            <td><input type="text" name="service_provider" value="<?= $v('service_provider') ?>" <?= $ro ?>></td>
        </tr>
        <tr>
            <th>Installing Company</th>
            <td><input type="text" name="installing_company" value="<?= $v('installing_company') ?>" <?= $ro ?>></td>
        </tr>
        <tr>
            <th>Installation Assessment</th>
            <td style="padding:0;">
                <div class="af-assess">
                    <?php foreach ($ynFields as $f => $label): ?>
                        <div>
                            <span><?= e($label) ?></span>
                            <span class="af-yn">
                                <label><input type="radio" name="<?= $f ?>" value="Y" <?= ($form[$f] ?? '') === 'Y' ? 'checked' : '' ?> <?= $ro ?>> Y</label>
                                <label><input type="radio" name="<?= $f ?>" value="N" <?= ($form[$f] ?? '') === 'N' ? 'checked' : '' ?> <?= $ro ?>> N</label>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </td>
        </tr>
    </table>

    <table class="af-table">
        <tr><th colspan="<?= count($wifiFields) * 2 ?>" class="sec"><span class="num">3</span> Wi-Fi Installation</th></tr>
        <tr>
            <?php foreach ($wifiFields as $f => $label): ?>
                <th><?= e($label) ?></th>
                <td>
                    <span class="af-yn">
                        <label><input type="radio" name="<?= $f ?>" value="Y" <?= ($form[$f] ?? '') === 'Y' ? 'checked' : '' ?> <?= $ro ?>> Y</label>
                        <label><input type="radio" name="<?= $f ?>" value="N" <?= ($form[$f] ?? '') === 'N' ? 'checked' : '' ?> <?= $ro ?>> N</label>
                    </span>
                </td>
            <?php endforeach; ?>
        </tr>
    </table>

    <?php
    // Renders the proof-image cell: saved thumbnail (links to full size), plus attach/replace/remove controls for editors.
    $proofCell = function (string $col) use ($saved, $site, $proofs, $canEdit) {
        $file = $saved[$col] ?? null;
        $url = APP_BASE_URL . '/sites/acceptance_file.php?id=' . (int)$site['id'] . '&type=' . $proofs[$col]['type'] . '&v=' . substr((string)$file, 6, 8);
        ob_start(); ?>
        <div class="af-proof">
            <?php if ($file): ?>
                <a href="<?= e($url) ?>" target="_blank" rel="noopener"><img src="<?= e($url) ?>" alt="<?= e(ucfirst($proofs[$col]['label'])) ?>"></a>
            <?php else: ?>
                <span class="af-proof-missing">No proof image attached</span>
            <?php endif; ?>
            <?php if ($canEdit): ?>
                <div class="af-proof-tools no-print">
                    <input type="file" name="<?= $col ?>" accept="image/jpeg,image/png" class="af-proof-input">
                    <span class="hint"><?= $file ? 'Choosing a new image replaces the current one. ' : '' ?>JPG or PNG, up to <?= ACCEPTANCE_PROOF_MAX_BYTES / 1024 / 1024 ?> MB.</span>
                    <?php if ($file): ?>
                        <label style="font-weight:400; display:inline-flex; align-items:center; gap:4px; margin:0;">
                            <input type="checkbox" name="<?= $col ?>_clear" value="1"> Remove image on save
                        </label>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php return ob_get_clean();
    };
    ?>
    <table class="af-table">
        <tr><th colspan="3" class="sec"><span class="num">4</span> Backup Power &amp; Connection Speed</th></tr>
        <tr>
            <th rowspan="2">Backup Power</th>
            <td colspan="2">
                <div class="af-choices">
                    <span>Is backup power installed?</span>
                    <span class="af-yn">
                        <label><input type="radio" name="backup_power" value="Y" <?= ($form['backup_power'] ?? '') === 'Y' ? 'checked' : '' ?> <?= $ro ?>> Y</label>
                        <label><input type="radio" name="backup_power" value="N" <?= ($form['backup_power'] ?? '') === 'N' ? 'checked' : '' ?> <?= $ro ?>> N</label>
                    </span>
                    <input type="text" name="backup_power_type" id="backupPowerType" value="<?= $v('backup_power_type') ?>"
                           placeholder="Type, e.g. UPS, battery, solar, generator" style="max-width:300px;" <?= $ro ?>>
                </div>
            </td>
        </tr>
        <tr>
            <td colspan="2"><strong class="af-proof-label">Proof (photo of backup power installation)</strong><?= $proofCell('backup_power_proof') ?></td>
        </tr>
        <tr>
            <th rowspan="2">Connection Speed</th>
            <td>
                <label class="af-inline">Download
                    <input type="text" inputmode="decimal" name="download_speed_mbps" value="<?= $v('download_speed_mbps') ?>" placeholder="0.00" <?= $ro ?>> Mbps</label>
            </td>
            <td>
                <label class="af-inline">Upload
                    <input type="text" inputmode="decimal" name="upload_speed_mbps" value="<?= $v('upload_speed_mbps') ?>" placeholder="0.00" <?= $ro ?>> Mbps</label>
            </td>
        </tr>
        <tr>
            <td colspan="2"><strong class="af-proof-label">Proof (speed test screenshot or photo)</strong><?= $proofCell('speed_proof') ?></td>
        </tr>
    </table>

    <table class="af-table">
        <tr><th colspan="3" class="sec"><span class="num">5</span> Site Signoff</th></tr>
        <tr>
            <th></th>
            <th style="width:39%;">
                Site Representative
                <span style="font-weight:400; display:flex; align-items:center; gap:6px; margin-top:4px;">Date:
                    <input type="date" name="site_rep_date" value="<?= $v('site_rep_date') ?>" <?= $ro ?>></span>
            </th>
            <th style="width:39%;">
                USAASA Representative
                <span style="font-weight:400; display:flex; align-items:center; gap:6px; margin-top:4px;">Date:
                    <input type="date" name="usaasa_rep_date" value="<?= $v('usaasa_rep_date') ?>" <?= $ro ?>></span>
            </th>
        </tr>
        <tr>
            <th>Full Name</th>
            <td><input type="text" name="site_rep_name" value="<?= $v('site_rep_name') ?>" <?= $ro ?>></td>
            <td><input type="text" name="usaasa_rep_name" value="<?= $v('usaasa_rep_name') ?>" <?= $ro ?>></td>
        </tr>
        <tr>
            <th>Designation</th>
            <td><input type="text" name="site_rep_designation" value="<?= $v('site_rep_designation') ?>" <?= $ro ?>></td>
            <td><input type="text" name="usaasa_rep_designation" value="<?= $v('usaasa_rep_designation') ?>" <?= $ro ?>></td>
        </tr>
        <tr>
            <th>Signature</th>
            <?php foreach ($signers as $who):
                $sigFile = $saved[$who . '_signature'] ?? null;
                $whoParam = $who === 'site_rep' ? 'site' : 'usaasa'; ?>
                <td>
                    <?php if ($sigFile): ?>
                        <div class="af-sig-existing">
                            <img src="<?= APP_BASE_URL ?>/sites/acceptance_file.php?id=<?= (int)$site['id'] ?>&amp;type=<?= $whoParam ?>_sig&amp;v=<?= e(substr($sigFile, 10, 8)) ?>"
                                 alt="<?= e($signerLabels[$who]) ?> signature">
                            <div class="af-sig-when">Signed on screen <?= format_datetime($saved[$who . '_signed_at']) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($canEdit): ?>
                        <div class="af-sig-pad" data-who="<?= $who ?>" <?= $sigFile ? 'style="display:none;"' : '' ?>>
                            <canvas width="600" height="180"></canvas>
                            <input type="hidden" name="<?= $who ?>_signature_data" value="">
                            <div class="af-sig-tools no-print">
                                <button type="button" class="btn btn-secondary btn-sm" data-action="clear">Clear</button>
                                <span class="hint"><?= $sigFile ? 'The new signature replaces the current one when you save.' : 'Sign with a finger, stylus or mouse.' ?></span>
                            </div>
                        </div>
                        <?php if ($sigFile): ?>
                            <div class="af-sig-tools no-print">
                                <button type="button" class="btn btn-secondary btn-sm" data-action="resign" data-who="<?= $who ?>">Sign again</button>
                                <label style="font-weight:400; display:inline-flex; align-items:center; gap:4px; margin:0;">
                                    <input type="checkbox" name="<?= $who ?>_signature_clear" value="1"> Remove signature on save
                                </label>
                            </div>
                        <?php endif; ?>
                    <?php elseif (!$sigFile): ?>
                        <div class="af-sign-line"></div>
                    <?php endif; ?>
                </td>
            <?php endforeach; ?>
        </tr>
        <tr>
            <th>Contact No.</th>
            <td><input type="text" inputmode="tel" name="site_rep_contact" value="<?= $v('site_rep_contact') ?>" <?= $ro ?>></td>
            <td><input type="text" inputmode="tel" name="usaasa_rep_contact" value="<?= $v('usaasa_rep_contact') ?>" <?= $ro ?>></td>
        </tr>
    </table>

    <p class="af-meta">
        Sign on screen above, or leave a pad blank and sign the printed form by hand.
        <?php if ($saved): ?>
            Last saved <?= format_datetime($saved['updated_at']) ?><?= $saved['updated_by_name'] ? ' by ' . e($saved['updated_by_name']) : '' ?>.
        <?php else: ?>
            Not yet saved — fields are pre-filled from the site record.
        <?php endif; ?>
    </p>

    <div class="actions-row af-actions no-print">
        <?php if ($canEdit): ?>
            <button type="submit" class="btn">Save acceptance form</button>
        <?php endif; ?>
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print / save as PDF</button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/sites/list.php">Back to sites</a>
    </div>
</form>
</div>

<script>
var SA_MUNICIPAL_STRUCTURE = <?= json_encode(sa_municipal_structure(), JSON_UNESCAPED_UNICODE) ?>;
(function () {
    var provinceSelect = document.getElementById('province');
    var districtSelect = document.getElementById('district_municipality');
    var localSelect = document.getElementById('local_municipality');

    function districtsFor(province) { return SA_MUNICIPAL_STRUCTURE[province] || []; }

    function fill(select, items, selected, emptyText, readyText, labelFn) {
        select.innerHTML = '';
        var ph = document.createElement('option');
        ph.value = '';
        ph.textContent = items.length ? readyText : emptyText;
        select.appendChild(ph);
        var found = false;
        items.forEach(function (item) {
            var o = document.createElement('option');
            o.value = typeof item === 'string' ? item : item.district;
            o.textContent = labelFn ? labelFn(item) : o.value;
            if (o.value === selected) { o.selected = true; found = true; }
            select.appendChild(o);
        });
        if (selected && !found) {
            var fb = document.createElement('option');
            fb.value = selected; fb.textContent = selected; fb.selected = true;
            select.appendChild(fb);
        }
    }

    function populateDistricts(province, selected) {
        fill(districtSelect, districtsFor(province), selected, 'Select province first…', 'Select district…', function (row) {
            return row.type === 'metro' ? row.district + ' (Metropolitan Municipality)' : row.district;
        });
    }
    function populateLocals(province, district, selected) {
        var row = districtsFor(province).filter(function (r) { return r.district === district; })[0];
        fill(localSelect, row ? row.locals : [], selected, 'Select district first…', 'Select local municipality…');
    }

    provinceSelect.addEventListener('change', function () {
        populateDistricts(provinceSelect.value, '');
        populateLocals(provinceSelect.value, '', '');
    });
    districtSelect.addEventListener('change', function () {
        populateLocals(provinceSelect.value, districtSelect.value, '');
    });
    populateDistricts(<?= json_encode($form['province'] ?? '') ?>, <?= json_encode($form['district_municipality'] ?? '') ?>);
    populateLocals(<?= json_encode($form['province'] ?? '') ?>, <?= json_encode($form['district_municipality'] ?? '') ?>, <?= json_encode($form['local_municipality'] ?? '') ?>);

    // "Other (specify)" text box is only relevant when Other is picked.
    var other = document.getElementById('siteTypeOther');
    function syncOther() {
        var checked = document.querySelector('input[name="site_type"]:checked');
        var isOther = checked && checked.value === 'Other';
        other.style.display = isOther ? '' : 'none';
    }
    document.querySelectorAll('input[name="site_type"]').forEach(function (r) { r.addEventListener('change', syncOther); });
    syncOther();

    // ---- Signature pads (one per representative) ----
    var redrawn = <?= json_encode($redrawn) ?>;
    document.querySelectorAll('.af-sig-pad').forEach(function (pad) {
        var canvas = pad.querySelector('canvas');
        var hidden = pad.querySelector('input[type=hidden]');
        var ctx = canvas.getContext('2d');
        var drawing = false, last = null;

        function setup() { ctx.strokeStyle = '#1d2730'; ctx.lineWidth = 2.5; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; }
        setup();

        function pos(e) {
            var rect = canvas.getBoundingClientRect();
            var t = e.touches && e.touches.length ? e.touches[0] : e;
            return [(t.clientX - rect.left) * canvas.width / rect.width, (t.clientY - rect.top) * canvas.height / rect.height];
        }
        function start(e) { drawing = true; last = pos(e); e.preventDefault(); }
        function move(e) {
            if (!drawing) return;
            var p = pos(e);
            ctx.beginPath(); ctx.moveTo(last[0], last[1]); ctx.lineTo(p[0], p[1]); ctx.stroke();
            last = p;
            e.preventDefault();
        }
        function end() {
            if (!drawing) return;
            drawing = false;
            hidden.value = canvas.toDataURL('image/png');
        }
        canvas.addEventListener('mousedown', start);
        canvas.addEventListener('mousemove', move);
        window.addEventListener('mouseup', end);
        canvas.addEventListener('touchstart', start, { passive: false });
        canvas.addEventListener('touchmove', move, { passive: false });
        canvas.addEventListener('touchend', end);

        pad.querySelector('[data-action="clear"]').addEventListener('click', function () {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            hidden.value = '';
        });

        // Put back a signature that was drawn but not saved because of a validation error.
        var who = pad.getAttribute('data-who');
        if (redrawn[who] && redrawn[who].indexOf('data:image/png;base64,') === 0) {
            var img = new Image();
            img.onload = function () { ctx.drawImage(img, 0, 0, canvas.width, canvas.height); hidden.value = redrawn[who]; };
            img.src = redrawn[who];
            pad.style.display = '';
        }
    });

    document.querySelectorAll('[data-action="resign"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var pad = document.querySelector('.af-sig-pad[data-who="' + btn.getAttribute('data-who') + '"]');
            pad.style.display = '';
            btn.style.display = 'none';
        });
    });

    // Backup power type only applies when backup power is "Y".
    var powerType = document.getElementById('backupPowerType');
    function syncPower() {
        var c = document.querySelector('input[name="backup_power"]:checked');
        powerType.style.display = c && c.value === 'Y' ? '' : 'none';
    }
    document.querySelectorAll('input[name="backup_power"]').forEach(function (r) { r.addEventListener('change', syncPower); });
    syncPower();

    // Catch oversized proof images before upload (an over-limit POST would otherwise lose the whole form).
    var maxProof = <?= (int)ACCEPTANCE_PROOF_MAX_BYTES ?>;
    document.querySelectorAll('.af-proof-input').forEach(function (input) {
        input.addEventListener('change', function () {
            var f = input.files && input.files[0];
            if (f && f.size > maxProof) {
                alert('That image is larger than ' + Math.round(maxProof / 1048576) + ' MB — please choose a smaller one.');
                input.value = '';
            }
        });
    });

    var locBtn = document.getElementById('useLocationBtn');
    if (locBtn) {
        locBtn.addEventListener('click', function () {
            var status = document.getElementById('locationStatus');
            if (!navigator.geolocation) { status.textContent = 'Location is not supported by this browser.'; return; }
            status.textContent = 'Getting location…';
            navigator.geolocation.getCurrentPosition(function (pos) {
                document.getElementById('latitude').value = pos.coords.latitude.toFixed(6);
                document.getElementById('longitude').value = pos.coords.longitude.toFixed(6);
                status.textContent = 'Location captured (±' + Math.round(pos.coords.accuracy) + ' m).';
            }, function (err) {
                status.textContent = 'Could not get location: ' + err.message;
            }, { enableHighAccuracy: true, timeout: 15000 });
        });
    }
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
