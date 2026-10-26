<?php
require __DIR__ . '/../../config/config.php';
$admin = require_login('admin');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $users = $pdo->query('SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.is_active, u.team_id, t.name AS team_name,
                                 u.lgu_id, l.name AS lgu_name, u.created_at
                          FROM users u LEFT JOIN maintenance_teams t ON t.id = u.team_id
                          LEFT JOIN lgus l ON l.id = u.lgu_id
                          ORDER BY u.role, u.last_name, u.first_name')->fetchAll();
    $teams = $pdo->query("SELECT t.id, t.name, t.lgu_id, l.name AS lgu_name,
                                 COUNT(DISTINCT CASE WHEN u.role = 'maintenance' AND u.is_active = 1 THEN u.id END) AS active_members
                          FROM maintenance_teams t LEFT JOIN lgus l ON l.id = t.lgu_id
                          LEFT JOIN users u ON u.team_id = t.id
                          GROUP BY t.id, t.name, t.lgu_id, l.name ORDER BY l.name, t.name")->fetchAll();
    $lgus = $pdo->query('SELECT id, name FROM lgus ORDER BY name')->fetchAll();
    respond(['ok' => true, 'users' => $users, 'teams' => $teams, 'lgus' => $lgus]);
}
$input = post_only();
$action = (string)($input['action'] ?? '');
if ($action === 'provision_lgu_accounts') {
    $pdo->beginTransaction();
    try {
        $lgus = $pdo->query('SELECT id, name FROM lgus ORDER BY id FOR UPDATE')->fetchAll();
        $findAccount = $pdo->prepare("SELECT id, email FROM users
                                      WHERE role = 'maintenance'
                                        AND (team_id = ? OR (lgu_id = ? AND (team_id IS NULL OR team_id = ?)))
                                      ORDER BY (team_id = ?) DESC, id LIMIT 1");
        $linkAccount = $pdo->prepare("UPDATE users SET team_id = ?, lgu_id = ? WHERE id = ? AND role = 'maintenance'");
        $insertAccount = $pdo->prepare("INSERT INTO users (first_name, last_name, email, password, role, team_id, lgu_id)
                                        VALUES (?, ?, ?, ?, 'maintenance', ?, ?)");
        $emailExists = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
        $credentials = [];
        foreach ($lgus as $lgu) {
            $lguId = (int)$lgu['id'];
            $team = ensure_lgu_maintenance_team($pdo, $lguId);
            $findAccount->execute([$team['id'], $lguId, $team['id'], $team['id']]);
            $existing = $findAccount->fetch();
            if ($existing) {
                $linkAccount->execute([$team['id'], $lguId, $existing['id']]);
                continue;
            }

            $email = lgu_maintenance_username((string)$lgu['name'], $lguId);
            $base = substr($email, 0, -strlen('@streetfix.local'));
            $suffix = 1;
            do {
                $emailExists->execute([$email]);
                if (!$emailExists->fetchColumn()) break;
                $email = $base . '.' . $suffix++ . '@streetfix.local';
            } while (true);

            $password = generate_secure_password();
            $insertAccount->execute([
                'Maintenance',
                mb_substr((string)$lgu['name'], 0, 60),
                $email,
                $password,
                $team['id'],
                $lguId,
            ]);
            $credentials[] = ['lgu' => $lgu['name'], 'username' => $email, 'password' => $password];
        }
        $pdo->commit();
        respond(['ok' => true, 'total' => count($lgus), 'created' => count($credentials), 'credentials' => $credentials]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
if ($action === 'reset_all_maintenance_passwords') {
    $accounts = $pdo->query("SELECT u.id, u.email, l.name AS lgu_name, t.name AS team_name
                             FROM users u
                             LEFT JOIN maintenance_teams t ON t.id = u.team_id
                             LEFT JOIN lgus l ON l.id = t.lgu_id
                             WHERE u.role = 'maintenance'
                             ORDER BY l.name, u.email")->fetchAll();
    $update = $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'maintenance'");
    $credentials = [];
    $pdo->beginTransaction();
    try {
        foreach ($accounts as $account) {
            $password = generate_secure_password();
            $update->execute([$password, $account['id']]);
            $credentials[] = [
                'lgu' => $account['lgu_name'] ?: ($account['team_name'] ?: 'Maintenance Team'),
                'username' => $account['email'],
                'password' => $password,
            ];
        }
        $pdo->commit();
        respond(['ok' => true, 'count' => count($credentials), 'credentials' => $credentials]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
if ($action === 'reset_password') {
    $userId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$userId) respond(['ok' => false, 'error' => 'Choose a valid maintenance account.'], 422);
    $password = generate_secure_password();
    $update = $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'maintenance'");
    $update->execute([$password, $userId]);
    if (!$update->rowCount()) respond(['ok' => false, 'error' => 'Maintenance account not found.'], 404);
    $email = $pdo->prepare('SELECT email FROM users WHERE id = ?');
    $email->execute([$userId]);
    respond(['ok' => true, 'credentials' => [[
        'lgu' => '',
        'username' => (string)$email->fetchColumn(),
        'password' => $password,
    ]]]);
}
if ($action === 'create_team') {
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 100) respond(['ok' => false, 'error' => 'Enter a team name up to 100 characters.'], 422);
    try {
        $pdo->prepare('INSERT INTO maintenance_teams (name) VALUES (?)')->execute([$name]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') respond(['ok' => false, 'error' => 'A team with that name already exists.'], 409);
        throw $e;
    }
    respond(['ok' => true], 201);
}
if ($action === 'create_lgu_team') {
    $lguId = filter_var($input['lgu_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$lguId) respond(['ok' => false, 'error' => 'Choose a valid LGU.'], 422);
    $team = ensure_lgu_maintenance_team($pdo, (int)$lguId);
    respond(['ok' => true, 'team' => $team], 201);
}
if ($action === 'create_maintenance') {
    $first = trim((string)($input['first_name'] ?? ''));
    $last = trim((string)($input['last_name'] ?? ''));
    $first = format_person_name($first);
    $last = format_person_name($last);
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $password = (string)($input['password'] ?? '');
    $teamId = filter_var($input['team_id'] ?? null, FILTER_VALIDATE_INT);
    if ($first === '' || mb_strlen($first) > 60 || $last === '' || mb_strlen($last) > 60 ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || strlen($password) < 8 || strlen($password) > 255 ||
        !$teamId) {
        respond(['ok' => false, 'error' => 'Enter valid names, email, maintenance team, and a password of at least 8 characters.'], 422);
    }
    $validTeam = $pdo->prepare('SELECT lgu_id FROM maintenance_teams WHERE id = ?');
    $validTeam->execute([$teamId]);
    $lguId = $validTeam->fetchColumn();
    if ($lguId === false || $lguId === null) respond(['ok' => false, 'error' => 'Choose an LGU Maintenance Team.'], 422);
    $exists = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
    $exists->execute([$email]);
    if ($exists->fetch()) respond(['ok' => false, 'error' => 'That email is already in use.'], 409);
    $insert = $pdo->prepare("INSERT INTO users (first_name, last_name, email, password, role, team_id, lgu_id) VALUES (?, ?, ?, ?, 'maintenance', ?, ?)");
    $insert->execute([$first, $last, $email, $password, $teamId, $lguId]);
    respond(['ok' => true], 201);
}
if ($action === 'set_team') {
    $userId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    $teamId = filter_var($input['team_id'] ?? null, FILTER_VALIDATE_INT);
    $validTeam = $pdo->prepare('SELECT lgu_id FROM maintenance_teams WHERE id = ?');
    $validTeam->execute([$teamId]);
    $lguId = $validTeam->fetchColumn();
    if (!$userId || !$teamId || $lguId === false || $lguId === null) respond(['ok' => false, 'error' => 'Choose a valid LGU Maintenance Team.'], 422);
    $activeTask = $pdo->prepare("SELECT 1 FROM assignments a JOIN reports r ON r.id = a.report_id
                                 JOIN users u ON u.id = ?
                                 WHERE a.active = 1 AND r.status IN ('Assigned','In Progress')
                                   AND (a.team_id = u.team_id OR (a.team_id IS NULL AND a.maintenance_user_id = u.id)) LIMIT 1");
    $activeTask->execute([$userId]);
    if ($activeTask->fetchColumn()) respond(['ok' => false, 'error' => 'Finish or reassign this user’s team tasks before changing membership.'], 409);
    $update = $pdo->prepare("UPDATE users SET team_id = ?, lgu_id = ? WHERE id = ? AND role = 'maintenance'");
    $update->execute([$teamId, $lguId, $userId]);
    if (!$update->rowCount()) respond(['ok' => false, 'error' => 'Maintenance user not found or already in that team.'], 404);
    respond(['ok' => true]);
}
if ($action === 'set_active') {
    $userId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    $active = filter_var($input['active'] ?? null, FILTER_VALIDATE_INT);
    if (!$userId || !in_array($active, [0, 1], true) || $userId === (int)$admin['id']) {
        respond(['ok' => false, 'error' => 'Invalid account change.'], 422);
    }
    if ($active === 0) {
        $assigned = $pdo->prepare("SELECT 1 FROM reports r JOIN users u ON u.id = ?
                                   LEFT JOIN assignments a ON a.report_id = r.id
                                   WHERE r.status IN ('Assigned','In Progress')
                                     AND a.active = 1
                                     AND (a.team_id = u.team_id OR (a.team_id IS NULL AND a.maintenance_user_id = u.id)) LIMIT 1");
        $assigned->execute([$userId]);
        if ($assigned->fetch()) respond(['ok' => false, 'error' => 'Reassign this user’s active tasks before deactivating the account.'], 409);
    }
    $update = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ? AND role <> 'admin'");
    $update->execute([$active, $userId]);
    if (!$update->rowCount()) respond(['ok' => false, 'error' => 'User not found or administrator accounts cannot be changed here.'], 404);
    respond(['ok' => true]);
}
respond(['ok' => false, 'error' => 'Unknown user action.'], 422);
