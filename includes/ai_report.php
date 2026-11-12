<?php
/**
 * AI Report engine — automated, rule-based cross-module integrated
 * reporting (Monthly / Quarterly / Half-Yearly / Yearly). "Automated" is
 * the operative word: every number here comes from a real SQL aggregate,
 * and every narrative sentence is template text filled in from those
 * numbers — there is no call to an external AI service, consistent with
 * this app's zero-external-dependency design (confirmed with the user
 * before building this).
 *
 * Quarterly/Half-Yearly/Yearly periods use South Africa's government
 * fiscal year (1 April – 31 March), matching the "Q2 2025/26"-style
 * labels already used in this app's indicator actuals. Monthly periods
 * use plain calendar months, since that's the more intuitive unit for a
 * month-by-month report even inside an org that budgets by fiscal year.
 */

/** [start, end, label] for a plain calendar month. */
function monthly_period_dates(int $year, int $month): array
{
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));
    $label = date('F Y', strtotime($start));
    return [$start, $end, $label];
}

/**
 * [start, end, label] for a fiscal period. $fiscalYearStart is the
 * calendar year the fiscal year BEGINS in (e.g. 2026 => FY "2026/27",
 * running 2026-04-01 to 2027-03-31). $subPeriod is 1-4 for quarters
 * (Q1 Apr-Jun ... Q4 Jan-Mar), 1-2 for halves (H1 Apr-Sep, H2 Oct-Mar),
 * or null for the full fiscal year.
 */
function fiscal_period_dates(string $periodType, int $fiscalYearStart, ?int $subPeriod): array
{
    $fyLabel = $fiscalYearStart . '/' . substr((string)($fiscalYearStart + 1), -2);
    $y1 = $fiscalYearStart;
    $y2 = $fiscalYearStart + 1;

    if ($periodType === 'Quarterly') {
        $quarters = [
            1 => [sprintf('%04d-04-01', $y1), sprintf('%04d-06-30', $y1)],
            2 => [sprintf('%04d-07-01', $y1), sprintf('%04d-09-30', $y1)],
            3 => [sprintf('%04d-10-01', $y1), sprintf('%04d-12-31', $y1)],
            4 => [sprintf('%04d-01-01', $y2), sprintf('%04d-03-31', $y2)],
        ];
        [$start, $end] = $quarters[$subPeriod] ?? $quarters[1];
        return [$start, $end, "Q$subPeriod $fyLabel"];
    }
    if ($periodType === 'Half-Yearly') {
        $halves = [
            1 => [sprintf('%04d-04-01', $y1), sprintf('%04d-09-30', $y1)],
            2 => [sprintf('%04d-10-01', $y1), sprintf('%04d-03-31', $y2)],
        ];
        [$start, $end] = $halves[$subPeriod] ?? $halves[1];
        return [$start, $end, "H$subPeriod $fyLabel"];
    }
    // Yearly
    return [sprintf('%04d-04-01', $y1), sprintf('%04d-03-31', $y2), "FY $fyLabel"];
}

/** Rounds a ratio to a percentage, or null if there's nothing to divide. */
function ai_pct(int $num, int $den): ?float
{
    return $den > 0 ? round($num / $den * 100, 1) : null;
}

function ai_pct_text(?float $pct): string
{
    return $pct === null ? 'n/a' : $pct . '%';
}

// ---------------------------------------------------------------------------
// Per-module aggregation. Each function takes the PDO handle and the
// period's [start, end] (inclusive, Y-m-d) and returns a plain array of
// numbers + a handful of representative rows — everything the narrative
// functions and the report template need, nothing live-queried again later.
// ---------------------------------------------------------------------------

function ai_report_findings(PDO $pdo, string $start, string $end): array
{
    $new = $pdo->prepare("SELECT f.reference_code, f.evidence_based_finding, p.project_name, f.status
        FROM findings_register f JOIN projects p ON p.id = f.project_id
        WHERE f.created_at BETWEEN ? AND ? ORDER BY f.created_at DESC LIMIT 10");
    $new->execute([$start, $end . ' 23:59:59']);
    $newRows = $new->fetchAll();

    $newCountStmt = $pdo->prepare('SELECT COUNT(*) FROM findings_register WHERE created_at BETWEEN ? AND ?');
    $newCountStmt->execute([$start, $end . ' 23:59:59']);
    $newCount = (int)$newCountStmt->fetchColumn();

    $closedCountStmt = $pdo->prepare("SELECT COUNT(*) FROM findings_register WHERE status = 'Closed' AND closed_at BETWEEN ? AND ?");
    $closedCountStmt->execute([$start, $end . ' 23:59:59']);
    $closedCount = (int)$closedCountStmt->fetchColumn();

    $overdueCount = (int)$pdo->query("SELECT COUNT(*) FROM findings_register WHERE status = 'Overdue'")->fetchColumn();

    return ['new_count' => $newCount, 'closed_count' => $closedCount, 'overdue_count' => $overdueCount, 'new_rows' => $newRows];
}

function ai_report_risks(PDO $pdo, string $start, string $end): array
{
    $new = $pdo->prepare("SELECT r.reference_code, r.risk_description, r.risk_rating, p.project_name
        FROM risks r JOIN projects p ON p.id = r.project_id
        WHERE r.created_at BETWEEN ? AND ? ORDER BY r.created_at DESC LIMIT 10");
    $new->execute([$start, $end . ' 23:59:59']);
    $newRows = $new->fetchAll();

    $newCountStmt = $pdo->prepare('SELECT COUNT(*) FROM risks WHERE created_at BETWEEN ? AND ?');
    $newCountStmt->execute([$start, $end . ' 23:59:59']);
    $newCount = (int)$newCountStmt->fetchColumn();

    $newCriticalStmt = $pdo->prepare("SELECT COUNT(*) FROM risks WHERE created_at BETWEEN ? AND ? AND risk_rating = 'Critical'");
    $newCriticalStmt->execute([$start, $end . ' 23:59:59']);
    $newCritical = (int)$newCriticalStmt->fetchColumn();

    $closedCountStmt = $pdo->prepare("SELECT COUNT(*) FROM risks WHERE status = 'Closed' AND closed_at BETWEEN ? AND ?");
    $closedCountStmt->execute([$start, $end . ' 23:59:59']);
    $closedCount = (int)$closedCountStmt->fetchColumn();

    $overdueCount = (int)$pdo->query("SELECT COUNT(*) FROM risks WHERE status = 'Overdue'")->fetchColumn();
    $criticalOpenCount = (int)$pdo->query("SELECT COUNT(*) FROM risks WHERE risk_rating = 'Critical' AND status <> 'Closed'")->fetchColumn();

    return [
        'new_count' => $newCount, 'new_critical' => $newCritical, 'closed_count' => $closedCount,
        'overdue_count' => $overdueCount, 'critical_open_count' => $criticalOpenCount, 'new_rows' => $newRows,
    ];
}

function ai_report_indicators(PDO $pdo, string $start, string $end): array
{
    $actualsStmt = $pdo->prepare(
        "SELECT a.period_label, a.actual_value, i.indicator_name, i.reference_code, p.project_name
         FROM indicator_actuals a JOIN indicators i ON i.id = a.indicator_id JOIN projects p ON p.id = i.project_id
         WHERE a.period_date BETWEEN ? AND ? ORDER BY a.period_date DESC LIMIT 10"
    );
    $actualsStmt->execute([$start, $end]);
    $actualRows = $actualsStmt->fetchAll();

    $actualsCountStmt = $pdo->prepare('SELECT COUNT(*) FROM indicator_actuals WHERE period_date BETWEEN ? AND ?');
    $actualsCountStmt->execute([$start, $end]);
    $actualsCount = (int)$actualsCountStmt->fetchColumn();

    $achievedStmt = $pdo->prepare("SELECT COUNT(*) FROM indicators WHERE status = 'Achieved' AND confirmed_at BETWEEN ? AND ?");
    $achievedStmt->execute([$start, $end . ' 23:59:59']);
    $achievedCount = (int)$achievedStmt->fetchColumn();

    $overdueCount = (int)$pdo->query("SELECT COUNT(*) FROM indicators WHERE status = 'Overdue'")->fetchColumn();
    $totalIndicators = (int)$pdo->query('SELECT COUNT(*) FROM indicators')->fetchColumn();

    return [
        'actuals_count' => $actualsCount, 'achieved_count' => $achievedCount, 'overdue_count' => $overdueCount,
        'total_indicators' => $totalIndicators, 'actual_rows' => $actualRows,
    ];
}

function ai_report_sites(PDO $pdo, string $start, string $end): array
{
    $submittedStmt = $pdo->prepare('SELECT COUNT(*) FROM sites WHERE submitted_at BETWEEN ? AND ?');
    $submittedStmt->execute([$start, $end . ' 23:59:59']);
    $submittedCount = (int)$submittedStmt->fetchColumn();

    $approvedStmt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(households_connected),0) FROM sites WHERE status = 'Approved' AND approved_at BETWEEN ? AND ?");
    $approvedStmt->execute([$start, $end . ' 23:59:59']);
    [$approvedCount, $approvedHouseholds] = $approvedStmt->fetch(PDO::FETCH_NUM);

    $rejectedStmt = $pdo->prepare("SELECT COUNT(*) FROM sites WHERE status = 'Rejected' AND rejected_at BETWEEN ? AND ?");
    $rejectedStmt->execute([$start, $end . ' 23:59:59']);
    $rejectedCount = (int)$rejectedStmt->fetchColumn();

    $recentStmt = $pdo->prepare(
        "SELECT s.site_code, s.site_name, p.project_name, s.status, s.households_connected
         FROM sites s JOIN projects p ON p.id = s.project_id
         WHERE s.submitted_at BETWEEN ? AND ? ORDER BY s.submitted_at DESC LIMIT 10"
    );
    $recentStmt->execute([$start, $end . ' 23:59:59']);
    $recentRows = $recentStmt->fetchAll();

    return [
        'submitted_count' => $submittedCount, 'approved_count' => (int)$approvedCount,
        'approved_households' => (int)$approvedHouseholds, 'rejected_count' => $rejectedCount,
        'approval_rate' => ai_pct((int)$approvedCount, (int)$approvedCount + $rejectedCount),
        'recent_rows' => $recentRows,
    ];
}

function ai_report_indigent(PDO $pdo, string $start, string $end): array
{
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM indigent_applications WHERE captured_at BETWEEN ? AND ?');
    $countStmt->execute([$start, $end . ' 23:59:59']);
    $count = (int)$countStmt->fetchColumn();

    $tvStmt = $pdo->prepare('SELECT COUNT(*) FROM indigent_applications WHERE captured_at BETWEEN ? AND ? AND has_functional_tv = 1');
    $tvStmt->execute([$start, $end . ' 23:59:59']);
    $tvCount = (int)$tvStmt->fetchColumn();

    $byProvinceStmt = $pdo->prepare(
        'SELECT province, COUNT(*) AS c FROM indigent_applications WHERE captured_at BETWEEN ? AND ? GROUP BY province ORDER BY c DESC'
    );
    $byProvinceStmt->execute([$start, $end . ' 23:59:59']);
    $byProvince = $byProvinceStmt->fetchAll();

    return ['count' => $count, 'tv_count' => $tvCount, 'by_province' => $byProvince];
}

function ai_report_planning(PDO $pdo, string $start, string $end): array
{
    $dispatchedStmt = $pdo->prepare('SELECT COUNT(*), COALESCE(SUM(target_installations),0) FROM installer_allocations WHERE dispatched_at BETWEEN ? AND ?');
    $dispatchedStmt->execute([$start, $end . ' 23:59:59']);
    [$dispatchedCount, $dispatchedTarget] = $dispatchedStmt->fetch(PDO::FETCH_NUM);

    $progressStmt = $pdo->prepare('SELECT COUNT(*) FROM allocation_progress_updates WHERE reported_at BETWEEN ? AND ?');
    $progressStmt->execute([$start, $end . ' 23:59:59']);
    $progressCount = (int)$progressStmt->fetchColumn();

    $completedStmt = $pdo->prepare("SELECT COUNT(*) FROM installer_allocations WHERE status = 'Completed' AND updated_at BETWEEN ? AND ?");
    $completedStmt->execute([$start, $end . ' 23:59:59']);
    $completedCount = (int)$completedStmt->fetchColumn();

    $overdueCount = (int)$pdo->query("SELECT COUNT(*) FROM installer_allocations WHERE status = 'Overdue'")->fetchColumn();

    return [
        'dispatched_count' => (int)$dispatchedCount, 'dispatched_target' => (int)$dispatchedTarget,
        'progress_count' => $progressCount, 'completed_count' => $completedCount, 'overdue_count' => $overdueCount,
    ];
}

function ai_report_projects(PDO $pdo): array
{
    $byProgramme = [];
    foreach (project_programmes() as $prog) {
        $byProgramme[$prog] = ['target_sites' => 0, 'actual_sites' => 0, 'pct' => null];
    }

    $targetStmt = $pdo->query("SELECT programme, SUM(target_sites) AS target_sites FROM projects WHERE target_sites IS NOT NULL GROUP BY programme");
    foreach ($targetStmt as $r) {
        if (isset($byProgramme[$r['programme']])) {
            $byProgramme[$r['programme']]['target_sites'] = (int)$r['target_sites'];
        }
    }

    $actualStmt = $pdo->query(
        "SELECT p.programme, COUNT(*) AS actual_sites
         FROM sites s JOIN projects p ON p.id = s.project_id
         WHERE s.status = 'Approved' GROUP BY p.programme"
    );
    foreach ($actualStmt as $r) {
        if (isset($byProgramme[$r['programme']])) {
            $byProgramme[$r['programme']]['actual_sites'] = (int)$r['actual_sites'];
        }
    }

    foreach ($byProgramme as $prog => $data) {
        $byProgramme[$prog]['pct'] = ai_pct($data['actual_sites'], $data['target_sites']);
    }
    return $byProgramme;
}

// ---------------------------------------------------------------------------
// Narrative generation — template sentences filled in from the numbers
// above. No external call; this is the "automated" half of "AI Report".
// ---------------------------------------------------------------------------

function ai_narrate_findings(array $d): string
{
    $s = "{$d['new_count']} new finding(s)/lesson(s) were logged during this period";
    $s .= $d['closed_count'] > 0 ? ", and {$d['closed_count']} were closed with a confirmed outcome." : '.';
    $s .= $d['overdue_count'] > 0
        ? " {$d['overdue_count']} finding(s) are currently overdue and have not reached a confirmed closure."
        : ' No findings are currently overdue.';
    return $s;
}

function ai_narrate_risks(array $d): string
{
    $s = "{$d['new_count']} new risk(s) were logged";
    $s .= $d['new_critical'] > 0 ? ", {$d['new_critical']} of them rated Critical." : '.';
    $s .= $d['closed_count'] > 0 ? " {$d['closed_count']} risk(s) were closed this period." : '';
    $s .= $d['critical_open_count'] > 0
        ? " ⚠ {$d['critical_open_count']} risk(s) are currently rated Critical and still open."
        : ' No risks are currently rated Critical and open.';
    $s .= $d['overdue_count'] > 0 ? " {$d['overdue_count']} risk(s) are overdue on their mitigation deadline." : '';
    return $s;
}

function ai_narrate_indicators(array $d): string
{
    $s = "{$d['actuals_count']} actual value(s) were reported against indicators for this period";
    $s .= $d['achieved_count'] > 0 ? ", and {$d['achieved_count']} indicator(s) reached confirmed achievement." : '.';
    $s .= $d['overdue_count'] > 0
        ? " {$d['overdue_count']} of {$d['total_indicators']} indicators are currently overdue on their target date."
        : " None of the {$d['total_indicators']} indicators are currently overdue.";
    return $s;
}

function ai_narrate_sites(array $d): string
{
    $s = "{$d['submitted_count']} site completion(s) were submitted this period. ";
    if ($d['approved_count'] + $d['rejected_count'] > 0) {
        $s .= "{$d['approved_count']} were approved for payment (" . ai_pct_text($d['approval_rate']) . " approval rate), "
            . "connecting " . number_format($d['approved_households']) . " household(s), while {$d['rejected_count']} were rejected.";
    } else {
        $s .= 'No submissions were resolved (approved or rejected) in this period.';
    }
    return $s;
}

function ai_narrate_indigent(array $d): string
{
    $topProvince = $d['by_province'][0]['province'] ?? null;
    $s = "{$d['count']} indigent application(s) were captured this period";
    $s .= $topProvince ? ", most from $topProvince ({$d['by_province'][0]['c']})." : '.';
    $s .= $d['count'] > 0 ? ' ' . ai_pct_text(ai_pct($d['tv_count'], $d['count'])) . ' of applicants already have a functional television.' : '';
    return $s;
}

function ai_narrate_planning(array $d): string
{
    $s = "{$d['dispatched_count']} installer allocation(s) were dispatched this period, targeting " . number_format($d['dispatched_target']) . ' installation(s). ';
    $s .= "{$d['progress_count']} progress update(s) were logged, and {$d['completed_count']} allocation(s) reached their target.";
    $s .= $d['overdue_count'] > 0 ? " ⚠ {$d['overdue_count']} allocation(s) are currently overdue." : ' No allocations are currently overdue.';
    return $s;
}

function ai_narrate_executive_summary(array $sections): string
{
    $highlights = [];
    $concerns = [];

    if ($sections['sites']['approved_count'] > 0) {
        $highlights[] = "{$sections['sites']['approved_count']} site(s) approved for payment, connecting " . number_format($sections['sites']['approved_households']) . ' households';
    }
    if ($sections['indicators']['achieved_count'] > 0) {
        $highlights[] = "{$sections['indicators']['achieved_count']} indicator(s) reached confirmed achievement";
    }
    if ($sections['planning']['completed_count'] > 0) {
        $highlights[] = "{$sections['planning']['completed_count']} installer allocation(s) reached their target";
    }
    if ($sections['indigent']['count'] > 0) {
        $highlights[] = "{$sections['indigent']['count']} new indigent application(s) captured";
    }

    if ($sections['risks']['critical_open_count'] > 0) {
        $concerns[] = "{$sections['risks']['critical_open_count']} risk(s) currently rated Critical and open";
    }
    $totalOverdue = $sections['findings']['overdue_count'] + $sections['risks']['overdue_count']
        + $sections['indicators']['overdue_count'] + $sections['planning']['overdue_count'];
    if ($totalOverdue > 0) {
        $concerns[] = "$totalOverdue item(s) overdue across findings, risks, indicators and planning combined";
    }
    if ($sections['sites']['rejected_count'] > 0) {
        $concerns[] = "{$sections['sites']['rejected_count']} site submission(s) rejected";
    }

    $text = $highlights
        ? 'Highlights this period: ' . implode('; ', $highlights) . '.'
        : 'No notable positive movement was recorded across modules this period.';
    $text .= ' ';
    $text .= $concerns
        ? "⚠ Needs attention: " . implode('; ', $concerns) . '.'
        : 'No outstanding concerns were flagged across modules.';
    return $text;
}

/** Orchestrator — runs every module's aggregation + narrative and returns the full snapshot array. */
function generate_ai_report(PDO $pdo, string $start, string $end): array
{
    $sections = [
        'findings' => ai_report_findings($pdo, $start, $end),
        'risks' => ai_report_risks($pdo, $start, $end),
        'indicators' => ai_report_indicators($pdo, $start, $end),
        'sites' => ai_report_sites($pdo, $start, $end),
        'indigent' => ai_report_indigent($pdo, $start, $end),
        'planning' => ai_report_planning($pdo, $start, $end),
    ];
    $narratives = [
        'findings' => ai_narrate_findings($sections['findings']),
        'risks' => ai_narrate_risks($sections['risks']),
        'indicators' => ai_narrate_indicators($sections['indicators']),
        'sites' => ai_narrate_sites($sections['sites']),
        'indigent' => ai_narrate_indigent($sections['indigent']),
        'planning' => ai_narrate_planning($sections['planning']),
    ];
    return [
        'sections' => $sections,
        'narratives' => $narratives,
        'executive_summary' => ai_narrate_executive_summary($sections),
        'projects_by_programme' => ai_report_projects($pdo),
    ];
}
