<?php
require __DIR__ . '/../../config/config.php';
require_login('admin');
$sql = "SELECT r.*, CONCAT_WS(' ', u.first_name, u.last_name) AS reporter,
               CONCAT_WS(' ', m.first_name, m.last_name) AS assignee, t.id AS team_id, t.name AS team_name,
               l.name AS lgu_name,
               rp.notes, rp.completion_submitted, fb.rating, fb.comments AS feedback_comments,
               dup.id AS duplicate_id,
               EXISTS(SELECT 1 FROM status_history h JOIN users hu ON hu.id = h.changed_by
                      WHERE h.report_id = r.id AND h.status = 'Duplicate' AND hu.role = 'admin') AS duplicate_reviewed,
               (SELECT photo_path FROM report_photos WHERE report_id = r.id AND photo_type = 'before' ORDER BY id DESC LIMIT 1) AS before_photo,
               (SELECT photo_path FROM report_photos WHERE report_id = r.id AND photo_type = 'after' ORDER BY id DESC LIMIT 1) AS after_photo
        FROM reports r JOIN users u ON u.id = r.user_id
        LEFT JOIN assignments a ON a.report_id = r.id AND a.active = 1
        LEFT JOIN users m ON m.id = a.maintenance_user_id
        LEFT JOIN maintenance_teams t ON t.id = a.team_id
        LEFT JOIN lgus l ON l.id = r.assigned_lgu_id
        LEFT JOIN repairs rp ON rp.report_id = r.id
        LEFT JOIN feedback fb ON fb.report_id = r.id
        LEFT JOIN reports dup ON dup.id = r.duplicate_of
        ORDER BY r.id DESC";
$rows = db()->query($sql)->fetchAll();
// "No. of Reports": how many reports describe the same pothole (the original plus every report flagged as its duplicate).
$clusterSize = [];
foreach ($rows as $r) {
    $root = (int)($r['duplicate_id'] ?: $r['id']);
    $clusterSize[$root] = ($clusterSize[$root] ?? 0) + 1;
}
$defaultAgency = (string)(db()->query("SELECT setting_value FROM system_settings WHERE setting_key = 'agency_name'")->fetchColumn() ?: '');
$photoStmt = db()->prepare("SELECT photo_path FROM report_photos WHERE report_id = ? AND photo_type = 'report' ORDER BY id");
$reports = array_map(static function (array $r) use ($photoStmt, $clusterSize, $defaultAgency): array {
    $photoStmt->execute([$r['id']]);
    $photos = array_map(static fn(array $photo): string => '../' . ltrim($photo['photo_path'], '/'), $photoStmt->fetchAll());
    if (!$photos) $photos[] = '../' . ltrim($r['photo_path'], '/');
    // The LGU that will handle this pothole: the assigned one, otherwise the LGU whose boundary contains the pin.
    $expectedLgu = $r['lgu_name'] ?? '';
    if ($expectedLgu === '') {
        $match = lgu_for_coordinates(db(), (float)$r['latitude'], (float)$r['longitude']);
        if ($match['status'] === 'matched') $expectedLgu = $match['lgu']['name'];
    }
    // Keep a custom agency the admin routed to; only replace blank or the generic default.
    $agency = (string)($r['responsible_agency'] ?? '');
    if ($expectedLgu !== '' && ($agency === '' || $agency === $defaultAgency)) $agency = $expectedLgu;
    return [
        'id' => (int)$r['id'], 'code' => report_code((int)$r['id']), 'road' => $r['road'],
        'description' => $r['description'] ?? '', 'severity' => $r['severity'], 'status' => $r['status'],
        'lat' => (float)$r['latitude'], 'lng' => (float)$r['longitude'], 'photo' => '../' . ltrim($r['photo_path'], '/'),
        'photos' => $photos, 'location_address' => $r['location_address'] ?? '',
        'agency' => $agency, 'expected_lgu' => $expectedLgu, 'agency_email' => $r['agency_email'] ?? '',
        'lgu_name' => $r['lgu_name'] ?? '',
        'lgu_assignment_status' => $r['lgu_assignment_status'],
        'lgu_assignment_note' => $r['lgu_assignment_note'] ?? '',
        'date' => substr((string)$r['created_at'], 0, 10), 'by' => $r['reporter'], 'assignee' => $r['assignee'] ?? '',
        'team_id' => $r['team_id'] === null ? null : (int)$r['team_id'], 'team_name' => $r['team_name'] ?? '',
        'repair_submitted' => (bool)$r['completion_submitted'],
        'before_photo' => $r['before_photo'] ? '../' . ltrim($r['before_photo'], '/') : '',
        'after_photo' => $r['after_photo'] ? '../' . ltrim($r['after_photo'], '/') : '',
        'repair_notes' => $r['notes'] ?? '', 'rating' => $r['rating'] === null ? null : (int)$r['rating'],
        'feedback' => $r['feedback_comments'] ?? '',
        'duplicate_of' => $r['duplicate_id'] ? report_code((int)$r['duplicate_id']) : '',
        'duplicate_reviewed' => (bool)$r['duplicate_reviewed'],
        'report_count' => $clusterSize[(int)($r['duplicate_id'] ?: $r['id'])] ?? 1,
    ];
}, $rows);
$teams = db()->query("SELECT t.id, t.name, t.lgu_id, l.name AS lgu_name,
                             COUNT(DISTINCT CASE WHEN u.role = 'maintenance' AND u.is_active = 1 THEN u.id END) AS active_members
                      FROM maintenance_teams t LEFT JOIN lgus l ON l.id = t.lgu_id
                      LEFT JOIN users u ON u.team_id = t.id
                      GROUP BY t.id, t.name, t.lgu_id, l.name ORDER BY l.name, t.name")->fetchAll();
$lgus = db()->query('SELECT id, name, lgu_type, location, administrative_area FROM lgus ORDER BY name')->fetchAll();
respond(['ok' => true, 'reports' => $reports, 'teams' => $teams, 'lgus' => $lgus]);
