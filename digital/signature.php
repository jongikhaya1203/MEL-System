<?php
/** Serves the drawn signature captured with a Digital Transformation sign-off. ?id=<dt_signoffs.id> */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$stmt = $pdo->prepare('SELECT signature_file FROM dt_signoffs WHERE id = ?');
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$file = $stmt->fetchColumn();
if (!$file || !preg_match('/^signature_[a-f0-9]{32}\.png$/', $file) || !is_file(DT_STORAGE_DIR . $file)) {
    http_response_code(404);
    die('Signature not found.');
}

header('Content-Type: image/png');
header('Content-Length: ' . filesize(DT_STORAGE_DIR . $file));
header('Cache-Control: private, no-cache');
header('X-Content-Type-Options: nosniff');
readfile(DT_STORAGE_DIR . $file);
