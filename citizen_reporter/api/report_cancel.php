<?php
// A citizen cancels one of their own reports, as long as no repair crew has been assigned to it yet.
//  - Not reviewed yet (Submitted): the report is DELETED completely: row, photos, history, notifications, stored images.
//  - Already being reviewed (Under Review / Verified): the report is KEPT and marked "Cancelled", so the admins' records stay complete.
require __DIR__ . '/../../config/config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['ok' => false, 'error' => 'POST required.'], 405);
$user = require_login('citizen');
$input = post_only();
$reportId = filter_var($input['report_id'] ?? null, FILTER_VALIDATE_INT);
if (!$reportId) respond(['ok' => false, 'error' => 'Choose a report to cancel.'], 422);

$pdo = db();
$code = report_code($reportId);
$deleted = false;
try {
    $pdo->beginTransaction();
    // Own report only; checked inside the transaction so two quick requests cannot both succeed.
    $find = $pdo->prepare('SELECT id, status FROM reports WHERE id = ? AND user_id = ?');
    $find->execute([$reportId, $user['id']]);
    $report = $find->fetch();
    if (!$report) {
        $pdo->rollBack();
        respond(['ok' => false, 'error' => 'Report not found.'], 404);
    }
    if ($report['status'] === 'Cancelled') {
        $pdo->rollBack();
        respond(['ok' => false, 'error' => 'This report is already cancelled.'], 409);
    }
    if (!in_array($report['status'], CITIZEN_CANCELLABLE_STATUSES, true)) {
        $pdo->rollBack();
        respond(['ok' => false, 'error' => 'This report can no longer be cancelled because it has already been assigned for repair or closed.'], 409);
    }
    $deleted = in_array($report['status'], CITIZEN_DELETABLE_STATUSES, true);

    // If other people's reports were grouped under this one as the same pothole, the oldest of them
    // becomes the pothole's main report again so it is not lost from the review queue.
    $others = $pdo->prepare('SELECT id FROM reports WHERE duplicate_of = ? AND id <> ? ORDER BY id');
    $others->execute([$reportId, $reportId]);
    $groupIds = array_map('intval', array_column($others->fetchAll(), 'id'));
    if ($groupIds) {
        $newRoot = array_shift($groupIds);
        $pdo->prepare("UPDATE reports SET status = 'Submitted', duplicate_of = NULL WHERE id = ?")->execute([$newRoot]);
        foreach ($groupIds as $memberId) {
            $pdo->prepare('UPDATE reports SET duplicate_of = ? WHERE id = ?')->execute([$newRoot, $memberId]);
        }
        record_status($pdo, $newRoot, null, 'Submitted', 'Became the main report for this pothole after ' . $code . ' was ' . ($deleted ? 'deleted' : 'cancelled') . ' by its reporter.');
    }

    if ($deleted) {
        foreach (['report_photos', 'status_history', 'notifications', 'lgu_notifications', 'assignments'] as $table) {
            $pdo->prepare("DELETE FROM $table WHERE report_id = ?")->execute([$reportId]);
        }
        $pdo->prepare('DELETE FROM reports WHERE id = ? AND user_id = ?')->execute([$reportId, $user['id']]);
    } else {
        $pdo->prepare("UPDATE reports SET status = 'Cancelled', duplicate_of = NULL WHERE id = ?")->execute([$reportId]);
        record_status($pdo, $reportId, (int)$user['id'], 'Cancelled', 'Cancelled by the reporter.');
        notify_reporter($pdo, (int)$user['id'], $reportId, 'Your report ' . $code . ' was cancelled.');
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('StreetFix report cancel failed: ' . $e->getMessage());
    respond(['ok' => false, 'error' => 'The report could not be cancelled. Please try again.'], 500);
}

// The database rows are gone; now remove the stored photos too. A failure here only leaves unreachable images behind.
if ($deleted) {
    try { rtdb_delete('reportImages/' . $code); }
    catch (Throwable $e) { error_log('StreetFix image cleanup failed for ' . $code . ': ' . $e->getMessage()); }
}
respond(['ok' => true, 'code' => $code, 'status' => $deleted ? 'Deleted' : 'Cancelled', 'deleted' => $deleted]);
