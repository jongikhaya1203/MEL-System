<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/cms/list.php');
}
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
$title = trim($_POST['title'] ?? '');
$slug = trim(strtolower($_POST['slug'] ?? ''));
$body = trim($_POST['body'] ?? '');
$isPublished = isset($_POST['is_published']) ? 1 : 0;
$user = current_user();

if ($title === '' || $body === '') {
    flash('error', 'Title and body are required.');
    redirect($id > 0 ? "/cms/form.php?id=$id" : '/cms/form.php');
}
if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
    flash('error', 'Slug must contain only lowercase letters, numbers and hyphens.');
    redirect($id > 0 ? "/cms/form.php?id=$id" : '/cms/form.php');
}

try {
    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE cms_pages SET title = ?, slug = ?, body = ?, is_published = ?, updated_by = ? WHERE id = ?'
        );
        $stmt->execute([$title, $slug, $body, $isPublished, $user['id'], $id]);
        flash('success', 'Page updated.');
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO cms_pages (slug, title, body, is_published, created_by) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$slug, $title, $body, $isPublished, $user['id']]);
        flash('success', 'Page created.');
    }
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        flash('error', 'That slug is already in use by another page.');
        redirect($id > 0 ? "/cms/form.php?id=$id" : '/cms/form.php');
    }
    throw $e;
}

redirect('/cms/list.php');
