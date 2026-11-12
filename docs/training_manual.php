<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin']);

$pageTitle = 'Training Manual';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Training Manual</h1>
        <p class="subtitle">USAASA MEL System &mdash; how to use every module</p>
    </div>
    <div class="actions-row no-print">
        <button type="button" class="btn" onclick="window.print()">Open as PDF</button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/docs/index.php">&larr; Documentation</a>
    </div>
</div>

<div class="doc-toc">
    <h2>Contents</h2>
    <ul>
        <li><a href="#getting-started">1. Getting started</a></li>
        <li><a href="#projects">2. Projects &amp; Programmes</a></li>
        <li><a href="#findings">3. Findings &amp; Lessons Register</a></li>
        <li><a href="#risks">4. Risk Register</a></li>
        <li><a href="#indicators">5. Results Framework (Indicators)</a></li>
        <li><a href="#planning">6. Planning &amp; Dispatch</a></li>
        <li><a href="#sites">7. Site Completions &amp; Payment Approval</a></li>
        <li><a href="#indigent">8. Indigent Applications</a></li>
        <li><a href="#import-export">9. Import &amp; export, batch operations</a></li>
        <li><a href="#ai-reports">10. AI Reports</a></li>
        <li><a href="#data-captured">11. Data Captured</a></li>
        <li><a href="#users">12. Users &amp; roles</a></li>
        <li><a href="#settings">13. Settings &amp; branding</a></li>
        <li><a href="#cms">14. Pages (CMS)</a></li>
        <li><a href="#notifications">15. Email notifications</a></li>
        <li><a href="#account">16. Your account &amp; password</a></li>
    </ul>
</div>

<div class="doc-callout">
    Screenshots below are captured from a live, logged-in session of this system (<code>assets/screenshots/</code>),
    using the sample/seeded data already in the database. If you customise branding, roles, or sample data, these
    will drift out of date — recapture them from the running system when that happens.
</div>

<div class="doc-section" id="getting-started">
    <h2>1. Getting started</h2>
    <p>Sign in at the login page with the username and password an administrator gave you. If this is your first
    login, or an admin has just reset your password, you'll be sent straight to <strong>Change password</strong>
    and can't use the rest of the system until you set a new one.</p>
    <?= doc_screenshot('login.png', 'Login page.') ?>
    <h3>The dashboard</h3>
    <p>After logging in you land on the Dashboard: provincial deployment charts (target vs. actual sites, by
    province), summary tiles for site completions and indigent applications, Planning &amp; Dispatch activity, and
    recent-activity tables. What you see here depends on your role &mdash; every tile links through to the module
    it summarises.</p>
    <?= doc_screenshot('dashboard.png', 'Dashboard, showing the stat tiles and the provincial target-vs-actual bar chart for every module.') ?>
    <h3>The sidebar</h3>
    <p>The left sidebar lists every module you have access to. Items you can't act on still usually appear (e.g.
    an Installer can see the Risk Register) &mdash; the system enforces what you can actually <em>do</em> on the
    page itself, not just by hiding the link. An "Administration" section appears at the bottom for admin-tier
    accounts, with a further "Settings" link for super admin accounts only.</p>
</div>

<div class="doc-section" id="projects">
    <h2>2. Projects &amp; Programmes</h2>
    <p><strong>Who:</strong> admin/manager can add and edit; everyone can view.</p>
    <p>Every other module (findings, risks, indicators, sites, planning) is logged against a Project. Projects are
    split into two Programmes &mdash; <strong>Broadband Connectivity</strong> (province-level internet access
    rollouts) and <strong>BDM</strong> (Broadcasting and Digital Migration &mdash; set-top box distribution) &mdash;
    shown as two separate sections on the Projects page. Most projects are tagged with a province; a
    province-tagged project is what Planning &amp; Dispatch offers when allocating installers.</p>
    <h3>To add a project</h3>
    <ol>
        <li>Open <strong>Projects</strong> in the sidebar.</li>
        <li>Fill in the "Add a project" form: code, name, programme, province (or leave as "not province-specific"
        for a national fund), and optional household/site targets.</li>
        <li>Click <strong>Add project</strong>.</li>
    </ol>
    <?= doc_screenshot('projects.png', 'Projects page, showing the two programme sections (Broadband Connectivity and BDM) and the add-project form.') ?>
</div>

<div class="doc-section" id="findings">
    <h2>3. Findings &amp; Lessons Register</h2>
    <p><strong>Who:</strong> admin/manager can log and manage; everyone can view.</p>
    <p>This is the C3.5 evaluation register: each entry separates the evidence-based finding, the lesson drawn
    from it, and the agreed management response into distinct fields, alongside source/date, evidence and
    limitations, implication, owner, and a deadline.</p>
    <h3>To log a finding</h3>
    <ol>
        <li>Open <strong>Findings &amp; Lessons Register</strong>, click <strong>+ New finding / lesson</strong>.</li>
        <li>Complete every field. The deadline you set here becomes the <em>original</em> deadline and cannot be
        changed later except through "Revise deadline".</li>
        <li>Click <strong>Log finding</strong>.</li>
    </ol>
    <h3>Deadlines, overdue flagging, and closure</h3>
    <p>If the current deadline passes while a finding is still Open/In Progress, it's automatically flagged
    <strong>Overdue</strong> the next time any page loads &mdash; no manual step needed. To move the deadline,
    open the finding and use <strong>Revise deadline</strong>: this requires a reason, and keeps the original
    date and every past revision on permanent record. A finding only reaches <strong>Closed</strong> through the
    explicit <strong>Confirm closure</strong> action, which requires a recorded outcome.</p>
    <?= doc_screenshot('finding-view.png', 'A finding\'s view page, showing the status badge and the Revise deadline / Confirm closure actions.') ?>
</div>

<div class="doc-section" id="risks">
    <h2>4. Risk Register</h2>
    <p><strong>Who:</strong> admin/manager can log and manage; everyone can view.</p>
    <p>Each risk records a category, description, existing controls, a mitigation action, an owner, and a
    deadline. <strong>Likelihood</strong> and <strong>Impact</strong> are chosen from Low/Medium/High; the
    <strong>Risk Rating</strong> (Low/Medium/High/Critical) is then calculated automatically from a standard 3&times;3
    matrix &mdash; you can't set it directly, so it's always consistent with the two inputs.</p>
    <h3>To log a risk</h3>
    <ol>
        <li>Open <strong>Risk Register</strong>, click <strong>+ New risk</strong>.</li>
        <li>Complete the form, choosing Likelihood and Impact &mdash; watch the rating that results.</li>
        <li>Click <strong>Log risk</strong>.</li>
    </ol>
    <p>Overdue flagging, deadline revision, and closure work exactly like the Findings Register above.</p>
    <?= doc_screenshot('risks-list.png', 'Risk Register list, showing the colour-coded rating badges (Low/Medium/High/Critical).') ?>
</div>

<div class="doc-section" id="indicators">
    <h2>5. Results Framework (Indicators)</h2>
    <p><strong>Who:</strong> admin/manager can log and manage; everyone can view.</p>
    <p>Indicators sit at a <strong>result level</strong> &mdash; Impact, Outcome, or Output &mdash; under a project,
    with a baseline (value + year), an end-term target, a data source, collection frequency, and a target date.
    The <strong>Results Framework</strong> page groups them hierarchically (project &rarr; result level &rarr;
    result statement); the <strong>Indicators</strong> list is the flat, filterable register.</p>
    <h3>To report a period's actual value</h3>
    <ol>
        <li>Open the indicator's view page.</li>
        <li>Under "Reported actual values", fill in a period label (e.g. "Q2 2026/27"), the period end date, and
        the actual value, with optional comments.</li>
        <li>Click <strong>Add reported period</strong>.</li>
    </ol>
    <p>Progress is always calculated from the <em>most recent</em> reported actual against baseline and target
    &mdash; never a value you type directly. An indicator only reaches <strong>Achieved</strong> through
    <strong>Confirm achievement</strong>, which requires a note. Target dates follow the same
    overdue/revision rules as the other two registers.</p>
    <?= doc_screenshot('indicator-view.png', 'An indicator\'s view page, showing baseline/target, the progress bar, and the reported-actuals history table.') ?>
</div>

<div class="doc-section" id="planning">
    <h2>6. Planning &amp; Dispatch</h2>
    <p><strong>Who:</strong> admin/manager/planner can dispatch; the assigned installer, back office, admin or
    manager can log progress; everyone can view.</p>
    <p>This is where installers get allocated work: a province (project), a region (district municipality or
    metro &mdash; the dropdown only offers real districts/metros for the province you picked), an installer, a
    target number of installations, and a deadline. An allocation can optionally link to a Results Framework
    indicator.</p>
    <h3>To dispatch an allocation</h3>
    <ol>
        <li>Open <strong>Planning &amp; Dispatch</strong>, click <strong>+ New allocation</strong>.</li>
        <li>Pick the province first &mdash; the Region dropdown then populates with that province's real
        districts/metros.</li>
        <li>Pick the installer, set the target and deadline, optionally link an indicator, and add notes.</li>
        <li>Click <strong>Dispatch allocation</strong>.</li>
    </ol>
    <h3>To log progress</h3>
    <ol>
        <li>Open the allocation's view page.</li>
        <li>Under "Report progress", enter a period label, date, and the <strong>cumulative</strong> installations
        completed so far (not just this period's additions), with optional comments.</li>
        <li>Click <strong>Add progress update</strong>. Reaching the target automatically marks the allocation
        <strong>Completed</strong>; if it's linked to an indicator, this same update also records an actual value
        on that indicator &mdash; Planning reports flow into the Results Framework automatically.</li>
    </ol>
    <?= doc_screenshot('planning-form.png', 'Planning & Dispatch\'s New Allocation form, showing the province dropdown and the installer/target/deadline fields.') ?>
    <p>The <strong>dashboard</strong> link on this module's page shows target-vs-actual by province and a
    region-level breakdown table.</p>
</div>

<div class="doc-section" id="sites">
    <h2>7. Site Completions &amp; Payment Approval</h2>
    <p><strong>Who:</strong> a three-step approval chain with segregation of duties.</p>
    <table>
        <thead><tr><th>Step</th><th>Role</th><th>Action</th><th>Resulting status</th></tr></thead>
        <tbody>
        <tr><td>1</td><td>Installer (<code>field_worker</code>)</td><td>Submits a completed site with proof of evidence (POE) files</td><td>Submitted</td></tr>
        <tr><td>2</td><td>Reviewer (<code>back_office</code>)</td><td>Verifies it, or rejects it back to the installer with a reason</td><td>Verified / Rejected</td></tr>
        <tr><td>3</td><td>Approver</td><td>Signs it off, or rejects it</td><td>Approved (ready for payment) / Rejected</td></tr>
        </tbody>
    </table>
    <p><code>admin</code> can act at any step, for fixing mistakes. <code>manager</code>/<code>viewer</code> can see
    everything read-only. Actual payment processing happens outside this system, in USAASA's finance system &mdash;
    "Approved" here means "cleared to pay", not "paid".</p>
    <h3>To submit a completed site (Installer)</h3>
    <ol>
        <li>Open <strong>Site Completions</strong>, click <strong>+ Submit completed site</strong>.</li>
        <li>Fill in site name, project, location, households connected, completion date, and attach at least one
        POE file.</li>
        <li>Click <strong>Submit</strong>.</li>
    </ol>
    <h3>To verify or approve</h3>
    <p>Open the site's view page. A Reviewer sees <strong>Verify</strong> / <strong>Reject</strong> buttons on a
    Submitted site; an Approver sees <strong>Approve</strong> / <strong>Reject</strong> on a Verified one. A
    rejected site returns to the Installer, who can resubmit it.</p>
    <?= doc_screenshot('site-view-verified.png', 'A site\'s view page at the Verified stage.') ?>
    <p>Use <strong>Preview</strong> (from the list) for a printable completion certificate with all POE
    attachments embedded &mdash; use your browser's Print &rarr; Save as PDF.</p>
</div>

<div class="doc-section" id="indigent">
    <h2>8. Indigent Applications</h2>
    <p><strong>Who:</strong> admin/manager/installer/data capturer can capture and edit; everyone can view.</p>
    <p>Household data capture for the SABC TV licence concession / set-top-box subsidy in rural areas: applicant
    details, GPS coordinate, household photo, address (with real cascading Province &rarr; District/Metro &rarr;
    Local Municipality dropdowns), ID number (scan the barcode or type it &mdash; it's checksum-validated either
    way), distances to the local municipality office in four directions, income, and a declaration the applicant
    signs on-screen.</p>
    <h3>To capture an application</h3>
    <ol>
        <li>Open <strong>Indigent Applications</strong>, click <strong>+ Capture application</strong>.</li>
        <li>Scan or type the ID number.</li>
        <li>Optionally tap <strong>Use current location</strong> for GPS, and attach a household photo.</li>
        <li>Pick the province, then the district/metro (the Local Municipality dropdown then populates for that
        district).</li>
        <li>Complete the rest of the form, then have the applicant sign in the signature box.</li>
        <li>Click <strong>Capture application</strong>.</li>
    </ol>
    <?= doc_screenshot('indigent-form.png', 'The capture form, showing the barcode-scan button, GPS button, cascading province/district/local dropdowns, declaration checkboxes, and the signature pad.') ?>
    <p>From a captured application's view page, <strong>Preview &amp; print</strong> gives a printable PDF-via-print
    copy; <strong>Export CSV</strong>/<strong>Export Excel</strong> export that one record. Only a super admin can
    permanently delete an application.</p>
</div>

<div class="doc-section" id="import-export">
    <h2>9. Import &amp; export, batch operations</h2>
    <p>Findings, Risks, Results Framework, Site Completions, Indigent Applications and Planning &amp; Dispatch all
    share the same export/import pattern &mdash; learn it once here.</p>
    <h3>Export</h3>
    <p>On each module's list page, admin/manager (and planner, for Planning) see an "Export" card: <strong>Export
    CSV</strong>, <strong>Export Excel</strong>, and (where available) <strong>Export / print PDF</strong>. These
    respect whatever filter is set above them, including a date range where offered. A checkbox per row plus
    "Export selected" buttons let you export only the rows you tick, ignoring the filter.</p>
    <?= doc_screenshot('indigent-list-export.png', 'The Indigent Applications list, showing row checkboxes and the Import & export card.') ?>
    <h3>Import (Site Completions, Indigent Applications, Planning &amp; Dispatch)</h3>
    <p>These three modules also accept a CSV or Excel (.xlsx) file to bulk-load data. On the Import page you
    choose:</p>
    <ul>
        <li><strong>If a row matches an existing record</strong> (by its reference, or a module-specific natural
        key) &mdash; either <strong>flag it as a duplicate and reject it</strong> (the default, safest choice), or
        <strong>update the existing record instead</strong>. Update mode only ever touches the core data fields
        &mdash; it never changes status, approvals, or deadlines, which always go through their own proper
        workflow screens.</li>
        <li><strong>An optional date range</strong> &mdash; rows whose own date (completed date, captured date, or
        deadline, depending on the module) falls outside the range are skipped without being counted as either a
        duplicate or an error.</li>
    </ul>
    <p>After importing, you get a per-row result: created / updated / duplicate-rejected / invalid, each with a
    reason. Every import run is also logged for an administrator to review on <strong>Data Captured</strong>.</p>
    <?= doc_screenshot('indigent-import-form.png', 'Indigent Applications\' Import page, showing the file upload field, the duplicate-handling mode selector, and the optional date range fields.') ?>
</div>

<div class="doc-section" id="ai-reports">
    <h2>10. AI Reports</h2>
    <p><strong>Who:</strong> admin/manager.</p>
    <p>An automated, rule-based report integrating every module above into one document &mdash; no external AI
    service is called; every figure is a live database total at the moment you generate it, and every sentence of
    narrative is filled in from those figures.</p>
    <h3>To generate a report</h3>
    <ol>
        <li>Open <strong>AI Reports</strong>, click <strong>+ Generate report</strong>.</li>
        <li>Choose a period type: Monthly (a calendar month), or Quarterly/Half-Yearly/Yearly (South Africa's
        government fiscal year, 1 April&ndash;31 March &mdash; e.g. fiscal year "2026" means 2026/27).</li>
        <li>Pick the specific month/quarter/half/year, and click <strong>Generate report</strong>.</li>
    </ol>
    <p>The report opens with an Executive Summary, then a section per module (stats + narrative + a short table of
    notable records), then a Projects/Programme progress table. Use <strong>Print / Save as PDF</strong> to export
    it. Once generated, a report is a frozen snapshot &mdash; reopening it later always shows exactly what was
    computed at generation time, even if the live data has since changed.</p>
    <?= doc_screenshot('ai-report-view.png', 'A generated AI Report, showing the Executive Summary, every module\'s stat tiles and narrative, and the Projects/Programme table.') ?>
</div>

<div class="doc-section" id="data-captured">
    <h2>11. Data Captured</h2>
    <p><strong>Who:</strong> admin and super admin only.</p>
    <p>Two consolidated views for administrator oversight: every bulk-import run across every module (filterable
    by module and date, with an expandable per-row detail for each run), and data captured directly by
    installers &mdash; site completions, with a per-installer totals table and a filterable, date-ranged detail
    list.</p>
    <?= doc_screenshot('data-captured.png', 'The Data Captured page, showing the import-activity stat tiles and the per-installer totals table.') ?>
</div>

<div class="doc-section" id="users">
    <h2>12. Users &amp; roles</h2>
    <p><strong>Who:</strong> admin manages most accounts; only <strong>super admin</strong> can manage admin-tier
    accounts (including granting the Super Admin flag itself).</p>
    <table>
        <thead><tr><th>Role</th><th>What they do</th></tr></thead>
        <tbody>
        <tr><td>Admin</td><td>Full access everywhere except the handful of super-admin-exclusive actions below.</td></tr>
        <tr><td>Super Admin</td><td>Everything Admin has, plus: manage other admin-tier accounts, Settings/SMTP/sample-data seeding, permanent deletes (Indigent/CMS), and the Access Review page below. Not a separate role value &mdash; it's an Admin account with the Super Admin flag set.</td></tr>
        <tr><td>Manager</td><td>Manages projects, findings, risks, indicators, planning, AI reports &mdash; not the site completion/payment chain.</td></tr>
        <tr><td>Viewer</td><td>Read-only everywhere.</td></tr>
        <tr><td>Installer (<code>field_worker</code>)</td><td>Submits site completions; can also be dispatched allocations and capture indigent applications.</td></tr>
        <tr><td>Reviewer (<code>back_office</code>)</td><td>Verifies submitted site completions.</td></tr>
        <tr><td>Approver</td><td>Signs off verified sites as ready for payment.</td></tr>
        <tr><td>Data Capturer (<code>indigent_capturer</code>)</td><td>Captures indigent applications only &mdash; no site-completion access.</td></tr>
        <tr><td>Planner</td><td>Dispatches and revises Planning &amp; Dispatch allocations only &mdash; not projects, findings, risks, or the results framework.</td></tr>
        </tbody>
    </table>
    <h3>To add a user</h3>
    <ol>
        <li>Open <strong>Users</strong> (under Administration), fill in the "Add a user" form, choose a role, and
        set a password.</li>
        <li>Click <strong>Add user</strong>. They'll be forced to set their own password at first login.</li>
    </ol>
    <?= doc_screenshot('users-list.png', 'The Users page, showing the add-user form and the role/status columns in the users table.') ?>

    <h3>Access Review (super admin only)</h3>
    <p>Shows the full role &rarr; module access matrix as a reference table, plus every account's last login and
    last access review, flagging accounts with no review on file, a review older than 90 days, or no recent
    login. Click <strong>Confirm reviewed</strong> once you've checked an account's role and status are still
    appropriate; click <strong>Edit</strong> to change them. Each row's "N modules" expands into that account's
    resolved, linked module list.</p>
    <?= doc_screenshot('access-review.png', 'The Access Review page, showing the role/module matrix and the per-account review status with the Review due / No recent login flags.') ?>
    <h3>Denying a specific profile's access to a module</h3>
    <p>Unlike everything else above, this one is a <strong>real, enforced restriction</strong> &mdash; not just a
    reference view. On a user's <strong>Edit user</strong> form, the "Module access for this profile" card lists
    every module that role can reach, each with a <strong>Deny</strong> checkbox. Tick one, click <strong>Update
    module access</strong>, and that profile is immediately blocked from that module &mdash; a 403 on every page
    in it &mdash; regardless of what their role would otherwise allow. Every other module they have stays exactly
    as accessible as before. You can't deny your own account's access this way, to avoid locking yourself out;
    ask another super admin if that's ever genuinely needed. Denying a non-admin-tier account is available to
    Admin; denying an admin-tier account is Super Admin only.</p>
    <?= doc_screenshot('deny-access.png', 'The Module access for this profile card, showing the Deny checkboxes and a module already denied, flagged with a Denied badge.') ?>
</div>

<div class="doc-section" id="settings">
    <h2>13. Settings &amp; branding</h2>
    <p><strong>Who:</strong> super admin only.</p>
    <p>Sitewide branding (site name, tagline, logo, footer text) and the SMTP configuration used for email
    notifications (see below). The logo, if uploaded, shows top-left in the header on every page.</p>
    <?= doc_screenshot('settings.png', 'The Settings page, showing the branding fields and the SMTP configuration fields.') ?>
</div>

<div class="doc-section" id="cms">
    <h2>14. Pages (CMS)</h2>
    <p><strong>Who:</strong> admin manages; everyone can view published pages via "Pages" in the nav.</p>
    <p>Simple content pages (a help page, a glossary) using lightweight formatting &mdash; <code># Heading</code>,
    <code>**bold**</code>, <code>*italic*</code>, <code>- bullet</code>, and
    <code>[link text](https://example.com)</code>. Raw HTML isn't allowed, so pasted formatting always renders as
    plain text rather than breaking the page.</p>
    <?= doc_screenshot('cms-list.png', 'Manage Pages, showing the page list.') ?>
    <?= doc_screenshot('cms-form.png', 'A page\'s edit form, with the formatting hint text.') ?>
</div>

<div class="doc-section" id="notifications">
    <h2>15. Email notifications</h2>
    <p>When an installer's submitted POE status changes (verified, approved, or rejected), the system emails them
    via SMTP, configured under Settings. There's no external email-sending library &mdash; it's a hand-rolled SMTP
    client over a raw socket. Use the <strong>Send test email</strong> button on Settings to confirm your SMTP
    details work before relying on it.</p>
</div>

<div class="doc-section" id="account">
    <h2>16. Your account &amp; password</h2>
    <p>Use <strong>Change password</strong> in the sidebar at any time. Five failed login attempts locks an
    account for 15 minutes &mdash; even the correct password is rejected while locked, with a countdown shown; ask
    an admin to unlock it sooner if needed (editing the account in Users clears the lock).</p>
    <?= doc_screenshot('change-password.png', 'The Change password page.') ?>
</div>

<p><a href="<?= APP_BASE_URL ?>/docs/index.php">&larr; Back to Documentation</a></p>

<?php include __DIR__ . '/../includes/footer.php'; ?>
