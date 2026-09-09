-- ============================================================
-- Migration 009: naklady a metrika — zvlast za zber, zvlast za vhodnost
--
-- Inspirovane admin.livescore_log z BetClubu: jeden riadok = jedno volanie
-- modelu, so vsetkym, co treba na kontrolu nakladov.
--
-- PRECO DVE SADY: aplikacia vola model v dvoch krokoch a kazdy sa spravaja
-- inak, co sa tyka nakladov:
--
--   'parse' — vytazenie udajov, bezi RAZ NA INZERAT.
--             Naklady rastu s poctom stiahnutych inzeratov.
--   'eval'  — posudenie vhodnosti, bezi RAZ NA INZERAT A POUZIVATELA.
--             Naklady rastu s poctom inzeratov NASOBENYM poctom userov.
--
-- Pri 10 userech je 'eval' desatnasobne drahsi nez 'parse' pri rovnakom
-- pocte inzeratov. Preto sa nesmu scitavat do jedneho cisla — treba vediet,
-- kolko stoji naplnenie DB a kolko vyhladavanie vhodnosti nad nou.
--
-- Vacsina udajov uz v job.ai_evaluations je (model_id, tokeny, cost_usd,
-- took_ms, created_at). Chyba CAS STIAHNUTIA inzeratu — created_at je cas
-- POSUDENIA modelom, nie cas stiahnutia zo stranky.
-- ============================================================

BEGIN;

-- ------------------------------------------------------------
-- JOB.OFFERS — metrika stiahnutia
--
-- detail_fetched_at (kedy sa stiahol detail) uz existuje z migracie 001.
-- Doplna sa, ako dlho stahovanie trvalo a kolko HTML prislo.
-- ------------------------------------------------------------
ALTER TABLE job.offers
    ADD COLUMN IF NOT EXISTS fetch_ms INT;
ALTER TABLE job.offers
    ADD COLUMN IF NOT EXISTS fetch_bytes INT;

-- Ktory beh zberu inzerat priniesol — na priradenie nakladov k behu.
ALTER TABLE job.offers
    ADD COLUMN IF NOT EXISTS first_run_id BIGINT REFERENCES job.scrape_runs(id) ON DELETE SET NULL;

COMMENT ON COLUMN job.offers.fetch_ms IS
    'Ako dlho trvalo stiahnutie detailu zo stranky (bez tazenia modelom)';

-- ------------------------------------------------------------
-- Indexy na vyhladavanie nakladov
-- ------------------------------------------------------------
CREATE INDEX IF NOT EXISTS ai_evaluations_offer_idx
    ON job.ai_evaluations (offer_id, ucel, created_at DESC);

-- Prehlad "kolko ma stoji vhodnost pre usera X" sa pyta cez user_id.
CREATE INDEX IF NOT EXISTS ai_evaluations_user_idx
    ON job.ai_evaluations (user_id, ucel, created_at DESC)
    WHERE user_id IS NOT NULL;

-- ------------------------------------------------------------
-- JOB.SCRAPE_RUNS — suhrn nakladov behu
-- ------------------------------------------------------------
ALTER TABLE job.scrape_runs
    ADD COLUMN IF NOT EXISTS cost_usd NUMERIC(10,6) NOT NULL DEFAULT 0;
ALTER TABLE job.scrape_runs
    ADD COLUMN IF NOT EXISTS tokens_total INT NOT NULL DEFAULT 0;
ALTER TABLE job.scrape_runs
    ADD COLUMN IF NOT EXISTS parsed_count INT NOT NULL DEFAULT 0;

-- ============================================================
-- POHLAD 1: naklady na ZBER (jeden riadok na inzerat)
--
-- Kedy sa inzerat stiahol, ako dlho to trvalo, ktory model ho vytazil,
-- kolko tokenov a kolko dolarov to stalo.
--
-- LEFT JOIN LATERAL je zamerny: inzerat sa ma zobrazit aj vtedy, ked
-- tazenie este nebezalo alebo zlyhalo — prave taky riadok clovek hlada.
-- LATERAL berie NAJNOVSIE vytazenie; inzerat sa moze tazit viackrat
-- (opakovanie po chybe, novy prompt) a v prehlade ma byt posledny stav.
-- ============================================================
CREATE OR REPLACE VIEW job.v_naklady_zber AS
SELECT
    o.id                    AS offer_id,
    o.external_id,
    o.url,
    o.title,
    o.company_name_raw,
    s.name                  AS portal,

    -- stiahnutie zo stranky
    o.created_at            AS stiahnuty_o,
    o.detail_fetched_at     AS detail_stiahnuty_o,
    o.fetch_ms,
    o.fetch_bytes,
    o.first_run_id,

    -- vytazenie modelom (posledne)
    e.model_id,
    e.created_at            AS vytazeny_o,
    e.took_ms               AS model_ms,
    e.prompt_tokens,
    e.completion_tokens,
    e.total_tokens,
    e.cost_usd,
    e.status                AS model_status,
    e.error                 AS model_error,
    e.id                    AS evaluation_id,

    -- kolkokrat sa uz tazilo; viac nez raz znamena opakovanie po chybe
    (SELECT COUNT(*) FROM job.ai_evaluations x
      WHERE x.offer_id = o.id AND x.ucel = 'parse')  AS pokusov,
    (SELECT COALESCE(SUM(x.cost_usd), 0) FROM job.ai_evaluations x
      WHERE x.offer_id = o.id AND x.ucel = 'parse')  AS cost_usd_spolu
FROM job.offers o
LEFT JOIN job.sources s ON s.id = o.source_id
LEFT JOIN LATERAL (
    SELECT * FROM job.ai_evaluations a
     WHERE a.offer_id = o.id AND a.ucel = 'parse'
     ORDER BY a.created_at DESC
     LIMIT 1
) e ON TRUE;

COMMENT ON VIEW job.v_naklady_zber IS
    'Naklady na naplnenie DB: jeden riadok na inzerat, posledne vytazenie';

-- ============================================================
-- POHLAD 2: naklady na VHODNOST (jeden riadok na inzerat a pouzivatela)
--
-- Rovnaka sada udajov ako pri zbere, ale kluc je dvojica (inzerat, user) —
-- ten isty inzerat sa posudzuje pre kazdeho pouzivatela zvlast.
-- ============================================================
CREATE OR REPLACE VIEW job.v_naklady_vhodnost AS
SELECT
    e.offer_id,
    e.user_id,
    u.username,
    o.external_id,
    o.title,
    o.company_name_raw,
    s.name                  AS portal,

    -- kedy sa inzerat dostal do DB (na porovnanie s casom posudenia)
    o.created_at            AS stiahnuty_o,

    -- posudenie modelom
    e.model_id,
    e.created_at            AS posudeny_o,
    e.took_ms               AS model_ms,
    e.prompt_tokens,
    e.completion_tokens,
    e.total_tokens,
    e.cost_usd,
    e.status                AS model_status,
    e.error                 AS model_error,
    e.score,
    e.bucket,
    e.id                    AS evaluation_id
FROM job.ai_evaluations e
JOIN job.offers o        ON o.id = e.offer_id
LEFT JOIN job.sources s  ON s.id = o.source_id
LEFT JOIN admin.users u  ON u.id = e.user_id
WHERE e.ucel = 'eval' AND e.offer_id IS NOT NULL;

COMMENT ON VIEW job.v_naklady_vhodnost IS
    'Naklady na vyhodnocovanie vhodnosti: riadok na dvojicu inzerat + pouzivatel';

-- ============================================================
-- POHLAD 3: POROVNANIE — kolko stoji naplnenie DB vs. vhodnost nad nou
--
-- Hlavna otazka pri rozhodovani o modeloch. Zamerne po DNOCH: len tak je
-- vidiet, ci naklady rastu s poctom inzeratov alebo s poctom pouzivatelov.
--
-- 'na_jednotku' je priemerna cena jedneho volania — to je cislo, ktore sa
-- da porovnavat medzi ucelmi aj medzi modelmi. Celkova suma sama o sebe
-- nehovori nic, lebo 'eval' bezi nasobne castejsie.
-- ============================================================
CREATE OR REPLACE VIEW job.v_naklady_porovnanie AS
SELECT
    created_at::date        AS den,
    ucel,
    call_type,
    model_id,
    COUNT(*)                                        AS volani,
    COUNT(*) FILTER (WHERE status = 'ok')           AS uspesnych,
    COUNT(DISTINCT offer_id)                        AS inzeratov,
    COUNT(DISTINCT user_id) FILTER (WHERE user_id IS NOT NULL) AS pouzivatelov,
    SUM(total_tokens)                               AS tokenov,
    ROUND(AVG(total_tokens))                        AS tokenov_priemer,
    SUM(cost_usd)                                   AS cena_spolu,
    -- priemer na jedno volanie: jedine cislo porovnatelne medzi ucelmi
    ROUND(AVG(cost_usd), 8)                         AS cena_na_volanie,
    ROUND(AVG(took_ms))                             AS ms_priemer
FROM job.ai_evaluations
WHERE call_type = 'live'          -- testy z laboratoria sa do nakladov neratuju
GROUP BY created_at::date, ucel, call_type, model_id;

COMMENT ON VIEW job.v_naklady_porovnanie IS
    'Porovnanie nakladov zber vs. vhodnost po dnoch a modeloch (len ostra prevadzka)';

INSERT INTO admin.schema_versions (version, description)
VALUES (9, 'Naklady na inzerat: metrika stiahnutia + pohlady zber / vhodnost / porovnanie')
ON CONFLICT (version) DO NOTHING;

COMMIT;
