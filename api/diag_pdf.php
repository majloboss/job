<?php
// Docasna diagnostika vytazenia textu z PDF.
// https://job.fellow.sk/api/diag_pdf.php?token=<CRON_SECRET>
//
// Po vyrieseni problemu tento subor ZMAZ.

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/config/db.php';

if (!defined('CRON_SECRET') || !hash_equals(CRON_SECRET, $_GET['token'] ?? '')) {
    http_response_code(403);
    exit("Neplatny token\n");
}

echo "=== prostredie ===\n";
echo 'PHP: ' . PHP_VERSION . "\n";
echo 'disable_functions: ' . (ini_get('disable_functions') ?: '(prazdne)') . "\n";
echo 'shell_exec existuje: ' . (function_exists('shell_exec') ? 'ano' : 'NIE') . "\n";
echo 'exec existuje: ' . (function_exists('exec') ? 'ano' : 'NIE') . "\n\n";

echo "=== hladanie pdftotext ===\n";
if (function_exists('shell_exec')) {
    $cmd = @shell_exec('command -v pdftotext 2>&1');
    echo "command -v pdftotext: " . var_export($cmd, true) . "\n";
    $which = @shell_exec('which pdftotext 2>&1');
    echo "which pdftotext:      " . var_export($which, true) . "\n";
    $ver = @shell_exec('pdftotext -v 2>&1');
    echo "pdftotext -v:\n" . ($ver ?: '(nic)') . "\n";
}
foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext', '/bin/pdftotext'] as $c) {
    printf("%-28s existuje=%s spustitelny=%s\n", $c,
        is_file($c) ? 'ano' : 'nie', is_executable($c) ? 'ano' : 'nie');
}

echo "\n=== test na skutocnom PDF ===\n";
// zober najnovsie nahrate PDF, ak nejake je
$pdf = null;
foreach ([__DIR__ . '/uploads/documents/', dirname(__DIR__) . '/uploads/documents/'] as $dir) {
    foreach (glob($dir . '*.pdf') ?: [] as $f) {
        if ($pdf === null || filemtime($f) > filemtime($pdf)) $pdf = $f;
    }
}

if ($pdf === null) {
    echo "V uploads/documents nie je ziadne PDF — najprv nahraj CV cez appku.\n";
    exit;
}

echo "subor: " . basename($pdf) . ' (' . number_format(filesize($pdf)) . " B)\n\n";

$bin = '/usr/bin/pdftotext';
$args = ' -enc UTF-8 -layout -q ' . escapeshellarg($pdf) . ' - ';

// 1) shell_exec
if (function_exists('shell_exec')) {
    $out = @shell_exec(escapeshellarg($bin) . $args . ' 2>&1');
    vypis('shell_exec', $out);
}

// 2) exec — vracia riadky v poli a navratovy kod
if (function_exists('exec')) {
    $riadky = [];
    $kod = -1;
    @exec(escapeshellarg($bin) . $args . ' 2>&1', $riadky, $kod);
    echo "--- exec ---\n  navratovy kod: $kod\n";
    vypis('exec', implode("\n", $riadky));
}

// 3) proc_open — najspolahlivejsie, obchadza shell
if (function_exists('proc_open')) {
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = @proc_open([$bin, '-enc', 'UTF-8', '-layout', '-q', $pdf, '-'], $desc, $pipes);
    if (is_resource($p)) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $kod = proc_close($p);
        echo "--- proc_open ---\n  navratovy kod: $kod\n";
        if ($err !== '') echo "  stderr: " . substr($err, 0, 200) . "\n";
        vypis('proc_open', $out);
    } else {
        echo "--- proc_open ---\n  nepodarilo sa spustit\n\n";
    }
}

function vypis(string $sposob, $out): void {
    echo "  dlzka: " . (is_string($out) ? strlen($out) : 0) . " B\n";
    if (is_string($out) && $out !== '') {
        echo "  platne UTF-8: " . (mb_check_encoding($out, 'UTF-8') ? 'ano' : 'NIE') . "\n";
        echo "  ukazka: " . substr(preg_replace('/\s+/', ' ', $out), 0, 220) . "\n";
    }
    echo "\n";
}
