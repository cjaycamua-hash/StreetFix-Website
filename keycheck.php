<?php
// TEMPORARY: open once, then DELETE this file. Never prints the key itself.
header('Content-Type: text/plain; charset=utf-8');
$env = getenv('STREETFIX_FIREBASE_KEY');
echo "Env STREETFIX_FIREBASE_KEY: " . ($env === false || $env === '' ? 'NOT SET' : $env) . "\n";
$path = $env ?: __DIR__ . '/config/firebase-service-account.json';
echo "File exists: " . (is_file($path) ? 'yes' : 'NO') . "\n";
if (!is_file($path)) { echo "Secret dir listing: " . implode(', ', glob('/etc/secrets/*') ?: ['(empty)']) . "\n"; exit; }
$raw = (string)file_get_contents($path);
echo "Size: " . strlen($raw) . " bytes (expected about 2300-2400)\n";
echo "First char: " . json_encode(substr($raw, 0, 1)) . " | Last char: " . json_encode(substr(rtrim($raw), -1)) . "\n";
$d = json_decode($raw, true);
echo "JSON parse: " . ($d === null ? 'FAILED - ' . json_last_error_msg() : 'OK') . "\n";
if (is_array($d)) {
    echo "type: " . ($d['type'] ?? 'MISSING') . "\n";
    echo "project_id: " . ($d['project_id'] ?? 'MISSING') . "\n";
    echo "client_email: " . (!empty($d['client_email']) ? 'present' : 'MISSING') . "\n";
    $pk = (string)($d['private_key'] ?? '');
    echo "private_key length: " . strlen($pk) . " (expected about 1700)\n";
    echo "private_key header ok: " . (str_starts_with($pk, '-----BEGIN PRIVATE KEY-----') ? 'yes' : 'NO') . "\n";
    echo "private_key has real newlines: " . (str_contains($pk, "\n") ? 'yes' : 'NO') . "\n";
    echo "openssl can load key: " . (@openssl_pkey_get_private($pk) ? 'yes' : 'NO') . "\n";
}
