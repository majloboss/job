<?php
// GET /v1/admin/ai-models — zoznam bezplatnych modelov na OpenRouteri
//
// Zoznam sa tiahne zivo (ktore modely su zadarmo sa v case meni) a zaroven
// sa zosynchronizuje ciselnik job.ai_models, aby sa k modelom dala drzat
// statistika uspesnosti z laboratoria.
require_auth(true);
if ($method !== 'GET') json_error('Method not allowed', 405);

require_once __DIR__ . '/../../helpers/openrouter_boot.php';
require_once __DIR__ . '/../../helpers/openrouter_fn.php';

$free = or_free_models();
or_sync_models($free);

// dopln statistiku z ciselnika
$stats = [];
foreach (db()->query(
    'SELECT model_id, lab_runs, lab_ok, avg_ms, is_enabled, is_default, last_error
       FROM job.ai_models')->fetchAll() as $r) {
    $stats[$r['model_id']] = $r;
}
foreach ($free as &$m) {
    $s = $stats[$m['id']] ?? null;
    $m['lab_runs']   = $s ? (int)$s['lab_runs'] : 0;
    $m['lab_ok']     = $s ? (int)$s['lab_ok']   : 0;
    $m['avg_ms']     = $s && $s['avg_ms'] !== null ? (int)$s['avg_ms'] : null;
    $m['is_enabled'] = $s ? in_array($s['is_enabled'], [true,'t','1',1], true) : true;
    $m['is_default'] = $s ? in_array($s['is_default'], [true,'t','1',1], true) : false;
    // Dovod vyradenia — frontend ho ukaze pri zasedivenom modeli.
    $m['last_error'] = $s['last_error'] ?? null;
}
unset($m);

// Pouzitelne modely idu hore; medzi nimi rozhoduje velkost kontextu
// (zoradenie z or_free_models zostava zachovane).
usort($free, fn($a, $b) => ($b['is_enabled'] <=> $a['is_enabled'])
                        ?: (($b['context'] ?? 0) <=> ($a['context'] ?? 0)));

$pouzitelnych = count(array_filter($free, fn($m) => $m['is_enabled']));

json_ok([
    'models'       => $free,
    'count'        => count($free),
    'pouzitelnych' => $pouzitelnych,
]);
