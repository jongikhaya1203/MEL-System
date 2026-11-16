<?php
/** Broadband > New Sites Awaiting Rollout — register of planned sites and where each is in the pipeline. */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$role = current_user()['role'];
$canManage = in_array($role, ['admin', 'manager', 'planner'], true);
$canChange = can_change_connectivity($role);
$canSelect = $canManage || $canChange;

require __DIR__ . '/rollout_filters.php';
$status = $f['status'];
$projectId = $f['project_id'];
$province = $f['province'];
$search = $f['q'];
$showAll = (bool)$f['all'];
$connectivity = $f['connectivity'];

$sql = "SELECT r.*, p.project_name, pa.id AS pa_id, pa.suitable_for_rollout
        FROM broadband_rollout_sites r
        JOIN projects p ON p.id = r.project_id
        LEFT JOIN site_pre_assessments pa ON pa.rollout_site_id = r.id";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= " ORDER BY FIELD(r.status, 'Awaiting Pre-assessment', 'Pre-assessed', 'Approved for Rollout', 'Rolled Out', 'Cancelled'),
          r.planned_rollout_date IS NULL, r.planned_rollout_date, r.site_name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$counts = $pdo->query('SELECT status, COUNT(*) FROM broadband_rollout_sites GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$connCounts = $pdo->query('SELECT connectivity_status, COUNT(*) FROM broadband_rollout_sites GROUP BY connectivity_status')->fetchAll(PDO::FETCH_KEY_PAIR);
$withGps = count(array_filter($rows, fn($r) => $r['latitude'] !== null && $r['longitude'] !== null));
$projects = $pdo->query('SELECT id, project_name FROM projects ORDER BY project_name')->fetchAll();

$pageTitle = 'New Sites Awaiting Rollout';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>New Sites Awaiting Rollout</h1>
        <p class="subtitle">Register new broadband sites &rarr; pre-assess each site &rarr; approve for rollout &rarr; install and submit through Site Completions</p>
    </div>
    <div class="actions-row" style="margin:0;">
        <a class="btn" href="<?= APP_BASE_URL ?>/broadband/rollout_map.php<?= $filterQuery !== '' ? '?' . e($filterQuery) : '' ?>"
           title="Show the <?= $withGps ?> listed sites that have GPS coordinates on a map">&#128506; View on map (<?= $withGps ?>)</a>
        <?php if ($canManage): ?>
            <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/broadband/rollout_import.php">Import CSV / Excel</a>
            <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/broadband/rollout_form.php">+ Register new site</a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="actions-row" style="margin:0 0 10px; align-items:center;">
        <strong style="font-size:13px; margin-right:4px;">Deployment:</strong>
        <?php foreach (connectivity_statuses() as $key => $c): ?>
            <a class="btn btn-secondary btn-sm conn-chip <?= $connectivity === $key ? 'is-on' : '' ?>"
               href="?<?= e(http_build_query(array_filter(['connectivity' => $connectivity === $key ? '' : $key, 'all' => '1'] + $f, fn($v) => $v !== ''))) ?>">
                <span class="conn-dot" style="background:<?= $c['color'] ?>"></span><?= e($c['label']) ?>: <strong><?= (int)($connCounts[$key] ?? 0) ?></strong></a>
        <?php endforeach; ?>
    </div>
    <div class="actions-row" style="margin:0 0 14px;">
        <?php foreach (rollout_statuses() as $st): ?>
            <a class="btn btn-secondary btn-sm" href="?status=<?= urlencode($st) ?>"><?= e($st) ?>: <strong><?= (int)($counts[$st] ?? 0) ?></strong></a>
        <?php endforeach; ?>
    </div>
    <form method="get" class="form-grid">
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status" onchange="this.form.submit()">
                <option value="">Awaiting rollout (default)</option>
                <?php foreach (rollout_statuses() as $st): ?>
                    <option value="<?= e($st) ?>" <?= $status === $st ? 'selected' : '' ?>><?= e($st) ?></option>
                <?php endforeach; ?>
            </select>
            <label style="font-weight:400; margin-top:6px;"><input type="checkbox" name="all" value="1" <?= $showAll ? 'checked' : '' ?> onchange="this.form.submit()"> Include rolled out &amp; cancelled</label>
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
        <div class="field">
            <label for="connectivity">Deployment (New / Online / Offline)</label>
            <select id="connectivity" name="connectivity" onchange="this.form.submit()">
                <option value="">All</option>
                <?php foreach (connectivity_statuses() as $key => $c): ?>
                    <option value="<?= $key ?>" <?= $connectivity === $key ? 'selected' : '' ?>><?= e($c['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="province">Province</label>
            <select id="province" name="province" onchange="this.form.submit()">
                <option value="">All provinces</option>
                <?php foreach (sa_provinces() as $p): ?>
                    <option value="<?= e($p) ?>" <?= $province === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="q">Search</label>
            <input type="text" id="q" name="q" value="<?= e($search) ?>" placeholder="Reference, site, official ID, town…">
        </div>
        <div class="field">
            <button type="submit" class="btn btn-secondary">Filter</button>
            <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/broadband/rollout_list.php">Reset</a>
        </div>
    </form>
</div>

<div class="card">
    <?php if ($canSelect && $rows): ?>
    <form method="post" action="<?= APP_BASE_URL ?>/broadband/rollout_delete.php" id="bulkForm">
        <?= csrf_field() ?>
        <input type="hidden" name="return_query" value="<?= e($_SERVER['QUERY_STRING'] ?? '') ?>">
        <div class="actions-row" style="margin:0 0 12px; align-items:center;">
            <?php if ($canChange): ?>
                <label for="bulkConnectivity" style="margin:0; font-weight:600; font-size:13px;">Set selected to</label>
                <select id="bulkConnectivity" name="connectivity_status" style="width:auto;">
                    <?php foreach (connectivity_statuses() as $key => $c): ?>
                        <option value="<?= $key ?>"><?= e($c['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-secondary btn-sm bulk-btn" id="bulkConnBtn" disabled
                        formaction="<?= APP_BASE_URL ?>/broadband/rollout_connectivity.php" data-action="connectivity">Apply</button>
            <?php endif; ?>
            <?php if ($canManage): ?>
                <button type="submit" class="btn btn-secondary btn-sm bulk-btn" id="bulkDeleteBtn" disabled data-action="delete"
                        style="color:var(--red); border-color:#f4b7b1;">Delete selected</button>
            <?php endif; ?>
            <span class="hint" id="selectedCount" style="margin:0;">Tick sites below to select them, or the box in the heading to select all <?= count($rows) ?> shown.</span>
        </div>
    </form>
    <?php endif; ?>
    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <?php if ($canSelect && $rows): ?><th><input type="checkbox" id="selectAllSites" title="Select all shown" aria-label="Select all shown sites"></th><?php endif; ?>
            <th>Reference</th><th>Site</th><th>Deployment</th><th>Type</th><th>Project</th><th>Province / Local Mun</th><th>Village/Town</th><th>GPS</th><th>Contact details</th>
            <th>Planned rollout</th><th>Pre-assessment</th><th>Status</th><th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <?php if ($canSelect): ?>
                    <td><input type="checkbox" class="site-checkbox" form="bulkForm" name="ids[]" value="<?= (int)$r['id'] ?>"
                               data-pa="<?= $r['pa_id'] ? 1 : 0 ?>" aria-label="Select <?= e($r['site_name']) ?>"></td>
                <?php endif; ?>
                <td><?= e($r['reference_code']) ?></td>
                <td><?= e($r['site_name']) ?><?= $r['official_site_id'] ? '<br><span class="hint">ID ' . e($r['official_site_id']) . '</span>' : '' ?></td>
                <td style="white-space:nowrap;">
                    <?php $cs = connectivity_statuses()[$r['connectivity_status']] ?? connectivity_statuses()['New']; ?>
                    <span class="conn-dot" style="background:<?= $cs['color'] ?>" data-dot-for="<?= (int)$r['id'] ?>"></span>
                    <?php if ($canChange): ?>
                        <select class="conn-select" data-id="<?= (int)$r['id'] ?>" aria-label="Deployment status for <?= e($r['site_name']) ?>">
                            <?php foreach (connectivity_statuses() as $key => $c): ?>
                                <option value="<?= $key ?>" <?= $r['connectivity_status'] === $key ? 'selected' : '' ?>><?= $key ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <?= e($r['connectivity_status']) ?>
                    <?php endif; ?>
                </td>
                <td><?= e($r['site_type'] === 'Other' && $r['site_type_other'] ? $r['site_type_other'] : ($r['site_type'] ?? '—')) ?></td>
                <td><?= e($r['project_name']) ?></td>
                <td><?= e($r['province'] ?? '—') ?><?= $r['local_municipality'] ? '<br><span class="hint">' . e($r['local_municipality']) . '</span>' : '' ?></td>
                <td><?= e($r['town_village'] ?? '—') ?></td>
                <td style="white-space:nowrap;">
                    <?php if ($r['latitude'] !== null && $r['longitude'] !== null): ?>
                        <a href="https://www.google.com/maps?q=<?= urlencode($r['latitude'] . ',' . $r['longitude']) ?>" target="_blank" rel="noopener"
                           title="Open in Google Maps"><?= e(round((float)$r['latitude'], 5) . ', ' . round((float)$r['longitude'], 5)) ?></a>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td><?= e($r['contact_details'] ?? '—') ?></td>
                <td><?= $r['planned_rollout_date'] ? format_date($r['planned_rollout_date']) : '—' ?></td>
                <td>
                    <?php if (!$r['pa_id']): ?>Not started
                    <?php elseif ($r['suitable_for_rollout'] === 'Y'): ?>Suitable
                    <?php elseif ($r['suitable_for_rollout'] === 'N'): ?>Not suitable
                    <?php else: ?>In progress<?php endif; ?>
                </td>
                <td><?= rollout_status_badge($r['status']) ?></td>
                <td style="white-space:nowrap;">
                    <a class="btn btn-secondary btn-sm" href="<?= APP_BASE_URL ?>/broadband/pre_assessment.php?id=<?= (int)$r['id'] ?>">Pre-assessment</a>
                    <?php if ($canManage): ?>
                        <a href="<?= APP_BASE_URL ?>/broadband/rollout_form.php?id=<?= (int)$r['id'] ?>">Edit</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="14">No sites match these filters.<?= $canManage ? ' Use “Register new site” to add one.' : '' ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<style>
    .conn-dot { display: inline-block; width: 11px; height: 11px; border-radius: 50%; vertical-align: middle; margin-right: 5px;
        box-shadow: 0 0 0 1px rgba(0,0,0,.15); }
    .conn-select { width: auto; padding: 2px 4px; font-size: 12.5px; }
    .conn-chip.is-on { outline: 2px solid var(--brand); }
    .conn-saving { opacity: .5; }
</style>

<?php if ($canSelect && $rows): ?>
<script>
(function () {
    var COLORS = <?= json_encode(array_map(fn($c) => $c['color'], connectivity_statuses())) ?>;
    var form = document.getElementById('bulkForm');
    var token = form.querySelector('input[name="csrf_token"]').value;
    var selectAll = document.getElementById('selectAllSites');
    var delBtn = document.getElementById('bulkDeleteBtn');
    var connBtn = document.getElementById('bulkConnBtn');
    var countLabel = document.getElementById('selectedCount');
    var defaultLabel = countLabel.textContent;
    var lastAction = null;
    function boxes() { return Array.prototype.slice.call(document.querySelectorAll('.site-checkbox')); }
    function checked() { return boxes().filter(function (b) { return b.checked; }); }

    function refresh() {
        var n = checked().length, total = boxes().length;
        if (delBtn) { delBtn.disabled = n === 0; delBtn.textContent = n ? 'Delete selected (' + n + ')' : 'Delete selected'; }
        if (connBtn) { connBtn.disabled = n === 0; connBtn.textContent = n ? 'Apply to ' + n : 'Apply'; }
        countLabel.textContent = n ? n + ' of ' + total + ' selected' : defaultLabel;
        selectAll.checked = n > 0 && n === total;
        selectAll.indeterminate = n > 0 && n < total;
    }
    selectAll.addEventListener('change', function () {
        boxes().forEach(function (b) { b.checked = selectAll.checked; });
        refresh();
    });
    boxes().forEach(function (b) { b.addEventListener('change', refresh); });

    document.querySelectorAll('.bulk-btn').forEach(function (b) {
        b.addEventListener('click', function () { lastAction = b.getAttribute('data-action'); });
    });
    form.addEventListener('submit', function (e) {
        var sel = checked();
        if (!sel.length) { e.preventDefault(); return; }
        if (lastAction === 'connectivity') {
            var to = document.getElementById('bulkConnectivity').value;
            if (!confirm('Set ' + sel.length + ' site' + (sel.length === 1 ? '' : 's') + ' to ' + to + '?')) { e.preventDefault(); }
            return;
        }
        var withPa = sel.filter(function (b) { return b.getAttribute('data-pa') === '1'; }).length;
        var msg = 'Permanently delete ' + sel.length + ' site' + (sel.length === 1 ? '' : 's') + '?';
        if (withPa) {
            msg += '\n\n' + withPa + ' of them ' + (withPa === 1 ? 'has a pre-assessment' : 'have pre-assessments') +
                   ', which will be deleted too (including photos and signatures).';
        }
        msg += '\n\nThis cannot be undone.';
        if (!confirm(msg)) { e.preventDefault(); }
    });
    refresh();

    // Per-row New / Online / Offline: saved straight away, no page reload.
    document.querySelectorAll('.conn-select').forEach(function (sel) {
        var previous = sel.value;
        sel.addEventListener('change', function () {
            var id = sel.getAttribute('data-id'), value = sel.value;
            var body = new FormData();
            body.append('csrf_token', token);
            body.append('ids[]', id);
            body.append('connectivity_status', value);
            sel.classList.add('conn-saving'); sel.disabled = true;
            fetch('<?= APP_BASE_URL ?>/broadband/rollout_connectivity.php', { method: 'POST', body: body, headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.ok) { throw new Error(res.message); }
                    previous = value;
                    var dot = document.querySelector('[data-dot-for="' + id + '"]');
                    if (dot) { dot.style.background = COLORS[value]; }
                })
                .catch(function (err) {
                    sel.value = previous;
                    alert('Could not change the status: ' + (err.message || 'network error'));
                })
                .finally(function () { sel.classList.remove('conn-saving'); sel.disabled = false; });
        });
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
