<?php
// Praca s ciselnikom modelov job.ai_models a poradim job.ai_poradie.
//
// Aplikacia ziskava udaje v dvoch krokoch a kazdy moze bezat na inom modeli:
//   'parse' — vytazenie udajov z inzeratu pri zbere (na portal)
//   'eval'  — posudenie vhodnosti pre pouzivatela (na portale nezavisi)
//
// Model sa uz neberie z konstanty v konfiguraku, ale z poradia v DB. Ked
// prvy zlyha, prejde sa na dalsi bez zasahu cloveka.
//
// Prevzate z BetClub (api/helpers/ai_models_fn.php), upravene na dvojicu
// (ucel, portal) namiesto sutaze.

const AI_UCELY = ['parse', 'eval', 'translate'];

// Kontext pod tuto hranicu sa do vyberu neberie — inzerat aj s promptom
// ma okolo 14 000 znakov a kratsi kontext by odpoved orezal.
const AI_MIN_CONTEXT = 16000;

// ------------------------------------------------------------
// Zosynchronizuje ciselnik s aktualnym cennikom OpenRoutera.
//
// Ceny aj to, ktore modely su bezplatne, sa v case meni — minimax-m3
// prestal byt free 8.9.2026. Preto sa cennik tiahne zivo.
//
// Vracia [pridanych, aktualizovanych].
// ------------------------------------------------------------
function ai_sync_cennik(): array {
    $ch = curl_init('https://openrouter.ai/api/v1/models');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . OPENROUTER_KEY],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$resp) {
        throw new RuntimeException("Cenník sa nepodarilo načítať (HTTP $code)");
    }

    $pdo = db();
    $st = $pdo->prepare(
        "INSERT INTO job.ai_models
            (provider, model_id, name, is_free, price_input_1m, price_output_1m,
             context_length, is_text_only, updated_at)
         VALUES ('openrouter', ?, ?, ?, ?, ?, ?, TRUE, NOW())
         ON CONFLICT (model_id) DO UPDATE SET
            name            = EXCLUDED.name,
            is_free         = EXCLUDED.is_free,
            price_input_1m  = EXCLUDED.price_input_1m,
            price_output_1m = EXCLUDED.price_output_1m,
            context_length  = EXCLUDED.context_length,
            is_text_only    = TRUE,
            updated_at      = NOW()
         RETURNING (xmax = 0) AS pridany");

    $pridanych = 0;
    $aktualiz  = 0;
    $videne    = [];   // model_id, ktore cennik prave vratil

    foreach (json_decode($resp, true)['data'] ?? [] as $m) {
        $id = $m['id'] ?? '';
        if ($id === '') continue;

        // Varianty ako :batch a -image na tazanie udajov nepotrebujeme
        // a ciselnik by len zahltili.
        if (str_contains($id, ':batch') || str_contains($id, '-image')) continue;

        // Do vyberu patria len modely, ktore vracaju VYLUCNE text.
        //
        // Hudobne a obrazkove modely (lyria, veo) maju v zozname modalit aj
        // 'text', ale za tokeny nic nestoja — plati sa za sekundy zvuku alebo
        // za obrazok. V poradi podla ceny by tak vysli ako najlacnejsie
        // a automaticky vyber by siahol po nich.
        $vystupy = $m['architecture']['output_modalities'] ?? ['text'];
        if ($vystupy !== ['text']) continue;

        $p = $m['pricing'] ?? [];
        // OpenRouter uvadza cenu za JEDEN token, my drzime za milion.
        //
        // Zaporna hodnota znamena "cena podla skutocne pouziteho modelu"
        // (openrouter/auto). Taku cenu nevieme dopredu, uklada sa NULL —
        // inak by -1 po vynasobeni milionom pretiekol stlpec.
        $cena = static function ($v): ?float {
            if ($v === null) return null;
            $f = (float)$v;
            return $f < 0 ? null : $f * 1000000;
        };

        $st->execute([
            $id,
            mb_substr($m['name'] ?? $id, 0, 200),
            str_ends_with($id, ':free') ? 't' : 'f',
            $cena($p['prompt']     ?? null),
            $cena($p['completion'] ?? null),
            $m['context_length'] ?? null,
        ]);

        if ($st->fetchColumn()) $pridanych++; else $aktualiz++;
        $videne[] = $id;
    }

    // Modely, ktore z cennika zmizli, sa nemazu — evaluacie na ne odkazuju.
    // Iba sa vyradia z automatickeho vyberu.
    //
    // Porovnava sa proti zoznamu prave videnych modelov, nie proti casu:
    // kontrola cez updated_at by pri prvom behu vyradila vsetko naraz.
    if ($videne) {
        $otazniky = implode(',', array_fill(0, count($videne), '?'));
        $pdo->prepare(
            "UPDATE job.ai_models
                SET is_enabled = FALSE,
                    unavailable_reason = 'Model už nie je v cenníku OpenRoutera',
                    updated_at = NOW()
              WHERE provider = 'openrouter'
                AND is_enabled
                AND unavailable_reason IS NULL
                AND model_id NOT IN ($otazniky)")->execute($videne);
    }

    return [$pridanych, $aktualiz];
}

// ------------------------------------------------------------
// Cena volania v USD podla ceny modelu v case volania.
// ------------------------------------------------------------
function ai_cena_volania(?array $model, ?int $vstup, ?int $vystup): float {
    if (!$model) return 0.0;
    return ($vstup  ?? 0) / 1000000 * (float)($model['price_input_1m']  ?? 0)
         + ($vystup ?? 0) / 1000000 * (float)($model['price_output_1m'] ?? 0);
}

// ------------------------------------------------------------
// Poradie modelov pre dany ucel a portal.
//
// Konkretny portal ma prednost pred nastavenim "pre vsetky portaly":
// profesia.sk moze mat vlastne poradie, zvysok spolocne.
//
// Vracia zoznam v poradi, v akom sa maju skusat. Prazdny zoznam znamena,
// ze poradie nie je nastavene.
// ------------------------------------------------------------
function ai_poradie(string $ucel, ?int $sourceId = null): array {
    $pdo = db();

    // najprv poradie konkretneho portalu
    if ($sourceId !== null) {
        $st = $pdo->prepare(
            'SELECT p.poradie, m.*
               FROM job.ai_poradie p
               JOIN job.ai_models m ON m.id = p.model_id
              WHERE p.ucel = ? AND p.source_id = ? AND p.is_enabled
                AND m.is_enabled AND m.unavailable_reason IS NULL
              ORDER BY p.poradie');
        $st->execute([$ucel, $sourceId]);
        $riadky = $st->fetchAll();
        if ($riadky) return $riadky;
    }

    // spolocne poradie pre vsetky portaly
    $st = $pdo->prepare(
        'SELECT p.poradie, m.*
           FROM job.ai_poradie p
           JOIN job.ai_models m ON m.id = p.model_id
          WHERE p.ucel = ? AND p.source_id IS NULL AND p.is_enabled
            AND m.is_enabled AND m.unavailable_reason IS NULL
          ORDER BY p.poradie');
    $st->execute([$ucel]);
    return $st->fetchAll();
}

// ------------------------------------------------------------
// Ktory model ma prave teraz obsluhovat dany ucel.
//
// Poradie hladania:
//   1. stav na dnesny den (job.ai_stav) — vratane pripadneho vypnutia
//   2. poradie modelov (job.ai_poradie) na mieste, kde sme skoncili
//   3. najlepsi bezplatny model z ciselnika
//
// Konstanta OPENROUTER_MODEL sa uz zamerne nepouziva — model patri do
// ciselnika, nie do konfiguraku.
//
// Vracia ['model' => riadok_ciselnika|null, 'dovod' => text, 'vypnute' => bool].
// ------------------------------------------------------------
function ai_vyber_model(string $ucel, ?int $sourceId = null): array {
    $pdo = db();
    $den = date('Y-m-d');
    $sid = $sourceId ?? 0;

    // 1) stav na dnesok
    //
    // Stlpce zo stavu maju predponu stav_: job.ai_models ma tiez stlpce
    // is_enabled a id, a pri FETCH_ASSOC by ich m.* prepisalo. Bez predpony
    // sa cital priznak z MODELU namiesto zo stavu — a pri prazdnom model_id
    // (LEFT JOIN nic nenasiel) vysiel NULL, co sa vyhodnotilo ako "vypnute".
    $st = $pdo->prepare(
        'SELECT s.is_enabled     AS stav_is_enabled,
                s.disabled_reason AS stav_disabled_reason,
                s.chosen_by      AS stav_chosen_by,
                s.poradie_index  AS stav_poradie_index,
                s.model_id       AS stav_model_id,
                m.*
           FROM job.ai_stav s
           LEFT JOIN job.ai_models m ON m.id = s.model_id
          WHERE s.ucel = ? AND s.source_id = ? AND s.den = ?');
    $st->execute([$ucel, $sid, $den]);
    $stav = $st->fetch();

    if ($stav && !ai_je_true($stav['stav_is_enabled'])) {
        return ['model' => null, 'vypnute' => true,
                'dovod' => 'Vypnuté pre dnešok: '
                         . ($stav['stav_disabled_reason'] ?: 'bez uvedeného dôvodu')];
    }

    // 2) poradie — berie sa miesto, na ktorom sme skoncili, aby sa po
    //    prepnuti nevracalo k modelu, ktory uz zlyhal
    $poradie = ai_poradie($ucel, $sourceId);
    if ($poradie) {
        $index = max(1, (int)($stav['stav_poradie_index'] ?? 1));
        $model = $poradie[min($index, count($poradie)) - 1];
        return ['model' => $model, 'vypnute' => false,
                'dovod' => sprintf('poradie %d z %d', $index, count($poradie))];
    }

    // Rucne nastaveny model na den bez poradia. Kontroluje sa stav_model_id
    // (odkaz zo stavu), ale vracia sa riadok ciselnika — v $stav su vdaka
    // m.* aj stlpce modelu vratane model_id.
    if ($stav && !empty($stav['stav_model_id']) && !empty($stav['model_id'])) {
        return ['model' => $stav, 'vypnute' => false,
                'dovod' => 'nastavenie na deň (' . $stav['stav_chosen_by'] . ')'];
    }

    // 3) zaloha: najlepsi bezplatny model z ciselnika
    $model = $pdo->query(
        'SELECT * FROM job.ai_models
          WHERE is_enabled AND unavailable_reason IS NULL AND is_free AND is_text_only
            AND (context_length IS NULL OR context_length >= ' . AI_MIN_CONTEXT . ')
          ORDER BY COALESCE(agree_rate, -1) DESC, COALESCE(success_rate, -1) DESC
          LIMIT 1')->fetch();

    if ($model) {
        return ['model' => $model, 'vypnute' => false,
                'dovod' => 'poradie nie je nastavené, použitý najlepší bezplatný'];
    }

    return ['model' => null, 'vypnute' => false,
            'dovod' => 'V číselníku nie je použiteľný model — spusti synchronizáciu cenníka'];
}

// Najlacnejsi funkcny model — pouzije sa pri prekroceni 80 % denneho stropu.
function ai_najlacnejsi(): ?array {
    $r = db()->query(
        'SELECT * FROM job.ai_models
          WHERE is_enabled AND unavailable_reason IS NULL AND is_text_only
            AND (success_rate IS NULL OR success_rate > 0)
            AND (context_length IS NULL OR context_length >= ' . AI_MIN_CONTEXT . ')
            AND (is_free OR (price_input_1m IS NOT NULL AND price_output_1m IS NOT NULL))
          ORDER BY is_free DESC,
                   COALESCE(price_input_1m, 0) + COALESCE(price_output_1m, 0) ASC
          LIMIT 1')->fetch();
    return $r ?: null;
}

// Boolean z PostgreSQL cez PDO. Tvar sa lisi podla ovladaca a nastavenia:
// true, 't', 'true', '1', 1 — a pri emulovanych prepared statements aj
// prazdny retazec pre FALSE. Porovnava sa preto zoznamom PRAVDIVYCH hodnot,
// nie negaciou nepravdivych; neznamu hodnotu je bezpecnejsie brat ako false.
function ai_je_true($v): bool {
    if (is_bool($v)) return $v;
    if (is_int($v))  return $v === 1;
    if (is_string($v)) {
        return in_array(strtolower($v), ['t', 'true', '1', 'y', 'yes', 'on'], true);
    }
    return false;
}

// ------------------------------------------------------------
// Je chyba trvala, teda nema zmysel model skusat znova?
//
// Docasne prekazky maju prednost: pri vycerpanom dennom limite vracaju
// poskytovatelia hlasky, ktore inak vyzeraju ako trvale zlyhanie. Model,
// ktory zajtra pobezi, sa nesmie vyradit natrvalo.
//
// Vracia zrozumitelny dovod, alebo null pri docasnej chybe.
// ------------------------------------------------------------
function ai_trvala_chyba(?string $chyba): ?string {
    if ($chyba === null || $chyba === '') return null;
    $c = mb_strtolower($chyba);

    foreach (['rate limit', 'rate-limited', 'temporarily', 'overloaded',
              'try again', 'retry', 'timeout', 'resourceexhausted'] as $docasne) {
        if (str_contains($c, $docasne)) return null;
    }

    $trvale = [
        'agentic harness'       => 'Dostupný len agentickým nástrojom, nie cez API',
        'no endpoints found'    => 'Model nemá dostupný endpoint',
        'is not a valid model'  => 'Model už neexistuje',
        'unavailable for free'  => 'Model už nie je bezplatný',
        'requires more credits' => 'Model vyžaduje kredit',
        'data policy'           => 'Blokuje nastavenie ochrany údajov na účte',
    ];
    foreach ($trvale as $vzor => $popis) {
        if (str_contains($c, $vzor)) return $popis;
    }
    return null;
}

// Oznaci model za trvalo nepouzitelny.
function ai_vyrad_model(string $modelKey, string $dovod): void {
    db()->prepare(
        'UPDATE job.ai_models
            SET is_enabled = FALSE, unavailable_reason = ?, last_error = ?, updated_at = NOW()
          WHERE model_id = ?')->execute([$dovod, $dovod, $modelKey]);
}

// ------------------------------------------------------------
// Nastavi model na dnesny den. Pouziva ho admin aj automaticky vyber.
// ------------------------------------------------------------
function ai_nastav_model(string $ucel, ?int $sourceId, int $modelDbId,
                         string $chosenBy, ?int $userId = null): void {
    db()->prepare(
        "INSERT INTO job.ai_stav
            (ucel, source_id, den, model_id, chosen_by, chosen_by_user_id, chosen_at, is_enabled)
         VALUES (?, ?, CURRENT_DATE, ?, ?, ?, NOW(), TRUE)
         ON CONFLICT (ucel, source_id, den) DO UPDATE
            SET model_id = EXCLUDED.model_id,
                chosen_by = EXCLUDED.chosen_by,
                chosen_by_user_id = EXCLUDED.chosen_by_user_id,
                chosen_at = NOW(),
                is_enabled = TRUE,
                disabled_reason = NULL")
        ->execute([$ucel, $sourceId ?? 0, $modelDbId, $chosenBy, $userId]);
}

// Vypne dany ucel pre dnesok.
function ai_vypni(string $ucel, ?int $sourceId, string $dovod, ?int $userId = null): void {
    db()->prepare(
        "INSERT INTO job.ai_stav
            (ucel, source_id, den, is_enabled, disabled_reason, chosen_by,
             chosen_by_user_id, chosen_at)
         VALUES (?, ?, CURRENT_DATE, FALSE, ?, 'admin', ?, NOW())
         ON CONFLICT (ucel, source_id, den) DO UPDATE
            SET is_enabled = FALSE,
                disabled_reason = EXCLUDED.disabled_reason,
                chosen_by_user_id = EXCLUDED.chosen_by_user_id,
                chosen_at = NOW()")
        ->execute([$ucel, $sourceId ?? 0, $dovod, $userId]);
}

// Znovu zapne ucel pre dnesok (admin).
function ai_zapni(string $ucel, ?int $sourceId, ?int $userId = null): void {
    db()->prepare(
        "INSERT INTO job.ai_stav
            (ucel, source_id, den, is_enabled, chosen_by, chosen_by_user_id, chosen_at)
         VALUES (?, ?, CURRENT_DATE, TRUE, 'admin', ?, NOW())
         ON CONFLICT (ucel, source_id, den) DO UPDATE
            SET is_enabled = TRUE, disabled_reason = NULL,
                stopped_150_at = NULL,
                chosen_by_user_id = EXCLUDED.chosen_by_user_id, chosen_at = NOW()")
        ->execute([$ucel, $sourceId ?? 0, $userId]);
}

// ------------------------------------------------------------
// Zaznamena vysledok volania: naklady, pocitadla a v pripade opakovanych
// zlyhani prepnutie na dalsi model v poradi.
//
// Prepina sa az po TROCH zlyhaniach za sebou — jedno zlyhanie moze byt
// vypadok siete a striedat model pri kazdom zakolisani by bolo horsie
// nez chvilu pockat. Trvala prekazka prepne hned.
//
// Vracia popis prepnutia, alebo null ked sa nic nemenilo.
// ------------------------------------------------------------
function ai_po_volani(string $ucel, ?int $sourceId, bool $uspech,
                      ?string $chyba = null, float $cena = 0.0): ?string {
    $pdo = db();
    $sid = $sourceId ?? 0;

    // Zapis nakladov a pocitadiel. Riadok na dnesok moze este neexistovat.
    $pdo->prepare(
        "INSERT INTO job.ai_stav (ucel, source_id, den, spent_usd, calls_count, fails_in_row)
         VALUES (?, ?, CURRENT_DATE, ?, 1, ?)
         ON CONFLICT (ucel, source_id, den) DO UPDATE
            SET spent_usd    = job.ai_stav.spent_usd + EXCLUDED.spent_usd,
                calls_count  = job.ai_stav.calls_count + 1,
                -- ?::BOOLEAN, nie holy ?: v prepared statement PostgreSQL
                -- neodvodi typ parametra vo WHEN a volanie zlyha. Cely INSERT
                -- by potom neprebehol a riadok na dnesok by ostal nekompletny.
                fails_in_row = CASE WHEN ?::BOOLEAN THEN 0
                                    ELSE job.ai_stav.fails_in_row + 1 END")
        ->execute([$ucel, $sid, $cena, $uspech ? 0 : 1, $uspech ? 'true' : 'false']);

    if ($uspech) return null;

    // Trvala prekazka vyradi model z ciselnika hned, netreba cakat na tri.
    $trvala = ai_trvala_chyba($chyba);

    $st = $pdo->prepare(
        'SELECT fails_in_row, poradie_index FROM job.ai_stav
          WHERE ucel = ? AND source_id = ? AND den = CURRENT_DATE');
    $st->execute([$ucel, $sid]);
    $stav = $st->fetch() ?: ['fails_in_row' => 1, 'poradie_index' => 1];

    $zlyhani = (int)$stav['fails_in_row'];
    if ($trvala === null && $zlyhani < 3) return null;

    $poradie = ai_poradie($ucel, $sourceId);
    if (!$poradie) return null;

    $terajsi = max(1, (int)$stav['poradie_index']);

    if ($trvala !== null) {
        $vyber = ai_vyber_model($ucel, $sourceId);
        if (!empty($vyber['model']['model_id'])) {
            ai_vyrad_model($vyber['model']['model_id'], $trvala);
        }
    }

    // Dalsi v poradi. Ked sme na konci, ucel sa vypne — vsetky modely
    // zlyhali a ukladat nespravne vytazene udaje je horsie nez ziadne.
    $dalsi = $terajsi + 1;
    if ($dalsi > count($poradie)) {
        $dovod = 'Zlyhali všetky modely z poradia (' . count($poradie) . ')';
        ai_vypni($ucel, $sourceId, $dovod);
        ai_uvedom_admina(
            'JOB: zastavené — zlyhali všetky modely (' . $ucel . ')',
            "$dovod.\n\nPosledná chyba: " . ($chyba ?? 'neuvedená')
          . "\n\nV Správa → Modely sa dá poradie upraviť alebo úlohu znova zapnúť.");
        return $dovod;
    }

    $novy = $poradie[$dalsi - 1];

    $pdo->prepare(
        "INSERT INTO job.ai_stav
            (ucel, source_id, den, model_id, poradie_index, is_enabled,
             chosen_by, chosen_at, fails_in_row, prepnuti_dnes)
         VALUES (?, ?, CURRENT_DATE, ?, ?, TRUE, 'fail', NOW(), 0, 1)
         ON CONFLICT (ucel, source_id, den) DO UPDATE
            SET model_id = EXCLUDED.model_id,
                poradie_index = EXCLUDED.poradie_index,
                chosen_by = 'fail', chosen_at = NOW(), fails_in_row = 0,
                prepnuti_dnes = job.ai_stav.prepnuti_dnes + 1")
        ->execute([$ucel, $sid, (int)$novy['id'], $dalsi]);

    $sprava = sprintf('Model %s zlyhal (%s), %s prepnuté na %s',
        $poradie[$terajsi - 1]['model_id'] ?? '?',
        $trvala ?? "{$zlyhani}× za sebou",
        $ucel, $novy['model_id']);

    ai_uvedom_admina('JOB: prepnuté na náhradný model', $sprava);
    return $sprava;
}

// ------------------------------------------------------------
// Strazi denny strop nakladov.
//
// Vola sa po kazdom ostrom volani. Podla vyuzitia stropu:
//   80 %  prepne na najlacnejsi funkcny model — zber dobehne lacnejsie
//         namiesto toho, aby sa zastavil uprostred
//   150 % zastavi ucel pre dany den
//
// Obe hranice posielaju spravu adminovi, kazdu najviac raz za den.
// Vracia zoznam vykonanych zasahov (prazdny, ked sa nic nedialo).
// ------------------------------------------------------------
function ai_straz_rozpocet(string $ucel, ?int $sourceId = null): array {
    $pdo = db();
    $sid = $sourceId ?? 0;
    $zasahy = [];

    $st = $pdo->prepare(
        'SELECT s.spent_usd, s.daily_budget_usd, s.warned_80_at, s.stopped_150_at,
                m.model_id AS model_key
           FROM job.ai_stav s
           LEFT JOIN job.ai_models m ON m.id = s.model_id
          WHERE s.ucel = ? AND s.source_id = ? AND s.den = CURRENT_DATE');
    $st->execute([$ucel, $sid]);
    $cfg = $st->fetch();
    if (!$cfg) return $zasahy;

    $minute = (float)$cfg['spent_usd'];
    $strop  = (float)($cfg['daily_budget_usd'] ?: 1.0);
    if ($strop <= 0) return $zasahy;

    $podiel = $minute / $strop;

    // --- 150 %: zastavit ---
    if ($podiel >= 1.5 && empty($cfg['stopped_150_at'])) {
        $dovod = sprintf('Prekročený denný strop: minuté $%.4f z $%.2f (%.0f %%)',
                         $minute, $strop, $podiel * 100);
        ai_vypni($ucel, $sourceId, $dovod);
        $pdo->prepare(
            'UPDATE job.ai_stav SET stopped_150_at = NOW()
              WHERE ucel = ? AND source_id = ? AND den = CURRENT_DATE')
            ->execute([$ucel, $sid]);

        ai_uvedom_admina('JOB: zastavené — prekročený denný strop',
            "$dovod\n\nÚloha '$ucel' je pre dnešok vypnutá. V Správa → Modely "
          . "sa dá znova zapnúť alebo zvýšiť denný strop.");

        $zasahy[] = ['typ' => 'zastavene', 'minute' => $minute, 'strop' => $strop];
        return $zasahy;
    }

    // --- 80 %: prepnut na najlacnejsi ---
    if ($podiel >= 0.8 && empty($cfg['warned_80_at'])) {
        $lacny = ai_najlacnejsi();

        if ($lacny && $lacny['model_id'] !== ($cfg['model_key'] ?? null)) {
            ai_nastav_model($ucel, $sourceId, (int)$lacny['id'], 'budget');
            $sprava = sprintf(
                'Minuté $%.4f z denného stropu $%.2f (%%%.0f). Úloha %s prepnutá '
              . 'na najlacnejší funkčný model %s, aby zber dobehol.',
                $minute, $strop, $podiel * 100, $ucel, $lacny['model_id']);
            $zasahy[] = ['typ' => 'prepnute', 'model' => $lacny['model_id'],
                         'minute' => $minute, 'strop' => $strop];
        } else {
            $sprava = sprintf(
                'Minuté $%.4f z denného stropu $%.2f (%.0f %%). Lacnejší model '
              . 'sa nenašiel — pri 150 %% sa úloha %s zastaví.',
                $minute, $strop, $podiel * 100, $ucel);
            $zasahy[] = ['typ' => 'upozornenie', 'minute' => $minute, 'strop' => $strop];
        }

        $pdo->prepare(
            'UPDATE job.ai_stav SET warned_80_at = NOW()
              WHERE ucel = ? AND source_id = ? AND den = CURRENT_DATE')
            ->execute([$ucel, $sid]);

        ai_uvedom_admina('JOB: blíži sa denný strop', $sprava);
    }

    return $zasahy;
}

// Sprava adminom. Nefunkcna posta nesmie zhodit zber.
function ai_uvedom_admina(string $predmet, string $telo): void {
    try {
        if (!function_exists('send_mail_logged')) {
            $m = __DIR__ . '/mailer.php';
            if (!file_exists($m)) return;
            require_once $m;
        }
        $pdo = db();
        $st = $pdo->query(
            "SELECT email FROM admin.users
              WHERE role = 'admin' AND is_active AND email IS NOT NULL AND email <> ''");
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $email) {
            send_mail_logged($pdo, $email, $predmet, nl2br(htmlspecialchars($telo)));
        }
    } catch (Throwable $e) {
        error_log('JOB rozpocet: notifikacia zlyhala - ' . $e->getMessage());
    }
}

// ------------------------------------------------------------
// Prepocita uspesnost a zhodu modelu z historie volani.
//
// Zhoda (agree_rate) je pri tazani udajov dolezitejsia nez uspesnost:
// model, ktory vrati mzdu 1600 EUR ked ostatnych osem vrati 2400,
// odpovedal "uspesne" a napriek tomu je nepouzitelny.
// ------------------------------------------------------------
function ai_prepocitaj_statistiku(string $modelKey): void {
    db()->prepare(
        "UPDATE job.ai_models m SET
            lab_runs     = s.spolu,
            lab_ok       = s.ok,
            success_rate = CASE WHEN s.spolu > 0
                                THEN ROUND(100.0 * s.ok / s.spolu, 2) END,
            agree_rate   = CASE WHEN s.hodnotenych > 0
                                THEN ROUND(100.0 * s.zhodnych / s.hodnotenych, 2) END,
            avg_tokens   = s.tokeny,
            avg_ms       = s.ms,
            last_tested_at = s.posledny,
            updated_at   = NOW()
         FROM (SELECT COUNT(*) AS spolu,
                      COUNT(*) FILTER (WHERE status = 'ok') AS ok,
                      COUNT(*) FILTER (WHERE agrees IS NOT NULL) AS hodnotenych,
                      COUNT(*) FILTER (WHERE agrees) AS zhodnych,
                      ROUND(AVG(total_tokens))::INT AS tokeny,
                      ROUND(AVG(took_ms))::INT AS ms,
                      MAX(created_at) AS posledny
                 FROM job.ai_evaluations WHERE model_id = ?) s
         WHERE m.model_id = ?")->execute([$modelKey, $modelKey]);
}

// ------------------------------------------------------------
// Vyber davky modelov na testovanie.
//
// Testovat vsetky (cez 300) nema zmysel: trvalo by to hodinu a vacsina je
// pre tazanie udajov aj tak nevhodna (drahe, maly kontext).
//
// $davka:
//   'free'        len bezplatne
//   'lacne'       bezplatne + platene do 0,50 USD za 1M tokenov
//   'stredne'     bezplatne + platene do 2 USD za 1M
//   'netestovane' este netestovane (doplnenie historie)
//   'najlepsie'   uz otestovane s najvyssou zhodou (overenie vitazov)
//   'vsetky'      cely zoznam (pozor na cas)
// ------------------------------------------------------------
function ai_davka_na_test(string $davka, int $limit = 30): array {
    $kde = ['is_enabled', 'unavailable_reason IS NULL', 'is_text_only',
            '(context_length IS NULL OR context_length >= ' . AI_MIN_CONTEXT . ')'];
    $radenie = 'is_free DESC, COALESCE(price_input_1m,0) + COALESCE(price_output_1m,0) ASC';

    switch ($davka) {
        case 'free':
            $kde[] = 'is_free';
            break;

        case 'lacne':
            $kde[] = '(is_free OR (price_input_1m IS NOT NULL
                       AND price_input_1m + price_output_1m <= 0.5))';
            break;

        case 'stredne':
            $kde[] = '(is_free OR (price_input_1m IS NOT NULL
                       AND price_input_1m + price_output_1m <= 2))';
            break;

        case 'netestovane':
            $kde[] = 'lab_runs = 0';
            $kde[] = '(is_free OR (price_input_1m IS NOT NULL
                       AND price_input_1m + price_output_1m <= 2))';
            break;

        case 'najlepsie':
            // Uz otestovane, zoradene podla zhody — sluzi na overenie,
            // ci vitazi obstoja aj na inom inzerate.
            $kde[] = 'lab_runs > 0';
            $radenie = 'COALESCE(agree_rate, -1) DESC, COALESCE(success_rate, -1) DESC,
                        COALESCE(price_input_1m,0) + COALESCE(price_output_1m,0) ASC';
            break;

        default:  // 'vsetky'
            $kde[] = '(is_free OR (price_input_1m IS NOT NULL AND price_output_1m IS NOT NULL))';
    }

    return db()->query(
        'SELECT * FROM job.ai_models
          WHERE ' . implode(' AND ', $kde) . '
          ORDER BY ' . $radenie . '
          LIMIT ' . (int)$limit)->fetchAll();
}

// Kolko modelov by dana davka mala — pre odhad casu pred spustenim.
function ai_pocet_v_davke(string $davka): int {
    return count(ai_davka_na_test($davka, 1000));
}
