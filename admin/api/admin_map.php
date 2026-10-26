<?php
require __DIR__ . '/../../config/config.php';
require_login('admin');
// One pin per pothole: duplicate reports are grouped under their original and only raise its counter.
// ?focus=SF-1001 also returns that pothole when it is resolved (a duplicate code resolves to its original).
$pdo = db();
$focus = 0;
if (preg_match('/^SF-(\d+)$/i', (string)($_GET['focus'] ?? ''), $m)) {
    $root = $pdo->prepare('SELECT COALESCE(duplicate_of, id) FROM reports WHERE id = ?');
    $root->execute([(int)$m[1] - 1000]);
    $focus = (int)$root->fetchColumn();
}
$stmt = $pdo->prepare("SELECT r.id, r.road, r.severity, r.status, r.latitude, r.longitude,
                              (SELECT COUNT(*) FROM reports d WHERE d.duplicate_of = r.id) AS dup_count,
                              (SELECT GROUP_CONCAT(DISTINCT d.severity) FROM reports d WHERE d.duplicate_of = r.id) AS dup_sev
                       FROM reports r
                       WHERE r.duplicate_of IS NULL AND r.status <> 'Cancelled' AND (r.status NOT IN ('Resolved', 'Completed') OR r.id = ?)
                       ORDER BY r.id DESC");
$stmt->execute([$focus]);
$pins = array_map(static function (array $report): array {
    $severities = severity_range($report['severity'], $report['dup_sev']);
    return [
        'code' => report_code((int)$report['id']),
        'road' => $report['road'],
        'severity' => end($severities),
        'severities' => $severities,
        'count' => 1 + (int)$report['dup_count'],
        'status' => $report['status'],
        'lat' => (float)$report['latitude'],
        'lng' => (float)$report['longitude'],
    ];
}, $stmt->fetchAll());
header('Cache-Control: no-store, no-cache, must-revalidate');
respond(['ok' => true, 'reports' => $pins]);
