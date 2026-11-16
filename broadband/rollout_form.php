<?php
/** Register / edit a new broadband site awaiting rollout. GET ?id= to edit; POST saves. */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'manager', 'planner']);

$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$editing = null;
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM broadband_rollout_sites WHERE id = ?');
    $stmt->execute([$id]);
    $editing = $stmt->fetch() ?: null;
    if (!$editing) {
        flash('error', 'Site not found.');
        redirect('/broadband/rollout_list.php');
    }
}

$textFields = ['site_name', 'site_type_other', 'official_site_id', 'province', 'district_municipality',
               'local_municipality', 'town_village', 'latitude', 'longitude', 'contact_details', 'service_provider'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $data = [];
    foreach ($textFields as $f) {
        $v = trim((string)($_POST[$f] ?? ''));
        $data[$f] = $v === '' ? null : mb_substr($v, 0, 255);
    }
    $data['project_id'] = (int)($_POST['project_id'] ?? 0);
    $type = $_POST['site_type'] ?? '';
    $data['site_type'] = in_array($type, broadband_site_types(), true) ? $type : null;
    if ($data['site_type'] !== 'Other') {
        $data['site_type_other'] = null;
    }
    $data['planned_rollout_date'] = parse_import_date((string)($_POST['planned_rollout_date'] ?? ''));
    $st = $_POST['status'] ?? 'Awaiting Pre-assessment';
    $data['status'] = in_array($st, rollout_statuses(), true) ? $st : 'Awaiting Pre-assessment';
    $notes = trim((string)($_POST['notes'] ?? ''));
    $data['notes'] = $notes === '' ? null : $notes;

    $errors = [];
    if ($data['site_name'] === null) {
        $errors[] = 'Site name is required.';
    }
    $projOk = $pdo->prepare('SELECT 1 FROM projects WHERE id = ?');
    $projOk->execute([$data['project_id']]);
    if (!$projOk->fetchColumn()) {
        $errors[] = 'Please choose a project.';
    }
    if ($data['province'] !== null && !in_array($data['province'], sa_provinces(), true)) {
        $errors[] = 'Please choose a valid province.';
    }
    foreach (['latitude' => [-90, 90], 'longitude' => [-180, 180]] as $f => [$min, $max]) {
        if ($data[$f] !== null && (!is_numeric($data[$f]) || (float)$data[$f] < $min || (float)$data[$f] > $max)) {
            $errors[] = ucfirst($f) . " must be a decimal number between $min and $max.";
        }
    }
    if ($errors) {
        foreach ($errors as $err) {
            flash('error', $err);
        }
        $_SESSION['rollout_form_old'] = $data;
        redirect('/broadband/rollout_form.php' . ($id ? '?id=' . $id : ''));
    }

    if ($editing) {
        $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($data)));
        $pdo->prepare("UPDATE broadband_rollout_sites SET $set WHERE id = ?")->execute([...array_values($data), $id]);
        flash('success', 'Site updated.');
    } else {
        $data['reference_code'] = next_rollout_reference_code($pdo);
        $data['created_by'] = (int)current_user()['id'];
        $cols = array_keys($data);
        $pdo->prepare('INSERT INTO broadband_rollout_sites (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($data));
        flash('success', 'Site ' . $data['reference_code'] . ' registered. Next step: complete its pre-assessment.');
    }
    redirect('/broadband/rollout_list.php');
}

$form = $_SESSION['rollout_form_old'] ?? $editing ?? ['status' => 'Awaiting Pre-assessment'];
unset($_SESSION['rollout_form_old']);
$v = fn(string $k) => e(isset($form[$k]) ? (string)$form[$k] : '');
$projects = $pdo->query('SELECT id, project_name FROM projects ORDER BY project_name')->fetchAll();

$pageTitle = $editing ? 'Edit rollout site' : 'Register new broadband site';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1><?= $editing ? 'Edit ' . e($editing['reference_code']) : 'Register new broadband site' ?></h1>
        <p class="subtitle">A new site awaiting rollout &mdash; complete its pre-assessment once registered</p>
    </div>
</div>

<div class="card">
<form method="post" action="<?= APP_BASE_URL ?>/broadband/rollout_form.php">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    <div class="form-grid">
        <div class="field">
            <label for="site_name">Site name</label>
            <input type="text" id="site_name" name="site_name" required value="<?= $v('site_name') ?>">
        </div>
        <div class="field">
            <label for="project_id">Project</label>
            <select id="project_id" name="project_id" required>
                <option value="">Select project…</option>
                <?php foreach ($projects as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= (string)($form['project_id'] ?? '') === (string)$p['id'] ? 'selected' : '' ?>><?= e($p['project_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field full">
            <label>Site type</label>
            <div style="display:flex; flex-wrap:wrap; gap:6px 18px; align-items:center;">
                <?php foreach (broadband_site_types() as $t): ?>
                    <label style="font-weight:400; display:inline-flex; gap:5px; align-items:center; margin:0;">
                        <input type="radio" name="site_type" value="<?= $t ?>" <?= ($form['site_type'] ?? '') === $t ? 'checked' : '' ?>> <?= $t === 'Other' ? 'Other (specify)' : $t ?></label>
                <?php endforeach; ?>
                <input type="text" id="siteTypeOther" name="site_type_other" value="<?= $v('site_type_other') ?>" placeholder="Specify other site type" style="max-width:240px;">
            </div>
        </div>
        <div class="field">
            <label for="official_site_id">Official site ID</label>
            <input type="text" id="official_site_id" name="official_site_id" value="<?= $v('official_site_id') ?>">
        </div>
        <div class="field">
            <label for="province">Province</label>
            <select id="province" name="province">
                <option value="">Select province…</option>
                <?php foreach (sa_provinces() as $p): ?>
                    <option value="<?= e($p) ?>" <?= ($form['province'] ?? '') === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="district_municipality">District municipality</label>
            <select id="district_municipality" name="district_municipality"></select>
        </div>
        <div class="field">
            <label for="local_municipality">Local municipality</label>
            <select id="local_municipality" name="local_municipality"></select>
        </div>
        <div class="field full">
            <label for="town_village">Town &amp; village</label>
            <input type="text" id="town_village" name="town_village" value="<?= $v('town_village') ?>">
        </div>
        <div class="field">
            <label for="latitude">GPS latitude</label>
            <input type="text" inputmode="decimal" id="latitude" name="latitude" value="<?= $v('latitude') ?>" placeholder="e.g. -25.7461">
        </div>
        <div class="field">
            <label for="longitude">GPS longitude</label>
            <input type="text" inputmode="decimal" id="longitude" name="longitude" value="<?= $v('longitude') ?>" placeholder="e.g. 28.1881">
        </div>
        <div class="field full">
            <button type="button" class="btn btn-secondary btn-sm" id="useLocationBtn">Use current location</button>
            <span class="hint" id="locationStatus"></span>
        </div>
        <div class="field full">
            <label for="contact_details">Contact details</label>
            <input type="text" id="contact_details" name="contact_details" value="<?= $v('contact_details') ?>" placeholder="e.g. Mr J Dlamini (Principal) 082 123 4567">
        </div>
        <div class="field">
            <label for="service_provider">Service provider</label>
            <input type="text" id="service_provider" name="service_provider" value="<?= $v('service_provider') ?>">
        </div>
        <div class="field">
            <label for="planned_rollout_date">Planned rollout date</label>
            <input type="date" id="planned_rollout_date" name="planned_rollout_date" value="<?= $v('planned_rollout_date') ?>">
        </div>
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <?php foreach (rollout_statuses() as $st): ?>
                    <option value="<?= e($st) ?>" <?= ($form['status'] ?? '') === $st ? 'selected' : '' ?>><?= e($st) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="hint">Moves to “Pre-assessed” automatically when the pre-assessment records a recommendation.</p>
        </div>
        <div class="field full">
            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" rows="3"><?= $v('notes') ?></textarea>
        </div>
    </div>
    <div class="actions-row">
        <button type="submit" class="btn"><?= $editing ? 'Save changes' : 'Register site' ?></button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/broadband/rollout_list.php">Cancel</a>
    </div>
</form>
</div>

<script src="<?= APP_BASE_URL ?>/assets/js/broadband_forms.js"></script>
<script>
BroadbandForms.municipalities(<?= json_encode(sa_municipal_structure(), JSON_UNESCAPED_UNICODE) ?>, {
    province: <?= json_encode($form['province'] ?? '') ?>,
    district: <?= json_encode($form['district_municipality'] ?? '') ?>,
    local: <?= json_encode($form['local_municipality'] ?? '') ?>
});
BroadbandForms.toggleOnRadio('site_type', 'Other', 'siteTypeOther');
BroadbandForms.geolocate('useLocationBtn', 'latitude', 'longitude', 'locationStatus');
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
