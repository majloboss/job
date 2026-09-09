<?php
// Sprava ciselnika modelov a poradia — /v1/admin/ai-ciselnik
//
// GET    ?ucel=parse[&source_id=1]  ciselnik, poradie a stav dnesneho dna
// POST   ?akcia=sync                zosynchronizuje cennik z OpenRoutera
// POST   ?akcia=poradie             ulozi poradie modelov { ucel, source_id, modely: [id,...] }
// POST   ?akcia=model               zapne/vypne model { model_id, is_enabled }
// POST   ?akcia=rozpocet            nastavi denny strop { ucel, source_id, budget }
// POST   ?akcia=stav                zapne/vypne ucel { ucel, source_id, is_enabled, dovod }
//
// Model do produkcie sa uz neberie z konstanty v konfiguraku, ale z tohto
// poradia — pozri api/helpers/ai_modely_fn.php.
require_auth(true);

require_once __DIR__ . '/../../helpers/openrouter_boot.php';
require_once __DIR__ . '/../../helpers/ai_modely_fn.php';

$pdo = db();

// ------------------------------------------------------------
// GET — ciselnik, poradie a stav
// ------------------------------------------------------------
if ($method === 'GET') {
    $ucel = $_GET['ucel'] ?? 'parse';
    if (!in_array($ucel, AI_UCELY, true)) json_error('Neznámy účel: ' . $ucel, 400);

    $sourceId = isset($_GET['source_id']) && $_GET['source_id'] !== ''
              ? (int)$_GET['source_id'] : null;

    // --- ciselnik ---
    // Zoradenie kopiruje logiku vyberu: pouzitelne hore, medzi nimi
    // bezplatne a podla zhody. Admin tak vidi zoznam v poradi vhodnosti.
    $modely = $pdo->query(
        'SELECT id, model_id, name, provider, is_free, is_enabled, is_text_only,
                price_input_1m, price_output_1m, context_length,
                success_rate, agree_rate, lab_runs, lab_ok, avg_tokens, avg_ms,
                unavailable_reason, last_error, last_tested_at
           FROM job.ai_models
          ORDER BY (is_enabled AND unavailable_reason IS NULL) DESC,
                   is_free DESC,
                   COALESCE(agree_rate, -1) DESC,
                   COALESCE(price_input_1m, 0) + COALESCE(price_output_1m, 0) ASC,
                   model_id')->fetchAll();

    foreach ($modely as &$m) {
        $m['is_free']      = ai_je_true($m['is_free']);
        $m['is_enabled']   = ai_je_true($m['is_enabled']);
        $m['is_text_only'] = ai_je_true($m['is_text_only']);
        $m['pouzitelny']   = $m['is_enabled'] && $m['unavailable_reason'] === null;
        // Cena za jedno volanie pri typickom inzerate (~5000 vstup / 800 vystup).
        $m['cena_volania'] = round(ai_cena_volania($m, 5000, 800), 6);
    }
    unset($m);

    // --- poradie na ucel ---
    $st = $pdo->prepare(
        'SELECT p.id, p.poradie, p.is_enabled, p.source_id,
                m.id AS model_db_id, m.model_id, m.name, m.is_free,
                m.agree_rate, m.success_rate, m.unavailable_reason,
                m.price_input_1m, m.price_output_1m
           FROM job.ai_poradie p
           JOIN job.ai_models m ON m.id = p.model_id
          WHERE p.ucel = ? AND p.source_id IS NOT DISTINCT FROM ?
          ORDER BY p.poradie');
    $st->execute([$ucel, $sourceId]);
    $poradie = $st->fetchAll();
    foreach ($poradie as &$p) {
        $p['is_free']    = ai_je_true($p['is_free']);
        $p['is_enabled'] = ai_je_true($p['is_enabled']);
    }
    unset($p);

    // --- stav dnesneho dna ---
    $st = $pdo->prepare(
        'SELECT s.*, m.model_id AS model_key, m.name AS model_name
           FROM job.ai_stav s
           LEFT JOIN job.ai_models m ON m.id = s.model_id
          WHERE s.ucel = ? AND s.source_id = ? AND s.den = CURRENT_DATE');
    $st->execute([$ucel, $sourceId ?? 0]);
    $stav = $st->fetch() ?: null;
    if ($stav) $stav['is_enabled'] = ai_je_true($stav['is_enabled']);

    // Ktory model by sa prave teraz pouzil — hlavna informacia pre admina.
    $vyber = ai_vyber_model($ucel, $sourceId);

    json_ok([
        'ucel'      => $ucel,
        'source_id' => $sourceId,
        'modely'    => $modely,
        'poradie'   => $poradie,
        'stav'      => $stav,
        'aktivny'   => [
            'model'   => $vyber['model']['model_id'] ?? null,
            'nazov'   => $vyber['model']['name'] ?? null,
            'dovod'   => $vyber['dovod'],
            'vypnute' => $vyber['vypnute'],
        ],
        'portaly'   => $pdo->query(
            'SELECT id, code, name FROM job.sources WHERE is_active ORDER BY name')->fetchAll(),
        'pocty'     => [
            'spolu'       => count($modely),
            'pouzitelnych'=> count(array_filter($modely, fn($m) => $m['pouzitelny'])),
            'free'        => count(array_filter($modely,
                                    fn($m) => $m['is_free'] && $m['pouzitelny'])),
        ],
    ]);
}

if ($method !== 'POST') json_error('Method not allowed', 405);

$vstup = json_decode(file_get_contents('php://input'), true) ?: [];
$akcia = $_GET['akcia'] ?? '';
$user  = require_auth(true);

// ------------------------------------------------------------
// POST ?akcia=sync — zosynchronizuje cennik z OpenRoutera
// ------------------------------------------------------------
if ($akcia === 'sync') {
    try {
        [$pridanych, $aktualiz] = ai_sync_cennik();
    } catch (RuntimeException $e) {
        json_error($e->getMessage(), 502);
    }
    json_ok(['pridanych' => $pridanych, 'aktualizovanych' => $aktualiz,
             'sprava' => "Cenník zosynchronizovaný: $pridanych nových, $aktualiz aktualizovaných"]);
}

// ------------------------------------------------------------
// POST ?akcia=poradie — ulozi poradie modelov
//
// Prijme zoznam ID modelov v poradi. Cele poradie sa prepise naraz —
// jednoduchsie a bezpecnejsie nez posuvat jednotlive riadky.
// ------------------------------------------------------------
if ($akcia === 'poradie') {
    $ucel = $vstup['ucel'] ?? '';
    if (!in_array($ucel, AI_UCELY, true)) json_error('Neznámy účel', 400);

    $sourceId = isset($vstup['source_id']) && $vstup['source_id'] !== ''
              ? (int)$vstup['source_id'] : null;
    $modely = $vstup['modely'] ?? [];
    if (!is_array($modely)) json_error('Očakáva sa zoznam modelov', 400);

    // Posudenie vhodnosti sa robi nad uz vytazenym inzeratom — je jedno,
    // z ktoreho portalu prisiel.
    if ($ucel === 'eval' && $sourceId !== null) {
        json_error('Posudzovanie vhodnosti sa nenastavuje na portál', 400);
    }

    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare(
            'DELETE FROM job.ai_poradie WHERE ucel = ? AND source_id IS NOT DISTINCT FROM ?');
        $del->execute([$ucel, $sourceId]);

        $ins = $pdo->prepare(
            'INSERT INTO job.ai_poradie (ucel, source_id, poradie, model_id)
             VALUES (?, ?, ?, ?)');
        $i = 0;
        foreach ($modely as $modelDbId) {
            $ins->execute([$ucel, $sourceId, ++$i, (int)$modelDbId]);
        }

        // Poradie sa zmenilo — zacina sa odznova od prveho modelu.
        $pdo->prepare(
            "UPDATE job.ai_stav SET poradie_index = 1, fails_in_row = 0
              WHERE ucel = ? AND source_id = ? AND den = CURRENT_DATE")
            ->execute([$ucel, $sourceId ?? 0]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    json_ok(['ulozenych' => $i, 'sprava' => "Poradie uložené ($i modelov)"]);
}

// ------------------------------------------------------------
// POST ?akcia=model — rucne zapnutie/vypnutie modelu
//
// Zapnutie zaroven maze unavailable_reason: admin tym hovori, ze model
// uz zase funguje (napr. po obnoveni denneho limitu).
// ------------------------------------------------------------
if ($akcia === 'model') {
    $modelDbId = (int)($vstup['model_id'] ?? 0);
    if (!$modelDbId) json_error('Chýba model_id', 400);
    $zapnut = !empty($vstup['is_enabled']);

    if ($zapnut) {
        $pdo->prepare(
            'UPDATE job.ai_models
                SET is_enabled = TRUE, unavailable_reason = NULL, last_error = NULL,
                    updated_at = NOW()
              WHERE id = ?')->execute([$modelDbId]);
    } else {
        $pdo->prepare(
            "UPDATE job.ai_models
                SET is_enabled = FALSE, unavailable_reason = 'Vypnuté správcom',
                    updated_at = NOW()
              WHERE id = ?")->execute([$modelDbId]);
    }
    json_ok(['sprava' => $zapnut ? 'Model zapnutý' : 'Model vypnutý']);
}

// ------------------------------------------------------------
// POST ?akcia=rozpocet — denny strop nakladov
// ------------------------------------------------------------
if ($akcia === 'rozpocet') {
    $ucel = $vstup['ucel'] ?? '';
    if (!in_array($ucel, AI_UCELY, true)) json_error('Neznámy účel', 400);
    $sid    = isset($vstup['source_id']) && $vstup['source_id'] !== ''
            ? (int)$vstup['source_id'] : 0;
    $budget = (float)($vstup['budget'] ?? 1.0);
    if ($budget < 0) json_error('Strop nemôže byť záporný', 400);

    $pdo->prepare(
        'INSERT INTO job.ai_stav (ucel, source_id, den, daily_budget_usd)
         VALUES (?, ?, CURRENT_DATE, ?)
         ON CONFLICT (ucel, source_id, den) DO UPDATE
            SET daily_budget_usd = EXCLUDED.daily_budget_usd,
                warned_80_at = NULL, stopped_150_at = NULL')
        ->execute([$ucel, $sid, $budget]);

    json_ok(['sprava' => sprintf('Denný strop nastavený na $%.2f', $budget)]);
}

// ------------------------------------------------------------
// POST ?akcia=stav — zapnutie/vypnutie ucelu pre dnesok
// ------------------------------------------------------------
if ($akcia === 'stav') {
    $ucel = $vstup['ucel'] ?? '';
    if (!in_array($ucel, AI_UCELY, true)) json_error('Neznámy účel', 400);
    $sourceId = isset($vstup['source_id']) && $vstup['source_id'] !== ''
              ? (int)$vstup['source_id'] : null;

    if (!empty($vstup['is_enabled'])) {
        ai_zapni($ucel, $sourceId, (int)$user['user_id']);
        json_ok(['sprava' => 'Úloha zapnutá']);
    }
    ai_vypni($ucel, $sourceId, $vstup['dovod'] ?? 'Vypnuté správcom', (int)$user['user_id']);
    json_ok(['sprava' => 'Úloha vypnutá']);
}

json_error('Neznáma akcia: ' . $akcia, 400);
