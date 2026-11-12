<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'field_worker']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/sites/list.php');
}
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
$user = current_user();

$fields = [
    'site_code' => trim($_POST['site_code'] ?? ''),
    'project_id' => (int)($_POST['project_id'] ?? 0),
    'site_name' => trim($_POST['site_name'] ?? ''),
    'location' => trim($_POST['location'] ?? ''),
];

$householdsRaw = trim($_POST['households_connected'] ?? '');
$completedDate = $_POST['completed_date'] ?? '';

foreach ($fields as $value) {
    if ($value === '' || $value === 0) {
        flash('error', 'All fields are required. Please complete the form.');
        redirect($id > 0 ? "/sites/form.php?id=$id" : '/sites/form.php');
    }
}
if (!ctype_digit($householdsRaw) || (int)$householdsRaw < 1) {
    flash('error', 'Households connected must be a whole number of at least 1.');
    redirect($id > 0 ? "/sites/form.php?id=$id" : '/sites/form.php');
}
if (!$completedDate || !strtotime($completedDate)) {
    flash('error', 'A valid completion date is required.');
    redirect($id > 0 ? "/sites/form.php?id=$id" : '/sites/form.php');
}

if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM sites WHERE id = ?');
    $stmt->execute([$id]);
    $site = $stmt->fetch();
    if (!$site || !in_array($site['status'], ['Submitted', 'Rejected'], true)) {
        flash('error', 'This site can no longer be edited.');
        redirect('/sites/list.php');
    }
    if ($user['role'] !== 'admin' && (int)$site['submitted_by'] !== (int)$user['id']) {
        flash('error', 'You can only edit your own submissions.');
        redirect('/sites/view.php?id=' . $id);
    }

    $stmt = $pdo->prepare(
        'UPDATE sites SET project_id = ?, site_name = ?, location = ?, households_connected = ?, completed_date = ? WHERE id = ?'
    );
    $stmt->execute([$fields['project_id'], $fields['site_name'], $fields['location'], (int)$householdsRaw, $completedDate, $id]);
    flash('success', 'Site completion updated.');
    redirect('/sites/view.php?id=' . $id);
}

try {
    $stmt = $pdo->prepare(
        "INSERT INTO sites (site_code, project_id, site_name, location, households_connected, completed_date, status, submitted_by)
         VALUES (?, ?, ?, ?, ?, ?, 'Submitted', ?)"
    );
    $stmt->execute([
        $fields['site_code'], $fields['project_id'], $fields['site_name'], $fields['location'],
        (int)$householdsRaw, $completedDate, $user['id'],
    ]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        flash('error', 'That reference code is already in use.');
        redirect('/sites/form.php');
    }
    throw $e;
}

flash('success', 'Site completion submitted. Attach proof of evidence (POE) below before it is verified.');
redirect('/sites/view.php?id=' . $pdo->lastInsertId());
