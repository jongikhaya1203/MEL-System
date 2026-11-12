<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT s.*, p.project_name, p.project_code,
            sub.full_name AS submitted_by_name,
            ver.full_name AS verified_by_name,
            app.full_name AS approved_by_name,
            rej.full_name AS rejected_by_name
     FROM sites s
     JOIN projects p ON p.id = s.project_id
     JOIN users sub ON sub.id = s.submitted_by
     LEFT JOIN users ver ON ver.id = s.verified_by
     LEFT JOIN users app ON app.id = s.approved_by
     LEFT JOIN users rej ON rej.id = s.rejected_by
     WHERE s.id = ?"
);
$stmt->execute([$id]);
$site = $stmt->fetch();

if (!$site) {
    http_response_code(404);
    die('Site not found.');
}

$docsStmt = $pdo->prepare(
    "SELECT d.*, u.full_name AS uploaded_by_name
     FROM site_poe_documents d
     JOIN users u ON u.id = d.uploaded_by
     WHERE d.site_id = ?
     ORDER BY d.uploaded_at ASC"
);
$docsStmt->execute([$id]);
$docs = $docsStmt->fetchAll();

$settings = get_site_settings($pdo);
$pageTitle = $site['site_code'] . ' — Preview';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?></title>
<link rel="stylesheet" href="<?= APP_BASE_URL ?>/assets/css/style.css">
<style>
    body { background: #e9eaea; }
    .preview-toolbar {
        position: sticky; top: 0; z-index: 10; background: var(--brand); color: #fff;
        padding: 12px 24px; display: flex; justify-content: space-between; align-items: center; gap: 12px;
    }
    .preview-toolbar .btn-secondary { background: rgba(255,255,255,.12); color: #fff; border-color: rgba(255,255,255,.3); }
    .preview-toolbar .btn-secondary:hover { background: rgba(255,255,255,.22); }
    .preview-sheet {
        max-width: 860px; margin: 28px auto 60px; background: #fff; border: 1px solid var(--border);
        border-radius: 6px; padding: 48px 56px; box-shadow: 0 2px 10px rgba(0,0,0,.06);
    }
    .preview-letterhead {
        display: flex; align-items: center; gap: 14px; border-bottom: 3px solid var(--orange);
        padding-bottom: 18px; margin-bottom: 28px;
    }
    .preview-letterhead img { height: 48px; width: auto; object-fit: contain; }
    .preview-letterhead .org-name { font-weight: 700; font-size: 17px; color: var(--brand); }
    .preview-letterhead .doc-title { margin-left: auto; text-align: right; }
    .preview-letterhead .doc-title h1 { font-size: 19px; margin: 0; }
    .preview-letterhead .doc-title .ref { font-family: monospace; color: #5b6b78; font-size: 13px; }
    .preview-section { margin-bottom: 30px; }
    .preview-section h2 {
        font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: #5b6b78;
        border-bottom: 1px solid var(--border); padding-bottom: 6px; margin-bottom: 14px;
    }
    .poe-item { margin-bottom: 20px; page-break-inside: avoid; }
    .poe-item .poe-meta { font-size: 12.5px; color: #6b7a85; margin-bottom: 6px; }
    .poe-item img { max-width: 100%; max-height: 520px; object-fit: contain; border: 1px solid var(--border); border-radius: 4px; }
    .poe-item iframe { width: 100%; height: 480px; border: 1px solid var(--border); border-radius: 4px; }
    .poe-text { background: #f8f8f6; border: 1px solid var(--border); border-radius: 4px; padding: 14px 16px; font-size: 13px; white-space: pre-wrap; font-family: "IBM Plex Mono", monospace; }
    .print-note { font-size: 11.5px; color: #6b7a85; font-style: italic; }
    .sig-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-top: 36px; }
    .sig-box { border-top: 1px solid var(--border); padding-top: 8px; font-size: 12.5px; color: #5b6b78; }
    .sig-box .role { font-weight: 600; color: var(--text); display: block; margin-bottom: 2px; }

    @media print {
        body { background: #fff; }
        .preview-toolbar, .no-print { display: none !important; }
        .preview-sheet { box-shadow: none; border: none; margin: 0; padding: 0; max-width: 100%; }
        .poe-item iframe { display: none; }
    }
</style>
</head>
<body>

<div class="preview-toolbar no-print">
    <div><strong><?= e($site['site_code']) ?></strong> — print preview</div>
    <div class="actions-row" style="margin:0;">
        <button type="button" class="btn" onclick="window.print()">Print</button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/sites/view.php?id=<?= $id ?>">Back to record</a>
    </div>
</div>

<div class="preview-sheet">
    <div class="preview-letterhead">
        <?php if ($settings['logo_url']): ?>
            <img src="<?= e($settings['logo_url']) ?>" alt="">
        <?php endif; ?>
        <span class="org-name"><?= e($settings['site_name']) ?></span>
        <div class="doc-title">
            <h1>Site Completion Record</h1>
            <div class="ref"><?= e($site['site_code']) ?></div>
        </div>
    </div>

    <div class="preview-section">
        <h2>Status</h2>
        <?= site_status_badge($site['status']) ?>
        <?php if ($site['status'] === 'Approved'): ?>
            <span class="print-note">&nbsp;— approved for payment by <?= e($site['approved_by_name']) ?> on <?= format_datetime($site['approved_at']) ?></span>
        <?php endif; ?>
    </div>

    <div class="preview-section">
        <h2>Submitted information</h2>
        <dl class="detail-grid">
            <dt>Project</dt>
            <dd><?= e($site['project_name']) ?> (<?= e($site['project_code']) ?>)</dd>

            <dt>Site name</dt>
            <dd><?= e($site['site_name']) ?></dd>

            <dt>Location</dt>
            <dd><?= e($site['location']) ?></dd>

            <dt>Households connected</dt>
            <dd><?= number_format((int)$site['households_connected']) ?></dd>

            <dt>Completion date</dt>
            <dd><?= format_date($site['completed_date']) ?></dd>

            <dt>Submitted by</dt>
            <dd><?= e($site['submitted_by_name']) ?>, <?= format_datetime($site['submitted_at']) ?></dd>
        </dl>
    </div>

    <?php if ($site['verified_by']): ?>
    <div class="preview-section">
        <h2>Verification</h2>
        <dl class="detail-grid">
            <dt>Verified by</dt>
            <dd><?= e($site['verified_by_name']) ?>, <?= format_datetime($site['verified_at']) ?></dd>
            <?php if ($site['verification_notes']): ?>
            <dt>Notes</dt>
            <dd><?= nl2br(e($site['verification_notes'])) ?></dd>
            <?php endif; ?>
        </dl>
    </div>
    <?php endif; ?>

    <?php if ($site['approved_by']): ?>
    <div class="preview-section">
        <h2>Approval</h2>
        <dl class="detail-grid">
            <dt>Approved by</dt>
            <dd><?= e($site['approved_by_name']) ?>, <?= format_datetime($site['approved_at']) ?></dd>
            <?php if ($site['approval_notes']): ?>
            <dt>Notes</dt>
            <dd><?= nl2br(e($site['approval_notes'])) ?></dd>
            <?php endif; ?>
        </dl>
    </div>
    <?php endif; ?>

    <?php if ($site['status'] === 'Rejected'): ?>
    <div class="preview-section">
        <h2>Rejection</h2>
        <dl class="detail-grid">
            <dt>Rejected by</dt>
            <dd><?= e($site['rejected_by_name']) ?>, <?= format_datetime($site['rejected_at']) ?></dd>
            <dt>Reason</dt>
            <dd><?= nl2br(e($site['rejection_reason'])) ?></dd>
        </dl>
    </div>
    <?php endif; ?>

    <div class="preview-section">
        <h2>Proof of evidence (<?= count($docs) ?>)</h2>
        <?php if (!$docs): ?>
            <p class="hint">No POE documents attached.</p>
        <?php else: ?>
            <?php foreach ($docs as $d): ?>
                <?php
                $isImage = str_starts_with($d['mime_type'], 'image/');
                $isPdf = $d['mime_type'] === 'application/pdf';
                $isText = $d['mime_type'] === 'text/plain';
                $inlineUrl = APP_BASE_URL . '/sites/download_poe.php?id=' . (int)$d['id'] . '&inline=1';
                ?>
                <div class="poe-item">
                    <div class="poe-meta">
                        <strong><?= e($d['file_name']) ?></strong><?= $d['description'] ? ' — ' . e($d['description']) : '' ?>
                        &middot; uploaded by <?= e($d['uploaded_by_name']) ?>, <?= format_datetime($d['uploaded_at']) ?>
                    </div>
                    <?php if ($isImage): ?>
                        <img src="<?= e($inlineUrl) ?>" alt="<?= e($d['file_name']) ?>">
                    <?php elseif ($isText): ?>
                        <div class="poe-text"><?= e(@file_get_contents(POE_STORAGE_DIR . $d['stored_filename']) ?: '(could not read file)') ?></div>
                    <?php elseif ($isPdf): ?>
                        <iframe src="<?= e($inlineUrl) ?>" class="no-print"></iframe>
                        <p class="print-note">PDF attachment — <a href="<?= e($inlineUrl) ?>" target="_blank">open <?= e($d['file_name']) ?></a> to view or print it separately; it is not embedded in this printed page.</p>
                    <?php else: ?>
                        <p class="hint">Attached file: <a href="<?= e($inlineUrl) ?>" target="_blank"><?= e($d['file_name']) ?></a></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="sig-row no-print-hide">
        <div class="sig-box"><span class="role">Installer</span><?= e($site['submitted_by_name']) ?></div>
        <div class="sig-box"><span class="role">Reviewer</span><?= $site['verified_by'] ? e($site['verified_by_name']) : '—' ?></div>
        <div class="sig-box"><span class="role">Approver</span><?= $site['approved_by'] ? e($site['approved_by_name']) : '—' ?></div>
    </div>

    <p class="print-note" style="margin-top:30px;">Generated from the USAASA MEL System on <?= format_datetime(date('Y-m-d H:i:s')) ?>. Reference: <?= e($site['site_code']) ?>.</p>
</div>

</body>
</html>
