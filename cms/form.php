<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin']);

$editing = null;
if (isset($_GET['id'])) {
    $stmt = $pdo->prepare('SELECT * FROM cms_pages WHERE id = ?');
    $stmt->execute([(int)$_GET['id']]);
    $editing = $stmt->fetch() ?: null;
    if (!$editing) {
        flash('error', 'Page not found.');
        redirect('/cms/list.php');
    }
}

$pageTitle = $editing ? 'Edit page' : 'New page';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1><?= $editing ? 'Edit page' : 'New page' ?></h1>
        <p class="subtitle">Manage Pages</p>
    </div>
</div>

<div class="card">
    <form method="post" action="<?= APP_BASE_URL ?>/cms/save.php">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">
        <div class="form-grid">
            <div class="field">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" required value="<?= e($editing['title'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="slug">Slug (used in the page URL)</label>
                <input type="text" id="slug" name="slug" required pattern="[a-z0-9\-]+"
                       value="<?= e($editing['slug'] ?? '') ?>" placeholder="e.g. help, glossary, data-policy">
                <p class="hint">Lowercase letters, numbers and hyphens only.</p>
            </div>
            <div class="field full">
                <label for="body">Body</label>
                <textarea id="body" name="body" required style="min-height:260px;"><?= e($editing['body'] ?? '') ?></textarea>
                <p class="hint">
                    Plain text, with simple formatting: <code># Heading</code>, <code>## Subheading</code>,
                    <code>**bold**</code>, <code>*italic*</code>, <code>- bullet item</code>, and
                    <code>[link text](https://example.com)</code>. Blank lines start a new paragraph.
                    No raw HTML is allowed — anything typed is shown as plain text except these patterns.
                </p>
            </div>
            <div class="field">
                <label style="display:flex; align-items:center; gap:6px;">
                    <input type="checkbox" name="is_published" value="1" style="width:auto;"
                           <?= (!$editing || $editing['is_published']) ? 'checked' : '' ?>>
                    Published (visible under "Pages")
                </label>
            </div>
        </div>
        <button type="submit" class="btn"><?= $editing ? 'Save changes' : 'Create page' ?></button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/cms/list.php">Cancel</a>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
