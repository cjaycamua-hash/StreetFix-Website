<?php
require __DIR__ . '/../../config/config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['ok' => false, 'error' => 'POST required.'], 405);
$user = require_login('citizen');
$input = post_only();
$reportId = filter_var($input['report_id'] ?? null, FILTER_VALIDATE_INT);
$rating = filter_var($input['rating'] ?? null, FILTER_VALIDATE_INT);
$comments = trim((string)($input['comments'] ?? ''));
if (!$reportId || !$rating || $rating < 1 || $rating > 5 || mb_strlen($comments) > 1000) {
    respond(['ok' => false, 'error' => 'Choose a rating from 1 to 5 and keep feedback under 1000 characters.'], 422);
}
$pdo = db();
$check = $pdo->prepare("SELECT id FROM reports WHERE id = ? AND user_id = ? AND status = 'Resolved'");
$check->execute([$reportId, $user['id']]);
if (!$check->fetch()) respond(['ok' => false, 'error' => 'Feedback is available only for your resolved reports.'], 403);
$exists = $pdo->prepare('SELECT 1 FROM feedback WHERE report_id = ?');
$exists->execute([$reportId]);
if ($exists->fetch()) respond(['ok' => false, 'error' => 'Feedback has already been submitted for this report.'], 409);
$insert = $pdo->prepare('INSERT INTO feedback (report_id, user_id, rating, comments) VALUES (?, ?, ?, ?)');
$insert->execute([$reportId, $user['id'], $rating, $comments ?: null]);
respond(['ok' => true], 201);
