<?php
declare(strict_types=1);

/* API endpoints must always answer with JSON: hide PHP warnings from the response and turn fatal errors into a JSON message. */
if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
    @ini_set('display_errors', '0');
    ob_start();
    register_shutdown_function(static function (): void {
        $err = error_get_last();
        if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            while (ob_get_level() > 0) { @ob_end_clean(); }
            if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
            error_log('StreetFix fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
            echo json_encode(['ok' => false, 'error' => 'Server error: ' . $err['message'] . ' (' . basename((string)$err['file']) . ':' . $err['line'] . ')']);
        }
    });
}

require_once __DIR__ . '/firebase_pdo.php';
// Reports filed within this many metres of an open report are automatically labelled Duplicate.
const DUPLICATE_RADIUS_METERS = 10;
const REPORT_STATUSES = ['Submitted', 'Under Review', 'Verified', 'Assigned', 'In Progress', 'Resolved', 'Rejected', 'Duplicate', 'Cancelled'];
// A citizen may cancel their own report until a repair crew has been assigned to it.
const CITIZEN_CANCELLABLE_STATUSES = ['Submitted', 'Under Review', 'Verified', 'Duplicate'];
// Cancelling a report nobody has looked at yet (citizens see an auto-flagged duplicate as "Submitted") deletes it for good.
// Once an administrator has started reviewing it, it is kept on record with the status "Cancelled" instead.
const CITIZEN_DELETABLE_STATUSES = ['Submitted', 'Duplicate'];

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Session cookie (no lifetime) is dropped when the browser closes; 'secure' applies automatically on HTTPS hosting.
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    // Keep sessions in config/sessions (blocked from the web by config/.htaccess) instead of the shared XAMPP folder
    // (C:\xampp\tmp), which on Windows often denies write access even when is_writable() says yes.
    // Each candidate folder is verified by really creating and deleting a file before it is used.
    // Mobile (API) sessions last 30 days of inactivity; website pages keep the 30-minute idle limit.
    // PHP's default gc_maxlifetime (24 min) silently deletes session files, which logs the app out mid-use.
    $isApi = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/');
    define('SF_IDLE_LIMIT', 30 * 86400);
    @ini_set('session.gc_maxlifetime', (string)SF_IDLE_LIMIT);
    $sessCandidates = [
        __DIR__ . DIRECTORY_SEPARATOR . 'sessions',
        rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'streetfix_sessions',
        (string)session_save_path(),
    ];
    foreach ($sessCandidates as $sessDir) {
        if ($sessDir === '') continue;
        if (!is_dir($sessDir)) @mkdir($sessDir, 0777, true);
        if (!is_dir($sessDir)) continue;
        $probe = $sessDir . DIRECTORY_SEPARATOR . '.probe_' . bin2hex(random_bytes(4));
        if (@file_put_contents($probe, '1') !== false) {
            @unlink($probe);
            session_save_path($sessDir);
            break;
        }
    }
    // A stale cookie may point to a session file in the old folder that cannot be read: start a fresh session instead of warning.
    if (!@session_start()) {
        session_id(bin2hex(random_bytes(16)));
        @session_start();
    }
    // Sign out after inactivity (30 min on website pages, 30 days for the mobile API).
    $idleLimit = $isApi ? SF_IDLE_LIMIT : 1800;
    if (!empty($_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > $idleLimit) {
        $_SESSION = [];
    }
    $_SESSION['last_activity'] = time();
}

set_exception_handler(static function (Throwable $e): void {
    error_log('StreetFix error: ' . $e->getMessage());
    $backend = $e instanceof RuntimeException || $e instanceof PDOException;
    $message = $backend
        ? 'Cannot connect to the StreetFix database (Firebase). Reason: ' . $e->getMessage() . ' (upload hosting_check.php once to see which step fails, then delete it).'
        : 'Something went wrong. Please try again.' . ((in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || is_file(__DIR__ . '/debug.txt')) ? ' [' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ']' : '');   // create an empty config/debug.txt on the host to see the real reason; delete it afterwards
    if (!headers_sent()) http_response_code(500);
    if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $message]);
    } else {
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>StreetFix unavailable</title><p>' . htmlspecialchars($message) . '</p></html>';
    }
    exit;
});

function db(): FbPDO
{
    static $fb;
    return $fb ??= new FbPDO();
}

/* Capitalizes the first letter of every word in a person's name ("juan dela cruz" -> "Juan Dela Cruz").
   Letters after the first are left as typed so names such as "McDonald" are not damaged. */
function format_person_name(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    return preg_replace_callback('/(^|[\s\-\'’])(\p{L})/u', static fn(array $m): string => $m[1] . mb_strtoupper($m[2]), $name);
}

function lgu_for_coordinates($pdo, float $latitude, float $longitude): array
{
    static $lgus = null;
    if ($lgus === null) {
        $lgus = $pdo->query('SELECT id, name, boundary_geojson FROM lgus')->fetchAll();
        foreach ($lgus as &$cached) $cached['geometry'] = json_decode((string)$cached['boundary_geojson'], true, 512, JSON_THROW_ON_ERROR);
        unset($cached);
    }
    $matches = [];
    foreach ($lgus as $lgu) {
        $geometry = $lgu['geometry'];
        if (geojson_contains_point($geometry, $longitude, $latitude)) {
            $matches[] = ['id' => (int)$lgu['id'], 'name' => $lgu['name']];
        }
    }
    if (count($matches) === 1) return ['status' => 'matched', 'lgu' => $matches[0]];
    return ['status' => $matches ? 'manual_review' : 'outside', 'lgu' => null];
}

function ensure_lgu_maintenance_team($pdo, int $lguId): array
{
    $stmt = $pdo->prepare('SELECT id, name, lgu_id FROM maintenance_teams WHERE lgu_id = ?');
    $stmt->execute([$lguId]);
    $team = $stmt->fetch();
    if ($team) return $team;

    $lguStmt = $pdo->prepare('SELECT name FROM lgus WHERE id = ?');
    $lguStmt->execute([$lguId]);
    $lguName = $lguStmt->fetchColumn();
    if (!$lguName) throw new RuntimeException('The selected LGU does not exist.');

    $name = $lguName . ' Maintenance Team';
    $insert = $pdo->prepare('INSERT INTO maintenance_teams (name, lgu_id) VALUES (?, ?)');
    try {
        $insert->execute([$name, $lguId]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() !== '23000') throw $e;
        $stmt->execute([$lguId]);
        $team = $stmt->fetch();
        if ($team) return $team;
        $name = mb_substr($lguName, 0, 70) . ' Maintenance Team ' . $lguId;
        $insert->execute([$name, $lguId]);
    }
    return ['id' => (int)$pdo->lastInsertId(), 'name' => $name, 'lgu_id' => $lguId];
}

function geojson_contains_point(array $geometry, float $longitude, float $latitude): bool
{
    $polygons = match ($geometry['type'] ?? null) {
        'Polygon' => [$geometry['coordinates'] ?? []],
        'MultiPolygon' => $geometry['coordinates'] ?? [],
        default => [],
    };
    foreach ($polygons as $polygon) {
        if (!isset($polygon[0]) || !geojson_ring_contains_point($polygon[0], $longitude, $latitude)) continue;
        foreach (array_slice($polygon, 1) as $hole) {
            if (geojson_ring_contains_point($hole, $longitude, $latitude)) continue 2;
        }
        return true;
    }
    return false;
}

function geojson_ring_contains_point(array $ring, float $longitude, float $latitude): bool
{
    $inside = false;
    $last = count($ring) - 1;
    for ($i = 0; $i < $last; $i++) {
        $a = $ring[$i];
        $b = $ring[$i + 1];
        $cross = ($longitude - (float)$a[0]) * ((float)$b[1] - (float)$a[1])
            - ($latitude - (float)$a[1]) * ((float)$b[0] - (float)$a[0]);
        if (abs($cross) < 1e-9 &&
            $longitude >= min((float)$a[0], (float)$b[0]) - 1e-9 &&
            $longitude <= max((float)$a[0], (float)$b[0]) + 1e-9 &&
            $latitude >= min((float)$a[1], (float)$b[1]) - 1e-9 &&
            $latitude <= max((float)$a[1], (float)$b[1]) + 1e-9) {
            return true;
        }
        if (((float)$a[1] > $latitude) !== ((float)$b[1] > $latitude) &&
            $longitude < ((float)$b[0] - (float)$a[0]) * ($latitude - (float)$a[1])
                / ((float)$b[1] - (float)$a[1]) + (float)$a[0]) {
            $inside = !$inside;
        }
    }
    return $inside;
}

function respond(array $data, int $code = 200): never
{
    while (ob_get_level() > 0) { @ob_end_clean(); }   // drop any stray warning text so the body is pure JSON
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function post_only(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(['ok' => false, 'error' => 'POST required.'], 405);
    }
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

/* Releases the session lock so one page's parallel requests are not queued behind each other. Only done on a local
   server: free hosts cap the number of simultaneous PHP processes, and parallel requests could hit that cap. */
function sf_release_session(): void
{
    if (in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) session_write_close();
}

function require_login(?string $role = null): array
{
    if (empty($_SESSION['user_id'])) {
        respond(['ok' => false, 'code' => 'session_missing', 'error' => 'Please log in.'], 401);
    }
    // A slow or failed backend read is NOT a logout: answer 503 and keep the session so the app can simply retry.
    try {
        $stmt = db()->prepare('SELECT id, first_name, last_name, role, team_id, lgu_id, is_active FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('StreetFix require_login backend error: ' . $e->getMessage());
        respond(['ok' => false, 'code' => 'backend_unavailable', 'error' => 'The server could not load your profile right now. Please try again.'], 503);
    }
    if ($user === false || $user === null) {
        // Missing profile: only a genuine removal if the users collection itself loaded with other accounts in it.
        $others = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($others > 0) {
            $_SESSION = [];
            session_destroy();
            respond(['ok' => false, 'code' => 'account_removed', 'error' => 'This account no longer exists. Please contact your administrator.'], 401);
        }
        respond(['ok' => false, 'code' => 'profile_unavailable', 'error' => 'Your profile could not be loaded. Please try again.'], 503);
    }
    if (!(int)$user['is_active']) {
        $_SESSION = [];
        session_destroy();
        respond(['ok' => false, 'code' => 'account_disabled', 'error' => 'Your account has been deactivated. Contact your administrator.'], 401);
    }
    if ($role !== null && $user['role'] !== $role) {
        respond(['ok' => false, 'code' => 'forbidden_role', 'error' => 'You do not have access.'], 403);
    }
    sf_release_session();
    return $user;
}

function require_page_role(string $role): array
{
    if (empty($_SESSION['user_id'])) {
        header('Location: ../auth/login.php');
        exit;
    }
    $stmt = db()->prepare('SELECT id, first_name, last_name, role, team_id, lgu_id, is_active FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user || !(int)$user['is_active']) {
        $_SESSION = [];
        session_destroy();
        header('Location: ../auth/login.php');
        exit;
    }
    if ($user['role'] !== $role) {
        header('Location: ../index.php');
        exit;
    }
    sf_release_session();
    return $user;
}

function report_code(int $id): string
{
    return 'SF-' . (1000 + $id);
}

function generate_secure_password(): string
{
    return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
}

function lgu_maintenance_username(string $lguName, int $lguId): string
{
    $preferredUsernames = [
        'City of Baliwag' => 'maintenance.baliwag@streetfix.local',
        'City of Malolos' => 'maintenance.malolos@streetfix.local',
        'City of Meycauayan' => 'maintenance.meycauayan@streetfix.local',
        'City of San Jose del Monte' => 'maintenance.sjdm@streetfix.local',
        'San Ildefonso' => 'maintenance.sanildefonso@streetfix.local',
        'Dona Remedios Trinidad' => 'maintenance.drt@streetfix.local',
    ];
    if (isset($preferredUsernames[$lguName])) return $preferredUsernames[$lguName];

    $asciiName = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lguName);
    $normalizedName = strtolower($asciiName === false ? '' : $asciiName);
    $slug = trim((string)preg_replace('/[^a-z0-9]+/', '.', $normalizedName), '.');
    return 'maintenance.' . ($slug !== '' ? $slug : 'lgu-' . $lguId) . '@streetfix.local';
}

function record_status($pdo, int $reportId, ?int $changedBy, string $status, ?string $note = null): void
{
    $stmt = $pdo->prepare('INSERT INTO status_history (report_id, changed_by, status, note) VALUES (?, ?, ?, ?)');
    $stmt->execute([$reportId, $changedBy, $status, $note]);
}

/* Reporters whose reports were grouped under $rootId as duplicates get the same progress updates as the
   original reporter. Each duplicate reporter is notified against their own report id. */
function notify_duplicate_reporters($pdo, int $rootId, string $event): void
{
    $stmt = $pdo->prepare('SELECT d.id, d.user_id FROM reports d JOIN reports p ON p.id = d.duplicate_of
                           WHERE d.duplicate_of = ? AND d.user_id <> p.user_id');
    $stmt->execute([$rootId]);
    foreach ($stmt->fetchAll() as $dup) {
        notify_reporter($pdo, (int)$dup['user_id'], (int)$dup['id'],
            $event . ' for ' . report_code($rootId) . ', the pothole you reported as ' . report_code((int)$dup['id']) . '.');
    }
}

/* Combined severity of a pothole's group: every distinct severity filed by its reporters, Low to High. */
function severity_range(string $own, ?string $groupCsv): array
{
    $rank = ['Low' => 1, 'Medium' => 2, 'High' => 3];
    $set = array_unique(array_merge([$own], $groupCsv ? explode(',', $groupCsv) : []));
    $set = array_values(array_filter($set, static fn($s) => isset($rank[$s])));
    usort($set, static fn($a, $b) => $rank[$a] <=> $rank[$b]);
    return $set ?: [$own];
}

function notify_reporter($pdo, int $userId, int $reportId, string $message): void
{
    $stmt = $pdo->prepare('INSERT INTO notifications (user_id, report_id, message) VALUES (?, ?, ?)');
    $stmt->execute([$userId, $reportId, $message]);
}

function notify_lgu($pdo, int $lguId, int $reportId, string $message): void
{
    $pdo->prepare('INSERT INTO lgu_notifications (lgu_id, report_id, message) VALUES (?, ?, ?)')
        ->execute([$lguId, $reportId, $message]);
    $users = $pdo->prepare("SELECT id FROM users WHERE lgu_id = ? AND role = 'maintenance' AND is_active = 1");
    $users->execute([$lguId]);
    foreach ($users->fetchAll() as $user) {
        notify_reporter($pdo, (int)$user['id'], $reportId, $message);
    }
}

/* Validates an uploaded photo and returns it for storage in the Realtime Database (see image_store()).
   Nothing is saved yet: the photo is stored under its report number once the report exists. */
function image_upload(array $file, string $prefix): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Choose a photo to upload.');
    }
    if (($file['size'] ?? 0) > 8 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new RuntimeException('Photo must be a valid image no larger than 8 MB.');
    }
    $types = ['image/jpeg', 'image/png', 'image/webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $contentType = (string)$finfo->file($file['tmp_name']);
    if (!in_array($contentType, $types, true) || @getimagesize($file['tmp_name']) === false) {
        throw new RuntimeException('Photo must be a valid JPG, PNG, or WEBP image.');
    }
    return ['tmp' => $file['tmp_name'], 'contentType' => $contentType, 'bytes' => (int)$file['size'], 'photo_type' => $prefix === 'report' ? 'report' : $prefix];
}

/* ---------- Profile (shared by the citizen and maintenance modules) ---------- */

function profile_payload($pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT u.first_name, u.middle_name, u.last_name, u.name_extension, u.email, u.age, u.birthdate,
                                  u.contact_number, u.address, u.role, u.created_at, l.name AS lgu_name, t.name AS team_name
                           FROM users u
                           LEFT JOIN lgus l ON l.id = u.lgu_id
                           LEFT JOIN maintenance_teams t ON t.id = u.team_id
                           WHERE u.id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch() ?: [];
    return [
        'first_name' => $row['first_name'] ?? '', 'middle_name' => $row['middle_name'] ?? '',
        'last_name' => $row['last_name'] ?? '', 'name_extension' => $row['name_extension'] ?? '',
        'email' => $row['email'] ?? '', 'age' => (int)($row['age'] ?? 0), 'birthdate' => $row['birthdate'] ?? '',
        'contact_number' => $row['contact_number'] ?? '', 'address' => $row['address'] ?? '',
        'role' => $row['role'] ?? '', 'lgu_name' => $row['lgu_name'] ?? '', 'team_name' => $row['team_name'] ?? '',
        'member_since' => substr((string)($row['created_at'] ?? ''), 0, 10),
    ];
}

/* GET returns the signed-in user's profile. POST {action:'update'} edits the details and
   POST {action:'password'} changes the password. Citizens can edit their email (it is their login);
   maintenance emails are the usernames issued by an administrator, so they stay read-only. */
function profile_api(array $user): never
{
    $pdo = db();
    $id = (int)$user['id'];
    $isCitizen = $user['role'] === 'citizen';
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        respond(['ok' => true, 'profile' => profile_payload($pdo, $id)]);
    }
    $in = post_only();
    $action = (string)($in['action'] ?? '');

    if ($action === 'password') {
        $current = (string)($in['current_password'] ?? '');
        $new = (string)($in['new_password'] ?? '');
        $confirm = (string)($in['confirm_password'] ?? '');
        $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $stored = (string)$stmt->fetchColumn();
        $matches = password_is_hashed($stored) ? password_verify($current, $stored) : hash_equals($stored, $current);
        if (!$matches) respond(['ok' => false, 'error' => 'Your current password is incorrect.'], 422);
        if (strlen($new) < 8) respond(['ok' => false, 'error' => 'New password must be at least 8 characters.'], 422);
        if (mb_strlen($new) > 255) respond(['ok' => false, 'error' => 'New password must be no more than 255 characters.'], 422);
        if ($new !== $confirm) respond(['ok' => false, 'error' => 'New passwords do not match.'], 422);
        if (hash_equals($current, $new)) respond(['ok' => false, 'error' => 'Choose a password different from your current one.'], 422);
        $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $id]);
        respond(['ok' => true]);
    }
    if ($action !== 'update') respond(['ok' => false, 'error' => 'Unknown action.'], 400);

    $s = static fn(string $k): string => trim((string)($in[$k] ?? ''));
    $first = format_person_name($s('first_name'));
    $middle = format_person_name($s('middle_name'));
    $last = format_person_name($s('last_name'));
    $ext = $s('name_extension');
    $birth = $s('birthdate');
    $contact = preg_replace('/[\s-]/', '', $s('contact_number'));
    $addr = $s('address');
    $errors = [];
    if ($first === '' || mb_strlen($first) > 60) $errors[] = 'First name is required (max 60 characters).';
    if ($last === '' || mb_strlen($last) > 60) $errors[] = 'Last name is required (max 60 characters).';
    if (mb_strlen($middle) > 60) $errors[] = 'Middle name is too long.';
    if (!in_array($ext, ['', 'Jr.', 'Sr.', 'II', 'III', 'IV', 'V'], true)) $errors[] = 'Invalid name extension.';
    $d = DateTime::createFromFormat('Y-m-d', $birth);
    $age = 0;
    if (!$d || $d->format('Y-m-d') !== $birth || $d > new DateTime('today')) {
        $errors[] = 'Enter a valid birthdate.';
    } else {
        $age = (int)$d->diff(new DateTime('today'))->y;
        if ($age < 13 || $age > 120) $errors[] = 'Age must be between 13 and 120.';
    }
    if ($contact === '' ? $isCitizen : !preg_match('/^(09\d{9}|\+639\d{9})$/', $contact)) {
        $errors[] = 'Contact number must look like 09171234567 or +639171234567.';
    }
    if (($isCitizen && $addr === '') || mb_strlen($addr) > 255) $errors[] = 'Address is required (max 255 characters).';

    $email = null;
    if ($isCitizen) {
        $email = strtolower($s('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            $errors[] = 'Enter a valid email address.';
        } else {
            $dup = $pdo->prepare('SELECT 1 FROM users WHERE email = ? AND id <> ?');
            $dup->execute([$email, $id]);
            if ($dup->fetch()) $errors[] = 'That email is already used by another account.';
        }
    }
    if ($errors) respond(['ok' => false, 'error' => implode(' ', $errors)], 422);

    $sql = 'UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, name_extension = ?, birthdate = ?, age = ?,
                             contact_number = ?, address = ?' . ($isCitizen ? ', email = ?' : '') . ' WHERE id = ?';
    $params = [$first, $middle ?: null, $last, $ext ?: null, $birth, $age, $contact, $addr];
    if ($isCitizen) $params[] = $email;
    $params[] = $id;
    $pdo->prepare($sql)->execute($params);
    respond(['ok' => true, 'profile' => profile_payload($pdo, $id)]);
}
