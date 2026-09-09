<?php
// Laboratorium modelov — porovnanie jedneho inzeratu viacerymi modelmi.
//
// Testuju sa DVA ucely, kazdy s vlastnym promptom:
//   'parse' — vytazenie udajov z inzeratu (krok 1: zber)
//   'eval'  — posudenie vhodnosti pre pouzivatela (krok 2)
//
// POST /v1/admin/ai-lab            zaloz beh: stiahne inzerat, pripravi prompt
//      Telo: { "url": "...", "models": [...], "ucel": "parse", "document_id": 12 }
// POST /v1/admin/ai-lab?step=1     otestuje JEDEN model v ramci behu
//      Telo: { "run_id": 5, "model": "a/b:free" }
// POST /v1/admin/ai-lab?zhoda=1    vyhodnoti zhodu modelov po dobehnuti behu
//      Telo: { "run_id": 5 }
// GET  /v1/admin/ai-lab?run_id=5   vysledky behu
// GET  /v1/admin/ai-lab            zoznam poslednych behov
//
// Modely sa volaju po jednom zvlast — bezplatne modely maju limit poziadaviek
// za minutu a jedno dlhe volanie so vsetkymi by casovo nevyslo.
$auth = require_auth(true);
require_once __DIR__ . '/../../helpers/openrouter_boot.php';
require_once __DIR__ . '/../../helpers/openrouter_fn.php';
require_once __DIR__ . '/../../helpers/ai_modely_fn.php';
require_once __DIR__ . '/../../helpers/ai_zhoda_fn.php';

$pdo = db();

// ------------------------------------------------------------
// GET — vysledky behu alebo zoznam behov
// ------------------------------------------------------------
if ($method === 'GET') {
    $runId = (int)($_GET['run_id'] ?? 0);

    if ($runId === 0) {
        $st = $pdo->prepare(
            'SELECT id, source_url, offer_title, ucel, status, models_total, models_done,
                    models_ok, started_at, finished_at
               FROM job.ai_lab_runs
              WHERE user_id = ?
              ORDER BY started_at DESC LIMIT 30');
        $st->execute([$auth['user_id']]);
        json_ok(['runs' => $st->fetchAll()]);
    }

    $st = $pdo->prepare('SELECT * FROM job.ai_lab_runs WHERE id = ? AND user_id = ?');
    $st->execute([$runId, $auth['user_id']]);
    $run = $st->fetch();
    if (!$run) json_error('Beh sa nenašiel', 404);

    $ucel = $run['ucel'] ?? 'eval';

    // Pri 'parse' rozhoduje zhoda s ostatnymi, pri 'eval' skore — preto sa
    // aj zoraduje inak. Model, ktory sa nezhodol, patri dolu bez ohladu na to,
    // ako rychlo odpovedal.
    $st = $pdo->prepare(
        'SELECT id, model_id, score, bucket, summary, pros, cons, missing_skills,
                parsed, agrees, status, error, prompt_tokens, completion_tokens,
                total_tokens, cost_usd, took_ms, created_at
           FROM job.ai_evaluations
          WHERE lab_run_id = ?
          ORDER BY ' . ($ucel === 'parse'
              ? '(agrees IS NOT TRUE), took_ms'
              : '(score IS NULL), score DESC, took_ms'));
    $st->execute([$runId]);

    $rows = [];
    foreach ($st->fetchAll() as $r) {
        foreach (['pros', 'cons', 'missing_skills', 'parsed'] as $f) {
            $r[$f] = $r[$f] !== null ? json_decode($r[$f], true) : null;
        }
        $r['score']  = $r['score'] !== null ? (int)$r['score'] : null;
        $r['agrees'] = $r['agrees'] === null ? null : ai_je_true($r['agrees']);
        $rows[] = $r;
    }

    // medián skóre — orientacny bod pri posudzovani vhodnosti
    $scores = array_values(array_filter(array_column($rows, 'score'), fn($v) => $v !== null));
    sort($scores);
    $n = count($scores);
    $median = $n === 0 ? null
        : ($n % 2 ? $scores[intdiv($n, 2)]
                  : (int)round(($scores[$n/2 - 1] + $scores[$n/2]) / 2));

    // odchylka kazdeho modelu od medianu
    foreach ($rows as &$r) {
        $r['score_diff'] = ($median !== null && $r['score'] !== null) ? $r['score'] - $median : null;
    }
    unset($r);

    // Pri tazani udajov je hlavne kriterium vacsinovy nazor na jednotlive
    // polia — ukaze sa, v com presne sa modely rozchadzaju.
    $zhoda = $ucel === 'parse' ? ai_prehlad_zhody($runId) : null;

    $run['offer_text'] = mb_substr((string)$run['offer_text'], 0, 4000);
    unset($run['offer_html']);   // do zoznamu netreba, je to velke

    json_ok(['run' => $run, 'results' => $rows, 'ucel' => $ucel,
             'zhoda' => $zhoda,
             'median_score' => $median, 'evaluated' => $n]);
}

if ($method !== 'POST') json_error('Method not allowed', 405);

$body = json_decode(file_get_contents('php://input'), true) ?: [];

// ------------------------------------------------------------
// POST ?step=1 — otestuj jeden model v ramci existujuceho behu
// ------------------------------------------------------------
if (($_GET['step'] ?? '') === '1') {
    $runId = (int)($body['run_id'] ?? 0);
    $model = trim((string)($body['model'] ?? ''));
    if ($runId === 0 || $model === '') json_error('Chýba run_id alebo model', 400);

    $st = $pdo->prepare('SELECT * FROM job.ai_lab_runs WHERE id = ? AND user_id = ?');
    $st->execute([$runId, $auth['user_id']]);
    $run = $st->fetch();
    if (!$run) json_error('Beh sa nenašiel', 404);

    $ps = $pdo->prepare('SELECT template FROM job.ai_prompts WHERE id = ?');
    $ps->execute([$run['prompt_id']]);
    $template = $ps->fetchColumn();
    if ($template === false) json_error('Prompt behu sa nenašiel', 500);

    // Pri tazani udajov ide do promptu iba inzerat — CV ani preferencie
    // s vytazenim udajov nesuvisia a len by minuli tokeny.
    $prompt = or_build_prompt(
        $template,
        (string)$run['prefs_text'], (string)$run['cv_text'], (string)$run['offer_text']
    );

    $res = or_evaluate([
        'prompt'     => $prompt,
        'lab_run_id' => $runId,
        'user_id'    => $auth['user_id'],
        'offer_id'   => $run['offer_id'],
        'prompt_id'  => $run['prompt_id'],
        'url'        => $run['source_url'],
        'ucel'       => $run['ucel'] ?? 'eval',
        'source_id'  => $run['source_id'],
        'call_type'  => 'test',
    ], $model);

    ai_prepocitaj_statistiku($model);

    $pdo->prepare(
        'UPDATE job.ai_lab_runs
            SET models_done = models_done + 1,
                models_ok   = models_ok + ?,
                status      = CASE WHEN models_done + 1 >= models_total THEN \'done\' ELSE status END,
                finished_at = CASE WHEN models_done + 1 >= models_total THEN NOW() ELSE finished_at END
          WHERE id = ?')->execute([$res['ok'] ? 1 : 0, $runId]);

    json_ok(['result' => $res]);
}

// ------------------------------------------------------------
// POST ?zhoda=1 — vyhodnot zhodu modelov po dobehnuti behu
//
// Vola sa raz, ked su vsetky modely otestovane. Az vtedy je z coho zistit
// vacsinovy nazor — pri priebeznom vyhodnocovani by prve dva modely urcili
// "vacsinu" a ostatne by sa im prisposobovali.
// ------------------------------------------------------------
if (($_GET['zhoda'] ?? '') === '1') {
    $runId = (int)($body['run_id'] ?? 0);
    if ($runId === 0) json_error('Chýba run_id', 400);

    $st = $pdo->prepare('SELECT ucel FROM job.ai_lab_runs WHERE id = ? AND user_id = ?');
    $st->execute([$runId, $auth['user_id']]);
    $ucel = $st->fetchColumn();
    if ($ucel === false) json_error('Beh sa nenašiel', 404);
    if ($ucel !== 'parse') {
        json_error('Zhoda sa vyhodnocuje len pri ťažení údajov', 400);
    }

    $vysledok = ai_vyhodnot_zhodu($runId);
    json_ok(['zhoda' => $vysledok,
             'prehlad' => ai_prehlad_zhody($runId)]);
}

// ------------------------------------------------------------
// POST — zaloz novy beh
// ------------------------------------------------------------
$url    = trim((string)($body['url'] ?? ''));
$models = $body['models'] ?? [];
$ucel   = $body['ucel'] ?? 'eval';
if (!in_array($ucel, ['parse', 'eval'], true)) json_error('Neznámy účel: ' . $ucel, 400);
if ($url === '')       json_error('Chýba URL inzerátu', 400);
if (!is_array($models) || !$models) json_error('Nie je vybraný žiadny model', 400);
if (count($models) > 40) json_error('Naraz sa dá porovnať najviac 40 modelov', 400);

or_check_url($url);

// zisti zdrojovy portal podla hostitela
$host     = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
$sourceId = null;
foreach ($pdo->query('SELECT id, base_url FROM job.sources')->fetchAll() as $s) {
    $h = strtolower(parse_url($s['base_url'], PHP_URL_HOST) ?: '');
    $bare = preg_replace('/^www\./', '', $h);
    if ($host === $h || $host === $bare || str_ends_with($host, '.' . $bare)) {
        $sourceId = (int)$s['id'];
        break;
    }
}

$page = or_fetch_offer($url);

// Pri tazani udajov ('parse') sa CV ani preferencie nepouzivaju — model ma
// z inzeratu vytiahnut fakty, nie posudit, komu sa hodia. Nacitavaju sa
// preto len pre 'eval'.
$prefsText = '';
$doc       = null;
$cvText    = '';

if ($ucel === 'eval') {
    $st = $pdo->prepare('SELECT free_text FROM job.user_preferences WHERE user_id = ?');
    $st->execute([$auth['user_id']]);
    $prefsText = (string)($st->fetchColumn() ?: '');

    // Frontend posiela document_id: null, ked nie je nic vybrate — isset() by
    // v tom pripade vratilo false rovnako ako pri chybajucom kluci, co je tu
    // spravne: obe znamenaju "vyber hlavne CV".
    $docId = (int)($body['document_id'] ?? 0);
    if ($docId > 0) {
        $st = $pdo->prepare(
            'SELECT id, extracted_text FROM job.user_documents WHERE id = ? AND user_id = ?');
        $st->execute([$docId, $auth['user_id']]);
    } else {
        $st = $pdo->prepare(
            "SELECT id, extracted_text FROM job.user_documents
              WHERE user_id = ? AND doc_type = 'cv' AND use_for_ai
              ORDER BY is_primary DESC, created_at DESC LIMIT 1");
        $st->execute([$auth['user_id']]);
    }
    $doc    = $st->fetch() ?: null;
    $cvText = (string)($doc['extracted_text'] ?? '');
}

$prompt = or_active_prompt($ucel === 'parse' ? 'offer_parse' : 'offer_eval');

$st = $pdo->prepare(
    'INSERT INTO job.ai_lab_runs
        (user_id, source_url, source_id, ucel, prompt_id, offer_text, offer_title, offer_html,
         cv_document_id, cv_text, prefs_text, input_chars, models_total, status)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,\'running\') RETURNING id');
$st->execute([
    $auth['user_id'], mb_substr($url, 0, 500), $sourceId, $ucel, $prompt['id'],
    $page['text'], $page['title'], $page['html'],
    $doc['id'] ?? null, $cvText, $prefsText,
    mb_strlen($page['text']), count($models),
]);
$runId = (int)$st->fetchColumn();

json_ok([
    'run_id'      => $runId,
    'ucel'        => $ucel,
    'title'       => $page['title'],
    'input_chars' => mb_strlen($page['text']),
    'truncated'   => mb_strlen($page['text']) > OR_MAX_INPUT_CHARS,
    'has_cv'      => $cvText !== '',
    'has_prefs'   => $prefsText !== '',
    'models'      => array_values($models),
    'preview'     => mb_substr($page['text'], 0, 1500),
]);
