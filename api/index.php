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

        // docasna diagnostika: je na hostingu Python pre scraper?
        $path === 'v1/admin/diag-python' => require __DIR__ . '/v1/admin/diag_python.php',

        default => json_error('Neznámy endpoint: ' . $path, 404),
    };
} catch (PDOException $e) {
    error_log('DB: ' . $e->getMessage());
    json_error('Chyba databázy', 500);
} catch (Throwable $e) {
    error_log('ERR: ' . $e->getMessage());
    json_error('Chyba servera: ' . $e->getMessage(), 500);
}
