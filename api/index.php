<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers/response.php';
require_once __DIR__ . '/helpers/db.php';
require_once __DIR__ . '/helpers/auth.php';

$path   = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$method = $_SERVER['REQUEST_METHOD'];
$path   = preg_replace('#^api/?#', '', $path);   // prefix /api na serveri

try {
    match (true) {
        // auth
        $path === 'v1/auth/login'    => require __DIR__ . '/v1/auth/login.php',
        $path === 'v1/auth/complete' => require __DIR__ . '/v1/auth/complete.php',

        // profil a preferencie
        $path === 'v1/profile'     => require __DIR__ . '/v1/profile.php',
        $path === 'v1/preferences' => require __DIR__ . '/v1/preferences.php',
        $path === 'v1/documents'   => require __DIR__ . '/v1/documents.php',

        // ponuky
        $path === 'v1/offers'  => require __DIR__ . '/v1/offers.php',
        $path === 'v1/matches' => require __DIR__ . '/v1/matches.php',

        // modely: ciselnik, poradie a laboratorium
        $path === 'v1/admin/ai-ciselnik'=> require __DIR__ . '/v1/admin/ai_ciselnik.php',
        $path === 'v1/admin/ai-models' => require __DIR__ . '/v1/admin/ai_models.php',
        $path === 'v1/admin/ai-lab'    => require __DIR__ . '/v1/admin/ai_lab.php',
        $path === 'v1/admin/ai-naklady'=> require __DIR__ . '/v1/admin/ai_naklady.php',

        // naklady na model: zber vs. vyhodnocovanie vhodnosti
        $path === 'v1/admin/naklady'   => require __DIR__ . '/v1/admin/naklady.php',

        // ciselnik portalov: nazov, adresy zberu, aktivny loading
        $path === 'v1/admin/portaly'   => require __DIR__ . '/v1/admin/portaly.php',
        // rucne spustenie zberu
        $path === 'v1/admin/zber'      => require __DIR__ . '/v1/admin/zber.php',
        // test modelov: tazenie udajov + vhodnost
        $path === 'v1/admin/test-modelov' => require __DIR__ . '/v1/admin/test_modelov.php',
        // docasna diagnostika: log procesov beziacich na pozadi
        $path === 'v1/admin/diag-log'  => require __DIR__ . '/v1/admin/diag_log.php',

        default => json_error('Neznámy endpoint: ' . $path, 404),
    };
} catch (PDOException $e) {
    error_log('DB: ' . $e->getMessage());

    // Samotne "Chyba databazy" nepovie, co sa stalo — pri ukladani formulara
    // to znamena hladat v logu servera namiesto opravy na obrazovke. Posiela
    // sa preto aj hlaska z databazy, bez SQL dotazu a nazvov stlpcov.
    $dovod = $e->getMessage();
    if (preg_match('/(invalid input syntax[^:]*: "[^"]*"|violates [a-z- ]+constraint'
                 . '|value too long[^:]*|out of range[^:]*)/i', $dovod, $m)) {
        $dovod = $m[1];
    } elseif (str_contains($dovod, ']')) {
        $dovod = trim(substr($dovod, strrpos($dovod, ']') + 1));
    }
    json_error('Chyba databázy: ' . mb_substr($dovod, 0, 200), 500);
} catch (Throwable $e) {
    error_log('ERR: ' . $e->getMessage());
    json_error('Chyba servera: ' . $e->getMessage(), 500);
}
