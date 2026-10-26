<?php
require __DIR__ . '/../../config/config.php';
$worker = require_login('maintenance');
$allScope = ($_GET['scope'] ?? '') === 'all';
$scopeSql = $allScope
    ? "r.status IN ('Assigned','In Progress','Resolved')"
    : "r.status IN ('Assigned','In Progress')";
$stmt = db()->prepare(
    "SELECT r.id, r.road, r.description, r.severity, r.status, r.latitude, r.longitude, r.photo_path, r.created_at,
            u.first_name, u.last_name, rp.notes, rp.completion_submitted, rp.completed_at, t.name AS team_name,
            r.location_address, r.responsible_agency, r.assigned_lgu_id, r.lgu_assignment_status, l.name AS lgu_name,
            (SELECT photo_path FROM report_photos WHERE report_id = r.id AND photo_type = 'before' ORDER BY id DESC LIMIT 1) AS before_photo,
            (SELECT photo_path FROM report_photos WHERE report_id = r.id AND photo_type = 'after' ORDER BY id DESC LIMIT 1) AS after_photo
     FROM reports r
     JOIN users u ON u.id = r.user_id
     LEFT JOIN assignments a ON a.report_id = r.id AND a.active = 1
     LEFT JOIN maintenance_teams t ON t.id = a.team_id
     LEFT JOIN lgus l ON l.id = r.assigned_lgu_id
     LEFT JOIN repairs rp ON rp.report_id = r.id
     WHERE ((a.team_id = :team_id AND (
              t.lgu_id IS NULL
              OR (t.lgu_id = r.assigned_lgu_id AND r.lgu_assignment_status = 'assigned')
          ))
          OR (a.team_id IS NULL AND a.maintenance_user_id = :worker_id))
     AND " . $scopeSql . "
     ORDER BY FIELD(r.severity, 'High', 'Medium', 'Low'), r.created_at"
);
$stmt->execute(['team_id' => $worker['team_id'], 'worker_id' => $worker['id']]);
$photoStmt = db()->prepare("SELECT photo_path FROM report_photos WHERE report_id = ? AND photo_type = 'report' ORDER BY id");
$rows = array_map(static function (array $r): array {
    return [
        'id' => (int)$r['id'], 'code' => report_code((int)$r['id']), 'road' => $r['road'],
        'description' => $r['description'] ?? '', 'severity' => $r['severity'], 'status' => $r['status'],
        'lat' => (float)$r['latitude'], 'lng' => (float)$r['longitude'],
        'location_address' => $r['location_address'] ?? '', 'agency' => $r['lgu_name'] ?? $r['responsible_agency'] ?? '',
        'lgu_assignment_status' => $r['lgu_assignment_status'],
        'team_name' => $r['team_name'] ?? '',
        'photo' => '../' . ltrim($r['photo_path'], '/'), 'date' => substr((string)$r['created_at'], 0, 10),
        'reporter' => trim($r['first_name'] . ' ' . $r['last_name']), 'notes' => $r['notes'] ?? '',
        'completion_submitted' => (bool)$r['completion_submitted'], 'completed_at' => $r['completed_at'] ? substr((string)$r['completed_at'], 0, 16) : '',
        'before_photo' => $r['before_photo'] ? '../' . ltrim($r['before_photo'], '/') : '',
        'after_photo' => $r['after_photo'] ? '../' . ltrim($r['after_photo'], '/') : '',
    ];
}, $stmt->fetchAll());
foreach ($rows as &$row) {
    $photoStmt->execute([$row['id']]);
    $row['photos'] = array_map(static fn(array $photo): string => '../' . ltrim($photo['photo_path'], '/'), $photoStmt->fetchAll());
    if (!$row['photos']) $row['photos'][] = $row['photo'];
}
unset($row);
$notifications = [];
if ($worker['lgu_id']) {
    $notices = db()->prepare('SELECT n.id, n.message, n.report_id, n.created_at, r.road
                              FROM lgu_notifications n JOIN reports r ON r.id = n.report_id
                              WHERE n.lgu_id = ? ORDER BY n.id DESC LIMIT 20');
    $notices->execute([$worker['lgu_id']]);
    $notifications = array_map(static fn(array $notice): array => [
        'id' => (int)$notice['id'],
        'message' => $notice['message'],
        'code' => report_code((int)$notice['report_id']),
        'road' => $notice['road'],
        'date' => substr((string)$notice['created_at'], 0, 16),
        'task_id' => (int)$notice['report_id'],
    ], $notices->fetchAll());
}
respond(['ok' => true, 'tasks' => $rows, 'notifications' => $notifications, 'worker_id' => (int)$worker['id'], 'worker' => trim($worker['first_name'] . ' ' . $worker['last_name'])]);
