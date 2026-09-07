<?php
// Preferencie pouzivatela.
//
// GET /v1/preferences   nacitanie
// PUT /v1/preferences   ulozenie { free_text, max_distance_km, ... }
//
// Hlavny vstup je free_text — user napise vlastnymi slovami, co hlada, a
// spracuje to model spolu s CV a inzeratom. Struktúrovane polia su volitelne
// a sluzia len na rychle SQL predfiltrovanie (napr. neposielat do modelu
// ponuky 300 km daleko).
$auth = require_auth();
$pdo  = db();
$uid  = $auth['user_id'];

if ($method === 'GET') {
    $st = $pdo->prepare(
        'SELECT p.*, u.home_location_id, u.home_lat, u.home_lon,
                l.name AS home_location_name
           FROM admin.users u
           LEFT JOIN job.user_preferences p ON p.user_id = u.id
           LEFT JOIN job.locations l ON l.id = u.home_location_id
          WHERE u.id = ?');
    $st->execute([$uid]);
    $row = $st->fetch() ?: [];
    json_ok(['preferences' => $row]);
}

if ($method !== 'PUT') json_error('Method not allowed', 405);

$body = json_decode(file_get_contents('php://input'), true) ?: [];

$freeText = isset($body['free_text']) ? trim((string)$body['free_text']) : null;
if ($freeText !== null && mb_strlen($freeText) > 8000) {
    json_error('Text preferencií je príliš dlhý (max 8000 znakov)', 400);
}

// Zisti, ci sa volny text naozaj zmenil — ak ano, treba prepocitat posudenia.
$st = $pdo->prepare('SELECT free_text FROM job.user_preferences WHERE user_id = ?');
$st->execute([$uid]);
$old = $st->fetchColumn();
$changed = $freeText !== null && $freeText !== ($old === false ? null : $old);

$pdo->prepare(
    'INSERT INTO job.user_preferences (user_id, free_text, free_text_updated_at, updated_at)
     VALUES (?, ?, NOW(), NOW())
     ON CONFLICT (user_id) DO UPDATE
        SET free_text = COALESCE(EXCLUDED.free_text, job.user_preferences.free_text),
            free_text_updated_at = CASE WHEN EXCLUDED.free_text IS DISTINCT FROM job.user_preferences.free_text
                                        THEN NOW() ELSE job.user_preferences.free_text_updated_at END,
            updated_at = NOW()')
    ->execute([$uid, $freeText]);

// volitelne struktúrovane polia pre predfiltrovanie
$num = ['max_distance_km', 'salary_min', 'min_score_notify', 'agency_penalty'];
foreach ($num as $f) {
    if (!array_key_exists($f, $body)) continue;
    $v = $body[$f] === null || $body[$f] === '' ? null : $body[$f];
    if ($v !== null && !is_numeric($v)) json_error("Pole $f musí byť číslo", 400);
    $pdo->prepare("UPDATE job.user_preferences SET $f = ?, updated_at = NOW() WHERE user_id = ?")
        ->execute([$v, $uid]);
}
foreach (['accept_agencies', 'salary_required', 'notify_push', 'notify_email'] as $f) {
    if (!array_key_exists($f, $body)) continue;
    $pdo->prepare("UPDATE job.user_preferences SET $f = ?, updated_at = NOW() WHERE user_id = ?")
        ->execute([$body[$f] ? 't' : 'f', $uid]);
}

// domovska lokalita je na pouzivatelovi
if (array_key_exists('home_location_id', $body)) {
    $pdo->prepare('UPDATE admin.users SET home_location_id = ? WHERE id = ?')
        ->execute([$body['home_location_id'] ?: null, $uid]);
}
foreach (['home_lat', 'home_lon'] as $f) {
    if (!array_key_exists($f, $body)) continue;
    $v = $body[$f] === null || $body[$f] === '' ? null : $body[$f];
    if ($v !== null && !is_numeric($v)) json_error("Pole $f musí byť číslo", 400);
    $pdo->prepare("UPDATE admin.users SET $f = ? WHERE id = ?")->execute([$v, $uid]);
}

// Zmena preferencii znehodnocuje doterajsie posudenia — oznac ich na prepocet.
if ($changed) {
    $pdo->prepare('DELETE FROM job.user_offer_match WHERE user_id = ?')->execute([$uid]);
}

json_ok(['saved' => true, 'recompute_needed' => $changed]);
