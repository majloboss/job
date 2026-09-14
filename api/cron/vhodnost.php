<?php
// Vyhodnotenie vhodnosti ponuk pre pouzivatela — DRUHY krok aplikacie.
//
// Zber (stiahnutie + vytazenie udajov) je krok prvy a konci tym, ze inzerat
// je v DB rozparsovany. Tento skript nad nim pusti model s CV a preferenciami
// a zapise skore s odovodnenim do job.user_offer_match.
//
// Delenie je zamerne a nie je to iba poriadok v kode:
//   - zber bezi RAZ za inzerat a je spolocny pre vsetkych,
//   - vhodnost bezi za KAZDEHO pouzivatela zvlast, takze naklady rastu
//     s poctom ludi, nie s poctom inzeratov.
// Preto maju vlastny ucel v ciselniku modelov ('eval') aj vlastny rozpocet.
//
// Spustenie:
//   php api/cron/vhodnost.php                 vsetci pouzivatelia, nove ponuky
//   php api/cron/vhodnost.php --limit=20      najviac 20 ponuk na pouzivatela
//   php api/cron/vhodnost.php --user=3        len jeden pouzivatel
//   php api/cron/vhodnost.php --offer=123     jedna ponuka (aj znova)
//   php api/cron/vhodnost.php --znova         prepocitaj aj uz posudene
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
$userId  = 0;
$offerId = 0;
$znova   = false;
foreach ($argv as $a) {
    if (preg_match('/^--limit=(\d+)$/', $a, $m)) $limit   = (int)$m[1];
    if (preg_match('/^--user=(\d+)$/', $a, $m))  $userId  = (int)$m[1];
    if (preg_match('/^--offer=(\d+)$/', $a, $m)) $offerId = (int)$m[1];
    if ($a === '--znova') $znova = true;
}

$pdo = db();

// ------------------------------------------------------------
// Pouzivatelia na spracovanie
//
// Posudzuje sa len tomu, kto ma co posudzovat podla coho: bez preferencii
// aj bez zivotopisu by model hadal a kazde volanie by stalo peniaze.
// ------------------------------------------------------------
$sql =
    "SELECT u.id, u.username,
            COALESCE(p.free_text, '') AS prefs_text,
            COALESCE((SELECT d.extracted_text
                        FROM job.user_documents d
                       WHERE d.user_id = u.id AND d.doc_type = 'cv' AND d.use_for_ai
                       ORDER BY d.is_primary DESC, d.created_at DESC
                       LIMIT 1), '') AS cv_text
       FROM admin.users u
       LEFT JOIN job.user_preferences p ON p.user_id = u.id
      WHERE u.is_active";
$args = [];
if ($userId) {
    $sql .= ' AND u.id = ?';
    $args[] = $userId;
}
$st = $pdo->prepare($sql . ' ORDER BY u.id');
$st->execute($args);
$pouzivatelia = $st->fetchAll();

$pouzivatelia = array_values(array_filter($pouzivatelia, static function (array $u): bool {
    return trim($u['prefs_text']) !== '' || trim($u['cv_text']) !== '';
}));

if (!$pouzivatelia) {
    exit("Ziadny pouzivatel s preferenciami ani zivotopisom — nie je co posudzovat.\n");
}

$prompt = or_active_prompt('offer_eval');

$posudenych = 0;
$chyb       = 0;
$cenaSpolu  = 0.0;
$tokenovSpolu = 0;

foreach ($pouzivatelia as $u) {
    // Ponuky, ktore este nemaju posudok. Uzavrete sa preskakuju rovnako ako
    // pri tazeni — volanie stoji peniaze a prihlasit sa uz neda.
    if ($offerId) {
        $st = $pdo->prepare(
            'SELECT o.id, o.external_id, o.title, o.source_id, c.text_full
               FROM job.offers o
               JOIN job.offer_content c ON c.offer_id = o.id AND c.is_original
              WHERE o.id = ?');
        $st->execute([$offerId]);
    } else {
        $kde = $znova ? '' :
            ' AND NOT EXISTS (SELECT 1 FROM job.user_offer_match m
                               WHERE m.offer_id = o.id AND m.user_id = ?)';
        $sql =
            "SELECT o.id, o.external_id, o.title, o.source_id, c.text_full
               FROM job.offers o
               JOIN job.offer_content c ON c.offer_id = o.id AND c.is_original
              WHERE o.is_active AND c.text_full IS NOT NULL$kde
              ORDER BY COALESCE(o.published_at, o.created_at) DESC";
        if ($limit > 0) $sql .= ' LIMIT ' . $limit;
        $st = $pdo->prepare($sql);
        $st->execute($znova ? [] : [(int)$u['id']]);
    }
    $ponuky = $st->fetchAll();

    printf("\n%s (#%d): %d ponuk na posudenie\n",
           $u['username'], $u['id'], count($ponuky));

    foreach ($ponuky as $o) {
        // Model sa vybera pre KAZDU ponuku znova: po zlyhani sa poradie
        // posunie a dalsia uz bezi na nahradnom modeli.
        $vyber = ai_vyber_model('eval', (int)$o['source_id']);
        if ($vyber['vypnute'] || !$vyber['model']) {
            printf("ZASTAVENE: %s\n", $vyber['dovod']);
            break 2;
        }
        $model = $vyber['model']['model_id'];

        printf('  %-28s %s … ',
               mb_substr((string)$o['title'], 0, 28),
               str_pad(mb_substr($model, 0, 30), 30));

        $res = or_evaluate([
            'prompt'    => or_build_prompt($prompt['template'], $u['prefs_text'],
                                           $u['cv_text'], (string)$o['text_full']),
            'offer_id'  => (int)$o['id'],
            'user_id'   => (int)$u['id'],
            'prompt_id' => $prompt['id'],
            'ucel'      => 'eval',
            'source_id' => (int)$o['source_id'],
            'call_type' => 'live',
        ], $model);

        $cenaSpolu    += (float)($res['cost'] ?? 0);
        $tokenovSpolu += (int)($res['tokens'] ?? 0);

        ai_po_volani('eval', (int)$o['source_id'], $res['ok'],
                     $res['error'], (float)($res['cost'] ?? 0));
        ai_straz_rozpocet('eval', (int)$o['source_id']);

        if (!$res['ok']) {
            $chyb++;
            printf("CHYBA: %s\n", mb_substr((string)($res['error'] ?? $res['status']), 0, 60));
            continue;
        }

        uloz_vhodnost($pdo, (int)$u['id'], (int)$o['id'], $res,
                      $model, (string)$prompt['version']);
        $posudenych++;
        printf("OK %d/100 %s (%d tok., %.1f s)\n",
               (int)($res['score'] ?? 0), (string)($res['bucket'] ?? '?'),
               $res['tokens'] ?? 0, ($res['ms'] ?? 0) / 1000);
    }
}

printf("\nHotovo: %d posudenych, %d chyb, %d tokenov, $%.6f\n",
       $posudenych, $chyb, $tokenovSpolu, $cenaSpolu);

// ============================================================
// Zapis posudku
// ============================================================
function uloz_vhodnost(PDO $pdo, int $userId, int $offerId, array $res,
                       string $model, string $verzia): void {
    $score = max(0, min(100, (int)($res['score'] ?? 0)));

    // Bucket od modelu sa OVERUJE proti skore: ked si model protireci
    // (napr. score 80 a "nevhodne"), plati skore — hranice su v prompte
    // a inak by sa filter na obrazovke nedal na bucket spolahnut.
    $podlaSkore = $score >= 60 ? 'vhodne' : ($score >= 26 ? 'menej_vhodne' : 'nevhodne');
    $bucket = (string)($res['bucket'] ?? '');
    if (!in_array($bucket, ['vhodne', 'menej_vhodne', 'nevhodne'], true)
        || $bucket !== $podlaSkore) {
        $bucket = $podlaSkore;
    }

    // Duvody sa drzia ako JSON: su to tri zoznamy (pre, proti, chybajuce
    // zrucnosti) a kazdy z nich sa na obrazovke zobrazuje zvlast.
    $reasons = json_encode([
        'pros'           => array_values((array)($res['pros'] ?? [])),
        'cons'           => array_values((array)($res['cons'] ?? [])),
        'missing_skills' => array_values((array)($res['missing_skills'] ?? [])),
    ], JSON_UNESCAPED_UNICODE);

    $st = $pdo->prepare(
        'INSERT INTO job.user_offer_match
            (user_id, offer_id, score, bucket, summary, reasons,
             scored_by, scorer_version, computed_at, ai_evaluation_id, model_id)
         VALUES (?, ?, ?, ?, ?, ?, \'ai\', ?, NOW(), ?, ?)
         ON CONFLICT (user_id, offer_id) DO UPDATE SET
            score = EXCLUDED.score, bucket = EXCLUDED.bucket,
            summary = EXCLUDED.summary, reasons = EXCLUDED.reasons,
            scored_by = EXCLUDED.scored_by, scorer_version = EXCLUDED.scorer_version,
            computed_at = NOW(), ai_evaluation_id = EXCLUDED.ai_evaluation_id,
            model_id = EXCLUDED.model_id');
    $st->execute([
        $userId, $offerId, $score, $bucket,
        mb_substr((string)($res['summary'] ?? ''), 0, 500),
        $reasons,
        'offer_eval/v' . $verzia,
        $res['id'] ?: null,
        $model,
    ]);
}
