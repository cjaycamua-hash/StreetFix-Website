<?php
require __DIR__ . '/../../config/config.php';
$in = post_only();
$email = strtolower(trim((string)($in['email'] ?? '')));
$pass  = (string)($in['password'] ?? '');

$stmt = db()->prepare('SELECT id, password, role, is_active FROM users WHERE email = ?');
$stmt->execute([$email]);
$u = $stmt->fetch();

$stored = $u ? (string)$u['password'] : '';
$validPassword = $stored !== '' && password_is_hashed($stored)
    ? password_verify($pass, $stored)
    : ($stored !== '' && hash_equals($stored, $pass));
if ($u && $stored === '' && !$validPassword) $validPassword = fb_auth_password_ok($email, $pass);   // app-registered account: password lives in Firebase Auth
$valid = $u && (int)$u['is_active'] === 1 && $validPassword;
if (!$valid) {
    respond(['ok' => false, 'error' => 'Incorrect email or password.'], 401);
}

session_regenerate_id(true);
$_SESSION['user_id'] = (int)$u['id'];
// Each role lands on its own page.
$pages = ['admin' => '../admin/index.php', 'maintenance' => '../road_maintenance/dashboard.php'];
respond(['ok' => true, 'redirect' => $pages[$u['role']] ?? '../citizen_reporter/dashboard.php']);
