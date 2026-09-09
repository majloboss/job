-- ============================================================
-- Migration 007: inzerat moze ponukat VIAC typov uvazku naraz
--
-- PRECO: pri teste promptu v2 na inzerate O5355999 sa styri modely rozisli
-- na employment_type — traja vratili 'tpp', jeden 'dohoda'. Kontrola
-- povodnej stranky ukazala, ze inzerat uvadza:
--
--     Druh pracovneho pomeru: plny uvazok, na dohodu (brigady)
--
-- Obidve moznosti naraz. Modely teda nehalucinovali — chyba bola v nasom
-- datovom modeli, ktory pripustal jedinu hodnotu. Zhoda by sa na tomto poli
-- nedala dosiahnut nikdy, lebo spravna odpoved je "oboje".
--
-- Profesia.sk takto inzeruje bezne (typicky brigady a gastro). Preto
-- pribuda pole employment_types; povodny stlpec employment_type zostava
-- ako HLAVNY typ kvoli existujucim filtrom a indexom.
-- ============================================================

BEGIN;

-- Vsetky ponukane typy uvazku. Prvy je hlavny (zhodny s employment_type).
ALTER TABLE job.offers
    ADD COLUMN IF NOT EXISTS employment_types TEXT[];

COMMENT ON COLUMN job.offers.employment_types IS
    'Vsetky ponukane typy uvazku; inzerat moze ponukat napr. TPP aj dohodu naraz';
COMMENT ON COLUMN job.offers.employment_type IS
    'Hlavny (prvy) typ uvazku — pre filtre; uplny zoznam je v employment_types';

-- Filter "ukaz mi dohody" musi najst aj inzerat, kde je dohoda az druha
-- v poradi. Bez indexu by to bol prechod celej tabulky.
CREATE INDEX IF NOT EXISTS offers_employment_types_idx
    ON job.offers USING GIN (employment_types);

-- Doterajsie riadky: hlavny typ sa stava jednoprvkovym zoznamom.
UPDATE job.offers
   SET employment_types = ARRAY[employment_type]
 WHERE employment_type IS NOT NULL AND employment_types IS NULL;

-- ------------------------------------------------------------
-- Prompt verzia 3 — vracia zoznam uvazkov namiesto jedneho
-- ------------------------------------------------------------
UPDATE job.ai_prompts SET is_active = FALSE
 WHERE code = 'offer_parse' AND version = 2;

INSERT INTO job.ai_prompts (code, version, template, note, is_active)
SELECT 'offer_parse', 3,
       -- Zmena je bodova, preto sa vychadza z verzie 2 a nahradzaju sa len
       -- tri miesta: ukazkovy JSON, povolene hodnoty a pravidlo.
       replace(replace(replace(template,
           '  "employment_type": "tpp",',
           '  "employment_type": "tpp",' || chr(10) ||
           '  "employment_types": ["tpp", "dohoda"],'),

           '- employment_type: tpp, dohoda, zivnost, brigada, internship, alebo null',
           '- employment_type: hlavny (prvy) typ z employment_types, alebo null' || chr(10) ||
           '- employment_types: zoznam z hodnot tpp, dohoda, zivnost, brigada,' || chr(10) ||
           '  internship; prazdny zoznam ked uvazok nie je uvedeny'),

           '- Co v inzerate NIE JE, daj null alebo prazdne pole. NIC SI NEVYMYSLAJ' || chr(10) ||
           '  a NEHADAJ. Ked uvazok nie je uvedeny, employment_type je null — nie tpp.',
           '- Co v inzerate NIE JE, daj null alebo prazdne pole. NIC SI NEVYMYSLAJ' || chr(10) ||
           '  a NEHADAJ. Ked uvazok nie je uvedeny, employment_types je [] a' || chr(10) ||
           '  employment_type null — nie tpp.' || chr(10) ||
           '- Inzerat casto ponuka VIAC uvazkov naraz ("plny uvazok, na dohodu").' || chr(10) ||
           '  Vtedy uved VSETKY do employment_types v poradi, v akom su v inzerate,' || chr(10) ||
           '  a prvy z nich zopakuj v employment_type.'),
       'Verzia 3 — inzerat moze ponukat viac typov uvazku naraz (zistene testom)',
       TRUE
  FROM job.ai_prompts WHERE code = 'offer_parse' AND version = 2
ON CONFLICT (code, version) DO NOTHING;

INSERT INTO admin.schema_versions (version, description)
VALUES (7, 'Inzerat moze ponukat viac typov uvazku: job.offers.employment_types + prompt v3')
ON CONFLICT (version) DO NOTHING;

COMMIT;
