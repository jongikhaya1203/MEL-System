<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'manager', 'field_worker', 'indigent_capturer']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/indigent/list.php');
}
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
$user = current_user();

$fields = [
    'reference_code' => trim($_POST['reference_code'] ?? ''),
    'first_name' => trim($_POST['first_name'] ?? ''),
    'surname' => trim($_POST['surname'] ?? ''),
    'province' => trim($_POST['province'] ?? ''),
    'district_municipality' => trim($_POST['district_municipality'] ?? ''),
    'local_municipality' => trim($_POST['local_municipality'] ?? ''),
    'village_name' => trim($_POST['village_name'] ?? ''),
];
$backUrl = $id > 0 ? "/indigent/form.php?id=$id" : '/indigent/form.php';

foreach ($fields as $value) {
    if ($value === '') {
        flash('error', 'All required fields must be completed.');
        redirect($backUrl);
    }
}
if (!in_array($fields['province'], sa_provinces(), true)) {
    flash('error', 'Please select a valid province.');
    redirect($backUrl);
}
if (!sa_validate_municipality($fields['province'], $fields['district_municipality'], $fields['local_municipality'])) {
    flash('error', 'Please select a valid district and local municipality for the chosen province.');
    redirect($backUrl);
}

$idNumberRaw = trim($_POST['id_number'] ?? '');
$idCheck = validate_sa_id_number($idNumberRaw);
if (!$idCheck['valid']) {
    flash('error', $idCheck['error']);
    redirect($backUrl);
}
$idNumber = preg_replace('/\D/', '', $idNumberRaw);
$idCaptureMethod = ($_POST['id_capture_method'] ?? 'manual') === 'scanned' ? 'scanned' : 'manual';

$numberOfPupilsRaw = trim($_POST['number_of_pupils'] ?? '0');
if (!ctype_digit($numberOfPupilsRaw)) {
    flash('error', 'Number of pupils must be a whole number.');
    redirect($backUrl);
}
$monthlyIncomeRaw = trim($_POST['monthly_income'] ?? '');
if (!is_numeric($monthlyIncomeRaw)) {
    flash('error', 'Monthly income must be a number.');
    redirect($backUrl);
}

function nullable_decimal($raw)
{
    $raw = trim((string)$raw);
    return ($raw !== '' && is_numeric($raw)) ? $raw : null;
}

$latitude = nullable_decimal($_POST['latitude'] ?? '');
$longitude = nullable_decimal($_POST['longitude'] ?? '');
$distanceNorth = nullable_decimal($_POST['distance_north_km'] ?? '');
$distanceSouth = nullable_decimal($_POST['distance_south_km'] ?? '');
$distanceEast = nullable_decimal($_POST['distance_east_km'] ?? '');
$distanceWest = nullable_decimal($_POST['distance_west_km'] ?? '');
$physicalAddress = trim($_POST['physical_address'] ?? '');
$hasTv = isset($_POST['has_functional_tv']) ? 1 : 0;

$declaresSA = isset($_POST['declares_south_african']) ? 1 : 0;
$declaresIncome = isset($_POST['declares_income_under_3200']) ? 1 : 0;
$declaresConcession = isset($_POST['declares_sabc_concession']) ? 1 : 0;
$declaresGrant = isset($_POST['declares_grant_with_proof']) ? 1 : 0;
if (!$declaresSA || !$declaresIncome || !$declaresConcession || !$declaresGrant) {
    flash('error', 'All declaration items must be confirmed before the application can be captured.');
    redirect($backUrl);
}

$signatureDataUrl = $_POST['signature_data'] ?? '';
$signatureFilename = $signatureDataUrl !== '' ? store_signature_data_url($signatureDataUrl) : null;
if (!$signatureFilename) {
    flash('error', 'A signature is required — please have the applicant sign before submitting.');
    redirect($backUrl);
}

$photoError = null;
$newPhotoFilename = store_household_photo_upload($_FILES['household_photo'] ?? [], $photoError);
if ($photoError) {
    flash('error', $photoError);
    redirect($backUrl);
}

if ($id > 0) {
    $current = $pdo->prepare('SELECT household_photo_filename FROM indigent_applications WHERE id = ?');
    $current->execute([$id]);
    $currentRow = $current->fetch();
    if (!$currentRow) {
        flash('error', 'Application not found.');
        redirect('/indigent/list.php');
    }
    $photoFilename = $newPhotoFilename ?: $currentRow['household_photo_filename'];
    if ($newPhotoFilename && $currentRow['household_photo_filename']) {
        $oldPath = INDIGENT_STORAGE_DIR . $currentRow['household_photo_filename'];
        if (is_file($oldPath)) {
            unlink($oldPath);
        }
    }

    $stmt = $pdo->prepare(
        "UPDATE indigent_applications SET
            first_name=?, surname=?, id_number=?, id_capture_method=?,
            latitude=?, longitude=?, household_photo_filename=?,
            province=?, district_municipality=?, local_municipality=?, village_name=?, physical_address=?,
            number_of_pupils=?, distance_north_km=?, distance_south_km=?, distance_east_km=?, distance_west_km=?,
            monthly_income=?, has_functional_tv=?,
            declares_south_african=?, declares_income_under_3200=?, declares_sabc_concession=?, declares_grant_with_proof=?,
            signature_filename=?, signed_at=NOW()
         WHERE id=?"
    );
    try {
        $stmt->execute([
            $fields['first_name'], $fields['surname'], $idNumber, $idCaptureMethod,
            $latitude, $longitude, $photoFilename,
            $fields['province'], $fields['district_municipality'], $fields['local_municipality'], $fields['village_name'], $physicalAddress !== '' ? $physicalAddress : null,
            (int)$numberOfPupilsRaw, $distanceNorth, $distanceSouth, $distanceEast, $distanceWest,
            $monthlyIncomeRaw, $hasTv,
            $declaresSA, $declaresIncome, $declaresConcession, $declaresGrant,
            $signatureFilename,
            $id,
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            flash('error', 'That ID number is already captured on another application.');
            redirect($backUrl);
        }
        throw $e;
    }
    flash('success', 'Application updated.');
    redirect('/indigent/view.php?id=' . $id);
}

try {
    $stmt = $pdo->prepare(
        "INSERT INTO indigent_applications (
            reference_code, first_name, surname, id_number, id_capture_method,
            latitude, longitude, household_photo_filename,
            province, district_municipality, local_municipality, village_name, physical_address,
            number_of_pupils, distance_north_km, distance_south_km, distance_east_km, distance_west_km,
            monthly_income, has_functional_tv,
            declares_south_african, declares_income_under_3200, declares_sabc_concession, declares_grant_with_proof,
            signature_filename, signed_at, captured_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)"
    );
    $stmt->execute([
        $fields['reference_code'], $fields['first_name'], $fields['surname'], $idNumber, $idCaptureMethod,
        $latitude, $longitude, $newPhotoFilename,
        $fields['province'], $fields['district_municipality'], $fields['local_municipality'], $fields['village_name'], $physicalAddress !== '' ? $physicalAddress : null,
        (int)$numberOfPupilsRaw, $distanceNorth, $distanceSouth, $distanceEast, $distanceWest,
        $monthlyIncomeRaw, $hasTv,
        $declaresSA, $declaresIncome, $declaresConcession, $declaresGrant,
        $signatureFilename, $user['id'],
    ]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        flash('error', 'That reference code or ID number is already in use.');
        redirect('/indigent/form.php');
    }
    throw $e;
}

flash('success', 'Application captured.');
redirect('/indigent/view.php?id=' . $pdo->lastInsertId());
