-- USAASA MEL System
-- Database schema
-- Default admin login created below: username "admin", password "ChangeMe!2026"
-- CHANGE THIS PASSWORD IMMEDIATELY AFTER FIRST LOGIN.

CREATE DATABASE IF NOT EXISTS usaasa_mel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE usaasa_mel;

-- ---------------------------------------------------------------------------
-- Users
-- ---------------------------------------------------------------------------
-- Roles:
--   admin        full access, including overriding the payment sign-off chain
--   manager      manages projects / findings / risks / indicators (not the
--                site completion & payment sign-off chain)
--   viewer       read-only across all modules
--   field_worker ("Installer") submits site completions with proof of
--                evidence (POE)
--   back_office  ("Reviewer") verifies submitted site completions
--   approver     signs off verified site completions as ready for payment
--   indigent_capturer ("Data Capturer") captures indigent beneficiary
--                applications — a distinct field role from Installer:
--                household data/subsidy-eligibility capture, not site
--                installation. Does not get Installer's site-completion
--                access, or vice versa.
--   planner      ("Planner") creates/dispatches installer allocations by
--                province and region (Planning & Dispatch module) — a
--                distinct, narrower role from manager: can dispatch and
--                revise allocation deadlines, but not manage projects,
--                findings, risks or the results framework.
--
-- Super admin is NOT a separate role value — a super admin is a user with
-- role='admin' AND is_super_admin=1. This is deliberate: every existing
-- require_role(['admin', ...]) / in_array($role, ['admin', ...]) check in
-- the codebase already passes for a super admin automatically (their role
-- really is 'admin'), so super admin always has at least everything admin
-- has, with zero risk of a missed check somewhere. is_super_admin is only
-- ever consulted at the handful of points that are MORE restrictive than
-- regular admin: managing other admin-tier accounts (users/list.php),
-- system configuration (settings/*.php, database/seed_sample_data.php),
-- and irreversible deletes (indigent/delete.php, cms/delete.php).
CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name     VARCHAR(150) NOT NULL,
    email         VARCHAR(150) NULL,
    role          ENUM('admin','manager','viewer','field_worker','back_office','approver','indigent_capturer','planner') NOT NULL DEFAULT 'viewer',
    is_super_admin TINYINT(1) NOT NULL DEFAULT 0,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    failed_login_attempts INT NOT NULL DEFAULT 0,
    locked_until  DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

ALTER TABLE users ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;
ALTER TABLE users ADD COLUMN IF NOT EXISTS failed_login_attempts INT NOT NULL DEFAULT 0 AFTER must_change_password;
ALTER TABLE users ADD COLUMN IF NOT EXISTS locked_until DATETIME NULL AFTER failed_login_attempts;
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_super_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER role;

-- Upgrades an already-created users table (CREATE TABLE above is skipped
-- once the table exists) to recognise newly-added roles.
ALTER TABLE users MODIFY COLUMN role ENUM('admin','manager','viewer','field_worker','back_office','approver','indigent_capturer','planner') NOT NULL DEFAULT 'viewer';

-- ---------------------------------------------------------------------------
-- User Access Review — supports the super-admin-only Access Review page
-- (users/access_review.php): when a user last signed in (to spot dormant
-- accounts that should have access revoked), and when a super admin last
-- confirmed that user's access is still appropriate (and who did it).
-- ---------------------------------------------------------------------------
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL AFTER locked_until;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_access_review_at DATETIME NULL AFTER last_login_at;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_access_reviewed_by INT NULL AFTER last_access_review_at;
ALTER TABLE users ADD CONSTRAINT fk_users_reviewed_by FOREIGN KEY (last_access_reviewed_by) REFERENCES users(id);

-- ---------------------------------------------------------------------------
-- Projects
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS projects (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    project_code      VARCHAR(50)  NOT NULL UNIQUE,
    project_name      VARCHAR(255) NOT NULL,
    description       TEXT NULL,
    target_households INT NULL,
    target_sites      INT NULL,
    created_by        INT NOT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_projects_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

ALTER TABLE projects ADD COLUMN IF NOT EXISTS target_households INT NULL AFTER description;
ALTER TABLE projects ADD COLUMN IF NOT EXISTS target_sites INT NULL AFTER target_households;

-- ---------------------------------------------------------------------------
-- C3.5 Evaluation Findings and Lessons Register
--
-- The source template field "Finding or lesson" is deliberately split into
-- two columns (evidence_based_finding, lesson_drawn) so the evidence-based
-- finding, the lesson drawn from it, and the agreed management response
-- (action) are captured as three distinct, separately editable fields
-- rather than conflated into one free-text box.
--
-- original_deadline is set once at creation and is never updated again.
-- current_deadline changes only through a logged revision (see
-- deadline_revisions below), so the original date and the reason for every
-- change are always preserved and auditable.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS findings_register (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    reference_code          VARCHAR(50)  NOT NULL UNIQUE,
    project_id              INT NOT NULL,

    source                  VARCHAR(255) NOT NULL,
    source_date             DATE NOT NULL,

    evidence_based_finding  TEXT NOT NULL,
    lesson_drawn            TEXT NOT NULL,
    evidence_and_limitations TEXT NOT NULL,
    implication             TEXT NOT NULL,
    management_response     TEXT NOT NULL,

    owner_user_id           INT NOT NULL,
    original_deadline       DATE NOT NULL,
    current_deadline        DATE NOT NULL,

    status                  ENUM('Open','In Progress','Overdue','Closed') NOT NULL DEFAULT 'Open',
    follow_up_outcome       TEXT NULL,

    closed_by               INT NULL,
    closed_at               DATETIME NULL,
    closure_notes           TEXT NULL,

    created_by              INT NOT NULL,
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_findings_project    FOREIGN KEY (project_id)     REFERENCES projects(id),
    CONSTRAINT fk_findings_owner      FOREIGN KEY (owner_user_id)  REFERENCES users(id),
    CONSTRAINT fk_findings_closed_by  FOREIGN KEY (closed_by)      REFERENCES users(id),
    CONSTRAINT fk_findings_created_by FOREIGN KEY (created_by)     REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Deadline revision audit trail
-- Every change to current_deadline inserts one row here. original_deadline
-- on findings_register is never touched, so it always reflects the very
-- first deadline agreed.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS deadline_revisions (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    finding_id       INT NOT NULL,
    previous_deadline DATE NOT NULL,
    new_deadline     DATE NOT NULL,
    reason           TEXT NOT NULL,
    revised_by       INT NOT NULL,
    revised_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_revisions_finding FOREIGN KEY (finding_id) REFERENCES findings_register(id) ON DELETE CASCADE,
    CONSTRAINT fk_revisions_user    FOREIGN KEY (revised_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Risk Register
--
-- Same lifecycle rules as the findings register: original_deadline is set
-- once and never updated again (see risk_deadline_revisions below); status
-- only reaches Closed through an explicit confirm-closure action; Overdue
-- is set automatically once current_deadline passes without closure.
--
-- risk_rating is computed server-side from likelihood x impact (not
-- trusted from client input) and stored for fast filtering/reporting.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS risks (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    reference_code      VARCHAR(50)  NOT NULL UNIQUE,
    project_id          INT NOT NULL,

    category            ENUM('Strategic','Operational','Financial','Compliance','Reputational','ICT','Other') NOT NULL,
    date_identified      DATE NOT NULL,

    risk_description     TEXT NOT NULL,
    existing_controls    TEXT NOT NULL,

    likelihood           ENUM('Low','Medium','High') NOT NULL,
    impact               ENUM('Low','Medium','High') NOT NULL,
    risk_rating          ENUM('Low','Medium','High','Critical') NOT NULL,

    mitigation_action    TEXT NOT NULL,

    owner_user_id        INT NOT NULL,
    original_deadline     DATE NOT NULL,
    current_deadline      DATE NOT NULL,

    status               ENUM('Open','In Progress','Overdue','Closed') NOT NULL DEFAULT 'Open',
    follow_up_outcome     TEXT NULL,

    closed_by            INT NULL,
    closed_at            DATETIME NULL,
    closure_notes        TEXT NULL,

    created_by           INT NOT NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_risks_project    FOREIGN KEY (project_id)    REFERENCES projects(id),
    CONSTRAINT fk_risks_owner      FOREIGN KEY (owner_user_id) REFERENCES users(id),
    CONSTRAINT fk_risks_closed_by  FOREIGN KEY (closed_by)     REFERENCES users(id),
    CONSTRAINT fk_risks_created_by FOREIGN KEY (created_by)    REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Risk deadline revision audit trail (mirrors deadline_revisions above)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS risk_deadline_revisions (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    risk_id          INT NOT NULL,
    previous_deadline DATE NOT NULL,
    new_deadline     DATE NOT NULL,
    reason           TEXT NOT NULL,
    revised_by       INT NOT NULL,
    revised_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_risk_revisions_risk FOREIGN KEY (risk_id) REFERENCES risks(id) ON DELETE CASCADE,
    CONSTRAINT fk_risk_revisions_user FOREIGN KEY (revised_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Indicator / Results Framework
--
-- Each indicator sits at a result level (Impact/Outcome/Output) under a
-- project and carries a baseline, an end-term target and a target date.
-- Performance is tracked over time via indicator_actuals (one append-only
-- row per reporting period) rather than overwriting a single "current
-- value" field, so the full reporting history is always visible.
--
-- The target date follows the same lifecycle rules as deadlines elsewhere
-- in the system: original_target_date is set once and never updated again
-- (see indicator_target_revisions below); status only reaches Achieved
-- through an explicit confirm-achievement action; Overdue is set
-- automatically once current_target_date passes without that confirmation.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS indicators (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    reference_code        VARCHAR(50)  NOT NULL UNIQUE,
    project_id            INT NOT NULL,

    result_level          ENUM('Impact','Outcome','Output') NOT NULL,
    result_statement      TEXT NOT NULL,
    indicator_name        VARCHAR(255) NOT NULL,
    definition            TEXT NOT NULL,
    unit_of_measure       VARCHAR(100) NOT NULL,

    baseline_value        DECIMAL(18,4) NOT NULL,
    baseline_year         SMALLINT NOT NULL,
    target_value          DECIMAL(18,4) NOT NULL,

    data_source           VARCHAR(255) NOT NULL,
    collection_frequency  ENUM('Monthly','Quarterly','Biannual','Annual') NOT NULL,

    responsible_user_id   INT NOT NULL,
    original_target_date   DATE NOT NULL,
    current_target_date    DATE NOT NULL,

    status                ENUM('Open','In Progress','Overdue','Achieved') NOT NULL DEFAULT 'Open',
    achievement_notes      TEXT NULL,

    confirmed_by          INT NULL,
    confirmed_at          DATETIME NULL,
    confirmation_notes    TEXT NULL,

    created_by            INT NOT NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_indicators_project     FOREIGN KEY (project_id)          REFERENCES projects(id),
    CONSTRAINT fk_indicators_responsible FOREIGN KEY (responsible_user_id) REFERENCES users(id),
    CONSTRAINT fk_indicators_confirmed_by FOREIGN KEY (confirmed_by)       REFERENCES users(id),
    CONSTRAINT fk_indicators_created_by  FOREIGN KEY (created_by)         REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Indicator target-date revision audit trail (mirrors deadline_revisions)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS indicator_target_revisions (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    indicator_id         INT NOT NULL,
    previous_target_date DATE NOT NULL,
    new_target_date      DATE NOT NULL,
    reason               TEXT NOT NULL,
    revised_by           INT NOT NULL,
    revised_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_indicator_revisions_indicator FOREIGN KEY (indicator_id) REFERENCES indicators(id) ON DELETE CASCADE,
    CONSTRAINT fk_indicator_revisions_user      FOREIGN KEY (revised_by)  REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Indicator actuals: one append-only row per reporting period. Never
-- edited or overwritten once reported, so the performance history behind
-- any progress calculation is always auditable.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS indicator_actuals (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    indicator_id  INT NOT NULL,
    period_label  VARCHAR(50)  NOT NULL,
    period_date   DATE NOT NULL,
    actual_value  DECIMAL(18,4) NOT NULL,
    comments      TEXT NULL,
    reported_by   INT NOT NULL,
    reported_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_indicator_actuals_indicator FOREIGN KEY (indicator_id) REFERENCES indicators(id) ON DELETE CASCADE,
    CONSTRAINT fk_indicator_actuals_user      FOREIGN KEY (reported_by) REFERENCES users(id),
    CONSTRAINT uq_indicator_actuals_period    UNIQUE (indicator_id, period_label)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Site Completion & Payment Approval
--
-- A different lifecycle from the registers above: not deadline-driven, but
-- an approval chain with segregation of duties enforced by role:
--   field_worker ("Installer") submits a completed site (with POE)  -> Submitted
--   back_office ("Reviewer") verifies it (or rejects it back)        -> Verified / Rejected
--   approver signs it off as ready for payment (or rejects)          -> Approved / Rejected
-- "Approved" IS "ready for payment" — this system stops at sign-off; actual
-- payment processing happens in USAASA's finance system, outside this tool.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sites (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    site_code           VARCHAR(50)  NOT NULL UNIQUE,
    project_id          INT NOT NULL,

    site_name           VARCHAR(255) NOT NULL,
    location            VARCHAR(255) NOT NULL,
    households_connected INT NOT NULL,
    completed_date       DATE NOT NULL,

    status              ENUM('Submitted','Verified','Approved','Rejected') NOT NULL DEFAULT 'Submitted',

    submitted_by        INT NOT NULL,
    submitted_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    verified_by         INT NULL,
    verified_at         DATETIME NULL,
    verification_notes  TEXT NULL,

    approved_by         INT NULL,
    approved_at         DATETIME NULL,
    approval_notes      TEXT NULL,

    rejected_by         INT NULL,
    rejected_at         DATETIME NULL,
    rejection_reason    TEXT NULL,

    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_sites_project      FOREIGN KEY (project_id)    REFERENCES projects(id),
    CONSTRAINT fk_sites_submitted_by FOREIGN KEY (submitted_by)  REFERENCES users(id),
    CONSTRAINT fk_sites_verified_by  FOREIGN KEY (verified_by)   REFERENCES users(id),
    CONSTRAINT fk_sites_approved_by  FOREIGN KEY (approved_by)   REFERENCES users(id),
    CONSTRAINT fk_sites_rejected_by  FOREIGN KEY (rejected_by)   REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- POE (proof of evidence) file attachments. Append-only — a corrected or
-- additional document is a new row, never an overwrite of a prior upload.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_poe_documents (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    site_id          INT NOT NULL,
    file_name        VARCHAR(255) NOT NULL,
    stored_filename  VARCHAR(255) NOT NULL,
    file_size        INT NOT NULL,
    mime_type        VARCHAR(100) NOT NULL,
    description      VARCHAR(255) NULL,
    uploaded_by      INT NOT NULL,
    uploaded_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_poe_site        FOREIGN KEY (site_id)     REFERENCES sites(id) ON DELETE CASCADE,
    CONSTRAINT fk_poe_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Site settings — a single row (id = 1) holding sitewide branding: the
-- name/tagline shown in the header and page titles, an optional logo
-- (uploaded file, stored in assets/branding/), and the footer text.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_settings (
    id             INT PRIMARY KEY,
    site_name      VARCHAR(150) NOT NULL DEFAULT 'USAASA MEL System',
    tagline        VARCHAR(255) NULL,
    footer_text    VARCHAR(255) NULL,
    logo_filename  VARCHAR(255) NULL,

    -- Outbound email (SMTP) for status-change notifications to installers.
    -- smtp_password is stored as plain text here for simplicity, same as
    -- every other credential this app currently holds in the database —
    -- see the security roadmap for moving secrets to a vault/env var.
    smtp_enabled    TINYINT(1) NOT NULL DEFAULT 0,
    smtp_host       VARCHAR(255) NULL,
    smtp_port       INT NULL DEFAULT 587,
    smtp_encryption ENUM('none','tls','ssl') NOT NULL DEFAULT 'tls',
    smtp_username   VARCHAR(255) NULL,
    smtp_password   VARCHAR(255) NULL,
    smtp_from_email VARCHAR(255) NULL,
    smtp_from_name  VARCHAR(150) NULL,

    updated_by     INT NULL,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

ALTER TABLE site_settings ADD COLUMN IF NOT EXISTS smtp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER logo_filename;
ALTER TABLE site_settings ADD COLUMN IF NOT EXISTS smtp_host VARCHAR(255) NULL AFTER smtp_enabled;
ALTER TABLE site_settings ADD COLUMN IF NOT EXISTS smtp_port INT NULL DEFAULT 587 AFTER smtp_host;
ALTER TABLE site_settings ADD COLUMN IF NOT EXISTS smtp_encryption ENUM('none','tls','ssl') NOT NULL DEFAULT 'tls' AFTER smtp_port;
ALTER TABLE site_settings ADD COLUMN IF NOT EXISTS smtp_username VARCHAR(255) NULL AFTER smtp_encryption;
ALTER TABLE site_settings ADD COLUMN IF NOT EXISTS smtp_password VARCHAR(255) NULL AFTER smtp_username;
ALTER TABLE site_settings ADD COLUMN IF NOT EXISTS smtp_from_email VARCHAR(255) NULL AFTER smtp_password;
ALTER TABLE site_settings ADD COLUMN IF NOT EXISTS smtp_from_name VARCHAR(150) NULL AFTER smtp_from_email;

INSERT INTO site_settings (id, site_name, tagline, footer_text)
VALUES (1, 'USAASA MEL System', 'Monitoring, Evaluation & Learning', 'USAASA — Monitoring, Evaluation & Learning System')
ON DUPLICATE KEY UPDATE id = id;

-- ---------------------------------------------------------------------------
-- Notification log — append-only record of every email attempt (sent or
-- failed), so an admin can actually see what happened when SMTP delivery
-- is inevitably fiddly to get right the first time.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_log (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    site_id         INT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    subject         VARCHAR(255) NOT NULL,
    status          ENUM('sent','failed') NOT NULL,
    error_message   TEXT NULL,
    sent_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_notification_log_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- CMS pages — simple, admin-editable content pages (e.g. help text,
-- glossary, policy notes). Body text is always HTML-escaped on render and
-- only a small fixed set of safe formatting (bold/italic/headings/lists/
-- http(s) links) is layered on top — see render_cms_content() — so a page
-- author can never inject arbitrary HTML/script.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cms_pages (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    slug          VARCHAR(100) NOT NULL UNIQUE,
    title         VARCHAR(255) NOT NULL,
    body          TEXT NOT NULL,
    is_published  TINYINT(1) NOT NULL DEFAULT 1,
    created_by    INT NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by    INT NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_cms_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_cms_updated_by FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Indigent Applications — data capture for the SABC TV licence
-- concession / set-top-box subsidy programme in rural areas. No approval
-- workflow here (unlike Sites) — this module is capture + manage +
-- import/export only, per what was actually asked for.
--
-- Address is captured as the three requested administrative fields
-- (Province/District/Local Municipality) PLUS a village/area name and a
-- physical address line — the three administrative fields alone don't
-- locate a specific household, so this module adds the two extra free-text
-- fields needed to make "capture the address" actually mean something.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS indigent_applications (
    id                          INT AUTO_INCREMENT PRIMARY KEY,
    reference_code              VARCHAR(50)  NOT NULL UNIQUE,

    first_name                  VARCHAR(100) NOT NULL,
    surname                     VARCHAR(100) NOT NULL,
    id_number                   VARCHAR(13)  NOT NULL UNIQUE,
    id_capture_method           ENUM('scanned','manual') NOT NULL DEFAULT 'manual',

    latitude                    DECIMAL(10,7) NULL,
    longitude                   DECIMAL(10,7) NULL,
    household_photo_filename    VARCHAR(255) NULL,

    province                    VARCHAR(50)  NOT NULL,
    district_municipality       VARCHAR(150) NOT NULL,
    local_municipality          VARCHAR(150) NOT NULL,
    village_name                VARCHAR(150) NOT NULL,
    physical_address            TEXT NULL,

    number_of_pupils            INT NOT NULL DEFAULT 0,
    distance_north_km           DECIMAL(6,2) NULL,
    distance_south_km           DECIMAL(6,2) NULL,
    distance_east_km            DECIMAL(6,2) NULL,
    distance_west_km            DECIMAL(6,2) NULL,
    monthly_income              DECIMAL(10,2) NOT NULL DEFAULT 0,
    has_functional_tv           TINYINT(1) NOT NULL DEFAULT 0,

    declares_south_african        TINYINT(1) NOT NULL DEFAULT 0,
    declares_income_under_3200    TINYINT(1) NOT NULL DEFAULT 0,
    declares_sabc_concession      TINYINT(1) NOT NULL DEFAULT 0,
    declares_grant_with_proof     TINYINT(1) NOT NULL DEFAULT 0,

    signature_filename          VARCHAR(255) NULL,
    signed_at                   DATETIME NULL,

    captured_by                 INT NOT NULL,
    captured_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_indigent_captured_by FOREIGN KEY (captured_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Projects: normalised province
--
-- Every seeded project is actually a province-level rollout (one project
-- per province), but that was only ever expressed in the project's name.
-- This makes it explicit so Planning & Dispatch (below) can key allocations
-- off a real province field instead of parsing project_name text. A
-- national/cross-province project (e.g. a universal-fund initiative) can
-- leave this NULL — Planning & Dispatch only offers province-tagged
-- projects, since "dispatch by province" needs one.
-- ---------------------------------------------------------------------------
ALTER TABLE projects ADD COLUMN IF NOT EXISTS province VARCHAR(50) NULL AFTER project_name;

-- ---------------------------------------------------------------------------
-- Projects: programme grouping
--
-- Splits projects into the two USAASA programmes they actually belong to:
-- "Broadband Connectivity" (the province-level internet access rollouts
-- already seeded) and "BDM" (Broadcasting and Digital Migration — set-top
-- box distribution / analogue switch-off support). Existing projects
-- default to Broadband Connectivity, so nothing already seeded changes
-- category silently.
-- ---------------------------------------------------------------------------
ALTER TABLE projects ADD COLUMN IF NOT EXISTS programme ENUM('Broadband Connectivity','BDM') NOT NULL DEFAULT 'Broadband Connectivity' AFTER province;

-- ---------------------------------------------------------------------------
-- Planning & Dispatch — allocate installers (field_worker users) to a
-- province + region (district municipality / metro, drawn from the same
-- hierarchy as indigent/sa_municipalities.php), with a target installation
-- count and a deadline. Follows the same original/current deadline audit
-- pattern as findings_register/risks/indicators.
--
-- Progress is reported the same way as indicator_actuals: one append-only
-- row per period, carrying the CUMULATIVE installations completed as of
-- that period (not a per-period delta). When an allocation is linked to a
-- Results Framework indicator, each progress update also writes/updates an
-- indicator_actuals row for that indicator — this is the "report update to
-- result framework modules" link: Planning reports feed the indicator's
-- actuals automatically, one-way.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS installer_allocations (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    reference_code        VARCHAR(50)  NOT NULL UNIQUE,
    project_id            INT NOT NULL,
    region                VARCHAR(150) NOT NULL,
    installer_id          INT NOT NULL,
    indicator_id          INT NULL,

    target_installations  INT NOT NULL,
    original_deadline     DATE NOT NULL,
    current_deadline      DATE NOT NULL,

    status                ENUM('Planned','In Progress','Completed','Overdue') NOT NULL DEFAULT 'Planned',
    notes                 TEXT NULL,

    dispatched_by         INT NOT NULL,
    dispatched_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_alloc_project    FOREIGN KEY (project_id)    REFERENCES projects(id),
    CONSTRAINT fk_alloc_installer  FOREIGN KEY (installer_id)  REFERENCES users(id),
    CONSTRAINT fk_alloc_indicator  FOREIGN KEY (indicator_id)  REFERENCES indicators(id),
    CONSTRAINT fk_alloc_dispatcher FOREIGN KEY (dispatched_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS allocation_progress_updates (
    id                       INT AUTO_INCREMENT PRIMARY KEY,
    allocation_id            INT NOT NULL,
    period_label             VARCHAR(50)  NOT NULL,
    period_date              DATE NOT NULL,
    installations_completed  INT NOT NULL,
    comments                 TEXT NULL,
    reported_by              INT NOT NULL,
    reported_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_alloc_progress_allocation FOREIGN KEY (allocation_id) REFERENCES installer_allocations(id) ON DELETE CASCADE,
    CONSTRAINT fk_alloc_progress_user       FOREIGN KEY (reported_by)   REFERENCES users(id),
    CONSTRAINT uq_alloc_progress_period     UNIQUE (allocation_id, period_label)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Allocation deadline revision audit trail (mirrors risk_deadline_revisions)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS allocation_deadline_revisions (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    allocation_id     INT NOT NULL,
    previous_deadline DATE NOT NULL,
    new_deadline      DATE NOT NULL,
    reason            TEXT NOT NULL,
    revised_by        INT NOT NULL,
    revised_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_alloc_rev_allocation FOREIGN KEY (allocation_id) REFERENCES installer_allocations(id) ON DELETE CASCADE,
    CONSTRAINT fk_alloc_rev_user       FOREIGN KEY (revised_by)    REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Import log — one row per CSV/Excel bulk import run, across every module
-- that supports it (Site Completions, Indigent Applications, Planning &
-- Dispatch allocations). Shown on the admin-only "Data Captured" report.
-- `details` holds a JSON-encoded array of per-row outcomes (created /
-- updated / duplicate_rejected / invalid, with a reason) for drill-down.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS import_log (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    module          VARCHAR(50) NOT NULL,
    mode            ENUM('reject_duplicates','update') NOT NULL,
    file_name       VARCHAR(255) NOT NULL,
    period_start    DATE NULL,
    period_end      DATE NULL,
    total_rows      INT NOT NULL DEFAULT 0,
    created_count   INT NOT NULL DEFAULT 0,
    updated_count   INT NOT NULL DEFAULT 0,
    duplicate_count INT NOT NULL DEFAULT 0,
    invalid_count   INT NOT NULL DEFAULT 0,
    details         TEXT NULL,
    imported_by     INT NOT NULL,
    imported_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_import_log_user FOREIGN KEY (imported_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- AI Reports — automated, rule-based cross-module integrated reports
-- (Monthly / Quarterly / Half-Yearly / Yearly). "AI" here means automated
-- aggregation + template-driven narrative generation from real numbers —
-- no external API call, consistent with this app's zero-dependency design.
-- A generated report's full computed snapshot (every section's numbers and
-- narrative) is frozen as JSON at generation time, so re-opening an old
-- report later shows exactly what was reported then, even if the
-- underlying live data has since changed.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_reports (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    period_type   ENUM('Monthly','Quarterly','Half-Yearly','Yearly') NOT NULL,
    period_label  VARCHAR(50) NOT NULL,
    period_start  DATE NOT NULL,
    period_end    DATE NOT NULL,
    snapshot      LONGTEXT NOT NULL,
    generated_by  INT NOT NULL,
    generated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_ai_reports_user FOREIGN KEY (generated_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Per-user module denials — an explicit override that revokes a specific
-- user's access to a specific module, regardless of what their role would
-- otherwise grant. Enforced centrally in require_login() (includes/auth.php),
-- which every require_role() call already goes through first — so this
-- protects every existing protected page automatically, with no sweep of
-- individual require_role() call sites. See includes/access_matrix.php for
-- the module_key values and the enforcement/lookup functions.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_module_denials (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    module_key  VARCHAR(50) NOT NULL,
    denied_by   INT NOT NULL,
    denied_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_denial_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_denial_denied_by FOREIGN KEY (denied_by) REFERENCES users(id),
    CONSTRAINT uq_denial_user_module UNIQUE (user_id, module_key)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Seed data
-- ---------------------------------------------------------------------------
INSERT INTO users (username, password_hash, full_name, email, role)
VALUES ('admin', '$2y$10$Gpr76WanXy0mQNs5DbvJDuQnhaF1LxWe3bJw2aDnYmlnSDObvio/O', 'System Administrator', 'admin@usaasa.org.za', 'admin')
ON DUPLICATE KEY UPDATE username = username;
