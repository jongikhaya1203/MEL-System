<?php
/**
 * One-time sample data seeder, covering every module in the system:
 *  - A project per South African province for each of the two USAASA
 *    programmes — Broadband Connectivity and BDM (Broadcasting and Digital
 *    Migration, set-top box distribution) — each tagged with a province,
 *    household target and site target, plus sample users for every
 *    workflow role (Installer, Reviewer, Approver, Manager, Viewer, Data
 *    Capturer, Planner).
 *  - Sample site completions moving through Submitted / Verified /
 *    Approved / Rejected, each Approved site with a real downloadable POE.
 *  - Findings & Lessons Register, Risk Register and Results Framework
 *    sample records spanning Open/In Progress/Overdue/Closed(/Achieved),
 *    including at least one deadline/target-date revision example each.
 *  - Indigent Applications using the real province/district/local
 *    municipality hierarchy and a genuinely valid SA ID checksum per
 *    applicant.
 *  - Planning & Dispatch installer allocations across provinces/regions,
 *    with progress-update history — two of which are linked to a Results
 *    Framework indicator, so their progress updates also appear as that
 *    indicator's reported actuals (Planning -> Results Framework sync).
 *
 * Safe to run more than once: records are matched by a natural unique key
 * (project code/username, site name, finding/risk description, indicator
 * name, ID number, or project+region+installer) and reused/skipped rather
 * than duplicated.
 *
 * Run it once by visiting this file in the browser while logged in as
 * admin, then delete it (or leave it — re-running does no harm).
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_super_admin();

$log = [];

function seed_get_or_create_project(
    PDO $pdo, string $code, string $name, int $households, int $targetSites, int $createdBy, array &$log,
    ?string $province = null, string $programme = 'Broadband Connectivity', ?string $description = null
): int {
    $stmt = $pdo->prepare('SELECT id FROM projects WHERE project_code = ?');
    $stmt->execute([$code]);
    $id = $stmt->fetchColumn();
    if ($id) {
        // Refresh the sample targets on an already-seeded project, so
        // re-running this script after a schema/target change stays current.
        $pdo->prepare('UPDATE projects SET target_households = ?, target_sites = ?, province = ?, programme = ? WHERE id = ?')
            ->execute([$households, $targetSites, $province, $programme, $id]);
        $log[] = "Project $code already exists — reused (targets refreshed).";
        return (int)$id;
    }
    $description = $description ?? "Province-level universal service rollout — $name.";
    $stmt = $pdo->prepare('INSERT INTO projects (project_code, project_name, province, programme, description, target_households, target_sites, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$code, $name, $province, $programme, $description, $households, $targetSites, $createdBy]);
    $log[] = "Created project $code ($name), target households " . number_format($households) . ", target sites $targetSites.";
    return (int)$pdo->lastInsertId();
}

function seed_get_or_create_user(PDO $pdo, string $username, string $fullName, string $role, string $passwordHash, array &$log): int
{
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $id = $stmt->fetchColumn();
    if ($id) {
        $log[] = "User $username already exists — reused.";
        return (int)$id;
    }
    $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, email, role) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$username, $passwordHash, $fullName, $username . '@usaasa.org.za', $role]);
    $log[] = "Created user $username ($fullName, role: $role).";
    return (int)$pdo->lastInsertId();
}

$demoPasswordHash = '$2y$10$aZwWiBiRjynnZnSKIzgZpup4f2pNNraoxKwXrDlNSZXh4bkvNycFa'; // "Demo@12345"
$adminId = current_user()['id'];

// --- Projects: one per South African province -----------------------------
// target_sites is the yearly site-deployment target used by the dashboard's
// "Target vs Actual" chart; it's independent of target_households (a
// household target can be hit via fewer, larger sites or vice versa).
$provinces = [
    ['USAASA-EC',  'Eastern Cape Connectivity Rollout',   'Eastern Cape',    52000, 18],
    ['USAASA-FS',  'Free State Connectivity Rollout',     'Free State',      28000, 10],
    ['USAASA-GP',  'Gauteng Connectivity Rollout',        'Gauteng',         61000, 25],
    ['USAASA-KZN', 'KwaZulu-Natal Connectivity Rollout',  'KwaZulu-Natal',   58000, 22],
    ['USAASA-LP',  'Limpopo Connectivity Rollout',        'Limpopo',         45000, 15],
    ['USAASA-MP',  'Mpumalanga Connectivity Rollout',     'Mpumalanga',      33000, 12],
    ['USAASA-NC',  'Northern Cape Connectivity Rollout',  'Northern Cape',   15000, 6],
    ['USAASA-NW',  'North West Connectivity Rollout',     'North West',      30000, 11],
    ['USAASA-WC',  'Western Cape Connectivity Rollout',   'Western Cape',    40000, 16],
];
$projectIds = [];
foreach ($provinces as [$code, $name, $province, $households, $targetSites]) {
    $projectIds[$code] = seed_get_or_create_project($pdo, $code, $name, $households, $targetSites, $adminId, $log, $province, 'Broadband Connectivity');
}

// --- BDM (Broadcasting and Digital Migration): one project per province ---
// A distinct programme from Broadband Connectivity — set-top box
// distribution and analogue switch-off support, not internet access.
// target_households here is STBs/households supported, not connections;
// target_sites is distribution/installation points.
$bdmProjects = [
    ['USAASA-BDM-EC',  'Eastern Cape Broadcasting & Digital Migration',  'Eastern Cape',    9000,  10],
    ['USAASA-BDM-FS',  'Free State Broadcasting & Digital Migration',    'Free State',      5000,  6],
    ['USAASA-BDM-GP',  'Gauteng Broadcasting & Digital Migration',       'Gauteng',         11000, 12],
    ['USAASA-BDM-KZN', 'KwaZulu-Natal Broadcasting & Digital Migration', 'KwaZulu-Natal',   10500, 11],
    ['USAASA-BDM-LP',  'Limpopo Broadcasting & Digital Migration',       'Limpopo',         8200,  9],
    ['USAASA-BDM-MP',  'Mpumalanga Broadcasting & Digital Migration',    'Mpumalanga',      6000,  7],
    ['USAASA-BDM-NC',  'Northern Cape Broadcasting & Digital Migration', 'Northern Cape',   2800,  4],
    ['USAASA-BDM-NW',  'North West Broadcasting & Digital Migration',    'North West',      5500,  6],
    ['USAASA-BDM-WC',  'Western Cape Broadcasting & Digital Migration',  'Western Cape',    7200,  8],
];
foreach ($bdmProjects as [$code, $name, $province, $households, $targetSites]) {
    $projectIds[$code] = seed_get_or_create_project(
        $pdo, $code, $name, $households, $targetSites, $adminId, $log, $province, 'BDM',
        "Broadcasting and Digital Migration — set-top box distribution and analogue switch-off support for qualifying households in $province."
    );
}

// --- Sample users for each Site Completion workflow role ------------------
$fieldWorkers = [
    seed_get_or_create_user($pdo, 'jmokoena', 'Johannes Mokoena', 'field_worker', $demoPasswordHash, $log),
    seed_get_or_create_user($pdo, 'tnkosi',   'Thandiwe Nkosi',   'field_worker', $demoPasswordHash, $log),
    seed_get_or_create_user($pdo, 'spillay',  'Suresh Pillay',    'field_worker', $demoPasswordHash, $log),
];
$backOffice = [
    seed_get_or_create_user($pdo, 'lvandermerwe', 'Lerato van der Merwe', 'back_office', $demoPasswordHash, $log),
    seed_get_or_create_user($pdo, 'bmahlangu',     'Bongani Mahlangu',     'back_office', $demoPasswordHash, $log),
];
$approvers = [
    seed_get_or_create_user($pdo, 'nkhumalo', 'Nomvula Khumalo', 'approver', $demoPasswordHash, $log),
    seed_get_or_create_user($pdo, 'dvanwyk',  'Dawid van Wyk',   'approver', $demoPasswordHash, $log),
];

// --- Sample site completions, one or two per province, across every status
$sampleSites = [
    ['USAASA-EC',  'Mthatha Ward 12 Community Hall',   'Mthatha, OR Tambo District',        420, 'Approved'],
    ['USAASA-EC',  'Qumbu Primary School',             'Qumbu, OR Tambo District',          180, 'Submitted'],
    ['USAASA-FS',  'Botshabelo Community Centre',      'Botshabelo, Mangaung',              310, 'Verified'],
    ['USAASA-GP',  'Soshanguve Block H Hall',          'Soshanguve, City of Tshwane',        650, 'Approved'],
    ['USAASA-GP',  'Alexandra Library',                'Alexandra, City of Johannesburg',    290, 'Rejected'],
    ['USAASA-KZN', 'Umlazi K Section Hall',            'Umlazi, eThekwini',                  540, 'Approved'],
    ['USAASA-KZN', 'Nongoma Tribal Office',            'Nongoma, Zululand',                  210, 'Submitted'],
    ['USAASA-LP',  'Giyani Community Hall',            'Giyani, Mopani District',            260, 'Verified'],
    ['USAASA-MP',  'KwaMhlanga Hall',                  'KwaMhlanga, Nkangala District',       230, 'Submitted'],
    ['USAASA-NC',  'Kuruman Civic Centre',             'Kuruman, John Taolo Gaetsewe',        95,  'Approved'],
    ['USAASA-NW',  'Mahikeng Ward 3 Hall',             'Mahikeng, Ngaka Modiri Molema',       175, 'Verified'],
    ['USAASA-WC',  'Khayelitsha Site C Hall',          'Khayelitsha, City of Cape Town',      480, 'Approved'],
];

$rejectionReason = 'Site photos did not clearly show the installed equipment; please resubmit with wider-angle photos.';
$verificationNotes = 'POE checked against site register; households count confirmed.';
$approvalNotes = 'POE and verification sign-off in order. Approved for payment.';

$i = 0;
foreach ($sampleSites as [$projectCode, $siteName, $location, $households, $targetStatus]) {
    $i++;
    $stmt = $pdo->prepare('SELECT id FROM sites WHERE site_name = ? AND project_id = ?');
    $stmt->execute([$siteName, $projectIds[$projectCode]]);
    if ($stmt->fetchColumn()) {
        $log[] = "Site \"$siteName\" already exists — skipped.";
        continue;
    }

    $fieldWorker = $fieldWorkers[$i % count($fieldWorkers)];
    $backOfficer = $backOffice[$i % count($backOffice)];
    $approver = $approvers[$i % count($approvers)];
    $siteCode = next_site_reference_code($pdo);
    $completedDate = date('Y-m-d', strtotime('-' . (10 + $i * 3) . ' days'));

    $stmt = $pdo->prepare(
        "INSERT INTO sites (site_code, project_id, site_name, location, households_connected, completed_date, status, submitted_by, submitted_at)
         VALUES (?, ?, ?, ?, ?, ?, 'Submitted', ?, ?)"
    );
    $submittedAt = date('Y-m-d H:i:s', strtotime($completedDate . ' +2 days'));
    $stmt->execute([$siteCode, $projectIds[$projectCode], $siteName, $location, $households, $completedDate, $fieldWorker, $submittedAt]);
    $siteId = (int)$pdo->lastInsertId();

    if (in_array($targetStatus, ['Verified', 'Approved'], true)) {
        $verifiedAt = date('Y-m-d H:i:s', strtotime($submittedAt . ' +1 day'));
        $pdo->prepare("UPDATE sites SET status = 'Verified', verified_by = ?, verified_at = ?, verification_notes = ? WHERE id = ?")
            ->execute([$backOfficer, $verifiedAt, $verificationNotes, $siteId]);
    }
    if ($targetStatus === 'Approved') {
        $approvedAt = date('Y-m-d H:i:s', strtotime($submittedAt . ' +3 days'));
        $pdo->prepare("UPDATE sites SET status = 'Approved', approved_by = ?, approved_at = ?, approval_notes = ? WHERE id = ?")
            ->execute([$approver, $approvedAt, $approvalNotes, $siteId]);

        // Give every Approved sample site a real, downloadable sample POE file.
        if (!is_dir(POE_STORAGE_DIR)) {
            mkdir(POE_STORAGE_DIR, 0750, true);
        }
        $storedFilename = bin2hex(random_bytes(16)) . '.txt';
        $content = "SAMPLE DATA — replace with a real POE document.\n\n"
            . "Proof of Evidence — Site Completion\n"
            . "Site: $siteName\n"
            . "Location: $location\n"
            . "Households connected: $households\n"
            . "Completed: " . format_date($completedDate) . "\n"
            . "Verified and approved for payment.\n";
        file_put_contents(POE_STORAGE_DIR . $storedFilename, $content);

        $pdo->prepare(
            'INSERT INTO site_poe_documents (site_id, file_name, stored_filename, file_size, mime_type, description, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$siteId, 'completion_certificate.txt', $storedFilename, strlen($content), 'text/plain', 'Completion certificate (sample)', $fieldWorker]);
    }
    if ($targetStatus === 'Rejected') {
        $rejectedAt = date('Y-m-d H:i:s', strtotime($submittedAt . ' +1 day'));
        $pdo->prepare("UPDATE sites SET status = 'Rejected', rejected_by = ?, rejected_at = ?, rejection_reason = ? WHERE id = ?")
            ->execute([$backOfficer, $rejectedAt, $rejectionReason, $siteId]);
    }

    $log[] = "Created site $siteCode \"$siteName\" ($projectCode), status: $targetStatus.";
}

// --- Bulk additional Approved sites, purely to give the dashboard's
// target-vs-actual bar chart and provincial pie chart a realistic amount
// of data to show (the dozen sites above, mostly non-Approved, aren't
// enough on their own to make those charts meaningful). Each is a real
// site row with its own POE file, same as above — just generated in bulk
// with a deterministic name so re-running this script doesn't duplicate.
$bulkApprovedCounts = [
    'USAASA-EC' => 13, 'USAASA-FS' => 6, 'USAASA-GP' => 21, 'USAASA-KZN' => 15,
    'USAASA-LP' => 9, 'USAASA-MP' => 7, 'USAASA-NC' => 4, 'USAASA-NW' => 6, 'USAASA-WC' => 12,
];
$bulkLocations = [
    'USAASA-EC' => 'OR Tambo District', 'USAASA-FS' => 'Mangaung Metro', 'USAASA-GP' => 'City of Tshwane',
    'USAASA-KZN' => 'eThekwini Metro', 'USAASA-LP' => 'Mopani District', 'USAASA-MP' => 'Nkangala District',
    'USAASA-NC' => 'John Taolo Gaetsewe District', 'USAASA-NW' => 'Ngaka Modiri Molema District', 'USAASA-WC' => 'Cape Winelands District',
];

$bulkIndex = 0;
foreach ($bulkApprovedCounts as $projectCode => $count) {
    for ($n = 1; $n <= $count; $n++) {
        $bulkIndex++;
        $siteName = "$projectCode Deployment Site $n";
        $stmt = $pdo->prepare('SELECT id FROM sites WHERE site_name = ? AND project_id = ?');
        $stmt->execute([$siteName, $projectIds[$projectCode]]);
        if ($stmt->fetchColumn()) {
            continue;
        }

        $fieldWorker = $fieldWorkers[$bulkIndex % count($fieldWorkers)];
        $backOfficer = $backOffice[$bulkIndex % count($backOffice)];
        $approver = $approvers[$bulkIndex % count($approvers)];
        $households = 60 + (($bulkIndex * 37) % 260); // deterministic spread, 60–319
        $siteCode = next_site_reference_code($pdo);
        $completedDate = date('Y-m-d', strtotime('-' . (20 + $bulkIndex) . ' days'));
        $submittedAt = date('Y-m-d H:i:s', strtotime($completedDate . ' +2 days'));
        $verifiedAt = date('Y-m-d H:i:s', strtotime($submittedAt . ' +1 day'));
        $approvedAt = date('Y-m-d H:i:s', strtotime($submittedAt . ' +3 days'));
        $location = $bulkLocations[$projectCode];

        $stmt = $pdo->prepare(
            "INSERT INTO sites (
                site_code, project_id, site_name, location, households_connected, completed_date,
                status, submitted_by, submitted_at, verified_by, verified_at, verification_notes,
                approved_by, approved_at, approval_notes
            ) VALUES (?, ?, ?, ?, ?, ?, 'Approved', ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $siteCode, $projectIds[$projectCode], $siteName, $location, $households, $completedDate,
            $fieldWorker, $submittedAt, $backOfficer, $verifiedAt, $verificationNotes,
            $approver, $approvedAt, $approvalNotes,
        ]);
        $siteId = (int)$pdo->lastInsertId();

        if (!is_dir(POE_STORAGE_DIR)) {
            mkdir(POE_STORAGE_DIR, 0750, true);
        }
        $storedFilename = bin2hex(random_bytes(16)) . '.txt';
        $content = "SAMPLE DATA — replace with a real POE document.\n\n"
            . "Proof of Evidence — Site Completion\n"
            . "Site: $siteName\n"
            . "Location: $location\n"
            . "Households connected: $households\n"
            . "Completed: " . format_date($completedDate) . "\n"
            . "Verified and approved for payment.\n";
        file_put_contents(POE_STORAGE_DIR . $storedFilename, $content);
        $pdo->prepare(
            'INSERT INTO site_poe_documents (site_id, file_name, stored_filename, file_size, mime_type, description, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$siteId, 'completion_certificate.txt', $storedFilename, strlen($content), 'text/plain', 'Completion certificate (sample)', $fieldWorker]);
    }
    $log[] = "Bulk-seeded up to $count additional Approved sites for $projectCode.";
}

// --- BDM sample sites (STB distribution points) — varied completion rate
// per province, intentionally short of target in every case (a programme
// mid-rollout, not a finished one), so the Projects list and dashboard
// charts show believable progress rather than either all-zero or all-done.
$bdmApprovedCounts = [
    'USAASA-BDM-EC' => 7, 'USAASA-BDM-FS' => 3, 'USAASA-BDM-GP' => 10, 'USAASA-BDM-KZN' => 8,
    'USAASA-BDM-LP' => 5, 'USAASA-BDM-MP' => 4, 'USAASA-BDM-NC' => 3, 'USAASA-BDM-NW' => 4, 'USAASA-BDM-WC' => 6,
];
$bdmLocations = [
    'USAASA-BDM-EC' => 'Amathole District', 'USAASA-BDM-FS' => 'Thabo Mofutsanyana District', 'USAASA-BDM-GP' => 'City of Johannesburg',
    'USAASA-BDM-KZN' => 'uMgungundlovu District', 'USAASA-BDM-LP' => 'Capricorn District', 'USAASA-BDM-MP' => 'Ehlanzeni District',
    'USAASA-BDM-NC' => 'Frances Baard District', 'USAASA-BDM-NW' => 'Dr Kenneth Kaunda District', 'USAASA-BDM-WC' => 'Overberg District',
];

$bdmIndex = 0;
foreach ($bdmApprovedCounts as $projectCode => $count) {
    for ($n = 1; $n <= $count; $n++) {
        $bdmIndex++;
        $siteName = "$projectCode STB Distribution Point $n";
        $stmt = $pdo->prepare('SELECT id FROM sites WHERE site_name = ? AND project_id = ?');
        $stmt->execute([$siteName, $projectIds[$projectCode]]);
        if ($stmt->fetchColumn()) {
            continue;
        }

        $fieldWorker = $fieldWorkers[$bdmIndex % count($fieldWorkers)];
        $backOfficer = $backOffice[$bdmIndex % count($backOffice)];
        $approver = $approvers[$bdmIndex % count($approvers)];
        $households = 40 + (($bdmIndex * 29) % 180); // deterministic spread, 40–219 households per distribution point
        $siteCode = next_site_reference_code($pdo);
        $completedDate = date('Y-m-d', strtotime('-' . (15 + $bdmIndex) . ' days'));
        $submittedAt = date('Y-m-d H:i:s', strtotime($completedDate . ' +2 days'));
        $verifiedAt = date('Y-m-d H:i:s', strtotime($submittedAt . ' +1 day'));
        $approvedAt = date('Y-m-d H:i:s', strtotime($submittedAt . ' +3 days'));
        $location = $bdmLocations[$projectCode];

        $stmt = $pdo->prepare(
            "INSERT INTO sites (
                site_code, project_id, site_name, location, households_connected, completed_date,
                status, submitted_by, submitted_at, verified_by, verified_at, verification_notes,
                approved_by, approved_at, approval_notes
            ) VALUES (?, ?, ?, ?, ?, ?, 'Approved', ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $siteCode, $projectIds[$projectCode], $siteName, $location, $households, $completedDate,
            $fieldWorker, $submittedAt, $backOfficer, $verifiedAt, 'STB distribution register checked against site log; household count confirmed.',
            $approver, $approvedAt, 'Distribution and installation confirmed. Approved for payment.',
        ]);
        $siteId = (int)$pdo->lastInsertId();

        if (!is_dir(POE_STORAGE_DIR)) {
            mkdir(POE_STORAGE_DIR, 0750, true);
        }
        $storedFilename = bin2hex(random_bytes(16)) . '.txt';
        $content = "SAMPLE DATA — replace with a real POE document.\n\n"
            . "Proof of Evidence — BDM Set-Top Box Distribution\n"
            . "Distribution point: $siteName\n"
            . "Location: $location\n"
            . "Households supported: $households\n"
            . "Completed: " . format_date($completedDate) . "\n"
            . "Verified and approved for payment.\n";
        file_put_contents(POE_STORAGE_DIR . $storedFilename, $content);
        $pdo->prepare(
            'INSERT INTO site_poe_documents (site_id, file_name, stored_filename, file_size, mime_type, description, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$siteId, 'stb_distribution_certificate.txt', $storedFilename, strlen($content), 'text/plain', 'STB distribution certificate (sample)', $fieldWorker]);
    }
    $log[] = "Bulk-seeded up to $count BDM STB distribution sites for $projectCode.";
}

$managerId = seed_get_or_create_user($pdo, 'tshabangu', 'Thabo Shabangu', 'manager', $demoPasswordHash, $log);
$viewerId = seed_get_or_create_user($pdo, 'nmokoena', 'Nonhlanhla Mokoena', 'viewer', $demoPasswordHash, $log);
$plannerId = seed_get_or_create_user($pdo, 'kmotaung', 'Kgomotso Motaung', 'planner', $demoPasswordHash, $log);

// --- Findings & Lessons Register sample data --------------------------------
function seed_get_or_create_finding(PDO $pdo, array $d, array &$log): int
{
    $stmt = $pdo->prepare('SELECT id FROM findings_register WHERE evidence_based_finding = ? AND project_id = ?');
    $stmt->execute([$d['finding'], $d['project_id']]);
    if ($id = $stmt->fetchColumn()) {
        $log[] = 'Finding "' . substr($d['finding'], 0, 40) . '…" already exists — skipped.';
        return (int)$id;
    }
    $ref = next_reference_code($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO findings_register (
            reference_code, project_id, source, source_date, evidence_based_finding, lesson_drawn,
            evidence_and_limitations, implication, management_response, owner_user_id,
            original_deadline, current_deadline, status, follow_up_outcome, closed_by, closed_at, closure_notes, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $ref, $d['project_id'], $d['source'], $d['source_date'], $d['finding'], $d['lesson'],
        $d['evidence'], $d['implication'], $d['response'], $d['owner'],
        $d['deadline'], $d['current_deadline'] ?? $d['deadline'], $d['status'], $d['follow_up'] ?? null,
        $d['closed_by'] ?? null, $d['closed_at'] ?? null, $d['closure_notes'] ?? null, $d['owner'],
    ]);
    $id = (int)$pdo->lastInsertId();
    if (!empty($d['revision'])) {
        $pdo->prepare('INSERT INTO deadline_revisions (finding_id, previous_deadline, new_deadline, reason, revised_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([$id, $d['deadline'], $d['current_deadline'], $d['revision'], $d['owner']]);
    }
    $log[] = "Created finding $ref.";
    return $id;
}

$sampleFindings = [
    [
        'project_id' => $projectIds['USAASA-EC'], 'source' => 'Site inspection report, Mthatha Ward 12', 'source_date' => '2026-02-10',
        'finding' => 'Household connection counts recorded on the site register did not match the number of active devices observed during a spot check.',
        'lesson' => 'Site registers need a same-day reconciliation step against device activation logs, not a weekly batch check.',
        'evidence' => 'Spot check covered 1 of 12 sites in the ward; not yet confirmed as a systemic issue across the district.',
        'implication' => 'Household-connected figures feeding the dashboard may be overstated until reconciliation is tightened.',
        'response' => 'Reviewer to cross-check device activation logs against the site register before sign-off, starting with OR Tambo District sites.',
        'owner' => $managerId, 'deadline' => '2026-12-15', 'status' => 'In Progress',
    ],
    [
        'project_id' => $projectIds['USAASA-GP'], 'source' => 'Quarterly stakeholder review, Gauteng rollout', 'source_date' => '2025-11-20',
        'finding' => 'Installer dispatch to Alexandra and Soshanguve relied on informal WhatsApp coordination with no written target or deadline.',
        'lesson' => 'Dispatch needs a recorded target and deadline per installer per area, not ad hoc coordination, to make non-delivery visible early.',
        'evidence' => 'Based on interviews with two installers and one back-office reviewer; not a full audit of all dispatch communication.',
        'implication' => 'Delays in under-performing areas were only noticed once households complained, well after the planned completion window.',
        'response' => 'Adopt the Planning & Dispatch module for all installer allocations going forward, with a logged target and deadline per allocation.',
        'owner' => $adminId, 'deadline' => '2025-12-31', 'current_deadline' => '2026-01-31',
        'revision' => 'Planning & Dispatch module was still in development; deadline moved to align with its rollout.',
        'status' => 'Closed', 'closed_by' => $adminId, 'closed_at' => '2026-02-02 09:00:00',
        'closure_notes' => 'Planning & Dispatch module deployed; all new allocations now go through it.',
        'follow_up' => 'Confirmed in use for the Gauteng, Limpopo and Mpumalanga allocations seeded alongside this finding.',
    ],
    [
        'project_id' => $projectIds['USAASA-KZN'], 'source' => 'POE audit sample, eThekwini sites', 'source_date' => '2026-01-18',
        'finding' => 'Three of ten sampled POE submissions included photos that did not clearly show the installed equipment.',
        'lesson' => 'A photo checklist shown to the installer before submission would catch this before it reaches the reviewer.',
        'evidence' => 'Sample of 10 POE submissions from one district only; other districts not yet sampled.',
        'implication' => 'Reviewer rework and resubmission cycles add delay to the payment approval chain.',
        'response' => 'Add a photo checklist reminder to the site completion submission screen.',
        'owner' => $managerId, 'deadline' => '2025-10-01', 'status' => 'Open',
    ],
    [
        'project_id' => $projectIds['USAASA-LP'], 'source' => 'Field monitoring visit, Vhembe District', 'source_date' => '2026-01-05',
        'finding' => 'Distance-to-office data captured on indigent applications in Vhembe is consistently estimated rather than measured.',
        'lesson' => 'Capturers need a simple reference distance table per village for consistent planning data, not individual guesswork.',
        'evidence' => 'Observed on 4 applications during one field visit; not a full data quality audit.',
        'implication' => 'Planning decisions based on these distances (e.g. mobile office scheduling) may be less reliable than assumed.',
        'response' => 'Compile a reference distance table per village for the Vhembe pilot and extend to other districts if it helps.',
        'owner' => $viewerId, 'deadline' => '2026-12-31', 'status' => 'In Progress',
    ],
    [
        'project_id' => $projectIds['USAASA-WC'], 'source' => 'Internal process review', 'source_date' => '2025-09-12',
        'finding' => 'Approvers in the Western Cape rollout had no visibility of a site\'s verification notes until opening the full record.',
        'lesson' => 'Showing verification notes inline on the approval screen reduces back-and-forth between reviewer and approver.',
        'evidence' => 'Based on a review of the approval screen only; not validated against approver time-to-decision data.',
        'implication' => 'Minor efficiency loss in the approval step; no evidence of incorrect approvals resulting from this.',
        'response' => 'Low priority — revisit if approver turnaround times become a measured concern.',
        'owner' => $adminId, 'deadline' => '2025-11-01', 'status' => 'Closed',
        'closed_by' => $adminId, 'closed_at' => '2025-11-05 14:00:00',
        'closure_notes' => 'Deprioritised; no measurable impact on approval turnaround found.',
    ],
    [
        'project_id' => $projectIds['USAASA-NW'], 'source' => 'Risk register follow-up', 'source_date' => '2025-08-22',
        'finding' => 'A planned mitigation action for a connectivity-equipment-theft risk in Bojanala Platinum was never assigned an owner.',
        'lesson' => 'Every mitigation action needs an explicit owner captured at the point the risk is logged, not left for later.',
        'evidence' => 'Found on one risk record during a register audit; other risk records were not re-checked at the same time.',
        'implication' => 'Mitigation actions without an owner are unlikely to be actioned on schedule.',
        'response' => 'Owner field is already required on the Risk Register form; reinforce this in onboarding for new managers.',
        'owner' => $managerId, 'deadline' => '2026-12-20', 'status' => 'Open',
    ],
];
foreach ($sampleFindings as $d) {
    seed_get_or_create_finding($pdo, $d, $log);
}

// --- Risk Register sample data ----------------------------------------------
function seed_get_or_create_risk(PDO $pdo, array $d, array &$log): int
{
    $stmt = $pdo->prepare('SELECT id FROM risks WHERE risk_description = ? AND project_id = ?');
    $stmt->execute([$d['description'], $d['project_id']]);
    if ($id = $stmt->fetchColumn()) {
        $log[] = 'Risk "' . substr($d['description'], 0, 40) . '…" already exists — skipped.';
        return (int)$id;
    }
    $rating = compute_risk_rating($d['likelihood'], $d['impact']);
    $ref = next_risk_reference_code($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO risks (
            reference_code, project_id, category, date_identified, risk_description, existing_controls,
            likelihood, impact, risk_rating, mitigation_action, owner_user_id,
            original_deadline, current_deadline, status, follow_up_outcome, closed_by, closed_at, closure_notes, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $ref, $d['project_id'], $d['category'], $d['date_identified'], $d['description'], $d['controls'],
        $d['likelihood'], $d['impact'], $rating, $d['mitigation'], $d['owner'],
        $d['deadline'], $d['current_deadline'] ?? $d['deadline'], $d['status'], $d['follow_up'] ?? null,
        $d['closed_by'] ?? null, $d['closed_at'] ?? null, $d['closure_notes'] ?? null, $d['owner'],
    ]);
    $id = (int)$pdo->lastInsertId();
    if (!empty($d['revision'])) {
        $pdo->prepare('INSERT INTO risk_deadline_revisions (risk_id, previous_deadline, new_deadline, reason, revised_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([$id, $d['deadline'], $d['current_deadline'], $d['revision'], $d['owner']]);
    }
    $log[] = "Created risk $ref ($rating).";
    return $id;
}

$sampleRisks = [
    [
        'project_id' => $projectIds['USAASA-EC'], 'category' => 'Operational', 'date_identified' => '2026-01-12',
        'description' => 'Heavy seasonal rainfall in OR Tambo District may delay access to rural installation sites between January and March.',
        'controls' => 'Installers currently reschedule on an ad hoc basis when roads are impassable.',
        'likelihood' => 'High', 'impact' => 'Medium',
        'mitigation' => 'Build a wet-season buffer into installer allocation deadlines for this district.',
        'owner' => $managerId, 'deadline' => '2026-12-31', 'status' => 'Open',
    ],
    [
        'project_id' => $projectIds['USAASA-GP'], 'category' => 'ICT', 'date_identified' => '2025-10-02',
        'description' => 'Equipment theft from unattended installation sites overnight in high-density urban areas.',
        'controls' => 'Installers are instructed to complete same-day installs where possible; no formal site security.',
        'likelihood' => 'Medium', 'impact' => 'High',
        'mitigation' => 'Pilot tamper-evident, low-resale-value enclosures for exposed equipment in the highest-incidence wards.',
        'owner' => $adminId, 'deadline' => '2025-12-15', 'current_deadline' => '2026-12-15',
        'revision' => 'Procurement of pilot enclosures delayed; extended to align with the new delivery date.',
        'status' => 'In Progress',
    ],
    [
        'project_id' => $projectIds['USAASA-KZN'], 'category' => 'Financial', 'date_identified' => '2025-09-18',
        'description' => 'Payment approval backlog if back-office reviewer capacity does not scale with the number of submitted sites.',
        'controls' => 'Two back-office reviewers currently cover all KwaZulu-Natal submissions.',
        'likelihood' => 'Medium', 'impact' => 'Medium',
        'mitigation' => 'Monitor average verification turnaround monthly; add a third reviewer if it exceeds 5 working days.',
        'owner' => $managerId, 'deadline' => '2026-12-30', 'status' => 'Open',
    ],
    [
        'project_id' => $projectIds['USAASA-LP'], 'category' => 'Compliance', 'date_identified' => '2025-11-30',
        'description' => 'Indigent applicant declarations may not be consistently witnessed/signed in households without literate adult members present.',
        'controls' => 'Capturers are trained to read the declaration aloud before the applicant signs.',
        'likelihood' => 'Low', 'impact' => 'High',
        'mitigation' => 'Add a witness-signature option for households that need it; review with legal/compliance before building it.',
        'owner' => $viewerId, 'deadline' => '2027-01-31', 'status' => 'Open',
    ],
    [
        'project_id' => $projectIds['USAASA-MP'], 'category' => 'Strategic', 'date_identified' => '2025-07-14',
        'description' => 'Underspend against the Mpumalanga household target if installer capacity is not increased before year-end.',
        'controls' => 'Three field_worker installers currently shared across the whole rollout.',
        'likelihood' => 'High', 'impact' => 'High',
        'mitigation' => 'Recruit at least one additional installer dedicated to Mpumalanga before Q3.',
        'owner' => $adminId, 'deadline' => '2025-09-30', 'status' => 'Closed',
        'closed_by' => $adminId, 'closed_at' => '2025-10-10 11:00:00',
        'closure_notes' => 'Additional installer capacity was not required — existing installers met the revised quarterly target.',
    ],
    [
        'project_id' => $projectIds['USAASA-WC'], 'category' => 'Reputational', 'date_identified' => '2025-08-05',
        'description' => 'Community expectations of installation timelines in Khayelitsha are not matched to the realistic dispatch schedule.',
        'controls' => 'No formal community communication plan currently in place.',
        'likelihood' => 'Medium', 'impact' => 'Medium',
        'mitigation' => 'Share planned dispatch windows with ward councillors ahead of each allocation.',
        'owner' => $managerId, 'deadline' => '2025-08-01', 'status' => 'Open',
    ],
];
foreach ($sampleRisks as $d) {
    seed_get_or_create_risk($pdo, $d, $log);
}

// --- Results Framework (indicators) sample data ------------------------------
function seed_get_or_create_indicator(PDO $pdo, array $d, array &$log): int
{
    $stmt = $pdo->prepare('SELECT id FROM indicators WHERE indicator_name = ? AND project_id = ?');
    $stmt->execute([$d['name'], $d['project_id']]);
    if ($id = $stmt->fetchColumn()) {
        $log[] = "Indicator \"{$d['name']}\" already exists — skipped.";
        return (int)$id;
    }
    $ref = next_indicator_reference_code($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO indicators (
            reference_code, project_id, result_level, result_statement, indicator_name, definition, unit_of_measure,
            baseline_value, baseline_year, target_value, data_source, collection_frequency,
            responsible_user_id, original_target_date, current_target_date, status,
            confirmed_by, confirmed_at, confirmation_notes, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $ref, $d['project_id'], $d['level'], $d['statement'], $d['name'], $d['definition'], $d['unit'],
        $d['baseline'], $d['baseline_year'], $d['target'], $d['source'], $d['frequency'],
        $d['owner'], $d['target_date'], $d['current_target_date'] ?? $d['target_date'], $d['status'],
        $d['confirmed_by'] ?? null, $d['confirmed_at'] ?? null, $d['confirmation_notes'] ?? null, $d['owner'],
    ]);
    $id = (int)$pdo->lastInsertId();
    if (!empty($d['revision'])) {
        $pdo->prepare('INSERT INTO indicator_target_revisions (indicator_id, previous_target_date, new_target_date, reason, revised_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([$id, $d['target_date'], $d['current_target_date'], $d['revision'], $d['owner']]);
    }
    foreach ($d['actuals'] ?? [] as $a) {
        $pdo->prepare('INSERT INTO indicator_actuals (indicator_id, period_label, period_date, actual_value, comments, reported_by) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$id, $a['label'], $a['date'], $a['value'], $a['comments'] ?? null, $d['owner']]);
    }
    $log[] = "Created indicator $ref ({$d['name']}).";
    return $id;
}

$sampleIndicators = [
    [
        'project_id' => $projectIds['USAASA-EC'], 'level' => 'Output',
        'statement' => 'Rural households in the Eastern Cape have access to a functioning community connectivity site.',
        'name' => 'Number of rural households connected via community access sites',
        'definition' => 'Count of households within range of a completed, approved community connectivity installation.',
        'unit' => 'households', 'baseline' => 0, 'baseline_year' => 2024, 'target' => 500,
        'source' => 'Site completion records (Sites module)', 'frequency' => 'Quarterly',
        'owner' => $managerId, 'target_date' => '2027-02-28', 'status' => 'In Progress',
        'actuals' => [
            ['label' => 'Q2 2025/26', 'date' => '2025-09-30', 'value' => 120, 'comments' => 'Early rollout, OR Tambo District.'],
            ['label' => 'Q3 2025/26', 'date' => '2025-12-31', 'value' => 260, 'comments' => 'Qumbu and Mthatha sites added.'],
        ],
    ],
    [
        'project_id' => $projectIds['USAASA-LP'], 'level' => 'Output',
        'statement' => 'Installer allocations in Limpopo are completed against planned targets.',
        'name' => 'Number of installation sites completed, Limpopo',
        'definition' => 'Count of completed installations reported through Planning & Dispatch allocations for Limpopo.',
        'unit' => 'sites', 'baseline' => 0, 'baseline_year' => 2025, 'target' => 60,
        'source' => 'Planning & Dispatch module', 'frequency' => 'Monthly',
        'owner' => $managerId, 'target_date' => '2027-01-31', 'status' => 'Open',
        'actuals' => [],
    ],
    [
        'project_id' => $projectIds['USAASA-GP'], 'level' => 'Outcome',
        'statement' => 'Underserved wards in Gauteng have active, in-use public access connectivity points.',
        'name' => 'Share of underserved wards with an active public access point',
        'definition' => 'Percentage of wards identified as underserved at baseline that now have at least one active, verified public access point.',
        'unit' => '%', 'baseline' => 10, 'baseline_year' => 2024, 'target' => 80,
        'source' => 'Ward-level site survey', 'frequency' => 'Biannual',
        'owner' => $adminId, 'target_date' => '2026-03-31', 'status' => 'Achieved',
        'confirmed_by' => $adminId, 'confirmed_at' => '2026-02-20 10:00:00',
        'confirmation_notes' => 'Target reached a month ahead of schedule following accelerated Soshanguve and Alexandra deployments.',
        'actuals' => [
            ['label' => 'H1 2025/26', 'date' => '2025-09-30', 'value' => 45, 'comments' => 'Steady progress across Tshwane and Johannesburg.'],
            ['label' => 'H2 2025/26', 'date' => '2026-02-15', 'value' => 82, 'comments' => 'Target exceeded; confirmed achieved.'],
        ],
    ],
    [
        'project_id' => $projectIds['USF-100'] ?? $projectIds['USAASA-EC'], 'level' => 'Impact',
        'statement' => 'Indigent households supported by the Underserviced Areas Fund have reduced digital exclusion.',
        'name' => 'Digital inclusion index among supported indigent households',
        'definition' => 'Composite index (0-100) combining device access, connectivity access and usage frequency among surveyed supported households.',
        'unit' => 'index points', 'baseline' => 20, 'baseline_year' => 2023, 'target' => 90,
        'source' => 'Annual household digital inclusion survey', 'frequency' => 'Annual',
        'owner' => $adminId, 'target_date' => '2027-03-31', 'status' => 'Open',
        'actuals' => [
            ['label' => 'FY 2025/26', 'date' => '2026-03-01', 'value' => 38, 'comments' => 'First post-baseline survey wave.'],
        ],
    ],
    [
        'project_id' => $projectIds['USAASA-FS'], 'level' => 'Output',
        'statement' => 'Community WiFi hotspots are installed and operational across the Free State rollout.',
        'name' => 'Number of community WiFi hotspots installed, Free State',
        'definition' => 'Count of installed and verified community WiFi hotspots.',
        'unit' => 'hotspots', 'baseline' => 0, 'baseline_year' => 2025, 'target' => 25,
        'source' => 'Site completion records (Sites module)', 'frequency' => 'Quarterly',
        'owner' => $managerId, 'target_date' => '2025-12-31', 'current_target_date' => '2027-02-28',
        'revision' => 'Equipment procurement delay pushed the rollout schedule back one quarter.',
        'status' => 'In Progress',
        'actuals' => [
            ['label' => 'Q3 2025/26', 'date' => '2025-12-31', 'value' => 6, 'comments' => 'Botshabelo Community Centre and two others completed.'],
        ],
    ],
    [
        'project_id' => $projectIds['USAASA-NW'], 'level' => 'Outcome',
        'statement' => 'North West households reached by the rollout report improved access to online government services.',
        'name' => 'Share of reached households reporting improved access to e-government services',
        'definition' => 'Percentage of surveyed connected households reporting they can now access at least one government service online.',
        'unit' => '%', 'baseline' => 5, 'baseline_year' => 2024, 'target' => 60,
        'source' => 'Household follow-up survey', 'frequency' => 'Biannual',
        'owner' => $viewerId, 'target_date' => '2025-10-31', 'status' => 'In Progress',
        'actuals' => [
            ['label' => 'H1 2025/26', 'date' => '2025-09-15', 'value' => 18, 'comments' => 'Below plan; survey response rate lower than expected.'],
        ],
    ],
];
$indicatorIdsByName = [];
foreach ($sampleIndicators as $d) {
    $indicatorIdsByName[$d['name']] = seed_get_or_create_indicator($pdo, $d, $log);
}

// --- Indigent Applications sample data --------------------------------------
// Uses the real cascading province -> district/metro -> local hierarchy
// (includes/sa_municipalities.php) and a genuinely valid SA ID checksum for
// each applicant, generated the same way validate_sa_id_number() checks it.
function seed_sa_id(string $yymmdd, int $genderSeq, int $citizenDigit): string
{
    $first12 = $yymmdd . str_pad((string)$genderSeq, 4, '0', STR_PAD_LEFT) . $citizenDigit . '8';
    $digits = array_map('intval', str_split($first12));
    $oddSum = 0;
    for ($i = 0; $i < 12; $i += 2) {
        $oddSum += $digits[$i];
    }
    $evenConcat = '';
    for ($i = 1; $i < 12; $i += 2) {
        $evenConcat .= $digits[$i];
    }
    $evenDoubled = (string)((int)$evenConcat * 2);
    $evenSum = array_sum(array_map('intval', str_split($evenDoubled)));
    $check = (10 - (($oddSum + $evenSum) % 10)) % 10;
    return $first12 . $check;
}

function seed_sample_signature(int $seed): string
{
    $im = imagecreatetruecolor(300, 100);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    $ink = imagecolorallocate($im, 20, 30, 40);
    $y = 50 + ($seed % 7) * 3;
    for ($x = 10; $x < 280; $x += 10) {
        $y2 = 50 + (int)(20 * sin(($x + $seed * 17) / 15));
        imageline($im, $x, $y, $x + 10, $y2, $ink);
        $y = $y2;
    }
    ob_start();
    imagepng($im);
    $bytes = ob_get_clean();
    imagedestroy($im);
    return 'data:image/png;base64,' . base64_encode($bytes);
}

function seed_get_or_create_indigent(PDO $pdo, array $d, int $capturedBy, array &$log): void
{
    $stmt = $pdo->prepare('SELECT id FROM indigent_applications WHERE id_number = ?');
    $stmt->execute([$d['id_number']]);
    if ($stmt->fetchColumn()) {
        $log[] = "Indigent application for {$d['first_name']} {$d['surname']} already exists — skipped.";
        return;
    }
    $signatureFilename = store_signature_data_url(seed_sample_signature(crc32($d['id_number'])));
    $ref = next_application_reference_code($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO indigent_applications (
            reference_code, first_name, surname, id_number, id_capture_method,
            latitude, longitude, province, district_municipality, local_municipality, village_name, physical_address,
            number_of_pupils, distance_north_km, distance_south_km, distance_east_km, distance_west_km,
            monthly_income, has_functional_tv,
            declares_south_african, declares_income_under_3200, declares_sabc_concession, declares_grant_with_proof,
            signature_filename, signed_at, captured_by
        ) VALUES (?, ?, ?, ?, \'manual\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, 1, 1, ?, NOW(), ?)'
    );
    $stmt->execute([
        $ref, $d['first_name'], $d['surname'], $d['id_number'],
        $d['latitude'] ?? null, $d['longitude'] ?? null,
        $d['province'], $d['district'], $d['local'], $d['village'], $d['address'] ?? null,
        $d['pupils'], $d['north'], $d['south'], $d['east'], $d['west'],
        $d['income'], $d['tv'] ? 1 : 0,
        $signatureFilename, $capturedBy,
    ]);
    $log[] = "Created indigent application $ref ({$d['first_name']} {$d['surname']}, {$d['province']}).";
}

$sampleIndigent = [
    [
        'first_name' => 'Nomsa', 'surname' => 'Dlamini', 'id_number' => seed_sa_id('780512', 4512, 0),
        'province' => 'Eastern Cape', 'district' => 'Sarah Baartman District Municipality', 'local' => 'Makana',
        'village' => 'Rini', 'address' => 'Extension 9, Rini', 'pupils' => 3,
        'north' => 4.2, 'south' => 7.8, 'east' => 2.1, 'west' => 9.6, 'income' => 1450, 'tv' => true,
        'latitude' => -33.3053, 'longitude' => 26.5222,
    ],
    [
        'first_name' => 'Pieter', 'surname' => 'van Rooyen', 'id_number' => seed_sa_id('650221', 5033, 0),
        'province' => 'Free State', 'district' => 'Lejweleputswa District Municipality', 'local' => 'Matjhabeng',
        'village' => 'Bronville', 'address' => null, 'pupils' => 1,
        'north' => 1.5, 'south' => 3.0, 'east' => 5.5, 'west' => 2.2, 'income' => 1800, 'tv' => false,
    ],
    [
        'first_name' => 'Tebogo', 'surname' => 'Mahlangu', 'id_number' => seed_sa_id('920814', 5611, 0),
        'province' => 'Gauteng', 'district' => 'City of Tshwane', 'local' => 'City of Tshwane',
        'village' => 'Hammanskraal', 'address' => 'Block L, Hammanskraal', 'pupils' => 4,
        'north' => 6.0, 'south' => 4.4, 'east' => 3.3, 'west' => 8.1, 'income' => 2500, 'tv' => true,
        'latitude' => -25.4041, 'longitude' => 28.2871,
    ],
    [
        'first_name' => 'Zanele', 'surname' => 'Khumalo', 'id_number' => seed_sa_id('880103', 4890, 0),
        'province' => 'KwaZulu-Natal', 'district' => 'uMkhanyakude District Municipality', 'local' => 'Jozini',
        'village' => 'Ingwavuma', 'address' => null, 'pupils' => 2,
        'north' => 12.0, 'south' => 9.4, 'east' => 15.2, 'west' => 6.8, 'income' => 900, 'tv' => true,
    ],
    [
        'first_name' => 'Rendani', 'surname' => 'Mulaudzi', 'id_number' => seed_sa_id('710630', 5244, 0),
        'province' => 'Limpopo', 'district' => 'Vhembe District Municipality', 'local' => 'Thulamela',
        'village' => 'Thohoyandou', 'address' => 'Unit C, Thohoyandou', 'pupils' => 5,
        'north' => 3.3, 'south' => 11.0, 'east' => 7.7, 'west' => 4.4, 'income' => 1500, 'tv' => false,
    ],
    [
        'first_name' => 'Sipho', 'surname' => 'Nkosi', 'id_number' => seed_sa_id('830925', 5378, 0),
        'province' => 'Mpumalanga', 'district' => 'Ehlanzeni District Municipality', 'local' => 'Bushbuckridge',
        'village' => 'Thulamahashe', 'address' => null, 'pupils' => 3,
        'north' => 5.6, 'south' => 2.9, 'east' => 8.8, 'west' => 3.1, 'income' => 2100, 'tv' => true,
    ],
    [
        'first_name' => 'Anna', 'surname' => 'Beukes', 'id_number' => seed_sa_id('690417', 4765, 0),
        'province' => 'Northern Cape', 'district' => 'Namakwa District Municipality', 'local' => 'Hantam',
        'village' => 'Calvinia', 'address' => null, 'pupils' => 1,
        'north' => 20.5, 'south' => 18.0, 'east' => 22.3, 'west' => 14.7, 'income' => 1100, 'tv' => false,
    ],
    [
        'first_name' => 'Kagiso', 'surname' => 'Molefe', 'id_number' => seed_sa_id('950228', 5102, 0),
        'province' => 'North West', 'district' => 'Ngaka Modiri Molema District Municipality', 'local' => 'Mahikeng',
        'village' => 'Mahikeng Ward 2', 'address' => null, 'pupils' => 2,
        'north' => 2.2, 'south' => 6.6, 'east' => 4.1, 'west' => 5.0, 'income' => 1900, 'tv' => true,
    ],
    [
        'first_name' => 'Lindiwe', 'surname' => 'Fortuin', 'id_number' => seed_sa_id('760809', 4958, 0),
        'province' => 'Western Cape', 'district' => 'Garden Route District Municipality', 'local' => 'George',
        'village' => 'George Ward 12', 'address' => 'Thembalethu, George', 'pupils' => 3,
        'north' => 1.8, 'south' => 3.3, 'east' => 2.0, 'west' => 6.5, 'income' => 2700, 'tv' => true,
        'latitude' => -33.9628, 'longitude' => 22.4573,
    ],
];
$capturerStmt = $pdo->prepare("SELECT id FROM users WHERE role = 'indigent_capturer' LIMIT 1");
$capturerStmt->execute();
$capturerId = $capturerStmt->fetchColumn() ?: $adminId;
foreach ($sampleIndigent as $d) {
    seed_get_or_create_indigent($pdo, $d, (int)$capturerId, $log);
}

// --- Planning & Dispatch sample data ----------------------------------------
// Dispatches installers by province + region with a target and deadline.
// Two allocations below are linked to a Results Framework indicator — their
// progress updates also write/update that indicator's actuals, demonstrating
// the "report update to Results Framework" link end to end.
function seed_get_or_create_allocation(PDO $pdo, array $d, int $dispatchedBy, array &$log): void
{
    $stmt = $pdo->prepare('SELECT id FROM installer_allocations WHERE project_id = ? AND region = ? AND installer_id = ?');
    $stmt->execute([$d['project_id'], $d['region'], $d['installer_id']]);
    if ($stmt->fetchColumn()) {
        $log[] = "Allocation for region \"{$d['region']}\" already exists — skipped.";
        return;
    }
    $ref = next_allocation_reference_code($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO installer_allocations (
            reference_code, project_id, region, installer_id, indicator_id,
            target_installations, original_deadline, current_deadline, status, notes, dispatched_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'Planned\', ?, ?)'
    );
    $stmt->execute([
        $ref, $d['project_id'], $d['region'], $d['installer_id'], $d['indicator_id'] ?? null,
        $d['target'], $d['deadline'], $d['deadline'], $d['notes'] ?? null, $dispatchedBy,
    ]);
    $allocationId = (int)$pdo->lastInsertId();

    $cumulative = 0;
    foreach ($d['progress'] ?? [] as $p) {
        $cumulative = $p['completed'];
        $pdo->prepare(
            'INSERT INTO allocation_progress_updates (allocation_id, period_label, period_date, installations_completed, comments, reported_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$allocationId, $p['label'], $p['date'], $p['completed'], $p['comments'] ?? null, $d['installer_id']]);

        if (!empty($d['indicator_id'])) {
            $autoComment = "Auto-reported from Planning allocation $ref.";
            try {
                $pdo->prepare(
                    'INSERT INTO indicator_actuals (indicator_id, period_label, period_date, actual_value, comments, reported_by)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$d['indicator_id'], $p['label'], $p['date'], $p['completed'], $autoComment, $d['installer_id']]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $pdo->prepare('UPDATE indicator_actuals SET actual_value = ?, comments = ? WHERE indicator_id = ? AND period_label = ?')
                        ->execute([$p['completed'], $autoComment, $d['indicator_id'], $p['label']]);
                } else {
                    throw $e;
                }
            }
            $pdo->prepare("UPDATE indicators SET status = 'In Progress' WHERE id = ? AND status = 'Open'")->execute([$d['indicator_id']]);
        }
    }
    $newStatus = $cumulative >= $d['target'] ? 'Completed' : (strtotime($d['deadline']) < strtotime(date('Y-m-d')) ? 'Overdue' : ($cumulative > 0 ? 'In Progress' : 'Planned'));
    $pdo->prepare('UPDATE installer_allocations SET status = ? WHERE id = ?')->execute([$newStatus, $allocationId]);

    $log[] = "Created allocation $ref ({$d['region']}), status $newStatus.";
}

$ecIndicatorId = $indicatorIdsByName['Number of rural households connected via community access sites'] ?? null;
$lpIndicatorId = $indicatorIdsByName['Number of installation sites completed, Limpopo'] ?? null;

$sampleAllocations = [
    [
        'project_id' => $projectIds['USAASA-EC'], 'region' => 'Sarah Baartman District Municipality',
        'installer_id' => $fieldWorkers[0], 'indicator_id' => $ecIndicatorId,
        'target' => 15, 'deadline' => date('Y-m-d', strtotime('+45 days')),
        'notes' => 'Covers Makana and Ndlambe local municipalities.',
        'progress' => [
            ['label' => 'Week 1', 'date' => date('Y-m-d', strtotime('-20 days')), 'completed' => 7, 'comments' => 'Good early progress.'],
            ['label' => 'Week 3', 'date' => date('Y-m-d', strtotime('-3 days')), 'completed' => 15, 'comments' => 'Target reached ahead of schedule.'],
        ],
    ],
    [
        'project_id' => $projectIds['USAASA-GP'], 'region' => 'City of Tshwane',
        'installer_id' => $fieldWorkers[1], 'indicator_id' => null,
        'target' => 20, 'deadline' => date('Y-m-d', strtotime('+30 days')),
        'notes' => 'Hammanskraal and surrounding wards.',
        'progress' => [
            ['label' => 'Week 2', 'date' => date('Y-m-d', strtotime('-10 days')), 'completed' => 8, 'comments' => 'On track.'],
        ],
    ],
    [
        'project_id' => $projectIds['USAASA-KZN'], 'region' => 'uMkhanyakude District Municipality',
        'installer_id' => $fieldWorkers[2], 'indicator_id' => null,
        'target' => 12, 'deadline' => date('Y-m-d', strtotime('-5 days')),
        'notes' => 'Jozini area — delayed by seasonal access issues.',
        'progress' => [
            ['label' => 'Week 2', 'date' => date('Y-m-d', strtotime('-9 days')), 'completed' => 4, 'comments' => 'Behind plan; road access difficulties reported.'],
        ],
    ],
    [
        'project_id' => $projectIds['USAASA-WC'], 'region' => 'City of Cape Town',
        'installer_id' => $fieldWorkers[0], 'indicator_id' => null,
        'target' => 10, 'deadline' => date('Y-m-d', strtotime('+60 days')),
        'notes' => 'Khayelitsha Site C and surrounding area.',
        'progress' => [],
    ],
    [
        'project_id' => $projectIds['USAASA-LP'], 'region' => 'Vhembe District Municipality',
        'installer_id' => $fieldWorkers[1], 'indicator_id' => $lpIndicatorId,
        'target' => 18, 'deadline' => date('Y-m-d', strtotime('+40 days')),
        'notes' => 'Thulamela local municipality, Thohoyandou area.',
        'progress' => [
            ['label' => 'Week 2', 'date' => date('Y-m-d', strtotime('-6 days')), 'completed' => 9, 'comments' => 'Halfway to target.'],
        ],
    ],
    [
        'project_id' => $projectIds['USAASA-MP'], 'region' => 'Ehlanzeni District Municipality',
        'installer_id' => $fieldWorkers[2], 'indicator_id' => null,
        'target' => 14, 'deadline' => date('Y-m-d', strtotime('+25 days')),
        'notes' => 'Bushbuckridge local municipality.',
        'progress' => [
            ['label' => 'Week 3', 'date' => date('Y-m-d', strtotime('-2 days')), 'completed' => 14, 'comments' => 'Target reached.'],
        ],
    ],
];
foreach ($sampleAllocations as $d) {
    seed_get_or_create_allocation($pdo, $d, $plannerId, $log);
}

$pageTitle = 'Seed sample data';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Sample data seeded</h1>
        <p class="subtitle">Province projects, workflow sample users, and sample site completions</p>
    </div>
</div>

<div class="card">
    <h2>Sample login credentials (all use password <code>Demo@12345</code> — change before real use)</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Username</th><th>Full name</th><th>Role</th></tr></thead>
        <tbody>
            <tr><td>jmokoena</td><td>Johannes Mokoena</td><td>Field Worker</td></tr>
            <tr><td>tnkosi</td><td>Thandiwe Nkosi</td><td>Field Worker</td></tr>
            <tr><td>spillay</td><td>Suresh Pillay</td><td>Field Worker</td></tr>
            <tr><td>lvandermerwe</td><td>Lerato van der Merwe</td><td>Back Office</td></tr>
            <tr><td>bmahlangu</td><td>Bongani Mahlangu</td><td>Back Office</td></tr>
            <tr><td>nkhumalo</td><td>Nomvula Khumalo</td><td>Approver</td></tr>
            <tr><td>dvanwyk</td><td>Dawid van Wyk</td><td>Approver</td></tr>
            <tr><td>tshabangu</td><td>Thabo Shabangu</td><td>Manager</td></tr>
            <tr><td>nmokoena</td><td>Nonhlanhla Mokoena</td><td>Viewer</td></tr>
            <tr><td>kmotaung</td><td>Kgomotso Motaung</td><td>Planner</td></tr>
        </tbody>
    </table>
    </div>
</div>

<div class="card">
    <h2>What happened</h2>
    <ul class="history-list">
        <?php foreach ($log as $line): ?>
            <li><?= e($line) ?></li>
        <?php endforeach; ?>
    </ul>
</div>

<p>
    <a class="btn" href="<?= APP_BASE_URL ?>/index.php">View dashboard &amp; charts</a>
    <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/projects/list.php">View projects</a>
    <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/sites/list.php">View site completions</a>
    <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/findings/list.php">View findings</a>
    <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/risks/list.php">View risks</a>
    <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indicators/framework.php">View results framework</a>
    <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/planning/dashboard.php">View planning &amp; dispatch</a>
    <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indigent/list.php">View indigent applications</a>
    <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/users/list.php">View users</a>
</p>

<?php include __DIR__ . '/../includes/footer.php'; ?>
