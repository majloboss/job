<?php
// Test modelov — vykonava beh zalozeny z obrazovky Test modelov.
//
// Bezi NA POZADI a do DB zapisuje priebezne, takze obrazovka ukazuje postup
// aj po refreshi a test pokracuje, aj ked sa prehliadac zavrie.
//
// Postup (podla zadania):
//   1. inzerat c. 1 — vsetky modely, uloha 'parse'
//   2. inzerat c. 1 — vsetky modely, uloha 'vhodnost'
//   3. inzerat c. 2 — to iste
//
// Modely su zoradene OD NAJLACNEJSICH: najprv bezplatne, potom platene
// po cenovy strop. Ked rozpocet dojde, test sa zastavi a v DB zostane
// vsetko, co sa dovtedy stihlo — to je zmysel priebezneho zapisu.
//
// Spustenie:
//   php api/cron/test_modelov.php --beh=12

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Len z prikazoveho riadka\n");
}

$BASE = dirname(__DIR__);
require_once $BASE . '/config/db.php';
require_once $BASE . '/helpers/db.php';

// json_error() je pre HTTP odpovede; v CLI musi skoncit vynimkou, inak by
// sa chyba stratila v prazdnom vystupe.
function json_error(string $sprava, int $kod = 500) { throw new RuntimeException($sprava); }
function json_ok($data = [], $kod = 200) { }

require_once $BASE . '/config/openrouter.php';
require_once $BASE . '/helpers/ai_modely_fn.php';
require_once $BASE . '/helpers/openrouter_fn.php';

$behId = 0;
foreach ($argv as $a) {
    if (preg_match('/^--beh=(\d+)$/', $a, $m)) $behId = (int)$m[1];
}
if (!$behId) exit("Pouzitie: php api/cron/test_modelov.php --beh=<id>\n");

$pdo = db();
$st = $pdo->prepare('SELECT * FROM job.test_behy WHERE id = ?');
$st->execute([$behId]);
$beh = $st->fetch();
if (!$beh) exit("Beh #$behId neexistuje\n");

printf("Beh #%d — rozpocet $%.2f, strop %.4f USD/1M, max %d modelov\n",
       $behId, $beh['rozpocet_usd'], $beh['cenovy_strop_1m'], $beh['max_modelov']);

// ------------------------------------------------------------
// Modely: od najlacnejsich. Bezplatne prve, potom platene po strop.
//
// Kontext pod 16 000 tokenov sa vynechava — inzerat aj s promptom ma
// okolo 14 000 znakov a kratsi kontext by odpoved orezal.
// ------------------------------------------------------------
$st = $pdo->prepare(
    "SELECT id, model_id, is_free, price_input_1m, price_output_1m
       FROM job.ai_models
      WHERE is_enabled AND unavailable_reason IS NULL AND is_text_only
        AND (context_length IS NULL OR context_length >= 16000)
        AND (is_free OR (price_input_1m IS NOT NULL AND price_output_1m IS NOT NULL
                         AND price_input_1m + price_output_1m > 0
                         AND price_input_1m + price_output_1m <= ?))
      ORDER BY is_free DESC,
               COALESCE(price_input_1m, 0) + COALESCE(price_output_1m, 0) ASC,
               model_id
      LIMIT ?");
$st->execute([$beh['cenovy_strop_1m'], $beh['max_modelov']]);
$modely = $st->fetchAll();

if (!$modely) {
    $pdo->prepare("UPDATE job.test_behy SET status='failed', finished_at=NOW(),
                   zastavene_dovod='V ciselniku nie su modely vyhovujuce stropu' WHERE id=?")
        ->execute([$behId]);
    exit("Ziadne modely nevyhovuju stropu.\n");
}

$pdo->prepare('UPDATE job.test_behy SET modelov_spolu = ? WHERE id = ?')
    ->execute([count($modely), $behId]);
printf("Modelov na test: %d (%d bezplatnych)\n\n",
       count($modely), count(array_filter($modely, fn($m) => ai_je_true($m['is_free']))));

$prompty = [
    'parse'    => or_active_prompt('test_parse'),
    'vhodnost' => or_active_prompt('test_vhodnost'),
];

$cenaSpolu = (float)$beh['cena_usd'];
$rozpocet  = (float)$beh['rozpocet_usd'];
$zastavene = false;

// ------------------------------------------------------------
// Hlavny cyklus: inzerat -> uloha -> modely
//
// Poradie je zamerne "najprv cely inzerat 1, potom cely inzerat 2" —
// tak ako v zadani. Pri zastaveni na rozpocte je tak aspon prvy inzerat
// spracovany uplne a da sa vyhodnotit.
// ------------------------------------------------------------
foreach ([1, 2] as $cislo) {
    if ($zastavene) break;

    $text = $beh['text' . $cislo];
    if (!$text) continue;                      // druhy inzerat je nepovinny

    foreach (['parse', 'vhodnost'] as $uloha) {
        if ($zastavene) break;

        printf("--- Inzerat %d, uloha %s ---\n", $cislo, $uloha);

        foreach ($modely as $model) {
            // Rozpocet sa kontroluje PRED kazdym volanim: po prekroceni
            // uz nema zmysel platit za dalsie.
            if ($cenaSpolu >= $rozpocet) {
                $zastavene = true;
                $dovod = sprintf('Dosiahnuty rozpocet $%.4f z $%.2f',
                                 $cenaSpolu, $rozpocet);
                $pdo->prepare("UPDATE job.test_behy
                                  SET status='stopped_budget', finished_at=NOW(),
                                      zastavene_dovod=? WHERE id=?")
                    ->execute([$dovod, $behId]);
                echo "\nZASTAVENE: $dovod\n";
                break;
            }

            // Zrusenie z obrazovky — kontroluje sa priebezne, aby sa dalo
            // zastavit aj dlho beziaci test.
            $stav = $pdo->prepare('SELECT status FROM job.test_behy WHERE id = ?');
            $stav->execute([$behId]);
            if ($stav->fetchColumn() === 'cancelled') {
                $zastavene = true;
                echo "\nZRUSENE pouzivatelom.\n";
                break;
            }

            $vysledok = test_zavolaj($beh, $cislo, $uloha, $model, $prompty[$uloha]);
            $cenaSpolu += $vysledok['cena'];

            test_zapis($pdo, $behId, $cislo, $uloha, $model, $vysledok);

            $pdo->prepare(
                "UPDATE job.test_behy SET
                    volani_spolu = volani_spolu + 1,
                    volani_ok    = volani_ok + ?,
                    tokenov_spolu = tokenov_spolu + ?,
                    cena_usd     = cena_usd + ?,
                    heartbeat_at = NOW()
                  WHERE id = ?")
                ->execute([$vysledok['ok'] ? 1 : 0, $vysledok['tokenov'],
                           $vysledok['cena'], $behId]);

            printf("  %-46s %-6s %5.1fs %6d tok. $%.6f  %s\n",
                   mb_substr($model['model_id'], 0, 46),
                   $vysledok['ok'] ? 'OK' : 'chyba',
                   $vysledok['ms'] / 1000, $vysledok['tokenov'], $vysledok['cena'],
                   mb_substr((string)($vysledok['popis'] ?? $vysledok['chyba'] ?? ''), 0, 40));
        }
    }
}

if (!$zastavene) {
    $pdo->prepare("UPDATE job.test_behy SET status='done', finished_at=NOW() WHERE id=?")
        ->execute([$behId]);
}

printf("\nHotovo: %d volani, $%.6f\n",
       (int)$pdo->query("SELECT volani_spolu FROM job.test_behy WHERE id=$behId")->fetchColumn(),
       $cenaSpolu);


// ============================================================
// Jedno volanie modelu
// ============================================================
function test_zavolaj(array $beh, int $cislo, string $uloha, array $model, array $prompt): array {
    $text = mb_substr((string)$beh['text' . $cislo], 0, OR_MAX_INPUT_CHARS);

    if ($uloha === 'parse') {
        $vstup = str_replace('{offer_text}', $text, $prompt['template']);
        // Odpoved obsahuje aj HTML inzeratu a preklad — potrebuje viac miesta.
        $maxTokens = 8000;
    } else {
        $vstup = strtr($prompt['template'], [
            '{offer_text}' => $text,
            '{cv_text}'    => mb_substr((string)$beh['cv_text'], 0, 6000) ?: '(zivotopis nie je nahraty)',
            '{prefs_text}' => (string)$beh['prefs_text'] ?: '(preferencie nie su vyplnene)',
        ]);
        $maxTokens = 2000;
    }

    $res = or_call_model($vstup, $model['model_id'], $maxTokens);
    $d   = is_array($res['data']) ? $res['data'] : [];

    $cena = ai_cena_volania($model,
                            $res['usage']['prompt_tokens'] ?? null,
                            $res['usage']['completion_tokens'] ?? null);

    // Uspech: pri tazeni musi prist nazov aj sumar, pri vhodnosti skore.
    $ok = $res['status'] === 'ok' && ($uloha === 'parse'
        ? (!empty($d['nazov']) && !empty($d['sumar']))
        : isset($d['skore']) && is_numeric($d['skore']));

    // Ked odpoved prisla, ale chyba v nej podstatny udaj, musi to byt vidiet
    // — inak by sa v logu objavilo len "chyba: ok", co nic nehovori.
    $chyba = $res['error'];
    if (!$ok && $chyba === null) {
        $chyba = $uloha === 'parse'
            ? 'Model nevratil nazov alebo sumar'
            : 'Model nevratil skore';
    }

    return [
        'ok'      => $ok,
        'data'    => $d,
        'status'  => $res['status'],
        'chyba'   => $chyba,
        'surova'  => $ok ? null : mb_substr((string)$res['content'], 0, 2000),
        'tokenov' => (int)($res['usage']['total_tokens'] ?? 0),
        'prompt_tokens'     => $res['usage']['prompt_tokens'] ?? null,
        'completion_tokens' => $res['usage']['completion_tokens'] ?? null,
        'cena'    => $cena,
        'ms'      => $res['took_ms'],
        'popis'   => $uloha === 'parse'
            ? ($d['nazov'] ?? null)
            : (isset($d['skore']) ? 'skore ' . $d['skore'] : null),
    ];
}


// ============================================================
// Zapis vysledku
// ============================================================
function test_zapis(PDO $pdo, int $behId, int $cislo, string $uloha,
                    array $model, array $v): void {
    $d = $v['data'];

    $txt  = static fn($x, $n) => (is_string($x) && trim($x) !== '')
        ? mb_substr(trim($x), 0, $n) : null;
    $cislo_ = static fn($x) => is_numeric($x) ? (float)$x : null;

    // Zoznam -> PostgreSQL TEXT[]. Polozky sa obaluju uvodzovkami, lebo
    // mozu obsahovat ciarku alebo medzeru.
    $pgPole = static function ($x): ?string {
        if (!is_array($x) || !$x) return null;
        $q = [];
        foreach ($x as $p) {
            if (is_string($p) && trim($p) !== '') {
                $q[] = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], mb_substr(trim($p), 0, 100)) . '"';
            }
        }
        return $q ? '{' . implode(',', $q) . '}' : null;
    };
    // Argumenty pre/proti su zoznam viet — do TEXT ako riadky, aby sa dali
    // zobrazit bez dalsieho parsovania.
    $riadky = static fn($x) => (is_array($x) && $x)
        ? mb_substr(implode("\n", array_filter($x, 'is_string')), 0, 2000) : null;

    $st = $pdo->prepare(
        'INSERT INTO job.test_vysledky
            (beh_id, inzerat, uloha, model_id, model_db_id, je_free, cena_1m,
             nazov, firma, datum_zverejnenia, datum_zverejnenia_text, sumar,
             html_original, html_sk, orig_lang,
             mzda_text, mzda_min, mzda_max, mzda_mena, mzda_obdobie,
             nastup, uvazok, uvazky, mesto, lokalita_zvysok,
             skore, zaradenie, hodnotenie, pre_argumenty, proti_argumenty,
             status, chyba, surova_odpoved,
             prompt_tokens, completion_tokens, total_tokens, cena_usd, trvanie_ms)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?::TEXT[],?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON CONFLICT (beh_id, inzerat, uloha, model_id) DO NOTHING');

    // Datum: model ho moze vratit v nezmyselnom tvare a cely zapis by zlyhal.
    $datum = null;
    if (!empty($d['datum_zverejnenia'])
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d['datum_zverejnenia'])) {
        $datum = $d['datum_zverejnenia'];
    }

    $skore = isset($d['skore']) && is_numeric($d['skore'])
        ? max(0, min(100, (int)$d['skore'])) : null;

    $st->execute([
        $behId, $cislo, $uloha, mb_substr($model['model_id'], 0, 150), $model['id'],
        ai_je_true($model['is_free']) ? 't' : 'f',
        ai_je_true($model['is_free']) ? 0
            : (float)$model['price_input_1m'] + (float)$model['price_output_1m'],

        $txt($d['nazov'] ?? null, 300), $txt($d['firma'] ?? null, 255),
        $datum, $txt($d['datum_zverejnenia_text'] ?? null, 100),
        $txt($d['sumar'] ?? null, 4000),
        $txt($d['html_original'] ?? null, 100000), $txt($d['html_sk'] ?? null, 100000),
        $txt($d['orig_lang'] ?? null, 5),

        $txt($d['mzda_text'] ?? null, 200), $cislo_($d['mzda_min'] ?? null),
        $cislo_($d['mzda_max'] ?? null), $txt($d['mzda_mena'] ?? null, 3),
        $txt($d['mzda_obdobie'] ?? null, 10),

        $txt($d['nastup'] ?? null, 100), $txt($d['uvazok'] ?? null, 30),
        $pgPole($d['uvazky'] ?? null),
        $txt($d['mesto'] ?? null, 120), $txt($d['lokalita_zvysok'] ?? null, 2000),

        $skore, $txt($d['zaradenie'] ?? null, 20), $txt($d['hodnotenie'] ?? null, 4000),
        $riadky($d['pre'] ?? null), $riadky($d['proti'] ?? null),

        // Ked odpoved prisla, ale chyba v nej podstatny udaj, status NESMIE
        // zostat 'ok' — taky riadok by v tabulke vyzeral ako uspesny, hoci
        // model nevratil to hlavne, na co sa testuje.
        $v['ok'] ? 'ok' : ($v['status'] === 'ok' ? 'neuplne' : $v['status']),
        $v['chyba'], $v['surova'],
        $v['prompt_tokens'], $v['completion_tokens'], $v['tokenov'],
        $v['cena'], $v['ms'],
    ]);
}
