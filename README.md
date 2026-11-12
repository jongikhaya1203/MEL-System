# USAASA MEL System

A PHP / MySQL Monitoring, Evaluation & Learning system: the C3.5
Evaluation Findings and Lessons Register, a Risk Register, an Indicator /
Results Framework module, a Site Completion & Payment Approval workflow,
an Indigent Applications data capture module, and a lightweight CMS for
branding and editable content pages.

## Setup (XAMPP)

1. Start Apache and MySQL in the XAMPP Control Panel.
2. Import the schema: open phpMyAdmin (http://localhost/phpmyadmin), create
   nothing manually — just go to the **Import** tab and import
   `database/schema.sql`. It creates the `usaasa_mel` database, all tables,
   and a default admin user. Re-importing it later (e.g. after this file
   gains new tables) is safe — every `CREATE TABLE` is `IF NOT EXISTS` and
   the seed insert is `ON DUPLICATE KEY UPDATE`, so existing data is left
   alone.
3. Check `config/config.php` if your MySQL user/password differ from the
   XAMPP defaults (`root` / empty password).
4. Browse to http://localhost/mel_system/login.php
5. Log in with:
   - **Username:** `admin`
   - **Password:** `ChangeMe!2026`
6. Immediately go to **Users** and either change the admin password (edit
   the user, set a new password) or create your own admin account and
   deactivate this one.
7. Optional: visit `database/seed_sample_data.php` while logged in as
   admin to populate sample data — see **Sample data** below.

## Modules

- **Users** (admin only, admin-tier accounts super-admin only) — accounts
  and roles: `admin`, `manager`, `viewer`, `field_worker`, `back_office`,
  `approver`, `indigent_capturer`, `planner` — plus the `is_super_admin`
  flag on an admin account. See **Super Admin & the bulk-import engine**
  below.
- **Data Captured** (admin only) — bulk-import activity log and
  installer-captured data, consolidated for admin oversight. See its own
  section below.
- **Documentation** (admin-tier only) — Training Manual, Enterprise
  Architecture, and Security Architecture. See its own section below.
- **Projects** — admin/manager can add and edit; referenced by every other
  module. Carries an optional `province` tag (what Planning & Dispatch
  offers for installer allocation), a `programme` (`Broadband
  Connectivity` or `BDM` — Broadcasting and Digital Migration, set-top box
  distribution/analogue switch-off support — the list page renders each as
  its own section), plus optional `target_households` and `target_sites`
  figures alongside the running totals of households connected / sites
  deployed across its **Approved** sites — the latter feeds the dashboard
  charts below. Existing projects default to `Broadband Connectivity` so
  nothing already seeded silently changed category.
- **Findings & Lessons Register (C3.5)** — the evaluation findings and
  lessons register itself.
- **Risk Register** — identified risks, likelihood/impact rating, existing
  controls, agreed mitigation action and treatment deadline. Shares the
  same lifecycle rules as the findings register (see below).
- **Indicator / Results Framework** — indicators (Impact/Outcome/Output)
  with baseline, target, periodic actual-value reporting and progress
  tracking. Also shares the same lifecycle rules, applied to its target
  date (see below).
- **Planning & Dispatch** — allocate installers to a province + region
  with a target installation count and deadline; progress reports
  optionally feed a linked Results Framework indicator. See its own
  section below.
- **Site Completion & Payment Approval** — installers (role slug
  `field_worker`) submit completed sites with proof of evidence (POE);
  reviewers (role slug `back_office`) verify; an approver signs off as
  ready for payment. A different lifecycle from the three registers
  above — see its own section below.
- **Indigent Applications** — household data capture for the SABC TV
  licence concession / set-top-box subsidy, with cascading province/
  district/local municipality dropdowns, GPS, ID barcode scanning and a
  digital signature. See its own section below.
- **AI Reports** (admin/manager only) — automated, rule-based Monthly/
  Quarterly/Half-Yearly/Yearly reports integrating every module above
  into one document. See its own section below.
- **Settings** (admin only) — sitewide branding: site name, tagline, logo,
  footer text. The logo (if uploaded) shows top-left in the header
  alongside the site name on every page.
- **CMS Pages** (admin-managed, visible to everyone via "Pages" in the
  nav) — simple editable content pages (e.g. a help page or glossary).
  See its own section below.

## How the register implements the C3.5 requirements

Each entry separates the template's "Finding or lesson" field into two
distinct fields, as required:

- **Evidence-based finding** — the evidence-based fact.
- **Lesson drawn** — the generalisable lesson drawn from it.
- **Agreed management response** — the action management has agreed to
  take, kept as its own field (distinct from the finding and the lesson).

Other fields: Reference & project, Source & date, Evidence and
limitations, Implication, Owner & deadline, Follow-up outcome.

### Deadlines passing without confirmed closure

Status can only become `Closed` (or, for indicators, `Achieved`) through
an explicit **Confirm closure** / **Confirm achievement** action, which
requires a recorded outcome/commentary and stores who confirmed it and
when. Any item whose deadline/target date passes while it is still `Open`
or `In Progress` is automatically flagged `Overdue` the next time the
dashboard or a register is loaded (see `sync_overdue_statuses()` in
`includes/functions.php`, which syncs `findings_register`, `risks` and
`indicators`). An optional `cron/flag_overdue.php` script is provided if
you also want this enforced by a scheduled task independent of page views.

### Revised deadlines preserve the original date and reason

`findings_register.original_deadline`, `risks.original_deadline` and
`indicators.original_target_date` are each set once, at creation, and the
application never updates them again. Changing the working date is only
possible through **Revise deadline** / **Revise target date**, which:

1. Requires a reason (cannot be submitted blank).
2. Logs a row to `deadline_revisions` / `risk_deadline_revisions` /
   `indicator_target_revisions` (previous date, new date, reason, who,
   when) — a full audit trail, nothing is overwritten.
3. Updates only the current date field on the main record.

The record view shows the original date, the current date, and the
complete chronological revision history with reasons.

**Export & batch export** (`findings/list.php`, admin/manager): CSV, Excel
and a printable PDF report (`findings/export.php`, `findings/print.php`)
of whatever the current status/project filter matches, plus a checkbox
per row and "Export selected" buttons that export only the ticked rows
regardless of the filter — same pattern, and the same single-record-vs-
batch role split, as every other register below.

## Risk Register

Each risk captures: Reference & project, Category (Strategic / Operational
/ Financial / Compliance / Reputational / ICT / Other), Date identified,
Risk description, Existing controls, Likelihood & Impact, Owner &
(mitigation) deadline, Follow-up outcome.

**Risk rating** (Low / Medium / High / Critical) is calculated server-side
from Likelihood x Impact on a standard 3x3 matrix (`compute_risk_rating()`
in `includes/functions.php`) — it is never trusted from client input, so
it can't be set to something inconsistent with the chosen likelihood and
impact.

It follows the exact same overdue-flagging, explicit-closure and
deadline-revision-with-reason rules as the findings register, via its own
`risks` / `risk_deadline_revisions` tables and `risks/*.php` pages
mirroring `findings/*.php`.

**Export & batch export** (`risks/list.php`, admin/manager): CSV, Excel
and a printable PDF report of whatever the current status/rating/project
filter matches (`risks/export.php`, `risks/print.php` — same CSV/Excel/
print-to-PDF approach as Indigent Applications, no external library), plus
a checkbox per row and "Export selected" CSV/Excel/PDF buttons that export
only the ticked rows regardless of the filter. Exporting exactly one
record only needs a login; more than one is admin/manager only — verified
live the same way as Indigent Applications' export.

## Indicator / Results Framework

Each indicator sits under a project at a **result level** (Impact /
Outcome / Output) and carries: Result statement, Indicator name,
Definition, Unit of measure, Baseline (value + year), End-term target,
Data source, Collection frequency, Responsible person, and a target date.

Two views are provided:

- **`indicators/list.php`** — flat, filterable/searchable register (like
  the findings and risk registers).
- **`indicators/framework.php`** ("Results Framework" in the nav) — the
  hierarchical view: projects &rarr; result level &rarr; result statement
  &rarr; indicators, each with a live progress bar.

**Performance tracking**: rather than a single "current value" field that
gets overwritten, each reporting period is a separate, append-only row in
`indicator_actuals` (period label, period end date, actual value,
comments, reported by/when) — the full history behind any progress figure
stays visible and auditable. Add one from the indicator's view page.

**Progress** is `(actual − baseline) / (target − baseline) × 100`,
computed in `compute_indicator_progress()` — this works whether the
indicator should increase or decrease, since both halves of the fraction
flip sign together with the intended direction. It is driven off the most
recent reported actual, never stored or trusted as input.

The target date follows the exact same overdue-flagging,
explicit-confirmation and revision-with-reason rules as the other two
registers — "Confirm closure" is named **Confirm achievement** here, and
status reaches `Achieved` instead of `Closed`, but the mechanics
(`indicators` / `indicator_target_revisions` tables, `indicators/*.php`
pages) are the same pattern as `findings/*.php` and `risks/*.php`.

**Export & batch export** (`indicators/list.php`, admin/manager): CSV,
Excel and a printable PDF report (`indicators/export.php`,
`indicators/print.php`) of whatever the current status/level/project
filter matches, plus a checkbox per row and "Export selected" buttons
that export only the ticked rows regardless of the filter.

**The export role split, used identically by every register/list above
(Findings, Risks, Indicators, Site Completions, Indigent Applications)**:
exporting exactly one record (via `id=` or a one-element `ids[]`) only
needs to be logged in — the same bar as viewing that one record. Exporting
more than one (batch `ids[]`, or the filtered bulk mode) is admin/manager
only. A batch request (`ids[]` present) that resolves to zero valid ids
fails with 400 rather than silently falling through to an unfiltered
export of the whole table. Verified live across all five modules with
throwaway test accounts: single-record export 200, multi-record batch
403 for a non-admin/manager role; empty/malformed `ids[]` 400 for
admin/manager.

## Planning & Dispatch

Allocates an Installer (`field_worker`) to a **province** (a project —
projects now carry a `province` column, since every seeded project already
was a province-level rollout in all but name) and a **region** (district
municipality or metro, the same `sa_municipal_structure()` hierarchy built
for Indigent Applications' cascading dropdowns — see that module's section
below), with a **target number of installations** and a **deadline**.

- **`planning/list.php`** — flat, filterable register (status/province/
  installer), same pattern as the findings/risk/indicator registers.
- **`planning/form.php`** / **`save.php`** — dispatch a new allocation or
  edit one; the Region dropdown cascades from the chosen province's real
  district/metro list (client-side JS) and is re-validated server-side
  against `sa_validate_district()` so a tampered submission can't smuggle
  in a region that doesn't belong to the selected province.
- **`planning/view.php`** / **`add_progress.php`** — view one allocation
  and log progress against it. Progress is tracked the same way as
  indicator actuals: one append-only row per period, carrying the
  *cumulative* installations completed as of that period, not a delta.
  Reaching the target automatically marks the allocation `Completed`;
  a past deadline without reaching target auto-flags `Overdue` via the
  same `sync_overdue_statuses()` used by the other three registers.
- **`planning/revise_deadline.php`** — same original/current deadline
  audit-trail pattern (`allocation_deadline_revisions`) as
  findings/risks/indicators.
- **`planning/dashboard.php`** — target vs. actual installations by
  province (bar chart) and a region-level breakdown table, in the same
  visual style as the main dashboard's provincial site chart. A summary
  (tiles + recent allocations) also appears on the main dashboard.

**Reports into the Results Framework**: an allocation can optionally link
to a Results Framework indicator. Every progress update logged against
that allocation also writes (or updates, if the same period was already
reported) an `indicator_actuals` row on the linked indicator, with a
comment noting it was auto-reported from that allocation's reference code
— a one-way sync, Planning &rarr; Results Framework, verified live: an
allocation's "Week 1"/"Week 3" progress updates appear as that indicator's
reported actuals, visible on the indicator's own view page.

**`planner` role**: a dedicated, narrower role than `manager` for this one
job — create/edit allocations, dispatch, and revise deadlines
(`planning/form.php`, `save.php`, `revise_deadline.php` all accept
`admin`/`manager`/`planner`) — but not projects, findings, risks, the
results framework, or user management, which stay `admin`/`manager` only.
Verified live: a `planner` account gets 200 on `planning/form.php` and
successfully dispatches an allocation end-to-end, but gets 403 on
`risks/form.php`, `findings/form.php`, `indicators/form.php` and
`users/list.php`. Logging progress against an allocation (not
creating/dispatching it) stays with `admin`/`manager`/`back_office`/the
assigned installer, same as before — a planner's job is the dispatch
decision, not field progress reporting.

## Site Completion & Payment Approval

A different lifecycle from the three registers above: not deadline-driven,
but a three-step approval chain with segregation of duties enforced by
role, ending in "ready for payment" — actual payment processing happens
in USAASA's finance system, outside this tool:

1. **Installer** (role slug `field_worker`) submits a completed site (site
   name, location, households connected, completion date) and attaches
   one or more POE (proof of evidence) files — status `Submitted`.
2. **Reviewer** (role slug `back_office`) verifies it (or rejects it with
   a reason, sending it back to the installer) — status `Verified` or
   `Rejected`.
3. **Approver** signs it off (or rejects it) — status `Approved`
   ("ready for payment") or `Rejected`.

The role slugs in the database/code stay `field_worker` and `back_office`
(every `require_role()` check and the README elsewhere refers to them that
way) — only the human-facing label changed, via `role_label()` in
`includes/functions.php`, to the terms USAASA actually uses for this
process. `admin` can act at every step (for fixing mistakes);
`manager`/`viewer` can see the list and detail read-only but cannot act on
it. Each role check is enforced server-side with `require_role()` in every
`sites/*.php` page, not just hidden in the UI — confirmed live during
testing: an installer hitting `sites/verify.php` directly gets a 403, and
a reviewer cannot reach `sites/approve.php`.

**Export & batch export** (`sites/list.php`, admin/manager): CSV, Excel
and a printable PDF report (`sites/export.php`, `sites/print.php` — a
tabular multi-record report, distinct from `sites/preview.php`'s single-
site document with inline POE attachments) of whatever the current
status/project filter matches, plus a checkbox per row and "Export
selected" buttons that export only the ticked rows regardless of the
filter — same pattern, and the same single-record-vs-batch role split, as
Risk Register and Indigent Applications.

**POE files** are real uploads (PDF/JPG/PNG, max 10 MB), validated by
`store_poe_upload()` in `includes/functions.php`: it checks the upload
error code, size, extension, AND the real on-disk content type via
`finfo` (never trusting the client-supplied MIME type) before storing the
file under a random name in `storage/poe_uploads/` — a directory with its
own `.htaccess` denying all direct web access. Files are only ever served
through `sites/download_poe.php`, which requires a logged-in session.
Rejecting a renamed-but-fake file (e.g. a `.txt` saved as `.pdf`) was
verified live.

Rejected sites can be edited and resubmitted by their original submitter
(or admin) via **Resubmit**, which moves the status back to `Submitted`
without erasing the rejection reason from the record.

### Preview & print

**"Preview & print"** (on the record view and the register list,
`sites/preview.php`) renders the full submission — site details,
verification/approval/rejection history, and every attached POE document
— as a standalone letterhead-style document, outside the app's normal
sidebar shell, with a **Print** button (`window.print()`) and its own
`@media print` rules that hide the toolbar and keep only the record
itself on the printed page.

Each POE document renders according to what it actually is, verified live
for each case:

- **Images** — embedded inline via `<img>`, so the actual evidence photo
  appears on the printed page.
- **Plain text** (the sample POE files this app's seed script generates)
  — the file's real content, read and escaped, shown as preformatted text.
- **PDF** — shown in an `<iframe>` on screen (hidden when printing, since
  PDF-in-iframe printing isn't reliable across browsers) with a link to
  open it separately.

This needed `sites/download_poe.php` to serve files two ways:
`?inline=1` (used only by the preview's `<img>`/`<iframe>` tags) sets
`Content-Disposition: inline` so the browser renders the file in place;
every other existing link to that file keeps the original
`Content-Disposition: attachment` behavior, so nothing that already
linked to a POE download changed.

## Settings & branding

`settings/edit.php` (admin only) edits a single `site_settings` row:
site name, tagline, footer text, and an optional logo. Every page reads
this through `get_site_settings()` in `includes/functions.php` (cached
per-request) instead of the old hardcoded `APP_NAME` constant, so a
change takes effect everywhere immediately — header `<title>`, the
top-left brand (logo + site name), the login page, and the footer.

The logo is a plain public static file (like `assets/css/style.css`) —
not sensitive, so it isn't gated behind a login check — served straight
from `assets/branding/`. Uploads are validated by `store_logo_upload()`
the same way POE uploads are: real extension, size cap (2 MB), AND the
actual on-disk content type via `finfo` (never the client-supplied MIME
type). The previous logo file is deleted when a new one replaces it.

## CMS Pages

`cms_pages` holds simple, admin-editable content pages (e.g. a help page
or glossary) — reachable by everyone via **Pages** in the nav
(`cms/directory.php` lists published pages; `cms/view.php?slug=...` shows
one). Admins manage them under **Manage Pages** (`cms/list.php` /
`form.php` / `save.php` / `delete.php`) and can preview an unpublished
page before making it live.

**Security note on page bodies**: authors type plain text with a small
set of safe formatting markers (`# Heading`, `**bold**`, `*italic*`,
`- bullet`, `[text](https://url)`), handled by `render_cms_content()` in
`includes/functions.php`. The raw text is HTML-escaped FIRST; only then
are a handful of hardcoded tags (`<h2-4>`, `<strong>`, `<em>`, `<ul><li>`,
`<p>`, and `<a href>` restricted to `http(s)://` URLs) layered on top. No
tag or attribute name ever comes from the author's input, so a page can
never carry arbitrary HTML or `<script>` — verified live by creating a
page whose body included a raw `<script>alert(1)</script>` and confirming
it rendered as inert escaped text, not an executing tag.

`cms/delete.php` (like every other destructive action in this system)
shows a confirmation page on `GET` and only deletes on a CSRF-checked
`POST` — never a one-click `GET` link.

## Sample data

`database/seed_sample_data.php` (admin-only; visit it in the browser while
logged in as admin) populates every module in the system, not just Sites:

- One project per South African province for each of the two programmes:
  **Broadband Connectivity** (e.g. Gauteng Connectivity Rollout, target
  61,000 households / 25 sites) and **BDM** (e.g. Gauteng Broadcasting &
  Digital Migration, target 11,000 households / 12 distribution points) —
  18 province-tagged projects total, plus the pre-existing national
  `USF-100` project. Each carries its own `target_households` and yearly
  `target_sites` figure.
- Ten sample users covering every role: three `field_worker`, two
  `back_office`, two `approver`, one `manager`, one `viewer`, one
  `planner` (plus the separately-created `indigent_capturer` account) —
  all using password `Demo@12345`, change these before any real use. The
  seeded Planning & Dispatch allocations below are dispatched by the
  sample `planner` account, not the manager.
- Twelve sample site completions spread across every status (`Submitted`,
  `Verified`, `Approved`, `Rejected`), so the full workflow is visible
  immediately, plus a further batch of additional `Approved` sites per
  province (93 in total) so the dashboard charts have enough data to be
  meaningful. Every `Approved` site has a real, downloadable sample POE
  text file attached (clearly marked "SAMPLE DATA").
- **BDM sample sites**: a further 50 `Approved` STB distribution points
  across the 9 BDM projects, each with its own sample POE. Deliberately
  short of target in every province (e.g. Eastern Cape 7/10 points,
  Free State 3/6) rather than all-zero or all-done, so the Projects list
  and dashboard charts show believable mid-rollout progress.
- **Six findings**, **six risks** and **seven indicators** spanning
  `Open`/`In Progress`/`Overdue`/`Closed` (and `Achieved` for indicators),
  each register including at least one deadline/target-date revision
  example with a reason on file.
- **Nine indigent applications**, one per province, using the real
  cascading province/district/local hierarchy and a genuinely valid SA ID
  checksum per applicant (computed the same way `validate_sa_id_number()`
  checks it) — each with a synthetic signature on file.
- **Six Planning & Dispatch allocations** across six provinces/regions,
  deliberately spread across every status (`Planned`, `In Progress` ×2,
  `Completed` ×2, `Overdue`) — two are linked to a Results Framework
  indicator, so their progress updates double as that indicator's
  reported actuals (see **Planning & Dispatch** above).

It's safe to run more than once — every record is matched by a natural
key (project code/username, site name, finding/risk description,
indicator name, ID number, or province+region+installer) and reused or
skipped rather than duplicated.

## AI Reports (automated, integrated cross-module reporting)

**"AI" here means automated and rule-based, not a call to an external AI
service** — confirmed with the user before building it, consistent with
this app's zero-external-dependency design. Every number in a generated
report is a live SQL aggregate at generation time; every sentence of
narrative is template text filled in from those numbers
(`includes/ai_report.php` — `ai_report_*()` aggregation functions,
`ai_narrate_*()` template functions, `generate_ai_report()` orchestrator).
Admin/manager only (`ai_reports/*.php`, nav item hidden for other roles).

- **Four period types**: Monthly (plain calendar month), and Quarterly /
  Half-Yearly / Yearly on South Africa's government fiscal year
  (1 April – 31 March — matching the "Q2 2025/26"-style labels already
  used in this app's indicator actuals): Q1 Apr–Jun … Q4 Jan–Mar, H1
  Apr–Sep / H2 Oct–Mar, FY = the full year.
- **Integrates every module** into one report: Findings & Lessons,
  Risk Register, Results Framework, Site Completions & Payment Approval,
  Indigent Applications, and Planning & Dispatch — plus an all-time
  Projects/Programme (Broadband Connectivity vs BDM) progress table.
- **Executive summary**: a top-level narrative pulling the most notable
  positive signal (approvals, achievements, completions) and concern
  (Critical-and-open risks, overdue counts, rejections) across every
  module into one paragraph.
- **Frozen snapshots**: a generated report's full computed result (every
  number and sentence) is saved as JSON to `ai_reports.snapshot` and
  never recomputed on reopen — re-opening an old report shows exactly
  what was reported then, even if the underlying live data has since
  changed. Same **Print / Save as PDF** pattern as every other report in
  this app (browser print-to-PDF, no library).
- Verified live: a Yearly FY2025/26 report correctly pulled seeded
  historical `closed_at`/`confirmed_at` dates (2 findings closed, 1 risk
  closed, 1 indicator achieved, all within that fiscal year); a Monthly
  November 2026 report correctly pulled the same month's `created_at`
  activity instead. A `field_worker` test account got 403 and saw no
  "AI Reports" nav link at all.

## Dashboard charts — provincial deployment

The dashboard's "Provincial deployment" section (`index.php`) renders two
charts from `projects.target_sites` vs. the count of each project's
`Approved` sites — no JS charting library, just inline SVG generated
server-side (`pie_slice_path()`, `bar_path_rounded_top()`,
`polar_to_cartesian()` in `includes/functions.php`), consistent with the
app's zero-external-dependency approach. **Not filtered by programme** —
it queries every project with a `target_sites` figure set, so since BDM
projects now carry targets too, both charts show 19 bars/slices (10
Broadband + 9 BDM) rather than one per province. If you'd rather see
Broadband and BDM broken out as separate charts, that's a follow-up, not
something this change did on its own initiative.

- **Bar chart** — grouped Target/Actual bars per province, gray for the
  planned target, brand orange for the actual count, with the achieved
  count labelled directly above each orange bar.
- **Pie chart** — each province's share of total sites deployed, ordered
  largest-first from 12 o'clock, in a single hue stepped by opacity rather
  than cycling through hues.

Both charts were built against Claude's `dataviz` skill (six computable
accessibility checks — lightness band, chroma floor, CVD separation,
normal-vision separation, contrast — run via its `validate_palette.js`,
not eyeballed). Two deliberate departures from that skill's default
guidance, and why:

- **The gray Target bar intentionally fails the "chroma floor" check** —
  it's a neutral reference value, not a second hue competing for
  identity, the same role a baseline/gridline plays elsewhere. The two
  checks that matter for actually telling the bars apart (CVD separation,
  contrast vs. surface) both pass.
- **The skill's own component guidance deprioritizes pie/donut charts in
  favor of a horizontal stacked bar for 9+ long-named categories** (here,
  South African provinces) — this was requested as a pie chart by name,
  so it's built as one, with every slice directly labelled (name + count
  + %) so identity never depends on telling similar orange shades apart,
  plus a full data table under each chart as the accessible, exact-values
  fallback.

Every mark carries a native SVG `<title>` hover tooltip and every value is
also in the table beneath — hovering a bar/slice isn't the only way to
get at a number.

## Account security

- **Account lockout**: 5 failed login attempts locks the account for 15
  minutes (`login.php`) — tracked via `users.failed_login_attempts` /
  `locked_until`. Verified live: the correct password is also rejected
  while locked, with a countdown shown.
- **Forced password change**: any password an admin sets — creating a
  user or resetting one via **Users** — sets `must_change_password = 1`.
  `require_login()` (`includes/auth.php`) redirects everywhere except
  `change_password.php`/`logout.php` until it's cleared. Any user can also
  change their password voluntarily any time via "Change password" in the
  sidebar.
- **Baseline HTTP security headers** and directory-listing lockout, set
  site-wide in the root `.htaccess`. **Fixed while writing the Security
  Architecture doc**: `Permissions-Policy` previously denied
  `geolocation`/`camera` entirely (`geolocation=(), camera=()`), which
  would have silently broken Indigent Applications' GPS capture and
  barcode scanning even for same-origin use — corrected to
  `geolocation=(self), camera=(self), microphone=()` and verified live
  via the response headers.

## Documentation (Training Manual, Enterprise & Security Architecture)

Three reference documents under **Administration → Documentation**
(`docs/*.php`, admin-tier only — regular admin and super admin both,
since the role check is `admin` and super admin's role genuinely is
`admin`):

- **Training Manual** — step-by-step "how to" for every module, written
  for day-to-day users (installers, reviewers, approvers, capturers,
  planners, managers, admins), with 18 **real screenshots** captured from
  a live, logged-in session of this system against the actual seeded
  data (`assets/screenshots/`, embedded via `doc_screenshot()` in
  `includes/functions.php`). Captured headlessly via `puppeteer-core`
  driving the system's existing installed Chrome (no bundled browser
  download, no change to the app's own zero-dependency runtime — this is
  a one-time authoring tool, not a shipped dependency) logged in as a
  real account and navigated to each page. Re-run the capture script if
  branding, roles, or sample data change enough to make these stale.
- **Enterprise Architecture** — system context, the zero-dependency
  architecture principle (with a table of what a typical stack would use
  vs. what this one uses instead), the component map, data architecture,
  tech stack, the one integration point (SMTP), deployment layout, and a
  architecture-decision log.
- **Security Architecture** — authentication, the authorization model
  (including the Super Admin design below), CSRF, injection prevention,
  file upload security, session security, the HTTP headers table (with
  the `Permissions-Policy` fix called out), audit trails, PII handling,
  and a known-gaps/recommendations table.

## Super Admin & the bulk-import engine

**Super admin is not a separate role value** — it's `users.role = 'admin'`
plus a `users.is_super_admin` flag (`is_super_admin()` /
`require_super_admin()` in `includes/auth.php`). Deliberately designed
this way: every one of the ~50 existing `require_role(['admin', ...])` /
`in_array($role, ['admin', ...])` checks across the codebase already
passes for a super admin automatically, since their role genuinely is
`admin` — no risk of a missed check anywhere, and super admin always has
at least everything admin has. `is_super_admin` is only ever consulted at
the handful of points that are *more* restrictive than regular admin:

- **Managing other admin-tier accounts** (`users/list.php`) — a regular
  admin can't select "Admin" as a role, grant the Super Admin flag, or
  edit/view an existing admin-tier account at all (even their own, via
  this screen — self-service password changes stay on
  `change_password.php`). Verified live: a regular admin's direct POST
  attempt to rename/role-change the real `admin` account is silently
  ignored, confirmed by re-reading the row afterwards.
- **System configuration** — `settings/edit.php`, `settings/test_email.php`
  and `database/seed_sample_data.php` are super-admin-only; "Settings" is
  removed from a regular admin's nav entirely.
- **Irreversible deletes** — `indigent/delete.php` and `cms/delete.php`
  (permanent, unlike every other module's soft "Closed"/"Rejected"
  status) are super-admin-only; the Delete link itself is hidden from a
  regular admin.

The sidebar shows a distinct **Super Admin** badge (`user_role_badge()`)
instead of the plain "Admin" badge.

### Access Review (`users/access_review.php`, super admin only)

Two layers, deliberately kept separate. Access is still fundamentally
role-based, enforced server-side on every page — the matrix and per-user
resolved lists below are display-only, documenting what those role checks
already enforce. The one real, enforced exception is the per-user module
**denial** override (last bullet): a genuine restriction, on top of the
role system, not a replacement for it.

- **Role → module access matrix** (`includes/access_matrix.php`,
  `module_access_matrix()`) — a DISPLAY-ONLY reference table documenting
  what every `require_role()`/`require_login()` check across the codebase
  already enforces, module by module. Changing a user's *actual* access
  still means changing their role via **Manage users** — this table
  doesn't do anything by itself, it just answers "who can do what" at a
  glance without reading fifteen modules' source.
- **Per-account review tracking** — `users.last_login_at` (set on every
  successful login) and `users.last_access_review_at` /
  `last_access_reviewed_by` (stamped by a **Confirm reviewed** button).
  Accounts with no review on file, a review older than 90 days, or no
  login in that time are flagged (`Review due` / `No recent login`) and
  sorted to the top. Verified live: confirming an account's review
  correctly stamped both the timestamp and the reviewing super admin's
  id; a regular admin account got 403 on the page itself and saw no link
  to it anywhere (nav, or the button on **Users**).
- **Per-user linked module access** — `module_access_definitions()` in
  `includes/access_matrix.php` is structured (module → access level →
  allowed role tokens + a link), not just prose, so it can be *resolved*
  for one specific role via `user_accessible_modules($role,
  $isSuperAdmin)` — a list of exactly the modules that role can reach,
  each linked, with which access level(s) apply. Shown two places: on a
  user's own profile (the **Edit user** form in Users, "Module access for
  this profile" — change the role, save, and the list updates, since it's
  resolved live rather than stored), and as an expandable "N modules" per
  row on Access Review. The old prose-only reference matrix is still
  generated from this same structured data (`module_access_matrix()`,
  kept for backward compatibility), so there's one source of truth, not
  two drifting copies. Verified live: a Planner profile correctly shows
  view-only on every module except Planning & Dispatch (its actual
  elevated access) and correctly excludes AI Reports/Settings/Users/
  Access Review entirely; the Super Admin profile correctly shows every
  module including the super-admin-exclusive ones.
- **Per-user module denial (real, enforced)** — `user_module_denials`
  (user_id, module_key, denied_by/at), set from the "Deny" checkbox on
  the **Module access for this profile** card, and enforced centrally in
  `require_login()` (`includes/auth.php`): it resolves which module the
  current request belongs to (`module_key_for_path()`, mirroring
  `header.php`'s nav-active matching) and 403s if that module is denied
  for the logged-in user — regardless of what their role would otherwise
  allow. This one change protects every existing `require_role()`-gated
  page automatically (every `require_role()` call already calls
  `require_login()` first), with no sweep of individual pages needed —
  the same centralisation principle as the Super Admin design above.
  `$GLOBALS['pdo']` is used to reach the database from inside
  `require_login()` without adding a parameter to a function called with
  no arguments from ~50 existing pages. Self-denial is blocked server-side
  (an admin can't lock themselves out) and surfaced in the UI too
  (checkboxes replaced with a note on your own profile). Denying a
  non-admin-tier user's module is available to Admin; denying an
  admin-tier user's module is Super Admin only (same split as editing
  that account at all). Verified live end to end: denied a test account's
  Risk Register access → 403 on every `risks/*` page while Findings and
  the Dashboard remained fully accessible → restored by unchecking → 200
  again; a self-denial attempt on the super admin's own account was
  rejected server-side and Settings access was confirmed unaffected.

### Bulk import engine (Site Completions, Indigent Applications, Planning & Dispatch)

Each of these three modules has an `import.php` alongside its existing
CSV/Excel export, sharing one engine (`read_import_rows()`,
`parse_import_date()`, `import_date_in_range()`, `log_import_run()` in
`includes/functions.php`):

- **Two modes**, chosen per upload: **reject duplicates** (a row matching
  an existing record by its natural key — reference code/ID number for
  Indigent, site code or name+project for Sites, reference or
  project+region+installer for Planning — is flagged and skipped, never
  silently merged) or **update existing** (the same match updates the
  record in place instead). Update mode deliberately only ever touches
  the core capture fields — it never touches status, approvals, or
  deadlines, which always go through their own proper workflow action.
- **Optional date range on import**: rows are matched against their own
  date column (Completed Date for Sites, Captured At for Indigent,
  Deadline for Planning) and anything outside the given range is skipped
  — not counted as a duplicate or invalid, just not processed.
- **Every run is logged** to `import_log` (module, mode, file name, date
  range, counts by outcome, and a JSON per-row detail breakdown) —
  verified live across all three modules: a file with one new row and one
  intentional duplicate produced exactly 1 created / 1 duplicate-rejected;
  re-uploading the same file in update mode produced 2 updated / 0
  duplicates; re-uploading with a date range excluding every row produced
  all zeros.
- **Export also gained a date range** (`date_from`/`date_to`) on Sites and
  Indigent, alongside their existing status/project/search filters —
  Planning & Dispatch gained CSV/Excel export for the first time
  (`planning/export.php`), with the same date range against its deadline.

### Data Captured (admin-only report)

`reports/data_captured.php` — admin and super admin only (`require_role(['admin'])`,
which already covers both, per the design above). Two sections:

1. **Import activity** — every `import_log` run, filterable by module and
   import date range, with totals (runs/created/updated/duplicates
   rejected/invalid) and an expandable per-row detail list for each run.
2. **Data captured by installers** — site completions submitted by
   `field_worker` accounts, with a per-installer totals table (sites
   submitted, households connected) and a filterable, date-ranged detail
   list — the same underlying data `sites/list.php` already shows, but
   consolidated here specifically for an administrator's oversight view.

## Indigent Applications

Field data capture for the SABC TV licence concession / set-top-box
subsidy programme in rural areas (`indigent/`). No approval workflow here
— unlike Sites, this was asked for as capture + manage + import/export
only, so that's all it does.

**Who can do what**: `admin`, `manager`, `field_worker` (Installer) and
`indigent_capturer` (**Data Capturer**) can capture/edit; `admin` only can
delete; `admin`/`manager` only can export or bulk-import (both are bulk
personal/financial-data operations, gated more tightly than ordinary
viewing); every logged-in role can view the list and individual records.

`indigent_capturer` is a dedicated role, not a reuse of Installer — a
data capturer collects household/subsidy-eligibility information, which
is a different field job from Installer's site-completion-and-POE work,
so it gets exactly the indigent-capture access and nothing else: verified
live that a Data Capturer account can open `indigent/form.php` (200) but
gets a 403 on `sites/form.php` and on `indigent/export.php`.

**Fields**, beyond the obvious name/surname/address: `province`,
**District Municipality** and **Local Municipality** are cascading
dropdowns (`includes/sa_municipalities.php` — `sa_municipal_structure()`),
each province listing its districts/metros, each district listing its
locals. Selecting a province repopulates the district list; selecting a
district repopulates the local list; metros (which have no separate
local-municipality layer) appear as their own single-option "local",
auto-selected. Data: South Africa's 9 provinces, 8 metros, 44 district
municipalities and 205 local municipalities — compiled against the 2022
census municipal code list and cross-checked province-by-province against
independently reported district/local counts (every province's counts
match exactly: `php check script summed metros=8 districts=44 locals=205`).
Server-side, `sa_validate_municipality()` in `includes/functions.php`
re-checks the submitted province/district/local combination on save, so
a tampered or JS-disabled submission can't smuggle in a mismatched triple.
Editing a record captured before this change (free-text district/local)
degrades gracefully: the edit form adds the stored value as a
"previously captured — please reselect" fallback option rather than
silently dropping it, but saving the edit does require picking a real
entry from the hierarchy. This is reference data, not a legal register —
municipal demarcation does change over time, so treat it as accurate as
of when it was compiled, not a live feed. Two fields were added beyond
what was asked for: **village/area name** and a **physical address**
line — Province/District/Local Municipality alone are administrative
boundaries, not an address that locates a specific household, so
capturing "the address" needed those two as well.

- **SA ID number**: 13-digit entry, validated server-side against the
  real South African ID checksum (`validate_sa_id_number()` in
  `includes/functions.php`) — verified live against a computed valid ID
  (correctly accepted, with date of birth/gender/citizenship correctly
  derived) and an invalid one (correctly rejected). **Barcode scanning**
  uses the browser's native camera + `BarcodeDetector` API — no JS
  library pulled in, consistent with this app's zero-dependency
  approach — and is strictly a progressive enhancement: on a browser
  without it (Firefox, Safari), the scan button says so and manual entry
  (always present) is the only path, exactly as it would be anyway.
- **GPS coordinate**: optional; a "Use current location" button calls the
  browser's native Geolocation API. Requires the applicant's/installer's
  permission prompt; works over plain HTTP on `localhost` (browsers treat
  that as a secure context) but will need real HTTPS once this is served
  from anywhere else.
- **Household photo**: validated the same way every other upload in this
  app is (real extension, size cap, real on-disk content type via
  `finfo`) — verified live with an actual PNG round-trip.
- **Signature**: a hand-rolled `<canvas>` signature pad (mouse + touch),
  no signature-pad library. On submit the canvas is read via
  `toDataURL('image/png')`, decoded server-side in
  `store_signature_data_url()`, and saved like any other upload. A
  near-empty canvas (nothing actually drawn) is rejected rather than
  silently accepted as a blank signature — verified live.
- **Photo and signature are access-controlled**, not public like the
  logo — stored in `storage/indigent_uploads/` behind a deny-all
  `.htaccess` (verified live: direct storage access returns 403) and
  served only through `indigent/view_file.php`, which requires login
  (verified live: unauthenticated access redirects to login).
- **Declaration**: four distinct checkboxes (South African citizen,
  household income under R3,200/month, covered by a SABC concessionary
  TV licence, social grant recipient with proof provided) — kept
  separate from the "functional TV" field captured earlier in the form
  rather than asking for it twice. All four are required before the
  signature is accepted.

### Import &amp; export

Export (CSV, Excel, and a printable report for PDF) and import (CSV,
Excel) use the same fixed column order, so an exported file re-imports
cleanly — verified live end-to-end: exported a captured record to CSV,
re-imported it, and confirmed it was correctly recognised as a duplicate
(by reference and ID number) and skipped, while a second new, valid row
in the same file imported successfully and a third, deliberately invalid
row (bad ID checksum) was skipped with a clear per-row reason — nothing
aborts the whole batch.

- **CSV** — plain `fputcsv`/`fgetcsv`, with a UTF-8 BOM on export so
  Excel opens accented characters correctly.
- **"Excel"** — an HTML table served with a `.xls` extension and
  `application/vnd.ms-excel`. Excel opens this reliably (it understands
  HTML tables) with a one-time "format differs from extension" prompt —
  this avoids hand-building real OOXML (or pulling in a spreadsheet
  library) just to produce a file Excel already opens correctly. **Import**
  of a real `.xlsx`, by contrast, reads genuine OOXML: `ZipArchive` +
  `SimpleXML` over `xl/worksheets/sheet1.xml` and `xl/sharedStrings.xml`
  (both built into PHP — confirmed enabled in this install — so still no
  external library). Handles a straightforward single-sheet export;
  elaborate workbooks (multiple sheets, merged cells) aren't the target.
- **"PDF"** — there's no PDF library here either, hand-rolling a correct
  multi-page paginated PDF from scratch is genuinely risky to get right,
  and there's a dependency-free alternative that's just as real: a
  landscape-oriented printable report (`indigent/print.php`, the same
  pattern as `sites/preview.php`) with a **Print / Save as PDF** button —
  the browser's own print-to-PDF produces a genuine PDF file.
- **PDF is import-only unsupported, deliberately**: a PDF isn't a
  structured data format, so there's nothing reliable to parse rows out
  of. The import page says so rather than offering a feature that
  wouldn't actually work.

**Batch export (selected rows)**: on `indigent/list.php`, admin/manager
get a checkbox per row (plus a "select all" header checkbox) and three
"Export selected" buttons — CSV, Excel, PDF — that export only the ticked
rows, ignoring whatever province/search filter is set above. Both
`export.php` and `print.php` accept `ids[]=1&ids[]=2…` for this, alongside
their existing single-`id` and filtered-bulk modes. Verified live:
selecting 3 specific rows and exporting CSV/PDF each returned exactly
those 3 records with a correctly pluralised filename/report header; a
malformed `ids[]` that resolves to zero valid ids fails with 400 rather
than silently falling through to an unfiltered export of every record.

**Export role split**: exporting exactly one record (via `id=` or a
one-element `ids[]`) only needs to be logged in — the same bar as the
single-record "Preview & print"/"Export CSV"/"Export Excel" buttons on
`indigent/view.php`, since that's just exporting a record you can already
view. Exporting more than one (batch `ids[]`, or the filtered bulk mode)
is treated as bulk PII export and stays admin/manager only — verified
live: a `indigent_capturer` account gets 200 exporting one record but 403
exporting three.

## Email notifications (SMTP)

The installer who submitted a site gets emailed whenever its status
changes — `Verified`, `Approved`, or `Rejected` (with the reason) — fired
from `sites/verify.php` and `sites/approve.php` via
`notify_installer_of_status_change()` in `includes/functions.php`.

- **No external mail library.** `send_smtp_email()` is a small,
  dependency-free SMTP client (plain sockets, `EHLO` → optional
  `STARTTLS` → optional `AUTH LOGIN` → `MAIL`/`RCPT`/`DATA` → `QUIT`),
  consistent with this app having zero third-party dependencies anywhere
  else. Covers STARTTLS on 587 and implicit TLS/SSL on 465, which is what
  the large majority of real SMTP servers (Office 365, Gmail, SendGrid,
  most corporate relays) expect. Verified live against a local
  protocol-conformance stub — the full EHLO/AUTH LOGIN/MAIL/RCPT/DATA
  transcript round-tripped correctly, including a UTF-8-encoded subject
  line and RFC 5321 dot-stuffing in the body.
- **Configure under Settings** (admin only): enable/disable, host, port,
  encryption, username, password (write-only — never redisplayed, same
  pattern as resetting a user's password), from-address, from-name.
- **Send a test email** button on the same page sends one message through
  whatever is currently saved, without needing to run an actual site
  through the workflow to find out if SMTP is configured correctly.
- **Never blocks the workflow.** `notify_installer_of_status_change()` is
  wrapped so a broken SMTP config — wrong password, unreachable host,
  timeout — can't break the verify/approve/reject action that triggered
  it. Verified live: an unreachable SMTP host still let the verify action
  complete normally (status changed, redirect happened), while
  `notification_log` recorded the failure and its reason.
- **`notification_log`** is an append-only record of every attempt, sent
  or failed, with the error message on failure — shown on the Settings
  page (last 20) so SMTP misconfiguration is visible, not silent.
- **Known limitation**: sending is synchronous — a slow or unreachable
  SMTP host adds its connection timeout (15s) to the verify/approve/reject
  request. Fine at USAASA's scale; a queue would be the fix if that ever
  becomes noticeable.

A full maturity benchmark, prioritized security findings, and a phased
hardening roadmap (TLS, least-privilege DB user, MFA, audit logging,
backups, POPIA documentation, version control) were written up
separately as a standalone assessment — ask for the artifact link if you
don't have it.

## Notes

- Plain PHP (PDO + prepared statements), sessions for auth, CSRF tokens on
  all forms, `password_hash`/`password_verify` for credentials, output
  escaped via `e()`. No framework/build step required.
- To extend the MEL system with further modules (indicators, M&E plans,
  risk register, etc.), follow the same pattern: a table + a folder with
  `list.php` / `form.php` / `save.php` / `view.php`, reusing
  `includes/auth.php`, `includes/csrf.php`, `includes/functions.php`.
