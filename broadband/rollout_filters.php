<?php
/**
 * Shared filter logic for the rollout list and map, so "View on map" shows exactly the sites the list shows.
 * Reads $_GET and sets: $f (the filter values), $where (SQL conditions on alias r), $params, $filterQuery (query string).
 */
$f = [
    'status'       => $_GET['status'] ?? '',
    'project_id'   => $_GET['project_id'] ?? '',
    'province'     => $_GET['province'] ?? '',
    'connectivity' => $_GET['connectivity'] ?? '',
    'q'            => trim($_GET['q'] ?? ''),
    'all'          => !empty($_GET['all']) ? '1' : '',
];

$where = [];
$params = [];
if (in_array($f['status'], rollout_statuses(), true)) {
    $where[] = 'r.status = ?';
    $params[] = $f['status'];
} elseif (!$f['all']) {
    // Default view = still awaiting rollout (not yet rolled out or cancelled).
    $where[] = "r.status NOT IN ('Rolled Out', 'Cancelled')";
}
if ($f['project_id'] !== '') {
    $where[] = 'r.project_id = ?';
    $params[] = (int)$f['project_id'];
}
if ($f['province'] !== '' && in_array($f['province'], sa_provinces(), true)) {
    $where[] = 'r.province = ?';
    $params[] = $f['province'];
}
if (isset(connectivity_statuses()[$f['connectivity']])) {
    $where[] = 'r.connectivity_status = ?';
    $params[] = $f['connectivity'];
}
if ($f['q'] !== '') {
    $where[] = '(r.reference_code LIKE ? OR r.site_name LIKE ? OR r.official_site_id LIKE ? OR r.town_village LIKE ? OR r.local_municipality LIKE ? OR r.contact_details LIKE ?)';
    $like = '%' . $f['q'] . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
$filterQuery = http_build_query(array_filter($f, fn($v) => $v !== ''));
