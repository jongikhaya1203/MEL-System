<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/planning/list.php');
}
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM installer_allocations WHERE id = ?');
$stmt->execute([$id]);
$allocation = $stmt->fetch();
if (!$allocation) {
    flash('error', 'Allocation not found.');
    redirect('/planning/list.php');
}

$role = current_user()['role'];
$canLogProgress = in_array($role, ['admin', 'manager', 'back_office'], true)
    || ($role === 'field_worker' && (int)$allocation['installer_id'] === (int)current_user()['id']);
if (!$canLogProgress) {
    http_response_code(403);
    die('You do not have permission to report progress on this allocation.');
}
if ($allocation['status'] === 'Completed') {
    flash('error', 'This allocation has already reached its target; no further progress is needed.');
    redirect('/planning/view.php?id=' . $id);
}

$periodLabel = trim($_POST['period_label'] ?? '');
$periodDate = $_POST['period_date'] ?? '';
$installationsCompleted = trim($_POST['installations_completed'] ?? '');
$comments = trim($_POST['comments'] ?? '');

if ($periodLabel === '' || !$periodDate || !strtotime($periodDate) || !ctype_digit($installationsCompleted)) {
    flash('error', 'Please provide a period label, a valid period date, and a whole-number installation count.');
    redirect('/planning/view.php?id=' . $id);
}
$installationsCompleted = (int)$installationsCompleted;

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO allocation_progress_updates (allocation_id, period_label, period_date, installations_completed, comments, reported_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    try {
        $stmt->execute([$id, $periodLabel, $periodDate, $installationsCompleted, $comments !== '' ? $comments : null, current_user()['id']]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            $pdo->rollBack();
            flash('error', 'A progress update for that period label has already been reported for this allocation.');
            redirect('/planning/view.php?id=' . $id);
        }
        throw $e;
    }

    $newStatus = $installationsCompleted >= (int)$allocation['target_installations']
        ? 'Completed'
        : (strtotime($allocation['current_deadline']) < strtotime(date('Y-m-d')) ? 'Overdue' : 'In Progress');
    $pdo->prepare("UPDATE installer_allocations SET status = ? WHERE id = ? AND status <> 'Completed'")
        ->execute([$newStatus, $id]);

    // Report update to the Results Framework: if this allocation is linked to an
    // indicator, the same period's cumulative count also becomes that indicator's
    // actual value for the period — one-way sync, Planning -> Results Framework.
    if ($allocation['indicator_id']) {
        $autoComment = "Auto-reported from Planning allocation {$allocation['reference_code']}."
            . ($comments !== '' ? ' ' . $comments : '');
        try {
            $ins = $pdo->prepare(
                'INSERT INTO indicator_actuals (indicator_id, period_label, period_date, actual_value, comments, reported_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([$allocation['indicator_id'], $periodLabel, $periodDate, $installationsCompleted, $autoComment, current_user()['id']]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $upd = $pdo->prepare(
                    'UPDATE indicator_actuals SET actual_value = ?, comments = ?, period_date = ?
                     WHERE indicator_id = ? AND period_label = ?'
                );
                $upd->execute([$installationsCompleted, $autoComment, $periodDate, $allocation['indicator_id'], $periodLabel]);
            } else {
                throw $e;
            }
        }
        $pdo->prepare("UPDATE indicators SET status = 'In Progress' WHERE id = ? AND status = 'Open'")
            ->execute([$allocation['indicator_id']]);
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    throw $e;
}

flash('success', 'Progress update added.' . ($allocation['indicator_id'] ? ' The linked Results Framework indicator was also updated.' : ''));
redirect('/planning/view.php?id=' . $id);
