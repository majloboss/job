<?php
// GET /v1/admin/naklady — prehlad nakladov na model
//
// Odpoveda na otazku: kolko ma stoji naplnenie DB a kolko vyhodnocovanie
// vhodnosti nad nou. Su to dve rozne veci a nesmu sa scitavat do jedneho
// cisla:
//   'parse' bezi RAZ NA INZERAT          — rastie s poctom inzeratov
//   'eval'  bezi raz na INZERAT A USERA  — rastie s poctom inzeratov x userov
//
// Parametre:
//   ?dni=7            obdobie (predvolene 7)
//   ?ucel=parse|eval  detailny zoznam pre dany ucel (inak len suhrny)
//   ?limit=100        pocet riadkov detailu
require_auth(true);
if ($method !== 'GET') json_error('Method not allowed', 405);

$pdo  = db();
$dni  = max(1, min(365, (int)($_GET['dni'] ?? 7)));
$ucel = $_GET['ucel'] ?? null;
if ($ucel !== null && !in_array($ucel, ['parse', 'eval'], true)) {
    json_error('Neznámy účel: ' . $ucel, 400);
}
$limit = max(10, min(500, (int)($_GET['limit'] ?? 100)));

// ------------------------------------------------------------
// Suhrn na ucel — hlavne cislo na porovnanie
//
// Testovacie volania z laboratoria sa nezapocitavaju (call_type = 'live'):
// inak by porovnanie skreslilo par behov laboratoria s desiatkami modelov.
// ------------------------------------------------------------
$st = $pdo->prepare(
    "SELECT ucel,
            COUNT(*)                                 AS volani,
            COUNT(*) FILTER (WHERE status = 'ok')    AS uspesnych,
            COUNT(DISTINCT offer_id)                 AS inzeratov,
            COUNT(DISTINCT user_id) FILTER (WHERE user_id IS NOT NULL) AS pouzivatelov,
            COALESCE(SUM(total_tokens), 0)           AS tokenov,
            ROUND(AVG(total_tokens))                 AS tokenov_priemer,
            COALESCE(SUM(cost_usd), 0)               AS cena_spolu,
            COALESCE(AVG(cost_usd), 0)               AS cena_na_volanie,
            ROUND(AVG(took_ms))                      AS ms_priemer
       FROM job.ai_evaluations
      WHERE call_type = 'live'
        AND created_at >= NOW() - (? || ' days')::INTERVAL
      GROUP BY ucel");
$st->execute([$dni]);

$suhrn = ['parse' => null, 'eval' => null];
foreach ($st->fetchAll() as $r) $suhrn[$r['ucel']] = $r;

// ------------------------------------------------------------
// Rozpad na modely — ktory model kolko stoji
// ------------------------------------------------------------
$st = $pdo->prepare(
    "SELECT ucel, model_id,
            COUNT(*)                              AS volani,
            COUNT(*) FILTER (WHERE status = 'ok') AS uspesnych,
            COALESCE(SUM(total_tokens), 0)        AS tokenov,
            COALESCE(SUM(cost_usd), 0)            AS cena_spolu,
            COALESCE(AVG(cost_usd), 0)            AS cena_na_volanie,
            ROUND(AVG(took_ms))                   AS ms_priemer
       FROM job.ai_evaluations
      WHERE call_type = 'live'
        AND created_at >= NOW() - (? || ' days')::INTERVAL
      GROUP BY ucel, model_id
      ORDER BY ucel, SUM(cost_usd) DESC, COUNT(*) DESC");
$st->execute([$dni]);
$modely = $st->fetchAll();

// ------------------------------------------------------------
// Vyvoj po dnoch — ci naklady rastu
// ------------------------------------------------------------
$st = $pdo->prepare(
    "SELECT created_at::date AS den, ucel,
            COUNT(*) AS volani,
            COALESCE(SUM(total_tokens), 0) AS tokenov,
            COALESCE(SUM(cost_usd), 0)     AS cena
       FROM job.ai_evaluations
      WHERE call_type = 'live'
        AND created_at >= NOW() - (? || ' days')::INTERVAL
      GROUP BY created_at::date, ucel
      ORDER BY den DESC, ucel");
$st->execute([$dni]);
$dennne = $st->fetchAll();

// ------------------------------------------------------------
// Detail — riadok na volanie
//
// Pri 'parse' je kluc inzerat, pri 'eval' dvojica inzerat + pouzivatel.
// Pohlady job.v_naklady_zber / job.v_naklady_vhodnost to uz spajaju.
// ------------------------------------------------------------
$detail = [];
if ($ucel === 'parse') {
    $st = $pdo->prepare(
        "SELECT offer_id, external_id, title, company_name_raw, portal,
                stiahnuty_o, detail_stiahnuty_o, fetch_ms, fetch_bytes,
                model_id, vytazeny_o, model_ms, prompt_tokens, completion_tokens,
                total_tokens, cost_usd, model_status, model_error, pokusov,
                cost_usd_spolu
           FROM job.v_naklady_zber
          WHERE stiahnuty_o >= NOW() - (? || ' days')::INTERVAL
          ORDER BY stiahnuty_o DESC, offer_id DESC
          LIMIT " . $limit);
    $st->execute([$dni]);
    $detail = $st->fetchAll();
} elseif ($ucel === 'eval') {
    $st = $pdo->prepare(
        "SELECT offer_id, user_id, username, external_id, title, company_name_raw,
                portal, stiahnuty_o, model_id, posudeny_o AS vytazeny_o,
                model_ms, prompt_tokens, completion_tokens, total_tokens,
                cost_usd, model_status, model_error, score, bucket
           FROM job.v_naklady_vhodnost
          WHERE posudeny_o >= NOW() - (? || ' days')::INTERVAL
          ORDER BY posudeny_o DESC
          LIMIT " . $limit);
    $st->execute([$dni]);
    $detail = $st->fetchAll();
}

// ------------------------------------------------------------
// Kolko z DB je uz vytazene — bez toho sa naklady zle citaju
// ------------------------------------------------------------
$stav = $pdo->query(
    "SELECT COUNT(*) AS inzeratov,
            COUNT(*) FILTER (WHERE detail_fetched_at IS NOT NULL) AS s_detailom,
            COUNT(*) FILTER (WHERE summary_sk IS NOT NULL)        AS vytazenych
       FROM job.offers WHERE is_active")->fetch();

json_ok([
    'dni'    => $dni,
    'ucel'   => $ucel,
    'suhrn'  => $suhrn,
    'modely' => $modely,
    'denne'  => $dennne,
    'detail' => $detail,
    'stav'   => $stav,
]);
