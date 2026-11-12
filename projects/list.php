<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$canManage = in_array(current_user()['role'], ['admin', 'manager'], true);

$editing = null;
if ($canManage && isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) {
        http_response_code(403);
        die('You do not have permission to manage projects.');
    }
    csrf_verify();

    $id = (int)($_POST['id'] ?? 0);
    $code = trim($_POST['project_code'] ?? '');
    $name = trim($_POST['project_name'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $province = ($province !== '' && in_array($province, sa_provinces(), true)) ? $province : null;
    $programme = trim($_POST['programme'] ?? '');
    $programme = in_array($programme, project_programmes(), true) ? $programme : 'Broadband Connectivity';
    $description = trim($_POST['description'] ?? '');
    $targetHouseholdsRaw = trim($_POST['target_households'] ?? '');
    $targetSitesRaw = trim($_POST['target_sites'] ?? '');
    $targetHouseholds = null;
    $targetSites = null;

    if ($code === '' || $name === '') {
        flash('error', 'Project code and name are required.');
        redirect('/projects/list.php');
    }
    if ($targetHouseholdsRaw !== '') {
        if (!ctype_digit($targetHouseholdsRaw)) {
            flash('error', 'Target households must be a whole number.');
            redirect('/projects/list.php');
        }
        $targetHouseholds = (int)$targetHouseholdsRaw;
    }
    if ($targetSitesRaw !== '') {
        if (!ctype_digit($targetSitesRaw)) {
            flash('error', 'Target sites must be a whole number.');
            redirect('/projects/list.php');
        }
        $targetSites = (int)$targetSitesRaw;
    }

    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE projects SET project_code = ?, project_name = ?, province = ?, programme = ?, description = ?, target_households = ?, target_sites = ? WHERE id = ?');
        $stmt->execute([$code, $name, $province, $programme, $description, $targetHouseholds, $targetSites, $id]);
        flash('success', 'Project updated.');
    } else {
        $stmt = $pdo->prepare('INSERT INTO projects (project_code, project_name, province, programme, description, target_households, target_sites, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$code, $name, $province, $programme, $description, $targetHouseholds, $targetSites, current_user()['id']]);
        flash('success', 'Project added.');
    }
    redirect('/projects/list.php');
}

$projects = $pdo->query(
    "SELECT p.*, (SELECT COUNT(*) FROM findings_register f WHERE f.project_id = p.id) AS finding_count,
            (SELECT COALESCE(SUM(households_connected), 0) FROM sites s WHERE s.project_id = p.id AND s.status = 'Approved') AS households_connected,
            (SELECT COUNT(*) FROM sites s WHERE s.project_id = p.id AND s.status = 'Approved') AS sites_deployed
     FROM projects p ORDER BY p.created_at DESC"
)->fetchAll();

$projectsByProgramme = [];
foreach (project_programmes() as $prog) {
    $projectsByProgramme[$prog] = [];
}
foreach ($projects as $p) {
    $projectsByProgramme[$p['programme']][] = $p;
}

function render_projects_table(array $rows, bool $canManage): void
{
    ?>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Code</th><th>Name</th><th>Province</th><th>Households connected / target</th><th>Sites deployed / target</th><th>Findings</th><th>Created</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($rows as $p): ?>
            <tr>
                <td><?= e($p['project_code']) ?></td>
                <td><?= e($p['project_name']) ?></td>
                <td><?= e($p['province'] ?? '—') ?></td>
                <td>
                    <?= number_format((int)$p['households_connected']) ?> /
                    <?= $p['target_households'] !== null ? number_format((int)$p['target_households']) : '<span class="hint">no target set</span>' ?>
                </td>
                <td>
                    <?= number_format((int)$p['sites_deployed']) ?> /
                    <?= $p['target_sites'] !== null ? number_format((int)$p['target_sites']) : '<span class="hint">no target set</span>' ?>
                </td>
                <td><?= (int)$p['finding_count'] ?></td>
                <td><?= format_date($p['created_at']) ?></td>
                <?php if ($canManage): ?>
                <td><a href="?edit=<?= (int)$p['id'] ?>">Edit</a></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="<?= $canManage ? 8 : 7 ?>">No projects in this programme yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
    <?php
}

$pageTitle = 'Projects';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Projects</h1>
        <p class="subtitle">Projects referenced by the evaluation findings &amp; lessons register</p>
    </div>
</div>

<?php if ($canManage): ?>
<div class="card">
    <h2><?= $editing ? 'Edit project' : 'Add a project' ?></h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">
        <div class="form-grid">
            <div class="field">
                <label for="project_code">Project code</label>
                <input type="text" id="project_code" name="project_code" required value="<?= e($editing['project_code'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="project_name">Project name</label>
                <input type="text" id="project_name" name="project_name" required value="<?= e($editing['project_name'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="programme">Programme</label>
                <select id="programme" name="programme">
                    <?php foreach (project_programmes() as $prog): ?>
                        <option value="<?= e($prog) ?>" <?= ($editing['programme'] ?? 'Broadband Connectivity') === $prog ? 'selected' : '' ?>><?= e($prog) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">Which USAASA programme this project belongs to — shown as its own section below.</p>
            </div>
            <div class="field">
                <label for="province">Province (optional)</label>
                <select id="province" name="province">
                    <option value="">Not province-specific (e.g. a national fund)</option>
                    <?php foreach (sa_provinces() as $p): ?>
                        <option value="<?= e($p) ?>" <?= ($editing['province'] ?? '') === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">Province-tagged projects are what Planning &amp; Dispatch offers for installer allocation.</p>
            </div>
            <div class="field">
                <label for="target_households">Target households</label>
                <input type="text" inputmode="numeric" id="target_households" name="target_households"
                       value="<?= e($editing['target_households'] ?? '') ?>" placeholder="e.g. 45000">
                <p class="hint">Optional. Overall household target for this project (e.g. a province-level rollout).</p>
            </div>
            <div class="field">
                <label for="target_sites">Target sites (this year)</label>
                <input type="text" inputmode="numeric" id="target_sites" name="target_sites"
                       value="<?= e($editing['target_sites'] ?? '') ?>" placeholder="e.g. 20">
                <p class="hint">Optional. Yearly site deployment target, shown on the dashboard target-vs-actual chart.</p>
            </div>
            <div class="field full">
                <label for="description">Description</label>
                <textarea id="description" name="description"><?= e($editing['description'] ?? '') ?></textarea>
            </div>
        </div>
        <button type="submit" class="btn"><?= $editing ? 'Save changes' : 'Add project' ?></button>
        <?php if ($editing): ?>
            <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/projects/list.php">Cancel</a>
        <?php endif; ?>
    </form>
</div>
<?php endif; ?>

<?php foreach ($projectsByProgramme as $programmeName => $rows): ?>
<div class="card">
    <h2><?= e($programmeName) ?></h2>
    <p class="subtitle" style="margin-top:-4px;">
        <?= $programmeName === 'BDM'
            ? 'Broadcasting and Digital Migration — set-top box distribution and analogue switch-off support.'
            : 'Province-level internet access rollout.' ?>
    </p>
    <?php render_projects_table($rows, $canManage); ?>
</div>
<?php endforeach; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
