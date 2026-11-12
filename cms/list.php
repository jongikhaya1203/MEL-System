<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin']);

$pages = $pdo->query(
    "SELECT p.*, u.full_name AS updated_by_name
     FROM cms_pages p
     LEFT JOIN users u ON u.id = p.updated_by
     ORDER BY p.title"
)->fetchAll();

$pageTitle = 'Manage Pages';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Manage Pages</h1>
        <p class="subtitle">Admin-editable content pages, shown under "Pages" in the nav</p>
    </div>
    <a class="btn" href="<?= APP_BASE_URL ?>/cms/form.php">+ New page</a>
</div>

<div class="card">
    <div class="table-wrap">
    <table>
        <thead><tr><th>Title</th><th>Slug</th><th>Published</th><th>Last updated</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pages as $p): ?>
            <tr>
                <td><?= e($p['title']) ?></td>
                <td><?= e($p['slug']) ?></td>
                <td><?= $p['is_published'] ? 'Yes' : 'No' ?></td>
                <td><?= format_datetime($p['updated_at']) ?><?= $p['updated_by_name'] ? ' by ' . e($p['updated_by_name']) : '' ?></td>
                <td>
                    <a href="<?= APP_BASE_URL ?>/cms/form.php?id=<?= (int)$p['id'] ?>">Edit</a> &middot;
                    <a href="<?= APP_BASE_URL ?>/cms/view.php?slug=<?= e($p['slug']) ?>">View</a>
                    <?php if (is_super_admin()): ?>
                        &middot; <a href="<?= APP_BASE_URL ?>/cms/delete.php?id=<?= (int)$p['id'] ?>" style="color:#b3261e;">Delete</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$pages): ?>
            <tr><td colspan="5">No pages yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
