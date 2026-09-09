<?php
// Rucne spustenie zberu — /v1/admin/zber
//
// GET                     historia behov + stav (bezi nieco?)
// POST ?akcia=spustit     spusti zber { source_id, dni, limit, bez_detailov }
// POST ?akcia=vytazit     pusti model nad uz stiahnutymi inzeratmi { limit }
// POST ?akcia=zrusit      oznaci bezuci beh za zruseny { run_id }
//
// Zber je dvojkrokovy a oba kroky sa spustaju samostatne — stahovanie
// a tazenie sa tak daju opakovat nezavisle. Tazenie sa da zopakovat
// lepsim promptom bez opatovneho stahovania z portalu.
$auth = require_auth(true);
$pdo  = db();

// ------------------------------------------------------------
// GET — historia behov
// ------------------------------------------------------------
if ($method === 'GET') {
    $st = $pdo->query(
        "SELECT r.id, r.run_type, r.period_days, r.status,
                r.started_at, r.finished_at,
                r.offers_found, r.offers_new, r.details_fetched,
                r.errors_count, r.error_message,
                r.cost_usd, r.tokens_total, r.parsed_count,
                s.name AS portal, u.username AS spustil,
                EXTRACT(EPOCH FROM (COALESCE(r.finished_at, NOW()) - r.started_at))::INT AS trvanie_s
           FROM job.scrape_runs r
           LEFT JOIN job.sources s ON s.id = r.source_id
           LEFT JOIN admin.users u ON u.id = r.triggered_by
          ORDER BY r.started_at DESC
          LIMIT 30");
    $behy = $st->fetchAll();

    // Kolko inzeratov caka na vytazenie modelom — druhy krok zberu.
    $caka = (int)$pdo->query(
        "SELECT COUNT(*) FROM job.offers o
          WHERE o.detail_fetched_at IS NOT NULL
            AND NOT EXISTS (SELECT 1 FROM job.ai_evaluations e
                             WHERE e.offer_id = o.id AND e.ucel = 'parse'
                               AND e.status = 'ok')")->fetchColumn();

    $stav = $pdo->query(
        "SELECT COUNT(*) AS inzeratov,
                COUNT(*) FILTER (WHERE detail_fetched_at IS NOT NULL) AS s_detailom,
                COUNT(*) FILTER (WHERE summary_sk IS NOT NULL) AS vytazenych
           FROM job.offers WHERE is_active")->fetch();

    json_ok([
        'behy'    => $behy,
        'caka'    => $caka,
        'stav'    => $stav,
        'portaly' => $pdo->query(
            'SELECT id, code, name, default_period_days
               FROM job.sources WHERE is_active ORDER BY name')->fetchAll(),
        // Bez Pythonu alebo kniznic sa zber musi spustat z prikazoveho riadka.
        'python'  => zber_najdi_python() !== null && zber_kniznice_su(),
    ]);
}

if ($method !== 'POST') json_error('Method not allowed', 405);
$vstup = json_decode(file_get_contents('php://input'), true) ?: [];
$akcia = $_GET['akcia'] ?? '';

// ------------------------------------------------------------
// Interpreter Pythonu
//
// proc_open s POLOM argumentov, rovnako ako doc_pdftotext(): na Websupporte
// su shell_exec aj exec definovane, ale nic nevracaju.
// ------------------------------------------------------------
function zber_najdi_python(): ?string {
    if (!function_exists('proc_open')) return null;
    $zakazane = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('proc_open', $zakazane, true)) return null;

    foreach (['/usr/bin/python3', '/usr/local/bin/python3', '/bin/python3',
              '/opt/python/bin/python3', '/usr/bin/python'] as $c) {
        if (is_executable($c)) return $c;
    }
    return null;
}

// Su kniznice pre scraper nainstalovane? Bez nich by beh spadol hned
// po spusteni a v historii by zostal len zaznam s chybou.
function zber_kniznice_su(): bool {
    return is_dir(dirname(__DIR__, 2) . '/pylibs/requests')
        && is_dir(dirname(__DIR__, 2) . '/pylibs/psycopg2');
}

// ------------------------------------------------------------
// POST ?akcia=spustit — stiahne inzeraty
//
// Beh sa zaklada TU, nie v Pythone: aj ked spustenie zlyha, v historii
// zostane zaznam s dovodom. Inak by po neuspesnom spusteni nezostalo nic.
// ------------------------------------------------------------
if ($akcia === 'spustit') {
    $sourceId = (int)($vstup['source_id'] ?? 0);
    $dni      = max(1, min(30, (int)($vstup['dni'] ?? 1)));
    $limit    = max(0, min(1000, (int)($vstup['limit'] ?? 20)));

    if (!$sourceId) json_error('Nie je vybraný portál', 400);

    $st = $pdo->prepare('SELECT code, name FROM job.sources WHERE id = ? AND is_active');
    $st->execute([$sourceId]);
    $zdroj = $st->fetch();
    if (!$zdroj) json_error('Portál sa nenašiel alebo nie je aktívny', 400);

    // Zaseknute behy: proces mohol spadnut skor, nez stihol prepisat stav
    // (napr. pri chybe pripojenia). Taky beh by inak blokoval spustenie
    // dalsieho donekonecna. Zber s odstupom 1,5 s na poziadavku a limitom
    // 1000 inzeratov trva najviac desiatky minut — po 30 minutach je beh
    // takmer isto mrtvy.
    $pdo->exec(
        "UPDATE job.scrape_runs
            SET status = 'failed', finished_at = NOW(),
                error_message = COALESCE(error_message,
                    'Beh neodpovedal viac než 30 minút — považovaný za zaseknutý')
          WHERE status = 'running' AND started_at < NOW() - INTERVAL '30 minutes'");

    // Dva behy naraz by sa bili o rovnake inzeraty a zbytocne zatazovali portal.
    $bezi = $pdo->query(
        "SELECT id, started_at FROM job.scrape_runs
          WHERE status = 'running' LIMIT 1")->fetch();
    if ($bezi) {
        json_error('Zber už beží (beh #' . $bezi['id'] . '). Počkaj, kým dobehne, '
                 . 'alebo ho zruš v histórii.', 409);
    }

    $st = $pdo->prepare(
        "INSERT INTO job.scrape_runs
            (source_id, run_type, period_days, status, started_at, triggered_by, filter_params)
         VALUES (?, 'manual', ?, 'running', NOW(), ?, ?)
         RETURNING id");
    $st->execute([$sourceId, $dni, (int)$auth['user_id'],
                  json_encode(['limit' => $limit], JSON_UNESCAPED_UNICODE)]);
    $runId = (int)$st->fetchColumn();

    $python = zber_najdi_python();
    if ($python !== null && !zber_kniznice_su()) {
        $pdo->prepare(
            "UPDATE job.scrape_runs SET status='failed', finished_at=NOW(),
                    error_message=? WHERE id=?")
            ->execute(['Chybaju Python kniznice v api/pylibs', $runId]);
        json_error('Na serveri chýbajú Python knižnice. Doinštaluj ich cez '
                 . 'POST /v1/admin/diag-python?pip=1', 501);
    }
    if ($python === null) {
        $pdo->prepare(
            "UPDATE job.scrape_runs
                SET status = 'failed', finished_at = NOW(), error_message = ?
              WHERE id = ?")
            ->execute(['Na serveri nie je Python — zber spusti z príkazového riadka', $runId]);

        json_error(
            'Na serveri nie je dostupný Python. Zber spusti lokálne:  '
            . 'python scraper/profesia.py --dni ' . $dni . ' --limit ' . $limit, 501);
    }

    // Skript bezi NA POZADI: zber trva minuty a HTTP poziadavka by medzitym
    // vyprsala. Vystup ide do suboru, stav sa sleduje cez job.scrape_runs.
    $skript = dirname(__DIR__, 3) . '/scraper/profesia.py';
    if (!is_file($skript)) {
        $pdo->prepare("UPDATE job.scrape_runs SET status='failed', finished_at=NOW(),
                       error_message=? WHERE id=?")
            ->execute(['Skript scraper/profesia.py na serveri chýba', $runId]);
        json_error('Skript scraper/profesia.py na serveri chýba', 500);
    }

    $log = sys_get_temp_dir() . '/job_zber_' . $runId . '.log';
    $prikaz = [$python, $skript,
               '--dni', (string)$dni, '--limit', (string)$limit,
               '--run-id', (string)$runId];

    // PYTHONPATH ukazuje na api/pylibs, kam sa kniznice instaluju.
    // Bez toho by scraper nenasiel requests ani psycopg2: systemovy Python
    // ich nema a instalacia cez --user by skoncila v /tmp/.local, ktory sa
    // pravidelne cisti.
    $env = [
        'PYTHONPATH' => dirname(__DIR__, 2) . '/pylibs',
        'HOME'       => sys_get_temp_dir(),
        'PATH'       => '/usr/local/bin:/usr/bin:/bin',
        'LANG'       => 'sk_SK.UTF-8',
    ];

    $popis  = [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']];
    $proces = @proc_open($prikaz, $popis, $rury, null, $env);

    if (!is_resource($proces)) {
        $pdo->prepare("UPDATE job.scrape_runs SET status='failed', finished_at=NOW(),
                       error_message=? WHERE id=?")
            ->execute(['Scraper sa nepodarilo spustiť (proc_open)', $runId]);
        json_error('Scraper sa nepodarilo spustiť', 500);
    }
    // Proces sa zamerne nezatvara cez proc_close() — to by cakalo na jeho
    // dokoncenie a HTTP poziadavka by vyprsala.

    json_ok([
        'run_id' => $runId,
        'sprava' => sprintf('Zber spustený: %s, posledných %d dní, limit %s',
                            $zdroj['name'], $dni, $limit ?: 'bez limitu'),
    ]);
}

// ------------------------------------------------------------
// POST ?akcia=vytazit — pusti model nad stiahnutymi inzeratmi
// ------------------------------------------------------------
if ($akcia === 'vytazit') {
    $limit = max(1, min(200, (int)($vstup['limit'] ?? 20)));

    $php = null;
    foreach (['/usr/bin/php', '/usr/local/bin/php', '/opt/php/bin/php', PHP_BINARY] as $c) {
        if ($c && is_executable($c)) { $php = $c; break; }
    }
    if ($php === null || !function_exists('proc_open')) {
        json_error('Ťaženie sa zo servera spustiť nedá — spusti: php api/cron/zber.php', 501);
    }

    $skript = dirname(__DIR__, 2) . '/cron/zber.php';
    if (!is_file($skript)) json_error('api/cron/zber.php na serveri chýba', 500);

    $log = sys_get_temp_dir() . '/job_vytazenie.log';
    $popis = [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']];
    $proces = @proc_open([$php, $skript, '--limit=' . $limit], $popis, $rury);

    if (!is_resource($proces)) json_error('Ťaženie sa nepodarilo spustiť', 500);

    json_ok(['sprava' => "Ťaženie spustené pre najviac $limit inzerátov"]);
}

// ------------------------------------------------------------
// POST ?akcia=zrusit — oznaci zaseknuty beh za zruseny
//
// Samotny proces sa nezastavuje (PHP ho neriadi) — ide o to, aby zaseknuty
// beh neblokoval spustenie dalsieho.
// ------------------------------------------------------------
if ($akcia === 'zrusit') {
    $runId = (int)($vstup['run_id'] ?? 0);
    if (!$runId) json_error('Chýba run_id', 400);

    $st = $pdo->prepare(
        "UPDATE job.scrape_runs
            SET status = 'cancelled', finished_at = NOW(),
                error_message = COALESCE(error_message, 'Zrušené správcom')
          WHERE id = ? AND status = 'running'");
    $st->execute([$runId]);

    if ($st->rowCount() === 0) json_error('Beh nebeží alebo neexistuje', 404);
    json_ok(['sprava' => 'Beh #' . $runId . ' označený za zrušený']);
}

json_error('Neznáma akcia: ' . $akcia, 400);
