<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

sync_overdue_statuses($pdo);

$counts = $pdo->query(
    "SELECT status, COUNT(*) AS c FROM findings_register GROUP BY status"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$total = array_sum($counts);
$projectCount = (int)$pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();

$recent = $pdo->query(
    "SELECT f.id, f.reference_code, f.status, f.current_deadline, p.project_name
     FROM findings_register f
     JOIN projects p ON p.id = f.project_id
     ORDER BY f.created_at DESC
     LIMIT 8"
)->fetchAll();

$riskCounts = $pdo->query(
    "SELECT status, COUNT(*) AS c FROM risks GROUP BY status"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$riskTotal = array_sum($riskCounts);
$criticalOpenRisks = (int)$pdo->query(
    "SELECT COUNT(*) FROM risks WHERE risk_rating = 'Critical' AND status <> 'Closed'"
)->fetchColumn();

$recentRisks = $pdo->query(
    "SELECT r.id, r.reference_code, r.status, r.risk_rating, r.current_deadline, p.project_name
     FROM risks r
     JOIN projects p ON p.id = r.project_id
     ORDER BY r.created_at DESC
     LIMIT 8"
)->fetchAll();

$indicatorCounts = $pdo->query(
    "SELECT status, COUNT(*) AS c FROM indicators GROUP BY status"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$indicatorTotal = array_sum($indicatorCounts);

$recentIndicators = $pdo->query(
    "SELECT i.id, i.reference_code, i.indicator_name, i.status, i.baseline_value, i.target_value, p.project_name,
            (SELECT actual_value FROM indicator_actuals a WHERE a.indicator_id = i.id ORDER BY a.period_date DESC LIMIT 1) AS latest_actual
     FROM indicators i
     JOIN projects p ON p.id = i.project_id
     ORDER BY i.created_at DESC
     LIMIT 8"
)->fetchAll();

$siteCounts = $pdo->query(
    "SELECT status, COUNT(*) AS c FROM sites GROUP BY status"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$siteTotal = array_sum($siteCounts);
$householdsReadyForPayment = (int)$pdo->query(
    "SELECT COALESCE(SUM(households_connected), 0) FROM sites WHERE status = 'Approved'"
)->fetchColumn();

$recentSites = $pdo->query(
    "SELECT s.id, s.site_code, s.site_name, s.status, s.households_connected, p.project_name
     FROM sites s
     JOIN projects p ON p.id = s.project_id
     ORDER BY s.submitted_at DESC
     LIMIT 8"
)->fetchAll();

$provincial = $pdo->query(
    "SELECT p.id, p.project_code, p.project_name, p.target_sites,
            (SELECT COUNT(*) FROM sites s WHERE s.project_id = p.id AND s.status = 'Approved') AS actual_sites
     FROM projects p
     WHERE p.target_sites IS NOT NULL
     ORDER BY p.project_name"
)->fetchAll();

$indigentTotal = (int)$pdo->query('SELECT COUNT(*) FROM indigent_applications')->fetchColumn();
$indigentWithTv = (int)$pdo->query('SELECT COUNT(*) FROM indigent_applications WHERE has_functional_tv = 1')->fetchColumn();
$indigentProvinceCount = (int)$pdo->query('SELECT COUNT(DISTINCT province) FROM indigent_applications')->fetchColumn();
$recentIndigent = $pdo->query(
    "SELECT a.id, a.reference_code, a.first_name, a.surname, a.province, a.village_name, a.captured_at
     FROM indigent_applications a
     ORDER BY a.captured_at DESC
     LIMIT 8"
)->fetchAll();

$allocationCounts = $pdo->query('SELECT status, COUNT(*) AS c FROM installer_allocations GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$allocationTotal = array_sum($allocationCounts);
$allocationTargetTotal = (int)$pdo->query('SELECT COALESCE(SUM(target_installations), 0) FROM installer_allocations')->fetchColumn();
$allocationActualTotal = (int)$pdo->query(
    "SELECT COALESCE(SUM(latest), 0) FROM (
        SELECT a.id, COALESCE((
            SELECT ap.installations_completed FROM allocation_progress_updates ap
            WHERE ap.allocation_id = a.id ORDER BY ap.period_date DESC LIMIT 1
        ), 0) AS latest
        FROM installer_allocations a
    ) t"
)->fetchColumn();
$recentAllocations = $pdo->query(
    "SELECT a.id, a.reference_code, a.region, a.status, a.target_installations, a.current_deadline,
            p.province, p.project_name, inst.full_name AS installer_name
     FROM installer_allocations a
     JOIN projects p ON p.id = a.project_id
     JOIN users inst ON inst.id = a.installer_id
     ORDER BY a.created_at DESC
     LIMIT 8"
)->fetchAll();

$pageTitle = 'Dashboard';
include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Dashboard</h1>
        <p class="subtitle">Overview of monitoring, evaluation &amp; learning activity</p>
    </div>
</div>

<h2>Findings &amp; Lessons Register</h2>
<div class="stats-grid">
    <div class="stat-tile">
        <div class="stat-num"><?= $total ?></div>
        <div class="stat-label">Total findings</div>
    </div>
    <div class="stat-tile">
        <div class="stat-num"><?= $counts['Open'] ?? 0 ?></div>
        <div class="stat-label">Open</div>
    </div>
    <div class="stat-tile">
        <div class="stat-num"><?= $counts['In Progress'] ?? 0 ?></div>
        <div class="stat-label">In progress</div>
    </div>
    <div class="stat-tile is-overdue">
        <div class="stat-num"><?= $counts['Overdue'] ?? 0 ?></div>
        <div class="stat-label">Overdue, unconfirmed</div>
    </div>
    <div class="stat-tile">
        <div class="stat-num"><?= $counts['Closed'] ?? 0 ?></div>
        <div class="stat-label">Closed</div>
    </div>
    <div class="stat-tile">
        <div class="stat-num"><?= $projectCount ?></div>
        <div class="stat-label">Projects</div>
    </div>
</div>

<div class="card">
    <h2>Recently added findings</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Reference</th><th>Project</th><th>Deadline</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
            <tr>
                <td><?= e($r['reference_code']) ?></td>
                <td><?= e($r['project_name']) ?></td>
                <td><?= format_date($r['current_deadline']) ?></td>
                <td><?= status_badge($r['status']) ?></td>
                <td><a href="<?= APP_BASE_URL ?>/findings/view.php?id=<?= (int)$r['id'] ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$recent): ?>
            <tr><td colspan="5">No findings recorded yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<h2>Risk Register</h2>
<div class="stats-grid">
    <div class="stat-tile">
        <div class="stat-num"><?= $riskTotal ?></div>
        <div class="stat-label">Total risks</div>
    </div>
    <div class="stat-tile">
        <div class="stat-num"><?= $riskCounts['Open'] ?? 0 ?></div>
        <div class="stat-label">Open</div>
    </div>
    <div class="stat-tile">
        <div class="stat-num"><?= $riskCounts['In Progress'] ?? 0 ?></div>
        <div class="stat-label">In progress</div>
    </div>
    <div class="stat-tile is-overdue">
        <div class="stat-num"><?= $riskCounts['Overdue'] ?? 0 ?></div>
        <div class="stat-label">Overdue, unconfirmed</div>
    </div>
    <div class="stat-tile is-overdue">
        <div class="stat-num"><?= $criticalOpenRisks ?></div>
        <div class="stat-label">Critical &amp; unresolved</div>
    </div>
    <div class="stat-tile">
        <div class="stat-num"><?= $riskCounts['Closed'] ?? 0 ?></div>
        <div class="stat-label">Closed</div>
    </div>
</div>

<div class="card">
    <h2>Recently logged risks</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Reference</th><th>Project</th><th>Rating</th><th>Deadline</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($recentRisks as $r): ?>
            <tr>
                <td><?= e($r['reference_code']) ?></td>
                <td><?= e($r['project_name']) ?></td>
                <td><?= risk_rating_badge($r['risk_rating']) ?></td>
                <td><?= format_date($r['current_deadline']) ?></td>
                <td><?= status_badge($r['status']) ?></td>
                <td><a href="<?= APP_BASE_URL ?>/risks/view.php?id=<?= (int)$r['id'] ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$recentRisks): ?>
            <tr><td colspan="6">No risks logged yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<h2>Results Framework</h2>
<div class="stats-grid">
    <div class="stat-tile">
        <div class="stat-num"><?= $indicatorTotal ?></div>
        <div class="stat-label">Total indicators</div>
    </div>
    <div class="stat-tile">
        <div class="stat-num"><?= $indicatorCounts['Open'] ?? 0 ?></div>
        <div class="stat-label">Open</div>
    </div>
    <div class="stat-tile">
        <div class="stat-num"><?= $indicatorCounts['In Progress'] ?? 0 ?></div>
        <div class="stat-label">In progress</div>
    </div>
    <div class="stat-tile is-overdue">
        <div class="stat-num"><?= $indicatorCounts['Overdue'] ?? 0 ?></div>
        <div class="stat-label">Overdue, unconfirmed</div>
    </div>
    <div class="stat-tile">
        <div class="stat-num"><?= $indicatorCounts['Achieved'] ?? 0 ?></div>
        <div class="stat-label">Achieved</div>
    </div>
</div>

<div class="card">
    <h2>Recently added indicators</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Reference</th><th>Indicator</th><th>Project</th><th>Progress</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($recentIndicators as $i): ?>
            <?php $progress = compute_indicator_progress((float)$i['baseline_value'], (float)$i['target_value'], $i['latest_actual'] !== null ? (float)$i['latest_actual'] : null); ?>
            <tr>
                <td><?= e($i['reference_code']) ?></td>
                <td><?= e($i['indicator_name']) ?></td>
                <td><?= e($i['project_name']) ?></td>
                <td><?= progress_bar($progress) ?></td>
                <td><?= status_badge($i['status']) ?></td>
                <td><a href="<?= APP_BASE_URL ?>/indicators/view.php?id=<?= (int)$i['id'] ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$recentIndicators): ?>
            <tr><td colspan="6">No indicators defined yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<h2>Site Completions &amp; Payment Approval</h2>
<div class="stats-grid">
    <div class="stat-tile">
        <div class="stat-num"><?= $siteTotal ?></div>
        <div class="stat-label">Total sites</div>
    </div>
    <div class="stat-tile">
        <div class="stat-num"><?= $siteCounts['Submitted'] ?? 0 ?></div>
        <div class="stat-label">Awaiting verification</div>
    </div>
    <div class="stat-tile">
        <div class="stat-num"><?= $siteCounts['Verified'] ?? 0 ?></div>
        <div class="stat-label">Awaiting approval</div>
    </div>
    <div class="stat-tile">
        <div class="stat-num"><?= $siteCounts['Approved'] ?? 0 ?></div>
        <div class="stat-label">Ready for payment</div>
    </div>
    <div class="stat-tile">
        <div class="stat-num"><?= number_format($householdsReadyForPayment) ?></div>
        <div class="stat-label">Households ready for payment</div>
    </div>
    <div class="stat-tile is-overdue">
        <div class="stat-num"><?= $siteCounts['Rejected'] ?? 0 ?></div>
        <div class="stat-label">Rejected</div>
    </div>
</div>

<h3>Provincial deployment &mdash; yearly target vs actual</h3>
<?php if (!$provincial): ?>
    <div class="card"><p class="hint">No project has a yearly site target set yet. Set one via Projects, or run the sample data seeder (<code>database/seed_sample_data.php</code>).</p></div>
<?php else: ?>
    <?php
    $totalTargetSites = array_sum(array_column($provincial, 'target_sites'));
    $totalActualSites = array_sum(array_column($provincial, 'actual_sites'));
    $pctAchieved = $totalTargetSites > 0 ? round($totalActualSites / $totalTargetSites * 100, 1) : null;
    ?>
    <div class="stats-grid">
        <div class="stat-tile">
            <div class="stat-num"><?= number_format($totalTargetSites) ?></div>
            <div class="stat-label">FY site target, all provinces</div>
        </div>
        <div class="stat-tile">
            <div class="stat-num"><?= number_format($totalActualSites) ?></div>
            <div class="stat-label">Sites actually deployed</div>
        </div>
        <div class="stat-tile">
            <div class="stat-num"><?= $pctAchieved !== null ? $pctAchieved . '%' : '—' ?></div>
            <div class="stat-label">Of target achieved</div>
        </div>
    </div>

    <div class="card">
        <h2>Target vs actual sites deployed, by province</h2>
        <?php
        $barW = 20; $barGap = 3; $groupGap = 22; $plotH = 200;
        $padTop = 16; $padBottom = 50; $padLeft = 44; $padRight = 16;
        $groupW = $barW * 2 + $barGap;
        $slotW = $groupW + $groupGap;
        $svgW = $padLeft + count($provincial) * $slotW + $padRight;
        $svgH = $padTop + $plotH + $padBottom;
        $rawMax = max(1, max(array_column($provincial, 'target_sites')), max(array_column($provincial, 'actual_sites')));
        $niceMax = (int)ceil($rawMax / 5) * 5;
        $baseline = $padTop + $plotH;
        ?>
        <div class="chart-legend">
            <span class="legend-item"><span class="legend-swatch" style="background:#6d6e71"></span> Target (planned)</span>
            <span class="legend-item"><span class="legend-swatch" style="background:#c15a00"></span> Actual deployed</span>
        </div>
        <div class="chart-scroll">
        <svg viewBox="0 0 <?= $svgW ?> <?= $svgH ?>" width="<?= $svgW ?>" height="<?= $svgH ?>" role="img" aria-label="Target versus actual sites deployed, by province">
            <?php for ($g = 0; $g <= 4; $g++): $gy = $padTop + $plotH - ($plotH * $g / 4); $gv = round($niceMax * $g / 4); ?>
                <line x1="<?= $padLeft ?>" y1="<?= $gy ?>" x2="<?= $svgW - $padRight ?>" y2="<?= $gy ?>" stroke="#e1e0d9" stroke-width="1" />
                <text x="<?= $padLeft - 8 ?>" y="<?= $gy + 4 ?>" text-anchor="end" font-size="11" fill="#898781"><?= number_format($gv) ?></text>
            <?php endfor; ?>
            <?php foreach ($provincial as $idx => $row):
                $x0 = $padLeft + $idx * $slotW;
                $target = (int)$row['target_sites'];
                $actual = (int)$row['actual_sites'];
                $targetH = $niceMax > 0 ? ($target / $niceMax) * $plotH : 0;
                $actualH = $niceMax > 0 ? ($actual / $niceMax) * $plotH : 0;
                $abbrev = strtoupper(str_replace('USAASA-', '', $row['project_code']));
            ?>
                <path class="chart-bar" d="<?= bar_path_rounded_top($x0, $baseline - $targetH, $barW, $targetH) ?>" fill="#6d6e71">
                    <title><?= e($row['project_name']) ?>: target <?= $target ?> sites</title>
                </path>
                <path class="chart-bar" d="<?= bar_path_rounded_top($x0 + $barW + $barGap, $baseline - $actualH, $barW, $actualH) ?>" fill="#c15a00">
                    <title><?= e($row['project_name']) ?>: <?= $actual ?> of <?= $target ?> sites deployed</title>
                </path>
                <?php if ($actualH > 0): ?>
                <text x="<?= $x0 + $barW + $barGap + $barW / 2 ?>" y="<?= $baseline - $actualH - 6 ?>" text-anchor="middle" font-size="11" font-weight="600" fill="#46545f"><?= $actual ?></text>
                <?php endif; ?>
                <text x="<?= $x0 + $groupW / 2 ?>" y="<?= $baseline + 18 ?>" text-anchor="middle" font-size="11" fill="#52514e"><?= e($abbrev) ?></text>
            <?php endforeach; ?>
            <line x1="<?= $padLeft ?>" y1="<?= $baseline ?>" x2="<?= $svgW - $padRight ?>" y2="<?= $baseline ?>" stroke="#c3c2b7" stroke-width="1" />
        </svg>
        </div>
        <div class="table-wrap" style="margin-top:14px;">
        <table>
            <thead><tr><th>Province</th><th>Target sites (FY)</th><th>Actual deployed</th><th>% of target</th></tr></thead>
            <tbody>
            <?php foreach ($provincial as $row): $pct = $row['target_sites'] > 0 ? round($row['actual_sites'] / $row['target_sites'] * 100) : null; ?>
                <tr>
                    <td><?= e($row['project_name']) ?></td>
                    <td><?= number_format((int)$row['target_sites']) ?></td>
                    <td><?= number_format((int)$row['actual_sites']) ?></td>
                    <td><?= $pct !== null ? $pct . '%' : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="card">
        <h2>Share of deployed sites by province</h2>
        <?php
        $pieData = $provincial;
        usort($pieData, function ($a, $b) { return $b['actual_sites'] <=> $a['actual_sites']; });
        $pieTotal = array_sum(array_column($pieData, 'actual_sites'));
        $cx = 150; $cy = 140; $r = 100; $labelR = $r + 30;
        $cursor = 0;
        ?>
        <?php if ($pieTotal <= 0): ?>
            <p class="hint">No sites have been approved yet, so there's nothing to chart.</p>
        <?php else: ?>
        <div class="pie-wrap">
        <svg viewBox="0 0 340 300" width="340" height="300" role="img" aria-label="Share of deployed sites by province">
            <?php foreach ($pieData as $rank => $row):
                if ((int)$row['actual_sites'] <= 0) { continue; }
                $pct = $row['actual_sites'] / $pieTotal * 100;
                $startDeg = $cursor / $pieTotal * 360;
                $cursor += $row['actual_sites'];
                $endDeg = $cursor / $pieTotal * 360;
                $midDeg = ($startDeg + $endDeg) / 2;
                $opacity = max(0.3, 1 - ($rank * 0.08));
                $abbrev = strtoupper(str_replace('USAASA-', '', $row['project_code']));
                [$lx, $ly] = polar_to_cartesian($cx, $cy, $labelR, $midDeg);
                [$ex, $ey] = polar_to_cartesian($cx, $cy, $r + 4, $midDeg);
                $anchor = $lx > $cx + 4 ? 'start' : ($lx < $cx - 4 ? 'end' : 'middle');
                $dx = $anchor === 'start' ? 3 : ($anchor === 'end' ? -3 : 0);
            ?>
                <path class="chart-slice" d="<?= pie_slice_path($cx, $cy, $r, $startDeg, $endDeg) ?>" fill="#f58220" fill-opacity="<?= $opacity ?>">
                    <title><?= e($row['project_name']) ?>: <?= (int)$row['actual_sites'] ?> sites (<?= round($pct, 1) ?>%)</title>
                </path>
                <line x1="<?= $ex ?>" y1="<?= $ey ?>" x2="<?= $lx ?>" y2="<?= $ly ?>" stroke="#c3c2b7" stroke-width="1" />
                <text x="<?= $lx + $dx ?>" y="<?= $ly + 4 ?>" text-anchor="<?= $anchor ?>" font-size="11" fill="#46545f"><?= e($abbrev) ?> <?= round($pct) ?>%</text>
            <?php endforeach; ?>
        </svg>
        </div>
        <div class="table-wrap" style="margin-top:14px;">
        <table>
            <thead><tr><th>Province</th><th>Sites deployed</th><th>Share of total deployed</th></tr></thead>
            <tbody>
            <?php foreach ($pieData as $row): $pct = $pieTotal > 0 ? round($row['actual_sites'] / $pieTotal * 100, 1) : 0; ?>
                <tr>
                    <td><?= e($row['project_name']) ?></td>
                    <td><?= number_format((int)$row['actual_sites']) ?></td>
                    <td><?= $pct ?>%</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card">
    <h2>Recently submitted sites</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Reference</th><th>Site</th><th>Project</th><th>Households</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($recentSites as $s): ?>
            <tr>
                <td><?= e($s['site_code']) ?></td>
                <td><?= e($s['site_name']) ?></td>
                <td><?= e($s['project_name']) ?></td>
                <td><?= number_format((int)$s['households_connected']) ?></td>
                <td><?= site_status_badge($s['status']) ?></td>
                <td><a href="<?= APP_BASE_URL ?>/sites/view.php?id=<?= (int)$s['id'] ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$recentSites): ?>
            <tr><td colspan="6">No site completions submitted yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:10px;">
    <h3 style="margin:0;">Indigent Applications</h3>
    <?php if (in_array(current_user()['role'], ['admin', 'manager', 'field_worker', 'indigent_capturer'], true)): ?>
        <a class="btn btn-orange" href="<?= APP_BASE_URL ?>/indigent/form.php">+ New Indigent Beneficiary Application</a>
    <?php endif; ?>
</div>
<div class="stats-grid">
    <div class="stat-tile">
        <div class="stat-num"><?= $indigentTotal ?></div>
        <div class="stat-label">Applications captured</div>
    </div>
    <div class="stat-tile">
        <div class="stat-num"><?= $indigentWithTv ?></div>
        <div class="stat-label">With a functional TV</div>
    </div>
    <div class="stat-tile">
        <div class="stat-num"><?= $indigentProvinceCount ?></div>
        <div class="stat-label">Provinces represented</div>
    </div>
</div>

<div class="card">
    <h2>Recently captured applications</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Reference</th><th>Name</th><th>Province</th><th>Village</th><th>Captured</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($recentIndigent as $a): ?>
            <tr>
                <td><?= e($a['reference_code']) ?></td>
                <td><?= e($a['first_name']) ?> <?= e($a['surname']) ?></td>
                <td><?= e($a['province']) ?></td>
                <td><?= e($a['village_name']) ?></td>
                <td><?= format_date($a['captured_at']) ?></td>
                <td><a href="<?= APP_BASE_URL ?>/indigent/view.php?id=<?= (int)$a['id'] ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$recentIndigent): ?>
            <tr><td colspan="6">No applications captured yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:10px; margin-top:28px;">
    <h3 style="margin:0;">Planning &amp; Dispatch</h3>
    <div class="actions-row">
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/planning/dashboard.php">View dashboard</a>
        <?php if (in_array(current_user()['role'], ['admin', 'manager', 'planner'], true)): ?>
            <a class="btn" href="<?= APP_BASE_URL ?>/planning/form.php">+ New allocation</a>
        <?php endif; ?>
    </div>
</div>
<div class="stats-grid">
    <div class="stat-tile">
        <div class="stat-num"><?= number_format($allocationTotal) ?></div>
        <div class="stat-label">Allocations dispatched</div>
    </div>
    <div class="stat-tile">
        <div class="stat-num"><?= number_format($allocationTargetTotal) ?></div>
        <div class="stat-label">Target installations</div>
    </div>
    <div class="stat-tile">
        <div class="stat-num"><?= number_format($allocationActualTotal) ?></div>
        <div class="stat-label">Actual installed (latest reported)</div>
    </div>
    <div class="stat-tile is-overdue">
        <div class="stat-num"><?= number_format($allocationCounts['Overdue'] ?? 0) ?></div>
        <div class="stat-label">Overdue</div>
    </div>
</div>

<div class="card">
    <h2>Recently dispatched allocations</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Reference</th><th>Province</th><th>Region</th><th>Installer</th><th>Target</th><th>Deadline</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($recentAllocations as $a): ?>
            <tr>
                <td><?= e($a['reference_code']) ?></td>
                <td><?= e($a['province'] ?? $a['project_name']) ?></td>
                <td><?= e($a['region']) ?></td>
                <td><?= e($a['installer_name']) ?></td>
                <td><?= number_format((int)$a['target_installations']) ?></td>
                <td><?= format_date($a['current_deadline']) ?></td>
                <td><?= status_badge($a['status']) ?></td>
                <td><a href="<?= APP_BASE_URL ?>/planning/view.php?id=<?= (int)$a['id'] ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$recentAllocations): ?>
            <tr><td colspan="8">No allocations dispatched yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
