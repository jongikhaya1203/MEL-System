<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'manager']);

$LEVELS = ['Impact', 'Outcome', 'Output'];
$FREQUENCIES = ['Monthly', 'Quarterly', 'Biannual', 'Annual'];

$editing = null;
if (isset($_GET['id'])) {
    $stmt = $pdo->prepare('SELECT * FROM indicators WHERE id = ?');
    $stmt->execute([(int)$_GET['id']]);
    $editing = $stmt->fetch() ?: null;
    if (!$editing) {
        flash('error', 'Indicator not found.');
        redirect('/indicators/list.php');
    }
    if ($editing['status'] === 'Achieved') {
        flash('info', 'This indicator has been marked Achieved. Baseline, target and definition can still be corrected if needed, but consider logging a new indicator for a fresh target cycle instead.');
    }
}

$projects = $pdo->query('SELECT id, project_name FROM projects ORDER BY project_name')->fetchAll();
$owners = $pdo->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll();

if (!$projects) {
    flash('error', 'Please add at least one project before defining an indicator.');
    redirect('/projects/list.php');
}

$suggestedRef = $editing['reference_code'] ?? next_indicator_reference_code($pdo);

$pageTitle = $editing ? 'Edit indicator' : 'New indicator';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1><?= $editing ? 'Edit indicator' : 'New indicator' ?></h1>
        <p class="subtitle">Indicator / Results Framework</p>
    </div>
</div>

<div class="card">
    <form method="post" action="<?= APP_BASE_URL ?>/indicators/save.php">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">

        <div class="form-grid">
            <div class="field">
                <label for="reference_code">Reference</label>
                <input type="text" id="reference_code" name="reference_code" required
                       value="<?= e($suggestedRef) ?>" <?= $editing ? 'readonly' : '' ?>>
            </div>
            <div class="field">
                <label for="project_id">Project</label>
                <select id="project_id" name="project_id" required>
                    <?php foreach ($projects as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= (int)($editing['project_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
                            <?= e($p['project_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="result_level">Result level</label>
                <select id="result_level" name="result_level" required>
                    <?php foreach ($LEVELS as $l): ?>
                        <option value="<?= $l ?>" <?= ($editing['result_level'] ?? '') === $l ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="collection_frequency">Data collection frequency</label>
                <select id="collection_frequency" name="collection_frequency" required>
                    <?php foreach ($FREQUENCIES as $f): ?>
                        <option value="<?= $f ?>" <?= ($editing['collection_frequency'] ?? '') === $f ? 'selected' : '' ?>><?= $f ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field full">
                <label for="result_statement">Result statement</label>
                <textarea id="result_statement" name="result_statement" required
                          placeholder="The outcome/output this indicator measures progress against, e.g. 'Increased access to broadband connectivity in underserviced areas'."><?= e($editing['result_statement'] ?? '') ?></textarea>
            </div>

            <div class="field full">
                <label for="indicator_name">Indicator</label>
                <input type="text" id="indicator_name" name="indicator_name" required
                       value="<?= e($editing['indicator_name'] ?? '') ?>"
                       placeholder="e.g. Number of households with access to broadband">
            </div>

            <div class="field full">
                <label for="definition">Definition / calculation method</label>
                <textarea id="definition" name="definition" required
                          placeholder="Precisely how this indicator is defined and calculated."><?= e($editing['definition'] ?? '') ?></textarea>
            </div>

            <div class="field">
                <label for="unit_of_measure">Unit of measure</label>
                <input type="text" id="unit_of_measure" name="unit_of_measure" required
                       value="<?= e($editing['unit_of_measure'] ?? '') ?>" placeholder="e.g. households, %, km of fibre">
            </div>
            <div class="field">
                <label for="data_source">Data source</label>
                <input type="text" id="data_source" name="data_source" required
                       value="<?= e($editing['data_source'] ?? '') ?>" placeholder="e.g. Site verification reports">
            </div>

            <div class="field">
                <label for="baseline_value">Baseline value</label>
                <input type="text" inputmode="decimal" id="baseline_value" name="baseline_value" required
                       value="<?= e($editing['baseline_value'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="baseline_year">Baseline year</label>
                <input type="text" inputmode="numeric" id="baseline_year" name="baseline_year" required
                       value="<?= e($editing['baseline_year'] ?? date('Y')) ?>">
            </div>

            <div class="field">
                <label for="target_value">End-term target value</label>
                <input type="text" inputmode="decimal" id="target_value" name="target_value" required
                       value="<?= e($editing['target_value'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="responsible_user_id">Responsible person</label>
                <select id="responsible_user_id" name="responsible_user_id" required>
                    <?php foreach ($owners as $o): ?>
                        <option value="<?= (int)$o['id'] ?>" <?= (int)($editing['responsible_user_id'] ?? 0) === (int)$o['id'] ? 'selected' : '' ?>>
                            <?= e($o['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if (!$editing): ?>
            <div class="field">
                <label for="target_date">Target date</label>
                <input type="date" id="target_date" name="target_date" required value="<?= e($_POST['target_date'] ?? '') ?>">
                <p class="hint">This becomes the original target date and cannot be changed here afterwards &mdash; use "Revise target date" on the record instead.</p>
            </div>
            <?php else: ?>
            <div class="field">
                <label>Current target date</label>
                <input type="text" value="<?= format_date($editing['current_target_date']) ?> (original: <?= format_date($editing['original_target_date']) ?>)" disabled>
                <p class="hint">To change the target date, use "Revise target date" from the record view &mdash; it requires a reason and keeps the original date on file.</p>
            </div>
            <?php endif; ?>

            <?php if ($editing): ?>
            <div class="field full">
                <label for="achievement_notes">Performance commentary</label>
                <textarea id="achievement_notes" name="achievement_notes"
                          placeholder="Ongoing narrative on progress towards the target."><?= e($editing['achievement_notes'] ?? '') ?></textarea>
            </div>
            <?php endif; ?>
        </div>

        <button type="submit" class="btn"><?= $editing ? 'Save changes' : 'Create indicator' ?></button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indicators/list.php">Cancel</a>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
