<?php
// Public map data for any logged-in citizen. Only location, severity, status and a report counter are shared:
// no reporter name, no photo, no description. Resolved reports are kept off the active map, except the
// pothole behind one of the citizen's own reports (?focus=SF-1001, used by "View on Map").
// Duplicate reports are grouped under their original, so a pothole is one pin with a counter.
require __DIR__ . '/../../config/config.php';
$user = require_login('citizen');
$pdo = db();

$focus = 0;
if (preg_match('/^SF-(\d+)$/i', (string)($_GET['focus'] ?? ''), $m)) {
    $own = $pdo->prepare('SELECT COALESCE(duplicate_of, id) FROM reports WHERE id = ? AND user_id = ?');
    $own->execute([(int)$m[1] - 1000, $user['id']]);
    $focus = (int)$own->fetchColumn();
}
$stmt = $pdo->prepare("SELECT r.id, r.road, r.severity, r.status, r.latitude, r.longitude,
                              (SELECT COUNT(*) FROM reports d WHERE d.duplicate_of = r.id) AS dup_count,
                              (SELECT GROUP_CONCAT(DISTINCT d.severity) FROM reports d WHERE d.duplicate_of = r.id) AS dup_sev
                       FROM reports r
                       WHERE r.duplicate_of IS NULL AND r.status <> 'Cancelled' AND (r.status NOT IN ('Resolved', 'Completed') OR r.id = ?)");
$stmt->execute([$focus]);
$pins = array_map(static function (array $r): array {
    $severities = severity_range($r['severity'], $r['dup_sev']);
    return [
        'code' => report_code((int)$r['id']), 'road' => $r['road'], 'severity' => end($severities), 'severities' => $severities,
        'count' => 1 + (int)$r['dup_count'], 'status' => $r['status'], 'lat' => (float)$r['latitude'], 'lng' => (float)$r['longitude'],
    ];
}, $stmt->fetchAll());
header('Cache-Control: no-store, no-cache, must-revalidate');
respond(['ok' => true, 'reports' => $pins]);
