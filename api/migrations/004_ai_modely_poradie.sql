-- ============================================================
-- Migration 004: ciselnik modelov s cenami + poradie nahradnych modelov
--
-- Doteraz sa model do produkcie bral z konstanty OPENROUTER_MODEL v
-- api/config/openrouter.php. To ma dve chyby:
--   1. zmena modelu = zmena suboru na serveri, nie klik v aplikacii
--   2. ked model prestane fungovat (vycerpany denny limit, zrusenie
--      bezplatnej varianty), aplikacia stoji, kym si toho niekto nevsimne
--
-- Rovnaky problem uz riesi BetClub (migracie 079, 080, 082). Preberame
-- odtial osvedceny model: ciselnik s cenami + zoznam modelov v PORADI,
-- v ktorom sa maju skusat.
--
-- ROZDIEL OPROTI BETCLUBU: tam je poradie viazane na sutaz. Tu na dvojicu
-- (ucel, portal), lebo aplikacia ziskava udaje v dvoch krokoch:
--   'parse' — vytazenie udajov z inzeratu pri zbere (moze sa lisit portal
--             od portalu, kazdy ma ine HTML)
--   'eval'  — posudenie vhodnosti pre pouzivatela (na portale nezavisi)
--
-- Zadanie: ZADANIE_JOB.md, kapitola 5b
-- ============================================================

BEGIN;

-- ============================================================
-- JOB.AI_MODELS — rozsirenie o ceny a dostupnost
--
-- Tabulka vznikla v migracii 002 pre laboratorium (len bezplatne modely).
-- Teraz z nej robime plnohodnotny ciselnik vratane platenych.
-- ============================================================

ALTER TABLE job.ai_models
    ADD COLUMN IF NOT EXISTS provider VARCHAR(30) NOT NULL DEFAULT 'openrouter';

-- Ceny su za MILION tokenov, tak ako ich uvadzaju cenniky. Ulozenie za
-- jeden token by pri rucnej kontrole zvadzalo k chybam o rady.
ALTER TABLE job.ai_models
    ADD COLUMN IF NOT EXISTS price_input_1m  NUMERIC(10,4);
ALTER TABLE job.ai_models
    ADD COLUMN IF NOT EXISTS price_output_1m NUMERIC(10,4);

-- Preco je model vyradeny. Odlisene od last_error: chyba moze byt docasna
-- (rate limit), unavailable_reason znamena trvalu prekazku.
ALTER TABLE job.ai_models
    ADD COLUMN IF NOT EXISTS unavailable_reason TEXT;

-- Uspesnost z historie testov aj ostrej prevadzky (0-100 %).
ALTER TABLE job.ai_models
    ADD COLUMN IF NOT EXISTS success_rate NUMERIC(5,2);

-- Ako casto sa model zhodol s vacsinou ostatnych. Pri tazani udajov je to
-- dolezitejsie nez uspesnost: model, ktory vrati mzdu 1600 EUR ked ostatnych
-- osem vrati 2400, odpovedal "uspesne" a napriek tomu je nepouzitelny.
ALTER TABLE job.ai_models
    ADD COLUMN IF NOT EXISTS agree_rate NUMERIC(5,2);

ALTER TABLE job.ai_models
    ADD COLUMN IF NOT EXISTS avg_tokens INT;

-- Len textove modely. Hudobne a obrazkove (lyria, veo) maju v modalitach
-- aj 'text', ale za tokeny nic nestoja — plati sa za sekundy zvuku. V poradi
-- podla ceny by vysli ako najlacnejsie a automaticky vyber by siahol po nich.
ALTER TABLE job.ai_models
    ADD COLUMN IF NOT EXISTS is_text_only BOOLEAN NOT NULL DEFAULT TRUE;

COMMENT ON COLUMN job.ai_models.price_input_1m IS
    'USD za 1 milion vstupnych tokenov; NULL pri bezplatnom modeli';
COMMENT ON COLUMN job.ai_models.agree_rate IS
    'Ako casto sa model zhodol s vacsinou ostatnych na tom istom inzerate';

CREATE INDEX IF NOT EXISTS ai_models_vyber_idx
    ON job.ai_models (is_enabled, is_free, success_rate DESC NULLS LAST);

-- is_default nahradza tabulka job.ai_poradie: model uz nie je jeden, ale
-- zoznam. Stlpec sa nemaze (drzi doterajsiu volbu), len prestava platit
-- jedinecnost — inak by sa nedal nastavit vitaz pre kazdy ucel zvlast.
DROP INDEX IF EXISTS job.ai_models_default_idx;
COMMENT ON COLUMN job.ai_models.is_default IS
    'Zastarale — vyber modelu riesi job.ai_poradie. Ponechane pre historiu.';

-- ============================================================
-- JOB.AI_PORADIE — zoznam modelov v poradi, v akom sa skusaju
--
-- Ked prvy prestane fungovat (vycerpany denny limit poskytovatela,
-- vypadok), aplikacia sa sama prepne na dalsi. Typicke poradie:
-- dva-tri bezplatne, za nimi lacny plateny ako poistka.
--
-- source_id NULL = plati pre vsetky portaly. Konkretny portal ma prednost,
-- takze sa da pre profesia.sk nastavit ine poradie nez pre zvysok.
-- Pri ucele 'eval' je source_id vzdy NULL — posudenie vhodnosti sa robi
-- nad uz vytazenym inzeratom a je jedno, odkial prisiel.
-- ============================================================
CREATE TABLE IF NOT EXISTS job.ai_poradie (
    id          SERIAL PRIMARY KEY,
    ucel        VARCHAR(10) NOT NULL,      -- 'parse' | 'eval' | 'translate'
    source_id   INT         REFERENCES job.sources(id) ON DELETE CASCADE,
    poradie     INT         NOT NULL,      -- 1 = prvy na rade
    model_id    INT         NOT NULL REFERENCES job.ai_models(id) ON DELETE CASCADE,
    is_enabled  BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMP   NOT NULL DEFAULT NOW(),
    CONSTRAINT ai_poradie_ucel_chk CHECK (ucel IN ('parse', 'eval', 'translate'))
);

-- Jedinecnost cez COALESCE: v UNIQUE indexe sa dva NULL nepovazuju za
-- zhodne, takze obycajne UNIQUE (ucel, source_id, poradie) by pripustilo
-- dva riadky s rovnakym poradim pre "vsetky portaly".
CREATE UNIQUE INDEX IF NOT EXISTS ai_poradie_miesto_idx
    ON job.ai_poradie (ucel, COALESCE(source_id, 0), poradie);
CREATE UNIQUE INDEX IF NOT EXISTS ai_poradie_model_idx
    ON job.ai_poradie (ucel, COALESCE(source_id, 0), model_id);

COMMENT ON TABLE job.ai_poradie IS
    'Poradie modelov na ucel a portal: ked jeden zlyha, prejde sa na dalsi';

-- ============================================================
-- JOB.AI_STAV — kde v poradi sa prave nachadzame
--
-- Bez tohto by sa aplikacia po prepnuti vracala k prvemu modelu, ktory
-- prave zlyhal. Riadok na (ucel, portal, den) — kazdy den sa zacina
-- odznova od prveho modelu, lebo denne limity sa o polnoci obnovuju.
-- ============================================================
CREATE TABLE IF NOT EXISTS job.ai_stav (
    ucel              VARCHAR(10) NOT NULL,
    source_id         INT         NOT NULL DEFAULT 0,   -- 0 = vsetky portaly
    den               DATE        NOT NULL DEFAULT CURRENT_DATE,

    poradie_index     INT         NOT NULL DEFAULT 1,   -- kolky model z poradia bezi
    model_id          INT         REFERENCES job.ai_models(id) ON DELETE SET NULL,

    is_enabled        BOOLEAN     NOT NULL DEFAULT TRUE,
    disabled_reason   TEXT,

    -- 'auto'   = poradie
    -- 'admin'  = nastavil clovek
    -- 'budget' = prepnute po prekroceni 80 % stropu
    -- 'fail'   = prepnute po opakovanych zlyhaniach
    chosen_by         VARCHAR(10) NOT NULL DEFAULT 'auto',
    chosen_at         TIMESTAMP   NOT NULL DEFAULT NOW(),
    chosen_by_user_id INT         REFERENCES admin.users(id) ON DELETE SET NULL,

    daily_budget_usd  NUMERIC(8,4) NOT NULL DEFAULT 1.0,

    -- kolko sa dnes minulo; drzi sa tu, aby sa nemuselo pri kazdom volani
    -- scitavat cez cely log
    spent_usd         NUMERIC(10,6) NOT NULL DEFAULT 0,
    calls_count       INT           NOT NULL DEFAULT 0,
    fails_in_row      INT           NOT NULL DEFAULT 0,
    prepnuti_dnes     INT           NOT NULL DEFAULT 0,

    -- ktore upozornenia uz odisli, aby sa neposielali opakovane
    warned_80_at      TIMESTAMP,
    stopped_150_at    TIMESTAMP,

    PRIMARY KEY (ucel, source_id, den)
);

CREATE INDEX IF NOT EXISTS ai_stav_den_idx ON job.ai_stav (den DESC);

COMMENT ON TABLE job.ai_stav IS
    'Ktory model z poradia prave bezi, kolko dnes minul a ci nie je vypnuty';
COMMENT ON COLUMN job.ai_stav.source_id IS
    '0 = plati pre vsetky portaly (nie NULL, aby fungoval zlozeny primarny kluc)';

-- ============================================================
-- JOB.AI_EVALUATIONS — doplnenie o ucel a naklady
--
-- Tabulka vznikla v migracii 002 len na posudzovanie vhodnosti. Teraz cez
-- nu ide aj tazenie udajov pri zbere, preto treba rozlisit ucel a ratat
-- naklady.
-- ============================================================
ALTER TABLE job.ai_evaluations
    ADD COLUMN IF NOT EXISTS ucel VARCHAR(10) NOT NULL DEFAULT 'eval';
ALTER TABLE job.ai_evaluations
    ADD COLUMN IF NOT EXISTS source_id INT REFERENCES job.sources(id) ON DELETE SET NULL;

-- Cena sa uklada v case volania — cenniky sa menia a spatny prepocet zo
-- sucasneho cennika by skresloval historiu.
ALTER TABLE job.ai_evaluations
    ADD COLUMN IF NOT EXISTS cost_usd NUMERIC(10,6);

-- 'live' = ostre volanie, 'test' = laboratorium. Testovacie volania sa
-- nesmu ratat do denneho stropu ostrej prevadzky.
ALTER TABLE job.ai_evaluations
    ADD COLUMN IF NOT EXISTS call_type VARCHAR(10) NOT NULL DEFAULT 'live';

-- Zhoda s vacsinou v ramci jedneho behu laboratoria.
-- NULL = zhoda sa este nevyhodnotila.
ALTER TABLE job.ai_evaluations
    ADD COLUMN IF NOT EXISTS agrees BOOLEAN;

CREATE INDEX IF NOT EXISTS ai_evaluations_naklady_idx
    ON job.ai_evaluations (ucel, created_at DESC)
    WHERE call_type = 'live';

-- Doterajsie riadky laboratoria su testy, nie ostra prevadzka.
UPDATE job.ai_evaluations SET call_type = 'test'
 WHERE lab_run_id IS NOT NULL AND call_type = 'live';

-- ============================================================
-- JOB.AI_LAB_RUNS — doplnenie o ucel
--
-- Laboratorium teraz porovnava modely aj na tazani udajov, nielen na
-- posudzovani vhodnosti.
-- ============================================================
ALTER TABLE job.ai_lab_runs
    ADD COLUMN IF NOT EXISTS ucel VARCHAR(10) NOT NULL DEFAULT 'eval';
ALTER TABLE job.ai_lab_runs
    ADD COLUMN IF NOT EXISTS source_id INT REFERENCES job.sources(id) ON DELETE SET NULL;

INSERT INTO admin.schema_versions (version, description)
VALUES (4, 'Ciselnik modelov s cenami, poradie nahradnych modelov na ucel a portal, naklady')
ON CONFLICT (version) DO NOTHING;

COMMIT;
