<?php
/**
 * One Digital Transformation deliverable. ?id=<deliverable id>
 *   POST action=upload  — attach a new document version (POE); restarts the sign-off route.
 *   POST action=signoff — endorse / reject the current version on behalf of the
 *                         governance body at the pending step of the route.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();
$user = current_user();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$load = function () use ($pdo, $id) {
    $stmt = $pdo->prepare('SELECT d.*, p.slug AS project_slug, p.name AS project_name FROM dt_deliverables d JOIN dt_projects p ON p.id = d.project_id WHERE d.id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
};
$d = $load();
if (!$d) {
    flash('error', 'Deliverable not found.');
    redirect('/digital/index.php');
}
$selfUrl = '/digital/deliverable.php?id=' . $id;
$myBodies = dt_user_body_ids($pdo, (int)$user['id']);
$canUpload = dt_can_upload($pdo, $user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        if (!$canUpload) {
            http_response_code(403);
            die('You do not have permission to attach documents.');
        }
        $error = null;
        $stored = dt_store_document_upload($_FILES['document'] ?? [], $error);
        if (!$stored) {
            flash('error', $error);
            redirect($selfUrl . '#attach');
        }
        $notes = trim((string)($_POST['notes'] ?? ''));
        try {
            $pdo->beginTransaction();
            $pdo->prepare('SELECT id FROM dt_deliverables WHERE id = ? FOR UPDATE')->execute([$id]);
            $stmt = $pdo->prepare('SELECT COALESCE(MAX(version_no), 0) + 1 FROM dt_documents WHERE deliverable_id = ?');
            $stmt->execute([$id]);
            $version = (int)$stmt->fetchColumn();
            $pdo->prepare('INSERT INTO dt_documents (deliverable_id, version_no, file_name, stored_filename, file_size, mime_type, sha256, notes, uploaded_by)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, $version, $stored['file_name'], $stored['stored_filename'], $stored['file_size'], $stored['mime_type'],
                           $stored['sha256'], $notes === '' ? null : mb_substr($notes, 0, 500), $user['id']]);
            $pdo->prepare('UPDATE dt_deliverables SET current_document_id = ? WHERE id = ?')->execute([(int)$pdo->lastInsertId(), $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            @unlink(DT_STORAGE_DIR . $stored['stored_filename']);
            flash('error', 'The document could not be saved. Please try again.');
            redirect($selfUrl . '#attach');
        }
        dt_refresh_status($pdo, $id);
        flash('success', "Version $version attached and routed for sign-off.");
        redirect($selfUrl);
    }

    if ($action === 'signoff') {
        $decision = $_POST['decision'] ?? '';
        $signerName = trim((string)($_POST['signer_name'] ?? ''));
        $designation = trim((string)($_POST['signer_designation'] ?? ''));
        $comments = trim((string)($_POST['comments'] ?? ''));
        $sigData = (string)($_POST['signer_signature_data'] ?? '');

        $errors = [];
        if (!in_array($decision, ['Endorsed', 'Rejected'], true)) {
            $errors[] = 'Choose Endorse or Reject.';
        }
        if ($signerName === '') {
            $errors[] = 'Enter your full name as signatory.';
        }
        if (empty($_POST['declaration'])) {
            $errors[] = 'Tick the declaration to confirm you have reviewed the document.';
        }
        if ($decision === 'Rejected' && $comments === '') {
            $errors[] = 'Give the reason for rejecting the document.';
        }
        if ($decision === 'Endorsed' && $sigData === '') {
            $errors[] = 'Draw your signature to endorse the document.';
        }
        if (strlen($sigData) > 600000) {
            $errors[] = 'The signature image is too large — clear it and sign again.';
        }
        if ($errors) {
            flash('error', implode(' ', $errors));
            redirect($selfUrl . '#signoff');
        }

        $sigFile = null;
        if ($sigData !== '') {
            $sigFile = store_signature_data_url($sigData, DT_STORAGE_DIR);
            if ($sigFile === null) {
                flash('error', 'The signature could not be read — clear it and sign again.');
                redirect($selfUrl . '#signoff');
            }
        }

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT current_document_id, pending_body_id, status FROM dt_deliverables WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $now = $stmt->fetch();
            $pendingBody = (int)$now['pending_body_id'];
            if ($now['status'] !== 'Awaiting Sign-off' || !$pendingBody || (int)$now['current_document_id'] !== (int)($_POST['document_id'] ?? 0)) {
                throw new RuntimeException('This document is no longer awaiting that sign-off — a newer version may have been attached, or someone else has already signed. Review the latest state below.');
            }
            if (!isset($myBodies[$pendingBody])) {
                throw new RuntimeException('You are not an assigned signatory for the governance body at the current step.');
            }
            $stmt = $pdo->prepare('SELECT w.step_order, b.name FROM dt_workflow_steps w JOIN dt_governance_bodies b ON b.id = w.body_id WHERE w.deliverable_id = ? AND w.body_id = ?');
            $stmt->execute([$id, $pendingBody]);
            $step = $stmt->fetch();
            $pdo->prepare('INSERT INTO dt_signoffs (deliverable_id, document_id, body_id, body_name, step_order, decision, comments, signed_by, signer_name, signer_designation, signature_file, ip_address)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, (int)$now['current_document_id'], $pendingBody, $step['name'], (int)$step['step_order'], $decision,
                           $comments === '' ? null : $comments, $user['id'], mb_substr($signerName, 0, 150),
                           $designation === '' ? null : mb_substr($designation, 0, 150), $sigFile, $_SERVER['REMOTE_ADDR'] ?? null]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($sigFile) {
                @unlink(DT_STORAGE_DIR . $sigFile);
            }
            flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'The sign-off could not be saved. Please try again.');
            redirect($selfUrl);
        }
        dt_refresh_status($pdo, $id);
        $d = $load();
        if ($decision === 'Rejected' || $d['status'] === 'Signed Off') {
            dt_notify_outcome($pdo, $id, $decision === 'Rejected' ? 'dt_rejected' : 'dt_signed_off', $comments);
        }
        flash('success', $decision === 'Rejected'
            ? 'Document rejected. It returns to the owner for a corrected version.'
            : ($d['status'] === 'Signed Off' ? 'Endorsed — every governance body has now signed off this deliverable.' : 'Endorsed — routed to the next signatory.'));
        redirect($selfUrl);
    }
}

// ---- view ----
$bodies = dt_bodies($pdo);
$route = dt_routes($pdo, [$d])[$id] ?? [];

$members = [];
foreach ($pdo->query('SELECT m.body_id, m.designation, u.full_name FROM dt_governance_members m JOIN users u ON u.id = m.user_id WHERE u.is_active = 1 ORDER BY u.full_name')->fetchAll() as $m) {
    $members[(int)$m['body_id']][] = $m;
}
$stmt = $pdo->prepare('SELECT designation FROM dt_governance_members WHERE user_id = ? AND body_id = ?');
$stmt->execute([$user['id'], (int)$d['pending_body_id']]);
$myDesignation = (string)($stmt->fetchColumn() ?: '');

$stmt = $pdo->prepare('SELECT doc.*, u.full_name AS uploader FROM dt_documents doc JOIN users u ON u.id = doc.uploaded_by WHERE doc.deliverable_id = ? ORDER BY doc.version_no DESC');
$stmt->execute([$id]);
$docs = $stmt->fetchAll();
$current = null;
foreach ($docs as $doc) {
    if ((int)$doc['id'] === (int)$d['current_document_id']) {
        $current = $doc;
    }
}
$stmt = $pdo->prepare('SELECT s.*, doc.version_no FROM dt_signoffs s JOIN dt_documents doc ON doc.id = s.document_id WHERE s.deliverable_id = ? ORDER BY s.signed_at DESC, s.id DESC');
$stmt->execute([$id]);
$signoffs = $stmt->fetchAll();
$byDoc = [];
foreach ($signoffs as $so) {
    $byDoc[(int)$so['document_id']][] = $so;
}

$pendingName = $d['pending_body_id'] && isset($bodies[$d['pending_body_id']]) ? $bodies[$d['pending_body_id']]['name'] : null;
$canSign = $d['status'] === 'Awaiting Sign-off' && $d['pending_body_id'] && isset($myBodies[(int)$d['pending_body_id']]);
$labels = dt_section_labels();
$sectionLabel = $d['project_slug'] === 'erp' ? ($labels[$d['section']] ?? $d['section']) : $d['project_name'];
$backHref = '/digital/project.php?p=' . urlencode($d['project_slug']) . ($d['project_slug'] === 'erp' ? '&amp;section=' . urlencode($d['section']) : '');
$navActiveHref = $backHref; // highlights this register's link in the sidebar
$inlineable = $current && in_array($current['mime_type'], ['application/pdf', 'image/png', 'image/jpeg'], true);

$pageTitle = $d['code'] . ' ' . $d['title'];
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1><?= e($d['title']) ?></h1>
        <p class="subtitle"><?= e($d['code']) ?> &middot; <?= e($d['project_name']) ?><?= $d['project_slug'] === 'erp' ? ' &rsaquo; ' . e($sectionLabel) : '' ?></p>
    </div>
    <div class="actions-row" style="margin:0;">
        <?php if (is_super_admin()): ?><a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/digital/workflow_edit.php?id=<?= $id ?>">Edit route</a><?php endif; ?>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL . $backHref ?>">Back to register</a>
    </div>
</div>

<?php if ($d['status'] === 'Awaiting Sign-off' && !$route): ?>
    <div class="alert alert-warning">No sign-off route is configured for this deliverable, so it cannot be signed off yet. <?= is_super_admin() ? 'Set the route with <strong>Edit route</strong>.' : 'Ask a super admin to set its approval workflow.' ?></div>
<?php elseif ($d['status'] === 'Rejected'): ?>
    <div class="alert alert-error">The current version was rejected. Attach a corrected version to restart the sign-off route.</div>
<?php elseif ($d['status'] === 'Signed Off'): ?>
    <div class="alert alert-success">Signed off by every governance body on the route &mdash; completed <?= format_datetime($d['signed_off_at']) ?>.</div>
<?php endif; ?>

<div class="dt-two-col">
    <div>
        <div class="card">
            <dl class="detail-grid">
                <dt>Status</dt><dd><?= dt_status_badge($d['status']) ?><?= $pendingName && $d['status'] === 'Awaiting Sign-off' ? ' <span class="hint">with ' . e($pendingName) . '</span>' : '' ?></dd>
                <dt>Accountable owner</dt><dd><?= $d['owner_body_id'] && isset($bodies[$d['owner_body_id']]) ? e($bodies[$d['owner_body_id']]['name']) : '<span class="hint">Not set</span>' ?></dd>
                <?php if ($d['description']): ?><dt>Scope</dt><dd><?= nl2br(e($d['description'])) ?></dd><?php endif; ?>
                <dt>Current document</dt>
                <dd>
                    <?php if ($current): ?>
                        <strong>Version <?= (int)$current['version_no'] ?></strong> &mdash; <?= e($current['file_name']) ?> (<?= dt_format_bytes((int)$current['file_size']) ?>)
                        <div class="actions-row" style="margin-top:6px;">
                            <a class="btn btn-secondary btn-sm" href="<?= APP_BASE_URL ?>/digital/download.php?id=<?= (int)$current['id'] ?>">Download</a>
                            <?php if ($inlineable): ?><a class="btn btn-secondary btn-sm" target="_blank" rel="noopener" href="<?= APP_BASE_URL ?>/digital/download.php?id=<?= (int)$current['id'] ?>&amp;inline=1">Open in browser</a><?php endif; ?>
                        </div>
                        <div class="hint">Attached by <?= e($current['uploader']) ?> on <?= format_datetime($current['uploaded_at']) ?><?= $current['notes'] ? ' &mdash; ' . e($current['notes']) : '' ?></div>
                        <div class="hint" title="SHA-256 fingerprint of the file that was signed">Fingerprint: <code><?= e(substr($current['sha256'], 0, 16)) ?>…</code></div>
                    <?php else: ?>
                        <span class="hint">No document attached yet.</span>
                    <?php endif; ?>
                </dd>
            </dl>
        </div>

        <div class="card">
            <h2 style="margin-top:0;">Sign-off route</h2>
            <?php if (!$route): ?>
                <p class="hint">No route configured.</p>
            <?php else: ?>
            <ol class="dt-steps">
                <?php foreach ($route as $s): $so = $s['signoff']; $signers = $members[(int)$s['body_id']] ?? []; ?>
                    <li class="is-<?= $s['state'] ?>">
                        <span class="dt-step-dot"><?= $s['state'] === 'done' ? '&#10003;' : ($s['state'] === 'rejected' ? '&#10005;' : (int)$s['step_order']) ?></span>
                        <div class="dt-step-body">
                            <strong><?= e($s['name']) ?></strong>
                            <div class="hint"><?= e($bodies[$s['body_id']]['responsibility'] ?? '') ?></div>
                            <?php if ($so): ?>
                                <div style="margin-top:6px; font-size:13.5px;">
                                    <?= $so['decision'] === 'Endorsed' ? '<span class="badge badge-closed">Endorsed</span>' : '<span class="badge badge-overdue">Rejected</span>' ?>
                                    by <strong><?= e($so['signer_name']) ?></strong><?= $so['signer_designation'] ? ', ' . e($so['signer_designation']) : '' ?>
                                    <span class="hint">&middot; <?= format_datetime($so['signed_at']) ?></span>
                                </div>
                                <?php if ($so['signature_file']): ?><img class="dt-sig-img" src="<?= APP_BASE_URL ?>/digital/signature.php?id=<?= (int)$so['id'] ?>" alt="Signature of <?= e($so['signer_name']) ?>"><?php endif; ?>
                                <?php if ($so['comments']): ?><div class="hint" style="font-size:13px;">&ldquo;<?= nl2br(e($so['comments'])) ?>&rdquo;</div><?php endif; ?>
                            <?php elseif ($s['state'] === 'pending'): ?>
                                <div style="margin-top:6px;"><span class="badge badge-progress">Awaiting signature</span></div>
                            <?php endif; ?>
                            <?php if (!$so): ?>
                                <div class="dt-signatories<?= $signers ? '' : ' is-missing' ?>" style="margin-top:4px;">
                                    <?= $signers ? 'Signatories: ' . e(implode(', ', array_map(fn($m) => $m['full_name'] . ($m['designation'] ? ' (' . $m['designation'] . ')' : ''), $signers)))
                                                 : 'No signatory assigned' . (is_super_admin() ? ' — assign one under Governance &amp; Accountability' : '') ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <?php if ($canSign): ?>
            <div class="card dt-signoff-box" id="signoff">
                <h2 style="margin-top:0;">Sign off as <?= e($pendingName) ?></h2>
                <p class="hint" style="margin-top:0;">You are signing version <?= (int)$current['version_no'] ?> (<?= e($current['file_name']) ?>). Open and review it before signing.</p>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="signoff">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="document_id" value="<?= (int)$current['id'] ?>">
                    <div class="form-grid">
                        <div class="field">
                            <label for="signer_name">Full name</label>
                            <input type="text" id="signer_name" name="signer_name" value="<?= e($user['full_name']) ?>" maxlength="150" required>
                        </div>
                        <div class="field">
                            <label for="signer_designation">Designation</label>
                            <input type="text" id="signer_designation" name="signer_designation" value="<?= e($myDesignation ?: $pendingName) ?>" maxlength="150">
                        </div>
                        <div class="field full">
                            <label>Signature <span class="hint">(required to endorse)</span></label>
                            <div class="bb-sig-pad" data-who="signer">
                                <canvas width="600" height="180"></canvas>
                                <input type="hidden" name="signer_signature_data" value="">
                                <div class="af-tools">
                                    <button type="button" class="btn btn-secondary btn-sm" data-action="clear">Clear</button>
                                    <span class="hint">Sign with a finger, stylus or mouse.</span>
                                </div>
                            </div>
                        </div>
                        <div class="field full">
                            <label for="comments">Comments <span class="hint">(required when rejecting)</span></label>
                            <textarea id="comments" name="comments" rows="3"></textarea>
                        </div>
                        <div class="field full">
                            <label class="dt-declaration"><input type="checkbox" name="declaration" value="1" required>
                                <span>I confirm that I have reviewed this document and, on behalf of <strong><?= e($pendingName) ?></strong>, record the decision below. My signature, name, date and the document's fingerprint are recorded for audit.</span></label>
                        </div>
                    </div>
                    <div class="actions-row">
                        <button type="submit" name="decision" value="Endorsed" class="btn">Endorse &amp; sign off</button>
                        <button type="submit" name="decision" value="Rejected" class="btn btn-danger">Reject</button>
                    </div>
                </form>
            </div>
        <?php elseif ($d['status'] === 'Awaiting Sign-off' && $pendingName): ?>
            <div class="card">
                <h2 style="margin-top:0;">Awaiting signature</h2>
                <p style="margin-bottom:0;">This document is with <strong><?= e($pendingName) ?></strong> for sign-off. Only that body's assigned signatories can endorse or reject it.</p>
            </div>
        <?php endif; ?>

        <?php if ($canUpload): ?>
            <div class="card" id="attach">
                <h2 style="margin-top:0;"><?= $current ? 'Attach a new version' : 'Attach document for approval' ?></h2>
                <?php if ($current): ?>
                    <p class="hint" style="margin-top:0;">A new version replaces version <?= (int)$current['version_no'] ?> as the document under approval and <strong>restarts the sign-off route from step 1</strong>. Earlier versions and their signatures stay in the history.</p>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data"<?= $d['status'] === 'Signed Off' ? ' onsubmit="return confirm(\'This deliverable is already signed off. Attaching a new version restarts sign-off. Continue?\');"' : '' ?>>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="upload">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <div class="field">
                        <label for="document">Document</label>
                        <input type="file" id="document" name="document" required
                               accept=".pdf,.docx,.doc,.xlsx,.xls,.pptx,.ppt,.vsdx,.mpp,.png,.jpg,.jpeg">
                        <div class="hint">PDF, Word, Excel, PowerPoint, Visio, MS Project, PNG or JPG &middot; up to <?= DT_DOC_MAX_BYTES / 1024 / 1024 ?> MB</div>
                    </div>
                    <div class="field">
                        <label for="notes">Version notes <span class="hint">(optional)</span></label>
                        <input type="text" id="notes" name="notes" maxlength="500" placeholder="e.g. Updated after CFO comments">
                    </div>
                    <button type="submit" class="btn"><?= $current ? 'Attach new version' : 'Attach &amp; route for sign-off' ?></button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <h2 style="margin-top:0;">Version history</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Version</th><th>File</th><th>Attached</th><th>Notes</th><th>Outcome</th></tr></thead>
            <tbody>
            <?php foreach ($docs as $doc):
                $decisions = $byDoc[(int)$doc['id']] ?? [];
                $isCurrent = (int)$doc['id'] === (int)$d['current_document_id'];
                if ($isCurrent) {
                    $outcome = dt_status_badge($d['status']);
                } elseif (array_filter($decisions, fn($x) => $x['decision'] === 'Rejected')) {
                    $outcome = '<span class="badge badge-overdue">Rejected</span>';
                } else {
                    $outcome = '<span class="badge badge-role">Superseded</span>';
                } ?>
                <tr>
                    <td>v<?= (int)$doc['version_no'] ?><?= $isCurrent ? ' <span class="hint">(current)</span>' : '' ?></td>
                    <td><a href="<?= APP_BASE_URL ?>/digital/download.php?id=<?= (int)$doc['id'] ?>"><?= e($doc['file_name']) ?></a> <span class="hint"><?= dt_format_bytes((int)$doc['file_size']) ?></span></td>
                    <td><?= format_datetime($doc['uploaded_at']) ?><br><span class="hint"><?= e($doc['uploader']) ?></span></td>
                    <td><?= e($doc['notes'] ?? '') ?></td>
                    <td><?= $outcome ?> <span class="hint"><?= count($decisions) ?> decision<?= count($decisions) === 1 ? '' : 's' ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$docs): ?><tr><td colspan="5">No versions attached yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h2 style="margin-top:0;">Sign-off audit trail</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Signed</th><th>Version</th><th>Step</th><th>Governance body</th><th>Signatory</th><th>Decision</th><th>Comments</th></tr></thead>
            <tbody>
            <?php foreach ($signoffs as $so): ?>
                <tr>
                    <td><?= format_datetime($so['signed_at']) ?></td>
                    <td>v<?= (int)$so['version_no'] ?></td>
                    <td><?= (int)$so['step_order'] ?></td>
                    <td><?= e($so['body_name']) ?></td>
                    <td><?= e($so['signer_name']) ?><?= $so['signer_designation'] ? '<br><span class="hint">' . e($so['signer_designation']) . '</span>' : '' ?></td>
                    <td><?= $so['decision'] === 'Endorsed' ? '<span class="badge badge-closed">Endorsed</span>' : '<span class="badge badge-overdue">Rejected</span>' ?></td>
                    <td><?= nl2br(e($so['comments'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$signoffs): ?><tr><td colspan="7">No sign-offs recorded yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($canSign): ?>
<script src="<?= APP_BASE_URL ?>/assets/js/broadband_forms.js"></script>
<script>BroadbandForms.signaturePads({});</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
