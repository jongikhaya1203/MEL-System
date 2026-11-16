<?php
/** Broadband > Acceptance Forms — every site with the state of its Broadband Infrastructure Acceptance Form. */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$projectId = $_GET['project_id'] ?? '';
$formStatus = $_GET['form_status'] ?? '';
$search = trim($_GET['q'] ?? '');

// Form state, derived: no row = Not started; both on-screen signatures = Signed; anything else = In progress.
$stateSql = "CASE WHEN af.id IS NULL THEN 'Not started'
                  WHEN af.site_rep_signature IS NOT NULL AND af.usaasa_rep_signature IS NOT NULL THEN 'Signed'
                  ELSE 'In progress' END";

$where = [];
$params = [];
if ($projectId !== '') {
    $where[] = 's.project_id = ?';
    $params[] = (int)$projectId;
}
if (in_array($formStatus, ['Not started', 'In progress', 'Signed'], true)) {
    $where[] = "$stateSql = ?";
    $params[] = $formStatus;
}
if ($search !== '') {
    $where[] = '(s.site_code LIKE ? OR s.site_name LIKE ? OR s.location LIKE ? OR af.official_site_id LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}

$sql = "SELECT s.id, s.site_code, s.site_name, s.location, s.status, p.project_name,
               af.site_type, af.local_municipality, af.backup_power, af.backup_power_proof,
               af.download_speed_mbps, af.upload_speed_mbps, af.speed_proof,
               af.site_rep_signature, af.usaasa_rep_signature, af.updated_at,
               $stateSql AS form_state
        FROM sites s
        JOIN projects p ON p.id = s.project_id
        LEFT JOIN site_acceptance_forms af ON af.site_id = s.id";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= " ORDER BY FIELD($stateSql, 'In progress', 'Not started', 'Signed'), af.updated_at DESC, s.site_name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$counts = $pdo->query(
    "SELECT $stateSql AS st, COUNT(*) AS n FROM sites s LEFT JOIN site_acceptance_forms af ON af.site_id = s.id GROUP BY st"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$projects = $pdo->query('SELECT id, project_name FROM projects ORDER BY project_name')->fetchAll();

$stateBadge = function (string $state): string {
    $cls = ['Not started' => 'badge badge-role', 'In progress' => 'badge badge-progress', 'Signed' => 'badge badge-closed'][$state] ?? 'badge';
    return '<span class="' . $cls . '">' . e($state) . '</span>';
};

$pageTitle = 'Acceptance Forms';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Broadband Infrastructure Acceptance Forms</h1>
        <p class="subtitle">One form per completed site &mdash; installation checks, backup power and speed proof, and sign-off by the site and USAASA representatives</p>
    </div>
</div>

<div class="card">
    <div class="actions-row" style="margin:0 0 14px;">
        <?php foreach (['Not started', 'In progress', 'Signed'] as $st): ?>
            <a class="btn btn-secondary btn-sm" href="?form_status=<?= urlencode($st) ?>"><?= $st ?>: <strong><?= (int)($counts[$st] ?? 0) ?></strong></a>
        <?php endforeach; ?>
    </div>
    <form method="get" class="form-grid">
        <div class="field">
            <label for="form_status">Form status</label>
            <select id="form_status" name="form_status" onchange="this.form.submit()">
                <option value="">All</option>
                <?php foreach (['Not started', 'In progress', 'Signed'] as $st): ?>
                    <option value="<?= $st ?>" <?= $formStatus === $st ? 'selected' : '' ?>><?= $st ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="project_id">Project</label>
            <select id="project_id" name="project_id" onchange="this.form.submit()">
                <option value="">All projects</option>
                <?php foreach ($projects as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= (string)$projectId === (string)$p['id'] ? 'selected' : '' ?>><?= e($p['project_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field full">
            <label for="q">Search reference / site name / location / official site ID</label>
            <input type="text" id="q" name="q" value="<?= e($search) ?>">
        </div>
        <div class="field">
            <button type="submit" class="btn btn-secondary">Filter</button>
            <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/sites/acceptance_list.php">Reset</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>Reference</th><th>Site</th><th>Project</th><th>Type</th><th>Local Mun</th>
            <th>Backup power</th><th>Speed (down / up)</th><th>Signed</th><th>Form</th><th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= e($r['site_code']) ?></td>
                <td><?= e($r['site_name']) ?></td>
                <td><?= e($r['project_name']) ?></td>
                <td><?= e($r['site_type'] ?? '—') ?></td>
                <td><?= e($r['local_municipality'] ?? '—') ?></td>
                <td>
                    <?php if ($r['backup_power'] === 'Y'): ?>Yes<?= $r['backup_power_proof'] ? ' &#128206;' : ' <span class="hint">(no proof)</span>' ?>
                    <?php elseif ($r['backup_power'] === 'N'): ?>No
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                    <?php if ($r['download_speed_mbps'] !== null || $r['upload_speed_mbps'] !== null): ?>
                        <?= e($r['download_speed_mbps'] ?? '—') ?> / <?= e($r['upload_speed_mbps'] ?? '—') ?> Mbps<?= $r['speed_proof'] ? ' &#128206;' : ' <span class="hint">(no proof)</span>' ?>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                    Site <?= $r['site_rep_signature'] ? '&#10003;' : '&ndash;' ?> &middot;
                    USAASA <?= $r['usaasa_rep_signature'] ? '&#10003;' : '&ndash;' ?>
                </td>
                <td><?= $stateBadge($r['form_state']) ?></td>
                <td><a class="btn btn-secondary btn-sm" style="white-space:nowrap;" href="<?= APP_BASE_URL ?>/sites/acceptance_form.php?id=<?= (int)$r['id'] ?>">
                    <?= $r['form_state'] === 'Not started' ? 'Start form' : 'Open form' ?></a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="10">No sites match these filters.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
