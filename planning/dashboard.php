<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

sync_overdue_statuses($pdo);

$statusCounts = $pdo->query('SELECT status, COUNT(*) AS c FROM installer_allocations GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$totalAllocations = array_sum($statusCounts);

// Per-province rollup: target vs latest-reported-cumulative actual, summed across
// every allocation in that province.
$provincial = $pdo->query(
    "SELECT p.id, p.project_name, p.province,
            SUM(a.target_installations) AS target_installations,
            SUM(COALESCE((
                SELECT ap.installations_completed FROM allocation_progress_updates ap
                WHERE ap.allocation_id = a.id ORDER BY ap.period_date DESC LIMIT 1
            ), 0)) AS actual_installations,
            COUNT(*) AS allocation_count
     FROM installer_allocations a
     JOIN projects p ON p.id = a.project_id
     GROUP BY p.id, p.project_name, p.province
     ORDER BY p.project_name"
)->fetchAll();

$regional = $pdo->query(
    "SELECT p.province, a.region,
            SUM(a.target_installations) AS target_installations,
            SUM(COALESCE((
                SELECT ap.installations_completed FROM allocation_progress_updates ap
                WHERE ap.allocation_id = a.id ORDER BY ap.period_date DESC LIMIT 1
            ), 0)) AS actual_installations,
            COUNT(*) AS allocation_count,
            SUM(a.status = 'Overdue') AS overdue_count
     FROM installer_allocations a
     JOIN projects p ON p.id = a.project_id
     GROUP BY p.province, a.region
     ORDER BY p.province, a.region"
)->fetchAll();

$pageTitle = 'Planning & Dispatch — Dashboard';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Planning &amp; Dispatch &mdash; Dashboard</h1>
        <p class="subtitle">Installer allocation targets vs actual installations, by province and region &mdash; see the <a href="<?= APP_BASE_URL ?>/planning/list.php">flat register</a> to filter/search individual allocations</p>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-tile">
        <div class="stat-num"><?= number_format($totalAllocations) ?></div>
        <div class="stat-label">Total allocations</div>
    </div>
    <div class="stat-tile">
        <div class="stat-num"><?= number_format($statusCounts['Planned'] ?? 0) ?></div>
        <div class="stat-label">Planned</div>
    </div>
    <div class="stat-tile">
        <div class="stat-num"><?= number_format($statusCounts['In Progress'] ?? 0) ?></div>
        <div class="stat-label">In progress</div>
    </div>
    <div class="stat-tile">
        <div class="stat-num"><?= number_format($statusCounts['Completed'] ?? 0) ?></div>
        <div class="stat-label">Completed</div>
    </div>
    <div class="stat-tile is-overdue">
        <div class="stat-num"><?= number_format($statusCounts['Overdue'] ?? 0) ?></div>
        <div class="stat-label">Overdue</div>
    </div>
</div>

<?php if (!$provincial): ?>
    <div class="card"><p class="hint">No allocations have been dispatched yet. <a href="<?= APP_BASE_URL ?>/planning/form.php">Dispatch the first one</a>, or run the sample data seeder.</p></div>
<?php else: ?>
    <div class="card">
        <h2>Target vs actual installations, by province</h2>
        <?php
        $barW = 20; $barGap = 3; $groupGap = 22; $plotH = 200;
        $padTop = 16; $padBottom = 50; $padLeft = 44; $padRight = 16;
        $groupW = $barW * 2 + $barGap;
        $slotW = $groupW + $groupGap;
        $svgW = $padLeft + count($provincial) * $slotW + $padRight;
        $svgH = $padTop + $plotH + $padBottom;
        $rawMax = max(1, max(array_column($provincial, 'target_installations')), max(array_column($provincial, 'actual_installations')));
        $niceMax = (int)ceil($rawMax / 5) * 5;
        $baseline = $padTop + $plotH;
        ?>
        <div class="chart-legend">
            <span class="legend-item"><span class="legend-swatch" style="background:#6d6e71"></span> Target (planned)</span>
            <span class="legend-item"><span class="legend-swatch" style="background:#c15a00"></span> Actual installed</span>
        </div>
        <div class="chart-scroll">
        <svg viewBox="0 0 <?= $svgW ?> <?= $svgH ?>" width="<?= $svgW ?>" height="<?= $svgH ?>" role="img" aria-label="Target versus actual installations, by province">
            <?php for ($g = 0; $g <= 4; $g++): $gy = $padTop + $plotH - ($plotH * $g / 4); $gv = round($niceMax * $g / 4); ?>
                <line x1="<?= $padLeft ?>" y1="<?= $gy ?>" x2="<?= $svgW - $padRight ?>" y2="<?= $gy ?>" stroke="#e1e0d9" stroke-width="1" />
                <text x="<?= $padLeft - 8 ?>" y="<?= $gy + 4 ?>" text-anchor="end" font-size="11" fill="#898781"><?= number_format($gv) ?></text>
            <?php endfor; ?>
            <?php foreach ($provincial as $idx => $row):
                $x0 = $padLeft + $idx * $slotW;
                $target = (int)$row['target_installations'];
                $actual = (int)$row['actual_installations'];
                $targetH = $niceMax > 0 ? ($target / $niceMax) * $plotH : 0;
                $actualH = $niceMax > 0 ? ($actual / $niceMax) * $plotH : 0;
                $abbrev = e($row['province'] ?? $row['project_name']);
            ?>
                <path class="chart-bar" d="<?= bar_path_rounded_top($x0, $baseline - $targetH, $barW, $targetH) ?>" fill="#6d6e71">
                    <title><?= e($row['project_name']) ?>: target <?= $target ?> installations</title>
                </path>
                <path class="chart-bar" d="<?= bar_path_rounded_top($x0 + $barW + $barGap, $baseline - $actualH, $barW, $actualH) ?>" fill="#c15a00">
                    <title><?= e($row['project_name']) ?>: <?= $actual ?> of <?= $target ?> installations completed</title>
                </path>
                <?php if ($actualH > 0): ?>
                <text x="<?= $x0 + $barW + $barGap + $barW / 2 ?>" y="<?= $baseline - $actualH - 6 ?>" text-anchor="middle" font-size="11" font-weight="600" fill="#46545f"><?= $actual ?></text>
                <?php endif; ?>
                <text x="<?= $x0 + $groupW / 2 ?>" y="<?= $baseline + 18 ?>" text-anchor="middle" font-size="10" fill="#52514e"><?= $abbrev ?></text>
            <?php endforeach; ?>
            <line x1="<?= $padLeft ?>" y1="<?= $baseline ?>" x2="<?= $svgW - $padRight ?>" y2="<?= $baseline ?>" stroke="#c3c2b7" stroke-width="1" />
        </svg>
        </div>
        <div class="table-wrap" style="margin-top:14px;">
        <table>
            <thead><tr><th>Province</th><th>Allocations</th><th>Target installations</th><th>Actual completed</th><th>% of target</th></tr></thead>
            <tbody>
            <?php foreach ($provincial as $row): $pct = $row['target_installations'] > 0 ? round($row['actual_installations'] / $row['target_installations'] * 100) : null; ?>
                <tr>
                    <td><?= e($row['province'] ?? $row['project_name']) ?></td>
                    <td><?= number_format((int)$row['allocation_count']) ?></td>
                    <td><?= number_format((int)$row['target_installations']) ?></td>
                    <td><?= number_format((int)$row['actual_installations']) ?></td>
                    <td><?= $pct !== null ? $pct . '%' : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="card">
        <h2>Breakdown by region</h2>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Province</th><th>Region</th><th>Allocations</th><th>Target</th><th>Actual</th><th>Progress</th><th>Overdue</th></tr></thead>
            <tbody>
            <?php foreach ($regional as $row): ?>
                <?php $rProgress = compute_allocation_progress((int)$row['target_installations'], (float)$row['actual_installations']); ?>
                <tr>
                    <td><?= e($row['province']) ?></td>
                    <td><?= e($row['region']) ?></td>
                    <td><?= number_format((int)$row['allocation_count']) ?></td>
                    <td><?= number_format((int)$row['target_installations']) ?></td>
                    <td><?= number_format((int)$row['actual_installations']) ?></td>
                    <td><?= progress_bar($rProgress) ?></td>
                    <td><?= (int)$row['overdue_count'] > 0 ? '<span class="badge badge-overdue">' . (int)$row['overdue_count'] . '</span>' : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
