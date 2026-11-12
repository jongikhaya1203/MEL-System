<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'manager', 'planner']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/planning/list.php');
}
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
$user = current_user();
$backUrl = $id > 0 ? "/planning/form.php?id=$id" : '/planning/form.php';

$fields = [
    'reference_code' => trim($_POST['reference_code'] ?? ''),
    'project_id' => (int)($_POST['project_id'] ?? 0),
    'region' => trim($_POST['region'] ?? ''),
    'installer_id' => (int)($_POST['installer_id'] ?? 0),
    'target_installations' => trim($_POST['target_installations'] ?? ''),
];

if ($fields['reference_code'] === '' || $fields['project_id'] <= 0 || $fields['region'] === '' || $fields['installer_id'] <= 0) {
    flash('error', 'All required fields must be completed.');
    redirect($backUrl);
}
if (!ctype_digit($fields['target_installations']) || (int)$fields['target_installations'] <= 0) {
    flash('error', 'Target number of installations must be a positive whole number.');
    redirect($backUrl);
}

$projectStmt = $pdo->prepare('SELECT province FROM projects WHERE id = ?');
$projectStmt->execute([$fields['project_id']]);
$province = $projectStmt->fetchColumn();
if (!$province) {
    flash('error', 'Please select a valid province-tagged project.');
    redirect($backUrl);
}
if (!sa_validate_district($province, $fields['region'])) {
    flash('error', 'Please select a valid region for the chosen province.');
    redirect($backUrl);
}

$installerCheck = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'field_worker' AND is_active = 1");
$installerCheck->execute([$fields['installer_id']]);
if (!$installerCheck->fetch()) {
    flash('error', 'Please select a valid, active Installer.');
    redirect($backUrl);
}

$indicatorId = (int)($_POST['indicator_id'] ?? 0);
if ($indicatorId > 0) {
    $indCheck = $pdo->prepare('SELECT id FROM indicators WHERE id = ?');
    $indCheck->execute([$indicatorId]);
    if (!$indCheck->fetch()) {
        flash('error', 'The selected linked indicator could not be found.');
        redirect($backUrl);
    }
} else {
    $indicatorId = null;
}

$notes = trim($_POST['notes'] ?? '');

if ($id > 0) {
    $stmt = $pdo->prepare(
        "UPDATE installer_allocations SET
            project_id = ?, region = ?, installer_id = ?, indicator_id = ?,
            target_installations = ?, notes = ?
         WHERE id = ?"
    );
    $stmt->execute([
        $fields['project_id'], $fields['region'], $fields['installer_id'], $indicatorId,
        (int)$fields['target_installations'], $notes !== '' ? $notes : null,
        $id,
    ]);
    flash('success', 'Allocation updated.');
    redirect("/planning/view.php?id=$id");
}

$deadline = $_POST['deadline'] ?? '';
if (!$deadline || !strtotime($deadline)) {
    flash('error', 'A valid deadline is required.');
    redirect('/planning/form.php');
}

$stmt = $pdo->prepare(
    "INSERT INTO installer_allocations (
        reference_code, project_id, region, installer_id, indicator_id,
        target_installations, original_deadline, current_deadline, status,
        notes, dispatched_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Planned', ?, ?)"
);
try {
    $stmt->execute([
        $fields['reference_code'], $fields['project_id'], $fields['region'], $fields['installer_id'], $indicatorId,
        (int)$fields['target_installations'], $deadline, $deadline,
        $notes !== '' ? $notes : null, $user['id'],
    ]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        flash('error', 'That reference code is already in use.');
        redirect('/planning/form.php');
    }
    throw $e;
}

flash('success', 'Allocation dispatched.');
redirect('/planning/view.php?id=' . $pdo->lastInsertId());
