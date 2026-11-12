<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$isAdmin = current_user()['role'] === 'admin';

$sql = 'SELECT id, slug, title, updated_at FROM cms_pages';
if (!$isAdmin) {
    $sql .= ' WHERE is_published = 1';
}
$sql .= ' ORDER BY title';
$pages = $pdo->query($sql)->fetchAll();

$pageTitle = 'Pages';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Pages</h1>
        <p class="subtitle">Help text, glossary and other content maintained by USAASA admins</p>
    </div>
    <?php if ($isAdmin): ?>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/cms/list.php">Manage pages</a>
    <?php endif; ?>
</div>

<div class="card">
    <?php if (!$pages): ?>
        <p class="hint">No pages have been published yet.</p>
    <?php else: ?>
        <ul class="history-list">
        <?php foreach ($pages as $p): ?>
            <li><a href="<?= APP_BASE_URL ?>/cms/view.php?slug=<?= e($p['slug']) ?>"><?= e($p['title']) ?></a>
                <span class="hint"> &mdash; updated <?= format_date($p['updated_at']) ?></span></li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
