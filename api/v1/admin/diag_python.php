<?php
// GET  /v1/admin/diag-python          je na hostingu Python a kniznice?
// POST /v1/admin/diag-python?pip=1    skusi doinstalovat requests a psycopg2
//
// Docasna diagnostika. Scraper (scraper/profesia.py) potrebuje Python 3
// s modulmi requests a psycopg2.
//
// Zistene na Websupporte:
//   - Python 3.10.12 je, proc_open funguje (shell_exec ani exec nie)
//   - moduly chybali, pip install --user ich doinstaloval
//   - POZOR: HOME procesu je /tmp, takze --user ich dal do /tmp/.local,
//     co sa pravidelne cisti. Preto sa instaluje do PEVNEHO adresara
//     v projekte (api/pylibs) cez --target a ten sa scraperu odovzda
//     cez PYTHONPATH.
require_auth(true);

// Kam sa instaluju kniznice. V projekte, nie v /tmp — musi to prezit
// cistenie docasnych suborov aj restart.
define('PYLIBS', dirname(__DIR__, 2) . '/pylibs');

$vysledok = [
    'proc_open'         => function_exists('proc_open'),
    'disable_functions' => (string)ini_get('disable_functions'),
    'pylibs'            => PYLIBS,
    'pylibs_existuje'   => is_dir(PYLIBS),
    'home'              => getenv('HOME') ?: '(nenastavene)',
    'python'            => [],
    'moduly'            => [],
];

// ------------------------------------------------------------
// Spusti prikaz cez proc_open a vrati [kod, stdout, stderr].
//
// Citanie je NEBLOKUJUCE: pip vypisuje vela a pri plnom pipe by proces
// cakal donekonecna.
// ------------------------------------------------------------
function diag_spusti(array $prikaz, int $timeout = 120, array $env = null): array {
    $popis = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proces = @proc_open($prikaz, $popis, $rury, null, $env);
    if (!is_resource($proces)) return [-1, '', 'proc_open zlyhal'];

    stream_set_blocking($rury[1], false);
    stream_set_blocking($rury[2], false);

    $out = $err = '';
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
    json_ok(array_merge($vysledok, ['zaver' => 'proc_open nie je dostupny']));
}

$python = diag_najdi_python();
if ($python === null) {
    json_ok(array_merge($vysledok, ['zaver' => 'Python 3 sa na serveri nenasiel']));
}

// PYTHONPATH ukazuje na nase kniznice — scraper sa spusta rovnako.
$env = ['PYTHONPATH' => PYLIBS, 'HOME' => sys_get_temp_dir(),
        'PATH' => '/usr/local/bin:/usr/bin:/bin'];

// ------------------------------------------------------------
// POST ?pip=1 — instalacia do api/pylibs
//
// --target, nie --user: --user riadi HOME, ktory je na tomto hostingu /tmp
// a ten sa cisti. Pevny adresar v projekte je jedina spolahliva moznost.
// psycopg2-binary, nie psycopg2: ta vyzaduje prekladac a hlavicky libpq.
// ------------------------------------------------------------
if ($method === 'POST' && ($_GET['pip'] ?? '') === '1') {
    if (!is_dir(PYLIBS) && !@mkdir(PYLIBS, 0755, true)) {
        json_error('Adresar ' . PYLIBS . ' sa nepodarilo vytvorit', 500);
    }

    [$kod, $out, $err] = diag_spusti(
        [$python, '-m', 'pip', 'install', '--target', PYLIBS, '--upgrade',
         '--no-input', '--no-warn-script-location', 'requests', 'psycopg2-binary'],
        240, $env);

    $vysledok['instalacia'] = [
        'kod'    => $kod,
        'vystup' => mb_substr($out, -800),
        'chyba'  => mb_substr($err, -800),
    ];
    $vysledok['pylibs_existuje'] = is_dir(PYLIBS);
}

// ------------------------------------------------------------
// Su moduly dostupne pri spusteni s nasim PYTHONPATH?
// ------------------------------------------------------------
[$kod, $out, $err] = diag_spusti([$python, '--version']);
$vysledok['python'][$python] = ['kod' => $kod, 'verzia' => $out ?: $err];

foreach (['requests', 'psycopg2'] as $modul) {
    [$kod, $out, $err] = diag_spusti(
        [$python, '-c', "import $modul, os; print($modul.__version__, os.path.dirname($modul.__file__))"],
        60, $env);
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
        : 'Chybaju moduly. Skus POST ?pip=1.',
]));
