<?php
/** Serves a pre-assessment image to logged-in users. ?id=<rollout site id>&type=photo|assessor_sig|site_rep_sig */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$columns = ['photo' => 'site_photo', 'assessor_sig' => 'assessor_signature', 'site_rep_sig' => 'site_rep_signature'];
$type = $_GET['type'] ?? '';
if (!isset($columns[$type])) {
    http_response_code(400);
    die('Invalid file type.');
}

$stmt = $pdo->prepare("SELECT {$columns[$type]} FROM site_pre_assessments WHERE rollout_site_id = ?");
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$filename = $stmt->fetchColumn();

if (!is_broadband_upload_filename($filename) || !is_file(ACCEPTANCE_STORAGE_DIR . $filename)) {
    http_response_code(404);
    die('File not found.');
}

$path = ACCEPTANCE_STORAGE_DIR . $filename;
header('Content-Type: ' . (substr($filename, -4) === '.png' ? 'image/png' : 'image/jpeg'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: private, no-cache');
header('X-Content-Type-Options: nosniff');
readfile($path);
