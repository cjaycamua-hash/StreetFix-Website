<?php
// The logged-in citizen's own reports, newest first.
require __DIR__ . '/../../config/config.php';
$user = require_login('citizen');

$stmt = db()->prepare('SELECT r.*, f.rating, f.comments AS feedback_comments, p.status AS group_status,
                              l.name AS lgu_name,
                              (SELECT COUNT(*) FROM reports s WHERE s.duplicate_of = COALESCE(r.duplicate_of, r.id)) + 1 AS group_count
                       FROM reports r LEFT JOIN feedback f ON f.report_id = r.id
                       LEFT JOIN reports p ON p.id = r.duplicate_of
                       LEFT JOIN lgus l ON l.id = r.assigned_lgu_id
                       WHERE r.user_id = ? ORDER BY r.id DESC');
$stmt->execute([$user['id']]);
$rows = $stmt->fetchAll();
$photoStmt = db()->prepare("SELECT photo_path FROM report_photos WHERE report_id = ? AND photo_type = 'report' ORDER BY id");
$reports = array_map(static function (array $r) use ($photoStmt): array {
    $photoStmt->execute([$r['id']]);
    $photos = array_map(static fn(array $photo): string => '../' . ltrim($photo['photo_path'], '/'), $photoStmt->fetchAll());
    if (!$photos) $photos[] = '../' . ltrim($r['photo_path'], '/');
    return [
        'id' => (int)$r['id'], 'code' => report_code((int)$r['id']), 'road' => $r['road'],
        'description' => $r['description'] ?? '', 'severity' => $r['severity'],
        'status' => $r['status'] === 'Duplicate' ? 'Submitted' : $r['status'],   // citizens never see "Duplicate"
        'lat' => (float)$r['latitude'], 'lng' => (float)$r['longitude'],
        'location_address' => $r['location_address'] ?? '',
        'assigned_lgu' => $r['lgu_assignment_status'] === 'assigned' ? ($r['lgu_name'] ?? '') : '',
        'lgu_assignment_status' => $r['lgu_assignment_status'],
        'lgu_assignment_note' => $r['lgu_assignment_note'] ?? '',
        'agency' => $r['responsible_agency'] ?? '', 'agency_email' => $r['agency_email'] ?? '',
        'photo' => '../' . ltrim($r['photo_path'], '/'), 'date' => substr((string)$r['created_at'], 0, 10),
        'photos' => $photos,
        // Only used by "View on Map" so a grouped report opens its pothole's pin; never displayed.
        'map_code' => report_code((int)($r['duplicate_of'] ?: $r['id'])),
        'rating' => $r['rating'] === null ? null : (int)$r['rating'],
        'can_cancel' => in_array($r['status'], CITIZEN_CANCELLABLE_STATUSES, true),
        'cancel_deletes' => in_array($r['status'], CITIZEN_DELETABLE_STATUSES, true),   // true: cancelling removes it completely
    ];
}, $rows);
respond(['ok' => true, 'reports' => $reports]);
