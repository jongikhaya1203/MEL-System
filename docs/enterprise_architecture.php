<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin']);

$pageTitle = 'Enterprise Architecture';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Enterprise Architecture</h1>
        <p class="subtitle">USAASA MEL System</p>
    </div>
    <div class="actions-row no-print">
        <button type="button" class="btn" onclick="window.print()">Open as PDF</button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/docs/index.php">&larr; Documentation</a>
    </div>
</div>

<div class="doc-toc">
    <h2>Contents</h2>
    <ul>
        <li><a href="#context">1. System context &amp; business purpose</a></li>
        <li><a href="#principles">2. Architecture style &amp; principles</a></li>
        <li><a href="#components">3. Component / module map</a></li>
        <li><a href="#data">4. Data architecture</a></li>
        <li><a href="#stack">5. Technology stack</a></li>
        <li><a href="#integration">6. Integration points</a></li>
        <li><a href="#deployment">7. Deployment architecture</a></li>
        <li><a href="#nonfunctional">8. Non-functional characteristics</a></li>
        <li><a href="#decisions">9. Key architecture decisions</a></li>
    </ul>
</div>

<div class="doc-section" id="context">
    <h2>1. System context &amp; business purpose</h2>
    <p>The MEL (Monitoring, Evaluation &amp; Learning) System supports USAASA's (Universal Service and Access
    Agency of South Africa) two connectivity programmes &mdash; <strong>Broadband Connectivity</strong>
    (province-level internet access rollout) and <strong>BDM</strong> (Broadcasting and Digital Migration, set-top
    box distribution) &mdash; end to end: evaluation findings and lessons (C3.5), risk management, a results
    framework against planned targets, field dispatch and progress tracking, the site-completion-to-payment
    approval chain, and household-level indigent beneficiary data capture for the SABC TV licence concession.
    An automated reporting layer (AI Reports) then rolls all of that up into Monthly/Quarterly/Half-Yearly/Yearly
    integrated reports.</p>
    <p>Primary users: field installers, back-office reviewers, payment approvers, dedicated data capturers and
    planners, M&amp;E managers, and system/super administrators &mdash; see the Training Manual's role table for
    the full breakdown.</p>
</div>

<div class="doc-section" id="principles">
    <h2>2. Architecture style &amp; principles</h2>
    <p><strong>Monolithic, server-rendered PHP.</strong> Every page is a plain PHP script that queries MySQL via
    PDO and renders HTML directly &mdash; no framework, no templating engine, no build step, no SPA/client-side
    routing. A request is easy to trace end to end by reading one file.</p>
    <p><strong>Zero external dependencies, deliberately.</strong> No Composer packages, no JS libraries, no
    third-party API calls (the one documented exception &mdash; SMTP email &mdash; talks directly to a mail
    server the user configures, not a SaaS API). Where a lesser system would reach for a library, this one uses a
    native browser API or a small hand-rolled implementation instead:</p>
    <table>
        <thead><tr><th>Need</th><th>What a typical stack uses</th><th>What this system uses instead</th></tr></thead>
        <tbody>
        <tr><td>Barcode scanning</td><td>A JS barcode library</td><td>The browser's native <code>BarcodeDetector</code> API</td></tr>
        <tr><td>GPS capture</td><td>A mapping SDK</td><td>The browser's native Geolocation API</td></tr>
        <tr><td>Digital signatures</td><td>A signature-pad library</td><td>A &lt;canvas&gt; + pointer events, ~40 lines</td></tr>
        <tr><td>Charts</td><td>A charting library (Chart.js etc.)</td><td>Server-generated inline SVG</td></tr>
        <tr><td>Excel export</td><td>PhpSpreadsheet or similar</td><td>An HTML table served as <code>.xls</code> with the Excel MIME type</td></tr>
        <tr><td>Excel import</td><td>A spreadsheet-parsing library</td><td><code>ZipArchive</code> + <code>SimpleXML</code> over the real OOXML parts (both built into PHP)</td></tr>
        <tr><td>PDF output</td><td>A PDF-generation library</td><td>A print-styled HTML page + the browser's own Print &rarr; Save as PDF</td></tr>
        <tr><td>Outbound email</td><td>An email-sending library/API</td><td>A hand-rolled SMTP client over a raw socket</td></tr>
        </tbody>
    </table>
    <p>This trades a small amount of hand-written code for zero supply-chain surface, zero version-drift risk, and
    a system that runs unmodified on any PHP 8 + MySQL install with no package manager at all.</p>
    <p><strong>Server-side authorization, not UI hiding.</strong> Every access rule is enforced in PHP
    (<code>require_role()</code>/<code>require_login()</code>) before a page does anything, independent of
    whether a link to it is shown. See the Security Architecture document for the full model.</p>
    <p><strong>Audit trail over mutation.</strong> Fields that represent an agreed commitment (a deadline, a
    target date, an ID number's capture method) are never silently overwritten &mdash; a change is a new row in a
    companion <code>*_revisions</code> table, with who/when/why, alongside the current value. See section 4.</p>
</div>

<div class="doc-section" id="components">
    <h2>3. Component / module map</h2>
    <table>
        <thead><tr><th>Directory</th><th>Module</th><th>Depends on</th></tr></thead>
        <tbody>
        <tr><td><code>findings/</code></td><td>Findings &amp; Lessons Register (C3.5)</td><td>projects, users</td></tr>
        <tr><td><code>risks/</code></td><td>Risk Register</td><td>projects, users</td></tr>
        <tr><td><code>indicators/</code></td><td>Results Framework</td><td>projects, users</td></tr>
        <tr><td><code>planning/</code></td><td>Planning &amp; Dispatch</td><td>projects, users, indicators (optional link), <code>sa_municipalities.php</code></td></tr>
        <tr><td><code>sites/</code></td><td>Site Completions &amp; Payment Approval</td><td>projects, users</td></tr>
        <tr><td><code>indigent/</code></td><td>Indigent Applications</td><td>users, <code>sa_municipalities.php</code></td></tr>
        <tr><td><code>ai_reports/</code></td><td>AI Reports</td><td>reads from every module above + projects</td></tr>
        <tr><td><code>reports/</code></td><td>Data Captured (admin report)</td><td>import_log, sites, users</td></tr>
        <tr><td><code>projects/</code></td><td>Projects &amp; Programmes</td><td>users</td></tr>
        <tr><td><code>users/</code></td><td>User &amp; role management</td><td>&mdash;</td></tr>
        <tr><td><code>settings/</code></td><td>Branding + SMTP configuration</td><td>&mdash;</td></tr>
        <tr><td><code>cms/</code></td><td>Content pages</td><td>users</td></tr>
        <tr><td><code>docs/</code></td><td>This documentation</td><td>&mdash;</td></tr>
        <tr><td><code>includes/</code></td><td>Shared: auth, CSRF, functions, the AI report engine, the SA municipal hierarchy data</td><td>&mdash;</td></tr>
        <tr><td><code>cron/</code></td><td>Optional scheduled overdue-flagging, independent of page views</td><td>findings, risks, indicators, planning</td></tr>
    </table>
    <p>Every module follows the same file pattern: <code>list.php</code> (filterable register) /
    <code>form.php</code> + <code>save.php</code> (create/edit) / <code>view.php</code> (detail) /
    module-specific action scripts (<code>revise_deadline.php</code>, <code>approve.php</code>, etc.) /
    <code>export.php</code> + <code>print.php</code> (and <code>import.php</code>, where built).</p>
</div>

<div class="doc-section" id="data">
    <h2>4. Data architecture</h2>
    <p>MySQL/MariaDB, InnoDB, one database. <code>database/schema.sql</code> is the single source of truth &mdash;
    additive via <code>CREATE TABLE IF NOT EXISTS</code> / <code>ALTER TABLE ... ADD COLUMN IF NOT EXISTS</code>,
    safe to re-run.</p>
    <h3>Core entity groups</h3>
    <table>
        <thead><tr><th>Group</th><th>Tables</th></tr></thead>
        <tbody>
        <tr><td>Identity</td><td><code>users</code> (role + <code>is_super_admin</code> flag &mdash; see Security Architecture)</td></tr>
        <tr><td>Projects</td><td><code>projects</code> (province, programme, targets)</td></tr>
        <tr><td>Findings</td><td><code>findings_register</code>, <code>deadline_revisions</code></td></tr>
        <tr><td>Risks</td><td><code>risks</code>, <code>risk_deadline_revisions</code></td></tr>
        <tr><td>Indicators</td><td><code>indicators</code>, <code>indicator_target_revisions</code>, <code>indicator_actuals</code> (append-only history)</td></tr>
        <tr><td>Planning</td><td><code>installer_allocations</code>, <code>allocation_progress_updates</code> (append-only), <code>allocation_deadline_revisions</code></td></tr>
        <tr><td>Sites</td><td><code>sites</code>, <code>site_poe_documents</code> (append-only)</td></tr>
        <tr><td>Indigent</td><td><code>indigent_applications</code></td></tr>
        <tr><td>Reporting</td><td><code>ai_reports</code> (frozen JSON snapshot per generated report), <code>import_log</code> (JSON per-row detail per import run)</td></tr>
        <tr><td>Content/config</td><td><code>cms_pages</code>, <code>site_settings</code>, <code>notification_log</code></td></tr>
        </tbody>
    </table>
    <h3>Recurring patterns</h3>
    <ul>
        <li><strong>original/current split + revisions table</strong>: findings/risks/indicators/allocations all
        carry an <code>original_*</code> column (set once, never touched again) and a <code>current_*</code>
        column (changed only via a logged revision row with a required reason).</li>
        <li><strong>Append-only history</strong>: <code>indicator_actuals</code> and
        <code>allocation_progress_updates</code> never update a row in place &mdash; a new period is a new row, so
        the full reporting history stays intact and auditable.</li>
        <li><strong>Frozen snapshots</strong>: <code>ai_reports.snapshot</code> stores the fully computed report
        as JSON at generation time, independent of later changes to the live data it was computed from.</li>
        <li><strong>Reference data as PHP, not a table</strong>: South Africa's province/district/metro/local
        municipality hierarchy (<code>includes/sa_municipalities.php</code>) is a static PHP array, not a database
        table &mdash; it's read-only reference data that doesn't need its own CRUD.</li>
    </ul>
</div>

<div class="doc-section" id="stack">
    <h2>5. Technology stack</h2>
    <table>
        <thead><tr><th>Layer</th><th>Technology</th></tr></thead>
        <tbody>
        <tr><td>Language/runtime</td><td>PHP 8, via XAMPP's Apache</td></tr>
        <tr><td>Database</td><td>MySQL/MariaDB (this install runs on a non-default port, 3311 &mdash; see <code>config/config.php</code>)</td></tr>
        <tr><td>Data access</td><td>PDO, prepared statements everywhere, <code>PDO::ATTR_ERRMODE::EXCEPTION</code></td></tr>
        <tr><td>Frontend</td><td>Server-rendered HTML + one hand-written CSS file (<code>assets/css/style.css</code>) + vanilla JS per page (no bundler, no framework)</td></tr>
        <tr><td>PHP extensions used</td><td><code>ZipArchive</code> + <code>SimpleXML</code> (XLSX read), <code>GD</code> (signature image validation), <code>fileinfo</code> (upload content-type checks)</td></tr>
        <tr><td>Browser APIs used</td><td>Geolocation, <code>BarcodeDetector</code>, <code>getUserMedia</code>, Canvas, native print-to-PDF</td></tr>
        <tr><td>Outbound integration</td><td>SMTP (hand-rolled client), configured per-deployment under Settings</td></tr>
        </tbody>
    </table>
</div>

<div class="doc-section" id="integration">
    <h2>6. Integration points</h2>
    <p>Exactly one: outbound SMTP email for POE status-change notifications to installers
    (<code>includes/</code>'s SMTP client, configured via Settings, tested via "Send test email"). There is no
    inbound integration, no webhook, no third-party API call, and no planned Active Directory/LDAP integration
    yet &mdash; that was explicitly deferred pending real server details (host, service account, base DN) rather
    than built as a non-functional placeholder.</p>
</div>

<div class="doc-section" id="deployment">
    <h2>7. Deployment architecture</h2>
    <p>Single-server deployment on XAMPP: Apache serves the PHP files directly from the webroot; MySQL runs
    alongside on the same host (custom port 3311 in this install). No queue, no cache layer, no separate app
    server &mdash; each request is stateless PHP-FPM/mod_php execution against the one database.</p>
    <table>
        <thead><tr><th>Path</th><th>Purpose</th></tr></thead>
        <tbody>
        <tr><td><code>storage/poe_uploads/</code></td><td>Site completion POE files &mdash; outside the webroot's directly-browsable path, deny-all <code>.htaccess</code>, served only via an authenticated download script</td></tr>
        <tr><td><code>storage/indigent_uploads/</code></td><td>Household photos + applicant signatures &mdash; same lockdown pattern</td></tr>
        <tr><td><code>assets/branding/</code></td><td>The uploaded site logo &mdash; public, since a logo isn't sensitive</td></tr>
        <tr><td><code>database/schema.sql</code></td><td>Full schema, safe to re-run</td></tr>
        <tr><td><code>database/seed_sample_data.php</code></td><td>One-time sample-data seeder covering every module (super admin only)</td></tr>
        </tbody>
    </table>
    <p>Session state is PHP's native file-based session store (default <code>session.save_path</code>) &mdash;
    fine for a single-server deployment; a multi-server deployment would need a shared session store (database or
    Redis-backed) before load-balancing across more than one app server.</p>
</div>

<div class="doc-section" id="nonfunctional">
    <h2>8. Non-functional characteristics</h2>
    <ul>
        <li><strong>Scalability</strong>: vertical scaling (bigger box) is the natural path for this
        architecture; horizontal scaling would first need the session-store change above. Query load is currently
        light (XAMPP-scale data volumes); the heaviest queries are the dashboard's cross-module aggregates and AI
        Reports' per-period aggregates, both read-only and straightforward to index further if data volume grows.</li>
        <li><strong>Maintainability</strong>: the "every module follows the same file pattern" convention (section
        3) means a developer who understands one module can navigate any other in minutes. Zero external
        dependencies also means no dependency-update treadmill.</li>
        <li><strong>Extensibility</strong>: new modules have a clear template to copy (list/form/save/view +
        export/import). The role model (see Security Architecture) is designed so a new narrow role never needs a
        sweep of existing permission checks.</li>
        <li><strong>Portability</strong>: no cloud-provider-specific service is used anywhere; this runs on any
        host offering PHP 8 + MySQL.</li>
    </ul>
</div>

<div class="doc-section" id="decisions">
    <h2>9. Key architecture decisions</h2>
    <table>
        <thead><tr><th>Decision</th><th>Rationale</th></tr></thead>
        <tbody>
        <tr><td>Zero external dependencies</td><td>No supply-chain risk, no version drift, runs anywhere PHP+MySQL does.</td></tr>
        <tr><td>Super admin = <code>role='admin'</code> + a flag, not a new role value</td><td>Every existing admin permission check already covers it automatically &mdash; no risk of a missed check across ~50 call sites.</td></tr>
        <tr><td>Original/current + revisions table for deadlines and targets</td><td>A deadline slipping without a recorded reason is itself a finding worth surfacing &mdash; the audit trail is the point, not a side effect.</td></tr>
        <tr><td>Province/district/local hierarchy as a PHP array, not a table</td><td>Reference data that doesn't change via the app's own CRUD doesn't need a table, a form, or an import path.</td></tr>
        <tr><td>AI Reports computed by rules, not an LLM call</td><td>Confirmed with the user: zero cost, zero external dependency, works offline, and every number is independently verifiable against the source tables.</td></tr>
        <tr><td>Fiscal-year (April&ndash;March) periods for Quarterly/Half-Yearly/Yearly reporting</td><td>Matches the convention USAASA already uses in indicator reporting periods (e.g. "Q2 2025/26").</td></tr>
        <tr><td>Active Directory integration deferred</td><td>Building it without a real LDAP host/service account/base DN would only produce a non-functional placeholder.</td></tr>
        </tbody>
    </table>
</div>

<p><a href="<?= APP_BASE_URL ?>/docs/index.php">&larr; Back to Documentation</a></p>

<?php include __DIR__ . '/../includes/footer.php'; ?>
