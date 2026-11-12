<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_super_admin();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM cms_pages WHERE id = ?');
$stmt->execute([$id]);
$page = $stmt->fetch();

if (!$page) {
    flash('error', 'Page not found.');
    redirect('/cms/list.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $pdo->prepare('DELETE FROM cms_pages WHERE id = ?')->execute([$id]);
    flash('success', 'Page deleted.');
    redirect('/cms/list.php');
}

$pageTitle = 'Delete page';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Delete page &mdash; <?= e($page['title']) ?></h1>
        <p class="subtitle">This cannot be undone.</p>
    </div>
</div>

<div class="card">
    <p>Are you sure you want to permanently delete the page "<?= e($page['title']) ?>" (<?= e($page['slug']) ?>)?</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <button type="submit" class="btn btn-danger">Delete permanently</button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/cms/list.php">Cancel</a>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
