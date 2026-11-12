<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/findings/list.php');
}
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
$user = current_user();

$fields = [
    'reference_code' => trim($_POST['reference_code'] ?? ''),
    'project_id' => (int)($_POST['project_id'] ?? 0),
    'source' => trim($_POST['source'] ?? ''),
    'source_date' => $_POST['source_date'] ?? '',
    'evidence_based_finding' => trim($_POST['evidence_based_finding'] ?? ''),
    'lesson_drawn' => trim($_POST['lesson_drawn'] ?? ''),
    'evidence_and_limitations' => trim($_POST['evidence_and_limitations'] ?? ''),
    'implication' => trim($_POST['implication'] ?? ''),
    'management_response' => trim($_POST['management_response'] ?? ''),
    'owner_user_id' => (int)($_POST['owner_user_id'] ?? 0),
];

foreach ($fields as $key => $value) {
    if ($value === '' || $value === 0) {
        flash('error', 'All fields are required. Please complete the form.');
        redirect($id > 0 ? "/findings/form.php?id=$id" : '/findings/form.php');
    }
}

if ($id > 0) {
    // Editing an existing record: deadline is NOT editable here (see revise_deadline.php).
    $stmt = $pdo->prepare(
        "UPDATE findings_register SET
            project_id = ?, source = ?, source_date = ?,
            evidence_based_finding = ?, lesson_drawn = ?, evidence_and_limitations = ?,
            implication = ?, management_response = ?, owner_user_id = ?,
            follow_up_outcome = ?
         WHERE id = ? AND status <> 'Closed'"
    );
    $ok = $stmt->execute([
        $fields['project_id'], $fields['source'], $fields['source_date'],
        $fields['evidence_based_finding'], $fields['lesson_drawn'], $fields['evidence_and_limitations'],
        $fields['implication'], $fields['management_response'], $fields['owner_user_id'],
        trim($_POST['follow_up_outcome'] ?? ''),
        $id,
    ]);
    if ($stmt->rowCount() === 0) {
        flash('error', 'This record could not be updated (it may already be closed).');
    } else {
        flash('success', 'Finding updated.');
    }
    redirect("/findings/view.php?id=$id");
}

// New record
$deadline = $_POST['deadline'] ?? '';
if (!$deadline || !strtotime($deadline)) {
    flash('error', 'A valid deadline is required.');
    redirect('/findings/form.php');
}

$stmt = $pdo->prepare(
    "INSERT INTO findings_register (
        reference_code, project_id, source, source_date,
        evidence_based_finding, lesson_drawn, evidence_and_limitations,
        implication, management_response, owner_user_id,
        original_deadline, current_deadline, status, created_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Open', ?)"
);
try {
    $stmt->execute([
        $fields['reference_code'], $fields['project_id'], $fields['source'], $fields['source_date'],
        $fields['evidence_based_finding'], $fields['lesson_drawn'], $fields['evidence_and_limitations'],
        $fields['implication'], $fields['management_response'], $fields['owner_user_id'],
        $deadline, $deadline, $user['id'],
    ]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        flash('error', 'That reference code is already in use.');
        redirect('/findings/form.php');
    }
    throw $e;
}

flash('success', 'Finding / lesson recorded.');
redirect('/findings/view.php?id=' . $pdo->lastInsertId());
