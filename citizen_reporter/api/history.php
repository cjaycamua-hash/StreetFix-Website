<?php
require __DIR__ . '/../../config/config.php';
$user = require_login('citizen');
$reportId = filter_var($_GET['report_id'] ?? null, FILTER_VALIDATE_INT);
if (!$reportId) respond(['ok' => false, 'error' => 'Invalid report.'], 422);
$owner = db()->prepare('SELECT 1 FROM reports WHERE id = ? AND user_id = ?');
$owner->execute([$reportId, $user['id']]);
if (!$owner->fetch()) respond(['ok' => false, 'error' => 'Report not found.'], 404);
$stmt = db()->prepare('SELECT status, note, created_at FROM status_history WHERE report_id = ? ORDER BY id DESC');
$stmt->execute([$reportId]);
$history = array_map(static function (array $h): array {
    if ($h['status'] === 'Duplicate') { $h['status'] = 'Submitted'; $h['note'] = null; }
    elseif ($h['note'] !== null && stripos($h['note'], 'duplicate') !== false) $h['note'] = null;
    return $h;
}, $stmt->fetchAll());
respond(['ok' => true, 'history' => $history]);
