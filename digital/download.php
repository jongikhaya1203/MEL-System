<?php
/** Serves a Digital Transformation deliverable document to logged-in users. ?id=<dt_documents.id>[&inline=1] */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$stmt = $pdo->prepare('SELECT * FROM dt_documents WHERE id = ?');
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$doc = $stmt->fetch();
if (!$doc || !preg_match('/^[a-f0-9]{32}\.[a-z]{3,4}$/', $doc['stored_filename']) || !is_file(DT_STORAGE_DIR . $doc['stored_filename'])) {
    http_response_code(404);
    die('Document not found.');
}

$path = DT_STORAGE_DIR . $doc['stored_filename'];
// Inline only for types a browser renders safely; everything else downloads.
$inline = isset($_GET['inline']) && in_array($doc['mime_type'], ['application/pdf', 'image/png', 'image/jpeg'], true);
$name = str_replace(['"', "\r", "\n"], '', basename($doc['file_name']));

header('Content-Type: ' . $doc['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"');
header('Cache-Control: private, no-cache');
header('X-Content-Type-Options: nosniff');
readfile($path);
