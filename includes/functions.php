<?php

require_once __DIR__ . '/sa_municipalities.php';
require_once __DIR__ . '/ai_report.php';
require_once __DIR__ . '/access_matrix.php';
require_once __DIR__ . '/digital.php';
require_once __DIR__ . '/secrets.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/directory.php';

function redirect(string $path): void
{
    header('Location: ' . APP_BASE_URL . $path);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function format_date(?string $date): string
{
    if (!$date) {
        return '—';
    }
    $ts = strtotime($date);
    return $ts ? date('d M Y', $ts) : '—';
}

function format_datetime(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }
    $ts = strtotime($datetime);
    return $ts ? date('d M Y H:i', $ts) : '—';
}

/**
 * Auto-flags any Open/In Progress finding, risk or indicator whose current
 * deadline/target date has passed without a confirmed closure/achievement
 * as Overdue. Closure/achievement can only be set by their explicit
 * confirm action, never by this sync.
 */
function sync_overdue_statuses(PDO $pdo): void
{
    $pdo->exec(
        "UPDATE findings_register
         SET status = 'Overdue'
         WHERE status IN ('Open', 'In Progress')
           AND current_deadline < CURDATE()"
    );
    $pdo->exec(
        "UPDATE risks
         SET status = 'Overdue'
         WHERE status IN ('Open', 'In Progress')
           AND current_deadline < CURDATE()"
    );
    $pdo->exec(
        "UPDATE indicators
         SET status = 'Overdue'
         WHERE status IN ('Open', 'In Progress')
           AND current_target_date < CURDATE()"
    );
    $pdo->exec(
        "UPDATE installer_allocations
         SET status = 'Overdue'
         WHERE status IN ('Planned', 'In Progress')
           AND current_deadline < CURDATE()"
    );
}

function status_badge(string $status): string
{
    $classes = [
        'Open' => 'badge badge-open',
        'In Progress' => 'badge badge-progress',
        'Overdue' => 'badge badge-overdue',
        'Closed' => 'badge badge-closed',
        'Achieved' => 'badge badge-closed',
        'Planned' => 'badge badge-open',
        'Completed' => 'badge badge-closed',
    ];
    $class = $classes[$status] ?? 'badge';
    return '<span class="' . $class . '">' . e($status) . '</span>';
}

/**
 * Human-facing label for a role slug. field_worker/back_office are shown
 * as Installer/Reviewer — the terminology USAASA actually uses for the
 * site-completion-and-payment-approval chain — without touching the
 * underlying role slug, which stays wired into every require_role() call.
 */
function role_label(string $role): string
{
    $labels = [
        'field_worker' => 'Installer',
        'back_office' => 'Reviewer',
        'indigent_capturer' => 'Data Capturer',
    ];
    return $labels[$role] ?? ucwords(str_replace('_', ' ', $role));
}

/** A real captured screenshot, from assets/screenshots/<filename>, with a caption underneath. */
function doc_screenshot(string $filename, string $caption): string
{
    return '<div class="doc-screenshot"><img src="' . APP_BASE_URL . '/assets/screenshots/' . e($filename) . '" alt="' . e($caption) . '" loading="lazy">'
        . '<div class="caption">' . e($caption) . '</div></div>';
}

function role_badge(string $role): string
{
    return '<span class="badge badge-role">' . e(role_label($role)) . '</span>';
}

/** Like role_badge(), but shows "Super Admin" distinctly for role=admin + is_super_admin=1. */
function user_role_badge(array $user): string
{
    if ($user['role'] === 'admin' && !empty($user['is_super_admin'])) {
        return '<span class="badge badge-role badge-super-admin">Super Admin</span>';
    }
    return role_badge($user['role']);
}

/**
 * Generates the next sequential reference code for the findings register,
 * e.g. USAASA-EVAL-2026-0001.
 */
function next_reference_code(PDO $pdo): string
{
    $year = date('Y');
    $prefix = "USAASA-EVAL-$year-";
    $stmt = $pdo->prepare(
        "SELECT reference_code FROM findings_register
         WHERE reference_code LIKE ?
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last) {
        $parts = explode('-', $last);
        $next = (int)end($parts) + 1;
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

/**
 * Generates the next sequential reference code for the risk register,
 * e.g. USAASA-RISK-2026-0001.
 */
function next_risk_reference_code(PDO $pdo): string
{
    $year = date('Y');
    $prefix = "USAASA-RISK-$year-";
    $stmt = $pdo->prepare(
        "SELECT reference_code FROM risks
         WHERE reference_code LIKE ?
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last) {
        $parts = explode('-', $last);
        $next = (int)end($parts) + 1;
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

/**
 * Standard 3x3 likelihood x impact risk matrix.
 */
function compute_risk_rating(string $likelihood, string $impact): string
{
    $matrix = [
        'Low'    => ['Low' => 'Low',    'Medium' => 'Low',      'High' => 'Medium'],
        'Medium' => ['Low' => 'Low',    'Medium' => 'Medium',   'High' => 'High'],
        'High'   => ['Low' => 'Medium', 'Medium' => 'High',     'High' => 'Critical'],
    ];
    return $matrix[$likelihood][$impact] ?? 'Medium';
}

function risk_rating_badge(string $rating): string
{
    $classes = [
        'Low' => 'badge badge-risk-low',
        'Medium' => 'badge badge-risk-medium',
        'High' => 'badge badge-risk-high',
        'Critical' => 'badge badge-risk-critical',
    ];
    $class = $classes[$rating] ?? 'badge';
    return '<span class="' . $class . '">' . e($rating) . '</span>';
}

/**
 * Generates the next sequential reference code for the indicator /
 * results framework register, e.g. USAASA-IND-2026-0001.
 */
function next_indicator_reference_code(PDO $pdo): string
{
    $year = date('Y');
    $prefix = "USAASA-IND-$year-";
    $stmt = $pdo->prepare(
        "SELECT reference_code FROM indicators
         WHERE reference_code LIKE ?
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last) {
        $parts = explode('-', $last);
        $next = (int)end($parts) + 1;
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

/**
 * Generates the next sequential reference code for installer allocations
 * (Planning & Dispatch), e.g. USAASA-ALLOC-2026-0001.
 */
function next_allocation_reference_code(PDO $pdo): string
{
    $year = date('Y');
    $prefix = "USAASA-ALLOC-$year-";
    $stmt = $pdo->prepare(
        "SELECT reference_code FROM installer_allocations
         WHERE reference_code LIKE ?
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last) {
        $parts = explode('-', $last);
        $next = (int)end($parts) + 1;
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

/** Simple target-ratio progress for an allocation — no baseline concept, unlike indicators. */
function compute_allocation_progress(int $target, ?float $completed): ?float
{
    if ($completed === null || $target <= 0) {
        return null;
    }
    return round(($completed / $target) * 100, 1);
}

/** Confirms a district/metro name is a real entry under the given province (Planning & Dispatch "region" field). */
function sa_validate_district(string $province, string $district): bool
{
    foreach (sa_districts_for_province($province) as $row) {
        if ($row['district'] === $district) {
            return true;
        }
    }
    return false;
}

function result_level_badge(string $level): string
{
    $classes = [
        'Impact' => 'badge badge-level-impact',
        'Outcome' => 'badge badge-level-outcome',
        'Output' => 'badge badge-level-output',
    ];
    $class = $classes[$level] ?? 'badge';
    return '<span class="' . $class . '">' . e($level) . '</span>';
}

/**
 * Percentage progress of an actual value towards the target, relative to
 * the baseline. Works whether the indicator is meant to increase or
 * decrease, because both the numerator and denominator flip sign
 * consistently with the intended direction of travel. Returns null when
 * baseline equals target (progress is undefined) and the actual hasn't
 * also reached that single value.
 */
function compute_indicator_progress(float $baseline, float $target, ?float $actual): ?float
{
    if ($actual === null) {
        return null;
    }
    $denominator = $target - $baseline;
    if ($denominator == 0.0) {
        return $actual == $target ? 100.0 : null;
    }
    return round((($actual - $baseline) / $denominator) * 100, 1);
}

function progress_bar(?float $percent): string
{
    if ($percent === null) {
        return '<span class="hint">No data yet</span>';
    }
    $clamped = max(0, min(100, $percent));
    if ($percent >= 100) {
        $class = 'bar-achieved';
    } elseif ($percent >= 50) {
        $class = 'bar-ontrack';
    } else {
        $class = 'bar-offtrack';
    }
    return '<div class="progress"><div class="progress-fill ' . $class . '" style="width:' . $clamped . '%"></div></div>'
        . '<span class="progress-label">' . number_format($percent, 1) . '%</span>';
}

function format_number($value): string
{
    if ($value === null) {
        return '—';
    }
    return rtrim(rtrim(number_format((float)$value, 4, '.', ','), '0'), '.') ?: '0';
}

/**
 * Generates the next sequential reference code for site completion
 * records, e.g. USAASA-SITE-2026-0001.
 */
function next_site_reference_code(PDO $pdo): string
{
    $year = date('Y');
    $prefix = "USAASA-SITE-$year-";
    $stmt = $pdo->prepare(
        "SELECT site_code FROM sites
         WHERE site_code LIKE ?
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last) {
        $parts = explode('-', $last);
        $next = (int)end($parts) + 1;
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function site_status_badge(string $status): string
{
    $classes = [
        'Submitted' => 'badge badge-site-submitted',
        'Verified' => 'badge badge-site-verified',
        'Approved' => 'badge badge-site-approved',
        'Rejected' => 'badge badge-site-rejected',
    ];
    $class = $classes[$status] ?? 'badge';
    $label = $status === 'Approved' ? 'Approved — ready for payment' : $status;
    return '<span class="' . $class . '">' . e($label) . '</span>';
}

/**
 * Validates and stores an uploaded POE file. Checks the upload error code,
 * size, extension AND the real on-disk content type (via finfo, not the
 * client-supplied MIME type) before moving it into storage with a random
 * filename — the original name is kept only as display metadata, never
 * used to build a filesystem path.
 *
 * Returns ['file_name', 'stored_filename', 'file_size', 'mime_type'] on
 * success, or null on failure (with $error set to a user-facing reason).
 */
function store_poe_upload(array $file, ?string &$error): ?array
{
    $allowedExtensions = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'txt' => 'text/plain'];

    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'Please choose a file to upload.';
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'The file upload failed. Please try again.';
        return null;
    }
    if ($file['size'] <= 0 || $file['size'] > POE_MAX_BYTES) {
        $error = 'The file must be larger than 0 bytes and no more than ' . (POE_MAX_BYTES / 1024 / 1024) . ' MB.';
        return null;
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        $error = 'Invalid upload.';
        return null;
    }

    $originalName = $file['name'];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!isset($allowedExtensions[$extension])) {
        $error = 'Only PDF, JPG, PNG or TXT files are allowed.';
        return null;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $expectedMime = $allowedExtensions[$extension];
    $mimeOk = $detectedMime === $expectedMime
        || ($extension === 'jpg' && $detectedMime === 'image/jpeg')
        || ($extension === 'txt' && in_array($detectedMime, ['text/plain', 'text/x-asm'], true));
    if (!$mimeOk) {
        $error = 'The file content does not match a ' . strtoupper($extension) . ' file.';
        return null;
    }

    if (!is_dir(POE_STORAGE_DIR) && !mkdir(POE_STORAGE_DIR, 0750, true) && !is_dir(POE_STORAGE_DIR)) {
        $error = 'Could not prepare storage for the upload.';
        return null;
    }

    $storedFilename = bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file($file['tmp_name'], POE_STORAGE_DIR . $storedFilename)) {
        $error = 'Could not save the uploaded file.';
        return null;
    }

    return [
        'file_name' => $originalName,
        'stored_filename' => $storedFilename,
        'file_size' => $file['size'],
        'mime_type' => $detectedMime,
    ];
}

/**
 * Validates and stores an uploaded site logo image the same way
 * store_poe_upload() handles POE files (error code, size, extension, AND
 * real on-disk content type via finfo), but into LOGO_STORAGE_DIR with a
 * smaller size cap and an image-only extension list.
 *
 * Returns the stored filename on success, or null on failure (with
 * $error set to a user-facing reason).
 */
function store_logo_upload(array $file, ?string &$error): ?string
{
    $allowedExtensions = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'];

    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'Please choose a logo file to upload.';
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'The file upload failed. Please try again.';
        return null;
    }
    if ($file['size'] <= 0 || $file['size'] > LOGO_MAX_BYTES) {
        $error = 'The logo must be larger than 0 bytes and no more than ' . (LOGO_MAX_BYTES / 1024 / 1024) . ' MB.';
        return null;
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        $error = 'Invalid upload.';
        return null;
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!isset($allowedExtensions[$extension])) {
        $error = 'Only PNG, JPG or GIF logos are allowed.';
        return null;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $mimeOk = $detectedMime === $allowedExtensions[$extension]
        || ($extension === 'jpg' && $detectedMime === 'image/jpeg');
    if (!$mimeOk) {
        $error = 'The file content does not match a ' . strtoupper($extension) . ' image.';
        return null;
    }

    if (!is_dir(LOGO_STORAGE_DIR) && !mkdir(LOGO_STORAGE_DIR, 0755, true) && !is_dir(LOGO_STORAGE_DIR)) {
        $error = 'Could not prepare storage for the upload.';
        return null;
    }

    $storedFilename = 'logo_' . bin2hex(random_bytes(8)) . '.' . $extension;
    if (!move_uploaded_file($file['tmp_name'], LOGO_STORAGE_DIR . $storedFilename)) {
        $error = 'Could not save the uploaded file.';
        return null;
    }

    return $storedFilename;
}

/**
 * Sitewide branding settings (site name, tagline, footer text, logo),
 * cached per-request so header.php/footer.php including it repeatedly
 * doesn't re-query. Falls back to APP_NAME if the settings row is
 * somehow missing (e.g. schema not yet migrated).
 */
function get_site_settings(PDO $pdo): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }
    $row = $pdo->query('SELECT * FROM site_settings WHERE id = 1')->fetch();
    if (!$row) {
        $row = ['site_name' => APP_NAME, 'tagline' => null, 'footer_text' => null, 'logo_filename' => null];
    }
    $row['logo_url'] = $row['logo_filename'] ? LOGO_PUBLIC_PATH . $row['logo_filename'] : null;
    // Stored encrypted (includes/secrets.php); callers always see the usable value.
    try {
        $row['smtp_password'] = decrypt_secret($row['smtp_password'] ?? null);
    } catch (Throwable $e) {
        $row['smtp_password'] = '';
    }
    $settings = $row;
    return $settings;
}

/**
 * Renders CMS page body text as limited, safe HTML: everything is
 * HTML-escaped first, THEN a small fixed set of formatting is layered on
 * top (headings, bold, italic, bullet lists, http(s) links) — the only
 * tags ever emitted are hardcoded here, never derived from the author's
 * input, so a page author can never inject arbitrary HTML or script.
 */
function render_cms_content(string $raw): string
{
    $html = e($raw);

    $html = preg_replace('/^### (.+)$/m', '<h4>$1</h4>', $html);
    $html = preg_replace('/^## (.+)$/m', '<h3>$1</h3>', $html);
    $html = preg_replace('/^# (.+)$/m', '<h2>$1</h2>', $html);

    $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
    $html = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $html);

    // [text](url) — url is restricted to http(s) and already HTML-escaped,
    // so it can't break out of the href attribute or use a javascript: scheme.
    $html = preg_replace('/\[(.+?)\]\((https?:\/\/[^\s)]+)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $html);

    // Group consecutive "- item" lines into a <ul>.
    $html = preg_replace_callback('/(?:^- .+$\n?)+/m', function ($m) {
        $items = preg_split('/\n/', trim($m[0]));
        $lis = array_map(function ($i) {
            return '<li>' . preg_replace('/^- /', '', $i) . '</li>';
        }, $items);
        return '<ul>' . implode('', $lis) . '</ul>' . "\n";
    }, $html);

    $blocks = preg_split('/\n{2,}/', trim($html));
    $blocks = array_map(function ($block) {
        if (preg_match('/^<(h\d|ul)/', trim($block))) {
            return $block;
        }
        return '<p>' . nl2br($block) . '</p>';
    }, $blocks);

    return implode("\n", $blocks);
}

/**
 * A point on a circle of radius $r centred at ($cx, $cy), at $angleDeg
 * measured clockwise from 12 o'clock (so 0° is straight up) — the
 * conventional starting point for pie charts.
 */
function polar_to_cartesian(float $cx, float $cy, float $r, float $angleDeg): array
{
    $rad = deg2rad($angleDeg - 90);
    return [$cx + $r * cos($rad), $cy + $r * sin($rad)];
}

/**
 * SVG path "d" string for one pie slice, from $startDeg to $endDeg
 * (clockwise from 12 o'clock). A $gapDeg inset on both sides of the slice
 * stands in for the surface-gap spacer between touching pie marks (an
 * actual 2px gap isn't meaningful on radii that shrink to a point at the
 * centre, so a small angular gap does the equivalent job).
 */
function pie_slice_path(float $cx, float $cy, float $r, float $startDeg, float $endDeg, float $gapDeg = 0.6): string
{
    $start = $startDeg + $gapDeg;
    $end = $endDeg - $gapDeg;
    if ($end <= $start) {
        $end = $start + 0.01;
    }
    [$x1, $y1] = polar_to_cartesian($cx, $cy, $r, $start);
    [$x2, $y2] = polar_to_cartesian($cx, $cy, $r, $end);
    $largeArc = ($end - $start) > 180 ? 1 : 0;
    return sprintf(
        'M %F %F L %F %F A %F %F 0 %d 1 %F %F Z',
        $cx, $cy, $x1, $y1, $r, $r, $largeArc, $x2, $y2
    );
}

/**
 * SVG path "d" string for a column/bar that grows up from a baseline:
 * rounded at the data-end (top), square where it meets the baseline —
 * the mark spec in the dataviz skill's marks-and-anatomy reference.
 */
function bar_path_rounded_top(float $x, float $y, float $width, float $height, float $radius = 4): string
{
    if ($height <= 0 || $width <= 0) {
        return '';
    }
    $r = min($radius, $width / 2, $height);
    if ($r <= 0.01) {
        return sprintf('M %F %F H %F V %F H %F Z', $x, $y, $x + $width, $y + $height, $x);
    }
    return sprintf(
        'M %F %F Q %F %F %F %F L %F %F Q %F %F %F %F L %F %F L %F %F Z',
        $x, $y + $r,
        $x, $y, $x + $r, $y,
        $x + $width - $r, $y,
        $x + $width, $y, $x + $width, $y + $r,
        $x + $width, $y + $height,
        $x, $y + $height
    );
}

/**
 * Sends one plain-text email to a single recipient. Kept for existing
 * callers; the SMTP client itself is smtp_send_message() in mailer.php.
 * Returns ['success' => bool, 'error' => ?string].
 */
function send_smtp_email(array $cfg, string $toEmail, string $toName, string $subject, string $body): array
{
    return smtp_send_message($cfg, [['email' => $toEmail, 'name' => $toName]], [], [], $cfg['smtp_reply_to'] ?? null, $subject, $body);
}

/**
 * Notifies the installer who submitted a site that its status changed,
 * and logs the attempt (sent or failed) to notification_log either way.
 * Never throws — a broken SMTP config must not block the verify/approve/
 * reject action that triggered it.
 */
function notify_installer_of_status_change(PDO $pdo, int $siteId, string $newStatus, ?string $reason = null): void
{
    try {
        notify_installer_of_status_change_impl($pdo, $siteId, $newStatus, $reason);
    } catch (Throwable $e) {
        // Best-effort only — a broken SMTP config or a migration that
        // hasn't run yet must never break the verify/approve/reject action
        // that triggered this notification.
    }
}

function notify_installer_of_status_change_impl(PDO $pdo, int $siteId, string $newStatus, ?string $reason): void
{
    $stmt = $pdo->prepare(
        "SELECT s.site_code, s.site_name, u.full_name AS installer_name, u.email AS installer_email
         FROM sites s
         JOIN users u ON u.id = s.submitted_by
         WHERE s.id = ?"
    );
    $stmt->execute([$siteId]);
    $site = $stmt->fetch();
    if (!$site) {
        return;
    }

    $body = "Hello {$site['installer_name']},\n\n"
        . "The status of your submitted site completion has changed.\n\n"
        . "Reference: {$site['site_code']}\n"
        . "Site: {$site['site_name']}\n"
        . "New status: $newStatus\n"
        . ($reason ? "Reason: $reason\n" : '')
        . "\nLog in to the USAASA MEL System to view the full record.\n";

    // Routing (on/off, CC, archive, test mode) and logging happen in send_notification().
    send_notification($pdo, 'site_status', [['email' => $site['installer_email'], 'name' => $site['installer_name']]],
        "[{$site['site_code']}] Status update: $newStatus", $body, $siteId);
}

/**
 * Generates the next sequential reference code for indigent applications,
 * e.g. USAASA-APP-2026-0001.
 */
function next_application_reference_code(PDO $pdo): string
{
    $year = date('Y');
    $prefix = "USAASA-APP-$year-";
    $stmt = $pdo->prepare(
        "SELECT reference_code FROM indigent_applications
         WHERE reference_code LIKE ?
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last) {
        $parts = explode('-', $last);
        $next = (int)end($parts) + 1;
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

/**
 * Validates a South African 13-digit ID number using the standard
 * Luhn-variant checksum (YYMMDD SSSS C A Z), and derives date of birth,
 * gender and citizenship from the digits — informational only, never
 * authoritative on their own.
 */
function validate_sa_id_number(string $raw): array
{
    $id = preg_replace('/\D/', '', $raw);
    if (strlen($id) !== 13) {
        return ['valid' => false, 'error' => 'ID number must be exactly 13 digits.', 'dob' => null, 'gender' => null, 'citizen' => null];
    }

    $digits = array_map('intval', str_split($id));

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
    $checkDigit = (10 - (($oddSum + $evenSum) % 10)) % 10;
    $valid = $checkDigit === $digits[12];

    $yy = substr($id, 0, 2);
    $mm = substr($id, 2, 2);
    $dd = substr($id, 4, 2);
    $genderDigits = (int)substr($id, 6, 4);
    $citizenshipDigit = $digits[10];
    $century = ((int)$yy <= (int)date('y')) ? '20' : '19';
    $dob = checkdate((int)$mm, (int)$dd, (int)($century . $yy)) ? "$century$yy-$mm-$dd" : null;

    return [
        'valid' => $valid,
        'error' => $valid ? null : 'Invalid ID number (checksum failed) — please re-enter or re-scan.',
        'dob' => $dob,
        'gender' => $genderDigits < 5000 ? 'Female' : 'Male',
        'citizen' => $citizenshipDigit === 0,
    ];
}

/**
 * Validates and stores an indigent application's household photo, same
 * validation discipline as the other upload helpers in this file (real
 * extension, size cap, AND real on-disk content type via finfo).
 */
function store_household_photo_upload(array $file, ?string &$error): ?string
{
    $allowedExtensions = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'];

    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null; // photo is optional
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'The photo upload failed. Please try again.';
        return null;
    }
    if ($file['size'] <= 0 || $file['size'] > INDIGENT_PHOTO_MAX_BYTES) {
        $error = 'The photo must be larger than 0 bytes and no more than ' . (INDIGENT_PHOTO_MAX_BYTES / 1024 / 1024) . ' MB.';
        return null;
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        $error = 'Invalid upload.';
        return null;
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!isset($allowedExtensions[$extension])) {
        $error = 'Only PNG, JPG or GIF photos are allowed.';
        return null;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $mimeOk = $detectedMime === $allowedExtensions[$extension] || ($extension === 'jpg' && $detectedMime === 'image/jpeg');
    if (!$mimeOk) {
        $error = 'The file content does not match a ' . strtoupper($extension) . ' image.';
        return null;
    }

    if (!is_dir(INDIGENT_STORAGE_DIR) && !mkdir(INDIGENT_STORAGE_DIR, 0750, true) && !is_dir(INDIGENT_STORAGE_DIR)) {
        $error = 'Could not prepare storage for the upload.';
        return null;
    }

    $storedFilename = 'photo_' . bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file($file['tmp_name'], INDIGENT_STORAGE_DIR . $storedFilename)) {
        $error = 'Could not save the uploaded photo.';
        return null;
    }

    return $storedFilename;
}

/**
 * Decodes a canvas signature pad's data: URL (always PNG, from
 * canvas.toDataURL('image/png')) and saves it to disk. Returns the stored
 * filename, or null if the data URL is empty/malformed (signature left
 * blank — caller decides whether that's acceptable).
 */
function store_signature_data_url(string $dataUrl, string $dir = INDIGENT_STORAGE_DIR): ?string
{
    if (strpos($dataUrl, 'data:image/png;base64,') !== 0) {
        return null;
    }
    $base64 = substr($dataUrl, strlen('data:image/png;base64,'));
    $bytes = base64_decode($base64, true);
    if ($bytes === false || strlen($bytes) < 100) {
        return null; // an essentially-blank canvas still encodes to a tiny PNG
    }

    $info = @getimagesizefromstring($bytes);
    if (!$info || $info[2] !== IMAGETYPE_PNG) {
        return null; // must genuinely be a PNG, not just claim to be one
    }

    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        return null;
    }

    $storedFilename = 'signature_' . bin2hex(random_bytes(16)) . '.png';
    if (file_put_contents($dir . $storedFilename, $bytes) === false) {
        return null;
    }
    return $storedFilename;
}

/**
 * Stores a proof image (JPG/PNG) attached to a Broadband Infrastructure
 * Acceptance Form — backup power or connection speed evidence. Returns the
 * stored filename, or null with $error left null when no file was chosen
 * (attaching is optional on each save), or null with $error set on failure.
 */
function store_acceptance_proof_upload(array $file, ?string &$error): ?string
{
    $error = null;
    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        $error = 'The image is too large (maximum ' . (ACCEPTANCE_PROOF_MAX_BYTES / 1024 / 1024) . ' MB).';
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        $error = 'The image upload failed. Please try again.';
        return null;
    }
    if ($file['size'] <= 0 || $file['size'] > ACCEPTANCE_PROOF_MAX_BYTES) {
        $error = 'The image must be larger than 0 bytes and no more than ' . (ACCEPTANCE_PROOF_MAX_BYTES / 1024 / 1024) . ' MB.';
        return null;
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!isset($allowed[$extension]) || $allowed[$extension] !== $detectedMime) {
        $error = 'Only JPG or PNG images are allowed as proof.';
        return null;
    }

    if (!is_dir(ACCEPTANCE_STORAGE_DIR) && !mkdir(ACCEPTANCE_STORAGE_DIR, 0750, true) && !is_dir(ACCEPTANCE_STORAGE_DIR)) {
        $error = 'Could not prepare storage for the upload.';
        return null;
    }

    $storedFilename = 'proof_' . bin2hex(random_bytes(16)) . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
    if (!move_uploaded_file($file['tmp_name'], ACCEPTANCE_STORAGE_DIR . $storedFilename)) {
        $error = 'Could not save the uploaded image.';
        return null;
    }
    return $storedFilename;
}

// ---------------------------------------------------------------------------
// Broadband rollout register + pre-assessment helpers (broadband/*).
// ---------------------------------------------------------------------------

function broadband_site_types(): array
{
    return ['School', 'Hotspot', 'Clinic', 'Library', 'Other'];
}

function rollout_statuses(): array
{
    return ['Awaiting Pre-assessment', 'Pre-assessed', 'Approved for Rollout', 'Rolled Out', 'Cancelled'];
}

function rollout_status_badge(string $status): string
{
    $classes = [
        'Awaiting Pre-assessment' => 'badge badge-open',
        'Pre-assessed'            => 'badge badge-progress',
        'Approved for Rollout'    => 'badge badge-level-impact',
        'Rolled Out'              => 'badge badge-closed',
        'Cancelled'               => 'badge badge-overdue',
    ];
    return '<span class="' . ($classes[$status] ?? 'badge') . '">' . e($status) . '</span>';
}

/** Deployment / connectivity state of a rollout site => [label, colour] (colours also used for map markers). */
function connectivity_statuses(): array
{
    return [
        'New'     => ['label' => 'New — not deployed', 'color' => '#d93025'],
        'Online'  => ['label' => 'Online — deployed',  'color' => '#1e8e3e'],
        'Offline' => ['label' => 'Offline',            'color' => '#f29900'],
    ];
}

/** Roles allowed to change a site's New / Online / Offline state. */
function can_change_connectivity(string $role): bool
{
    return in_array($role, ['admin', 'manager', 'planner', 'field_worker', 'back_office'], true);
}

function next_rollout_reference_code(PDO $pdo): string
{
    $prefix = 'USAASA-BB-' . date('Y') . '-';
    $stmt = $pdo->prepare('SELECT reference_code FROM broadband_rollout_sites WHERE reference_code LIKE ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $parts = $last ? explode('-', $last) : [];
    $next = $last ? (int)end($parts) + 1 : 1;
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

/** Lower-cases a municipality name and strips accents, punctuation and "District/Local/Metropolitan Municipality" wording, for loose matching. */
function sa_municipality_key(string $name): string
{
    $s = strtolower(trim($name));
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($ascii !== false && $ascii !== '') {
        $s = $ascii;
    }
    $s = preg_replace('/\b(metropolitan|metro|district|local|municipality|municipal|dm|lm)\b/', ' ', $s);
    return preg_replace('/[^a-z0-9]/', '', $s);
}

/**
 * Matches free-text district / local municipality names (as typed in an import
 * file) against sa_municipal_structure(). Returns the canonical province,
 * district and local names where they can be determined, plus notes about
 * anything that could not be matched (the raw text is kept in that case).
 */
function sa_match_municipality(string $district, string $local): array
{
    $dKey = $district !== '' ? sa_municipality_key($district) : '';
    $lKey = $local !== '' ? sa_municipality_key($local) : '';
    $out = ['province' => null, 'district' => $district !== '' ? $district : null, 'local' => $local !== '' ? $local : null, 'notes' => []];

    $districtHits = [];
    $localHits = [];
    foreach (sa_municipal_structure() as $province => $rows) {
        foreach ($rows as $row) {
            if ($dKey !== '' && sa_municipality_key($row['district']) === $dKey) {
                $districtHits[] = [$province, $row];
            }
            foreach ($row['locals'] as $lm) {
                if ($lKey !== '' && sa_municipality_key($lm) === $lKey) {
                    $localHits[] = [$province, $row['district'], $lm];
                }
            }
        }
    }

    if (count($districtHits) === 1) {
        [$province, $row] = $districtHits[0];
        $out['province'] = $province;
        $out['district'] = $row['district'];
        foreach ($row['locals'] as $lm) {
            if ($lKey !== '' && sa_municipality_key($lm) === $lKey) {
                $out['local'] = $lm;
                return $out;
            }
        }
        if ($lKey !== '') {
            $out['notes'][] = "local municipality \"$local\" is not in {$row['district']} — kept as typed";
        } elseif ($row['type'] === 'metro') {
            $out['local'] = $row['locals'][0]; // a metro is its own local municipality
        }
        return $out;
    }

    // District not recognised: fall back to the local municipality, if it is unambiguous.
    if (count($localHits) === 1) {
        [$out['province'], $out['district'], $out['local']] = $localHits[0];
        if ($dKey !== '') {
            $out['notes'][] = "district \"$district\" not recognised — taken from local municipality as {$out['district']}";
        }
        return $out;
    }
    if (count($localHits) > 1) {
        $out['notes'][] = "local municipality \"$local\" exists in more than one district — please check the district";
    } elseif ($dKey !== '' || $lKey !== '') {
        $out['notes'][] = 'district / local municipality not recognised — kept as typed, province left blank';
    }
    return $out;
}

/**
 * Parses a GPS cell into [lat, lng] decimal degrees. Accepts "-25.74, 28.19",
 * "S25.74 E28.19", "25.74S 28.19E" and degrees-minutes-seconds such as
 * 25°44'46"S 28°11'17"E. Returns null if two coordinates can't be read.
 */
function parse_gps_pair(string $raw): ?array
{
    $raw = trim(str_replace(['′', '″', '’', '”', 'º'], ["'", '"', "'", '"', '°'], $raw));
    if ($raw === '') {
        return null;
    }
    $raw = preg_replace('/(\d)\.\s+(\d)/', '$1.$2', $raw);          // "29. 73828" -> "29.73828"
    $raw = preg_replace('/(\d)\s*;\s*(\d{1,2}(?:\.\d+)?\s*")/', "$1'$2", $raw); // 24'19;45" -> 24'19'45"
    $raw = preg_replace('/(\d{1,3})\'(\d{2})(\d{2})"/', "$1'$2'$3\"", $raw);    // 29'4925" -> 29'49'25"
    // Spreadsheet-style DMS with apostrophes instead of a degree sign: 24'34'56" 30'19'21" (or a stray ' as the final mark).
    if (strpos($raw, '°') === false
        && preg_match_all('/([NSEW])?\s*(-?\d{1,3})\s*\'\s*(\d{1,2}(?:\.\d+)?)\s*\'\s*(\d{1,2}(?:\.\d+)?)\s*(?:"|\'\'|\')?\s*([NSEW])?/i', $raw, $m, PREG_SET_ORDER) === 2) {
        $values = [];
        foreach ($m as $g) {
            $val = abs((float)$g[2]) + (float)$g[3] / 60 + (float)$g[4] / 3600;
            $hemi = strtoupper(($g[1] ?? '') . ($g[5] ?? ''));
            $values[] = (strpos($g[2], '-') === 0 || strpbrk($hemi, 'SW') !== false) ? -$val : $val;
        }
        return $values;
    }
    // Hemisphere letters are either all leading ("S25 E28") or all trailing ("25S 28E") — decide which first.
    $leading = (bool)preg_match('/^[NSEW]/i', $raw);
    $num = '(-?\d+(?:\.\d+)?)';
    $values = [];
    if (strpos($raw, '°') !== false) {
        $dms = $num . '\s*°\s*(?:(\d+(?:\.\d+)?)\s*\'\s*)?(?:(\d+(?:\.\d+)?)\s*"\s*)?';
        $pattern = $leading ? '/([NSEW])\s*' . $dms . '/iu' : '/()' . $dms . '\s*([NSEW])?/iu';
        preg_match_all($pattern, $raw, $m, PREG_SET_ORDER);
        foreach ($m as $g) {
            $val = abs((float)$g[2]) + (float)($g[3] ?? 0) / 60 + (float)($g[4] ?? 0) / 3600;
            $hemi = strtoupper($g[1] . ($g[5] ?? ''));
            $values[] = (strpos($g[2], '-') === 0 || strpbrk($hemi, 'SW') !== false) ? -$val : $val;
        }
    } else {
        // Decimal degrees separated by comma, semicolon, slash or whitespace (decimal point must be ".").
        $pattern = $leading ? '/([NSEW])\s*' . $num . '/i' : '/()' . $num . '\s*([NSEW])?/i';
        preg_match_all($pattern, $raw, $m, PREG_SET_ORDER);
        foreach ($m as $g) {
            $val = (float)$g[2];
            $hemi = strtoupper($g[1] . ($g[3] ?? ''));
            $values[] = strpbrk($hemi, 'SW') !== false ? -abs($val) : $val;
        }
    }
    if (count($values) !== 2) {
        return null;
    }
    return [$values[0], $values[1]];
}

/** Filenames written by store_signature_data_url() / store_acceptance_proof_upload() — used before serving or deleting one. */
function is_broadband_upload_filename($filename): bool
{
    return is_string($filename) && (bool)preg_match('/^(signature|proof)_[0-9a-f]{32}\.(png|jpg)$/', $filename);
}

/** South African provinces — the one part of the address hierarchy stable enough to hardcode. */
/** The two USAASA programmes a project belongs to — see Projects' "programme" column. */
function project_programmes(): array
{
    return ['Broadband Connectivity', 'BDM'];
}

function sa_provinces(): array
{
    return [
        'Eastern Cape', 'Free State', 'Gauteng', 'KwaZulu-Natal', 'Limpopo',
        'Mpumalanga', 'Northern Cape', 'North West', 'Western Cape',
    ];
}

/** Districts (and metros) for a province, as listed in sa_municipal_structure(). */
function sa_districts_for_province(string $province): array
{
    return sa_municipal_structure()[$province] ?? [];
}

/** Local municipalities for a given province + district/metro combination. */
function sa_locals_for_district(string $province, string $district): array
{
    foreach (sa_districts_for_province($province) as $row) {
        if ($row['district'] === $district) {
            return $row['locals'];
        }
    }
    return [];
}

/** Confirms a province/district/local combination is a real entry in the hierarchy. */
function sa_validate_municipality(string $province, string $district, string $local): bool
{
    foreach (sa_districts_for_province($province) as $row) {
        if ($row['district'] === $district) {
            return in_array($local, $row['locals'], true);
        }
    }
    return false;
}

// ---------------------------------------------------------------------------
// Shared bulk-import engine — used by sites/import.php, indigent/import.php
// and planning/import.php. Every import run supports two modes:
//   'reject_duplicates' — a row matching an existing record is flagged and
//                          skipped, never silently merged.
//   'update'             — a row matching an existing record updates it in
//                          place instead of being rejected as a duplicate.
// and an optional date range that filters which rows get processed by a
// per-row date column (e.g. completed date) before dedup is even checked.
// Every run is logged to import_log for the admin-only Data Captured report.
// ---------------------------------------------------------------------------

function xlsx_col_to_index(string $ref): int
{
    $col = preg_replace('/\d/', '', $ref);
    $index = 0;
    foreach (str_split($col) as $char) {
        $index = $index * 26 + (ord($char) - ord('A') + 1);
    }
    return $index - 1;
}

/** Minimal single-sheet XLSX reader: ZipArchive + SimpleXML, no library. */
/**
 * Every worksheet in an .xlsx, in workbook order: [sheet name => rows]. Used by imports where a
 * workbook holds one list per sheet (e.g. a sheet per district).
 */
function parse_xlsx_all_sheets(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not open the uploaded file as an Excel workbook.');
    }
    $workbook = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $zip->close();
    $targets = [];
    if ($rels !== false) {
        foreach ((new SimpleXMLElement($rels))->Relationship as $rel) {
            $targets[(string)$rel['Id']] = ltrim(preg_replace('#^/?xl/#', '', (string)$rel['Target']), '/');
        }
    }
    $sheets = [];
    if ($workbook !== false) {
        $wb = new SimpleXMLElement($workbook);
        foreach ($wb->sheets->sheet as $s) {
            $rid = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            if (isset($targets[$rid])) {
                $sheets[(string)$s['name']] = parse_xlsx_first_sheet($path, 'xl/' . $targets[$rid]);
            }
        }
    }
    return $sheets ?: ['Sheet1' => parse_xlsx_first_sheet($path)];
}

function parse_xlsx_first_sheet(string $path, string $sheetPath = 'xl/worksheets/sheet1.xml'): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not open the uploaded file as an Excel workbook.');
    }

    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ssDoc = new SimpleXMLElement($ssXml);
        foreach ($ssDoc->si as $si) {
            if (isset($si->t)) {
                $sharedStrings[] = (string)$si->t;
            } else {
                $text = '';
                foreach ($si->r as $r) {
                    $text .= (string)$r->t;
                }
                $sharedStrings[] = $text;
            }
        }
    }

    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('Could not find a worksheet in the uploaded file.');
    }
    $sheetDoc = new SimpleXMLElement($sheetXml);

    $rows = [];
    foreach ($sheetDoc->sheetData->row as $rowEl) {
        $rowData = [];
        $maxCol = -1;
        foreach ($rowEl->c as $cEl) {
            $ref = (string)$cEl['r'];
            $colIndex = xlsx_col_to_index($ref);
            $type = (string)$cEl['t'];
            if ($type === 's') {
                $value = $sharedStrings[(int)(string)($cEl->v ?? '')] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string)($cEl->is->t ?? '');
            } else {
                $value = (string)($cEl->v ?? '');
            }
            $rowData[$colIndex] = $value;
            $maxCol = max($maxCol, $colIndex);
        }
        $sequential = [];
        for ($i = 0; $i <= $maxCol; $i++) {
            $sequential[] = $rowData[$i] ?? '';
        }
        $rows[] = $sequential;
    }
    return $rows;
}

function yn_to_bool(string $v): int
{
    return in_array(strtolower(trim($v)), ['yes', 'y', '1', 'true'], true) ? 1 : 0;
}

/**
 * Reads an uploaded CSV or .xlsx file into data rows (header row stripped).
 * Returns null and sets $error on failure.
 */
/** Reads the first <table> of an HTML-table ".xls" (as written by this app's Excel exports). Null if the file isn't HTML. */
function parse_html_table_xls(string $path): ?array
{
    $html = (string)file_get_contents($path);
    $html = preg_replace('/^\xEF\xBB\xBF/', '', $html);
    if (stripos(ltrim($html), '<') !== 0 || stripos($html, '<table') === false) {
        return null;
    }
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
    libxml_clear_errors();
    $table = $doc->getElementsByTagName('table')->item(0);
    if (!$table) {
        return null;
    }
    $rows = [];
    foreach ($table->getElementsByTagName('tr') as $tr) {
        $cells = [];
        foreach ($tr->childNodes as $cell) {
            if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                $cells[] = trim($cell->textContent);
            }
        }
        $rows[] = $cells;
    }
    return $rows;
}

/** Like parse_import_date(), but also understands Excel date serial numbers (e.g. 46022 = 2026-01-01). */
function parse_import_date_or_serial(string $raw): ?string
{
    $raw = trim($raw);
    if (preg_match('/^\d{5}(\.\d+)?$/', $raw) && (float)$raw > 20000 && (float)$raw < 80000) {
        return gmdate('Y-m-d', ((int)$raw - 25569) * 86400);
    }
    // Day-first dates (31/03/2027) as used in South Africa; strtotime would read 03/31 as month-first.
    if (preg_match('#^(\d{1,2})[/.](\d{1,2})[/.](\d{4})$#', $raw, $m)) {
        return checkdate((int)$m[2], (int)$m[1], (int)$m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
    }
    // ISO dates must be real calendar dates (strtotime would roll 2027-02-30 over to 2 March).
    if (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})( .*)?$#', $raw, $m)) {
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
    }
    return parse_import_date($raw);
}

function read_import_rows(array $file, ?string &$error, bool $keepHeader = false): ?array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = 'Please choose a CSV or Excel (.xlsx) file to import.';
        return null;
    }
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    try {
        if ($extension === 'csv') {
            $handle = fopen($file['tmp_name'], 'r');
            $rows = [];
            $header = fgetcsv($handle); // header row, discarded unless the caller maps columns by name
            if ($keepHeader && $header !== false) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]); // Excel's UTF-8 BOM
                $rows[] = $header;
            }
            while (($row = fgetcsv($handle)) !== false) {
                $rows[] = $row;
            }
            fclose($handle);
            return $rows;
        }
        if ($extension === 'xlsx') {
            $allRows = parse_xlsx_first_sheet($file['tmp_name']);
            return $keepHeader ? $allRows : array_slice($allRows, 1); // drop header row
        }
        if ($extension === 'xls') {
            // This app's own "Export Excel" files are HTML tables saved as .xls — those can be read back.
            $allRows = parse_html_table_xls($file['tmp_name']);
            if ($allRows === null) {
                $error = 'That .xls file is in the old binary Excel format, which can\'t be read — open it in Excel and use Save As → Excel Workbook (.xlsx), then import the .xlsx.';
                return null;
            }
            return $keepHeader ? $allRows : array_slice($allRows, 1);
        }
        $error = 'Only .csv, .xlsx (or this system\'s own exported .xls) files can be imported.';
        return null;
    } catch (Throwable $e) {
        $error = 'Could not read that file: ' . $e->getMessage();
        return null;
    }
}

/**
 * Parses a date cell from an import file into Y-m-d, or null if blank/unparseable.
 * Used both to apply the optional import date-range filter and to read a
 * row's own date for display.
 */
function parse_import_date(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d', $ts) : null;
}

/** True if $rowDate (Y-m-d or null) falls within the optional [$from, $to] range (either/both may be null = unbounded). */
function import_date_in_range(?string $rowDate, ?string $from, ?string $to): bool
{
    if ($from === null && $to === null) {
        return true;
    }
    if ($rowDate === null) {
        return false; // a range was requested but this row has no usable date — exclude rather than guess
    }
    if ($from !== null && $rowDate < $from) {
        return false;
    }
    if ($to !== null && $rowDate > $to) {
        return false;
    }
    return true;
}

/**
 * Logs one import run to import_log for the admin-only Data Captured report.
 * $rowDetails is an array of ['row' => int, 'outcome' => string, 'reason' => string].
 */
function log_import_run(
    PDO $pdo, string $module, string $mode, string $fileName,
    ?string $periodStart, ?string $periodEnd, int $totalRows,
    int $created, int $updated, int $duplicates, int $invalid,
    array $rowDetails, int $importedBy
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO import_log (
            module, mode, file_name, period_start, period_end, total_rows,
            created_count, updated_count, duplicate_count, invalid_count, details, imported_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $module, $mode, $fileName, $periodStart, $periodEnd, $totalRows,
        $created, $updated, $duplicates, $invalid, json_encode($rowDetails), $importedBy,
    ]);
    return (int)$pdo->lastInsertId();
}
