<?php
require __DIR__ . '/../../config/config.php';
require_login('admin');
$pdo = db();
$keys = ['agency_name', 'agency_email', 'service_area'];
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query('SELECT setting_key, setting_value FROM system_settings');
    respond(['ok' => true, 'settings' => $stmt->fetchAll(PDO::FETCH_KEY_PAIR)]);
}
$input = post_only();
$settings = [];
foreach ($keys as $key) $settings[$key] = trim((string)($input[$key] ?? ''));
if ($settings['agency_name'] === '' || mb_strlen($settings['agency_name']) > 190 ||
    ($settings['agency_email'] !== '' && (!filter_var($settings['agency_email'], FILTER_VALIDATE_EMAIL) || strlen($settings['agency_email']) > 190)) ||
    $settings['service_area'] === '' || mb_strlen($settings['service_area']) > 500) {
    respond(['ok' => false, 'error' => 'Enter a valid agency name, contact email, and service area.'], 422);
}
$pdo->beginTransaction();
$save = $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
foreach ($settings as $key => $value) $save->execute([$key, $value]);
$pdo->commit();
respond(['ok' => true]);
