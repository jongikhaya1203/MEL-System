<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/indicators/list.php');
}
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
$user = current_user();

$LEVELS = ['Impact', 'Outcome', 'Output'];
$FREQUENCIES = ['Monthly', 'Quarterly', 'Biannual', 'Annual'];

$resultLevel = $_POST['result_level'] ?? '';
$frequency = $_POST['collection_frequency'] ?? '';

if (!in_array($resultLevel, $LEVELS, true) || !in_array($frequency, $FREQUENCIES, true)) {
    flash('error', 'Please choose a valid result level and collection frequency.');
    redirect($id > 0 ? "/indicators/form.php?id=$id" : '/indicators/form.php');
}

$baselineValue = trim($_POST['baseline_value'] ?? '');
$baselineYear = trim($_POST['baseline_year'] ?? '');
$targetValue = trim($_POST['target_value'] ?? '');

if (!is_numeric($baselineValue) || !is_numeric($targetValue) || !ctype_digit($baselineYear)) {
    flash('error', 'Baseline value, baseline year and target value must be numeric.');
    redirect($id > 0 ? "/indicators/form.php?id=$id" : '/indicators/form.php');
}

$fields = [
    'reference_code' => trim($_POST['reference_code'] ?? ''),
    'project_id' => (int)($_POST['project_id'] ?? 0),
    'result_level' => $resultLevel,
    'result_statement' => trim($_POST['result_statement'] ?? ''),
    'indicator_name' => trim($_POST['indicator_name'] ?? ''),
    'definition' => trim($_POST['definition'] ?? ''),
    'unit_of_measure' => trim($_POST['unit_of_measure'] ?? ''),
    'data_source' => trim($_POST['data_source'] ?? ''),
    'collection_frequency' => $frequency,
    'responsible_user_id' => (int)($_POST['responsible_user_id'] ?? 0),
];

foreach ($fields as $key => $value) {
    if ($value === '' || $value === 0) {
        flash('error', 'All fields are required. Please complete the form.');
        redirect($id > 0 ? "/indicators/form.php?id=$id" : '/indicators/form.php');
    }
}

if ($id > 0) {
    // Editing an existing record: target date is NOT editable here (see revise_target.php).
    $stmt = $pdo->prepare(
        "UPDATE indicators SET
            project_id = ?, result_level = ?, result_statement = ?, indicator_name = ?,
            definition = ?, unit_of_measure = ?, data_source = ?, collection_frequency = ?,
            baseline_value = ?, baseline_year = ?, target_value = ?,
            responsible_user_id = ?, achievement_notes = ?
         WHERE id = ?"
    );
    $stmt->execute([
        $fields['project_id'], $fields['result_level'], $fields['result_statement'], $fields['indicator_name'],
        $fields['definition'], $fields['unit_of_measure'], $fields['data_source'], $fields['collection_frequency'],
        $baselineValue, $baselineYear, $targetValue,
        $fields['responsible_user_id'], trim($_POST['achievement_notes'] ?? ''),
        $id,
    ]);
    flash('success', 'Indicator updated.');
    redirect("/indicators/view.php?id=$id");
}

// New record
$targetDate = $_POST['target_date'] ?? '';
if (!$targetDate || !strtotime($targetDate)) {
    flash('error', 'A valid target date is required.');
    redirect('/indicators/form.php');
}

$stmt = $pdo->prepare(
    "INSERT INTO indicators (
        reference_code, project_id, result_level, result_statement, indicator_name,
        definition, unit_of_measure, data_source, collection_frequency,
        baseline_value, baseline_year, target_value,
        responsible_user_id, original_target_date, current_target_date, status, created_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Open', ?)"
);
try {
    $stmt->execute([
        $fields['reference_code'], $fields['project_id'], $fields['result_level'], $fields['result_statement'], $fields['indicator_name'],
        $fields['definition'], $fields['unit_of_measure'], $fields['data_source'], $fields['collection_frequency'],
        $baselineValue, $baselineYear, $targetValue,
        $fields['responsible_user_id'], $targetDate, $targetDate, $user['id'],
    ]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        flash('error', 'That reference code is already in use.');
        redirect('/indicators/form.php');
    }
    throw $e;
}

flash('success', 'Indicator created.');
redirect('/indicators/view.php?id=' . $pdo->lastInsertId());
