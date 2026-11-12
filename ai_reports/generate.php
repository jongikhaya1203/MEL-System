<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'manager']);

$currentYear = (int)date('Y');
$currentFiscalYear = (int)date('n') >= 4 ? $currentYear : $currentYear - 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $periodType = $_POST['period_type'] ?? '';
    if (!in_array($periodType, ['Monthly', 'Quarterly', 'Half-Yearly', 'Yearly'], true)) {
        flash('error', 'Please choose a valid period type.');
        redirect('/ai_reports/generate.php');
    }

    if ($periodType === 'Monthly') {
        $year = (int)($_POST['month_year'] ?? 0);
        $month = (int)($_POST['month'] ?? 0);
        if ($year < 2000 || $month < 1 || $month > 12) {
            flash('error', 'Please choose a valid month and year.');
            redirect('/ai_reports/generate.php');
        }
        [$start, $end, $label] = monthly_period_dates($year, $month);
    } else {
        $fiscalYear = (int)($_POST['fiscal_year'] ?? 0);
        if ($fiscalYear < 2000) {
            flash('error', 'Please choose a valid fiscal year.');
            redirect('/ai_reports/generate.php');
        }
        $subPeriod = null;
        if ($periodType === 'Quarterly') {
            $subPeriod = (int)($_POST['quarter'] ?? 0);
            if ($subPeriod < 1 || $subPeriod > 4) {
                flash('error', 'Please choose a valid quarter.');
                redirect('/ai_reports/generate.php');
            }
        } elseif ($periodType === 'Half-Yearly') {
            $subPeriod = (int)($_POST['half'] ?? 0);
            if ($subPeriod < 1 || $subPeriod > 2) {
                flash('error', 'Please choose a valid half.');
                redirect('/ai_reports/generate.php');
            }
        }
        [$start, $end, $label] = fiscal_period_dates($periodType, $fiscalYear, $subPeriod);
    }

    $snapshot = generate_ai_report($pdo, $start, $end);

    $stmt = $pdo->prepare(
        'INSERT INTO ai_reports (period_type, period_label, period_start, period_end, snapshot, generated_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$periodType, $label, $start, $end, json_encode($snapshot), current_user()['id']]);

    flash('success', "Report generated for $label.");
    redirect('/ai_reports/view.php?id=' . $pdo->lastInsertId());
}

$pageTitle = 'Generate AI Report';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Generate AI Report</h1>
        <p class="subtitle">Automated, integrated report across every module &mdash; no external AI call, every number is a live aggregate and every sentence is computed from it</p>
    </div>
</div>

<div class="card">
    <form method="post" id="generateForm">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="field">
                <label for="period_type">Period type</label>
                <select id="period_type" name="period_type">
                    <option value="Monthly">Monthly</option>
                    <option value="Quarterly">Quarterly</option>
                    <option value="Half-Yearly">Half-Yearly</option>
                    <option value="Yearly">Yearly</option>
                </select>
            </div>
            <div class="field"></div>

            <div class="field period-field period-monthly">
                <label for="month">Month</label>
                <select id="month" name="month">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m === (int)date('n') ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="field period-field period-monthly">
                <label for="month_year">Year</label>
                <input type="number" id="month_year" name="month_year" value="<?= $currentYear ?>" min="2000" max="2100">
            </div>

            <div class="field period-field period-quarterly period-half-yearly period-yearly" style="display:none;">
                <label for="fiscal_year">Fiscal year (starting April)</label>
                <input type="number" id="fiscal_year" name="fiscal_year" value="<?= $currentFiscalYear ?>" min="2000" max="2100">
                <p class="hint">e.g. 2026 means the fiscal year 2026/27 (1 Apr 2026 &ndash; 31 Mar 2027).</p>
            </div>
            <div class="field period-field period-quarterly" style="display:none;">
                <label for="quarter">Quarter</label>
                <select id="quarter" name="quarter">
                    <option value="1">Q1 (Apr&ndash;Jun)</option>
                    <option value="2">Q2 (Jul&ndash;Sep)</option>
                    <option value="3">Q3 (Oct&ndash;Dec)</option>
                    <option value="4">Q4 (Jan&ndash;Mar)</option>
                </select>
            </div>
            <div class="field period-field period-half-yearly" style="display:none;">
                <label for="half">Half</label>
                <select id="half" name="half">
                    <option value="1">H1 (Apr&ndash;Sep)</option>
                    <option value="2">H2 (Oct&ndash;Mar)</option>
                </select>
            </div>
        </div>
        <p class="hint">Integrates Findings &amp; Lessons, Risk Register, Results Framework, Site Completions, Indigent Applications, and Planning &amp; Dispatch into one report, with a project/programme progress summary and an overall executive summary.</p>
        <button type="submit" class="btn">Generate report</button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/ai_reports/list.php">Cancel</a>
    </form>
</div>

<script>
(function () {
    var typeSelect = document.getElementById('period_type');
    function updateFields() {
        var type = typeSelect.value;
        var slug = type.toLowerCase().replace(/\s+/g, '-');
        document.querySelectorAll('.period-field').forEach(function (el) {
            el.style.display = el.classList.contains('period-' + slug) ? '' : 'none';
        });
    }
    typeSelect.addEventListener('change', updateFields);
    updateFields();
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
