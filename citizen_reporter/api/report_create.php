<?php
require __DIR__ . '/../../config/config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['ok' => false, 'error' => 'POST required.'], 405);
}
$user = require_login('citizen');
$road = trim((string)($_POST['road'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$address = trim((string)($_POST['location_address'] ?? ''));
$severity = (string)($_POST['severity'] ?? '');
$latitude = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
$longitude = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);

$errors = [];
if ($road === '' || mb_strlen($road) > 255) $errors[] = 'Road and landmark is required (max 255 characters).';
if (mb_strlen($description) > 1000) $errors[] = 'Description must be no longer than 1000 characters.';
if (mb_strlen($address) > 255) $errors[] = 'Address must be no longer than 255 characters.';
if (!in_array($severity, ['Low', 'Medium', 'High'], true)) $errors[] = 'Choose a valid severity.';
if ($latitude === false || $longitude === false || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
    $errors[] = 'Select a valid location on the map.';
}
if ($errors) respond(['ok' => false, 'error' => implode(' ', $errors)], 422);

$pdo = db();
$fileSet = $_FILES['photos'] ?? null;
if (!$fileSet || !is_array($fileSet['name'] ?? null)) respond(['ok' => false, 'error' => 'Choose at least one pothole photo.'], 422);
$photoCount = count($fileSet['name']);
if ($photoCount < 1 || $photoCount > 5) respond(['ok' => false, 'error' => 'Choose between 1 and 5 pothole photos.'], 422);
$photos = [];
try {
    foreach (array_keys($fileSet['name']) as $index) {
        $photos[] = image_upload([
            'name' => $fileSet['name'][$index],
            'type' => $fileSet['type'][$index],
            'tmp_name' => $fileSet['tmp_name'][$index],
            'error' => $fileSet['error'][$index],
            'size' => $fileSet['size'][$index],
        ], 'report');
    }
} catch (RuntimeException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 422);
}

$code = null;   // report number once the report row exists; used to clean up stored images if anything fails
try {
    // Any still-open report within DUPLICATE_RADIUS_METERS is the same pothole, regardless of how long ago it
    // was filed. Resolved/Rejected/Duplicate/Cancelled reports are skipped so a new pothole at a repaired spot is not hidden.
    $latRange = DUPLICATE_RADIUS_METERS / 111000 * 1.5;
    $lngRange = $latRange / max(cos(deg2rad($latitude)), 0.01);
    $nearby = $pdo->prepare(
        "SELECT id, latitude, longitude FROM reports
         WHERE status NOT IN ('Rejected','Duplicate','Resolved','Cancelled')
         AND latitude BETWEEN ? AND ? AND longitude BETWEEN ? AND ?"
    );
    $nearby->execute([$latitude - $latRange, $latitude + $latRange, $longitude - $lngRange, $longitude + $lngRange]);
    $duplicateOf = null;
    $nearest = DUPLICATE_RADIUS_METERS;
    foreach ($nearby->fetchAll() as $candidate) {
        $latDiff = deg2rad($latitude - (float)$candidate['latitude']);
        $lngDiff = deg2rad($longitude - (float)$candidate['longitude']);
        $a = sin($latDiff / 2) ** 2 + cos(deg2rad($latitude)) * cos(deg2rad((float)$candidate['latitude'])) * sin($lngDiff / 2) ** 2;
        $distance = 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
        if ($distance <= $nearest) {
            $nearest = $distance;
            $duplicateOf = (int)$candidate['id'];
        }
    }

    $status = $duplicateOf ? 'Duplicate' : 'Submitted';
    $settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('agency_name','agency_email')")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    // Pre-fill the responsible agency with the LGU whose boundary contains the pin (falls back to the default agency).
    $lguMatch = lgu_for_coordinates($pdo, (float)$latitude, (float)$longitude);
    $agencyName = $lguMatch['status'] === 'matched' ? $lguMatch['lgu']['name'] : ($settings['agency_name'] ?? null);
    $agencyEmail = $lguMatch['status'] === 'matched' ? null : ($settings['agency_email'] ?? null);
    $pdo->beginTransaction();
    $insert = $pdo->prepare('INSERT INTO reports (user_id, road, description, severity, status, duplicate_of, location_address, responsible_agency, agency_email, latitude, longitude, photo_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $insert->execute([$user['id'], $road, $description ?: null, $severity, $status, $duplicateOf, $address ?: null, $agencyName, $agencyEmail, $latitude, $longitude, 'pending']);
    $reportId = (int)$pdo->lastInsertId();
    $code = report_code($reportId);
    // Photos go to the Realtime Database under the report number; the report row keeps the reference.
    $insertPhoto = $pdo->prepare("INSERT INTO report_photos (report_id, uploaded_by, photo_type, photo_path) VALUES (?, ?, 'report', 'pending')");
    $setPhoto = $pdo->prepare('UPDATE report_photos SET photo_path = ? WHERE id = ?');
    $firstPath = null;
    foreach ($photos as $photo) {
        $insertPhoto->execute([$reportId, $user['id']]);
        $photoId = (int)$pdo->lastInsertId();
        $path = image_store($code, 'report_' . $photoId, $photo);
        $setPhoto->execute([$path, $photoId]);
        $firstPath ??= $path;
    }
    $pdo->prepare('UPDATE reports SET photo_path = ? WHERE id = ?')->execute([$firstPath, $reportId]);
    record_status($pdo, $reportId, (int)$user['id'], $status, $duplicateOf ? 'Automatically flagged as a possible duplicate of ' . report_code($duplicateOf) : null);
    // Citizens are never told a report is a duplicate: it is simply "Submitted" and "reported".
    notify_reporter($pdo, (int)$user['id'], $reportId, 'Your report ' . report_code($reportId) . ' was submitted.');
    $pdo->commit();
    respond(['ok' => true, 'code' => report_code($reportId), 'status' => 'Submitted'], 201);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($code !== null) { try { rtdb_delete('reportImages/' . $code); } catch (Throwable $cleanup) { error_log('StreetFix image cleanup failed: ' . $cleanup->getMessage()); } }
    error_log('StreetFix report creation failed: ' . $e->getMessage());
    respond(['ok' => false, 'error' => 'The report could not be saved. Please try again.'], 500);
}
