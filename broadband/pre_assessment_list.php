<?php
/** Broadband > Pre-Assessment Forms — every registered rollout site with the state of its pre-assessment. */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$paStatus = $_GET['pa_status'] ?? '';
$search = trim($_GET['q'] ?? '');

// Derived: no row = Not started; recommendation recorded = Suitable / Not suitable; otherwise In progress.
$stateSql = "CASE WHEN pa.id IS NULL THEN 'Not started'
                  WHEN pa.suitable_for_rollout = 'Y' THEN 'Suitable'
                  WHEN pa.suitable_for_rollout = 'N' THEN 'Not suitable'
                  ELSE 'In progress' END";
$states = ['Not started', 'In progress', 'Suitable', 'Not suitable'];

$where = ["r.status <> 'Cancelled'"];
$params = [];
if (in_array($paStatus, $states, true)) {
    $where[] = "$stateSql = ?";
    $params[] = $paStatus;
}
if ($search !== '') {
    $where[] = '(r.reference_code LIKE ? OR r.site_name LIKE ? OR r.official_site_id LIKE ? OR r.local_municipality LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}

$stmt = $pdo->prepare(
    "SELECT r.id, r.reference_code, r.site_name, r.site_type, r.province, r.local_municipality, r.status,
            pa.assessment_date, pa.recommended_technology, pa.assessor_name, pa.updated_at,
            pa.assessor_signature, pa.site_rep_signature, $stateSql AS pa_state
     FROM broadband_rollout_sites r
     LEFT JOIN site_pre_assessments pa ON pa.rollout_site_id = r.id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY FIELD($stateSql, 'In progress', 'Not started', 'Suitable', 'Not suitable'), pa.updated_at DESC, r.site_name"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$counts = $pdo->query(
    "SELECT $stateSql AS st, COUNT(*) FROM broadband_rollout_sites r
     LEFT JOIN site_pre_assessments pa ON pa.rollout_site_id = r.id
     WHERE r.status <> 'Cancelled' GROUP BY st"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$stateBadge = function (string $s): string {
    $cls = ['Not started' => 'badge badge-role', 'In progress' => 'badge badge-progress',
            'Suitable' => 'badge badge-closed', 'Not suitable' => 'badge badge-overdue'][$s] ?? 'badge';
    return '<span class="' . $cls . '">' . e($s) . '</span>';
};

$pageTitle = 'Pre-Assessment Forms';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Broadband Site Pre-Assessment Forms</h1>
        <p class="subtitle">Site survey for each new site awaiting rollout &mdash; readiness checks, recommended technology and a suitability recommendation</p>
    </div>
    <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/broadband/rollout_list.php">New sites awaiting rollout</a>
</div>

<div class="card">
    <div class="actions-row" style="margin:0 0 14px;">
        <?php foreach ($states as $st): ?>
            <a class="btn btn-secondary btn-sm" href="?pa_status=<?= urlencode($st) ?>"><?= $st ?>: <strong><?= (int)($counts[$st] ?? 0) ?></strong></a>
        <?php endforeach; ?>
    </div>
    <form method="get" class="form-grid">
        <div class="field">
            <label for="pa_status">Pre-assessment status</label>
            <select id="pa_status" name="pa_status" onchange="this.form.submit()">
                <option value="">All</option>
                <?php foreach ($states as $st): ?>
                    <option value="<?= $st ?>" <?= $paStatus === $st ? 'selected' : '' ?>><?= $st ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="q">Search</label>
            <input type="text" id="q" name="q" value="<?= e($search) ?>" placeholder="Reference, site, official ID, local mun…">
        </div>
        <div class="field">
            <button type="submit" class="btn btn-secondary">Filter</button>
            <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/broadband/pre_assessment_list.php">Reset</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>Reference</th><th>Site</th><th>Type</th><th>Province / Local Mun</th><th>Assessed on</th>
            <th>Assessor</th><th>Recommended tech</th><th>Signed</th><th>Pre-assessment</th><th>Rollout status</th><th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= e($r['reference_code']) ?></td>
                <td><?= e($r['site_name']) ?></td>
                <td><?= e($r['site_type'] ?? '—') ?></td>
                <td><?= e($r['province'] ?? '—') ?><?= $r['local_municipality'] ? '<br><span class="hint">' . e($r['local_municipality']) . '</span>' : '' ?></td>
                <td><?= $r['assessment_date'] ? format_date($r['assessment_date']) : '—' ?></td>
                <td><?= e($r['assessor_name'] ?? '—') ?></td>
                <td><?= e($r['recommended_technology'] ?? '—') ?></td>
                <td>Assessor <?= $r['assessor_signature'] ? '&#10003;' : '&ndash;' ?> &middot; Site <?= $r['site_rep_signature'] ? '&#10003;' : '&ndash;' ?></td>
                <td><?= $stateBadge($r['pa_state']) ?></td>
                <td><?= rollout_status_badge($r['status']) ?></td>
                <td><a class="btn btn-secondary btn-sm" style="white-space:nowrap;" href="<?= APP_BASE_URL ?>/broadband/pre_assessment.php?id=<?= (int)$r['id'] ?>">
                    <?= $r['pa_state'] === 'Not started' ? 'Start form' : 'Open form' ?></a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="11">No sites match. Pre-assessments are done for sites registered under
                <a href="<?= APP_BASE_URL ?>/broadband/rollout_list.php">New Sites Awaiting Rollout</a>.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
