<?php
// Serves a report image stored in the Firebase Realtime Database (reportImages/{reportNumber}/{key}).
// Replaces direct links to uploads/. Only signed-in users can view photos; citizens only their own reports'.
require __DIR__ . '/config/config.php';
$user = require_login();
$ref = image_ref_from_url('image.php?r=' . (string)($_GET['r'] ?? '') . '&k=' . (string)($_GET['k'] ?? ''));
if ($ref === null) { http_response_code(400); exit; }
if ($user['role'] === 'citizen') {
    $reportId = (int)substr((string)$_GET['r'], 3) - 1000;
    $own = db()->prepare('SELECT 1 FROM reports WHERE id = ? AND user_id = ?');
    $own->execute([$reportId, $user['id']]);
    if (!$own->fetch()) { http_response_code(403); exit; }
}
$node = rtdb_get($ref);
if (!$node || empty($node['imageData']) || !in_array($node['contentType'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(404);
    exit;
}
$bytes = base64_decode((string)$node['imageData'], true);
if ($bytes === false) { http_response_code(500); exit; }
header('Content-Type: ' . $node['contentType']);
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
echo $bytes;
