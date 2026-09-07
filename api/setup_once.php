<?php
// Jednorazove vytvorenie prveho admin uctu na novej databaze.
//
// Funguje IBA ked je tabulka admin.users prazdna — druhy raz uz nie, takze
// subor nemoze posluzit na vytvorenie dalsieho admina.
//
// Pouzitie:
//   https://devjob.fellow.sk/api/setup_once.php?token=<CRON_SECRET>&username=majlo&password=<heslo>
//
// Po pouziti subor zmaz zo servera. Do produkcie sa nenahrava vobec
// (je v exclude v .github/workflows/deploy.yml).

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers/db.php';

$token = $_GET['token'] ?? '';
if (!defined('CRON_SECRET') || !hash_equals(CRON_SECRET, $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Neplatný token'], JSON_UNESCAPED_UNICODE);
    exit;
}

$username = trim($_GET['username'] ?? 'admin');
$password = (string)($_GET['password'] ?? '');

if (strlen($password) < 8) {
    http_response_code(400);
    echo json_encode(['error' => 'Heslo musí mať aspoň 8 znakov'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
    http_response_code(400);
    echo json_encode(['error' => 'Neplatné používateľské meno'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = db();

    $count = (int)$pdo->query('SELECT COUNT(*) FROM admin.users')->fetchColumn();
    if ($count > 0) {
        http_response_code(409);
        echo json_encode([
            'error' => 'Databáza už obsahuje používateľov — tento skript sa dá použiť len raz',
            'users' => $count,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO admin.users (username, password, role, is_active, username_changed)
         VALUES (?, ?, 'admin', TRUE, TRUE) RETURNING id");
    $stmt->execute([$username, password_hash($password, PASSWORD_BCRYPT)]);
    $id = (int)$stmt->fetchColumn();

    echo json_encode([
        'ok'       => true,
        'user_id'  => $id,
        'username' => $username,
        'note'     => 'Admin vytvorený. Zmaž tento súbor zo servera.',
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Chyba: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
