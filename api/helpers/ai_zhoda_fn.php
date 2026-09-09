<?php
// Vyhodnotenie zhody modelov pri tazani udajov z inzeratu ('parse').
//
// PRECO TO TREBA: model, ktory vrati platny JSON, este nemusi mat pravdu.
// BetClub to zistil na livescore — z 11 modelov, ktore test "presli",
// vratilo 6 ROZNYCH skore toho isteho zapasu. Uspesnost teda nestaci.
//
// Pri inzerate je to zlozitejsie nez pri skore: nie je jeden udaj, ale
// sada poli. Preto sa neporovnava cela odpoved naraz, ale pole po poli —
// a model dostane podiel poli, v ktorych sa zhodol s vacsinou.
//
// Nie je to dokaz spravnosti, ale model osamote proti vacsine je podozrivy.

// Polia, na ktorych sa meria zhoda, s vahou.
//
// Mzda a nazov pozicie vazia najviac — to su udaje, podla ktorych sa
// pouzivatel rozhoduje. Zoznamy (locations, languages) sa porovnavaju ako
// mnoziny, poradie v nich nic neznamena.
const AI_ZHODA_POLIA = [
    // Nazov pozicie a nazov firmy sa NEPOROVNAVAJU — od promptu v4 ich
    // model nevracia. Su to presne retazce zo stranky, ktore vytiahne
    // scraper z HTML; modely ich komolili ("cacnika" namiesto "casnika").
    'profession'      => ['vaha' => 3, 'typ' => 'text'],
    'salary_min'      => ['vaha' => 3, 'typ' => 'cislo'],
    'salary_max'      => ['vaha' => 2, 'typ' => 'cislo'],
    'salary_period'   => ['vaha' => 2, 'typ' => 'presne'],

    // Uvazok sa porovnava ako mnozina, nie jedna hodnota: inzerat casto
    // ponuka viac moznosti naraz ("plny uvazok, na dohodu"). Pri jedinej
    // hodnote by sa modely nezhodli nikdy — spravna odpoved je "oboje".
    'employment_types' => ['vaha' => 2, 'typ' => 'mnozina'],
    'is_agency'       => ['vaha' => 2, 'typ' => 'bool'],
    'remote_type'     => ['vaha' => 1, 'typ' => 'presne'],
    'seniority'       => ['vaha' => 1, 'typ' => 'presne'],
    'orig_lang'       => ['vaha' => 1, 'typ' => 'presne'],
    'locations'       => ['vaha' => 2, 'typ' => 'mnozina'],
    'positions_count' => ['vaha' => 1, 'typ' => 'cislo'],
    'industry'        => ['vaha' => 2, 'typ' => 'text'],

    // Technologie sa porovnavaju ako mnozina: model, ktory k inzeratu na
    // vodica prida "Java", si zjavne vymysla. Kluc. slova sa zamerne
    // NEPOROVNAVAJU — kazdy model ich formuluje inak a zhoda by bola nahodna.
    'technologies'    => ['vaha' => 2, 'typ' => 'mnozina'],
];

// Od akeho podielu zhodnych poli sa model povazuje za zhodny s vacsinou.
const AI_ZHODA_HRANICA = 0.7;

// ------------------------------------------------------------
// Normalizuje hodnotu pola na porovnatelny tvar.
//
// Bez toho by "Bratislava" a "bratislava " vysli ako nezhoda a kazdy model
// by bol podozrivy. Cielom je porovnavat udaj, nie pravopis.
// ------------------------------------------------------------
function ai_norm_hodnota($v, string $typ): ?string {
    if ($v === null || $v === '' || $v === []) return null;

    switch ($typ) {
        case 'cislo':
            if (!is_numeric($v)) return null;
            // Mzda 1600 a 1600.00 je ta ista mzda.
            return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');

        case 'bool':
            return in_array($v, [true, 'true', 1, '1'], true) ? '1' : '0';

        case 'mnozina':
            if (!is_array($v)) $v = [$v];
            $polozky = [];
            foreach ($v as $p) {
                $n = ai_norm_text(is_array($p) ? ($p['name'] ?? '') : (string)$p);
                if ($n !== '') $polozky[] = $n;
            }
            if (!$polozky) return null;
            // Poradie miest vykonu prace nic neznamena.
            sort($polozky);
            return implode('|', array_unique($polozky));

        case 'presne':
            return ai_norm_text((string)$v);

        default:  // 'text'
            return ai_norm_text((string)$v);
    }
}

// Text na porovnanie: male pismena, bez diakritiky, bez viacnasobnych medzier.
function ai_norm_text(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = strtr($s, [
        'á'=>'a','ä'=>'a','č'=>'c','ď'=>'d','é'=>'e','í'=>'i','ĺ'=>'l','ľ'=>'l',
        'ň'=>'n','ó'=>'o','ô'=>'o','ŕ'=>'r','š'=>'s','ť'=>'t','ú'=>'u','ý'=>'y',
        'ž'=>'z','ě'=>'e','ř'=>'r','ů'=>'u',
    ]);
    $s = preg_replace('/[^a-z0-9|]+/u', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

// ------------------------------------------------------------
// Vyhodnoti zhodu vsetkych modelov v jednom behu laboratoria.
//
// Postup:
//   1. pre kazde pole sa zisti najcastejsia hodnota (vacsinovy nazor)
//   2. kazdemu modelu sa spocita vazeny podiel poli, v ktorych ju trafil
//   3. model nad hranicou sa oznaci agrees = TRUE
//
// Do porovnania sa berie len pole, ktore vyplnila aspon polovica modelov —
// inak by udaj, ktory v inzerate nie je, robil z vacsiny nezhodu.
//
// Vracia ['vacsina' => [pole => hodnota], 'modely' => [model => podiel]].
// ------------------------------------------------------------
function ai_vyhodnot_zhodu(int $runId): array {
    $pdo = db();

    $st = $pdo->prepare(
        "SELECT id, model_id, parsed FROM job.ai_evaluations
          WHERE lab_run_id = ? AND status = 'ok' AND parsed IS NOT NULL");
    $st->execute([$runId]);
    $riadky = $st->fetchAll();

    // Na zhodu treba aspon troch: pri dvoch modeloch neexistuje vacsina.
    if (count($riadky) < 3) return ['vacsina' => [], 'modely' => []];

    // --- 1) rozparsuj odpovede ---
    $odpovede = [];   // id => [pole => normalizovana hodnota]
    foreach ($riadky as $r) {
        $d = json_decode($r['parsed'], true);
        if (!is_array($d)) continue;
        $norm = [];
        foreach (AI_ZHODA_POLIA as $pole => $cfg) {
            $norm[$pole] = ai_norm_hodnota($d[$pole] ?? null, $cfg['typ']);
        }
        $odpovede[$r['id']] = ['model' => $r['model_id'], 'polia' => $norm];
    }
    if (count($odpovede) < 3) return ['vacsina' => [], 'modely' => []];

    // --- 2) vacsinovy nazor na kazde pole ---
    $vacsina = [];
    $pocet   = count($odpovede);
    foreach (AI_ZHODA_POLIA as $pole => $cfg) {
        $hlasy = [];
        foreach ($odpovede as $o) {
            $h = $o['polia'][$pole];
            if ($h === null) continue;
            $hlasy[$h] = ($hlasy[$h] ?? 0) + 1;
        }
        // Pole, ktore vyplnila menej nez polovica modelov, sa nehodnoti —
        // pravdepodobne v inzerate nie je.
        if (!$hlasy || array_sum($hlasy) < $pocet / 2) continue;

        arsort($hlasy);
        $vacsina[$pole] = ['hodnota' => array_key_first($hlasy),
                           'hlasov'  => reset($hlasy)];
    }
    if (!$vacsina) return ['vacsina' => [], 'modely' => []];

    // --- 3) podiel zhody kazdeho modelu ---
    $upd = $pdo->prepare('UPDATE job.ai_evaluations SET agrees = ? WHERE id = ?');
    $vysledky = [];

    foreach ($odpovede as $id => $o) {
        $vahaSpolu = 0;
        $vahaZhoda = 0;

        foreach ($vacsina as $pole => $v) {
            $vaha = AI_ZHODA_POLIA[$pole]['vaha'];
            $moja = $o['polia'][$pole];

            // Model, ktory pole nevyplnil vobec, sa nepenalizuje plnou vahou —
            // chybajuci udaj je mensie zlo nez vymysleny.
            if ($moja === null) { $vahaSpolu += $vaha / 2; continue; }

            $vahaSpolu += $vaha;
            if ($moja === $v['hodnota']) $vahaZhoda += $vaha;
        }

        $podiel = $vahaSpolu > 0 ? $vahaZhoda / $vahaSpolu : 0;
        $upd->execute([$podiel >= AI_ZHODA_HRANICA ? 't' : 'f', $id]);
        $vysledky[$o['model']] = round($podiel * 100, 1);
    }

    // --- 4) prepocet zhody v ciselniku ---
    foreach (array_keys($vysledky) as $model) {
        ai_prepocitaj_statistiku($model);
    }

    $prehlad = [];
    foreach ($vacsina as $pole => $v) {
        $prehlad[$pole] = ['hodnota' => $v['hodnota'],
                           'hlasov'  => $v['hlasov'], 'z' => $pocet];
    }

    return ['vacsina' => $prehlad, 'modely' => $vysledky];
}

// ------------------------------------------------------------
// Prehlad zhody uz vyhodnoteneho behu — pre zobrazenie v laboratoriu.
//
// Na rozdiel od ai_vyhodnot_zhodu() nic neprepocitava ani nezapisuje.
// Ukaze, na ktorych poliach sa modely rozchadzaju: prave tam sa laduje
// prompt alebo vyraduje model.
//
// Vracia null, ked sa zhoda este nevyhodnotila.
// ------------------------------------------------------------
function ai_prehlad_zhody(int $runId): ?array {
    $st = db()->prepare(
        "SELECT model_id, parsed, agrees FROM job.ai_evaluations
          WHERE lab_run_id = ? AND status = 'ok' AND parsed IS NOT NULL");
    $st->execute([$runId]);
    $riadky = $st->fetchAll();
    if (count($riadky) < 3) return null;

    $vyhodnotene = false;
    $odpovede    = [];
    foreach ($riadky as $r) {
        if ($r['agrees'] !== null) $vyhodnotene = true;
        $d = json_decode($r['parsed'], true);
        if (!is_array($d)) continue;
        $norm = [];
        foreach (AI_ZHODA_POLIA as $pole => $cfg) {
            $norm[$pole] = ai_norm_hodnota($d[$pole] ?? null, $cfg['typ']);
        }
        $odpovede[] = ['model' => $r['model_id'], 'polia' => $norm,
                       'agrees' => $r['agrees']];
    }
    if (!$vyhodnotene || !$odpovede) return null;

    // Pre kazde pole: ktore hodnoty modely vratili a kolko ich bolo.
    // Pole s jedinou hodnotou je nudne — zaujimave su tie rozporne.
    $polia = [];
    foreach (AI_ZHODA_POLIA as $pole => $cfg) {
        $hlasy = [];
        foreach ($odpovede as $o) {
            $h = $o['polia'][$pole] ?? null;
            $kluc = $h ?? '(nevyplnené)';
            $hlasy[$kluc] = ($hlasy[$kluc] ?? 0) + 1;
        }
        arsort($hlasy);
        $polia[$pole] = [
            'vaha'      => $cfg['vaha'],
            'hodnoty'   => $hlasy,
            'zhodnych'  => reset($hlasy),
            'z'         => count($odpovede),
            'rozporne'  => count($hlasy) > 1,
        ];
    }

    return [
        'polia'     => $polia,
        'modelov'   => count($odpovede),
        'zhodnych'  => count(array_filter($odpovede, fn($o) => ai_je_true($o['agrees']))),
        'hranica'   => AI_ZHODA_HRANICA,
    ];
}
