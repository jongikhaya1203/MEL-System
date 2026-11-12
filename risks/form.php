<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'manager']);

$CATEGORIES = ['Strategic', 'Operational', 'Financial', 'Compliance', 'Reputational', 'ICT', 'Other'];
$LEVELS = ['Low', 'Medium', 'High'];

$editing = null;
if (isset($_GET['id'])) {
    $stmt = $pdo->prepare('SELECT * FROM risks WHERE id = ?');
    $stmt->execute([(int)$_GET['id']]);
    $editing = $stmt->fetch() ?: null;
    if (!$editing) {
        flash('error', 'Risk not found.');
        redirect('/risks/list.php');
    }
    if ($editing['status'] === 'Closed') {
        flash('info', 'This risk is closed. Reopen is not supported; log a new risk if it recurs.');
    }
}

$projects = $pdo->query('SELECT id, project_name FROM projects ORDER BY project_name')->fetchAll();
$owners = $pdo->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll();

if (!$projects) {
    flash('error', 'Please add at least one project before logging a risk.');
    redirect('/projects/list.php');
}

$suggestedRef = $editing['reference_code'] ?? next_risk_reference_code($pdo);

$pageTitle = $editing ? 'Edit risk' : 'New risk';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1><?= $editing ? 'Edit risk' : 'New risk' ?></h1>
        <p class="subtitle">Risk Register</p>
    </div>
</div>

<div class="card">
    <form method="post" action="<?= APP_BASE_URL ?>/risks/save.php">
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
                <label for="category">Risk category</label>
                <select id="category" name="category" required>
                    <?php foreach ($CATEGORIES as $c): ?>
                        <option value="<?= $c ?>" <?= ($editing['category'] ?? '') === $c ? 'selected' : '' ?>><?= $c ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="date_identified">Date identified</label>
                <input type="date" id="date_identified" name="date_identified" required
                       value="<?= e($editing['date_identified'] ?? '') ?>">
            </div>

            <div class="field full">
                <label for="risk_description">Risk description</label>
                <textarea id="risk_description" name="risk_description" required
                          placeholder="What could happen, and why, stated as a specific risk (not a general concern)."><?= e($editing['risk_description'] ?? '') ?></textarea>
            </div>

            <div class="field full">
                <label for="existing_controls">Existing controls</label>
                <textarea id="existing_controls" name="existing_controls" required
                          placeholder="Controls already in place and their limitations."><?= e($editing['existing_controls'] ?? '') ?></textarea>
            </div>

            <div class="field">
                <label for="likelihood">Likelihood</label>
                <select id="likelihood" name="likelihood" required>
                    <?php foreach ($LEVELS as $l): ?>
                        <option value="<?= $l ?>" <?= ($editing['likelihood'] ?? '') === $l ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="impact">Impact</label>
                <select id="impact" name="impact" required>
                    <?php foreach ($LEVELS as $l): ?>
                        <option value="<?= $l ?>" <?= ($editing['impact'] ?? '') === $l ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">Risk rating (Low/Medium/High/Critical) is calculated automatically from likelihood &times; impact when you save.</p>
            </div>

            <div class="field full">
                <label for="mitigation_action">Agreed mitigation action</label>
                <textarea id="mitigation_action" name="mitigation_action" required
                          placeholder="The specific action agreed to treat this risk."><?= e($editing['mitigation_action'] ?? '') ?></textarea>
            </div>

            <div class="field">
                <label for="owner_user_id">Owner</label>
                <select id="owner_user_id" name="owner_user_id" required>
                    <?php foreach ($owners as $o): ?>
                        <option value="<?= (int)$o['id'] ?>" <?= (int)($editing['owner_user_id'] ?? 0) === (int)$o['id'] ? 'selected' : '' ?>>
                            <?= e($o['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if (!$editing): ?>
            <div class="field">
                <label for="deadline">Mitigation deadline</label>
                <input type="date" id="deadline" name="deadline" required value="<?= e($_POST['deadline'] ?? '') ?>">
                <p class="hint">This becomes the original deadline and cannot be changed here afterwards &mdash; use "Revise deadline" on the record instead.</p>
            </div>
            <?php else: ?>
            <div class="field">
                <label>Current deadline</label>
                <input type="text" value="<?= format_date($editing['current_deadline']) ?> (original: <?= format_date($editing['original_deadline']) ?>)" disabled>
                <p class="hint">To change the deadline, use "Revise deadline" from the record view &mdash; it requires a reason and keeps the original date on file.</p>
            </div>
            <?php endif; ?>

            <?php if ($editing): ?>
            <div class="field full">
                <label for="follow_up_outcome">Follow-up outcome</label>
                <textarea id="follow_up_outcome" name="follow_up_outcome"
                          placeholder="Progress notes on implementation of the mitigation action."><?= e($editing['follow_up_outcome'] ?? '') ?></textarea>
            </div>
            <?php endif; ?>
        </div>

        <button type="submit" class="btn"><?= $editing ? 'Save changes' : 'Log risk' ?></button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/risks/list.php">Cancel</a>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
