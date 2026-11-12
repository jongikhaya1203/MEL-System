<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

sync_overdue_statuses($pdo);

$projectId = $_GET['project_id'] ?? '';

$params = [];
$sql = "SELECT i.*, p.project_name,
               (SELECT actual_value FROM indicator_actuals a WHERE a.indicator_id = i.id ORDER BY a.period_date DESC LIMIT 1) AS latest_actual
        FROM indicators i
        JOIN projects p ON p.id = i.project_id";
if ($projectId !== '') {
    $sql .= ' WHERE i.project_id = ?';
    $params[] = (int)$projectId;
}
$sql .= ' ORDER BY p.project_name, FIELD(i.result_level, "Impact", "Outcome", "Output"), i.indicator_name';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$projects = $pdo->query('SELECT id, project_name FROM projects ORDER BY project_name')->fetchAll();

// Group: project_name -> result_level -> result_statement -> [indicators]
$tree = [];
foreach ($rows as $r) {
    $tree[$r['project_name']][$r['result_level']][$r['result_statement']][] = $r;
}

$LEVEL_ORDER = ['Impact', 'Outcome', 'Output'];

$pageTitle = 'Results Framework';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Results Framework</h1>
        <p class="subtitle">Indicators grouped by project, result level and result statement &mdash; see the <a href="<?= APP_BASE_URL ?>/indicators/list.php">flat register</a> to filter/search individual indicators</p>
    </div>
    <?php if (in_array(current_user()['role'], ['admin', 'manager'], true)): ?>
        <a class="btn" href="<?= APP_BASE_URL ?>/indicators/form.php">+ New indicator</a>
    <?php endif; ?>
</div>

<div class="card">
    <form method="get" class="form-grid">
        <div class="field">
            <label for="project_id">Project</label>
            <select id="project_id" name="project_id" onchange="this.form.submit()">
                <option value="">All projects</option>
                <?php foreach ($projects as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= (string)$projectId === (string)$p['id'] ? 'selected' : '' ?>><?= e($p['project_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<?php if (!$tree): ?>
    <div class="card"><p class="hint">No indicators have been defined yet.</p></div>
<?php endif; ?>

<?php foreach ($tree as $projectName => $levels): ?>
    <div class="card results-tree">
        <h2><?= e($projectName) ?></h2>
        <?php foreach ($LEVEL_ORDER as $level): ?>
            <?php if (empty($levels[$level])) continue; ?>
            <div class="level-group">
                <h3><?= result_level_badge($level) ?></h3>
                <?php foreach ($levels[$level] as $statement => $indicatorsForStatement): ?>
                    <div class="result-block">
                        <div class="result-statement"><?= nl2br(e($statement)) ?></div>
                        <?php foreach ($indicatorsForStatement as $i): ?>
                            <?php $progress = compute_indicator_progress((float)$i['baseline_value'], (float)$i['target_value'], $i['latest_actual'] !== null ? (float)$i['latest_actual'] : null); ?>
                            <div class="indicator-row">
                                <div>
                                    <a href="<?= APP_BASE_URL ?>/indicators/view.php?id=<?= (int)$i['id'] ?>"><?= e($i['indicator_name']) ?></a>
                                    <div class="indicator-meta">
                                        <?= e($i['reference_code']) ?> &middot;
                                        Baseline <?= format_number($i['baseline_value']) ?> (<?= e((string)$i['baseline_year']) ?>) &rarr;
                                        Target <?= format_number($i['target_value']) ?> <?= e($i['unit_of_measure']) ?> by <?= format_date($i['current_target_date']) ?>
                                    </div>
                                </div>
                                <div>
                                    <?= progress_bar($progress) ?>
                                </div>
                                <div><?= status_badge($i['status']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
