<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$type = $_GET['type'] ?? '';
if (!in_array($type, ['photo', 'signature'], true)) {
    http_response_code(400);
    die('Invalid file type.');
}

$column = $type === 'photo' ? 'household_photo_filename' : 'signature_filename';
$stmt = $pdo->prepare("SELECT $column AS filename FROM indigent_applications WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row || !$row['filename']) {
    http_response_code(404);
    die('File not found.');
}

$path = INDIGENT_STORAGE_DIR . $row['filename'];
if (!is_file($path)) {
    http_response_code(404);
    die('The file is missing from storage.');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $path);
finfo_close($finfo);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . basename($row['filename']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
