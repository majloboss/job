<?php
function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

function jwt_create($payload) {
    $header  = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = base64url_encode(json_encode($payload));
    $sig     = base64url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    return "$header.$payload.$sig";
}

function jwt_verify($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $payload, $sig] = $parts;
    $expected = base64url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) return null;
    $data = json_decode(base64url_decode($payload), true);
    if (!$data || $data['exp'] < time()) return null;
    return $data;
}

function require_auth($admin = false) {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token  = str_starts_with($header, 'Bearer ') ? substr($header, 7) : null;
    if (!$token) json_error('Unauthorized', 401);
    $payload = jwt_verify($token);
    if (!$payload) json_error('Unauthorized', 401);

    // Dokončenie registrácie: user ešte nie je aktívny — preskoč DB kontrolu
    if (!empty($payload['complete'])) {
        if ($admin) json_error('Forbidden', 403);
        return $payload;
    }

    // Efektívny "revoke": deaktivovaný/zmazaný user okamžite stráca prístup.
    // Rola sa berie z DB (spoľahlivejšie ako z tokenu — admin mohol byť degradovaný).
    $stmt = db()->prepare("SELECT is_active, role, token_version FROM admin.users WHERE id = ?");
    $stmt->execute([$payload['user_id'] ?? 0]);
    $row = $stmt->fetch();
    $active = $row && ($row['is_active'] === true || $row['is_active'] === 't'
                       || $row['is_active'] === '1' || $row['is_active'] === 1);
    if (!$active) json_error('Účet je neaktívny alebo neexistuje', 401);

    // Token versioning: token bez 'tv' alebo s nesprávnou verziou je odmietnutý → nútený relogin
    if (!isset($payload['tv']) || (int)$payload['tv'] !== (int)$row['token_version']) {
        json_error('Relácia vypršala, prihláste sa znova', 401);
    }

    $payload['role'] = $row['role']; // aktuálna rola z DB
    if ($admin && $row['role'] !== 'admin') json_error('Forbidden', 403);

    return $payload;
}

function require_admin() {
    return require_auth(true);
}
