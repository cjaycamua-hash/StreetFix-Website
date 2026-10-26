<?php
require __DIR__ . '/../config/config.php';
$currentUser = require_page_role('maintenance');
$activePage = $activePage ?? 'dashboard';
$first = $currentUser['first_name'];
$pageTitles = [
    'dashboard'    => ['Dashboard', 'Good day, ' . $first . '!', 'Here’s your task for today.'],
    'assigned'     => ['Assigned reports', 'Assigned reports', 'Review every report assigned to you and track repair progress.'],
    'notifications' => ['Notifications', 'Notifications', 'Updates from your LGU about reports assigned to you.'],
    'profile'      => ['My profile', 'My profile', 'View and update your personal details and password.'],
    'task-details' => ['Task details', 'Task details', 'Review the assigned location and update repair progress.'],
];
$pageTitle = $pageTitles[$activePage] ?? $pageTitles['dashboard'];
$h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$navCurrent = static fn(bool $on): string => $on ? ' aria-current="page"' : '';
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title><?= $h($pageTitle[0]) ?> · StreetFix</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"><script src="../assets/js/theme-init.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/theme-init.js') ?>"></script><link rel="stylesheet" href="../assets/css/ui.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/ui.css') ?>"><link rel="stylesheet" href="css/maintenance.css?v=<?= (int)@filemtime(__DIR__ . '/css/maintenance.css') ?>"><link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""><script src="../assets/js/tab-guard.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/tab-guard.js') ?>" data-logout="api/logout.php" data-login="../auth/login.php"></script></head>
<body data-page="<?= $h($activePage) ?>"><div class="app"><aside class="side"><a class="brand" href="../index.php">Street<b>Fix</b></a>
<nav aria-label="Main"><a href="dashboard.php"<?= $navCurrent($activePage === 'dashboard') ?>>Dashboard</a><a href="assigned_reports.php"<?= $navCurrent($activePage === 'assigned' || $activePage === 'task-details') ?>>Assigned reports</a><a href="notifications.php" id="nav-notif"<?= $navCurrent($activePage === 'notifications') ?>>Notifications</a><a href="profile.php"<?= $navCurrent($activePage === 'profile') ?>>Profile</a></nav>
<div class="who">Signed in as<br><strong><?= $h($currentUser['first_name'] . ' ' . $currentUser['last_name']) ?></strong><br>Road Maintenance<button id="logout" type="button">Log out</button></div></aside>
<main class="main" id="overview"><header class="top"><div><h1><?= $h($pageTitle[1]) ?></h1><p><?= $h($pageTitle[2]) ?></p></div><a class="me" href="profile.php" aria-label="Open my profile"><span class="av" aria-hidden="true"><?= $h(strtoupper(substr($currentUser['first_name'], 0, 1) . substr($currentUser['last_name'], 0, 1))) ?></span><?= $h($currentUser['first_name']) ?></a></header>
<div class="toast-stack" id="toast-stack" aria-live="polite" aria-atomic="false"></div>
<?php if ($activePage === 'dashboard'): ?>
<section class="stats m3" aria-label="Task summary"><div class="stat"><span>Pending</span><strong id="t-assigned">0</strong></div><div class="stat"><span>In Progress</span><strong id="t-progress">0</strong></div><div class="stat"><span>Resolved</span><strong id="t-done">0</strong></div></section>
<section class="panel" id="recent-section"><h2>Recent assigned reports</h2><div id="recent"><p class="empty">Loading assigned reports…</p></div></section>
<section class="panel" id="map-section"><h2>Assigned locations</h2><div class="map" id="map" aria-label="Map of assigned repair locations"></div></section>
<?php elseif ($activePage === 'assigned'): ?>
<div class="toolbar-row"><div class="tabs" id="filters" role="group" aria-label="Filter reports"><button type="button" class="on" data-filter="all">All</button><button type="button" data-filter="progress">In Progress</button><button type="button" data-filter="pending">Pending</button><button type="button" data-filter="completed">Resolved</button></div><input id="task-search" class="search" type="search" placeholder="Search by ID, road, or location…" aria-label="Search assigned reports"></div>
<section class="panel" id="list-section"><div class="tablewrap"><table><thead><tr><th>ID</th><th>Location</th><th>Severity</th><th>Status</th><th>Reported</th><th>Action</th></tr></thead><tbody id="rows"><tr><td colspan="6" class="empty">Loading assigned reports…</td></tr></tbody></table></div></section>
<?php elseif ($activePage === 'notifications'): ?>
<section class="panel" id="notif-section"><div class="panel-head"><h2>LGU notifications</h2><button id="read-all" class="btn sm ghost" type="button" hidden>Mark all as read</button></div><ul class="notifs" id="notifs"><li class="empty">Loading notifications…</li></ul></section>
<?php elseif ($activePage === 'profile'): $profileApi = 'api/profile.php'; $profileEmailEditable = false; require __DIR__ . '/../includes/profile-panel.php'; ?>
<?php else: ?>
<section class="panel" id="detail" aria-live="polite"><p class="empty">Loading task…</p></section>
<?php endif; ?>
</main></div><script src="../assets/js/theme.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/theme.js') ?>"></script><script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script><script src="../assets/js/map.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/map.js') ?>"></script><script src="js/maintenance.js?v=<?= (int)@filemtime(__DIR__ . '/js/maintenance.js') ?>"></script><?php if ($activePage === 'profile'): ?><script src="../assets/js/profile.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/profile.js') ?>"></script><?php endif; ?></body></html>
