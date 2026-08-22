<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/RegistryRepository.php';
require_once __DIR__ . '/../src/ParticipantRepository.php';
require_once __DIR__ . '/../src/ScreeningRepository.php';
require_once __DIR__ . '/../src/EventRepository.php';
require_once __DIR__ . '/../src/FollowUpRepository.php';
require_once __DIR__ . '/../src/ReportsRepository.php';
require_once __DIR__ . '/../src/AuthRepository.php';

Config::load(__DIR__ . '/../.env');

$allowedOrigin = Config::get('CORS_ORIGIN', '*');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Headers: Content-Type, X-Registry-Admin-Key, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $_POST;
}

function getTokenFromRequest(): ?string
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) return $m[1];
    return null;
}

function authRequired(): array
{
    $token = getTokenFromRequest();
    if ($token) {
        $db = (new Database())->getConnection();
        $auth = new AuthRepository($db);
        $user = $auth->validateToken($token);
        if ($user) return $user;
    }

    $adminKey = Config::get('REGISTRY_ADMIN_KEY');
    $provided = $_SERVER['HTTP_X_REGISTRY_ADMIN_KEY'] ?? '';
    if ($adminKey && hash_equals($adminKey, $provided)) {
        return ['id' => null, 'role' => 'ADMINISTRATOR', 'username' => 'admin_api'];
    }

    jsonResponse(['success' => false, 'error' => 'Authorization required.'], 401);
    return [];
}

function roleRequired(string ...$roles): array
{
    $user = authRequired();
    if (!in_array($user['role'], $roles, true)) {
        jsonResponse(['success' => false, 'error' => 'Insufficient permissions.'], 403);
    }
    return $user;
}

function adminRequired(): array { return roleRequired('ADMINISTRATOR'); }
function supervisorRequired(): array { return roleRequired('ADMINISTRATOR', 'SUPERVISOR'); }
function dataEntryRequired(): array { return roleRequired('ADMINISTRATOR', 'SUPERVISOR', 'DATA_ENTRY'); }

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $path = rtrim($path, '/') ?: '/';

    // === HEALTH ===
    if ($path === '/' || $path === '/api' || $path === '/api/v1' || $path === '/api/v1/health') {
        jsonResponse(['success' => true, 'message' => 'TSCA Registry API is running', 'version' => '3.0.0']);
    }

    $db = (new Database())->getConnection();
    $authRepo = new AuthRepository($db);

    // ==========================================
    // AUTH ENDPOINTS
    // ==========================================

    // Bootstrap: create first Administrator
    if ($path === '/api/v1/registry/auth/bootstrap' && $method === 'POST') {
        $data = body();
        if (empty($data['secret'])) jsonResponse(['success' => false, 'error' => 'Bootstrap secret required.'], 422);
        if (empty($data['username']) || trim($data['username']) === '') jsonResponse(['success' => false, 'error' => 'Username required.'], 422);
        if (empty($data['full_name']) || trim($data['full_name']) === '') jsonResponse(['success' => false, 'error' => 'Full name required.'], 422);
        if (empty($data['password']) || strlen($data['password']) < 6) jsonResponse(['success' => false, 'error' => 'Password must be at least 6 characters.'], 422);
        if (($data['password'] ?? '') !== ($data['confirm_password'] ?? '')) jsonResponse(['success' => false, 'error' => 'Passwords do not match.'], 422);

        $result = $authRepo->bootstrapAdmin($data['secret'], $data['username'], $data['username'], $data['password'], $data['full_name']);
        if (!$result) jsonResponse(['success' => false, 'error' => 'Invalid bootstrap secret.'], 403);
        if (isset($result['error']) && $result['error'] === 'bootstrap_completed') jsonResponse(['success' => false, 'error' => 'Administrator already exists. Bootstrap is complete.'], 409);
        jsonResponse(['success' => true, 'message' => 'Administrator created. You may now log in.', 'data' => ['id' => $result['id'], 'username' => $result['username'], 'role' => $result['role']]], 201);
    }

    // Check if bootstrap is needed
    if ($path === '/api/v1/registry/auth/bootstrap-status' && $method === 'GET') {
        jsonResponse(['success' => true, 'data' => ['bootstrap_complete' => $authRepo->bootstrapComplete()]]);
    }

    // Login
    if ($path === '/api/v1/registry/auth/login' && $method === 'POST') {
        $data = body();
        if (empty($data['identifier']) || empty($data['password'])) jsonResponse(['success' => false, 'error' => 'Username and password required.'], 422);
        $result = $authRepo->login($data['identifier'], $data['password']);
        if (!$result) jsonResponse(['success' => false, 'error' => 'Invalid credentials.'], 401);
        jsonResponse(['success' => true, 'data' => $result]);
    }

    // Key-based login (admin key bypass)
    if ($path === '/api/v1/registry/auth/key-login' && $method === 'POST') {
        $data = body();
        $adminKey = Config::get('REGISTRY_ADMIN_KEY');
        if (empty($data['key']) || !$adminKey || !hash_equals($adminKey, $data['key'])) {
            jsonResponse(['success' => false, 'error' => 'Invalid admin key.'], 401);
        }
        $stmt = $db->prepare("SELECT id, username, full_name, role FROM tsca_users WHERE role = 'ADMINISTRATOR' AND status = 'ACTIVE' LIMIT 1");
        $stmt->execute();
        $admin = $stmt->fetch();
        if (!$admin) jsonResponse(['success' => false, 'error' => 'No administrator account found.'], 404);
        $token = bin2hex(random_bytes(64));
        $expires = date('Y-m-d H:i:s', time() + 86400);
        $ins = $db->prepare('INSERT INTO user_sessions (user_id, token, ip_address, user_agent, expires_at) VALUES (:uid, :token, :ip, :ua, :expires)');
        $ins->execute([':uid' => $admin['id'], ':token' => $token, ':ip' => $_SERVER['REMOTE_ADDR'] ?? null, ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null, ':expires' => $expires]);
        $db->prepare('UPDATE tsca_users SET last_login_at = NOW() WHERE id = :id')->execute([':id' => $admin['id']]);
        jsonResponse(['success' => true, 'data' => ['token' => $token, 'user' => ['id' => $admin['id'], 'username' => $admin['username'], 'full_name' => $admin['full_name'], 'role' => $admin['role']]]]);
    }

    // Logout
    if ($path === '/api/v1/registry/auth/logout' && $method === 'POST') {
        $token = getTokenFromRequest();
        if ($token) $authRepo->logout($token);
        jsonResponse(['success' => true, 'message' => 'Logged out.']);
    }

    // Current user
    if ($path === '/api/v1/registry/auth/me' && $method === 'GET') {
        $user = authRequired();
        jsonResponse(['success' => true, 'data' => [
            'authenticated' => true,
            'user' => ['id' => $user['id'], 'username' => $user['username'], 'full_name' => $user['full_name'] ?? '', 'role' => $user['role']]
        ]]);
    }

    // ==========================================
    // USER MANAGEMENT (Admin only)
    // ==========================================

    if ($path === '/api/v1/registry/users' && $method === 'GET') {
        adminRequired();
        jsonResponse(['success' => true, 'data' => $authRepo->listUsers()]);
    }

    if ($path === '/api/v1/registry/users' && $method === 'POST') {
        adminRequired();
        $data = body();
        if (empty($data['username']) || trim($data['username']) === '') jsonResponse(['success' => false, 'error' => 'Username required.'], 422);
        if (empty($data['full_name']) || trim($data['full_name']) === '') jsonResponse(['success' => false, 'error' => 'Full name required.'], 422);
        if (empty($data['password']) || strlen($data['password']) < 6) jsonResponse(['success' => false, 'error' => 'Password must be at least 6 characters.'], 422);
        if (empty($data['role']) || !in_array($data['role'], ['DATA_ENTRY', 'SUPERVISOR', 'ADMINISTRATOR'], true)) jsonResponse(['success' => false, 'error' => 'Valid role required (DATA_ENTRY, SUPERVISOR, ADMINISTRATOR).'], 422);
        try {
            $created = $authRepo->createUser($data);
            jsonResponse(['success' => true, 'message' => 'User created.', 'data' => $created], 201);
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') jsonResponse(['success' => false, 'error' => 'Username already exists.'], 409);
            throw $e;
        }
    }

    if (preg_match('#^/api/v1/registry/users/(\d+)$#', $path, $matches)) {
        $uid = (int)$matches[1];
        $user = authRequired();

        if ($method === 'GET') {
            if ($user['role'] !== 'ADMINISTRATOR' && $user['id'] != $uid) jsonResponse(['success' => false, 'error' => 'Forbidden.'], 403);
            $record = $authRepo->findUser($uid);
            $record ? jsonResponse(['success' => true, 'data' => $record]) : jsonResponse(['success' => false, 'error' => 'Not found.'], 404);
        }

        if ($method === 'PATCH' || $method === 'PUT') {
            adminRequired();
            $data = body();
            if (isset($data['role']) && !in_array($data['role'], ['DATA_ENTRY', 'SUPERVISOR', 'ADMINISTRATOR'], true)) jsonResponse(['success' => false, 'error' => 'Invalid role.'], 422);
            if (isset($data['status']) && !in_array($data['status'], ['ACTIVE', 'DISABLED'], true)) jsonResponse(['success' => false, 'error' => 'Invalid status.'], 422);
            $updated = $authRepo->updateUser($uid, $data);
            $updated ? jsonResponse(['success' => true, 'data' => $updated]) : jsonResponse(['success' => false, 'error' => 'Not found.'], 404);
        }

        if ($method === 'DELETE') {
            adminRequired();
            $authRepo->deleteUser($uid) ? jsonResponse(['success' => true, 'message' => 'User deleted.']) : jsonResponse(['success' => false, 'error' => 'Cannot delete.'], 403);
        }
    }

    if ($path === '/api/v1/registry/users/disable' && $method === 'POST') {
        adminRequired();
        $data = body();
        if (empty($data['user_id'])) jsonResponse(['success' => false, 'error' => 'user_id required.'], 422);
        $authRepo->disableUser((int)$data['user_id']) ? jsonResponse(['success' => true, 'message' => 'User disabled.']) : jsonResponse(['success' => false, 'error' => 'Cannot disable.'], 403);
    }

    if ($path === '/api/v1/registry/users/enable' && $method === 'POST') {
        adminRequired();
        $data = body();
        if (empty($data['user_id'])) jsonResponse(['success' => false, 'error' => 'user_id required.'], 422);
        $authRepo->enableUser((int)$data['user_id']) ? jsonResponse(['success' => true, 'message' => 'User enabled.']) : jsonResponse(['success' => false, 'error' => 'Not found.'], 404);
    }

    // ==========================================
    // SCREENING REVIEW (Supervisor+)
    // ==========================================

    if (preg_match('#^/api/v1/registry/screenings/(\d+)/review$#', $path, $matches)) {
        if ($method === 'POST' || $method === 'PATCH') {
            $user = supervisorRequired();
            $sid = (int)$matches[1];
            $data = body();
            if (empty($data['review_status']) || !in_array($data['review_status'], ['REVIEWED', 'VALIDATED', 'REJECTED', 'PENDING'], true)) {
                jsonResponse(['success' => false, 'error' => 'Valid review_status required.'], 422);
            }
            $stmt = $db->prepare('UPDATE screenings SET review_status = :rs, reviewed_by = :rb, reviewed_at = NOW() WHERE id = :id RETURNING *');
            $stmt->execute([':rs' => $data['review_status'], ':rb' => $user['id'], ':id' => $sid]);
            $row = $stmt->fetch();
            $row ? jsonResponse(['success' => true, 'data' => $row]) : jsonResponse(['success' => false, 'error' => 'Not found.'], 404);
        }
    }

    if ($path === '/api/v1/registry/screenings/pending-review' && $method === 'GET') {
        supervisorRequired();
        $stmt = $db->query("SELECT s.*, p.tsca_id, p.first_name, p.last_name FROM screenings s JOIN participants p ON s.participant_id = p.id WHERE s.review_status = 'PENDING' ORDER BY s.screening_date DESC");
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
    }

    // ==========================================
    // LEGACY REGISTRY (unchanged)
    // ==========================================
    $legacyRegistry = new RegistryRepository($db);

    if ($path === '/api/v1/registry/stats') {
        adminRequired();
        jsonResponse(['success' => true, 'data' => $legacyRegistry->stats()]);
    }

    if ($path === '/api/v1/registry' || $path === '/api/v1/registry/') {
        if ($method === 'POST') {
            $data = body();
            $name = trim((string)($data['full_name'] ?? $data['name'] ?? ''));
            $email = strtolower(trim((string)($data['email'] ?? '')));
            $phone = trim((string)($data['phone'] ?? ''));
            $type = trim((string)($data['subscription_type'] ?? ''));
            if ($name === '' || strlen($name) < 2) jsonResponse(['success' => false, 'error' => 'Valid full name required.'], 422);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonResponse(['success' => false, 'error' => 'Valid email required.'], 422);
            if (!in_array($type, ['newsletter', 'volunteer', 'member'], true)) jsonResponse(['success' => false, 'error' => 'Valid type required.'], 422);
            try {
                $created = $legacyRegistry->create(['full_name' => $name, 'email' => $email, 'phone' => $phone, 'subscription_type' => $type]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23505') jsonResponse(['success' => false, 'error' => 'Email already registered.'], 409);
                throw $e;
            }
            jsonResponse(['success' => true, 'message' => 'Registration complete.', 'data' => ['registry_number' => $created['registry_number'], 'full_name' => $created['full_name']]], 201);
        }
        if ($method === 'GET') {
            adminRequired();
            jsonResponse(['success' => true, 'data' => $legacyRegistry->list($_GET)]);
        }
    }

    if (preg_match('#^/api/v1/registry/(\d+)$#', $path, $m)) {
        adminRequired();
        $id = (int)$m[1];
        if ($method === 'GET') { $r = $legacyRegistry->find($id); $r ? jsonResponse(['success' => true, 'data' => $r]) : jsonResponse(['success' => false, 'error' => 'Not found.'], 404); }
        if ($method === 'PATCH') { $data = body(); try { $u = $legacyRegistry->update($id, $data); } catch (PDOException $e) { if ($e->getCode() === '23505') jsonResponse(['success' => false, 'error' => 'Duplicate.'], 409); throw $e; } $u ? jsonResponse(['success' => true, 'data' => $u]) : jsonResponse(['success' => false, 'error' => 'Not found.'], 404); }
        if ($method === 'DELETE') { $legacyRegistry->delete($id) ? jsonResponse(['success' => true]) : jsonResponse(['success' => false, 'error' => 'Not found.'], 404); }
    }

    // ==========================================
    // PARTICIPANTS
    // ==========================================
    $participants = new ParticipantRepository($db);

    if ($path === '/api/v1/registry/participants/stats') {
        dataEntryRequired();
        jsonResponse(['success' => true, 'data' => $participants->stats()]);
    }

    if (preg_match('#^/api/v1/registry/participants/by-tsca/(TSCA-[\w-]+)$#', $path, $m)) {
        if ($method === 'GET') { dataEntryRequired(); $r = $participants->findByTscaId($m[1]); $r ? jsonResponse(['success' => true, 'data' => $r]) : jsonResponse(['success' => false, 'error' => 'Not found.'], 404); }
    }

    if ($path === '/api/v1/registry/participants' || $path === '/api/v1/registry/participants/') {
        if ($method === 'POST') {
            dataEntryRequired();
            $data = body();
            if (empty($data['first_name'])) jsonResponse(['success' => false, 'error' => 'First name required.'], 422);
            if (empty($data['last_name'])) jsonResponse(['success' => false, 'error' => 'Last name required.'], 422);
            if (empty($data['gender']) || !in_array($data['gender'], ['male','female','other'], true)) jsonResponse(['success' => false, 'error' => 'Valid gender required.'], 422);
            try { $c = $participants->create($data); } catch (PDOException $e) { if ($e->getCode() === '23505') jsonResponse(['success' => false, 'error' => 'Duplicate national ID.'], 409); throw $e; }
            jsonResponse(['success' => true, 'message' => 'Registered.', 'data' => $c], 201);
        }
        if ($method === 'GET') { dataEntryRequired(); jsonResponse(['success' => true, 'data' => $participants->list($_GET)]); }
    }

    if (preg_match('#^/api/v1/registry/participants/(\d+)$#', $path, $m)) {
        $id = (int)$m[1];
        if ($method === 'GET') { dataEntryRequired(); $r = $participants->find($id); $r ? jsonResponse(['success' => true, 'data' => $r]) : jsonResponse(['success' => false, 'error' => 'Not found.'], 404); }
        if ($method === 'PATCH' || $method === 'PUT') { dataEntryRequired(); $d = body(); $u = $participants->update($id, $d); $u ? jsonResponse(['success' => true, 'data' => $u]) : jsonResponse(['success' => false, 'error' => 'Not found.'], 404); }
        if ($method === 'DELETE') { adminRequired(); $participants->delete($id) ? jsonResponse(['success' => true]) : jsonResponse(['success' => false, 'error' => 'Not found.'], 404); }
    }

    // ==========================================
    // SCREENINGS
    // ==========================================
    $screenings = new ScreeningRepository($db);

    if ($path === '/api/v1/registry/screenings/stats') {
        dataEntryRequired();
        jsonResponse(['success' => true, 'data' => $screenings->stats()]);
    }

    if ($path === '/api/v1/registry/screenings' || $path === '/api/v1/registry/screenings/') {
        if ($method === 'GET') { dataEntryRequired(); jsonResponse(['success' => true, 'data' => $screenings->list($_GET)]); }
    }

    if (preg_match('#^/api/v1/registry/participants/(\d+)/screenings/?$#', $path, $m)) {
        $pid = (int)$m[1];
        if ($method === 'POST') {
            dataEntryRequired();
            $data = body(); $data['participant_id'] = $pid;
            if (empty($data['screening_date'])) jsonResponse(['success' => false, 'error' => 'Date required.'], 422);
            if (empty($data['test_type']) || !in_array($data['test_type'], ['rapid_test','hemoglobin_electrophoresis','hplc','other'], true)) jsonResponse(['success' => false, 'error' => 'Valid test type required.'], 422);
            if (empty($data['result']) || !in_array($data['result'], ['reactive','non_reactive','AA','AS','SS','SC','unknown'], true)) jsonResponse(['success' => false, 'error' => 'Valid result required.'], 422);
            $c = $screenings->create($data);
            jsonResponse(['success' => true, 'message' => 'Screening recorded.', 'data' => $c], 201);
        }
        if ($method === 'GET') { dataEntryRequired(); jsonResponse(['success' => true, 'data' => $screenings->findByParticipant($pid)]); }
    }

    if (preg_match('#^/api/v1/registry/screenings/(\d+)$#', $path, $m)) {
        $id = (int)$m[1];
        if ($method === 'GET') { dataEntryRequired(); $r = $screenings->find($id); $r ? jsonResponse(['success' => true, 'data' => $r]) : jsonResponse(['success' => false, 'error' => 'Not found.'], 404); }
        if ($method === 'PATCH' || $method === 'PUT') { dataEntryRequired(); $u = $screenings->update($id, body()); $u ? jsonResponse(['success' => true, 'data' => $u]) : jsonResponse(['success' => false, 'error' => 'Not found.'], 404); }
        if ($method === 'DELETE') { adminRequired(); $screenings->delete($id) ? jsonResponse(['success' => true]) : jsonResponse(['success' => false, 'error' => 'Not found.'], 404); }
    }

    // ==========================================
    // EVENTS
    // ==========================================
    $events = new EventRepository($db);

    if ($path === '/api/v1/registry/events' || $path === '/api/v1/registry/events/') {
        if ($method === 'POST') {
            dataEntryRequired();
            $data = body();
            if (empty($data['event_name'])) jsonResponse(['success' => false, 'error' => 'Event name required.'], 422);
            if (empty($data['district'])) jsonResponse(['success' => false, 'error' => 'District required.'], 422);
            if (empty($data['event_date'])) jsonResponse(['success' => false, 'error' => 'Date required.'], 422);
            $c = $events->create($data);
            jsonResponse(['success' => true, 'message' => 'Event created.', 'data' => $c], 201);
        }
        if ($method === 'GET') { dataEntryRequired(); jsonResponse(['success' => true, 'data' => $events->list($_GET)]); }
    }

    if (preg_match('#^/api/v1/registry/events/(\d+)$#', $path, $m)) {
        $id = (int)$m[1];
        if ($method === 'GET') { dataEntryRequired(); $r = $events->find($id); $r ? jsonResponse(['success' => true, 'data' => $r]) : jsonResponse(['success' => false, 'error' => 'Not found.'], 404); }
        if ($method === 'PATCH' || $method === 'PUT') { dataEntryRequired(); $u = $events->update($id, body()); $u ? jsonResponse(['success' => true, 'data' => $u]) : jsonResponse(['success' => false, 'error' => 'Not found.'], 404); }
        if ($method === 'DELETE') { adminRequired(); $events->delete($id) ? jsonResponse(['success' => true]) : jsonResponse(['success' => false, 'error' => 'Not found.'], 404); }
    }

    // ==========================================
    // FOLLOW-UPS
    // ==========================================
    $followUps = new FollowUpRepository($db);

    if (preg_match('#^/api/v1/registry/participants/(\d+)/follow-ups/?$#', $path, $m)) {
        $pid = (int)$m[1];
        if ($method === 'POST') {
            dataEntryRequired();
            $data = body(); $data['participant_id'] = $pid;
            if (empty($data['follow_up_date'])) jsonResponse(['success' => false, 'error' => 'Date required.'], 422);
            $c = $followUps->create($data);
            jsonResponse(['success' => true, 'message' => 'Follow-up recorded.', 'data' => $c], 201);
        }
        if ($method === 'GET') { dataEntryRequired(); jsonResponse(['success' => true, 'data' => $followUps->findByParticipant($pid)]); }
    }

    if ($path === '/api/v1/registry/follow-ups' || $path === '/api/v1/registry/follow-ups/') {
        if ($method === 'GET') { supervisorRequired(); jsonResponse(['success' => true, 'data' => $followUps->stats()]); }
    }

    if (preg_match('#^/api/v1/registry/follow-ups/(\d+)$#', $path, $m)) {
        $id = (int)$m[1];
        if ($method === 'GET') { dataEntryRequired(); $r = $followUps->find($id); $r ? jsonResponse(['success' => true, 'data' => $r]) : jsonResponse(['success' => false, 'error' => 'Not found.'], 404); }
        if ($method === 'PATCH' || $method === 'PUT') { dataEntryRequired(); $u = $followUps->update($id, body()); $u ? jsonResponse(['success' => true, 'data' => $u]) : jsonResponse(['success' => false, 'error' => 'Not found.'], 404); }
        if ($method === 'DELETE') { adminRequired(); $followUps->delete($id) ? jsonResponse(['success' => true]) : jsonResponse(['success' => false, 'error' => 'Not found.'], 404); }
    }

    // ==========================================
    // REPORTS
    // ==========================================
    $reports = new ReportsRepository($db);

    if ($path === '/api/v1/registry/reports/summary') { supervisorRequired(); jsonResponse(['success' => true, 'data' => $reports->summary()]); }
    if ($path === '/api/v1/registry/reports/results') { supervisorRequired(); jsonResponse(['success' => true, 'data' => $reports->resultDistribution()]); }
    if ($path === '/api/v1/registry/reports/demographics') { supervisorRequired(); jsonResponse(['success' => true, 'data' => $reports->demographics()]); }
    if (preg_match('#^/api/v1/registry/reports/events(?:/(\d+))?$#', $path, $m)) { supervisorRequired(); jsonResponse(['success' => true, 'data' => $reports->eventReport(isset($m[1]) ? (int)$m[1] : null)]); }
    if ($path === '/api/v1/registry/reports/referrals') { supervisorRequired(); jsonResponse(['success' => true, 'data' => $reports->referralReport()]); }

    if ($path === '/api/v1/registry/export/participants') {
        supervisorRequired();
        $items = $reports->exportParticipants($_GET);
        header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="participants.csv"');
        $o = fopen('php://output', 'w');
        if ($items) { fputcsv($o, array_keys($items[0])); foreach ($items as $r) fputcsv($o, $r); }
        fclose($o); exit;
    }

    if ($path === '/api/v1/registry/export/screenings') {
        supervisorRequired();
        $items = $reports->exportScreenings($_GET);
        header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="screenings.csv"');
        $o = fopen('php://output', 'w');
        if ($items) { fputcsv($o, array_keys($items[0])); foreach ($items as $r) fputcsv($o, $r); }
        fclose($o); exit;
    }

    jsonResponse(['success' => false, 'error' => 'Endpoint not found.'], 404);
} catch (Throwable $e) {
    $debug = filter_var(Config::get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);
    jsonResponse([
        'success' => false,
        'error' => 'The server could not complete the request.',
        ...($debug ? ['details' => $e->getMessage()] : []),
    ], 500);
}
