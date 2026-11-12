<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'field_worker', 'back_office']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/sites/list.php');
}
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT id, status FROM sites WHERE id = ?');
$stmt->execute([$id]);
$site = $stmt->fetch();

if (!$site) {
    flash('error', 'Site not found.');
    redirect('/sites/list.php');
}
if ($site['status'] === 'Approved') {
    flash('error', 'This site has already been approved; no further documents can be attached.');
    redirect('/sites/view.php?id=' . $id);
}

$error = null;
$stored = store_poe_upload($_FILES['poe_file'] ?? [], $error);
if (!$stored) {
    flash('error', $error ?? 'Upload failed.');
    redirect('/sites/view.php?id=' . $id);
}

$description = trim($_POST['description'] ?? '');

$stmt = $pdo->prepare(
    'INSERT INTO site_poe_documents (site_id, file_name, stored_filename, file_size, mime_type, description, uploaded_by)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
    $id, $stored['file_name'], $stored['stored_filename'], $stored['file_size'], $stored['mime_type'],
    $description !== '' ? $description : null, current_user()['id'],
]);

flash('success', 'POE document attached.');
redirect('/sites/view.php?id=' . $id);
