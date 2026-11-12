<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/risks/list.php');
}
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
$user = current_user();

$CATEGORIES = ['Strategic', 'Operational', 'Financial', 'Compliance', 'Reputational', 'ICT', 'Other'];
$LEVELS = ['Low', 'Medium', 'High'];

$category = $_POST['category'] ?? '';
$likelihood = $_POST['likelihood'] ?? '';
$impact = $_POST['impact'] ?? '';

if (!in_array($category, $CATEGORIES, true) || !in_array($likelihood, $LEVELS, true) || !in_array($impact, $LEVELS, true)) {
    flash('error', 'Please choose a valid category, likelihood and impact.');
    redirect($id > 0 ? "/risks/form.php?id=$id" : '/risks/form.php');
}

$fields = [
    'reference_code' => trim($_POST['reference_code'] ?? ''),
    'project_id' => (int)($_POST['project_id'] ?? 0),
    'category' => $category,
    'date_identified' => $_POST['date_identified'] ?? '',
    'risk_description' => trim($_POST['risk_description'] ?? ''),
    'existing_controls' => trim($_POST['existing_controls'] ?? ''),
    'likelihood' => $likelihood,
    'impact' => $impact,
    'mitigation_action' => trim($_POST['mitigation_action'] ?? ''),
    'owner_user_id' => (int)($_POST['owner_user_id'] ?? 0),
];

foreach ($fields as $key => $value) {
    if ($value === '' || $value === 0) {
        flash('error', 'All fields are required. Please complete the form.');
        redirect($id > 0 ? "/risks/form.php?id=$id" : '/risks/form.php');
    }
}

$riskRating = compute_risk_rating($likelihood, $impact);

if ($id > 0) {
    // Editing an existing record: deadline is NOT editable here (see revise_deadline.php).
    $stmt = $pdo->prepare(
        "UPDATE risks SET
            project_id = ?, category = ?, date_identified = ?,
            risk_description = ?, existing_controls = ?,
            likelihood = ?, impact = ?, risk_rating = ?,
            mitigation_action = ?, owner_user_id = ?,
            follow_up_outcome = ?
         WHERE id = ? AND status <> 'Closed'"
    );
    $stmt->execute([
        $fields['project_id'], $fields['category'], $fields['date_identified'],
        $fields['risk_description'], $fields['existing_controls'],
        $fields['likelihood'], $fields['impact'], $riskRating,
        $fields['mitigation_action'], $fields['owner_user_id'],
        trim($_POST['follow_up_outcome'] ?? ''),
        $id,
    ]);
    if ($stmt->rowCount() === 0) {
        flash('error', 'This record could not be updated (it may already be closed).');
    } else {
        flash('success', 'Risk updated.');
    }
    redirect("/risks/view.php?id=$id");
}

// New record
$deadline = $_POST['deadline'] ?? '';
if (!$deadline || !strtotime($deadline)) {
    flash('error', 'A valid mitigation deadline is required.');
    redirect('/risks/form.php');
}

$stmt = $pdo->prepare(
    "INSERT INTO risks (
        reference_code, project_id, category, date_identified,
        risk_description, existing_controls,
        likelihood, impact, risk_rating,
        mitigation_action, owner_user_id,
        original_deadline, current_deadline, status, created_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Open', ?)"
);
try {
    $stmt->execute([
        $fields['reference_code'], $fields['project_id'], $fields['category'], $fields['date_identified'],
        $fields['risk_description'], $fields['existing_controls'],
        $fields['likelihood'], $fields['impact'], $riskRating,
        $fields['mitigation_action'], $fields['owner_user_id'],
        $deadline, $deadline, $user['id'],
    ]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        flash('error', 'That reference code is already in use.');
        redirect('/risks/form.php');
    }
    throw $e;
}

flash('success', 'Risk logged.');
redirect('/risks/view.php?id=' . $pdo->lastInsertId());
