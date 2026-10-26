<?php
// Restores the StreetFix data (from your MySQL export) into Cloud Firestore + Realtime Database.
// Localhost only. Reads restore-data.json from this folder. Safe to run again: existing documents are never overwritten.
// Keep this restore/ folder on your own computer; do not upload it to a public host.
declare(strict_types=1);
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) { http_response_code(403); exit('Open this page from the server (localhost) only.'); }
@set_time_limit(0);
@ini_set('memory_limit', '1024M');
require __DIR__ . '/../config/firebase.php';
header('Content-Type: text/html; charset=utf-8');
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$file = __DIR__ . '/restore-data.json';
$go = ($_SERVER['REQUEST_METHOD'] === 'POST');
echo '<!doctype html><meta charset="utf-8"><title>Restore StreetFix data</title><body style="font-family:system-ui;max-width:900px;margin:30px auto"><h2>Restore StreetFix data to Firebase</h2>';
if (!is_file($file)) exit('<p style="color:#b00">Missing restore-data.json next to this file.</p>');
try {
    $plan = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $F = $plan['firestore']; $IMG = $plan['rtdb']['reportImages'] ?? [];
    $existing = fs_list_many(array_keys($F));
    $rows = []; $writes = []; $fixes = 0;
    foreach ($F as $col => $docs) {
        $new = 0; $have = 0;
        foreach ($docs as $id => $fields) {
            $id = (string)$id;
            if (!isset($existing[$col][$id])) { $writes[] = ['op' => 'create', 'col' => $col, 'id' => $id, 'fields' => $fields]; $new++; continue; }
            $have++;
            if ($col === 'users') {   // never overwrite a value that is already there: only fill what is missing (incl. a missing/invalid password hash)
                $cur = $existing[$col][$id]; $fix = [];
                foreach ($fields as $k => $v) {
                    if ($k === 'password_hash') {
                        $hv = (string)($cur['password_hash'] ?? '');
                        if ($hv === '' || password_get_info($hv)['algo'] === null) $fix[$k] = $v;
                    } elseif ((!array_key_exists($k, $cur) || $cur[$k] === null || $cur[$k] === '') && $v !== null && $v !== '') {
                        $fix[$k] = $v;
                    }
                }
                if ($fix) { $writes[] = ['op' => 'set', 'col' => $col, 'id' => $id, 'fields' => $fix]; $fixes++; }
            }
        }
        $rows[] = [$col, count($docs), $new, $have];
    }
    $imgTotal = 0; foreach ($IMG as $ph) $imgTotal += count($ph);
    echo '<table border="1" cellpadding="5" style="border-collapse:collapse;width:100%"><tr><th>collection</th><th>in MySQL export</th><th>to create</th><th>already in Firestore (kept)</th></tr>';
    foreach ($rows as $r) echo '<tr><td>' . $h($r[0]) . '</td><td>' . $r[1] . '</td><td>' . $r[2] . '</td><td>' . $r[3] . '</td></tr>';
    echo '</table><p>Existing user documents that will get missing fields filled (nothing is overwritten): ' . $fixes . '. Report photos for Realtime Database (reportImages): ' . $imgTotal . '.</p>';
    if (!$go) {
        echo '<form method="post"><p><button style="padding:8px 16px">Restore now</button></p></form><p>Preview only: nothing has been written.</p>';
        exit;
    }
    // ---- Firestore
    foreach (array_chunk($writes, 200) as $chunk) fs_commit($chunk);
    echo '<p style="color:green">Firestore: ' . count($writes) . ' write(s) applied.</p>';
    // ---- Realtime Database photos (skipped when the same bytes are already there)
    $imgNew = 0; $imgKept = 0;
    foreach ($IMG as $num => $photos) foreach ($photos as $key => $node) {
        $path = "reportImages/$num/$key";
        $cur = null; try { $cur = rtdb_get($path); } catch (Throwable $e) { $cur = null; }
        if (is_array($cur) && ($cur['sha256'] ?? '') === $node['sha256']) { $imgKept++; continue; }
        unset($node['sourcePath']); $node['uploadedAt'] = (int)(microtime(true) * 1000);
        rtdb_put($path, $node); $imgNew++;
    }
    echo '<p style="color:green">Realtime Database: ' . $imgNew . ' photo(s) written, ' . $imgKept . ' already present.</p>';
    // ---- Verification
    $bad = [];
    $now = fs_list_many(array_keys($F));
    foreach ($F as $col => $docs) {
        $have = count($now[$col] ?? []);
        if ($have < count($docs)) $bad[] = "$col: Firestore $have < expected " . count($docs);
        foreach ($docs as $id => $_) if (!isset($now[$col][(string)$id])) $bad[] = "$col/$id missing";
    }
    foreach ($F['users'] ?? [] as $id => $_) { $hv = (string)($now['users'][(string)$id]['password_hash'] ?? ''); if ($hv === '' || password_get_info($hv)['algo'] === null) $bad[] = "users/$id has no valid password_hash"; }
    foreach ($IMG as $num => $photos) foreach ($photos as $key => $node) {
        $v = null; try { $v = rtdb_get("reportImages/$num/$key"); } catch (Throwable $e) {}
        if (!is_array($v)) { $bad[] = "RTDB reportImages/$num/$key missing"; continue; }
        if (hash('sha256', (string)base64_decode((string)($v['imageData'] ?? ''), true)) !== $node['sha256']) $bad[] = "RTDB reportImages/$num/$key checksum mismatch";
    }
    foreach ($F['reports'] ?? [] as $id => $r) if (!empty($r['imageReference'])) { [, $n, $k] = explode('/', $r['imageReference']); if (!isset($IMG[$n][$k])) $bad[] = "report $id image reference has no photo (file was missing in uploads)"; }
    if ($bad) { echo '<p style="color:#b00"><b>Finished with notes:</b></p><ul>'; foreach ($bad as $b) echo '<li>' . $h($b) . '</li>'; echo '</ul>'; }
    else echo '<p style="color:green"><b>VERIFIED:</b> all collections, password hashes, photos and image references match.</p>';
    echo '<p>Restore finished. You can log in now.</p>';
} catch (Throwable $e) { echo '<p style="color:#b00"><b>Error:</b> ' . $h($e->getMessage()) . '</p>'; }
