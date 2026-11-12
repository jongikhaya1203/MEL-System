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
$stmt = $pdo->prepare('SELECT id FROM indicators WHERE id = ?');
$stmt->execute([$id]);
if (!$stmt->fetch()) {
    flash('error', 'Indicator not found.');
    redirect('/indicators/list.php');
}

$periodLabel = trim($_POST['period_label'] ?? '');
$periodDate = $_POST['period_date'] ?? '';
$actualValue = trim($_POST['actual_value'] ?? '');
$comments = trim($_POST['comments'] ?? '');

if ($periodLabel === '' || !$periodDate || !strtotime($periodDate) || !is_numeric($actualValue)) {
    flash('error', 'Please provide a period label, a valid period end date, and a numeric actual value.');
    redirect('/indicators/view.php?id=' . $id);
}

try {
    $stmt = $pdo->prepare(
        'INSERT INTO indicator_actuals (indicator_id, period_label, period_date, actual_value, comments, reported_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$id, $periodLabel, $periodDate, $actualValue, $comments !== '' ? $comments : null, current_user()['id']]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        flash('error', 'A value for that period label has already been reported for this indicator.');
        redirect('/indicators/view.php?id=' . $id);
    }
    throw $e;
}

// Reaching an actual value doesn't auto-close the indicator; moving it out
// of Open into In Progress (unless already further along) reflects that
// data collection has started.
$pdo->prepare("UPDATE indicators SET status = 'In Progress' WHERE id = ? AND status = 'Open'")->execute([$id]);

flash('success', 'Reported period added.');
redirect('/indicators/view.php?id=' . $id);
