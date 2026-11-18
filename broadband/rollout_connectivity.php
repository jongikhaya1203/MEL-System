<?php
/**
 * Sets the New / Online / Offline state of one or more rollout sites.
 * POST ids[] + connectivity_status. Called from the list (per-row dropdown and "Set selected to…")
 * and from the map popups. Answers JSON when called with fetch (Accept: application/json), otherwise redirects back.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$wantsJson = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
$respond = function (bool $ok, string $message, int $code = 200, array $extra = []) use ($wantsJson) {
    if ($wantsJson) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok, 'message' => $message] + $extra);
        exit;
    }
    flash($ok ? 'success' : 'error', $message);
    $q = preg_replace('/[^\w=&%.\-+]/', '', (string)($_POST['return_query'] ?? ''));
    redirect('/broadband/rollout_list.php' . ($q !== '' ? '?' . $q : ''));
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(false, 'Invalid request.', 405);
}
if (!can_change_connectivity(current_user()['role'])) {
    $respond(false, 'You do not have permission to change site status.', 403);
}
$token = $_POST['csrf_token'] ?? '';
if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    $respond(false, 'Your session expired — please reload the page and try again.', 400);
}

$status = $_POST['connectivity_status'] ?? '';
if (!isset(connectivity_statuses()[$status])) {
    $respond(false, 'Choose New, Online or Offline.', 422);
}
$ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
if (!$ids) {
    $respond(false, 'No sites were selected.', 422);
}

$in = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("UPDATE broadband_rollout_sites
                       SET connectivity_status = ?, connectivity_changed_at = NOW(), connectivity_changed_by = ?
                       WHERE id IN ($in) AND connectivity_status <> ?");
$stmt->execute(array_merge([$status, (int)current_user()['id']], $ids, [$status]));
$n = $stmt->rowCount();

// Sites without GPS coordinates can't be plotted, so their colour never shows on the map — say so.
$gps = $pdo->prepare("SELECT COUNT(*) FROM broadband_rollout_sites WHERE id IN ($in) AND (latitude IS NULL OR longitude IS NULL)");
$gps->execute($ids);
$noGps = (int)$gps->fetchColumn();

$message = $n . ' site' . ($n === 1 ? '' : 's') . " set to $status.";
if ($noGps) {
    $message .= ' ' . ($noGps === count($ids) && $noGps === 1 ? 'This site has' : "$noGps of the selected sites have")
        . ' no GPS coordinates, so ' . ($noGps === 1 ? 'it' : 'they') . ' can\'t appear on the map — add coordinates on the site\'s Edit form.';
}
$respond(true, $message, 200, [
    'status' => $status, 'color' => connectivity_statuses()[$status]['color'], 'changed' => $n, 'no_gps' => $noGps,
]);
