<?php
require __DIR__ . '/../../config/config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['ok' => false, 'error' => 'POST required.'], 405);
$worker = require_login('maintenance');
$action = (string)($_POST['action'] ?? '');
$reportId = filter_var($_POST['report_id'] ?? null, FILTER_VALIDATE_INT);
if (!$reportId || !in_array($action, ['start', 'complete'], true)) respond(['ok' => false, 'error' => 'Invalid task update.'], 422);
$pdo = db();
$find = $pdo->prepare("SELECT r.id, r.user_id, r.status, r.assigned_lgu_id, r.lgu_assignment_status,
                              COALESCE(repairs.completion_submitted, 0) AS completion_submitted
                       FROM reports r LEFT JOIN assignments a ON a.report_id = r.id AND a.active = 1
                       LEFT JOIN maintenance_teams t ON t.id = a.team_id
                       LEFT JOIN repairs ON repairs.report_id = r.id
                       WHERE r.id = ? AND (
                           (a.team_id = ? AND (
                               t.lgu_id IS NULL
                               OR (t.lgu_id = r.assigned_lgu_id AND r.lgu_assignment_status = 'assigned')
                           ))
                           OR (a.team_id IS NULL AND a.maintenance_user_id = ?)
                       )");
$find->execute([$reportId, $worker['team_id'], $worker['id']]);
$report = $find->fetch();
if (!$report) respond(['ok' => false, 'error' => 'This task is not assigned to your account.'], 403);
$lguTask = $report['lgu_assignment_status'] === 'assigned' && (int)$report['assigned_lgu_id'] === (int)$worker['lgu_id'];

if ($action === 'start') {
    if ($report['status'] !== 'Assigned') respond(['ok' => false, 'error' => 'Only assigned tasks can be started.'], 422);
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE reports SET status = 'In Progress' WHERE id = ?")->execute([$reportId]);
    record_status($pdo, $reportId, (int)$worker['id'], 'In Progress', 'Maintenance work started.');
    notify_reporter($pdo, (int)$report['user_id'], $reportId, 'Repair work has started for ' . report_code($reportId) . '.');
    notify_duplicate_reporters($pdo, $reportId, 'Repair work has started');
    $pdo->commit();
    respond(['ok' => true]);
}

if ($report['status'] !== 'In Progress') respond(['ok' => false, 'error' => 'This repair is not in progress.'], 422);
if ((int)$report['completion_submitted'] === 1) respond(['ok' => false, 'error' => 'Repair evidence has already been submitted for administrator review.'], 409);
$notes = trim((string)($_POST['notes'] ?? ''));
if ($notes === '' || mb_strlen($notes) > 2000) respond(['ok' => false, 'error' => 'Add repair notes (up to 2000 characters).'], 422);
$uploaded = [];
try {
    $uploaded['before'] = image_upload($_FILES['before_photo'] ?? [], 'before');
    $uploaded['after'] = image_upload($_FILES['after_photo'] ?? [], 'after');
} catch (RuntimeException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 422);
}
$storedKeys = [];
$reportCode = report_code($reportId);
try {
    $pdo->beginTransaction();
    $repair = $pdo->prepare("INSERT INTO repairs (report_id, maintenance_user_id, notes, completion_submitted, completed_at)
        VALUES (?, ?, ?, 1, NOW()) ON DUPLICATE KEY UPDATE maintenance_user_id = VALUES(maintenance_user_id), notes = VALUES(notes), completion_submitted = 1, completed_at = NOW()");
    $repair->execute([$reportId, $worker['id'], $notes]);
    // Evidence photos go to the Realtime Database under the report number.
    $photo = $pdo->prepare("INSERT INTO report_photos (report_id, uploaded_by, photo_type, photo_path) VALUES (?, ?, ?, 'pending')");
    $setPhoto = $pdo->prepare('UPDATE report_photos SET photo_path = ? WHERE id = ?');
    foreach (['before', 'after'] as $type) {
        $photo->execute([$reportId, $worker['id'], $type]);
        $photoId = (int)$pdo->lastInsertId();
        $key = $type . '_' . $photoId;
        $path = image_store($reportCode, $key, $uploaded[$type]);
        $storedKeys[] = $key;
        $setPhoto->execute([$path, $photoId]);
    }
    if ($lguTask) {
        $pdo->prepare("UPDATE reports SET status = 'Resolved' WHERE id = ?")->execute([$reportId]);
        record_status($pdo, $reportId, (int)$worker['id'], 'Resolved', 'LGU personnel resolved the pothole and submitted before-and-after photo evidence.');
        notify_reporter($pdo, (int)$report['user_id'], $reportId, 'The pothole in report ' . report_code($reportId) . ' was resolved by the assigned LGU.');
        notify_duplicate_reporters($pdo, $reportId, 'Repair work was completed');
    } else {
        record_status($pdo, $reportId, (int)$worker['id'], 'In Progress', 'Repair submitted for admin verification.');
        notify_reporter($pdo, (int)$report['user_id'], $reportId, 'Repair work for ' . report_code($reportId) . ' is awaiting administrator verification.');
    }
    $pdo->commit();
    respond(['ok' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($storedKeys as $key) { try { rtdb_delete('reportImages/' . $reportCode . '/' . $key); } catch (Throwable $cleanup) { error_log('StreetFix image cleanup failed: ' . $cleanup->getMessage()); } }
    error_log('StreetFix repair update failed: ' . $e->getMessage());
    respond(['ok' => false, 'error' => 'Repair evidence could not be saved. Please try again.'], 500);
}
