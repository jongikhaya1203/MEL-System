<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin']);

$pageTitle = 'Security Architecture';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Security Architecture</h1>
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
        <li><a href="#auth">1. Authentication</a></li>
        <li><a href="#authz">2. Authorization model</a></li>
        <li><a href="#csrf">3. CSRF protection</a></li>
        <li><a href="#injection">4. Injection prevention &amp; input validation</a></li>
        <li><a href="#uploads">5. File upload security</a></li>
        <li><a href="#session">6. Session security</a></li>
        <li><a href="#headers">7. HTTP security headers</a></li>
        <li><a href="#audit">8. Audit trails &amp; data integrity</a></li>
        <li><a href="#pii">9. PII &amp; data protection</a></li>
        <li><a href="#gaps">10. Known gaps &amp; recommendations</a></li>
    </ul>
</div>

<div class="doc-section" id="auth">
    <h2>1. Authentication</h2>
    <ul>
        <li><strong>Passwords</strong>: hashed with bcrypt (<code>password_hash()</code>/<code>password_verify()</code>,
        PHP's default cost factor) &mdash; never stored or logged in plain text.</li>
        <li><strong>Account lockout</strong>: 5 failed attempts locks the account for 15 minutes
        (<code>users.failed_login_attempts</code> / <code>locked_until</code>, enforced in <code>login.php</code>).
        The correct password is also rejected while locked, with a countdown shown &mdash; this prevents an
        attacker who's guessed the right password mid-lockout from sneaking in.</li>
        <li><strong>Forced password change</strong>: any password an admin sets &mdash; creating a user or
        resetting one &mdash; sets <code>must_change_password = 1</code>. <code>require_login()</code>
        redirects everywhere except <code>change_password.php</code>/<code>logout.php</code> until it's cleared,
        so an admin never has to transmit a password the user will actually keep using.</li>
        <li><strong>No multi-factor authentication</strong> currently &mdash; see Known gaps.</li>
    </ul>
</div>

<div class="doc-section" id="authz">
    <h2>2. Authorization model</h2>
    <p>Enforced server-side on every page via <code>require_login()</code> / <code>require_role(array $roles)</code>
    (<code>includes/auth.php</code>) &mdash; never just by hiding a nav link. A role check runs before the page
    does anything else.</p>
    <h3>Roles</h3>
    <p>See the Training Manual's role table for what each role does day to day. From a security standpoint, the
    one structural decision worth calling out:</p>
    <div class="doc-callout">
        <strong>Super Admin is not a separate role value.</strong> It's <code>users.role = 'admin'</code> plus a
        <code>users.is_super_admin</code> flag (<code>is_super_admin()</code> / <code>require_super_admin()</code>).
        Every one of the ~50 existing <code>require_role(['admin', ...])</code> checks across the codebase already
        passes for a super admin automatically, since their role genuinely is <code>admin</code> &mdash; there is
        no way to miss updating a check somewhere and accidentally lock super admin out of something admin can do.
        <code>is_super_admin</code> is consulted only at the handful of points that are <em>more</em> restrictive
        than regular admin:
        <ul>
            <li>Managing other admin-tier accounts, or granting/revoking the Super Admin flag itself (<code>users/list.php</code>) &mdash; enforced both in the form (role options, checkbox) and again on every POST, independent of what the client submits.</li>
            <li>System configuration &mdash; <code>settings/edit.php</code>, <code>settings/test_email.php</code>, <code>database/seed_sample_data.php</code>.</li>
            <li>Irreversible deletes &mdash; <code>indigent/delete.php</code>, <code>cms/delete.php</code> (every other module only has a soft "Closed"/"Rejected" status, never a real delete).</li>
        </ul>
    </div>
    <h3>Segregation of duties</h3>
    <p>The Site Completion chain is the clearest example: an Installer cannot verify their own submission, and a
    Reviewer cannot approve what they just verified &mdash; each step requires a different role
    (<code>sites/verify.php</code>, <code>sites/approve.php</code>). Verified live during development: an
    installer hitting <code>verify.php</code> directly gets a 403, and a reviewer cannot reach
    <code>approve.php</code>.</p>
    <h3>Export/import role split</h3>
    <p>Exporting exactly one record you can already view only needs a login; exporting more than one (a batch
    selection, or the whole filtered list) is treated as bulk PII export and is admin/manager only. This same rule
    is applied identically across Findings, Risks, Indicators, Sites, and Indigent Applications.</p>
    <h3>Per-user module denial (Access Review)</h3>
    <p>The only per-user override in the system, on top of the role-based model above — not a replacement for it.
    A super admin (or an admin, for non-admin-tier accounts) can explicitly deny one profile access to one module
    from the "Module access for this profile" card on <strong>Users</strong>. Enforcement is centralised in a
    single place, <code>require_login()</code>, rather than swept across every <code>require_role()</code> call
    site: it resolves which module the current request belongs to and checks a denial table before the page runs
    anything. Since every <code>require_role()</code> call already calls <code>require_login()</code> first, this
    protects every existing protected page automatically. Self-denial is blocked server-side (an account can't
    lock itself out) independent of what the UI shows. Verified live: denying a test account's Risk Register
    access produced a 403 on every <code>risks/*</code> page while every other module stayed fully accessible to
    it; a self-denial attempt against the super admin's own account was rejected and its own access confirmed
    unaffected.</p>
</div>

<div class="doc-section" id="csrf">
    <h2>3. CSRF protection</h2>
    <p>Every state-changing form carries a per-session CSRF token (<code>csrf_field()</code>/<code>csrf_verify()</code>
    in <code>includes/csrf.php</code>) checked with <code>hash_equals()</code> before any POST is processed.
    Read-only GET requests (list filters, exports) don't need one.</p>
</div>

<div class="doc-section" id="injection">
    <h2>4. Injection prevention &amp; input validation</h2>
    <ul>
        <li><strong>SQL injection</strong>: every query is a PDO prepared statement with bound parameters &mdash;
        there is no string-concatenated SQL anywhere in the codebase. <code>PDO::ATTR_ERRMODE::EXCEPTION</code> is
        set globally, so a database error surfaces as an exception rather than a silently wrong result.</li>
        <li><strong>XSS</strong>: every piece of user-supplied data is passed through <code>e()</code>
        (<code>htmlspecialchars</code>, <code>ENT_QUOTES</code>, UTF-8) at output time. CMS page content is
        deliberately restricted to a small safe-subset markdown renderer &mdash; raw HTML typed into a page body
        is shown as literal text, not executed.</li>
        <li><strong>SA ID numbers</strong>: validated against the real 13-digit Luhn-variant checksum
        (<code>validate_sa_id_number()</code>), not just a digit-count check &mdash; both on manual entry and on
        barcode-scanned input.</li>
        <li><strong>Bulk import rows</strong>: every column is re-validated server-side exactly as if typed into
        the form &mdash; province/district/region values are checked against the real municipal hierarchy, dates
        are parsed defensively, numeric fields are range/type-checked, and a row that fails any check is reported
        as invalid rather than partially applied.</li>
    </ul>
</div>

<div class="doc-section" id="uploads">
    <h2>5. File upload security</h2>
    <p>Applied identically to every upload path (POE documents, household photos, signatures, the site logo):</p>
    <ol>
        <li>Real file-extension allowlist check.</li>
        <li><strong>On-disk content-type verification</strong> via <code>finfo</code> &mdash; the client-supplied
        MIME type is never trusted, since it's attacker-controlled.</li>
        <li>A random filename is generated for storage (<code>bin2hex(random_bytes(16))</code>) &mdash; the
        original filename is kept only as display metadata, never used as a path.</li>
        <li>POE and indigent uploads are stored <strong>outside any directly browsable path</strong>
        (<code>storage/</code>, with a deny-all <code>.htaccess</code> in each subfolder) and served only through
        an authenticated download/view script that checks the requester is logged in first. The site logo is the
        one exception, stored as a public static file under <code>assets/branding/</code> &mdash; a logo isn't
        sensitive, so it's served directly.</li>
    </ol>
</div>

<div class="doc-section" id="session">
    <h2>6. Session security</h2>
    <ul>
        <li>Session cookies set <code>httponly</code> and <code>SameSite=Lax</code> (<code>config/config.php</code>).</li>
        <li><code>session_regenerate_id(true)</code> on every successful login &mdash; prevents session fixation.</li>
        <li>Logout fully destroys the session (<code>$_SESSION = []; session_destroy();</code>), not just clearing a flag.</li>
    </ul>
</div>

<div class="doc-section" id="headers">
    <h2>7. HTTP security headers</h2>
    <p>Set site-wide in the root <code>.htaccess</code>:</p>
    <table>
        <thead><tr><th>Header</th><th>Value</th><th>Why</th></tr></thead>
        <tbody>
        <tr><td><code>X-Content-Type-Options</code></td><td><code>nosniff</code></td><td>Stops the browser guessing a different content type than what's declared.</td></tr>
        <tr><td><code>X-Frame-Options</code></td><td><code>DENY</code></td><td>Blocks the entire app from being framed by another site (clickjacking).</td></tr>
        <tr><td><code>Referrer-Policy</code></td><td><code>strict-origin-when-cross-origin</code></td><td>Limits what leaks to an external site via the Referer header.</td></tr>
        <tr><td><code>Permissions-Policy</code></td><td><code>geolocation=(self), camera=(self), microphone=()</code></td><td>Geolocation and camera are genuinely used (Indigent Applications' GPS capture and barcode scanning) and are scoped to same-origin only; microphone is never used, so it's fully denied. <em>(Corrected while writing this document &mdash; it was previously <code>geolocation=(), camera=(), microphone=()</code>, which would have silently broken both features even for same-origin use; fixed and verified live via response headers.)</em></td></tr>
        <tr><td><code>Options -Indexes</code></td><td>&mdash;</td><td>No directory listing for a folder with no <code>index.php</code>.</td></tr>
        </tbody>
    </table>
    <p><strong>No Content-Security-Policy yet</strong> &mdash; deliberately deferred, since this codebase uses
    inline <code>&lt;style&gt;</code>/<code>&lt;script&gt;</code> blocks throughout and a CSP needs an audit of
    every one of them first, or it will silently break pages rather than protect them.</p>
</div>

<div class="doc-section" id="audit">
    <h2>8. Audit trails &amp; data integrity</h2>
    <ul>
        <li><strong>Deadline/target revisions</strong>: the original value is set once and never overwritten;
        every change is a new row (previous value, new value, reason, who, when) in a companion revisions table.
        Applies to Findings, Risks, Indicators, and Planning &amp; Dispatch allocations identically.</li>
        <li><strong>Append-only reporting history</strong>: <code>indicator_actuals</code> and
        <code>allocation_progress_updates</code> are never updated in place &mdash; a corrected figure is a new
        row, not an edit, so the reported history can't be quietly rewritten.</li>
        <li><strong>Import activity log</strong>: every bulk import run (module, mode, file name, date-range
        filter, counts by outcome, and a per-row detail breakdown) is recorded in <code>import_log</code> and
        reviewable by an administrator on Data Captured &mdash; including every row a duplicate check rejected.</li>
        <li><strong>Frozen report snapshots</strong>: an AI Report's full computed result is saved as JSON at
        generation time and never recomputed &mdash; a report can't silently change after the fact.</li>
    </ul>
</div>

<div class="doc-section" id="pii">
    <h2>9. PII &amp; data protection</h2>
    <p>The most sensitive data in the system is in Indigent Applications: SA ID numbers, GPS coordinates,
    household photos, income, grant/TV-licence declarations, and a digital signature. These get the same
    file-storage lockdown as POE documents (section 5), and the export role split (section 2) means bulk export
    of this data is admin/manager only, while a capturer can only export the one record they themselves captured.
    ID numbers are stored digits-only after checksum validation, never the raw scanned/typed string.</p>
</div>

<div class="doc-section" id="gaps">
    <h2>10. Known gaps &amp; recommendations</h2>
    <table>
        <thead><tr><th>Gap</th><th>Recommendation</th></tr></thead>
        <tbody>
        <tr><td>No HTTPS enforced at the app layer</td><td>Terminate TLS at Apache (or a reverse proxy in front of it) in any real deployment; add HSTS once HTTPS is confirmed working everywhere.</td></tr>
        <tr><td>No multi-factor authentication</td><td>Worth prioritising for admin/super admin accounts specifically, given their elevated blast radius.</td></tr>
        <tr><td>No Content-Security-Policy</td><td>Deferred until the inline script/style audit mentioned in section 7 is done.</td></tr>
        <tr><td>No Active Directory/LDAP integration</td><td>Deferred pending a real AD host, service account, and base DN &mdash; raised and explicitly deferred with the user rather than built as a placeholder.</td></tr>
        <tr><td>No real AI/LLM integration for AI Reports</td><td>Deliberate, confirmed with the user &mdash; AI Reports is rule-based by design, not a gap to close.</td></tr>
        <tr><td>File-based PHP sessions</td><td>Fine for this single-server deployment; move to a shared session store before horizontally scaling across more than one app server.</td></tr>
        <tr><td>No centralised security log / SIEM feed</td><td>Lockouts, permission denials, and import rejections are all recorded (respectively: <code>users</code>, HTTP 403 responses, <code>import_log</code>), but nothing currently ships them to a central monitoring system &mdash; worth doing before a production rollout at scale.</td></tr>
        </tbody>
    </table>
</div>

<p><a href="<?= APP_BASE_URL ?>/docs/index.php">&larr; Back to Documentation</a></p>

<?php include __DIR__ . '/../includes/footer.php'; ?>
