<?php
// GET /v1/admin/diag-log?run_id=6 — vypis logu zberu zo servera
//
// Docasna diagnostika. Scraper bezi na pozadi a jeho vystup ide do suboru
// v docasnom adresari; bez tohto endpointu sa neda zistit, preco beh zlyhal
// — v job.scrape_runs zostane len 'running', ked proces spadne skor, nez
// staci stav prepisat.
require_auth(true);
if ($method !== 'GET') json_error('Method not allowed', 405);

$runId = (int)($_GET['run_id'] ?? 0);
if (!$runId) json_error('Chýba run_id', 400);

$log = sys_get_temp_dir() . '/job_zber_' . $runId . '.log';

json_ok([
    'subor'    => $log,
    'existuje' => is_file($log),
    'velkost'  => is_file($log) ? filesize($log) : null,
    'obsah'    => is_file($log) ? mb_substr((string)file_get_contents($log), -4000) : null,
    // Zoznam vsetkych logov — ked chyba prave tento, moze pomoct vidiet,
    // ci sa vobec nejaky vytvoril.
    'vsetky'   => array_map('basename', glob(sys_get_temp_dir() . '/job_zber_*.log') ?: []),
]);
