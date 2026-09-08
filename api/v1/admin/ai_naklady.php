<?php
// GET /v1/admin/ai-naklady?run_id=5
//
// Kolko tokenov posudenie minulo a kolko by to stalo na platenych modeloch.
//
// Pouzitie v praxi: bezplatne modely maju limity a menia sa. Toto ukaze,
// ci sa oplati prejst na plateny — pri par stovkach inzeratov mesacne
// byva vysledok radovo v centoch.

$auth = require_auth(true);
if ($method !== 'GET') json_error('Method not allowed', 405);

require_once __DIR__ . '/../../helpers/openrouter_boot.php';
require_once __DIR__ . '/../../helpers/openrouter_fn.php';

$pdo   = db();
$runId = (int)($_GET['run_id'] ?? 0);

// Skutocna spotreba: bud z konkretneho behu, alebo priemer zo vsetkych.
if ($runId > 0) {
    $st = $pdo->prepare(
        "SELECT ROUND(AVG(prompt_tokens))::INT     AS vstup,
                ROUND(AVG(completion_tokens))::INT AS vystup,
                COUNT(*)                           AS meraní
           FROM job.ai_evaluations
          WHERE lab_run_id = ? AND status = 'ok' AND total_tokens IS NOT NULL");
    $st->execute([$runId]);
} else {
    $st = $pdo->query(
        "SELECT ROUND(AVG(prompt_tokens))::INT     AS vstup,
                ROUND(AVG(completion_tokens))::INT AS vystup,
                COUNT(*)                           AS meraní
           FROM job.ai_evaluations
          WHERE status = 'ok' AND total_tokens IS NOT NULL");
}
$sp = $st->fetch();

$vstup  = (int)($sp['vstup']  ?? 0);
$vystup = (int)($sp['vystup'] ?? 0);

if ($vstup === 0) {
    json_error('Zatiaľ nie sú namerané žiadne tokeny — spusti najprv posúdenie', 400);
}

// Ceny sa beru zive; zoznam je velky, preto sa vyfiltruju bezne modely.
$cennik = or_cennik();
if (!$cennik) json_error('Cenník z OpenRoutera sa nepodarilo načítať', 502);

// Porovnavaju sa PRESNE tieto modely — jeden lacny, jeden stredny a jeden
// silny od kazdeho vyrobcu. Zoznam je zamerne kratky: cennik ma stovky
// poloziek a varianty (:batch, -codex, -image) by prehlad len zahltili.
$zaujimave = [
    'anthropic/claude-haiku-4.5',
    'anthropic/claude-sonnet-5',
    'anthropic/claude-opus-5',
    'openai/gpt-5-nano',
    'openai/gpt-5-mini',
    'openai/gpt-5',
    'google/gemini-3-flash-preview',
    'google/gemini-3-pro',
    'deepseek/deepseek-v4-flash',
    'deepseek/deepseek-v4-pro',
    'mistralai/mistral-large-2512',
];

$modely = [];
foreach ($zaujimave as $id) {
    $c = $cennik[$id] ?? null;
    if ($c === null || $c['vstup'] === null) continue;

    $zaPosudok = $vstup * $c['vstup'] + $vystup * $c['vystup'];
    $modely[$id] = [
        'model'          => $id,
        'nazov'          => $c['nazov'],
        'cena_vstup_1M'  => round($c['vstup']  * 1_000_000, 3),
        'cena_vystup_1M' => round($c['vystup'] * 1_000_000, 3),
        'za_posudok'     => round($zaPosudok, 6),
        'za_100'         => round($zaPosudok * 100, 3),
        'za_1000'        => round($zaPosudok * 1000, 2),
    ];
}

usort($modely, fn($a, $b) => $a['za_posudok'] <=> $b['za_posudok']);

json_ok([
    'spotreba' => [
        'vstup_tokenov'  => $vstup,
        'vystup_tokenov' => $vystup,
        'spolu'          => $vstup + $vystup,
        'meranych'       => (int)($sp['meraní'] ?? 0),
        'zdroj'          => $runId > 0 ? "beh #$runId" : 'priemer všetkých posúdení',
    ],
    'modely' => array_values($modely),
    'poznamka' => 'Ceny sú v USD. "za_posudok" = jeden inzerát pre jedného '
                . 'používateľa pri nameranej spotrebe tokenov.',
]);
