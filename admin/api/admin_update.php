<?php
require __DIR__ . '/../../config/config.php';
$admin = require_login('admin');
$input = post_only();
$action = (string)($input['action'] ?? '');
$reportId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
$status = (string)($input['status'] ?? '');
$teamId = filter_var($input['team_id'] ?? null, FILTER_VALIDATE_INT);
if ($action === 'reassign') {
    if (!$reportId || !$teamId) respond(['ok' => false, 'error' => 'Choose a report and maintenance team.'], 422);
    $pdo = db();
    $find = $pdo->prepare('SELECT user_id, status FROM reports WHERE id = ?');
    $find->execute([$reportId]);
    $report = $find->fetch();
    if (!$report || !in_array($report['status'], ['Assigned', 'In Progress'], true)) {
        respond(['ok' => false, 'error' => 'Only active repair tasks can be reassigned.'], 422);
    }
    $worker = $pdo->prepare("SELECT u.id, t.name AS team_name FROM users u JOIN maintenance_teams t ON t.id = u.team_id
                             WHERE u.team_id = ? AND u.role = 'maintenance' AND u.is_active = 1 ORDER BY u.id LIMIT 1");
    $worker->execute([$teamId]);
    $workerRow = $worker->fetch();
    if (!$workerRow) respond(['ok' => false, 'error' => 'That team has no active maintenance members.'], 422);
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE assignments SET active = 0 WHERE report_id = ?')->execute([$reportId]);
    $pdo->prepare('INSERT INTO assignments (report_id, maintenance_user_id, assigned_by, team_id) VALUES (?, ?, ?, ?)')
        ->execute([$reportId, $workerRow['id'], $admin['id'], $teamId]);
    record_status($pdo, $reportId, (int)$admin['id'], (string)$report['status'], 'Reassigned to ' . $workerRow['team_name'] . '.');
    notify_reporter($pdo, (int)$report['user_id'], $reportId, 'Repair work for ' . report_code($reportId) . ' was reassigned to another maintenance team.');
    $pdo->commit();
    respond(['ok' => true]);
}
if ($action === 'auto_review') {
    // Opening the Reports tab moves every newly submitted report to Under Review. Three set-based statements bounded
    // by the highest id seen at the start, so it stays fast with many reports and cannot skip a report it logged.
    $pdo = db();
    $max = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM reports WHERE status = 'Submitted'")->fetchColumn();
    if ($max === 0) respond(['ok' => true, 'moved' => 0]);
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO status_history (report_id, changed_by, status, note)
                   SELECT id, ?, 'Under Review', 'Automatically moved to Under Review when an administrator opened Reports.'
                   FROM reports WHERE status = 'Submitted' AND id <= ?")->execute([$admin['id'], $max]);
    $pdo->prepare("INSERT INTO notifications (user_id, report_id, message)
                   SELECT user_id, id, CONCAT('Your report SF-', 1000 + id, ' is now: Under Review.')
                   FROM reports WHERE status = 'Submitted' AND id <= ?")->execute([$max]);
    $moved = $pdo->prepare("UPDATE reports SET status = 'Under Review' WHERE status = 'Submitted' AND id <= ?");
    $moved->execute([$max]);
    $pdo->commit();
    respond(['ok' => true, 'moved' => $moved->rowCount()]);
}
if ($action === 'merge_duplicate') {
    // Manually group a report under another one (for duplicates further apart than the automatic radius).
    $into = (string)($input['into'] ?? '');
    if (!$reportId || !preg_match('/^SF-(\d+)$/i', trim($into), $m)) respond(['ok' => false, 'error' => 'Enter the report ID to group this under, for example SF-1001.'], 422);
    $pdo = db();
    $find = $pdo->prepare('SELECT id, user_id, status FROM reports WHERE id = ?');
    $find->execute([$reportId]);
    $report = $find->fetch();
    $find->execute([(int)$m[1] - 1000]);
    $target = $find->fetch();
    if (!$report || !$target) respond(['ok' => false, 'error' => 'Report not found.'], 404);
    $rootStmt = $pdo->prepare('SELECT COALESCE(duplicate_of, id) FROM reports WHERE id = ?');
    $rootStmt->execute([$target['id']]);
    $rootId = (int)$rootStmt->fetchColumn();
    if ($rootId === $reportId) respond(['ok' => false, 'error' => 'A report cannot be grouped under itself.'], 422);
    $rootState = $pdo->prepare('SELECT status FROM reports WHERE id = ?');
    $rootState->execute([$rootId]);
    if ($rootState->fetchColumn() === 'Cancelled') respond(['ok' => false, 'error' => 'That report was cancelled by its reporter.'], 422);
    if (!in_array($report['status'], ['Submitted', 'Under Review'], true)) respond(['ok' => false, 'error' => 'Only reports still under review can be grouped as duplicates.'], 422);
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE reports SET status = 'Duplicate', duplicate_of = ? WHERE id = ?")->execute([$rootId, $reportId]);
    $pdo->prepare('UPDATE reports SET duplicate_of = ? WHERE duplicate_of = ?')->execute([$rootId, $reportId]);
    record_status($pdo, $reportId, (int)$admin['id'], 'Duplicate', 'Marked as a duplicate of ' . report_code($rootId) . ' by administrator.');
    notify_reporter($pdo, (int)$report['user_id'], $reportId, 'Your report ' . report_code($reportId) . ' was grouped with ' . report_code($rootId) . ' as the same pothole.');
    $pdo->commit();
    respond(['ok' => true]);
}
if ($action === 'confirm_duplicate') {
    if (!$reportId) respond(['ok' => false, 'error' => 'Choose a report.'], 422);
    $pdo = db();
    $find = $pdo->prepare('SELECT user_id, status, duplicate_of FROM reports WHERE id = ?');
    $find->execute([$reportId]);
    $report = $find->fetch();
    if (!$report || $report['status'] !== 'Duplicate') respond(['ok' => false, 'error' => 'Only reports flagged as duplicates can be confirmed.'], 422);
    $pdo->beginTransaction();
    record_status($pdo, $reportId, (int)$admin['id'], 'Duplicate', 'Duplicate confirmed by administrator' . ($report['duplicate_of'] ? ' (of ' . report_code((int)$report['duplicate_of']) . ')' : '') . '.');
    $pdo->commit();
    respond(['ok' => true]);
}
if ($action === 'manual_assign_lgu') {
    $lguId = filter_var($input['lgu_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$reportId || !$lguId) respond(['ok' => false, 'error' => 'Choose a report and an LGU.'], 422);
    $pdo = db();
    $find = $pdo->prepare('SELECT user_id, status, lgu_assignment_status FROM reports WHERE id = ?');
    $find->execute([$reportId]);
    $report = $find->fetch();
    $lguStmt = $pdo->prepare('SELECT id, name FROM lgus WHERE id = ?');
    $lguStmt->execute([$lguId]);
    $lgu = $lguStmt->fetch();
    if (!$report || $report['status'] !== 'Verified' || $report['lgu_assignment_status'] !== 'manual_review' || !$lgu) {
        respond(['ok' => false, 'error' => 'Only verified reports flagged for manual location review can be assigned this way.'], 422);
    }
    $pdo->beginTransaction();
    $team = ensure_lgu_maintenance_team($pdo, (int)$lguId);
    $worker = $pdo->prepare("SELECT id FROM users WHERE team_id = ? AND role = 'maintenance' AND is_active = 1 ORDER BY id LIMIT 1");
    $worker->execute([$team['id']]);
    $workerId = $worker->fetchColumn() ?: null;
    $pdo->prepare('UPDATE assignments SET active = 0 WHERE report_id = ?')->execute([$reportId]);
    $pdo->prepare('INSERT INTO assignments (report_id, maintenance_user_id, assigned_by, team_id) VALUES (?, ?, ?, ?)')
        ->execute([$reportId, $workerId, $admin['id'], $team['id']]);
    $pdo->prepare("UPDATE reports SET status = 'Assigned', assigned_lgu_id = ?, lgu_assignment_status = 'assigned',
                   lgu_assignment_note = NULL, responsible_agency = ?, agency_email = NULL WHERE id = ?")
        ->execute([$lguId, $lgu['name'], $reportId]);
    record_status($pdo, $reportId, (int)$admin['id'], 'Assigned', 'Manually assigned to ' . $lgu['name'] . ' after location review.');
    notify_lgu($pdo, (int)$lguId, $reportId, report_code($reportId) . ' was manually assigned to ' . $lgu['name'] . '.');
    notify_reporter($pdo, (int)$report['user_id'], $reportId, 'Your report ' . report_code($reportId) . ' was assigned to ' . $lgu['name'] . '.');
    $pdo->commit();
    respond(['ok' => true]);
}
if ($action === 'route') {
    $agency = trim((string)($input['agency'] ?? ''));
    $email = strtolower(trim((string)($input['agency_email'] ?? '')));
    if (!$reportId || $agency === '' || mb_strlen($agency) > 190 ||
        ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190))) {
        respond(['ok' => false, 'error' => 'Enter a responsible agency and a valid contact email (if available).'], 422);
    }
    $pdo = db();
    $find = $pdo->prepare('SELECT user_id, status FROM reports WHERE id = ?');
    $find->execute([$reportId]);
    $report = $find->fetch();
    if (!$report) respond(['ok' => false, 'error' => 'Report not found.'], 404);
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE reports SET responsible_agency = ?, agency_email = ? WHERE id = ?')
        ->execute([$agency, $email ?: null, $reportId]);
    record_status($pdo, $reportId, (int)$admin['id'], (string)$report['status'], 'Forwarded to ' . $agency . ($email ? ' (' . $email . ')' : '') . '.');
    notify_reporter($pdo, (int)$report['user_id'], $reportId, 'Your report ' . report_code($reportId) . ' was routed to ' . $agency . '.');
    $pdo->commit();
    respond(['ok' => true]);
}
if (!$reportId || !in_array($status, REPORT_STATUSES, true)) respond(['ok' => false, 'error' => 'Choose a valid report and status.'], 422);

$pdo = db();
$find = $pdo->prepare('SELECT id, user_id, status, latitude, longitude FROM reports WHERE id = ?');
$find->execute([$reportId]);
$report = $find->fetch();
if (!$report) respond(['ok' => false, 'error' => 'Report not found.'], 404);

$allowed = [
    'Submitted' => ['Under Review', 'Verified', 'Rejected'],
    'Under Review' => ['Verified', 'Rejected'],
    'Verified' => ['Assigned', 'Verified'],
    'Assigned' => [],
    'In Progress' => ['Resolved'],
    'Resolved' => [],
    'Rejected' => [],
    'Duplicate' => ['Under Review', 'Verified'],
    'Cancelled' => [],
];
if (!in_array($status, $allowed[$report['status']] ?? [], true)) {
    respond(['ok' => false, 'error' => 'That status change is not allowed.'], 422);
}

$assignee = null;
$note = null;
$matchedLgu = null;
$assignmentNote = null;
$nextStatus = $status;
if ($status === 'Verified') {
    $match = lgu_for_coordinates($pdo, (float)$report['latitude'], (float)$report['longitude']);
    if ($match['status'] === 'matched') {
        $matchedLgu = $match['lgu'];
        $nextStatus = 'Assigned';
    } else {
        $assignmentNote = $match['status'] === 'outside'
            ? 'GPS location is outside the Bulacan LGU boundaries.'
            : 'GPS location intersects more than one LGU boundary.';
    }
}
if ($report['status'] === 'Duplicate' && in_array($status, ['Under Review', 'Verified'], true)) $note = 'Reviewed by administrator: not a duplicate.';
if ($status === 'Assigned') {
    if (!$teamId) respond(['ok' => false, 'error' => 'Select a maintenance team to assign.'], 422);
    $worker = $pdo->prepare("SELECT id FROM users WHERE team_id = ? AND role = 'maintenance' AND is_active = 1 ORDER BY id LIMIT 1");
    $worker->execute([$teamId]);
    $assignee = $worker->fetchColumn();
    if (!$assignee) respond(['ok' => false, 'error' => 'That team has no active maintenance members.'], 422);
    $teamName = $pdo->prepare('SELECT name FROM maintenance_teams WHERE id = ?');
    $teamName->execute([$teamId]);
    $note = 'Assigned to ' . $teamName->fetchColumn() . '.';
}
if ($status === 'Resolved') {
    $repair = $pdo->prepare("SELECT completion_submitted FROM repairs WHERE report_id = ? AND completion_submitted = 1");
    $repair->execute([$reportId]);
    if (!$repair->fetchColumn()) respond(['ok' => false, 'error' => 'The assigned worker must submit repair notes and before-and-after photos first.'], 422);
}

$pdo->beginTransaction();
if ($status === 'Assigned') {
    $pdo->prepare('UPDATE assignments SET active = 0 WHERE report_id = ?')->execute([$reportId]);
    $pdo->prepare('INSERT INTO assignments (report_id, maintenance_user_id, assigned_by, team_id) VALUES (?, ?, ?, ?)')->execute([$reportId, $assignee, $admin['id'], $teamId]);
}
if ($status === 'Verified' && $matchedLgu) {
    $pdo->prepare('UPDATE assignments SET active = 0 WHERE report_id = ?')->execute([$reportId]);
    $team = ensure_lgu_maintenance_team($pdo, (int)$matchedLgu['id']);
    $worker = $pdo->prepare("SELECT id FROM users WHERE team_id = ? AND role = 'maintenance' AND is_active = 1 ORDER BY id LIMIT 1");
    $worker->execute([$team['id']]);
    $workerId = $worker->fetchColumn() ?: null;
    $pdo->prepare('INSERT INTO assignments (report_id, maintenance_user_id, assigned_by, team_id) VALUES (?, ?, ?, ?)')
        ->execute([$reportId, $workerId, $admin['id'], $team['id']]);
    $pdo->prepare("UPDATE reports SET status = 'Assigned', assigned_lgu_id = ?, lgu_assignment_status = 'assigned',
                   lgu_assignment_note = NULL, responsible_agency = ?, agency_email = NULL WHERE id = ?")
        ->execute([$matchedLgu['id'], $matchedLgu['name'], $reportId]);
    $note = 'GPS coordinates matched the stored Bulacan boundary for ' . $matchedLgu['name'] . '.';
} elseif ($status === 'Verified') {
    $pdo->prepare("UPDATE reports SET status = 'Verified', assigned_lgu_id = NULL, lgu_assignment_status = 'manual_review',
                   lgu_assignment_note = ? WHERE id = ?")->execute([$assignmentNote, $reportId]);
    $note = 'Verified; flagged for manual LGU review. ' . $assignmentNote;
} else {
    $pdo->prepare('UPDATE reports SET status = ? WHERE id = ?')->execute([$nextStatus, $reportId]);
}
// An admin who clears a duplicate flag (back to review / verified) removes the link so it stops counting toward the original.
if ($report['status'] === 'Duplicate' && in_array($status, ['Under Review', 'Verified'], true)) {
    $pdo->prepare('UPDATE reports SET duplicate_of = NULL WHERE id = ?')->execute([$reportId]);
}
if ($status === 'Resolved') {
    $pdo->prepare('UPDATE repairs SET completed_at = COALESCE(completed_at, NOW()) WHERE report_id = ?')->execute([$reportId]);
    notify_duplicate_reporters($pdo, $reportId, 'Repair work was completed and verified');
}
if ($status === 'Verified' && $matchedLgu) {
    record_status($pdo, $reportId, (int)$admin['id'], 'Verified', 'Administrator verified the report.');
    record_status($pdo, $reportId, (int)$admin['id'], 'Assigned', $note);
    notify_lgu($pdo, (int)$matchedLgu['id'], $reportId, 'New pothole report ' . report_code($reportId) . ' was assigned to ' . $matchedLgu['name'] . '.');
    notify_reporter($pdo, (int)$report['user_id'], $reportId, 'Your verified report ' . report_code($reportId) . ' was assigned to ' . $matchedLgu['name'] . '.');
} else {
    record_status($pdo, $reportId, (int)$admin['id'], $nextStatus, $note);
    $message = $status === 'Verified' && !$matchedLgu
        ? 'Your report ' . report_code($reportId) . ' was verified, but its location needs manual LGU review.'
        : 'Your report ' . report_code($reportId) . ' is now: ' . $nextStatus . '.';
    notify_reporter($pdo, (int)$report['user_id'], $reportId, $message);
}
$pdo->commit();
respond(['ok' => true]);
