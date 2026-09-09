<?php
// GET  /v1/admin/diag-python          je na hostingu Python a kniznice?
// POST /v1/admin/diag-python?pip=1    skusi doinstalovat requests a psycopg2
//
// Docasna diagnostika. Scraper (scraper/profesia.py) potrebuje Python 3
// s modulmi requests a psycopg2. Prve meranie ukazalo, ze na Websupporte
// je Python 3.10.12 a proc_open funguje, ale moduly chybaju — tento
// endpoint skusi, ci sa daju doinstalovat do domovskeho adresara.
//
// Rovnaky sposob spustania ako doc_pdftotext(): proc_open s POLOM
// argumentov. shell_exec ani exec na Websupporte nic nevracaju.
require_auth(true);

$vysledok = [
    'proc_open'         => function_exists('proc_open'),
    'disable_functions' => (string)ini_get('disable_functions'),
    'python'            => [],
    'moduly'            => [],
];

// ------------------------------------------------------------
// Spusti prikaz cez proc_open a vrati [navratovy_kod, stdout, stderr].
// ------------------------------------------------------------
function diag_spusti(array $prikaz, int $timeout = 120): array {
    $popis = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proces = @proc_open($prikaz, $popis, $rury);
    if (!is_resource($proces)) return [-1, '', 'proc_open zlyhal'];

    // Bez neblokujuceho citania by sa dlhy vystup pipu zaplnil a proces
    // by cakal donekonecna. pip vypisuje vela.
    stream_set_blocking($rury[1], false);
    stream_set_blocking($rury[2], false);

    $out = '';
    $err = '';
    $koniec = time() + $timeout;
    while (time() < $koniec) {
        $out .= stream_get_contents($rury[1]);
        $err .= stream_get_contents($rury[2]);
        $stav = proc_get_status($proces);
        if (!$stav['running']) break;
        usleep(200000);
    }
    $out .= stream_get_contents($rury[1]);
    $err .= stream_get_contents($rury[2]);

    fclose($rury[1]);
    fclose($rury[2]);
    return [proc_close($proces), trim($out), trim($err)];
}

function diag_najdi_python(): ?string {
    foreach (['/usr/bin/python3', '/usr/local/bin/python3', '/bin/python3',
              '/opt/python/bin/python3', '/usr/bin/python'] as $c) {
        if (is_executable($c)) return $c;
    }
    return null;
}

if (!$vysledok['proc_open']) {
    json_ok(array_merge($vysledok, [
        'zaver' => 'proc_open nie je dostupny — scraper sa zo servera spustit neda',
    ]));
}

$python = diag_najdi_python();
if ($python === null) {
    json_ok(array_merge($vysledok, ['zaver' => 'Python 3 sa na serveri nenasiel']));
}

// ------------------------------------------------------------
// POST ?pip=1 — pokus o instalaciu do domovskeho adresara
//
// --user instaluje do ~/.local/lib, kam sa na zdielanom hostingu zapisovat
// da. Bez --user by to skoncilo na pravach do systemovych adresarov.
// psycopg2-binary, nie psycopg2: ta druha vyzaduje prekladac a hlavicky
// libpq, ktore na hostingu nebyvaju.
// ------------------------------------------------------------
if ($method === 'POST' && ($_GET['pip'] ?? '') === '1') {
    $kroky = [];
    foreach ([['ensurepip', ['-m', 'ensurepip', '--user']],
              ['pip', ['-m', 'pip', 'install', '--user', '--no-input',
                       'requests', 'psycopg2-binary']]] as [$nazov, $args]) {
        [$kod, $out, $err] = diag_spusti(array_merge([$python], $args), 180);
        $kroky[$nazov] = [
            'kod'    => $kod,
            'vystup' => mb_substr($out, -600),
            'chyba'  => mb_substr($err, -600),
        ];
        // ensurepip moze zlyhat, ked uz pip existuje — to nevadi,
        // dolezity je az vysledok samotnej instalacie.
    }
    $vysledok['instalacia'] = $kroky;
}

// ------------------------------------------------------------
// Su potrebne moduly?
// ------------------------------------------------------------
[$kod, $out, $err] = diag_spusti([$python, '--version']);
$vysledok['python'][$python] = ['kod' => $kod, 'verzia' => $out ?: $err];

foreach (['requests', 'psycopg2'] as $modul) {
    [$kod, $out, $err] = diag_spusti([$python, '-c', "import $modul; print($modul.__version__)"]);
    $vysledok['moduly'][$modul] = [
        'je'     => $kod === 0,
        'verzia' => $kod === 0 ? $out : null,
        'chyba'  => $kod === 0 ? null : mb_substr($err, -200),
    ];
}

$vsetko = $vysledok['moduly']['requests']['je'] && $vysledok['moduly']['psycopg2']['je'];

json_ok(array_merge($vysledok, [
    'interpreter' => $python,
    'pripraveny'  => $vsetko,
    'zaver' => $vsetko
        ? 'Python aj moduly su k dispozicii — scraper sa da spustat zo servera'
        : 'Python je, ale chybaju moduly. Skus POST ?pip=1 na instalaciu.',
]));
