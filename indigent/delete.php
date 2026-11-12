<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_super_admin();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM indigent_applications WHERE id = ?');
$stmt->execute([$id]);
$app = $stmt->fetch();

if (!$app) {
    flash('error', 'Application not found.');
    redirect('/indigent/list.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach ([$app['household_photo_filename'], $app['signature_filename']] as $filename) {
        if ($filename) {
            $path = INDIGENT_STORAGE_DIR . $filename;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
    $pdo->prepare('DELETE FROM indigent_applications WHERE id = ?')->execute([$id]);
    flash('success', 'Application deleted.');
    redirect('/indigent/list.php');
}

$pageTitle = 'Delete application';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Delete application &mdash; <?= e($app['reference_code']) ?></h1>
        <p class="subtitle">This permanently removes the record, photo and signature. This cannot be undone.</p>
    </div>
</div>

<div class="card">
    <p>Are you sure you want to permanently delete the application for "<?= e($app['first_name']) ?> <?= e($app['surname']) ?>" (<?= e($app['reference_code']) ?>)?</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <button type="submit" class="btn btn-danger">Delete permanently</button>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indigent/view.php?id=<?= $id ?>">Cancel</a>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
