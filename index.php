<?php
require __DIR__ . '/config/config.php';
if (!empty($_SESSION['user_id'])) {
    $stmt = db()->prepare('SELECT role, is_active FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if ($user && (int)$user['is_active'] === 1) {
        $pages = ['admin' => 'admin/index.php', 'maintenance' => 'road_maintenance/dashboard.php', 'citizen' => 'citizen_reporter/dashboard.php'];
        header('Location: ' . $pages[$user['role']]);
        exit;
    }
    $_SESSION = [];
    session_destroy();
}
header('Location: auth/login.php');
exit;
