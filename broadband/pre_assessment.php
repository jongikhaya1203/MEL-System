<?php
/**
 * Broadband Site Pre-Assessment Form — one per rollout site (broadband_rollout_sites).
 * GET ?id=<rollout site id> shows it; POST saves (roles in $editRoles). Prints as a paper form.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$editRoles = ['admin', 'manager', 'planner', 'field_worker', 'back_office'];
$canEdit = in_array(current_user()['role'], $editRoles, true);
$canManageRegister = in_array(current_user()['role'], ['admin', 'manager', 'planner'], true);

$rolloutId = (int)($_POST['rollout_site_id'] ?? $_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT r.*, p.project_name FROM broadband_rollout_sites r JOIN projects p ON p.id = r.project_id WHERE r.id = ?');
$stmt->execute([$rolloutId]);
$site = $stmt->fetch();
if (!$site) {
    flash('error', 'Rollout site not found.');
    redirect('/broadband/pre_assessment_list.php');
}

$readiness = [
    'mains_power'           => 'Mains (grid) power available',
    'power_stable'          => 'Power supply stable',
    'backup_power'          => 'Backup power available',
    'secure_room'           => 'Secure room for equipment',
    'mounting_location'     => 'Suitable mounting location (roof / wall / mast)',
    'line_of_sight'         => 'Clear line of sight / no obstructions',
    'existing_connectivity' => 'Existing internet connectivity on site',
    'site_accessible'       => 'Site accessible for installation team',
    'permission_granted'    => 'Permission / wayleave granted',
];
$technologies = ['VSAT', 'Terrestrial', 'Fibre', 'Fixed Wireless', 'Other'];
$textFields = ['contact_name', 'contact_designation', 'contact_number', 'contact_email', 'recommended_tech_other', 'mobile_signal',
               'assessor_name', 'assessor_designation', 'assessor_contact', 'site_rep_name', 'site_rep_designation', 'site_rep_contact'];
$longTextFields = ['additional_requirements', 'comments'];
$dateFields = ['assessment_date', 'assessor_date', 'site_rep_date'];
$signers = ['assessor' => 'Assessor', 'site_rep' => 'Site Representative'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) {
        http_response_code(403);
        die('You do not have permission to edit pre-assessment forms.');
    }
    csrf_verify();

    $data = [];
    foreach ($textFields as $f) {
        $val = trim((string)($_POST[$f] ?? ''));
        $data[$f] = $val === '' ? null : mb_substr($val, 0, 150);
    }
    foreach ($longTextFields as $f) {
        $val = trim((string)($_POST[$f] ?? ''));
        $data[$f] = $val === '' ? null : mb_substr($val, 0, 5000);
    }
    foreach ($dateFields as $f) {
        $data[$f] = parse_import_date((string)($_POST[$f] ?? ''));
    }
    foreach (array_merge(array_keys($readiness), ['suitable_for_rollout']) as $f) {
        $val = $_POST[$f] ?? '';
        $data[$f] = in_array($val, ['Y', 'N'], true) ? $val : null;
    }
    $tech = $_POST['recommended_technology'] ?? '';
    $data['recommended_technology'] = in_array($tech, $technologies, true) ? $tech : null;
    if ($data['recommended_technology'] !== 'Other') {
        $data['recommended_tech_other'] = null;
    }

    $errors = [];
    $users = trim((string)($_POST['estimated_users'] ?? ''));
    if ($users === '') {
        $data['estimated_users'] = null;
    } elseif (!ctype_digit($users) || (int)$users > 10000000) {
        $errors[] = 'Estimated users must be a whole number.';
        $data['estimated_users'] = $users;
    } else {
        $data['estimated_users'] = (int)$users;
    }
    if ($data['contact_email'] !== null && !filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Contact email address is not valid.';
    }

    $drawn = [];
    foreach (array_keys($signers) as $who) {
        $dataUrl = (string)($_POST[$who . '_signature_data'] ?? '');
        if ($dataUrl !== '') {
            if (strlen($dataUrl) > 2 * 1024 * 1024) {
                $errors[] = 'Signature image is too large — please clear it and sign again.';
            } else {
                $drawn[$who] = $dataUrl;
            }
        }
    }

    $back = '/broadband/pre_assessment.php?id=' . $rolloutId;
    if ($errors) {
        foreach ($errors as $err) {
            flash('error', $err);
        }
        if (($_FILES['site_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            flash('error', 'Nothing was saved, so please attach the site photo again.');
        }
        $_SESSION['pa_form_old'] = $data + ['_drawn' => $drawn];
        redirect($back);
    }

    $existingStmt = $pdo->prepare('SELECT site_photo, assessor_signature, site_rep_signature FROM site_pre_assessments WHERE rollout_site_id = ?');
    $existingStmt->execute([$rolloutId]);
    $existing = $existingStmt->fetch() ?: [];
    $filesToDelete = [];
    $newlyStored = [];
    $fail = function (string $msg) use (&$newlyStored, $data, $drawn, $back) {
        foreach ($newlyStored as $f) {
            @unlink(ACCEPTANCE_STORAGE_DIR . $f);
        }
        flash('error', $msg);
        $_SESSION['pa_form_old'] = $data + ['_drawn' => $drawn];
        redirect($back);
    };

    // Site photo: new upload replaces; "remove" clears; otherwise kept.
    $uploadError = null;
    $photo = isset($_FILES['site_photo']) ? store_acceptance_proof_upload($_FILES['site_photo'], $uploadError) : null;
    if ($uploadError !== null) {
        $fail('Could not attach the site photo: ' . $uploadError);
    }
    if ($photo !== null || !empty($_POST['site_photo_clear'])) {
        $data['site_photo'] = $photo;
        if ($photo) {
            $newlyStored[] = $photo;
        }
        if (!empty($existing['site_photo'])) {
            $filesToDelete[] = $existing['site_photo'];
        }
    }

    foreach ($signers as $who => $label) {
        $col = $who . '_signature';
        if (isset($drawn[$who])) {
            $stored = store_signature_data_url($drawn[$who], ACCEPTANCE_STORAGE_DIR);
            if ($stored === null) {
                $fail("The $label signature could not be read — please clear it and sign again.");
            }
            $newlyStored[] = $stored;
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

    $data['rollout_site_id'] = $rolloutId;
    $data['updated_by'] = (int)current_user()['id'];
    $cols = array_keys($data);
    $updates = implode(', ', array_map(fn($c) => "$c = VALUES($c)", array_diff($cols, ['rollout_site_id'])));
    $pdo->prepare('INSERT INTO site_pre_assessments (' . implode(', ', $cols) . ') VALUES ('
        . implode(', ', array_fill(0, count($cols), '?')) . ') ON DUPLICATE KEY UPDATE ' . $updates)
        ->execute(array_values($data));

    // A recorded recommendation moves a site out of "Awaiting Pre-assessment".
    if ($data['suitable_for_rollout'] !== null && $site['status'] === 'Awaiting Pre-assessment') {
        $pdo->prepare("UPDATE broadband_rollout_sites SET status = 'Pre-assessed' WHERE id = ?")->execute([$rolloutId]);
    }

    foreach ($filesToDelete as $old) {
        if (is_broadband_upload_filename($old)) {
            @unlink(ACCEPTANCE_STORAGE_DIR . $old);
        }
    }
    flash('success', 'Pre-assessment saved.');
    redirect($back);
}

$formStmt = $pdo->prepare(
    'SELECT pa.*, u.full_name AS updated_by_name FROM site_pre_assessments pa
     LEFT JOIN users u ON u.id = pa.updated_by WHERE pa.rollout_site_id = ?'
);
$formStmt->execute([$rolloutId]);
$saved = $formStmt->fetch() ?: null;

$form = $_SESSION['pa_form_old'] ?? $saved ?? ['assessment_date' => date('Y-m-d')];
$redrawn = $_SESSION['pa_form_old']['_drawn'] ?? [];
unset($_SESSION['pa_form_old']);
$v = fn(string $k) => e(isset($form[$k]) ? (string)$form[$k] : '');
$ro = $canEdit ? '' : 'disabled';
$fileUrl = fn(string $type, ?string $file) => APP_BASE_URL . '/broadband/pa_file.php?id=' . $rolloutId . '&type=' . $type . '&v=' . substr((string)$file, -12, 8);
$yn = function (string $name) use ($form, $ro): string {
    $out = '<span class="af-yn">';
    foreach (['Y', 'N'] as $opt) {
        $out .= '<label><input type="radio" name="' . $name . '" value="' . $opt . '" ' . (($form[$name] ?? '') === $opt ? 'checked' : '') . " $ro> $opt</label>";
    }
    return $out . '</span>';
};

$settings = get_site_settings($pdo);
$pageTitle = 'Pre-Assessment — ' . $site['site_name'];
include __DIR__ . '/../includes/header.php';
?>
<style>
    .af-sheet { background: #fff; max-width: 980px; }
    .af-head { display: flex; align-items: center; gap: 14px; border-bottom: 3px solid var(--orange); padding-bottom: 12px; margin-bottom: 16px; }
    .af-head img { height: 44px; width: auto; }
    .af-head h1 { margin: 0; font-size: 21px; }
    .af-head .ref { margin-left: auto; font-family: monospace; color: #5b6b78; font-size: 13px; }
    .af-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    .af-table th, .af-table td { border: 1px solid #9aa5ad; padding: 6px 8px; vertical-align: middle; font-size: 13.5px; text-align: left; }
    .af-table th { background: #f1f4f7; font-weight: 600; width: 24%; }
    .af-table .sec { background: var(--brand); color: #fff; font-size: 14px; }
    .af-table .sec .num { display: inline-block; width: 22px; font-weight: 700; }
    .af-table input[type=text], .af-table input[type=date], .af-table input[type=email], .af-table textarea {
        width: 100%; box-sizing: border-box; border: 1px solid #d0d5d9; border-radius: 3px; padding: 5px 7px; font: inherit; background: #fff;
    }
    .af-choices { display: flex; flex-wrap: wrap; gap: 6px 18px; align-items: center; }
    .af-choices label, .af-yn label { display: inline-flex; align-items: center; gap: 5px; font-weight: 400; margin: 0; white-space: nowrap; }
    .af-yn { display: inline-flex; gap: 12px; }
    .af-assess { display: grid; grid-template-columns: repeat(2, 1fr); }
    .af-assess > div { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 7px 8px; border-right: 1px solid #d0d5d9; border-bottom: 1px solid #d0d5d9; }
    .af-sign-line { height: 46px; border-bottom: 1px solid #1d2730; }
    .bb-sig-pad canvas { display: block; width: 100%; max-width: 420px; aspect-ratio: 600 / 180; height: auto;
        border: 1px dashed #9aa5ad; border-radius: 4px; background: #fff; touch-action: none; cursor: crosshair; }
    .af-tools { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 6px; }
    .af-sig-existing img { display: block; max-width: 100%; max-height: 80px; }
    .af-small { font-size: 11.5px; color: #6b7a85; }
    .af-photo img { display: block; max-width: 100%; max-height: 260px; border: 1px solid #d0d5d9; border-radius: 4px; }
    .af-missing { font-size: 12.5px; color: #6b7a85; font-style: italic; }
    .af-meta { font-size: 12px; color: #6b7a85; margin-top: 6px; }
    @media (max-width: 760px) { .af-assess { grid-template-columns: 1fr; } .af-table th { width: 35%; } }
    @media print {
        .af-sheet { box-shadow: none; border: 0; padding: 0; }
        .page-header, .alert, .af-actions, .af-meta { display: none !important; }
        .af-table input[type=text], .af-table input[type=date], .af-table input[type=email], .af-table textarea {
            border: 0; padding: 0; background: transparent; color: #000; resize: none;
        }
        .af-table .sec { background: #e3e6e8 !important; color: #000 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .af-table th, .af-table td { font-size: 12px; padding: 5px 6px; }
        .bb-sig-pad canvas { border: 0; border-bottom: 1px solid #1d2730; border-radius: 0; max-height: 70px; width: auto; }
        .af-photo img { max-height: 180px; }
        @page { size: A4; margin: 12mm; }
    }
</style>

<div class="page-header no-print">
    <div>
        <h1>Broadband Site Pre-Assessment Form</h1>
        <p class="subtitle"><?= e($site['reference_code']) ?> &middot; <?= e($site['site_name']) ?> &middot; <?= rollout_status_badge($site['status']) ?></p>
    </div>
    <div class="actions-row" style="margin:0;">
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print / save as PDF</button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/broadband/pre_assessment_list.php">Back to pre-assessments</a>
    </div>
</div>

<div class="card af-sheet">
<form method="post" action="<?= APP_BASE_URL ?>/broadband/pre_assessment.php" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="rollout_site_id" value="<?= (int)$rolloutId ?>">

    <div class="af-head">
        <?php if (!empty($settings['logo_url'])): ?><img src="<?= e($settings['logo_url']) ?>" alt=""><?php endif; ?>
        <h1>Broadband Site Pre-Assessment Form</h1>
        <span class="ref"><?= e($site['reference_code']) ?></span>
    </div>

    <table class="af-table">
        <tr><th colspan="4" class="sec"><span class="num">1</span> Site Information</th></tr>
        <tr><th>Project Name</th><td colspan="3"><?= e($site['project_name']) ?></td></tr>
        <tr><th>Site Name</th><td colspan="3"><?= e($site['site_name']) ?></td></tr>
        <tr>
            <th>Site Type</th><td><?= e($site['site_type'] === 'Other' ? 'Other: ' . ($site['site_type_other'] ?? '') : ($site['site_type'] ?? '—')) ?></td>
            <th>Official Site ID</th><td><?= e($site['official_site_id'] ?? '—') ?></td>
        </tr>
        <tr>
            <th>Province</th><td><?= e($site['province'] ?? '—') ?></td>
            <th>District Mun:</th><td><?= e($site['district_municipality'] ?? '—') ?></td>
        </tr>
        <tr>
            <th>Local Mun:</th><td><?= e($site['local_municipality'] ?? '—') ?></td>
            <th>Town &amp; Village</th><td><?= e($site['town_village'] ?? '—') ?></td>
        </tr>
        <tr>
            <th>GPS Coordinates</th><td><?= $site['latitude'] ? e($site['latitude'] . ', ' . $site['longitude']) : '—' ?></td>
            <th>Assessment Date</th><td><input type="date" name="assessment_date" value="<?= $v('assessment_date') ?>" <?= $ro ?>></td>
        </tr>
        <?php if (!empty($site['contact_details'])): ?>
            <tr><th>Contact Details (register)</th><td colspan="3"><?= e($site['contact_details']) ?></td></tr>
        <?php endif; ?>
        <?php if ($canManageRegister): ?>
            <tr class="no-print"><td colspan="4" class="af-small">Site details come from the rollout register &mdash;
                <a href="<?= APP_BASE_URL ?>/broadband/rollout_form.php?id=<?= (int)$rolloutId ?>">edit them there</a>.</td></tr>
        <?php endif; ?>
    </table>

    <table class="af-table">
        <tr><th colspan="4" class="sec"><span class="num">2</span> Site Contact Person</th></tr>
        <tr>
            <th>Full Name</th><td><input type="text" name="contact_name" value="<?= $v('contact_name') ?>" <?= $ro ?>></td>
            <th>Designation</th><td><input type="text" name="contact_designation" value="<?= $v('contact_designation') ?>" <?= $ro ?>></td>
        </tr>
        <tr>
            <th>Contact No.</th><td><input type="text" inputmode="tel" name="contact_number" value="<?= $v('contact_number') ?>" <?= $ro ?>></td>
            <th>Email</th><td><input type="email" name="contact_email" value="<?= $v('contact_email') ?>" <?= $ro ?>></td>
        </tr>
    </table>

    <table class="af-table">
        <tr><th class="sec"><span class="num">3</span> Site Readiness</th></tr>
        <tr><td style="padding:0;">
            <div class="af-assess">
                <?php foreach ($readiness as $f => $label): ?>
                    <div><span><?= e($label) ?></span><?= $yn($f) ?></div>
                <?php endforeach; ?>
            </div>
        </td></tr>
    </table>

    <table class="af-table">
        <tr><th colspan="2" class="sec"><span class="num">4</span> Connectivity Requirements</th></tr>
        <tr>
            <th>Recommended Technology</th>
            <td>
                <div class="af-choices">
                    <?php foreach ($technologies as $t): ?>
                        <label><input type="radio" name="recommended_technology" value="<?= $t ?>" <?= ($form['recommended_technology'] ?? '') === $t ? 'checked' : '' ?> <?= $ro ?>>
                            <?= $t === 'Other' ? 'Other (specify)' : $t ?></label>
                    <?php endforeach; ?>
                    <input type="text" id="techOther" name="recommended_tech_other" value="<?= $v('recommended_tech_other') ?>" placeholder="Specify" style="max-width:220px;" <?= $ro ?>>
                </div>
            </td>
        </tr>
        <tr>
            <th>Mobile Network Signal</th>
            <td><input type="text" name="mobile_signal" value="<?= $v('mobile_signal') ?>" placeholder="e.g. Vodacom 4G, 2 bars / none" <?= $ro ?>></td>
        </tr>
        <tr>
            <th>Estimated Number of Users</th>
            <td><input type="text" inputmode="numeric" name="estimated_users" value="<?= $v('estimated_users') ?>" placeholder="Learners, staff, patients or public users" <?= $ro ?>></td>
        </tr>
        <tr>
            <th>Additional Requirements</th>
            <td><textarea name="additional_requirements" rows="3" placeholder="e.g. mast, trenching, electrical work, UPS, burglar bars" <?= $ro ?>><?= $v('additional_requirements') ?></textarea></td>
        </tr>
        <tr>
            <th>Site Photo</th>
            <td>
                <div class="af-photo">
                    <?php if (!empty($saved['site_photo'])): $u = $fileUrl('photo', $saved['site_photo']); ?>
                        <a href="<?= e($u) ?>" target="_blank" rel="noopener"><img src="<?= e($u) ?>" alt="Site photo"></a>
                    <?php else: ?>
                        <span class="af-missing">No site photo attached</span>
                    <?php endif; ?>
                    <?php if ($canEdit): ?>
                        <div class="af-tools no-print">
                            <input type="file" name="site_photo" accept="image/jpeg,image/png" class="bb-image-input">
                            <span class="hint">JPG or PNG, up to <?= ACCEPTANCE_PROOF_MAX_BYTES / 1024 / 1024 ?> MB<?= !empty($saved['site_photo']) ? ' — replaces the current photo' : '' ?>.</span>
                            <?php if (!empty($saved['site_photo'])): ?>
                                <label style="font-weight:400; display:inline-flex; gap:4px; align-items:center; margin:0;"><input type="checkbox" name="site_photo_clear" value="1"> Remove photo on save</label>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
    </table>

    <table class="af-table">
        <tr><th colspan="2" class="sec"><span class="num">5</span> Recommendation</th></tr>
        <tr><th>Site suitable for rollout?</th><td><?= $yn('suitable_for_rollout') ?></td></tr>
        <tr><th>Comments</th><td><textarea name="comments" rows="3" <?= $ro ?>><?= $v('comments') ?></textarea></td></tr>
    </table>

    <table class="af-table">
        <tr><th colspan="3" class="sec"><span class="num">6</span> Sign-off</th></tr>
        <tr>
            <th></th>
            <?php foreach ($signers as $who => $label): ?>
                <th style="width:38%;"><?= $label ?>
                    <span style="font-weight:400; display:flex; align-items:center; gap:6px; margin-top:4px;">Date:
                        <input type="date" name="<?= $who ?>_date" value="<?= $v($who . '_date') ?>" <?= $ro ?>></span>
                </th>
            <?php endforeach; ?>
        </tr>
        <tr>
            <th>Full Name</th>
            <?php foreach ($signers as $who => $label): ?><td><input type="text" name="<?= $who ?>_name" value="<?= $v($who . '_name') ?>" <?= $ro ?>></td><?php endforeach; ?>
        </tr>
        <tr>
            <th>Designation</th>
            <?php foreach ($signers as $who => $label): ?><td><input type="text" name="<?= $who ?>_designation" value="<?= $v($who . '_designation') ?>" <?= $ro ?>></td><?php endforeach; ?>
        </tr>
        <tr>
            <th>Signature</th>
            <?php foreach ($signers as $who => $label): $sig = $saved[$who . '_signature'] ?? null; ?>
                <td>
                    <?php if ($sig): ?>
                        <div class="af-sig-existing">
                            <img src="<?= e($fileUrl($who . '_sig', $sig)) ?>" alt="<?= e($label) ?> signature">
                            <div class="af-small">Signed on screen <?= format_datetime($saved[$who . '_signed_at']) ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($canEdit): ?>
                        <div class="bb-sig-pad" data-who="<?= $who ?>" <?= $sig ? 'style="display:none;"' : '' ?>>
                            <canvas width="600" height="180"></canvas>
                            <input type="hidden" name="<?= $who ?>_signature_data" value="">
                            <div class="af-tools no-print">
                                <button type="button" class="btn btn-secondary btn-sm" data-action="clear">Clear</button>
                                <span class="hint"><?= $sig ? 'Replaces the current signature when you save.' : 'Sign with a finger, stylus or mouse.' ?></span>
                            </div>
                        </div>
                        <?php if ($sig): ?>
                            <div class="af-tools no-print">
                                <button type="button" class="btn btn-secondary btn-sm" data-action="resign" data-who="<?= $who ?>">Sign again</button>
                                <label style="font-weight:400; display:inline-flex; gap:4px; align-items:center; margin:0;"><input type="checkbox" name="<?= $who ?>_signature_clear" value="1"> Remove signature on save</label>
                            </div>
                        <?php endif; ?>
                    <?php elseif (!$sig): ?>
                        <div class="af-sign-line"></div>
                    <?php endif; ?>
                </td>
            <?php endforeach; ?>
        </tr>
        <tr>
            <th>Contact No.</th>
            <?php foreach ($signers as $who => $label): ?><td><input type="text" inputmode="tel" name="<?= $who ?>_contact" value="<?= $v($who . '_contact') ?>" <?= $ro ?>></td><?php endforeach; ?>
        </tr>
    </table>

    <p class="af-meta">
        Sign on screen above, or leave a pad blank and sign the printed form by hand.
        <?php if ($saved): ?>
            Last saved <?= format_datetime($saved['updated_at']) ?><?= $saved['updated_by_name'] ? ' by ' . e($saved['updated_by_name']) : '' ?>.
        <?php else: ?>
            Not yet saved.
        <?php endif; ?>
    </p>

    <div class="actions-row af-actions no-print">
        <?php if ($canEdit): ?><button type="submit" class="btn">Save pre-assessment</button><?php endif; ?>
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print / save as PDF</button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/broadband/pre_assessment_list.php">Back to pre-assessments</a>
    </div>
</form>
</div>

<script src="<?= APP_BASE_URL ?>/assets/js/broadband_forms.js"></script>
<script>
BroadbandForms.toggleOnRadio('recommended_technology', 'Other', 'techOther');
BroadbandForms.signaturePads(<?= json_encode($redrawn) ?>);
BroadbandForms.imageSizeGuard('.bb-image-input', <?= (int)ACCEPTANCE_PROOF_MAX_BYTES ?>);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
