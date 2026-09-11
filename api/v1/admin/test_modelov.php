<?php
// Test modelov — /v1/admin/test-modelov
//
// GET                    posledne behy + prehlad
// GET ?beh=12            vysledky jedneho behu (priebezne, aj pocas behu)
// POST ?akcia=spustit    zalozi a spusti test { url1, url2, max_modelov,
//                                              cenovy_strop_1m, rozpocet_usd }
// POST ?akcia=zrusit     zastavi beziaci test { beh_id }
//
// Test bezi NA POZADI cez proc_open, takze pokracuje aj po zavreti
// prehliadaca. Vysledky pribudaju priebezne — obrazovka ich len cita.
$auth = require_auth(true);
$pdo  = db();

require_once __DIR__ . '/../../helpers/openrouter_boot.php';
require_once __DIR__ . '/../../helpers/openrouter_fn.php';

// ------------------------------------------------------------
// GET
// ------------------------------------------------------------
if ($method === 'GET') {
    $behId = (int)($_GET['beh'] ?? 0);

    // --- zoznam behov ---
    if (!$behId) {
        $behy = $pdo->query(
            "SELECT id, url1, url2, nazov1, nazov2, status, zastavene_dovod,
                    modelov_spolu, volani_spolu, volani_ok, tokenov_spolu, cena_usd,
                    rozpocet_usd, cenovy_strop_1m, max_modelov,
                    started_at, finished_at,
                    EXTRACT(EPOCH FROM (NOW() - heartbeat_at))::INT AS ticho_s
               FROM job.test_behy
              ORDER BY started_at DESC LIMIT 20")->fetchAll();

        // Kolko modelov by dnes vyhovovalo beznemu stropu — aby obrazovka
        // vedela ukazat, do coho sa clovek pusta.
        $pocty = $pdo->query(
            "SELECT COUNT(*) FILTER (WHERE is_free) AS free,
                    COUNT(*) FILTER (WHERE NOT is_free
                        AND price_input_1m + price_output_1m <= 1) AS do_1usd
               FROM job.ai_models
              WHERE is_enabled AND unavailable_reason IS NULL AND is_text_only
                AND (context_length IS NULL OR context_length >= 16000)
                AND (is_free OR (price_input_1m IS NOT NULL AND price_output_1m IS NOT NULL
                                 AND price_input_1m + price_output_1m > 0))")->fetch();

        json_ok(['behy' => $behy, 'pocty' => $pocty]);
    }

    // --- detail behu ---
    $st = $pdo->prepare('SELECT * FROM job.test_behy WHERE id = ?');
    $st->execute([$behId]);
    $beh = $st->fetch();
    if (!$beh) json_error('Beh sa nenašiel', 404);

    // Vstupny text sa do odpovede neposiela cely — je to desiatky kB
    // a obrazovka z neho potrebuje len ukazku.
    foreach (['text1', 'text2', 'html1', 'html2', 'cv_text'] as $pole) {
        $beh[$pole] = $beh[$pole] !== null ? mb_substr($beh[$pole], 0, 600) : null;
    }

    $st = $pdo->prepare(
        'SELECT * FROM job.test_vysledky WHERE beh_id = ?
          ORDER BY inzerat, uloha DESC, cena_1m, id');
    $st->execute([$behId]);
    $vysledky = $st->fetchAll();

    foreach ($vysledky as &$v) {
        $v['je_free'] = ai_je_true($v['je_free']);
        $v['uvazky']  = test_pg_pole($v['uvazky']);
        // HTML inzeratu moze mat desiatky kB. Do zoznamu ide len priznak,
        // ze existuje; cele sa dotahuje az pri rozkliknuti riadka.
        $v['ma_html'] = ($v['html_sk'] ?? '') !== '' || ($v['html_original'] ?? '') !== '';
        if (($_GET['html'] ?? '') !== '1') {
            unset($v['html_original'], $v['html_sk'], $v['surova_odpoved']);
        }
    }
    unset($v);

    json_ok(['beh' => $beh, 'vysledky' => $vysledky]);
}

if ($method !== 'POST') json_error('Method not allowed', 405);
$vstup = json_decode(file_get_contents('php://input'), true) ?: [];
$akcia = $_GET['akcia'] ?? '';

// ------------------------------------------------------------
// POST ?akcia=zrusit
// ------------------------------------------------------------
if ($akcia === 'zrusit') {
    $behId = (int)($vstup['beh_id'] ?? 0);
    if (!$behId) json_error('Chýba beh_id', 400);

    // Samotny proces sa nezastavuje priamo — skript kontroluje stav v DB
    // pred kazdym volanim a sam sa ukonci.
    $st = $pdo->prepare(
        "UPDATE job.test_behy SET status='cancelled', finished_at=NOW(),
                zastavene_dovod=COALESCE(zastavene_dovod,'Zrušené správcom')
          WHERE id=? AND status='running'");
    $st->execute([$behId]);
    if (!$st->rowCount()) json_error('Beh nebeží alebo neexistuje', 404);

    json_ok(['sprava' => 'Test #' . $behId . ' sa zastaví pri najbližšom volaní']);
}

// ------------------------------------------------------------
// POST ?akcia=spustit
// ------------------------------------------------------------
if ($akcia !== 'spustit') json_error('Neznáma akcia: ' . $akcia, 400);

$url1 = trim((string)($vstup['url1'] ?? ''));
$url2 = trim((string)($vstup['url2'] ?? ''));
if ($url1 === '') json_error('Chýba adresa prvého inzerátu', 400);

or_check_url($url1);
if ($url2 !== '') or_check_url($url2);

// Dva behy naraz by sa bili o rovnaky rozpocet a vysledky by sa miesali.
$bezi = $pdo->query(
    "SELECT id FROM job.test_behy WHERE status='running'
      AND heartbeat_at > NOW() - INTERVAL '10 minutes' LIMIT 1")->fetch();
if ($bezi) json_error('Test už beží (#' . $bezi['id'] . '). Počkaj alebo ho zruš.', 409);

// Zaseknuty beh (proces spadol a stav uz neprepisal) nema blokovat dalsi.
$pdo->exec("UPDATE job.test_behy
               SET status='failed', finished_at=NOW(),
                   zastavene_dovod=COALESCE(zastavene_dovod,
                       'Beh neodpovedal viac než 10 minút')
             WHERE status='running' AND heartbeat_at < NOW() - INTERVAL '10 minutes'");

// --- stiahnutie inzeratov ---
// Stahuju sa RAZ a vsetky modely dostanu presne ten isty vstup. Inak by
// sa porovnavali odpovede na rozne zadania.
$s1 = or_fetch_offer($url1);
$s2 = $url2 !== '' ? or_fetch_offer($url2) : null;

// --- podklady pre ulohu 'vhodnost' ---
$st = $pdo->prepare('SELECT free_text FROM job.user_preferences WHERE user_id = ?');
$st->execute([$auth['user_id']]);
$prefs = (string)($st->fetchColumn() ?: '');

$st = $pdo->prepare(
    "SELECT extracted_text FROM job.user_documents
      WHERE user_id = ? AND doc_type = 'cv' AND use_for_ai AND extracted_text IS NOT NULL
      ORDER BY is_primary DESC, created_at DESC LIMIT 1");
$st->execute([$auth['user_id']]);
$cv = (string)($st->fetchColumn() ?: '');

$st = $pdo->prepare(
    'INSERT INTO job.test_behy
        (user_id, url1, url2, text1, text2, html1, html2, nazov1, nazov2,
         cv_text, prefs_text, max_modelov, cenovy_strop_1m, rozpocet_usd)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id');
$st->execute([
    $auth['user_id'], mb_substr($url1, 0, 500), $url2 !== '' ? mb_substr($url2, 0, 500) : null,
    $s1['text'], $s2['text'] ?? null, $s1['html'], $s2['html'] ?? null,
    $s1['title'], $s2['title'] ?? null,
    $cv, $prefs,
    max(1, min(200, (int)($vstup['max_modelov'] ?? 20))),
    max(0, min(50, (float)($vstup['cenovy_strop_1m'] ?? 1.0))),
    max(0.01, min(20, (float)($vstup['rozpocet_usd'] ?? 1.0))),
]);
$behId = (int)$st->fetchColumn();

// --- spustenie na pozadi ---
$php = null;
foreach (['/usr/bin/php', '/usr/local/bin/php', '/opt/php/bin/php', PHP_BINARY] as $c) {
    if ($c && is_executable($c)) { $php = $c; break; }
}
if ($php === null || !function_exists('proc_open')) {
    $pdo->prepare("UPDATE job.test_behy SET status='failed', finished_at=NOW(),
                   zastavene_dovod=? WHERE id=?")
        ->execute(['Na serveri sa nepodarilo spustiť PHP proces', $behId]);
    json_error('Test sa zo servera spustiť nedá — spusti: php api/cron/test_modelov.php --beh='
             . $behId, 501);
}

$skript = dirname(__DIR__, 2) . '/cron/test_modelov.php';
$log    = sys_get_temp_dir() . '/job_test_' . $behId . '.log';
$popis  = [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']];

// Proces sa zamerne nezatvara cez proc_close() — to by cakalo na jeho
// dokoncenie a HTTP poziadavka by vyprsala.
$proces = @proc_open([$php, $skript, '--beh=' . $behId], $popis, $rury);
if (!is_resource($proces)) {
    $pdo->prepare("UPDATE job.test_behy SET status='failed', finished_at=NOW(),
                   zastavene_dovod='proc_open zlyhal' WHERE id=?")->execute([$behId]);
    json_error('Test sa nepodarilo spustiť', 500);
}

json_ok([
    'beh_id'    => $behId,
    'nazov1'    => $s1['title'],
    'nazov2'    => $s2['title'] ?? null,
    'znakov1'   => mb_strlen($s1['text']),
    'znakov2'   => $s2 ? mb_strlen($s2['text']) : null,
    'ma_cv'     => $cv !== '',
    'ma_prefs'  => $prefs !== '',
    'sprava'    => 'Test spustený — výsledky pribúdajú priebežne',
]);


// PostgreSQL vracia TEXT[] ako '{a,b}'. Bez rozbalenia by frontend dostal
// nepouzitelny retazec namiesto pola.
function test_pg_pole($v): array {
    if ($v === null || $v === '' || $v === '{}') return [];
    if (is_array($v)) return $v;
    $vnutro = trim($v, '{}');
    if ($vnutro === '') return [];
    return array_values(array_filter(array_map('trim', str_getcsv($vnutro)),
                                     fn($s) => $s !== ''));
}
