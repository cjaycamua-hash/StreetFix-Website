<?php
// GET: the citizen's latest notifications. POST: mark them all as read.
require __DIR__ . '/../../config/config.php';
$user = require_login('citizen');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$user['id']]);
    respond(['ok' => true]);
}

$stmt = $pdo->prepare('SELECT message, is_read, created_at FROM notifications
                       WHERE user_id = ? ORDER BY id DESC LIMIT 20');
$stmt->execute([$user['id']]);
$items = array_map(fn($n) => ['message' => stripos($n['message'], 'grouped with') !== false
                                  ? preg_replace('/^Your report (SF-\d+).*$/s', 'Your report $1 was submitted.', $n['message'])
                                  : $n['message'], 'unread' => !$n['is_read'],
                              'date' => substr($n['created_at'], 0, 16)], $stmt->fetchAll());
respond(['ok' => true, 'notifications' => $items, 'unread' => count(array_filter($items, fn($n) => $n['unread']))]);
