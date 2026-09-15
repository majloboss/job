<?php
// Ciselnik portalov — /v1/admin/portaly
//
// GET                     zoznam portalov s poctom zozbieranych ponuk
// POST ?akcia=ulozit      zmeni portal { id, name, url_kriteria,
//                         url_kriteria_dalsie, is_active, je_agentura,
//                         ponuk_na_stranu, request_delay_ms, popis }
// POST ?akcia=pridat      zalozi novy portal { code, name, ... }
// POST ?akcia=zmazat      zmaze portal aj s jeho ponukami { id }
//
// Portal moze mat VIAC vychodzich adries: profesia.sk nedava vsetky ponuky
// na jednom zozname, kazdy kraj ma vlastnu. Prva adresa je url_kriteria,
// dalsie su v poli url_kriteria_dalsie — zber ich prejde po kolach.
$auth = require_auth(true);
$pdo  = db();

// PostgreSQL vracia pole ako text '{"a","b"}'. Ovladac ho nerozbaluje,
// takze sa to robi tu — rovnako ako v offers.php, ale ta funkcia je
// sukroma pre svoj endpoint.
function portaly_pole($v): array {
    if (is_array($v)) return $v;
    $t = trim((string)$v, '{}');
    if ($t === '') return [];
    return array_values(array_filter(array_map(
        static fn($x) => trim($x, ' "'),
        str_getcsv($t))));
}

// Boolean z PostgreSQL chodi ako 't'/'f' alebo true/false podla ovladaca.
function portaly_je_true($v): bool {
    return $v === true || $v === 't' || $v === 1 || $v === '1';
}

// Boolean do PostgreSQL.
//
// PDO viaze PHP false ako PRAZDNY RETAZEC a PostgreSQL ho pre typ boolean
// odmietne ("invalid input syntax for type boolean"). Uklada sa preto
// vyslovne 't'/'f' — inak ulozenie s odskrtnutym polickom zlyha.
function portaly_bool(bool $v): string {
    return $v ? 't' : 'f';
}

// Pole adries do tvaru, ktory prijme PostgreSQL.
function portaly_pole_sql(array $adresy): string {
    if (!$adresy) return '{}';
    return '{' . implode(',', array_map(
        static fn($a) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $a) . '"',
        $adresy)) . '}';
}

// ------------------------------------------------------------
// GET — zoznam
// ------------------------------------------------------------
if ($method === 'GET') {
    // Pocty ponuk hovoria, ci zber z portalu naozaj nieco prinasa —
    // bez nich sa neda rozlisit nenastaveny portal od pokazeneho.
    $portaly = $pdo->query(
        "SELECT s.id, s.code, s.name, s.base_url, s.url_kriteria,
                s.url_kriteria_dalsie, s.is_active, s.je_agentura,
                s.vyzaduje_prihlasenie, s.ponuk_na_stranu, s.request_delay_ms,
                s.default_period_days, s.popis, s.notes, s.last_scraped_at,
                COUNT(o.id)                                   AS ponuk,
                COUNT(*) FILTER (WHERE o.is_active)           AS platnych,
                COUNT(o.company_name_raw)                     AS s_firmou,
                COUNT(o.employment_type)                      AS s_uvazkom,
                COUNT(o.salary_min)                           AS s_mzdou,
                COUNT(o.summary_sk)                           AS vytazenych
           FROM job.sources s
           LEFT JOIN job.offers o ON o.source_id = s.id
          GROUP BY s.id
          ORDER BY s.is_active DESC, s.name")->fetchAll();

    foreach ($portaly as &$p) {
        $p['url_kriteria_dalsie'] = portaly_pole($p['url_kriteria_dalsie']);
        $p['is_active']           = portaly_je_true($p['is_active']);
        $p['je_agentura']         = portaly_je_true($p['je_agentura']);
        $p['vyzaduje_prihlasenie'] = portaly_je_true($p['vyzaduje_prihlasenie']);
    }
    unset($p);

    json_ok(['portaly' => $portaly]);
}

if ($method !== 'POST') json_error('Method not allowed', 405);
$vstup = json_decode(file_get_contents('php://input'), true) ?: [];
$akcia = $_GET['akcia'] ?? '';

// ------------------------------------------------------------
// Adresy zberu
//
// Prva adresa je povinna, dalsie nepovinne. Prijimaju sa ako jeden text
// (riadok = adresa), lebo tak sa najprirodzenejsie vkladaju z prehliadaca.
// ------------------------------------------------------------
function portaly_adresy(array $vstup): array {
    $riadky = $vstup['adresy'] ?? null;

    if (is_string($riadky)) {
        $riadky = preg_split('/\R+/', $riadky);
    } elseif (!is_array($riadky)) {
        // Starsi tvar: prva adresa a pole dalsich zvlast.
        $riadky = array_merge(
            [(string)($vstup['url_kriteria'] ?? '')],
            (array)($vstup['url_kriteria_dalsie'] ?? []));
    }

    $adresy = [];
    foreach ($riadky as $r) {
        $r = trim((string)$r);
        if ($r === '' || in_array($r, $adresy, true)) continue;
        if (!preg_match('#^https?://#i', $r)) {
            json_error('Adresa musí začínať http:// alebo https:// — ' . mb_substr($r, 0, 60), 400);
        }
        $adresy[] = $r;
    }
    if (!$adresy) json_error('Portál musí mať aspoň jednu adresu zberu', 400);
    return $adresy;
}

// ------------------------------------------------------------
// POST ?akcia=ulozit — zmena portalu
// ------------------------------------------------------------
if ($akcia === 'ulozit') {
    $id = (int)($vstup['id'] ?? 0);
    if (!$id) json_error('Chýba id portálu', 400);

    $st = $pdo->prepare('SELECT code FROM job.sources WHERE id = ?');
    $st->execute([$id]);
    if (!$st->fetchColumn()) json_error('Portál sa nenašiel', 404);

    $adresy = portaly_adresy($vstup);
    $nazov  = trim((string)($vstup['name'] ?? ''));
    if ($nazov === '') json_error('Chýba názov portálu', 400);

    $st = $pdo->prepare(
        'UPDATE job.sources SET
            name = ?, url_kriteria = ?, url_kriteria_dalsie = ?,
            is_active = ?, je_agentura = ?,
            ponuk_na_stranu = ?, request_delay_ms = ?, popis = ?
          WHERE id = ?');
    $st->execute([
        mb_substr($nazov, 0, 100),
        $adresy[0],
        portaly_pole_sql(array_slice($adresy, 1)),
        portaly_bool(!empty($vstup['is_active'])),
        portaly_bool(!empty($vstup['je_agentura'])),
        max(0, min(500, (int)($vstup['ponuk_na_stranu'] ?? 0))) ?: null,
        max(200, min(10000, (int)($vstup['request_delay_ms'] ?? 1500))),
        mb_substr(trim((string)($vstup['popis'] ?? '')), 0, 2000) ?: null,
        $id,
    ]);

    json_ok(['sprava' => 'Portál ' . $nazov . ' uložený ('
                       . count($adresy) . ' '
                       . (count($adresy) === 1 ? 'adresa' : 'adries') . ').']);
}

// ------------------------------------------------------------
// POST ?akcia=pridat — novy portal
// ------------------------------------------------------------
if ($akcia === 'pridat') {
    $kod   = strtolower(trim((string)($vstup['code'] ?? '')));
    $nazov = trim((string)($vstup['name'] ?? ''));

    // Kod je kluc pouzivany v scraperi (--portal <kod>), preto bez diakritiky
    // a medzier — inak by sa nedal zadat z prikazoveho riadka.
    if (!preg_match('/^[a-z][a-z0-9_-]{1,29}$/', $kod)) {
        json_error('Kód smie mať len malé písmená, číslice, pomlčku a podčiarkovník', 400);
    }
    if ($nazov === '') json_error('Chýba názov portálu', 400);

    $st = $pdo->prepare('SELECT 1 FROM job.sources WHERE code = ?');
    $st->execute([$kod]);
    if ($st->fetchColumn()) json_error('Portál s kódom ' . $kod . ' už existuje', 409);

    $adresy = portaly_adresy($vstup);

    // base_url sa odvodi z prvej adresy — sluzi na doplnenie relativnych
    // odkazov v zozname a rucne ho zadavat netreba.
    $casti = parse_url($adresy[0]);
    $base  = ($casti['scheme'] ?? 'https') . '://' . ($casti['host'] ?? '');

    $st = $pdo->prepare(
        'INSERT INTO job.sources
            (code, name, base_url, url_kriteria, url_kriteria_dalsie,
             is_active, je_agentura, ponuk_na_stranu, request_delay_ms,
             default_period_days, popis, country)
         VALUES (?,?,?,?,?,?,?,?,?,2,?,\'SK\') RETURNING id');
    $st->execute([
        $kod, mb_substr($nazov, 0, 100), $base, $adresy[0],
        portaly_pole_sql(array_slice($adresy, 1)),
        portaly_bool(!empty($vstup['is_active'])),
        portaly_bool(!empty($vstup['je_agentura'])),
        max(0, min(500, (int)($vstup['ponuk_na_stranu'] ?? 0))) ?: null,
        max(200, min(10000, (int)($vstup['request_delay_ms'] ?? 1500))),
        mb_substr(trim((string)($vstup['popis'] ?? '')), 0, 2000) ?: null,
    ]);

    json_ok(['id' => (int)$st->fetchColumn(),
             'sprava' => 'Portál ' . $nazov . ' pridaný.']);
}

// ------------------------------------------------------------
// POST ?akcia=zmazat — zmaze portal aj s ponukami
//
// Ponuky bez portalu by v zozname zostali ako siroty bez zdroja, preto
// kaskada. Vyzaduje vyslovne potvrdenie — mazanie sa neda vratit.
// ------------------------------------------------------------
if ($akcia === 'zmazat') {
    $id = (int)($vstup['id'] ?? 0);
    if (!$id) json_error('Chýba id portálu', 400);
    if (empty($vstup['potvrdene'])) json_error('Chýba potvrdenie zmazania', 400);

    $st = $pdo->prepare(
        'SELECT s.name, COUNT(o.id) AS ponuk
           FROM job.sources s LEFT JOIN job.offers o ON o.source_id = s.id
          WHERE s.id = ? GROUP BY s.name');
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) json_error('Portál sa nenašiel', 404);

    $pdo->prepare('DELETE FROM job.sources WHERE id = ?')->execute([$id]);

    json_ok(['sprava' => 'Portál ' . $p['name'] . ' zmazaný'
                       . ((int)$p['ponuk'] > 0
                          ? ' aj s ' . $p['ponuk'] . ' ponukami.' : '.')]);
}

json_error('Neznáma akcia: ' . $akcia, 400);
