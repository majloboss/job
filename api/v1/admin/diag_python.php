<?php
// GET /v1/admin/diag-python — je na hostingu Python a kniznice pre scraper?
//
// Docasna diagnostika. Scraper (scraper/profesia.py) potrebuje Python 3
// s modulmi requests a psycopg2. Ak na Websupporte nie su, musi sa zber
// spustat inak (napr. cez PHP scraper alebo z domaceho pocitaca) —
// a to je lepsie zistit teraz nez po napisani celej obrazovky.
//
// Rovnaky sposob spustania ako doc_pdftotext(): proc_open s POLOM
// argumentov. shell_exec ani exec na Websupporte nic nevracaju.
require_auth(true);
if ($method !== 'GET') json_error('Method not allowed', 405);

$vysledok = [
    'proc_open'         => function_exists('proc_open'),
    'disable_functions' => (string)ini_get('disable_functions'),
    'python'            => [],
    'moduly'            => [],
];

// ------------------------------------------------------------
// Spusti prikaz cez proc_open a vrati [navratovy_kod, stdout, stderr].
// ------------------------------------------------------------
function diag_spusti(array $prikaz): array {
    $popis = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proces = @proc_open($prikaz, $popis, $rury);
    if (!is_resource($proces)) return [-1, '', 'proc_open zlyhal'];

    $out = stream_get_contents($rury[1]);
    $err = stream_get_contents($rury[2]);
    fclose($rury[1]);
    fclose($rury[2]);
    return [proc_close($proces), trim((string)$out), trim((string)$err)];
}

if (!$vysledok['proc_open']) {
    json_ok(array_merge($vysledok, [
        'zaver' => 'proc_open nie je dostupny — scraper sa zo servera spustit neda',
    ]));
}

// ------------------------------------------------------------
// Hladanie interpretera
// ------------------------------------------------------------
$kandidati = [
    '/usr/bin/python3', '/usr/local/bin/python3', '/bin/python3',
    '/opt/python/bin/python3', '/usr/bin/python3.11', '/usr/bin/python3.9',
    '/usr/bin/python',
];

$najdeny = null;
foreach ($kandidati as $c) {
    if (!is_executable($c)) continue;
    [$kod, $out, $err] = diag_spusti([$c, '--version']);
    $vysledok['python'][$c] = ['kod' => $kod, 'verzia' => $out ?: $err];
    if ($kod === 0 && $najdeny === null) $najdeny = $c;
}

if ($najdeny === null) {
    json_ok(array_merge($vysledok, [
        'zaver' => 'Python 3 sa na serveri nenasiel — zber sa bude musiet spustat inak',
    ]));
}

// ------------------------------------------------------------
// Su potrebne moduly?
// ------------------------------------------------------------
foreach (['requests', 'psycopg2'] as $modul) {
    [$kod, $out, $err] = diag_spusti([$najdeny, '-c', "import $modul; print($modul.__version__)"]);
    $vysledok['moduly'][$modul] = [
        'je'     => $kod === 0,
        'verzia' => $kod === 0 ? $out : null,
        'chyba'  => $kod === 0 ? null : mb_substr($err, 0, 200),
    ];
}

$vsetko = $vysledok['moduly']['requests']['je'] && $vysledok['moduly']['psycopg2']['je'];

json_ok(array_merge($vysledok, [
    'interpreter' => $najdeny,
    'pripraveny'  => $vsetko,
    'zaver' => $vsetko
        ? 'Python aj moduly su k dispozicii — scraper sa da spustat zo servera'
        : 'Python je, ale chybaju moduly — treba ich doinstalovat alebo spustat zber inak',
]));
