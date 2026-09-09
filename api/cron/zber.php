<?php
// Vytazenie udajov z ulozenych inzeratov modelom — krok 2 zberu.
//
// Scraper (scraper/profesia.py) stiahne HTML a ulozi ho do job.offer_content.
// Tento skript nad nim pusti model a vyplni job.offers: mzdu, uvazky,
// profesiu, kluc. slova, technologie, odvetvie, suhrn a preklad.
//
// Oddelenie od stahovania je zamerne — tazenie sa da zopakovat lepsim
// promptom bez opatovneho stahovania z portalu.
//
// Spustenie:
//   php api/cron/zber.php              spracuje vsetky nevytazene
//   php api/cron/zber.php --limit=10   najviac 10 inzeratov
//   php api/cron/zber.php --offer=123  konkretny inzerat (aj znova)
//
// Z prehliadaca sa neda spustit — cron skripty nemaju byt verejne.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Len z prikazoveho riadka\n");
}

$BASE = dirname(__DIR__);
require_once $BASE . '/config/db.php';
require_once $BASE . '/helpers/db.php';

// json_error() je urcena pre HTTP odpovede; v CLI musi skoncit vynimkou,
// inak by sa chyba stratila v prazdnom vystupe.
function json_error(string $sprava, int $kod = 500) {
    throw new RuntimeException($sprava);
}
function json_ok($data = [], $kod = 200) { /* v CLI sa nepouziva */ }

require_once $BASE . '/config/openrouter.php';
require_once $BASE . '/helpers/ai_modely_fn.php';
require_once $BASE . '/helpers/openrouter_fn.php';

// ------------------------------------------------------------
// Parametre
// ------------------------------------------------------------
$limit   = 0;
$offerId = 0;
$znova   = false;
foreach ($argv as $a) {
    if (preg_match('/^--limit=(\d+)$/', $a, $m)) $limit   = (int)$m[1];
    if (preg_match('/^--offer=(\d+)$/', $a, $m)) $offerId = (int)$m[1];
    if ($a === '--znova') $znova = true;
}

$pdo = db();

// ------------------------------------------------------------
// Co treba vytazit
//
// Standardne inzeraty, ktore maju stiahnuty detail a este neboli uspesne
// vytazene. Kontroluje sa cez ai_evaluations, nie cez priznak v offers —
// tak sa zaroven vidi, kolko pokusov uz bolo.
// ------------------------------------------------------------
if ($offerId) {
    $st = $pdo->prepare(
        'SELECT o.id, o.external_id, o.url, o.source_id, c.text_full
           FROM job.offers o
           JOIN job.offer_content c ON c.offer_id = o.id AND c.is_original
          WHERE o.id = ?');
    $st->execute([$offerId]);
} else {
    // Standardne inzeraty bez uspesneho vytazenia. S --znova aj tie, ktorym
    // chyba suhrn napriek uspesnemu volaniu — vysledok sa do inzeratu
    // nezapisal (starsia chyba vo vyhodnoteni uspechu).
    $podmienka = $znova
        ? "(o.summary_sk IS NULL)"
        : "NOT EXISTS (SELECT 1 FROM job.ai_evaluations e
                        WHERE e.offer_id = o.id AND e.ucel = 'parse' AND e.status = 'ok')";

    $sql =
        "SELECT o.id, o.external_id, o.url, o.source_id, c.text_full
           FROM job.offers o
           JOIN job.offer_content c ON c.offer_id = o.id AND c.is_original
          WHERE o.detail_fetched_at IS NOT NULL
            AND $podmienka
          ORDER BY o.created_at";
    if ($limit > 0) $sql .= ' LIMIT ' . $limit;
    $st = $pdo->query($sql);
}

$inzeraty = $st->fetchAll();
if (!$inzeraty) {
    echo "Niet co vytazit.\n";
    exit(0);
}
printf("Na vytazenie: %d inzeratov\n", count($inzeraty));

// ------------------------------------------------------------
// Prompt a model
// ------------------------------------------------------------
$prompt = or_active_prompt('offer_parse');

$spracovanych = 0;
$chyb         = 0;
$cenaSpolu    = 0.0;
$tokenovSpolu = 0;

foreach ($inzeraty as $o) {
    // Model sa vybera pre KAZDY inzerat znova: po zlyhani sa poradie posunie
    // a dalsi inzerat uz ma bezat na nahradnom modeli.
    $vyber = ai_vyber_model('parse', (int)$o['source_id']);
    if ($vyber['vypnute'] || !$vyber['model']) {
        printf("ZASTAVENE: %s\n", $vyber['dovod']);
        break;
    }
    $model = $vyber['model']['model_id'];

    printf('  %s %s … ', $o['external_id'], str_pad(mb_substr($model, 0, 34), 34));

    $res = or_evaluate([
        'prompt'    => or_build_prompt($prompt['template'], '', '', (string)$o['text_full']),
        'offer_id'  => (int)$o['id'],
        'prompt_id' => $prompt['id'],
        'url'       => $o['url'],
        'ucel'      => 'parse',
        'source_id' => (int)$o['source_id'],
        'call_type' => 'live',
    ], $model);

    $cenaSpolu    += (float)($res['cost'] ?? 0);
    $tokenovSpolu += (int)($res['tokens'] ?? 0);

    // Zaznamenanie vysledku posunie poradie modelov a stroj rozpocet.
    ai_po_volani('parse', (int)$o['source_id'], $res['ok'],
                 $res['error'], (float)($res['cost'] ?? 0));
    ai_straz_rozpocet('parse', (int)$o['source_id']);

    if (!$res['ok']) {
        $chyb++;
        printf("CHYBA: %s\n", mb_substr((string)($res['error'] ?? $res['status']), 0, 60));
        continue;
    }

    uloz_vytazene($pdo, (int)$o['id'], $res['parsed']);
    $spracovanych++;
    printf("OK %s (%d tok., %.1f s)\n",
           mb_substr((string)($res['parsed']['profession'] ?? '?'), 0, 24),
           $res['tokens'] ?? 0, ($res['ms'] ?? 0) / 1000);
}

// Suhrn nakladov k behu, ktory inzeraty priniesol.
$pdo->prepare(
    "UPDATE job.scrape_runs r SET
        cost_usd     = s.cena,
        tokens_total = s.tokeny,
        parsed_count = s.pocet
     FROM (SELECT o.first_run_id AS run_id,
                  COALESCE(SUM(e.cost_usd), 0) AS cena,
                  COALESCE(SUM(e.total_tokens), 0) AS tokeny,
                  COUNT(*) FILTER (WHERE e.status = 'ok') AS pocet
             FROM job.ai_evaluations e
             JOIN job.offers o ON o.id = e.offer_id
            WHERE e.ucel = 'parse' AND o.first_run_id IS NOT NULL
            GROUP BY o.first_run_id) s
     WHERE r.id = s.run_id")->execute();

printf("\nHotovo: %d vytazenych, %d chyb, %d tokenov, $%.6f\n",
       $spracovanych, $chyb, $tokenovSpolu, $cenaSpolu);

// ============================================================
// Zapis vytazenych udajov do job.offers
//
// Nazov pozicie a firmy sa ZAMERNE neprepisuju — od promptu v4 ich model
// nevracia, su presne zo stranky od scrapera (modely ich komolili).
// ============================================================
function uloz_vytazene(PDO $pdo, int $offerId, ?array $d): void {
    if (!$d) return;

    $text = static fn($v, $max) => (is_string($v) && $v !== '')
        ? mb_substr($v, 0, $max) : null;
    $cislo = static fn($v) => is_numeric($v) ? (float)$v : null;

    // Zoznam -> PostgreSQL TEXT[]. Prazdny zoznam sa uklada ako NULL,
    // aby sa dalo odlisit "model nic nenasiel" od "este sa netazilo".
    $pole = static function ($v): ?array {
        if (!is_array($v) || !$v) return null;
        $ocistene = [];
        foreach ($v as $x) {
            if (is_string($x) && trim($x) !== '') $ocistene[] = mb_substr(trim($x), 0, 100);
        }
        return $ocistene ?: null;
    };

    $uvazky = $pole($d['employment_types'] ?? null);

    // Lokality: job.offer_locations vyzaduje location_id (NOT NULL, sucast
    // primarneho kluca), ale ciselnik lokalit este nie je naplneny (faza 2).
    // Docasne sa preto ukladaju do job.offers.locations_raw a znormalizuju sa,
    // az ked ciselnik vznikne.
    $lokality = $pole($d['locations'] ?? null);

    $st = $pdo->prepare(
        'UPDATE job.offers SET
            profession_raw    = COALESCE(?, profession_raw),
            industry          = COALESCE(?, industry),
            summary_sk        = COALESCE(?, summary_sk),
            orig_lang         = COALESCE(?, orig_lang),
            salary_raw        = COALESCE(?, salary_raw),
            salary_min        = COALESCE(?, salary_min),
            salary_max        = COALESCE(?, salary_max),
            salary_currency   = COALESCE(?, salary_currency),
            salary_period     = COALESCE(?, salary_period),
            employment_type   = COALESCE(?, employment_type),
            employment_types  = COALESCE(?::TEXT[], employment_types),
            contract_duration = COALESCE(?, contract_duration),
            education_level   = COALESCE(?, education_level),
            seniority         = COALESCE(?, seniority),
            remote_type       = COALESCE(?, remote_type),
            positions_count   = COALESCE(?, positions_count),
            start_date        = COALESCE(?, start_date),
            valid_until       = COALESCE(?::DATE, valid_until),
            is_agency_offer   = COALESCE(?, is_agency_offer),
            keywords          = COALESCE(?::TEXT[], keywords),
            technologies      = COALESCE(?::TEXT[], technologies),
            locations_raw     = COALESCE(?::TEXT[], locations_raw),
            updated_at        = NOW()
          WHERE id = ?');

    // PostgreSQL pole cez PDO: zapisuje sa v tvare {a,b}. Polozky sa
    // obaluju uvodzovkami, lebo mozu obsahovat ciarku alebo medzeru.
    $pgPole = static function (?array $v): ?string {
        if ($v === null) return null;
        $q = array_map(fn($x) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $x) . '"', $v);
        return '{' . implode(',', $q) . '}';
    };

    $agentura = isset($d['is_agency'])
        ? (in_array($d['is_agency'], [true, 'true', 1, '1'], true) ? 't' : 'f') : null;

    $st->execute([
        $text($d['profession'] ?? null, 200),
        $text($d['industry'] ?? null, 100),
        $text($d['summary_sk'] ?? null, 2000),
        $text($d['orig_lang'] ?? null, 5),
        $text($d['salary_raw'] ?? null, 200),
        $cislo($d['salary_min'] ?? null),
        $cislo($d['salary_max'] ?? null),
        $text($d['salary_currency'] ?? null, 3),
        $text($d['salary_period'] ?? null, 10),
        $text($d['employment_type'] ?? null, 30),
        $pgPole($uvazky),
        $text($d['contract_duration'] ?? null, 100),
        $text($d['education_level'] ?? null, 100),
        $text($d['seniority'] ?? null, 50),
        $text($d['remote_type'] ?? null, 20),
        isset($d['positions_count']) && is_numeric($d['positions_count'])
            ? (int)$d['positions_count'] : null,
        $text($d['start_date'] ?? null, 100),
        $text($d['valid_until'] ?? null, 10),
        $agentura,
        $pgPole($pole($d['keywords'] ?? null)),
        $pgPole($pole($d['technologies'] ?? null)),
        $pgPole($lokality),
        $offerId,
    ]);

    // Preklad do slovenciny — samostatny riadok, original sa nikdy neprepisuje.
    if (!empty($d['text_sk']) && is_string($d['text_sk'])) {
        $pdo->prepare(
            "INSERT INTO job.offer_content
                (offer_id, lang, is_original, text_full, translated_by, translated_at)
             VALUES (?, 'sk', FALSE, ?, 'model', NOW())
             ON CONFLICT (offer_id, lang) DO UPDATE SET
                text_full = EXCLUDED.text_full,
                translated_by = EXCLUDED.translated_by,
                translated_at = NOW()")
            ->execute([$offerId, $d['text_sk']]);
        $pdo->prepare('UPDATE job.offers SET translated_at = NOW() WHERE id = ?')
            ->execute([$offerId]);
    }

}
