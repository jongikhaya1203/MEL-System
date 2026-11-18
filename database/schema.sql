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
-- Broadband Infrastructure Acceptance Form — one per site (UNIQUE site_id).
-- Captured/edited from sites/acceptance_form.php and printed for wet-ink
-- signature by the site representative and the USAASA representative.
-- Y/N answers are stored as 'Y' / 'N' (NULL = not yet answered).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_acceptance_forms (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    site_id                 INT NOT NULL UNIQUE,

    project_name            VARCHAR(255) NULL,

    site_name               VARCHAR(255) NULL,
    site_type               ENUM('School','Hotspot','Clinic','Library','Other') NULL,
    site_type_other         VARCHAR(150) NULL,
    official_site_id        VARCHAR(100) NULL,
    province                VARCHAR(100) NULL,
    district_municipality   VARCHAR(150) NULL,
    local_municipality      VARCHAR(150) NULL,
    town_village            VARCHAR(255) NULL,
    latitude                VARCHAR(30)  NULL,
    longitude               VARCHAR(30)  NULL,

    service_provider        VARCHAR(255) NULL,
    installing_company      VARCHAR(255) NULL,

    installation_neat       CHAR(1) NULL,
    dedicated_power_plug    CHAR(1) NULL,
    poe_enclosure_installed CHAR(1) NULL,
    secure_room             CHAR(1) NULL,
    adapter_mounted         CHAR(1) NULL,
    mounted                 CHAR(1) NULL,

    wifi_vsat               CHAR(1) NULL,
    wifi_terrestrial        CHAR(1) NULL,

    site_rep_date           DATE NULL,
    site_rep_name           VARCHAR(150) NULL,
    site_rep_designation    VARCHAR(150) NULL,
    site_rep_contact        VARCHAR(50)  NULL,

    usaasa_rep_date         DATE NULL,
    usaasa_rep_name         VARCHAR(150) NULL,
    usaasa_rep_designation  VARCHAR(150) NULL,
    usaasa_rep_contact      VARCHAR(50)  NULL,

    -- Backup power + connection speed, each with a proof image
    -- (JPG/PNG filename in storage/acceptance_signatures/).
    backup_power            CHAR(1) NULL,
    backup_power_type       VARCHAR(150) NULL,
    backup_power_proof      VARCHAR(255) NULL,
    download_speed_mbps     DECIMAL(10,2) NULL,
    upload_speed_mbps       DECIMAL(10,2) NULL,
    speed_proof             VARCHAR(255) NULL,

    -- On-screen signatures: PNG filenames in storage/acceptance_signatures/
    site_rep_signature      VARCHAR(255) NULL,
    site_rep_signed_at      DATETIME NULL,
    usaasa_rep_signature    VARCHAR(255) NULL,
    usaasa_rep_signed_at    DATETIME NULL,

    updated_by              INT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_acceptance_site       FOREIGN KEY (site_id)    REFERENCES sites(id) ON DELETE CASCADE,
    CONSTRAINT fk_acceptance_updated_by FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

ALTER TABLE site_acceptance_forms ADD COLUMN IF NOT EXISTS site_rep_signature   VARCHAR(255) NULL AFTER usaasa_rep_contact;
ALTER TABLE site_acceptance_forms ADD COLUMN IF NOT EXISTS site_rep_signed_at   DATETIME NULL     AFTER site_rep_signature;
ALTER TABLE site_acceptance_forms ADD COLUMN IF NOT EXISTS usaasa_rep_signature VARCHAR(255) NULL AFTER site_rep_signed_at;
ALTER TABLE site_acceptance_forms ADD COLUMN IF NOT EXISTS usaasa_rep_signed_at DATETIME NULL     AFTER usaasa_rep_signature;
ALTER TABLE site_acceptance_forms ADD COLUMN IF NOT EXISTS backup_power         CHAR(1) NULL      AFTER wifi_terrestrial;
ALTER TABLE site_acceptance_forms ADD COLUMN IF NOT EXISTS backup_power_type    VARCHAR(150) NULL AFTER backup_power;
ALTER TABLE site_acceptance_forms ADD COLUMN IF NOT EXISTS backup_power_proof   VARCHAR(255) NULL AFTER backup_power_type;
ALTER TABLE site_acceptance_forms ADD COLUMN IF NOT EXISTS download_speed_mbps  DECIMAL(10,2) NULL AFTER backup_power_proof;
ALTER TABLE site_acceptance_forms ADD COLUMN IF NOT EXISTS upload_speed_mbps    DECIMAL(10,2) NULL AFTER download_speed_mbps;
ALTER TABLE site_acceptance_forms ADD COLUMN IF NOT EXISTS speed_proof          VARCHAR(255) NULL AFTER upload_speed_mbps;

-- ---------------------------------------------------------------------------
-- Broadband rollout register — new sites identified for connection that are
-- awaiting rollout. Each one gets a pre-assessment (site survey) before
-- installation; once installed it is submitted through Site Completions.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS broadband_rollout_sites (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    reference_code          VARCHAR(50)  NOT NULL UNIQUE,
    project_id              INT NOT NULL,

    site_name               VARCHAR(255) NOT NULL,
    site_type               ENUM('School','Hotspot','Clinic','Library','Other') NULL,
    site_type_other         VARCHAR(150) NULL,
    official_site_id        VARCHAR(100) NULL,
    province                VARCHAR(100) NULL,
    district_municipality   VARCHAR(150) NULL,
    local_municipality      VARCHAR(150) NULL,
    town_village            VARCHAR(255) NULL,
    latitude                VARCHAR(30)  NULL,
    longitude               VARCHAR(30)  NULL,

    contact_details         VARCHAR(255) NULL,

    service_provider        VARCHAR(255) NULL,
    planned_rollout_date    DATE NULL,
    status                  ENUM('Awaiting Pre-assessment','Pre-assessed','Approved for Rollout','Rolled Out','Cancelled')
                            NOT NULL DEFAULT 'Awaiting Pre-assessment',
    notes                   TEXT NULL,

    created_by              INT NOT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_rollout_project    FOREIGN KEY (project_id) REFERENCES projects(id),
    CONSTRAINT fk_rollout_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

ALTER TABLE broadband_rollout_sites ADD COLUMN IF NOT EXISTS contact_details VARCHAR(255) NULL AFTER longitude;

-- Deployment / connectivity state shown on the list and the map:
--   New = not yet deployed (red), Online = deployed and connected (green), Offline = deployed but down (orange).
ALTER TABLE broadband_rollout_sites ADD COLUMN IF NOT EXISTS connectivity_status ENUM('New','Online','Offline') NOT NULL DEFAULT 'New' AFTER status;
ALTER TABLE broadband_rollout_sites ADD COLUMN IF NOT EXISTS connectivity_changed_at DATETIME NULL AFTER connectivity_status;
ALTER TABLE broadband_rollout_sites ADD COLUMN IF NOT EXISTS connectivity_changed_by INT NULL AFTER connectivity_changed_at;

-- ---------------------------------------------------------------------------
-- Broadband site pre-assessment form — one per rollout site (UNIQUE).
-- Y/N answers stored as 'Y' / 'N' (NULL = not answered). Site photo and
-- signatures are files in storage/acceptance_signatures/ (shared, locked
-- down broadband uploads folder), served by broadband/pa_file.php.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_pre_assessments (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    rollout_site_id         INT NOT NULL UNIQUE,
    assessment_date         DATE NULL,

    contact_name            VARCHAR(150) NULL,
    contact_designation     VARCHAR(150) NULL,
    contact_number          VARCHAR(50)  NULL,
    contact_email           VARCHAR(150) NULL,

    mains_power             CHAR(1) NULL,
    power_stable            CHAR(1) NULL,
    backup_power            CHAR(1) NULL,
    secure_room             CHAR(1) NULL,
    mounting_location       CHAR(1) NULL,
    line_of_sight           CHAR(1) NULL,
    existing_connectivity   CHAR(1) NULL,
    site_accessible         CHAR(1) NULL,
    permission_granted      CHAR(1) NULL,

    recommended_technology  ENUM('VSAT','Terrestrial','Fibre','Fixed Wireless','Other') NULL,
    recommended_tech_other  VARCHAR(150) NULL,
    mobile_signal           VARCHAR(100) NULL,
    estimated_users         INT NULL,
    additional_requirements TEXT NULL,
    site_photo              VARCHAR(255) NULL,

    suitable_for_rollout    CHAR(1) NULL,
    comments                TEXT NULL,

    assessor_date           DATE NULL,
    assessor_name           VARCHAR(150) NULL,
    assessor_designation    VARCHAR(150) NULL,
    assessor_contact        VARCHAR(50)  NULL,
    assessor_signature      VARCHAR(255) NULL,
    assessor_signed_at      DATETIME NULL,

    site_rep_date           DATE NULL,
    site_rep_name           VARCHAR(150) NULL,
    site_rep_designation    VARCHAR(150) NULL,
    site_rep_contact        VARCHAR(50)  NULL,
    site_rep_signature      VARCHAR(255) NULL,
    site_rep_signed_at      DATETIME NULL,

    updated_by              INT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_preassess_site       FOREIGN KEY (rollout_site_id) REFERENCES broadband_rollout_sites(id) ON DELETE CASCADE,
    CONSTRAINT fk_preassess_updated_by FOREIGN KEY (updated_by)      REFERENCES users(id)
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
-- Digital Transformation programme (sidebar: Programmes > Digital
-- Transformation). Workstreams (ERP Beam Implementation, USAASA MEL System,
-- E-Procurement, Service Desk, Network Tool) each hold a register of
-- deliverables. A deliverable's evidence document (POE) is attached as
-- append-only versions, then routed through an ordered sign-off workflow of
-- governance bodies (CFO, CIO, Internal Audit, ...). Only users assigned as
-- signatories of the body at the current step can endorse/reject it; a
-- super admin maintains the bodies, their signatories and every route.
-- See includes/digital.php for the routing/status logic.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dt_projects (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    slug        VARCHAR(30)  NOT NULL UNIQUE,
    name        VARCHAR(150) NOT NULL,
    description TEXT NULL,
    sort_order  INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dt_governance_bodies (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    code           VARCHAR(20)  NOT NULL UNIQUE,
    name           VARCHAR(150) NOT NULL,
    short_name     VARCHAR(40)  NOT NULL,
    responsibility VARCHAR(255) NOT NULL,
    sort_order     INT NOT NULL DEFAULT 0,
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Users who sign on behalf of a governance body (e.g. the actual CFO).
CREATE TABLE IF NOT EXISTS dt_governance_members (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    body_id     INT NOT NULL,
    user_id     INT NOT NULL,
    designation VARCHAR(150) NULL,
    assigned_by INT NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_dtm_body FOREIGN KEY (body_id) REFERENCES dt_governance_bodies(id) ON DELETE CASCADE,
    CONSTRAINT fk_dtm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_dtm_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id),
    CONSTRAINT uq_dtm_body_user UNIQUE (body_id, user_id)
) ENGINE=InnoDB;

-- status / pending_body_id / signed_off_at are derived and kept current by
-- dt_refresh_status() after every upload, sign-off and route change.
CREATE TABLE IF NOT EXISTS dt_deliverables (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    project_id          INT NOT NULL,
    code                VARCHAR(30)  NOT NULL UNIQUE,
    section             VARCHAR(30)  NOT NULL DEFAULT 'general',
    title               VARCHAR(255) NOT NULL,
    description         TEXT NULL,
    owner_body_id       INT NULL,
    sort_order          INT NOT NULL DEFAULT 0,
    status              ENUM('Not Started','Awaiting Sign-off','Rejected','Signed Off') NOT NULL DEFAULT 'Not Started',
    current_document_id INT NULL,
    pending_body_id     INT NULL,
    signed_off_at       DATETIME NULL,
    workflow_customised TINYINT(1) NOT NULL DEFAULT 0,
    created_by          INT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_dtd_project FOREIGN KEY (project_id) REFERENCES dt_projects(id),
    CONSTRAINT fk_dtd_owner   FOREIGN KEY (owner_body_id) REFERENCES dt_governance_bodies(id) ON DELETE SET NULL,
    CONSTRAINT fk_dtd_pending FOREIGN KEY (pending_body_id) REFERENCES dt_governance_bodies(id) ON DELETE SET NULL,
    CONSTRAINT fk_dtd_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Ordered sign-off route for one deliverable (step 1 signs first).
CREATE TABLE IF NOT EXISTS dt_workflow_steps (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    deliverable_id INT NOT NULL,
    step_order     INT NOT NULL,
    body_id        INT NOT NULL,

    CONSTRAINT fk_dtw_deliverable FOREIGN KEY (deliverable_id) REFERENCES dt_deliverables(id) ON DELETE CASCADE,
    CONSTRAINT fk_dtw_body FOREIGN KEY (body_id) REFERENCES dt_governance_bodies(id) ON DELETE CASCADE,
    CONSTRAINT uq_dtw_step UNIQUE (deliverable_id, step_order),
    CONSTRAINT uq_dtw_body UNIQUE (deliverable_id, body_id)
) ENGINE=InnoDB;

-- Append-only document versions; a new version restarts the sign-off route.
CREATE TABLE IF NOT EXISTS dt_documents (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    deliverable_id  INT NOT NULL,
    version_no      INT NOT NULL,
    file_name       VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    file_size       INT NOT NULL,
    mime_type       VARCHAR(150) NOT NULL,
    sha256          CHAR(64) NOT NULL,
    notes           VARCHAR(500) NULL,
    uploaded_by     INT NOT NULL,
    uploaded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_dtdoc_deliverable FOREIGN KEY (deliverable_id) REFERENCES dt_deliverables(id) ON DELETE CASCADE,
    CONSTRAINT fk_dtdoc_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id),
    CONSTRAINT uq_dtdoc_version UNIQUE (deliverable_id, version_no)
) ENGINE=InnoDB;

-- One decision per governance body per document version. body_name and
-- step_order are snapshots, so the audit trail survives later route edits.
CREATE TABLE IF NOT EXISTS dt_signoffs (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    deliverable_id     INT NOT NULL,
    document_id        INT NOT NULL,
    body_id            INT NULL,
    body_name          VARCHAR(150) NOT NULL,
    step_order         INT NOT NULL,
    decision           ENUM('Endorsed','Rejected') NOT NULL,
    comments           TEXT NULL,
    signed_by          INT NOT NULL,
    signer_name        VARCHAR(150) NOT NULL,
    signer_designation VARCHAR(150) NULL,
    signature_file     VARCHAR(255) NULL,
    ip_address         VARCHAR(45) NULL,
    signed_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_dts_deliverable FOREIGN KEY (deliverable_id) REFERENCES dt_deliverables(id) ON DELETE CASCADE,
    CONSTRAINT fk_dts_document FOREIGN KEY (document_id) REFERENCES dt_documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_dts_body FOREIGN KEY (body_id) REFERENCES dt_governance_bodies(id) ON DELETE SET NULL,
    CONSTRAINT fk_dts_signed_by FOREIGN KEY (signed_by) REFERENCES users(id),
    CONSTRAINT uq_dts_doc_body UNIQUE (document_id, body_id)
) ENGINE=InnoDB;

ALTER TABLE dt_deliverables ADD CONSTRAINT fk_dtd_current_doc FOREIGN KEY IF NOT EXISTS (current_document_id) REFERENCES dt_documents(id) ON DELETE SET NULL;

-- Seed: workstreams
INSERT INTO dt_projects (slug, name, description, sort_order) VALUES
('erp', 'ERP Beam Implementation', 'Replacement of the legacy ERP with the Beam ERP platform: blueprint deliverables, data migration blueprint and governance sign-off.', 1),
('mel', 'USAASA MEL System', 'Monitoring, Evaluation & Learning system — results framework, registers, programme data capture and reporting.', 2),
('eproc', 'E-Procurement', 'Electronic procurement platform — sourcing, supplier management, RFQ/tender and purchase-to-pay automation.', 3),
('servicedesk', 'Service Desk', 'IT service management — incident, request, problem and change management with SLA tracking.', 4),
('network', 'Network Tool', 'Network monitoring and management tooling — availability, capacity and configuration of the USAASA network.', 5)
ON DUPLICATE KEY UPDATE slug = slug;

-- Seed: governance structure (the blueprint must identify who owns and approves each component)
INSERT INTO dt_governance_bodies (code, name, short_name, responsibility, sort_order) VALUES
('ESC', 'Executive Steering Committee', 'Steering Committee', 'Strategic oversight and decisions', 1),
('CIO', 'CIO / IT Executive', 'CIO', 'Technology architecture and delivery', 2),
('CFO', 'CFO', 'CFO', 'Financial processes and controls', 3),
('HR',  'HR Executive', 'HR Executive', 'Human capital requirements', 4),
('SCM', 'Supply Chain Executive', 'Supply Chain', 'Procurement and supply chain', 5),
('BPO', 'Business Process Owners', 'Process Owners', 'Functional requirements and acceptance', 6),
('IMP', 'ERP Implementation Partner', 'Impl. Partner', 'Configuration and technical delivery', 7),
('IA',  'Internal Audit', 'Internal Audit', 'Controls, compliance and assurance', 8)
ON DUPLICATE KEY UPDATE code = code;

-- Seed: ERP blueprint deliverables checklist + data migration blueprint
INSERT INTO dt_deliverables (project_id, code, section, title, description, sort_order)
SELECT p.id, s.code, s.section, s.title, s.description, s.sort_order
FROM (
    SELECT 'ERP-BP-01' AS code, 'blueprint' AS section, 'Approved ERP Business Requirements Specification (BRS)' AS title, NULL AS description, 1 AS sort_order
    UNION ALL SELECT 'ERP-BP-02', 'blueprint', 'AS-IS business process documentation', NULL, 2
    UNION ALL SELECT 'ERP-BP-03', 'blueprint', 'TO-BE business process documentation', NULL, 3
    UNION ALL SELECT 'ERP-BP-04', 'blueprint', 'Functional Requirements Specification (FRS)', NULL, 4
    UNION ALL SELECT 'ERP-BP-05', 'blueprint', 'ERP gap analysis and fit-gap assessment', NULL, 5
    UNION ALL SELECT 'ERP-BP-06', 'blueprint', 'Enterprise Solution Architecture Document', NULL, 6
    UNION ALL SELECT 'ERP-BP-07', 'blueprint', 'ERP Technical Architecture Document', NULL, 7
    UNION ALL SELECT 'ERP-BP-08', 'blueprint', 'Data Migration and Data Governance Strategy', NULL, 8
    UNION ALL SELECT 'ERP-BP-09', 'blueprint', 'Systems Integration Architecture and Interface Catalogue', NULL, 9
    UNION ALL SELECT 'ERP-BP-10', 'blueprint', 'Security and Access Control Matrix', NULL, 10
    UNION ALL SELECT 'ERP-BP-11', 'blueprint', 'Reporting and Business Intelligence Requirements', NULL, 11
    UNION ALL SELECT 'ERP-BP-12', 'blueprint', 'ERP Implementation Project Plan and Gantt Chart', NULL, 12
    UNION ALL SELECT 'ERP-BP-13', 'blueprint', 'ERP Testing and Quality Assurance Strategy', NULL, 13
    UNION ALL SELECT 'ERP-BP-14', 'blueprint', 'Change Management and Training Plan', NULL, 14
    UNION ALL SELECT 'ERP-BP-15', 'blueprint', 'Risk Register and Business Continuity Plan', NULL, 15
    UNION ALL SELECT 'ERP-BP-16', 'blueprint', 'ERP Cost Model, TCO and ROI Business Case', NULL, 16
    UNION ALL SELECT 'ERP-BP-17', 'blueprint', 'ERP Vendor Evaluation and Selection Report', NULL, 17
    UNION ALL SELECT 'ERP-BP-18', 'blueprint', 'Implementation Acceptance Criteria and Sign-off Matrix', NULL, 18
    UNION ALL SELECT 'ERP-DM-01', 'data_migration', 'Existing data sources and databases', 'Inventory of every legacy system, database and file store holding data in scope for migration.', 1
    UNION ALL SELECT 'ERP-DM-02', 'data_migration', 'Data ownership and accountability', 'Named data owners and stewards accountable for each data domain.', 2
    UNION ALL SELECT 'ERP-DM-03', 'data_migration', 'Master data management', 'Master data definitions, standards and the governance process for maintaining them.', 3
    UNION ALL SELECT 'ERP-DM-04', 'data_migration', 'Data cleansing and deduplication', 'Cleansing rules, deduplication approach and data-quality results before load.', 4
    UNION ALL SELECT 'ERP-DM-05', 'data_migration', 'Data mapping between old and new ERP', 'Field-level source-to-target mapping and transformation rules.', 5
    UNION ALL SELECT 'ERP-DM-06', 'data_migration', 'Historical transaction migration', 'Scope, period and method for migrating historical transactions.', 6
    UNION ALL SELECT 'ERP-DM-07', 'data_migration', 'Customer, supplier and employee records', 'Migration approach and validation for customer, supplier and employee master records.', 7
    UNION ALL SELECT 'ERP-DM-08', 'data_migration', 'Financial opening balances', 'Trial balance and sub-ledger opening balances to be loaded at cut-over.', 8
    UNION ALL SELECT 'ERP-DM-09', 'data_migration', 'Asset registers and inventory balances', 'Fixed asset register and inventory balances to be migrated and reconciled.', 9
    UNION ALL SELECT 'ERP-DM-10', 'data_migration', 'Data validation and reconciliation', 'Validation checks and reconciliation of migrated data back to source.', 10
    UNION ALL SELECT 'ERP-DM-11', 'data_migration', 'Migration testing and sign-off', 'Mock migration cycles, test results and formal migration sign-off.', 11
    UNION ALL SELECT 'ERP-DM-12', 'data_migration', 'Data retention and archival', 'Retention periods and archival of legacy data not migrated.', 12
) s
JOIN dt_projects p ON p.slug = 'erp'
ON DUPLICATE KEY UPDATE code = dt_deliverables.code;

-- Seed: accountable owner of each component (only where none is set yet)
UPDATE dt_deliverables d
JOIN (
    SELECT 'ERP-BP-01' AS code, 'BPO' AS body UNION ALL SELECT 'ERP-BP-02', 'BPO' UNION ALL SELECT 'ERP-BP-03', 'BPO'
    UNION ALL SELECT 'ERP-BP-04', 'BPO' UNION ALL SELECT 'ERP-BP-05', 'IMP' UNION ALL SELECT 'ERP-BP-06', 'CIO'
    UNION ALL SELECT 'ERP-BP-07', 'IMP' UNION ALL SELECT 'ERP-BP-08', 'CIO' UNION ALL SELECT 'ERP-BP-09', 'IMP'
    UNION ALL SELECT 'ERP-BP-10', 'CIO' UNION ALL SELECT 'ERP-BP-11', 'BPO' UNION ALL SELECT 'ERP-BP-12', 'IMP'
    UNION ALL SELECT 'ERP-BP-13', 'IMP' UNION ALL SELECT 'ERP-BP-14', 'HR'  UNION ALL SELECT 'ERP-BP-15', 'CIO'
    UNION ALL SELECT 'ERP-BP-16', 'CFO' UNION ALL SELECT 'ERP-BP-17', 'SCM' UNION ALL SELECT 'ERP-BP-18', 'BPO'
    UNION ALL SELECT 'ERP-DM-01', 'CIO' UNION ALL SELECT 'ERP-DM-02', 'CIO' UNION ALL SELECT 'ERP-DM-03', 'BPO'
    UNION ALL SELECT 'ERP-DM-04', 'BPO' UNION ALL SELECT 'ERP-DM-05', 'IMP' UNION ALL SELECT 'ERP-DM-06', 'CFO'
    UNION ALL SELECT 'ERP-DM-07', 'BPO' UNION ALL SELECT 'ERP-DM-08', 'CFO' UNION ALL SELECT 'ERP-DM-09', 'CFO'
    UNION ALL SELECT 'ERP-DM-10', 'CFO' UNION ALL SELECT 'ERP-DM-11', 'CIO' UNION ALL SELECT 'ERP-DM-12', 'CIO'
) o ON o.code = d.code
JOIN dt_governance_bodies b ON b.code = o.body
SET d.owner_body_id = b.id
WHERE d.owner_body_id IS NULL AND d.workflow_customised = 0;

-- Seed: default sign-off routes. Only applied to a deliverable that has no
-- route yet and whose route a super admin has never edited, so re-importing
-- this file never overwrites a customised workflow.
INSERT INTO dt_workflow_steps (deliverable_id, step_order, body_id)
SELECT d.id, r.step_order, b.id
FROM (
              SELECT 'ERP-BP-01' AS code, 1 AS step_order, 'BPO' AS body UNION ALL SELECT 'ERP-BP-01', 2, 'CIO' UNION ALL SELECT 'ERP-BP-01', 3, 'ESC'
    UNION ALL SELECT 'ERP-BP-02', 1, 'BPO' UNION ALL SELECT 'ERP-BP-02', 2, 'CIO'
    UNION ALL SELECT 'ERP-BP-03', 1, 'IMP' UNION ALL SELECT 'ERP-BP-03', 2, 'BPO' UNION ALL SELECT 'ERP-BP-03', 3, 'CIO'
    UNION ALL SELECT 'ERP-BP-04', 1, 'IMP' UNION ALL SELECT 'ERP-BP-04', 2, 'BPO' UNION ALL SELECT 'ERP-BP-04', 3, 'CIO'
    UNION ALL SELECT 'ERP-BP-05', 1, 'IMP' UNION ALL SELECT 'ERP-BP-05', 2, 'BPO' UNION ALL SELECT 'ERP-BP-05', 3, 'CIO'
    UNION ALL SELECT 'ERP-BP-06', 1, 'IMP' UNION ALL SELECT 'ERP-BP-06', 2, 'CIO' UNION ALL SELECT 'ERP-BP-06', 3, 'ESC'
    UNION ALL SELECT 'ERP-BP-07', 1, 'IMP' UNION ALL SELECT 'ERP-BP-07', 2, 'CIO'
    UNION ALL SELECT 'ERP-BP-08', 1, 'IMP' UNION ALL SELECT 'ERP-BP-08', 2, 'CIO' UNION ALL SELECT 'ERP-BP-08', 3, 'CFO' UNION ALL SELECT 'ERP-BP-08', 4, 'IA'
    UNION ALL SELECT 'ERP-BP-09', 1, 'IMP' UNION ALL SELECT 'ERP-BP-09', 2, 'CIO'
    UNION ALL SELECT 'ERP-BP-10', 1, 'CIO' UNION ALL SELECT 'ERP-BP-10', 2, 'IA'
    UNION ALL SELECT 'ERP-BP-11', 1, 'BPO' UNION ALL SELECT 'ERP-BP-11', 2, 'CFO' UNION ALL SELECT 'ERP-BP-11', 3, 'CIO'
    UNION ALL SELECT 'ERP-BP-12', 1, 'IMP' UNION ALL SELECT 'ERP-BP-12', 2, 'CIO' UNION ALL SELECT 'ERP-BP-12', 3, 'ESC'
    UNION ALL SELECT 'ERP-BP-13', 1, 'IMP' UNION ALL SELECT 'ERP-BP-13', 2, 'BPO' UNION ALL SELECT 'ERP-BP-13', 3, 'CIO'
    UNION ALL SELECT 'ERP-BP-14', 1, 'IMP' UNION ALL SELECT 'ERP-BP-14', 2, 'HR'  UNION ALL SELECT 'ERP-BP-14', 3, 'ESC'
    UNION ALL SELECT 'ERP-BP-15', 1, 'CIO' UNION ALL SELECT 'ERP-BP-15', 2, 'IA'  UNION ALL SELECT 'ERP-BP-15', 3, 'ESC'
    UNION ALL SELECT 'ERP-BP-16', 1, 'CFO' UNION ALL SELECT 'ERP-BP-16', 2, 'ESC'
    UNION ALL SELECT 'ERP-BP-17', 1, 'SCM' UNION ALL SELECT 'ERP-BP-17', 2, 'CFO' UNION ALL SELECT 'ERP-BP-17', 3, 'IA'  UNION ALL SELECT 'ERP-BP-17', 4, 'ESC'
    UNION ALL SELECT 'ERP-BP-18', 1, 'BPO' UNION ALL SELECT 'ERP-BP-18', 2, 'CIO' UNION ALL SELECT 'ERP-BP-18', 3, 'IA'  UNION ALL SELECT 'ERP-BP-18', 4, 'ESC'
    UNION ALL SELECT 'ERP-DM-01', 1, 'IMP' UNION ALL SELECT 'ERP-DM-01', 2, 'CIO'
    UNION ALL SELECT 'ERP-DM-02', 1, 'CIO' UNION ALL SELECT 'ERP-DM-02', 2, 'BPO' UNION ALL SELECT 'ERP-DM-02', 3, 'ESC'
    UNION ALL SELECT 'ERP-DM-03', 1, 'IMP' UNION ALL SELECT 'ERP-DM-03', 2, 'BPO' UNION ALL SELECT 'ERP-DM-03', 3, 'CIO'
    UNION ALL SELECT 'ERP-DM-04', 1, 'IMP' UNION ALL SELECT 'ERP-DM-04', 2, 'BPO'
    UNION ALL SELECT 'ERP-DM-05', 1, 'IMP' UNION ALL SELECT 'ERP-DM-05', 2, 'BPO' UNION ALL SELECT 'ERP-DM-05', 3, 'CIO'
    UNION ALL SELECT 'ERP-DM-06', 1, 'IMP' UNION ALL SELECT 'ERP-DM-06', 2, 'CFO' UNION ALL SELECT 'ERP-DM-06', 3, 'CIO'
    UNION ALL SELECT 'ERP-DM-07', 1, 'IMP' UNION ALL SELECT 'ERP-DM-07', 2, 'SCM' UNION ALL SELECT 'ERP-DM-07', 3, 'HR'  UNION ALL SELECT 'ERP-DM-07', 4, 'CFO'
    UNION ALL SELECT 'ERP-DM-08', 1, 'IMP' UNION ALL SELECT 'ERP-DM-08', 2, 'CFO' UNION ALL SELECT 'ERP-DM-08', 3, 'IA'
    UNION ALL SELECT 'ERP-DM-09', 1, 'IMP' UNION ALL SELECT 'ERP-DM-09', 2, 'SCM' UNION ALL SELECT 'ERP-DM-09', 3, 'CFO' UNION ALL SELECT 'ERP-DM-09', 4, 'IA'
    UNION ALL SELECT 'ERP-DM-10', 1, 'IMP' UNION ALL SELECT 'ERP-DM-10', 2, 'BPO' UNION ALL SELECT 'ERP-DM-10', 3, 'CFO' UNION ALL SELECT 'ERP-DM-10', 4, 'IA'
    UNION ALL SELECT 'ERP-DM-11', 1, 'IMP' UNION ALL SELECT 'ERP-DM-11', 2, 'BPO' UNION ALL SELECT 'ERP-DM-11', 3, 'CIO' UNION ALL SELECT 'ERP-DM-11', 4, 'ESC'
    UNION ALL SELECT 'ERP-DM-12', 1, 'CIO' UNION ALL SELECT 'ERP-DM-12', 2, 'IA'
) r
JOIN dt_deliverables d ON d.code = r.code
JOIN dt_governance_bodies b ON b.code = r.body
WHERE d.workflow_customised = 0
  AND NOT EXISTS (SELECT 1 FROM dt_workflow_steps w WHERE w.deliverable_id = d.id);

-- ---------------------------------------------------------------------------
-- Email routing & Active Directory (super admin: Email & Notifications,
-- Directory Access). Secrets (SMTP password, AD bind password) are stored
-- AES-256-GCM encrypted with the key in config/app_key.php — see
-- includes/secrets.php. A value without the "enc:" prefix is a legacy
-- plaintext value and is encrypted on the next save.
-- ---------------------------------------------------------------------------
ALTER TABLE site_settings MODIFY COLUMN smtp_password VARCHAR(512) NULL;
ALTER TABLE site_settings ADD COLUMN IF NOT EXISTS smtp_reply_to VARCHAR(255) NULL AFTER smtp_from_name;
ALTER TABLE site_settings ADD COLUMN IF NOT EXISTS mail_bcc_archive VARCHAR(255) NULL AFTER smtp_reply_to;
ALTER TABLE site_settings ADD COLUMN IF NOT EXISTS mail_test_mode_to VARCHAR(255) NULL AFTER mail_bcc_archive;

-- One row per notification type; a missing row means "enabled, no CC" (see notification_events()).
CREATE TABLE IF NOT EXISTS notification_rules (
    event_key  VARCHAR(50) PRIMARY KEY,
    enabled    TINYINT(1) NOT NULL DEFAULT 1,
    cc_emails  VARCHAR(1000) NULL,
    updated_by INT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_notification_rules_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE notification_log ADD COLUMN IF NOT EXISTS event_key VARCHAR(50) NULL AFTER site_id;

CREATE TABLE IF NOT EXISTS ad_settings (
    id                INT PRIMARY KEY,
    enabled           TINYINT(1) NOT NULL DEFAULT 0,
    hosts             VARCHAR(500) NULL,
    port              INT NOT NULL DEFAULT 636,
    encryption        ENUM('ldaps','starttls','none') NOT NULL DEFAULT 'ldaps',
    verify_cert       TINYINT(1) NOT NULL DEFAULT 1,
    base_dn           VARCHAR(500) NULL,
    bind_dn           VARCHAR(500) NULL,
    bind_password     VARCHAR(512) NULL,
    user_filter       VARCHAR(500) NOT NULL DEFAULT '(&(objectCategory=person)(objectClass=user))',
    upn_suffix        VARCHAR(150) NULL,
    nested_groups     TINYINT(1) NOT NULL DEFAULT 1,
    auto_provision    TINYINT(1) NOT NULL DEFAULT 0,
    sync_deactivate   TINYINT(1) NOT NULL DEFAULT 1,
    timeout_seconds   INT NOT NULL DEFAULT 5,
    last_sync_at      DATETIME NULL,
    last_sync_summary VARCHAR(500) NULL,
    updated_by        INT NULL,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_ad_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
INSERT INTO ad_settings (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id;

-- AD security group -> MEL role (+ optional Digital Transformation governance
-- body). The lowest priority number wins when a user is in several groups.
-- Mappings never grant Super Admin; that stays a manual, local decision.
CREATE TABLE IF NOT EXISTS ad_group_mappings (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    group_dn    VARCHAR(500) NOT NULL,
    group_name  VARCHAR(255) NULL,
    role        ENUM('admin','manager','viewer','field_worker','back_office','approver','indigent_capturer','planner') NOT NULL,
    dt_body_id  INT NULL,
    priority    INT NOT NULL DEFAULT 100,
    created_by  INT NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_adgm_body FOREIGN KEY (dt_body_id) REFERENCES dt_governance_bodies(id) ON DELETE SET NULL,
    CONSTRAINT fk_adgm_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- auth_source='ad': password checked against Active Directory, never stored
-- locally. role_source='directory': role follows AD group mappings on every
-- login/sync; 'manual': role set here and left alone by sync.
ALTER TABLE users ADD COLUMN IF NOT EXISTS auth_source ENUM('local','ad') NOT NULL DEFAULT 'local' AFTER is_super_admin;
ALTER TABLE users ADD COLUMN IF NOT EXISTS role_source ENUM('manual','directory') NOT NULL DEFAULT 'manual' AFTER auth_source;
ALTER TABLE users ADD COLUMN IF NOT EXISTS ad_guid VARCHAR(36) NULL AFTER role_source;
ALTER TABLE users ADD COLUMN IF NOT EXISTS ad_dn VARCHAR(500) NULL AFTER ad_guid;
ALTER TABLE users ADD COLUMN IF NOT EXISTS ad_synced_at DATETIME NULL AFTER ad_dn;
ALTER TABLE users ADD UNIQUE INDEX IF NOT EXISTS uq_users_ad_guid (ad_guid);

CREATE TABLE IF NOT EXISTS access_audit_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NULL,
    actor_id   INT NULL,
    action     VARCHAR(50) NOT NULL,
    details    VARCHAR(1000) NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_access_audit_created (created_at),
    CONSTRAINT fk_access_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_access_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Seed data
-- ---------------------------------------------------------------------------
INSERT INTO users (username, password_hash, full_name, email, role)
VALUES ('admin', '$2y$10$Gpr76WanXy0mQNs5DbvJDuQnhaF1LxWe3bJw2aDnYmlnSDObvio/O', 'System Administrator', 'admin@usaasa.org.za', 'admin')
ON DUPLICATE KEY UPDATE username = username;
