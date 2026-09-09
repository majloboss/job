<?php
// Volanie modelov cez OpenRouter + posudzovanie vhodnosti inzeratu.
//
// Model je pouzity zamerne aj na parsovanie inzeratu — vie sa zorientovat aj
// ked portal zmeni strukturu stranky, na rozdiel od pevneho parsera.

// Vyber modelu a ceny — or_evaluate() rata naklady kazdeho volania.
require_once __DIR__ . '/ai_modely_fn.php';

const OR_MAX_INPUT_CHARS = 14000;   // vstup sa krati, free modely maju maly kontext

// ------------------------------------------------------------
// Ochrana pred zneuzitim endpointu na dopyty do vnutornej siete:
// stahovat sa da len z portalov, ktore mame v ciselniku job.sources.
// ------------------------------------------------------------
function or_check_url(string $url): void {
    $parts = parse_url($url);
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
        json_error('Adresa musí byť https:// s platným hostiteľom', 400);
    }
    $host = strtolower($parts['host']);

    $allowed = db()->query('SELECT base_url FROM job.sources WHERE is_active')->fetchAll();
    foreach ($allowed as $row) {
        $h = strtolower(parse_url($row['base_url'], PHP_URL_HOST) ?: '');
        if ($h === '') continue;
        // povol aj subdomeny a variant bez www.
        $bare = preg_replace('/^www\./', '', $h);
        if ($host === $h || $host === $bare || str_ends_with($host, '.' . $bare)) return;
    }
    json_error('Adresa nie je z povoleného pracovného portálu', 400);
}

// ------------------------------------------------------------
// Stiahne stranku inzeratu. Vracia komplet HTML (archiv) aj cisty text.
// ------------------------------------------------------------
function or_fetch_offer(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_USERAGENT      => 'JobBot/1.0 (+https://job.fellow.sk)',
        CURLOPT_HTTPHEADER     => ['Accept-Language: sk,cs,en;q=0.8'],
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($html === false) json_error('Stránku sa nepodarilo stiahnuť: ' . $err, 502);
    if ($code !== 200)   json_error("Stránka vrátila HTTP $code", 502);

    return [
        'html'  => $html,
        'text'  => or_html_to_text($html),
        'title' => or_extract_title($html),
    ];
}

// HTML -> cisty text: prec so skriptami, stylmi a navigaciou
function or_html_to_text(string $html): string {
    $s = preg_replace('#<(script|style|noscript|svg|iframe)\b[^>]*>.*?</\1>#is', ' ', $html);
    $s = preg_replace('#<(nav|footer|header)\b[^>]*>.*?</\1>#is', ' ', $s);
    $s = preg_replace('#<br\s*/?>|</(p|div|li|tr|h[1-6])>#i', "\n", $s);
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('/[ \t\x{00A0}]+/u', ' ', $s);
    $s = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $s);
    return trim($s);
}

function or_extract_title(string $html): ?string {
    if (preg_match('#<h1[^>]*>(.*?)</h1>#is', $html, $m)) {
        $t = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($t !== '') return mb_substr($t, 0, 300);
    }
    if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
        return mb_substr(trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 300);
    }
    return null;
}

// ------------------------------------------------------------
// Zoznam bezplatnych modelov na OpenRouteri.
// Ktore su zadarmo sa v case meni, preto sa zoznam tiahne naziво.
// ------------------------------------------------------------
function or_free_models(): array {
    $ch = curl_init('https://openrouter.ai/api/v1/models');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . OPENROUTER_KEY],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$resp) json_error("Zoznam modelov sa nepodarilo načítať (HTTP $code)", 502);

    $free = [];
    foreach (json_decode($resp, true)['data'] ?? [] as $m) {
        if (!str_ends_with($m['id'] ?? '', ':free')) continue;
        $free[] = [
            'id'      => $m['id'],
            'name'    => $m['name'] ?? $m['id'],
            'context' => $m['context_length'] ?? null,
        ];
    }
    usort($free, fn($a, $b) => ($b['context'] ?? 0) <=> ($a['context'] ?? 0));
    return $free;
}

// ------------------------------------------------------------
// Cennik vsetkych modelov na OpenRouteri.
//
// Vracia [model_id => ['vstup' => cena, 'vystup' => cena, 'nazov' => ...]],
// kde cena je v USD za JEDEN token (tak to OpenRouter uvadza). Na porovnanie
// s beznymi cennikmi sa nasobi milionom.
// ------------------------------------------------------------
function or_cennik(): array {
    $ch = curl_init('https://openrouter.ai/api/v1/models');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . OPENROUTER_KEY],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$resp) return [];

    $cennik = [];
    foreach (json_decode($resp, true)['data'] ?? [] as $m) {
        $p = $m['pricing'] ?? [];
        $cennik[$m['id']] = [
            'nazov'   => $m['name'] ?? $m['id'],
            'vstup'   => isset($p['prompt'])     ? (float)$p['prompt']     : null,
            'vystup'  => isset($p['completion']) ? (float)$p['completion'] : null,
            'context' => $m['context_length'] ?? null,
        ];
    }
    return $cennik;
}

// Zosynchronizuje ciselnik job.ai_models s aktualnym zoznamom z OpenRoutera.
function or_sync_models(array $free): void {
    $pdo = db();
    $pdo->exec('UPDATE job.ai_models SET is_free = FALSE');
    $st = $pdo->prepare(
        'INSERT INTO job.ai_models (model_id, name, is_free, context_length, updated_at)
         VALUES (?, ?, TRUE, ?, NOW())
         ON CONFLICT (model_id) DO UPDATE
            SET name = EXCLUDED.name, is_free = TRUE,
                context_length = EXCLUDED.context_length, updated_at = NOW()');
    foreach ($free as $m) $st->execute([$m['id'], $m['name'], $m['context']]);
}

// ------------------------------------------------------------
// Zostavi prompt z aktivnej sablony.
// ------------------------------------------------------------
function or_active_prompt(string $code = 'offer_eval'): array {
    $st = db()->prepare('SELECT * FROM job.ai_prompts WHERE code = ? AND is_active LIMIT 1');
    $st->execute([$code]);
    $row = $st->fetch();
    if (!$row) json_error("Chýba aktívny prompt '$code' v job.ai_prompts", 500);
    return $row;
}

function or_build_prompt(string $template, string $prefsText, string $cvText, string $offerText): string {
    return strtr($template, [
        '{prefs_text}' => $prefsText !== '' ? $prefsText : '(používateľ zatiaľ nezadal preferencie)',
        '{cv_text}'    => $cvText    !== '' ? mb_substr($cvText, 0, 6000) : '(životopis nie je nahraný)',
        '{offer_text}' => mb_substr($offerText, 0, OR_MAX_INPUT_CHARS),
    ]);
}

// ------------------------------------------------------------
// Zavola model a vrati rozparsovanu odpoved.
// ------------------------------------------------------------
function or_call_model(string $prompt, string $model, int $maxTokens = 1200): array {
    $payload = json_encode([
        'model'       => $model,
        'messages'    => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0,
        'max_tokens'  => $maxTokens,
    ], JSON_UNESCAPED_UNICODE);

    $started = microtime(true);
    $ch = curl_init(defined('OPENROUTER_URL') ? OPENROUTER_URL : 'https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . OPENROUTER_KEY,
            'Content-Type: application/json',
            'HTTP-Referer: ' . (defined('APP_URL') ? APP_URL : 'https://job.fellow.sk'),
            'X-Title: JOB - posudenie inzeratu',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $took = (int)round((microtime(true) - $started) * 1000);

    $out = ['data' => null, 'content' => '', 'usage' => null,
            'error' => null, 'status' => 'ok', 'took_ms' => $took];

    if ($resp === false) {
        $out['error']  = 'Spojenie zlyhalo: ' . $err;
        $out['status'] = 'failed';
        return $out;
    }

    $ai = json_decode($resp, true);
    if ($code !== 200) {
        $out['error']  = $ai['error']['message'] ?? ('HTTP ' . $code);
        $out['status'] = $code === 429 ? 'rate_limited' : 'failed';
        return $out;
    }

    $content = $ai['choices'][0]['message']['content'] ?? '';
    $out['content'] = $content;
    $out['usage']   = $ai['usage'] ?? null;

    if (($ai['choices'][0]['finish_reason'] ?? null) === 'length') {
        // Reasoning modely ratuju do max_tokens aj vnutorne uvazovanie.
        // Ked minu cely strop na uvazovanie, obsah pride prazdny — to nie je
        // "orezana odpoved", ale model, ktory sa k odpovedi vobec nedostal.
        // Rozlisenie je podstatne: prve sa riesi kratsim vstupom, druhe
        // vyssim stropom alebo inym modelom.
        $uvazoval = ($ai['choices'][0]['message']['reasoning'] ?? '') !== '';
        $out['error']  = ($content === '' && $uvazoval)
            ? 'Model minul celý limit na uvažovanie a nestihol odpovedať'
            : 'Odpoveď modelu bola orezaná (max_tokens)';
        $out['status'] = 'truncated';
        return $out;
    }

    // Model niekedy obali JSON do ```json ... ``` alebo pripoji komentar.
    $json = $content;
    if (preg_match('/\{.*\}/s', $json, $m)) $json = $m[0];
    $data = json_decode($json, true);

    if (!is_array($data)) {
        $out['error']  = 'Odpoveď nie je platný JSON';
        $out['status'] = 'invalid_json';
        return $out;
    }
    $out['data'] = $data;
    return $out;
}

// ------------------------------------------------------------
// Posudi jeden inzerat jednym modelom a ulozi vysledok do ai_evaluations.
// ------------------------------------------------------------
function or_evaluate(array $ctx, string $model): array {
    // Pri tazani udajov ('parse') moze byt odpoved dlha — obsahuje aj preklad
    // celeho inzeratu. 1200 tokenov by ju orezalo hned pri prvom dlhsom texte.
    //
    // 12000 nie je preklep: REASONING MODELY (nex-n2.5-pro, dots-3-note)
    // ratuju do max_tokens aj vnutorne uvazovanie. Pri strope 4000 minuli
    // vsetko na uvazovanie a vratili finish_reason 'length' s prazdnym
    // obsahom — vyzeralo to ako chyba promptu, pritom siel o limit.
    $ucel = $ctx['ucel'] ?? 'eval';
    $res = or_call_model($ctx['prompt'], $model, $ucel === 'parse' ? 12000 : 4000);
    $d   = is_array($res['data']) ? $res['data'] : [];

    $num = static fn($v) => is_numeric($v) ? (int)$v : null;
    $arr = static fn($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : null;

    $score  = $num($d['score'] ?? null);
    if ($score !== null) $score = max(0, min(100, $score));
    $bucket = $d['bucket'] ?? null;
    if (!in_array($bucket, ['vhodne', 'menej_vhodne', 'nevhodne'], true)) {
        // dopocitaj z hranic v prompte, ked model bucket vynechal alebo zmylil
        $bucket = $score === null ? null : ($score <= 25 ? 'nevhodne' : ($score <= 59 ? 'menej_vhodne' : 'vhodne'));
    }

    // Pri 'parse' je vysledkom cela odpoved (vytazene udaje), nie podobjekt
    // 'parsed' ako pri posudzovani vhodnosti.
    $parsed  = $ucel === 'parse' ? ($d ?: null) : ($d['parsed'] ?? null);
    $summary = $ucel === 'parse' ? ($d['summary_sk'] ?? null) : ($d['summary'] ?? null);

    // Cena volania podla cenníka v case volania — spatny prepocet zo
    // sucasneho cennika by skresloval historiu.
    $cena = null;
    if ($res['usage']) {
        $cm = db()->prepare('SELECT price_input_1m, price_output_1m FROM job.ai_models
                              WHERE model_id = ?');
        $cm->execute([$model]);
        $cena = ai_cena_volania($cm->fetch() ?: null,
                                $res['usage']['prompt_tokens'] ?? null,
                                $res['usage']['completion_tokens'] ?? null);
    }

    $st = db()->prepare(
        'INSERT INTO job.ai_evaluations
            (offer_id, user_id, lab_run_id, model_id, prompt_id, source_url,
             ucel, source_id, call_type,
             score, bucket, summary, pros, cons, missing_skills, parsed,
             status, error, raw_response,
             prompt_tokens, completion_tokens, total_tokens, cost_usd, took_ms)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
         RETURNING id');
    $st->execute([
        $ctx['offer_id'] ?? null, $ctx['user_id'] ?? null, $ctx['lab_run_id'] ?? null,
        mb_substr($model, 0, 150), $ctx['prompt_id'] ?? null,
        isset($ctx['url']) ? mb_substr($ctx['url'], 0, 500) : null,
        $ucel, $ctx['source_id'] ?? null, $ctx['call_type'] ?? 'live',
        $score, $bucket,
        is_string($summary) ? mb_substr($summary, 0, 1000) : null,
        $arr($d['pros'] ?? null), $arr($d['cons'] ?? null), $arr($d['missing_skills'] ?? null),
        $arr($parsed),
        $res['status'], $res['error'],
        $res['error'] !== null ? mb_substr($res['content'], 0, 2000) : null,
        $res['usage']['prompt_tokens'] ?? null,
        $res['usage']['completion_tokens'] ?? null,
        $res['usage']['total_tokens'] ?? null,
        $cena,
        $res['took_ms'],
    ]);

    // Modely, ktore nikdy nebudu fungovat, sa vyradia zo zoznamu — aby
    // nezdrzovali kazdy dalsi beh. Tyka sa to len trvalych prekazok, nie
    // docasnych (limit poziadaviek, vypadok siete).
    //
    // Vyraduje sa cez ai_vyrad_model(), ktora nastavi aj unavailable_reason.
    // Samotny last_error by nestacil: vyber modelu sa riadi prave tym
    // stlpcom, takze model by sa napriek "vyradeniu" dalej ponukal.
    $trvale = ai_trvala_chyba($res['error']);
    if ($trvale !== null) ai_vyrad_model($model, $trvale);

    // Uspech znamena pri kazdom ucele nieco ine: pri posudzovani vhodnosti
    // musi prist skore, pri tazani udajov suhrn a profesia. Model, ktory
    // vrati prazdny JSON, "odpovedal" — pouzitelny vsak nie je.
    //
    // Nazov pozicie sa uz neposudzuje: od promptu v4 ho model nevracia,
    // berie ho scraper priamo z HTML (modely ho komolili).
    $ok = $res['status'] === 'ok'
        && ($ucel === 'parse'
            ? (!empty($d['summary_sk']) && !empty($d['profession']))
            : $score !== null);

    return [
        'id'      => (int)$st->fetchColumn(),
        'model'   => $model,
        'ok'      => $ok,
        'status'  => $res['status'],
        'error'   => $res['error'],
        'vyradeny' => $trvale !== null,
        'score'   => $score,
        'bucket'  => $bucket,
        'summary' => $summary,
        'pros'    => $d['pros'] ?? null,
        'cons'    => $d['cons'] ?? null,
        'missing_skills' => $d['missing_skills'] ?? null,
        'parsed'  => $parsed,
        'tokens'  => $res['usage']['total_tokens'] ?? null,
        'cost'    => $cena,
        'ms'      => $res['took_ms'],
    ];
}

// Je chyba trvala, teda nema zmysel model skusat znova?
//
// Rozhodovanie sa presunulo do ai_trvala_chyba() v ai_modely_fn.php, aby
// existovalo na jednom mieste — dve kopie by sa casom rozisli a model by
// sa podla jednej vyradil a podla druhej nie. Tato funkcia zostava len
// ako nazov, na ktory sa odkazuje starsi kod.
function or_trvalo_nedostupny(?string $chyba): ?string {
    return ai_trvala_chyba($chyba);
}

// Statistiku modelu prepocitava ai_prepocitaj_statistiku() v
// ai_modely_fn.php — okrem uspesnosti rata aj zhodu s ostatnymi modelmi,
// co je pri tazani udajov hlavne kriterium vyberu.
