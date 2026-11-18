<?php
/**
 * Digital Transformation programme — shared helpers for the deliverable
 * registers, governance bodies and sign-off routing (digital/*.php).
 *
 * Routing model: each deliverable has an ordered route of governance bodies
 * (dt_workflow_steps). Its current document version is signed step by step;
 * the pending step is the first body on the route that has not yet endorsed
 * that version. Only users assigned to that body (dt_governance_members) can
 * sign it. A rejection stops the route until a new version is attached; a
 * new version always restarts the route from step 1.
 */

/** Register sections, keyed by dt_deliverables.section. */
function dt_section_labels(): array
{
    return [
        'blueprint' => 'ERP Blueprint Deliverables Checklist',
        'data_migration' => 'Data Migration Blueprint',
        'general' => 'Deliverables',
    ];
}

/** Which sections a workstream's register is split into. */
function dt_project_sections(string $slug): array
{
    return $slug === 'erp' ? ['blueprint', 'data_migration'] : ['general'];
}

function dt_status_badge(string $status): string
{
    $cls = [
        'Not Started' => 'badge badge-role',
        'Awaiting Sign-off' => 'badge badge-progress',
        'Rejected' => 'badge badge-overdue',
        'Signed Off' => 'badge badge-closed',
    ][$status] ?? 'badge';
    return '<span class="' . $cls . '">' . e($status) . '</span>';
}

function dt_get_project(PDO $pdo, string $slug): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM dt_projects WHERE slug = ?');
    $stmt->execute([$slug]);
    return $stmt->fetch() ?: null;
}

/** id => row for every governance body (active first, in display order). */
function dt_bodies(PDO $pdo, bool $activeOnly = false): array
{
    $rows = $pdo->query('SELECT * FROM dt_governance_bodies' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name')->fetchAll();
    return array_column($rows, null, 'id');
}

/** body_id => true for every governance body the user signs for. */
function dt_user_body_ids(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT body_id FROM dt_governance_members WHERE user_id = ?');
    $stmt->execute([$userId]);
    return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
}

/** Admins and managers maintain the registers; any assigned signatory may also attach evidence. */
function dt_can_upload(PDO $pdo, array $user): bool
{
    return in_array($user['role'], ['admin', 'manager'], true) || (bool)dt_user_body_ids($pdo, (int)$user['id']);
}

function dt_can_manage_register(array $user): bool
{
    return in_array($user['role'], ['admin', 'manager'], true);
}

/**
 * The route for many deliverables at once: deliverable_id => list of
 * ['step_order', 'body_id', 'name', 'short_name', 'state', 'signoff'] where
 * state is done | rejected | pending | waiting, judged against each
 * deliverable's CURRENT document version.
 */
function dt_routes(PDO $pdo, array $deliverables): array
{
    if (!$deliverables) {
        return [];
    }
    $ids = array_map(fn($d) => (int)$d['id'], $deliverables);
    $in = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare(
        "SELECT w.deliverable_id, w.step_order, w.body_id, b.name, b.short_name
         FROM dt_workflow_steps w JOIN dt_governance_bodies b ON b.id = w.body_id
         WHERE w.deliverable_id IN ($in) ORDER BY w.deliverable_id, w.step_order"
    );
    $stmt->execute($ids);
    $steps = [];
    foreach ($stmt->fetchAll() as $s) {
        $steps[(int)$s['deliverable_id']][] = $s;
    }

    $docIds = array_values(array_filter(array_map(fn($d) => (int)($d['current_document_id'] ?? 0), $deliverables)));
    $signed = [];
    if ($docIds) {
        $inDocs = implode(',', array_fill(0, count($docIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM dt_signoffs WHERE document_id IN ($inDocs)");
        $stmt->execute($docIds);
        foreach ($stmt->fetchAll() as $so) {
            $signed[(int)$so['document_id']][(int)$so['body_id']] = $so;
        }
    }

    $routes = [];
    foreach ($deliverables as $d) {
        $id = (int)$d['id'];
        $docSigned = $signed[(int)($d['current_document_id'] ?? 0)] ?? [];
        $pendingSeen = false;
        $route = [];
        foreach ($steps[$id] ?? [] as $s) {
            $so = $docSigned[(int)$s['body_id']] ?? null;
            if ($so && $so['decision'] === 'Endorsed') {
                $state = 'done';
            } elseif ($so && $so['decision'] === 'Rejected') {
                $state = 'rejected';
                $pendingSeen = true;
            } elseif (!$pendingSeen && !empty($d['current_document_id']) && $d['status'] !== 'Rejected') {
                $state = 'pending';
                $pendingSeen = true;
            } else {
                $state = 'waiting';
            }
            $route[] = $s + ['state' => $state, 'signoff' => $so];
        }
        $routes[$id] = $route;
    }
    return $routes;
}

/** Compact route chips: ✓ done, ● pending, ✕ rejected, plain = still to come. */
function dt_route_chips(array $route): string
{
    if (!$route) {
        return '<span class="dt-route-empty">No sign-off route</span>';
    }
    $marks = ['done' => '&#10003; ', 'pending' => '&#9679; ', 'rejected' => '&#10005; ', 'waiting' => ''];
    $titles = ['done' => 'endorsed', 'pending' => 'awaiting signature', 'rejected' => 'rejected', 'waiting' => 'still to sign'];
    $out = '<span class="dt-route">';
    foreach ($route as $i => $s) {
        if ($i) {
            $out .= '<span class="dt-route-arrow" aria-hidden="true">&rsaquo;</span>';
        }
        $out .= '<span class="dt-chip is-' . $s['state'] . '" title="Step ' . (int)$s['step_order'] . ': ' . e($s['name']) . ' — ' . $titles[$s['state']] . '">'
            . $marks[$s['state']] . e($s['short_name']) . '</span>';
    }
    return $out . '</span>';
}

/**
 * Recomputes a deliverable's derived status / pending_body_id /
 * signed_off_at from its current document, route and sign-offs. Call after
 * every upload, sign-off and route change. Notifies the newly pending
 * body's signatories by email when the pending step moves (best-effort).
 */
function dt_refresh_status(PDO $pdo, int $deliverableId, bool $notify = true): void
{
    $stmt = $pdo->prepare('SELECT * FROM dt_deliverables WHERE id = ?');
    $stmt->execute([$deliverableId]);
    $d = $stmt->fetch();
    if (!$d) {
        return;
    }

    $pending = null;
    $signedOffAt = null;
    if (!$d['current_document_id']) {
        $status = 'Not Started';
    } else {
        $stmt = $pdo->prepare('SELECT body_id, decision, signed_at FROM dt_signoffs WHERE document_id = ?');
        $stmt->execute([$d['current_document_id']]);
        $decisions = [];
        $rejected = false;
        foreach ($stmt->fetchAll() as $so) {
            $decisions[(int)$so['body_id']] = $so;
            if ($so['decision'] === 'Rejected') {
                $rejected = true;
            }
        }
        $stmt = $pdo->prepare('SELECT body_id FROM dt_workflow_steps WHERE deliverable_id = ? ORDER BY step_order');
        $stmt->execute([$deliverableId]);
        $route = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        if ($rejected) {
            $status = 'Rejected';
        } elseif (!$route) {
            $status = 'Awaiting Sign-off'; // unrouted — a super admin must set the route
        } else {
            foreach ($route as $bodyId) {
                if (($decisions[$bodyId]['decision'] ?? null) !== 'Endorsed') {
                    $pending = $bodyId;
                    break;
                }
            }
            if ($pending === null) {
                $status = 'Signed Off';
                $signedOffAt = max(array_map(fn($b) => $decisions[$b]['signed_at'], $route));
            } else {
                $status = 'Awaiting Sign-off';
            }
        }
    }

    $pdo->prepare('UPDATE dt_deliverables SET status = ?, pending_body_id = ?, signed_off_at = ? WHERE id = ?')
        ->execute([$status, $pending, $signedOffAt, $deliverableId]);

    if ($notify && $pending !== null && $pending !== (int)$d['pending_body_id']) {
        dt_notify_signatories($pdo, $deliverableId, $pending);
    }
}

/** Emails every signatory of $bodyId that a deliverable is waiting for them. Never throws. */
function dt_notify_signatories(PDO $pdo, int $deliverableId, int $bodyId): void
{
    try {
        $stmt = $pdo->prepare(
            'SELECT d.code, d.title, p.name AS project, b.name AS body
             FROM dt_deliverables d JOIN dt_projects p ON p.id = d.project_id JOIN dt_governance_bodies b ON b.id = ?
             WHERE d.id = ?'
        );
        $stmt->execute([$bodyId, $deliverableId]);
        $info = $stmt->fetch();
        if (!$info) {
            return;
        }
        $stmt = $pdo->prepare(
            'SELECT u.full_name, u.email FROM dt_governance_members m JOIN users u ON u.id = m.user_id
             WHERE m.body_id = ? AND u.is_active = 1'
        );
        $stmt->execute([$bodyId]);
        $recipients = array_map(fn($m) => ['email' => $m['email'], 'name' => $m['full_name']], $stmt->fetchAll());
        if (!$recipients) {
            return;
        }
        $body = "Hello,\n\n"
            . "A Digital Transformation deliverable has been routed to {$info['body']} for sign-off.\n\n"
            . "Workstream: {$info['project']}\n"
            . "Deliverable: {$info['code']} — {$info['title']}\n\n"
            . "Log in to the USAASA MEL System and open My Sign-offs to review the document and endorse or reject it.\n";
        send_notification($pdo, 'dt_signoff_required', $recipients, "[{$info['code']}] Sign-off required: {$info['title']}", $body);
    } catch (Throwable $e) {
        // Best-effort only — a mail problem must never block the sign-off that triggered it.
    }
}

/**
 * Tells whoever attached the current version, plus the owner body's
 * signatories, that the deliverable was rejected or fully signed off.
 * $event is dt_rejected or dt_signed_off. Never throws.
 */
function dt_notify_outcome(PDO $pdo, int $deliverableId, string $event, ?string $comments = null): void
{
    try {
        $stmt = $pdo->prepare(
            'SELECT d.code, d.title, d.owner_body_id, p.name AS project, doc.version_no, u.email, u.full_name
             FROM dt_deliverables d JOIN dt_projects p ON p.id = d.project_id
             JOIN dt_documents doc ON doc.id = d.current_document_id JOIN users u ON u.id = doc.uploaded_by
             WHERE d.id = ?'
        );
        $stmt->execute([$deliverableId]);
        $info = $stmt->fetch();
        if (!$info) {
            return;
        }
        $recipients = [['email' => $info['email'], 'name' => $info['full_name']]];
        if ($info['owner_body_id']) {
            $stmt = $pdo->prepare('SELECT u.email, u.full_name FROM dt_governance_members m JOIN users u ON u.id = m.user_id WHERE m.body_id = ? AND u.is_active = 1');
            $stmt->execute([$info['owner_body_id']]);
            foreach ($stmt->fetchAll() as $m) {
                $recipients[] = ['email' => $m['email'], 'name' => $m['full_name']];
            }
        }
        $rejected = $event === 'dt_rejected';
        $body = "Hello,\n\n"
            . "Version {$info['version_no']} of a Digital Transformation deliverable has been "
            . ($rejected ? 'REJECTED and needs a corrected version.' : 'signed off by every governance body on its route.') . "\n\n"
            . "Workstream: {$info['project']}\n"
            . "Deliverable: {$info['code']} — {$info['title']}\n"
            . ($rejected && $comments ? "Reason: $comments\n" : '')
            . "\nLog in to the USAASA MEL System to view the sign-off record.\n";
        send_notification($pdo, $event, $recipients, "[{$info['code']}] " . ($rejected ? 'Rejected' : 'Signed off') . ": {$info['title']}", $body);
    } catch (Throwable $e) {
        // best-effort only
    }
}

/** Number of deliverables currently waiting on a body this user signs for (sidebar badge). */
function dt_pending_count_for_user(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM dt_deliverables d
         JOIN dt_governance_members m ON m.body_id = d.pending_body_id AND m.user_id = ?
         WHERE d.status = 'Awaiting Sign-off'"
    );
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

/** Extension => [content type served, signature family] for deliverable documents. */
function dt_allowed_document_types(): array
{
    return [
        'pdf'  => ['application/pdf', 'pdf'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'ooxml'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'ooxml'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'ooxml'],
        'vsdx' => ['application/vnd.ms-visio.drawing.main+xml', 'ooxml'],
        'doc'  => ['application/msword', 'ole'],
        'xls'  => ['application/vnd.ms-excel', 'ole'],
        'ppt'  => ['application/vnd.ms-powerpoint', 'ole'],
        'mpp'  => ['application/vnd.ms-project', 'ole'],
        'png'  => ['image/png', 'png'],
        'jpg'  => ['image/jpeg', 'jpeg'],
        'jpeg' => ['image/jpeg', 'jpeg'],
    ];
}

/**
 * Validates and stores an uploaded deliverable document. Checks the upload
 * error, size, extension AND the file's real leading bytes (not the
 * client-supplied type) before moving it into DT_STORAGE_DIR under a random
 * name. Returns the dt_documents column values, or null with $error set.
 */
function dt_store_document_upload(array $file, ?string &$error): ?array
{
    $types = dt_allowed_document_types();
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'Please choose a document to attach.';
        return null;
    }
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        $error = 'The document is larger than the server allows (' . (DT_DOC_MAX_BYTES / 1024 / 1024) . ' MB maximum).';
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        $error = 'The upload failed. Please try again.';
        return null;
    }
    if ($file['size'] <= 0 || $file['size'] > DT_DOC_MAX_BYTES) {
        $error = 'The document must be larger than 0 bytes and no more than ' . (DT_DOC_MAX_BYTES / 1024 / 1024) . ' MB.';
        return null;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!isset($types[$ext])) {
        $error = 'Allowed formats: PDF, Word, Excel, PowerPoint, Visio, MS Project, PNG or JPG.';
        return null;
    }

    $head = (string)file_get_contents($file['tmp_name'], false, null, 0, 8);
    $magic = [
        'pdf' => '%PDF', 'ooxml' => "PK\x03\x04", 'ole' => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1",
        'png' => "\x89PNG", 'jpeg' => "\xFF\xD8\xFF",
    ][$types[$ext][1]];
    if (strncmp($head, $magic, strlen($magic)) !== 0) {
        $error = 'The file content does not match a .' . $ext . ' document.';
        return null;
    }

    if (!is_dir(DT_STORAGE_DIR) && !mkdir(DT_STORAGE_DIR, 0750, true) && !is_dir(DT_STORAGE_DIR)) {
        $error = 'Could not prepare storage for the document.';
        return null;
    }
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], DT_STORAGE_DIR . $stored)) {
        $error = 'Could not save the uploaded document.';
        return null;
    }
    return [
        'file_name' => mb_substr(basename($file['name']), 0, 255),
        'stored_filename' => $stored,
        'file_size' => (int)$file['size'],
        'mime_type' => $types[$ext][0],
        'sha256' => hash_file('sha256', DT_STORAGE_DIR . $stored),
    ];
}

function dt_format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    return number_format(max(1, $bytes / 1024)) . ' KB';
}

/** Signed-off share of a set of deliverables, as a whole percentage (null when empty). */
function dt_percent(int $done, int $total): ?float
{
    return $total > 0 ? round($done / $total * 100) : null;
}
