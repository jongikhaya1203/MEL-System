<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM site_poe_documents WHERE id = ?');
$stmt->execute([$id]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    die('Document not found.');
}

$path = POE_STORAGE_DIR . $doc['stored_filename'];
if (!is_file($path)) {
    http_response_code(404);
    die('The file for this document is missing from storage.');
}

// ?inline=1 is used by sites/preview.php to embed images/PDFs directly in
// the printable preview; every other link to this file keeps forcing a
// download, which is the safer default for arbitrary uploaded content.
$disposition = isset($_GET['inline']) ? 'inline' : 'attachment';

header('Content-Type: ' . $doc['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . basename($doc['file_name']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
