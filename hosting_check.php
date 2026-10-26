<?php
// One-time check for a new host (InfinityFree etc.). Open it once in the browser, then DELETE this file.
header('Content-Type: text/plain; charset=utf-8');
$ok = static fn(bool $b): string => $b ? 'OK   ' : 'FAIL ';
echo "StreetFix hosting check - delete this file afterwards\n\n";
echo 'PHP version: ' . PHP_VERSION . "\n";
foreach (['curl', 'openssl', 'pdo_sqlite', 'mbstring', 'fileinfo', 'json'] as $ext) echo $ok(extension_loaded($ext)) . "extension $ext\n";
require __DIR__ . '/config/firebase.php';
echo $ok(basename(fb_tmp_dir()) === 'cache') . "private cache folder (config/cache) is writable\n";
$sessions = __DIR__ . '/config/sessions';
echo $ok(is_dir($sessions) && is_writable($sessions)) . "session folder (config/sessions) is writable\n";
try {
    fb_token();
    echo "OK   Firebase access token received\n";
    $data = fs_list_many(['system_settings']);
    echo 'OK   Firestore read works (' . count($data['system_settings']) . " settings found)\n";
} catch (Throwable $e) {
    echo 'FAIL Firebase: ' . $e->getMessage() . "\n";
}
