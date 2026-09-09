<?php
// GET /v1/admin/diag-log[?run_id=8] — vypis logu zberu alebo tazenia
//
// Docasna diagnostika. Oba kroky zberu bezia NA POZADI a ich vystup ide do
// suboru v docasnom adresari. Bez tohto endpointu sa neda zistit, preco
// proces skoncil predcasne — v DB zostane len ciastocny vysledok.
//
// Bez run_id vypise log tazenia (api/cron/zber.php).
require_auth(true);
if ($method !== 'GET') json_error('Method not allowed', 405);

$runId = (int)($_GET['run_id'] ?? 0);
$log = $runId
    ? sys_get_temp_dir() . '/job_zber_' . $runId . '.log'
    : sys_get_temp_dir() . '/job_vytazenie.log';

json_ok([
    'subor'    => $log,
    'existuje' => is_file($log),
    'velkost'  => is_file($log) ? filesize($log) : null,
    'zmeneny'  => is_file($log) ? date('c', filemtime($log)) : null,
    'obsah'    => is_file($log) ? mb_substr((string)file_get_contents($log), -5000) : null,
    'vsetky'   => array_map('basename', glob(sys_get_temp_dir() . '/job_*.log') ?: []),
]);
