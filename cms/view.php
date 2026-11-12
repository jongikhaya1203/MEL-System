<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$slug = trim($_GET['slug'] ?? '');
$stmt = $pdo->prepare(
    "SELECT p.*, c.full_name AS created_by_name, u.full_name AS updated_by_name
     FROM cms_pages p
     JOIN users c ON c.id = p.created_by
     LEFT JOIN users u ON u.id = p.updated_by
     WHERE p.slug = ?"
);
$stmt->execute([$slug]);
$page = $stmt->fetch();

$isAdmin = current_user()['role'] === 'admin';
if (!$page || (!$page['is_published'] && !$isAdmin)) {
    flash('error', 'Page not found.');
    redirect('/cms/directory.php');
}

$pageTitle = $page['title'];
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1><?= e($page['title']) ?></h1>
        <p class="subtitle">
            Last updated <?= format_datetime($page['updated_at']) ?><?= $page['updated_by_name'] ? ' by ' . e($page['updated_by_name']) : '' ?>
            <?php if (!$page['is_published']): ?> &mdash; <strong>unpublished (admin preview)</strong><?php endif; ?>
        </p>
    </div>
    <?php if ($isAdmin): ?>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/cms/form.php?id=<?= (int)$page['id'] ?>">Edit</a>
    <?php endif; ?>
</div>

<div class="card">
    <?= render_cms_content($page['body']) ?>
</div>

<p><a href="<?= APP_BASE_URL ?>/cms/directory.php">&larr; Back to pages</a></p>

<?php include __DIR__ . '/../includes/footer.php'; ?>
