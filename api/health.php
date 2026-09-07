<?php
// Kontrola nasadenia: spojenie s DB, stav migracii, konfiguracia, prava na zapis.
//
//   https://devjob.fellow.sk/api/health.php?token=<CRON_SECRET>
//
// Bez tokenu vrati len holy udaj, ci API bezi — podrobnosti o serveri
// nepatria na verejnost.

header('Content-Type: application/json; charset=utf-8');

$cfgPath = __DIR__ . '/config/db.php';
if (!file_exists($cfgPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Chýba api/config/db.php'], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once $cfgPath;
require_once __DIR__ . '/helpers/db.php';

$token   = $_GET['token'] ?? '';
$podrobne = defined('CRON_SECRET') && $token !== '' && hash_equals(CRON_SECRET, $token);

if (!$podrobne) {
    echo json_encode(['ok' => true, 'api' => 'beží'], JSON_UNESCAPED_UNICODE);
    exit;
}

$out = [
    'ok'          => true,
    'php'         => PHP_VERSION,
    'app_url'     => defined('APP_URL') ? APP_URL : null,
    'db_name'     => defined('DB_NAME') ? DB_NAME : null,
    'rozsirenia'  => [
        'pdo_pgsql' => extension_loaded('pdo_pgsql'),
        'zip'       => extension_loaded('zip'),      // vytazenie textu z DOCX/ODT
        'zlib'      => extension_loaded('zlib'),     // vytazenie textu z PDF
        'curl'      => extension_loaded('curl'),     // OpenRouter, stahovanie inzeratov
        'mbstring'  => extension_loaded('mbstring'),
    ],
    'pdftotext'   => function_exists('shell_exec')
                     && trim((string)@shell_exec('command -v pdftotext 2>/dev/null')) !== '',
    'openrouter'  => file_exists(__DIR__ . '/config/openrouter.php'),
];

// priecinok na nahrate dokumenty
$up = __DIR__ . '/uploads/documents';
$out['uploads'] = [
    'cesta'      => 'api/uploads/documents',
    'existuje'   => is_dir($up),
    'zapisovatelny' => is_dir($up) ? is_writable($up) : is_writable(__DIR__),
];

// databaza a migracie
try {
    $pdo = db();
    $out['db_spojenie'] = true;
    $out['db_verzia']   = $pdo->query('SELECT version()')->fetchColumn();

    $schemy = $pdo->query(
        "SELECT schema_name FROM information_schema.schemata
          WHERE schema_name IN ('admin','job') ORDER BY schema_name")->fetchAll(PDO::FETCH_COLUMN);
    $out['schemy'] = $schemy;

    if (in_array('admin', $schemy, true)) {
        $mig = $pdo->query(
            "SELECT version, description, applied_at
               FROM admin.schema_versions ORDER BY version")->fetchAll(PDO::FETCH_ASSOC);
        $out['migracie'] = $mig;
        $out['pocet_tabuliek'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema IN ('admin','job')")->fetchColumn();
        $out['pocet_pouzivatelov'] = (int)$pdo->query('SELECT COUNT(*) FROM admin.users')->fetchColumn();
    } else {
        $out['migracie'] = [];
        $out['poznamka'] = 'Schéma admin neexistuje — spusti migrácie 001 a 002';
    }
} catch (Throwable $e) {
    $out['ok']          = false;
    $out['db_spojenie'] = false;
    $out['db_chyba']    = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
