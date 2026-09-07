<?php
// POST /v1/auth/login
if ($method !== 'POST') json_error('Method not allowed', 405);

$body = json_decode(file_get_contents('php://input'), true);
$username = trim($body['username'] ?? '');
$password = $body['password'] ?? '';

if (!$username || !$password) json_error('Chýba username alebo heslo');

$stmt = db()->prepare(
    'SELECT id, password, role, is_active, username_changed, token_version FROM admin.users WHERE username = ?'
);
$stmt->execute([$username]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password'])) {
    json_error('Nesprávne prihlasovacie údaje', 401);
}
if (!$user['is_active']) {
    json_error('Účet nie je aktivovaný', 403);
}

$token = jwt_create([
    'user_id'          => $user['id'],
    'role'             => $user['role'],
    'username_changed' => (bool)$user['username_changed'],
    'tv'               => (int)$user['token_version'],
    'exp'              => time() + 86400 * 7  // 7 dni
]);

// Log login
try {
    // dev prostredie je na devjob.fellow.sk, produkcia na job.fellow.sk
    $env = str_starts_with($_SERVER['HTTP_HOST'] ?? '', 'devjob') ? 'develop' : 'main';
    db()->prepare("INSERT INTO admin.login_logs (user_id, username, ip_address, user_agent, env) VALUES (?,?,?,?,?)")
       ->execute([$user['id'], $username,
                  $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null,
                  $_SERVER['HTTP_USER_AGENT'] ?? null,
                  $env]);
} catch (Exception $e) { /* non-fatal */ }

json_ok(['token' => $token, 'role' => $user['role'], 'username_changed' => (bool)$user['username_changed']]);
