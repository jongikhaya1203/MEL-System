<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT a.*, u.full_name AS captured_by_name
     FROM indigent_applications a
     JOIN users u ON u.id = a.captured_by
     WHERE a.id = ?"
);
$stmt->execute([$id]);
$app = $stmt->fetch();

if (!$app) {
    flash('error', 'Application not found.');
    redirect('/indigent/list.php');
}

$canManage = in_array(current_user()['role'], ['admin', 'manager', 'field_worker', 'indigent_capturer'], true);
$canDelete = is_super_admin();
$idCheck = validate_sa_id_number($app['id_number']);

$pageTitle = $app['reference_code'];
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1><?= e($app['reference_code']) ?></h1>
        <p class="subtitle"><?= e($app['first_name']) ?> <?= e($app['surname']) ?> &mdash; <?= e($app['village_name']) ?>, <?= e($app['local_municipality']) ?></p>
    </div>
    <div class="actions-row">
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indigent/print.php?id=<?= $id ?>" target="_blank">Preview &amp; print</a>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indigent/export.php?format=csv&id=<?= $id ?>">Export CSV</a>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indigent/export.php?format=excel&id=<?= $id ?>">Export Excel</a>
        <?php if ($canManage): ?>
            <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indigent/form.php?id=<?= $id ?>">Edit</a>
        <?php endif; ?>
        <?php if ($canDelete): ?>
            <a class="btn btn-danger" href="<?= APP_BASE_URL ?>/indigent/delete.php?id=<?= $id ?>">Delete</a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <h2>Applicant</h2>
    <dl class="detail-grid">
        <dt>Name</dt>
        <dd><?= e($app['first_name']) ?> <?= e($app['surname']) ?></dd>

        <dt>ID number</dt>
        <dd>
            <?= e($app['id_number']) ?>
            <span class="hint">(captured via <?= $app['id_capture_method'] === 'scanned' ? 'barcode scan' : 'manual entry' ?>)</span>
            <?php if ($idCheck['valid']): ?>
                <br><span class="hint">Derived: <?= e($idCheck['gender']) ?>, born <?= format_date($idCheck['dob']) ?>, <?= $idCheck['citizen'] ? 'SA citizen per ID' : 'non-citizen per ID'; ?></span>
            <?php endif; ?>
        </dd>

        <dt>GPS coordinate</dt>
        <dd><?= ($app['latitude'] !== null && $app['longitude'] !== null) ? e($app['latitude']) . ', ' . e($app['longitude']) : '<span class="hint">Not captured.</span>' ?></dd>

        <dt>Household photo</dt>
        <dd>
            <?php if ($app['household_photo_filename']): ?>
                <a href="<?= APP_BASE_URL ?>/indigent/view_file.php?id=<?= $id ?>&type=photo" target="_blank">
                    <img src="<?= APP_BASE_URL ?>/indigent/view_file.php?id=<?= $id ?>&type=photo" alt="Household photo" style="max-height:160px; border-radius:6px; display:block;">
                </a>
            <?php else: ?>
                <span class="hint">No photo attached.</span>
            <?php endif; ?>
        </dd>
    </dl>
</div>

<div class="card">
    <h2>Address</h2>
    <dl class="detail-grid">
        <dt>Province</dt>
        <dd><?= e($app['province']) ?></dd>
        <dt>District Municipality</dt>
        <dd><?= e($app['district_municipality']) ?></dd>
        <dt>Local Municipality</dt>
        <dd><?= e($app['local_municipality']) ?></dd>
        <dt>Village / area</dt>
        <dd><?= e($app['village_name']) ?></dd>
        <dt>Physical address</dt>
        <dd><?= $app['physical_address'] ? nl2br(e($app['physical_address'])) : '<span class="hint">—</span>' ?></dd>
        <dt>Distance to Local Municipality office</dt>
        <dd>
            North: <?= $app['distance_north_km'] !== null ? number_format((float)$app['distance_north_km'], 1) . ' km' : '—' ?> &middot;
            South: <?= $app['distance_south_km'] !== null ? number_format((float)$app['distance_south_km'], 1) . ' km' : '—' ?> &middot;
            East: <?= $app['distance_east_km'] !== null ? number_format((float)$app['distance_east_km'], 1) . ' km' : '—' ?> &middot;
            West: <?= $app['distance_west_km'] !== null ? number_format((float)$app['distance_west_km'], 1) . ' km' : '—' ?>
        </dd>
    </dl>
</div>

<div class="card">
    <h2>Household &amp; declaration</h2>
    <dl class="detail-grid">
        <dt>Number of pupils</dt>
        <dd><?= (int)$app['number_of_pupils'] ?></dd>
        <dt>Monthly income</dt>
        <dd>R<?= number_format((float)$app['monthly_income'], 2) ?></dd>
        <dt>Functional television</dt>
        <dd><?= $app['has_functional_tv'] ? 'Yes' : 'No' ?></dd>
        <dt>Declaration</dt>
        <dd>
            <?= $app['declares_south_african'] ? '✓' : '✗' ?> South African citizen<br>
            <?= $app['declares_income_under_3200'] ? '✓' : '✗' ?> Household income under R3,200/month<br>
            <?= $app['declares_sabc_concession'] ? '✓' : '✗' ?> Covered by SABC concessionary TV licence<br>
            <?= $app['declares_grant_with_proof'] ? '✓' : '✗' ?> Social grant recipient, proof provided
        </dd>
        <dt>Applicant signature</dt>
        <dd>
            <?php if ($app['signature_filename']): ?>
                <img src="<?= APP_BASE_URL ?>/indigent/view_file.php?id=<?= $id ?>&type=signature" alt="Applicant signature" style="max-height:80px; border:1px solid var(--border); border-radius:4px; background:#fff; display:block;">
                <span class="hint">Signed <?= format_datetime($app['signed_at']) ?></span>
            <?php else: ?>
                <span class="hint">No signature on file.</span>
            <?php endif; ?>
        </dd>
    </dl>
</div>

<p class="hint">Captured by <?= e($app['captured_by_name']) ?> on <?= format_datetime($app['captured_at']) ?><?= $app['updated_at'] !== $app['captured_at'] ? ', last updated ' . format_datetime($app['updated_at']) : '' ?>.</p>

<p><a href="<?= APP_BASE_URL ?>/indigent/list.php">&larr; Back to applications</a></p>

<?php include __DIR__ . '/../includes/footer.php'; ?>
