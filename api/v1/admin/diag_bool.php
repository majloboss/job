<?php
// GET /v1/admin/diag-bool — co presne vracia PDO pre boolean z PostgreSQL
//
// Docasna diagnostika. Tazenie sa zastavuje na "Vypnute pre dnesok", hoci
// v DB je is_enabled = true. Rozdiel musi byt v tom, ako hodnotu podava PDO
// — Node ovladac vracia true, PDO moze vracat 't', '1' alebo nieco ine.
require_auth(true);
if ($method !== 'GET') json_error('Method not allowed', 405);

require_once __DIR__ . '/../../helpers/ai_modely_fn.php';

$pdo = db();

$st = $pdo->prepare(
    'SELECT s.is_enabled, s.disabled_reason, s.chosen_by, s.poradie_index, s.den, m.*
       FROM job.ai_stav s
       LEFT JOIN job.ai_models m ON m.id = s.model_id
      WHERE s.ucel = ? AND s.source_id = ? AND s.den = ?');
$st->execute(['parse', 1, date('Y-m-d')]);
$stav = $st->fetch();

// Ako vidi hodnotu PHP — presny typ aj obsah, aby bolo jasne, na com
// ai_je_true() zlyhava.
$rozbor = null;
if ($stav) {
    $v = $stav['is_enabled'];
    $rozbor = [
        'typ'         => gettype($v),
        'var_export'  => var_export($v, true),
        'dlzka'       => is_string($v) ? strlen($v) : null,
        'ai_je_true'  => ai_je_true($v),
        'ako_bool'    => (bool)$v,
    ];
}

$vyber = ai_vyber_model('parse', 1);

json_ok([
    'php_datum'   => date('Y-m-d'),
    'db_datum'    => $pdo->query('SELECT CURRENT_DATE::TEXT')->fetchColumn(),
    'riadok_je'   => $stav !== false,
    'den_v_riadku'=> $stav['den'] ?? null,
    'is_enabled'  => $rozbor,
    'vyber'       => [
        'model'   => $vyber['model']['model_id'] ?? null,
        'dovod'   => $vyber['dovod'],
        'vypnute' => $vyber['vypnute'],
    ],
    // Vsetky riadky, nech je vidno, ci sa nepozerame na iny den.
    'vsetky_riadky' => $pdo->query(
        'SELECT ucel, source_id, den::TEXT, is_enabled::TEXT AS is_enabled_text
           FROM job.ai_stav ORDER BY den DESC')->fetchAll(),
]);
