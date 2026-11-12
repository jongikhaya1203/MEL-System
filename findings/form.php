<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'manager']);

$editing = null;
if (isset($_GET['id'])) {
    $stmt = $pdo->prepare('SELECT * FROM findings_register WHERE id = ?');
    $stmt->execute([(int)$_GET['id']]);
    $editing = $stmt->fetch() ?: null;
    if (!$editing) {
        flash('error', 'Finding not found.');
        redirect('/findings/list.php');
    }
    if ($editing['status'] === 'Closed') {
        flash('info', 'This record is closed. Reopen is not supported; create a follow-up finding if further action is needed.');
    }
}

$projects = $pdo->query('SELECT id, project_name FROM projects ORDER BY project_name')->fetchAll();
$owners = $pdo->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll();

if (!$projects) {
    flash('error', 'Please add at least one project before recording a finding.');
    redirect('/projects/list.php');
}

$suggestedRef = $editing['reference_code'] ?? next_reference_code($pdo);

$pageTitle = $editing ? 'Edit finding / lesson' : 'New finding / lesson';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1><?= $editing ? 'Edit finding / lesson' : 'New finding / lesson' ?></h1>
        <p class="subtitle">C3.5 Evaluation Findings &amp; Lessons Register</p>
    </div>
</div>

<div class="card">
    <form method="post" action="<?= APP_BASE_URL ?>/findings/save.php">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">

        <div class="form-grid">
            <div class="field">
                <label for="reference_code">Reference</label>
                <input type="text" id="reference_code" name="reference_code" required
                       value="<?= e($suggestedRef) ?>" <?= $editing ? 'readonly' : '' ?>>
                <p class="hint">Unique reference for this finding/lesson entry.</p>
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
                <label for="source">Source</label>
                <input type="text" id="source" name="source" required value="<?= e($editing['source'] ?? '') ?>"
                       placeholder="e.g. Mid-term evaluation report">
            </div>
            <div class="field">
                <label for="source_date">Date of source</label>
                <input type="date" id="source_date" name="source_date" required
                       value="<?= e($editing['source_date'] ?? '') ?>">
            </div>

            <div class="field full">
                <label for="evidence_based_finding">Evidence-based finding</label>
                <textarea id="evidence_based_finding" name="evidence_based_finding" required
                          placeholder="What the evidence shows, stated as fact (not interpretation)."><?= e($editing['evidence_based_finding'] ?? '') ?></textarea>
            </div>

            <div class="field full">
                <label for="lesson_drawn">Lesson drawn</label>
                <textarea id="lesson_drawn" name="lesson_drawn" required
                          placeholder="The generalisable lesson or insight drawn from the finding above."><?= e($editing['lesson_drawn'] ?? '') ?></textarea>
            </div>

            <div class="field full">
                <label for="evidence_and_limitations">Evidence and limitations</label>
                <textarea id="evidence_and_limitations" name="evidence_and_limitations" required
                          placeholder="Data sources used and any limitations on reliability, coverage or attribution."><?= e($editing['evidence_and_limitations'] ?? '') ?></textarea>
            </div>

            <div class="field full">
                <label for="implication">Implication</label>
                <textarea id="implication" name="implication" required
                          placeholder="What this means for the project, programme or policy going forward."><?= e($editing['implication'] ?? '') ?></textarea>
            </div>

            <div class="field full">
                <label for="management_response">Agreed management response (action)</label>
                <textarea id="management_response" name="management_response" required
                          placeholder="The specific action management has agreed to take in response."><?= e($editing['management_response'] ?? '') ?></textarea>
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
                <label for="deadline">Deadline</label>
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
                          placeholder="Progress notes on implementation of the agreed action."><?= e($editing['follow_up_outcome'] ?? '') ?></textarea>
            </div>
            <?php endif; ?>
        </div>

        <button type="submit" class="btn"><?= $editing ? 'Save changes' : 'Create finding / lesson' ?></button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/findings/list.php">Cancel</a>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
