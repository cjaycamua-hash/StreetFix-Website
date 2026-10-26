<?php
declare(strict_types=1);
/* Firebase connection for StreetFix: Cloud Firestore (structured data) + Realtime Database (report images).
   Runs on the PHP server with a service account, so no secret is ever sent to the browser.
   Put the key file at config/firebase-service-account.json (this folder is blocked by .htaccess) or point the
   STREETFIX_FIREBASE_KEY environment variable at it. Never commit the key. */

const FB_PROJECT_ID = 'street-fix-329ae';
const FB_DATABASE_URL = 'https://street-fix-329ae-default-rtdb.firebaseio.com';
const FB_SCOPES = 'https://www.googleapis.com/auth/datastore https://www.googleapis.com/auth/firebase.database https://www.googleapis.com/auth/userinfo.email';

function fb_fail(string $message, int $http = 0): never
{
    throw new RuntimeException($message . ($http ? " (HTTP $http)" : ''), $http);
}

function fb_b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/* Folder for the access-token and collection caches. Prefers config/cache (blocked from the web by config/.htaccess and
   private to this site, which matters on shared hosting) and only falls back to the system temp folder if it is not writable. */
function fb_tmp_dir(): string
{
    static $dir = null;
    if ($dir !== null) return $dir;
    $local = __DIR__ . DIRECTORY_SEPARATOR . 'cache';
    if (!is_dir($local)) @mkdir($local, 0777, true);
    $marker = $local . DIRECTORY_SEPARATOR . '.ok';
    $usable = is_dir($local) && (is_file($marker) || @file_put_contents($marker, '1') !== false);
    return $dir = $usable ? $local : rtrim(sys_get_temp_dir(), '/\\');
}

function fb_credentials(): array
{
    static $cred = null;
    if ($cred !== null) return $cred;
    $path = getenv('STREETFIX_FIREBASE_KEY') ?: __DIR__ . DIRECTORY_SEPARATOR . 'firebase-service-account.json';
    // Safer on hosting: the key may sit one level above the website folder (outside the public web root).
    $outside = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'firebase-service-account.json';
    if (!@is_file($path) && @is_file($outside)) $path = $outside;
    if (!is_file($path)) {
        // Accept the file under the name Google gave it (e.g. street-fix-329ae-firebase-adminsdk-xxxxx.json, or one ending in .json.json).
        foreach (glob(__DIR__ . DIRECTORY_SEPARATOR . '*.json*') ?: [] as $candidate) {
            if (stripos(basename($candidate), 'example') !== false || stripos(basename($candidate), 'template') !== false) continue;
            $try = json_decode((string)file_get_contents($candidate), true);
            if (is_array($try) && ($try['type'] ?? '') === 'service_account' && !empty($try['private_key'])) { $path = $candidate; break; }
        }
    }
    if (!is_file($path)) {
        $found = array_map('basename', glob(__DIR__ . DIRECTORY_SEPARATOR . '*') ?: []);
        fb_fail('Firebase service account key not found in the config folder (' . dirname($path) . '). Files seen there: ' . implode(', ', $found) . '. Save the downloaded key file in that folder.');
    }
    $cred = json_decode((string)file_get_contents($path), true);
    if (!is_array($cred) || empty($cred['private_key']) || empty($cred['client_email'])) fb_fail('The Firebase service account key file is not valid.');
    if (str_contains((string)$cred['private_key'], 'REPLACE_ME')) fb_fail('The service account file in the config folder is only the template. Replace it with your real key: Firebase console > Project settings > Service accounts > Generate new private key, saved as config/firebase-service-account.json.');
    if (($cred['project_id'] ?? '') !== FB_PROJECT_ID) fb_fail('The service account key belongs to a different Firebase project than ' . FB_PROJECT_ID . '.');
    return $cred;
}

function fb_curl(string $url, string $method = 'GET', ?string $body = null, array $headers = [])
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60, CURLOPT_ENCODING => '',   // accept gzip: Firestore JSON shrinks several times
        // Some home/school networks reset IPv6 or HTTP/2 connections to Google; IPv4 + HTTP/1.1 is the most compatible.
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    // XAMPP on Windows often has no CA bundle configured for curl; use the one that ships with it when present.
    if (!ini_get('curl.cainfo') && !ini_get('openssl.cafile')) {
        foreach ([dirname(PHP_BINARY) . '/extras/ssl/cacert.pem', 'C:/xampp/php/extras/ssl/cacert.pem', 'C:/xampp/apache/bin/curl-ca-bundle.crt'] as $ca) {
            if (is_file($ca)) { curl_setopt($ch, CURLOPT_CAINFO, $ca); break; }
        }
    }
    return $ch;
}

function fb_request(string $method, string $url, ?array $json = null, bool $auth = true): array
{
    $headers = ['Accept: application/json'];
    $body = null;
    if ($json !== null) { $body = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); $headers[] = 'Content-Type: application/json'; }
    if ($auth) $headers[] = 'Authorization: Bearer ' . fb_token();
    for ($attempt = 1; ; $attempt++) {   // retry dropped connections (all our requests are safe to repeat)
        $ch = fb_curl($url, $method, $body, $headers);
        $raw = curl_exec($ch);
        if ($raw !== false) break;
        $err = curl_error($ch);
        curl_close($ch);
        if ($attempt >= 3) fb_fail('Network error talking to Firebase after 3 tries: ' . $err);
        usleep(400000 * $attempt);
    }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $raw === '' ? null : json_decode((string)$raw, true)];
}

function fb_token(): string
{
    static $token = null, $exp = 0;
    if ($token !== null && $exp - 120 > time()) return $token;
    $cred = fb_credentials();
    $cache = fb_tmp_dir() . DIRECTORY_SEPARATOR . 'streetfix_fb_token_' . substr(hash('sha256', $cred['client_email']), 0, 12) . '.json';
    $cached = is_file($cache) ? json_decode((string)file_get_contents($cache), true) : null;
    if (is_array($cached) && ($cached['exp'] ?? 0) - 120 > time()) { $token = $cached['token']; $exp = $cached['exp']; return $token; }

    $now = time();
    $claims = ['iss' => $cred['client_email'], 'scope' => FB_SCOPES, 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600];
    $input = fb_b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . fb_b64url(json_encode($claims));
    if (!function_exists('openssl_sign')) fb_fail('The PHP OpenSSL extension is not enabled. In XAMPP: Config > php.ini, remove the ; before extension=openssl, then restart Apache.');
    if (!@openssl_sign($input, $sig, $cred['private_key'], OPENSSL_ALGO_SHA256)) fb_fail('Could not sign the Firebase access request: the private_key in the service account file is not a valid key (' . (openssl_error_string() ?: 'unknown OpenSSL error') . '). Download a fresh key from the Firebase console.');
    $ch = fb_curl('https://oauth2.googleapis.com/token', 'POST',
        http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $input . '.' . fb_b64url($sig)]),
        ['Content-Type: application/x-www-form-urlencoded']);
    $raw = curl_exec($ch);
    if ($raw === false) { $err = curl_error($ch); curl_close($ch); fb_fail('Network error getting a Firebase access token: ' . $err); }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string)$raw, true);
    if ($code !== 200 || empty($data['access_token'])) fb_fail('Firebase refused the service account: ' . ($data['error_description'] ?? $data['error'] ?? 'unknown error'), $code);
    $token = $data['access_token'];
    $exp = $now + (int)($data['expires_in'] ?? 3600);
    @file_put_contents($cache, json_encode(['token' => $token, 'exp' => $exp]), LOCK_EX);
    return $token;
}

/* ---------------- Cloud Firestore (REST) ---------------- */

function fs_base(): string
{
    return 'https://firestore.googleapis.com/v1/projects/' . FB_PROJECT_ID . '/databases/(default)/documents';
}

function fs_encode_value(mixed $v): array
{
    return match (true) {
        $v === null => ['nullValue' => null],
        is_bool($v) => ['booleanValue' => $v],
        is_int($v) => ['integerValue' => (string)$v],
        is_float($v) => ['doubleValue' => $v],
        default => ['stringValue' => (string)$v],
    };
}

function fs_decode_value(array $v): mixed
{
    if (array_key_exists('nullValue', $v)) return null;
    if (isset($v['stringValue'])) return $v['stringValue'];
    if (isset($v['integerValue'])) return (int)$v['integerValue'];
    if (isset($v['doubleValue'])) return (float)$v['doubleValue'];
    if (isset($v['booleanValue'])) return (bool)$v['booleanValue'];
    if (isset($v['timestampValue'])) return $v['timestampValue'];
    return null;
}

/* Returns every document of the given collections as [collection => [id => fields]]. First pages are fetched in parallel. */
function fs_list_many(array $collections): array
{
    $out = [];
    $pending = array_fill_keys($collections, null);   // collection => next page token
    // Some free hosts (InfinityFree) disable curl_multi_*: then the collections are simply read one after another.
    $parallel = function_exists('curl_multi_init') && function_exists('curl_multi_exec');
    while ($pending) {
        $handles = [];
        foreach ($pending as $col => $page) {
            $url = fs_base() . '/' . rawurlencode($col) . '?pageSize=300' . ($page ? '&pageToken=' . rawurlencode($page) : '');
            $handles[$col] = fb_curl($url, 'GET', null, ['Accept: application/json', 'Authorization: Bearer ' . fb_token()]);
        }
        $results = [];   // collection => [raw body, http code, curl error]
        if ($parallel) {
            $mh = curl_multi_init();
            foreach ($handles as $ch) curl_multi_add_handle($mh, $ch);
            do { $status = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running && $status === CURLM_OK);
            foreach ($handles as $col => $ch) {
                $results[$col] = [(string)curl_multi_getcontent($ch), (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), curl_error($ch)];
                curl_multi_remove_handle($mh, $ch); curl_close($ch);
            }
            curl_multi_close($mh);
        } else {
            foreach ($handles as $col => $ch) {
                $raw = curl_exec($ch);
                $results[$col] = [$raw === false ? '' : (string)$raw, (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), curl_error($ch)];
                curl_close($ch);
            }
        }
        $pending = [];
        foreach ($results as $col => [$raw, $code, $err]) {
            if ($code !== 200) fb_fail("Could not read Firestore collection '$col'" . ($err ? ": $err" : ': ' . substr($raw, 0, 300)), $code);
            $data = json_decode($raw, true) ?: [];
            foreach ($data['documents'] ?? [] as $doc) {
                $id = substr($doc['name'], strrpos($doc['name'], '/') + 1);
                $fields = [];
                foreach ($doc['fields'] ?? [] as $k => $v) $fields[$k] = fs_decode_value($v);
                $out[$col][$id] = $fields;
            }
            $out[$col] ??= [];
            if (!empty($data['nextPageToken'])) $pending[$col] = $data['nextPageToken'];
        }
    }
    return $out;
}

/* $writes: list of ['op'=>'set'|'create'|'delete', 'col'=>..., 'id'=>..., 'fields'=>[...]]. Applied atomically (per 400). */
function fs_commit(array $writes): void
{
    foreach (array_chunk($writes, 400) as $chunk) {
        $payload = [];
        foreach ($chunk as $w) {
            $name = 'projects/' . FB_PROJECT_ID . '/databases/(default)/documents/' . $w['col'] . '/' . $w['id'];
            if ($w['op'] === 'delete') { $payload[] = ['delete' => $name]; continue; }
            $enc = [];
            foreach ($w['fields'] as $k => $v) $enc[$k] = fs_encode_value($v);
            $item = ['update' => ['name' => $name, 'fields' => (object)$enc], 'updateMask' => ['fieldPaths' => array_keys($w['fields'])]];
            if ($w['op'] === 'create') $item['currentDocument'] = ['exists' => false];
            $payload[] = $item;
        }
        [$code, $data] = fb_request('POST', fs_base() . ':commit', ['writes' => $payload]);
        if ($code === 409 || ($data['error']['status'] ?? '') === 'ALREADY_EXISTS') fb_fail('A record with that id already exists. Please try again.', 409);
        if ($code !== 200) fb_fail('Firestore write failed: ' . ($data['error']['message'] ?? 'unknown error'), $code);
    }
}

/* ---------------- Realtime Database (REST): report images ---------------- */

function rtdb_url(string $path): string
{
    $segments = array_map('rawurlencode', array_filter(explode('/', $path), static fn($s) => $s !== ''));
    return FB_DATABASE_URL . '/' . implode('/', $segments) . '.json';
}

function rtdb_put(string $path, array $value): void
{
    [$code, $data] = fb_request('PUT', rtdb_url($path) . '?print=silent', $value);   // print=silent: do not send the (huge) image back
    if ($code !== 200 && $code !== 204) fb_fail('Realtime Database write failed: ' . ($data['error'] ?? 'unknown error'), $code);
}

function rtdb_get(string $path): ?array
{
    [$code, $data] = fb_request('GET', rtdb_url($path));
    if ($code !== 200) fb_fail('Realtime Database read failed: ' . ($data['error'] ?? 'unknown error'), $code);
    return is_array($data) ? $data : null;
}

function rtdb_delete(string $path): void
{
    fb_request('DELETE', rtdb_url($path));
}

/* A stored image is addressed by report number + key; the web page URL for it is image.php?r=SF-1001&k=report_5. */
function image_url(string $reportCode, string $key): string
{
    return 'image.php?r=' . rawurlencode($reportCode) . '&k=' . rawurlencode($key);
}

function image_ref_from_url(string $url): ?string
{
    if (preg_match('/^image\.php\?r=(SF-\d+)&k=([A-Za-z0-9_]+)$/', $url, $m)) return 'reportImages/' . $m[1] . '/' . $m[2];
    return null;
}

function image_store(string $reportCode, string $key, array $image): string
{
    $data = file_get_contents($image['tmp']);
    if ($data === false) fb_fail('Could not read the uploaded photo.');
    rtdb_put("reportImages/$reportCode/$key", [
        'reportNumber' => $reportCode, 'photoType' => $image['photo_type'] ?? 'report', 'contentType' => $image['contentType'],
        'bytes' => strlen($data), 'sha256' => hash('sha256', $data), 'imageData' => base64_encode($data),
        'uploadedAt' => (int)(microtime(true) * 1000),
    ]);
    return image_url($reportCode, $key);
}

/* Password check against Firebase Authentication (Identity Toolkit REST). Used for accounts that were registered in the
   StreetFix mobile app: they exist in Firebase Auth and have no password_hash in Firestore.
   Put the Web API key of the SAME Firebase project as FB_PROJECT_ID here (Firebase console > Project settings > General >
   Web API key).. */
const FB_WEB_API_KEY = 'AIzaSyB46NY9NMOzXDdIqlIvAR8sbQJRKrDz_7Y';
function fb_auth_password_ok(string $email, string $password): bool
{
    if (FB_WEB_API_KEY === '') return false;
    $ch = fb_curl('https://identitytoolkit.googleapis.com/v1/accounts:signInWithPassword?key=' . FB_WEB_API_KEY, 'POST',
        json_encode(['email' => $email, 'password' => $password, 'returnSecureToken' => false]),
        ['Content-Type: application/json', 'Accept: application/json']);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $raw !== false && $code === 200;
}
