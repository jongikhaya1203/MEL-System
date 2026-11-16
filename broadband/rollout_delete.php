<?php
/**
 * Bulk delete of rollout sites selected on broadband/rollout_list.php (POST ids[]).
 * Deleting a site also deletes its pre-assessment (FK cascade), so that form's
 * photo and signature files are removed from storage too.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'manager', 'planner']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/broadband/rollout_list.php');
}
csrf_verify();

$back = '/broadband/rollout_list.php' . (!empty($_POST['return_query']) ? '?' . preg_replace('/[^\w=&%.\-+]/', '', (string)$_POST['return_query']) : '');
$ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
if (!$ids) {
    flash('error', 'No sites were selected.');
    redirect($back);
}

$in = implode(',', array_fill(0, count($ids), '?'));
$files = $pdo->prepare("SELECT site_photo, assessor_signature, site_rep_signature FROM site_pre_assessments WHERE rollout_site_id IN ($in)");
$files->execute($ids);
$toUnlink = array_filter(array_merge(...array_map('array_values', $files->fetchAll() ?: [[]])));

$pdo->beginTransaction();
$del = $pdo->prepare("DELETE FROM broadband_rollout_sites WHERE id IN ($in)");
$del->execute($ids);
$count = $del->rowCount();
$pdo->commit();

foreach ($toUnlink as $f) {
    if (is_broadband_upload_filename($f)) {
        @unlink(ACCEPTANCE_STORAGE_DIR . $f);
    }
}

flash('success', $count . ' site' . ($count === 1 ? '' : 's') . ' deleted.');
redirect($back);
