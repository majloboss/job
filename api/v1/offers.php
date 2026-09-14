<?php
// GET /v1/offers — zoznam inzeratov s filtrami, radenim a strankovanim
// GET /v1/offers?id=123 — detail jedneho inzeratu vratane textu
//
// Ten isty endpoint sluzi pouzivatelovi aj adminovi. Rozdiel je len v tom,
// co sa vrati navyse: admin vidi udaje o zbere (ktory model inzerat vytazil,
// kolko to stalo), pouzivatel nie.
$auth    = require_auth();
$jeAdmin = ($auth['role'] ?? '') === 'admin';

if ($method !== 'GET') json_error('Method not allowed', 405);

$pdo = db();

// ------------------------------------------------------------
// Detail jedneho inzeratu
// ------------------------------------------------------------
if (!empty($_GET['id'])) {
    $st = $pdo->prepare(
        'SELECT o.*, s.name AS source_name, s.code AS source_code,
                c.name AS company_name, c.is_agency
           FROM job.offers o
           LEFT JOIN job.sources   s ON s.id = o.source_id
           LEFT JOIN job.companies c ON c.id = o.company_id
          WHERE o.id = ?');
    $st->execute([(int)$_GET['id']]);
    $offer = $st->fetch();
    if (!$offer) json_error('Inzerát sa nenašiel', 404);

    // Originalny text a preklad. Original sa nikdy neprepisuje — preklad je
    // samostatny riadok, takze sa da kedykolvek pregenerovat lepsim modelom.
    $st = $pdo->prepare(
        'SELECT lang, is_original, text_full, html_full, description, requirements,
                benefits, company_info, translated_by, translated_at
           FROM job.offer_content WHERE offer_id = ? ORDER BY is_original DESC');
    $st->execute([(int)$offer['id']]);

    $obsah = ['original' => null, 'preklad' => null];
    foreach ($st->fetchAll() as $c) {
        $kluc = ai_je_true_offers($c['is_original']) ? 'original' : 'preklad';
        $obsah[$kluc] = [
            'lang'          => $c['lang'],
            'text'          => $c['text_full'],
            'html'          => $c['html_full'],
            'description'   => $c['description'],
            'requirements'  => $c['requirements'],
            'benefits'      => $c['benefits'],
            'company_info'  => $c['company_info'],
            'translated_by' => $c['translated_by'],
            'translated_at' => $c['translated_at'],
        ];
    }

    // Miesta vykonu prace
    $st = $pdo->prepare(
        'SELECT l.name, l.region, l.lat, l.lon
           FROM job.offer_locations ol
           JOIN job.locations l ON l.id = ol.location_id
          WHERE ol.offer_id = ?');
    $st->execute([(int)$offer['id']]);
    $offer['locations'] = $st->fetchAll();

    $offer['keywords']         = pg_pole($offer['keywords']);
    $offer['technologies']     = pg_pole($offer['technologies']);
    $offer['employment_types'] = pg_pole($offer['employment_types']);
    $offer['locations_raw']    = pg_pole($offer['locations_raw']);
    $offer['is_active']        = ai_je_true_offers($offer['is_active']);

    // HTML zo zdroja ide priamo do stranky, takze sa musi ocistit — inzerat
    // moze obsahovat <script>, sledovacie pixely aj styly, ktore by rozbili
    // vzhlad. Ponechaju sa len znacky, z ktorych sa da poskladat citatelny
    // text; vysledok je tak rovnaky bez ohladu na portal.
    foreach (['original', 'preklad'] as $kluc) {
        if ($obsah[$kluc] !== null) {
            $obsah[$kluc]['html'] = offers_ocisti_html($obsah[$kluc]['html']);
        }
    }

    // Adminovi navyse: cim a za kolko sa inzerat vytazil.
    if ($jeAdmin) {
        $st = $pdo->prepare(
            "SELECT model_id, status, error, total_tokens, cost_usd, took_ms, created_at
               FROM job.ai_evaluations
              WHERE offer_id = ? AND ucel = 'parse'
              ORDER BY created_at DESC LIMIT 5");
        $st->execute([(int)$offer['id']]);
        $offer['zber'] = $st->fetchAll();
    }

    json_ok(['offer' => $offer, 'obsah' => $obsah]);
}

// ------------------------------------------------------------
// Zoznam — filtre
//
// Kazdy filter je nepovinny. Skladaju sa cez AND: pouzivatel si zuzuje
// vyber, nie rozsiruje.
// ------------------------------------------------------------
$kde  = ['o.is_active'];
$args = [];

// Fulltext cez nazov, firmu, sumar a kluc. slova. Hlada sa aj v prelozenom
// nazve — inzerat v cestine sa ma dat najst slovenskym slovom.
if (($q = trim((string)($_GET['q'] ?? ''))) !== '') {
    $kde[] = '(o.title ILIKE ? OR o.title_sk ILIKE ? OR o.company_name_raw ILIKE ?
               OR o.summary_sk ILIKE ?
               OR EXISTS (SELECT 1 FROM unnest(o.keywords) k WHERE k ILIKE ?)
               OR EXISTS (SELECT 1 FROM unnest(o.technologies) t WHERE t ILIKE ?))';
    $vzor = '%' . $q . '%';
    array_push($args, $vzor, $vzor, $vzor, $vzor, $vzor, $vzor);
}

if (!empty($_GET['source_id'])) {
    $kde[] = 'o.source_id = ?';
    $args[] = (int)$_GET['source_id'];
}

if (!empty($_GET['industry'])) {
    $kde[] = 'o.industry = ?';
    $args[] = $_GET['industry'];
}

// Hlada sa vo VSETKYCH ponukanych uvazkoch, nie len v hlavnom: inzerat
// casto ponuka "plny uvazok, na dohodu" a filter na dohodu ho musi najst
// aj vtedy, ked je dohoda az druha v poradi.
if (!empty($_GET['employment_type'])) {
    $kde[] = '(o.employment_types @> ARRAY[?]::TEXT[] OR o.employment_type = ?)';
    $args[] = $_GET['employment_type'];
    $args[] = $_GET['employment_type'];
}

if (!empty($_GET['remote_type'])) {
    $kde[] = 'o.remote_type = ?';
    $args[] = $_GET['remote_type'];
}

// Mzda: inzerat bez uvedenej mzdy sa pri filtrovani NEVYRADI, ak si to
// pouzivatel vyslovne nezela — inak by prisiel o polovicu ponuk.
if (!empty($_GET['salary_min'])) {
    $bezMzdy = !empty($_GET['aj_bez_mzdy']);
    $kde[] = $bezMzdy
        ? '(o.salary_min >= ? OR o.salary_min IS NULL)'
        : 'o.salary_min >= ?';
    $args[] = (float)$_GET['salary_min'];
}

// Agentury sa daju vypnut — casty poziadavok, agenturne inzeraty sa opakuju.
if (isset($_GET['bez_agentur']) && $_GET['bez_agentur'] === '1') {
    $kde[] = '(o.is_agency_offer IS NOT TRUE)';
}

// Len ponuky zverejnene za poslednych N dni.
if (!empty($_GET['dni'])) {
    $kde[] = 'COALESCE(o.published_at, o.created_at) >= NOW() - (? || \' days\')::INTERVAL';
    $args[] = (int)$_GET['dni'];
}

// Technologia — presna zhoda v poli, vyuzije GIN index.
if (!empty($_GET['technologia'])) {
    $kde[] = 'o.technologies @> ARRAY[?]::TEXT[]';
    $args[] = $_GET['technologia'];
}

// ------------------------------------------------------------
// Radenie
//
// Stlpec sa berie z bieleho zoznamu — nazov stlpca sa do SQL vklada
// priamo (nedaju sa naň naviazat parametre) a cokolvek od pouzivatela
// by tu bolo zranitelnostou.
// ------------------------------------------------------------
$STLPCE = [
    'published_at' => 'COALESCE(o.published_at, o.created_at)',
    'created_at'   => 'o.created_at',
    'title'        => 'COALESCE(o.title_sk, o.title)',
    'company'      => 'o.company_name_raw',
    'salary'       => 'o.salary_min',
    'industry'     => 'o.industry',
    'source'       => 's.name',
    'score'        => 'm.score',
    'lokalita'     => 'o.locations_raw[1]',
    'uvazok'       => 'o.employment_type',
    'remote'       => 'o.remote_type',
];

$radit = $_GET['radit'] ?? 'published_at';
if (!isset($STLPCE[$radit])) $radit = 'published_at';
$smer = strtolower($_GET['smer'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

// Prazdne hodnoty vzdy dolu: ponuka bez uvedenej mzdy nema byt hore len
// preto, ze sa radi vzostupne.
$orderBy = $STLPCE[$radit] . ' ' . $smer . ' NULLS LAST, o.id DESC';

// ------------------------------------------------------------
// Strankovanie
// ------------------------------------------------------------
$limit  = min(200, max(10, (int)($_GET['limit'] ?? 50)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

$where = implode(' AND ', $kde);

// Vhodnost sa pripaja LEFT JOINom — inzerat sa ma zobrazit aj vtedy,
// ked pre neho posudok este nebezal.
$sql = "SELECT o.id, o.external_id, o.url, o.title, o.title_sk, o.summary_sk,
               o.company_name_raw, o.is_agency_offer, o.industry,
               o.salary_raw, o.salary_min, o.salary_max, o.salary_currency,
               o.salary_period, o.employment_type, o.employment_types, o.remote_type, o.seniority,
               o.keywords, o.technologies, o.locations_raw, o.orig_lang,
               o.is_active, o.closed_reason,
               o.published_at, o.published_at_raw, o.created_at, o.last_seen_at,
               s.name AS source_name, s.code AS source_code,
               m.score, m.bucket, m.summary AS match_summary, m.distance_km
          FROM job.offers o
          LEFT JOIN job.sources s ON s.id = o.source_id
          LEFT JOIN job.user_offer_match m ON m.offer_id = o.id AND m.user_id = ?
         WHERE $where
         ORDER BY $orderBy
         LIMIT $limit OFFSET $offset";

$st = $pdo->prepare($sql);
$st->execute(array_merge([$auth['user_id']], $args));
$offers = $st->fetchAll();

foreach ($offers as &$o) {
    $o['keywords']         = pg_pole($o['keywords']);
    $o['employment_types'] = pg_pole($o['employment_types']);
    $o['technologies']     = pg_pole($o['technologies']);
    $o['locations_raw']    = pg_pole($o['locations_raw']);
    $o['is_active']        = ai_je_true_offers($o['is_active']);
    $o['is_agency_offer'] = $o['is_agency_offer'] === null
                          ? null : ai_je_true_offers($o['is_agency_offer']);
    $o['score']           = $o['score'] !== null ? (int)$o['score'] : null;
}
unset($o);

// Celkovy pocet pre strankovanie. Pocita sa zvlast, aby sa nemusel tahat
// s kazdym riadkom.
$stc = $pdo->prepare("SELECT COUNT(*) FROM job.offers o
                        LEFT JOIN job.sources s ON s.id = o.source_id
                       WHERE $where");
$stc->execute($args);
$spolu = (int)$stc->fetchColumn();

// Hodnoty do rozbalovacich filtrov — len tie, ktore sa v datach naozaj
// vyskytuju, aby sa neponukalo odvetvie s nula ponukami.
$ciselniky = [
    'portaly' => $pdo->query(
        'SELECT s.id, s.name, COUNT(o.id) AS pocet
           FROM job.sources s LEFT JOIN job.offers o ON o.source_id = s.id AND o.is_active
          GROUP BY s.id, s.name HAVING COUNT(o.id) > 0 ORDER BY s.name')->fetchAll(),
    'odvetvia' => $pdo->query(
        'SELECT industry, COUNT(*) AS pocet FROM job.offers
          WHERE is_active AND industry IS NOT NULL
          GROUP BY industry ORDER BY COUNT(*) DESC, industry')->fetchAll(),
    'uvazky' => $pdo->query(
        'SELECT employment_type, COUNT(*) AS pocet FROM job.offers
          WHERE is_active AND employment_type IS NOT NULL
          GROUP BY employment_type ORDER BY COUNT(*) DESC')->fetchAll(),
];

json_ok([
    'offers'    => $offers,
    'spolu'     => $spolu,
    'limit'     => $limit,
    'offset'    => $offset,
    'radit'     => $radit,
    'smer'      => strtolower($smer),
    'ciselniky' => $ciselniky,
    'je_admin'  => $jeAdmin,
]);

// ------------------------------------------------------------
// Ocisti HTML inzeratu pred zobrazenim.
//
// Kazdy portal ma vlastnu strukturu a vlastny balast — skripty, sledovacie
// pixely, triedy, styly. Po ocisteni zostane len obsah v beznych znackach,
// takze detail vyzera rovnako bez ohladu na to, odkial inzerat je.
//
// Zaroven je to bezpecnostna vec: obsah pochadza z cudzej stranky a ide
// priamo do DOM.
// ------------------------------------------------------------
function offers_ocisti_html(?string $html): ?string {
    if ($html === null || trim($html) === '') return null;

    // Najprv sa orezava na hlavny obsah stranky. Odstranit menu a paticku
    // podla znaciek nestaci — portaly ich stavaju z obycajnych <div>. Ked
    // ma stranka <main> alebo <article>, je to najspolahlivejsia hranica
    // medzi inzeratom a okolim portalu.
    if (preg_match('#<(main|article)\b[^>]*>(.*)</\1>#is', $html, $m)) {
        $html = $m[2];
    }

    // Prilohy pod inzeratom: "Podobne pozicie", "Dalsie ponuky" a pod. Je to
    // zoznam CUDZICH inzeratov, takze v detaile nema co robit — na ariva.sk
    // takto na koniec pribudlo dalsich 20 ponuk aj s platmi.
    //
    // Rezu sa podla NADPISU, nie podla obalujuceho prvku: ten je na ariva.sk
    // obycajna <section class="section"> bez rozlisenia, rovnaka ako sekcia
    // so samotnym inzeratom.
    $html = preg_replace(
        '#<h[1-4]\b[^>]*>(?:(?!</h[1-4]>).)*?'
      . '(?:podobn\p{L}*\s+(?:pozíci|ponuk)|ďal[šs]\p{L}*\s+(?:pozíci|ponuk|inzer)'
      . '|súvisiac\p{L}*\s+(?:pozíci|ponuk)|similar\s+jobs|related\s+jobs)'
      . '.*$#isu',
        '', $html);

    // Ovladacie prvky portalu na konci inzeratu: zdielanie na socialne siete
    // a vyzva na prihlasenie. Po ocisteni by zostali ako holy text
    // ("Zdielat na Twitter", "Mam zaujem") a citali by sa ako sucast ponuky.
    //
    // Reze sa podla TRIEDY bloku, nie podla textu tlacidiel: rovnake slova
    // sa mozu vyskytnut aj v samotnom inzerate a rez do konca dokumentu by
    // vtedy zahodil kus ponuky.
    $html = preg_replace(
        '#<(div|ul|section)\b[^>]*class="[^"]*'
      . '(?:share-list|job-share|social-share|share-buttons)[^"]*"[^>]*>.*$#isu',
        '', $html);

    // Nebezpecne prvky aj s obsahom.
    $html = preg_replace("#<(script|style|iframe|object|embed|form|noscript)\\b[^>]*>.*?</\\1>#is",
                         '', $html);
    $html = preg_replace("#<(script|style|iframe|object|embed|form|input|img)\\b[^>]*/?>#i",
                         '', $html);

    // Hlavicka a pata portalu do detailu inzeratu nepatria.
    $html = preg_replace("#<(nav|header|footer|aside)\\b[^>]*>.*?</\\1>#is", '', $html);

    // Drobcekova navigacia — ariva.sk ju ma ako <ol class="breadcrumb">, teda
    // mimo <nav>, takze predchadzajuce pravidlo ju nechytilo a v detaile
    // zostal cislovany zoznam "1. ariva.sk 2. Volne pozicie 3. <nazov>".
    $html = preg_replace(
        "#<(ol|ul|div|nav)\\b[^>]*class=\"[^\"]*(?:breadcrumb|drobcek)[^\"]*\"[^>]*>.*?</\\1>#is",
        '', $html);

    $html = strip_tags($html,
        '<h1><h2><h3><h4><h5><p><br><ul><ol><li><strong><b><em><i><u>'
      . '<table><thead><tbody><tr><td><th><dl><dt><dd><blockquote><hr>');

    // Atributy: ponechava sa iba obsah, ziadne triedy, styly ani udalosti.
    $html = preg_replace('#<([a-z][a-z0-9]*)\s[^>]*>#i', '<\1>', $html);

    // Prazdne odseky a nadpisy po ocisteni — portaly ich maju vela.
    $html = preg_replace('#<(p|h[1-5]|li)>\s*</\1>#i', '', $html);
    $html = preg_replace('#(<br>\s*){3,}#i', '<br><br>', $html);

    // Ariva ma telo inzeratu zakodovane DVAKRAT — v zdroji je "&aacute;"
    // ako text, takze v prehliadaci by sa zobrazilo doslova. Dekoduje sa
    // az teraz, ked su nebezpecne prvky prec: skorsie dekodovanie by mohlo
    // z neskodneho textu vyrobit platnu znacku.
    //
    // Entity ostrych zatvoriek sa zamerne NEDEKODUJU, inak by "&lt;script&gt;"
    // v texte inzeratu prezil ocistenie a stal sa skutocnou znackou.
    $html = preg_replace_callback(
        '/&(?!lt;|gt;|amp;|#0*(?:60|62|38|39|34);)(?:[a-zA-Z][a-zA-Z0-9]{1,8};|#\d{2,6};)/',
        static fn(array $m): string => html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        $html);

    return mb_substr(trim($html), 0, 80000);
}

// ------------------------------------------------------------
// PostgreSQL vracia TEXT[] ako retazec '{a,b,"c d"}'. Bez rozbalenia by
// frontend dostal nepouzitelny text namiesto pola.
// ------------------------------------------------------------
function pg_pole($v): array {
    if ($v === null || $v === '' || $v === '{}') return [];
    if (is_array($v)) return $v;
    $vnutro = trim($v, '{}');
    if ($vnutro === '') return [];
    // str_getcsv zvladne uvodzovky okolo poloziek s ciarkou vnutri.
    return array_values(array_filter(array_map('trim', str_getcsv($vnutro)),
                                     fn($s) => $s !== ''));
}

function ai_je_true_offers($v): bool {
    return in_array($v, [true, 't', 'true', '1', 1], true);
}
