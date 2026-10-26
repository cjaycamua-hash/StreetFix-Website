<?php
require __DIR__ . '/../config/config.php';
if (!empty($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Log in · StreetFix</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"><script src="../assets/js/theme-init.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/theme-init.js') ?>"></script><link rel="stylesheet" href="../assets/css/ui.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/ui.css') ?>"></head>
<body><main class="auth"><aside class="auth-side"><div><div class="dash" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></div><h1>Report it.<em>We’ll fix it.</em></h1><p>StreetFix connects citizens, agencies and maintenance crews to find, track and repair road damage faster.</p></div><small>Fast. Reliable. On the move.</small></aside>
<section class="auth-main"><div class="card"><a class="brand" href="../index.php">Street<b>Fix</b></a><h2>Welcome back</h2><p class="sub">Log in to your StreetFix account.</p>
<div id="msg" class="msg" role="alert"></div>
<form id="login-form"><div class="field"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="190" autocomplete="email" required></div>
<div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
<button class="btn full" type="submit">Log in</button></form>
<p class="alt">New to StreetFix? <a href="register.php">Create an account</a></p></div></section></main>
<script src="../assets/js/theme.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/theme.js') ?>"></script><script src="../assets/js/common.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/common.js') ?>"></script><script src="js/auth.js?v=<?= (int)@filemtime(__DIR__ . '/js/auth.js') ?>"></script></body></html>
