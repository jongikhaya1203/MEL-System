<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'manager', 'planner']);

$editing = null;
if (isset($_GET['id'])) {
    $stmt = $pdo->prepare('SELECT * FROM installer_allocations WHERE id = ?');
    $stmt->execute([(int)$_GET['id']]);
    $editing = $stmt->fetch() ?: null;
    if (!$editing) {
        flash('error', 'Allocation not found.');
        redirect('/planning/list.php');
    }
    if ($editing['status'] === 'Completed') {
        flash('info', 'This allocation is complete. Changes here won\'t affect its target/deadline history.');
    }
}

$projects = $pdo->query("SELECT id, project_name, province FROM projects WHERE province IS NOT NULL ORDER BY project_name")->fetchAll();
$installers = $pdo->query("SELECT id, full_name FROM users WHERE role = 'field_worker' AND is_active = 1 ORDER BY full_name")->fetchAll();
$indicators = $pdo->query(
    "SELECT i.id, i.indicator_name, i.reference_code, p.project_name
     FROM indicators i JOIN projects p ON p.id = i.project_id
     ORDER BY p.project_name, i.indicator_name"
)->fetchAll();

if (!$projects) {
    flash('error', 'Please add at least one province-tagged project before dispatching an allocation.');
    redirect('/projects/list.php');
}
if (!$installers) {
    flash('error', 'Please add at least one active Installer (field_worker) user before dispatching an allocation.');
    redirect('/users/list.php');
}

$projectProvinces = [];
foreach ($projects as $p) {
    $projectProvinces[(int)$p['id']] = $p['province'];
}

$suggestedRef = $editing['reference_code'] ?? next_allocation_reference_code($pdo);

$pageTitle = $editing ? 'Edit allocation' : 'New allocation';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1><?= $editing ? 'Edit allocation' : 'Dispatch & allocate installer' ?></h1>
        <p class="subtitle">Planning &amp; Dispatch</p>
    </div>
</div>

<div class="card">
    <form method="post" action="<?= APP_BASE_URL ?>/planning/save.php" id="allocationForm">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">

        <div class="form-grid">
            <div class="field">
                <label for="reference_code">Reference</label>
                <input type="text" id="reference_code" name="reference_code" required
                       value="<?= e($suggestedRef) ?>" <?= $editing ? 'readonly' : '' ?>>
            </div>
            <div class="field"></div>

            <div class="field">
                <label for="project_id">Province (project)</label>
                <select id="project_id" name="project_id" required>
                    <option value="">Select province…</option>
                    <?php foreach ($projects as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= (int)($editing['project_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
                            <?= e($p['project_name']) ?> (<?= e($p['province']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="region">Region (district municipality / metro)</label>
                <select id="region" name="region" required>
                    <option value="">Select province first…</option>
                </select>
            </div>

            <div class="field">
                <label for="installer_id">Installer</label>
                <select id="installer_id" name="installer_id" required>
                    <option value="">Select installer…</option>
                    <?php foreach ($installers as $i): ?>
                        <option value="<?= (int)$i['id'] ?>" <?= (int)($editing['installer_id'] ?? 0) === (int)$i['id'] ? 'selected' : '' ?>>
                            <?= e($i['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="indicator_id">Linked indicator (optional)</label>
                <select id="indicator_id" name="indicator_id">
                    <option value="">No linked indicator</option>
                    <?php foreach ($indicators as $ind): ?>
                        <option value="<?= (int)$ind['id'] ?>" <?= (int)($editing['indicator_id'] ?? 0) === (int)$ind['id'] ? 'selected' : '' ?>>
                            [<?= e($ind['project_name']) ?>] <?= e($ind['indicator_name']) ?> (<?= e($ind['reference_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">Progress reported against this allocation also records an actual value on the linked Results Framework indicator.</p>
            </div>

            <div class="field">
                <label for="target_installations">Target number of installations</label>
                <input type="text" inputmode="numeric" id="target_installations" name="target_installations" required
                       value="<?= e((string)($editing['target_installations'] ?? '')) ?>">
            </div>

            <?php if (!$editing): ?>
            <div class="field">
                <label for="deadline">Deadline</label>
                <input type="date" id="deadline" name="deadline" required value="<?= e($_POST['deadline'] ?? '') ?>">
                <p class="hint">This becomes the original deadline and cannot be changed here afterwards &mdash; use "Revise deadline" on the record instead.</p>
            </div>
            <?php else: ?>
            <div class="field">
                <label>Current deadline</label>
                <input type="text" value="<?= format_date($editing['current_deadline']) ?> (original: <?= format_date($editing['original_deadline']) ?>)" disabled>
                <p class="hint">To change the deadline, use "Revise deadline" from the record view.</p>
            </div>
            <?php endif; ?>

            <div class="field full">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" placeholder="Dispatch instructions, coverage area detail, equipment notes, etc."><?= e($editing['notes'] ?? '') ?></textarea>
            </div>
        </div>

        <button type="submit" class="btn"><?= $editing ? 'Save changes' : 'Dispatch allocation' ?></button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/planning/list.php">Cancel</a>
    </form>
</div>

<script>
var SA_MUNICIPAL_STRUCTURE = <?= json_encode(sa_municipal_structure(), JSON_UNESCAPED_UNICODE) ?>;
var PROJECT_PROVINCES = <?= json_encode($projectProvinces) ?>;
(function () {
    var existingProjectId = <?= json_encode((string)($editing['project_id'] ?? '')) ?>;
    var existingRegion = <?= json_encode($editing['region'] ?? '') ?>;

    var projectSelect = document.getElementById('project_id');
    var regionSelect = document.getElementById('region');

    function populateRegions(projectId, selectedRegion) {
        var province = PROJECT_PROVINCES[projectId];
        var districts = (province && SA_MUNICIPAL_STRUCTURE[province]) ? SA_MUNICIPAL_STRUCTURE[province] : [];
        regionSelect.innerHTML = '';
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = districts.length ? 'Select region…' : 'Select province first…';
        regionSelect.appendChild(placeholder);
        var found = false;
        districts.forEach(function (row) {
            var o = document.createElement('option');
            o.value = row.district;
            o.textContent = row.type === 'metro' ? row.district + ' (Metropolitan Municipality)' : row.district;
            if (row.district === selectedRegion) { o.selected = true; found = true; }
            regionSelect.appendChild(o);
        });
        if (selectedRegion && !found) {
            var fallback = document.createElement('option');
            fallback.value = selectedRegion;
            fallback.textContent = selectedRegion + ' (previously captured — please reselect)';
            fallback.selected = true;
            regionSelect.appendChild(fallback);
        }
    }

    projectSelect.addEventListener('change', function () {
        populateRegions(projectSelect.value, '');
    });

    populateRegions(existingProjectId, existingRegion);
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
