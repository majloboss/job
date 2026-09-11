-- ============================================================
-- Migration 013: test modelov na tazenie udajov a vhodnost
--
-- Inspirovane livescore testom z BetClubu, ale s dvoma rozdielmi:
--   - testuju sa DVE ULOHY nad tym istym inzeratom (tazenie + vhodnost),
--     takze sa da porovnat, ci model dobry na jedno je dobry aj na druhe
--   - vysledky pribudaju PRIEBEZNE, takze obrazovka ukazuje postup aj po
--     refreshi a test bezi dalej, aj ked sa zavrie prehliadac
--
-- Prehlad behu je v job.test_behy, jednotlive volania v job.test_vysledky.
-- Oddelene zamerne: beh ma svoj rozpocet a stav, volania su riadky, ktorych
-- su stovky.
-- ============================================================

BEGIN;

-- ============================================================
-- JOB.TEST_BEHY — jeden beh testu
-- ============================================================
CREATE TABLE IF NOT EXISTS job.test_behy (
    id              BIGSERIAL PRIMARY KEY,
    user_id         INT       REFERENCES admin.users(id) ON DELETE SET NULL,

    -- Dva inzeraty, na ktorych sa testuje. Zamerne dva: jeden moze byt
    -- netypicky a sam o sebe by zavadzal.
    url1            VARCHAR(500) NOT NULL,
    url2            VARCHAR(500),

    -- Stiahnuty obsah. Uklada sa RAZ a vsetky modely dostanu presne ten
    -- isty vstup — inak by sa porovnavali odpovede na rozne zadania.
    text1           TEXT,
    text2           TEXT,
    html1           TEXT,
    html2           TEXT,
    nazov1          VARCHAR(300),
    nazov2          VARCHAR(300),

    -- Podklady pre ulohu 'vhodnost'
    cv_text         TEXT,
    prefs_text      TEXT,

    -- Rozsah testu
    max_modelov     INT          NOT NULL DEFAULT 20,
    cenovy_strop_1m NUMERIC(10,4) NOT NULL DEFAULT 1.0,   -- USD za 1M tokenov
    rozpocet_usd    NUMERIC(8,4) NOT NULL DEFAULT 1.0,    -- kedy zastavit

    status          VARCHAR(20)  NOT NULL DEFAULT 'running',
                    -- 'running' | 'done' | 'stopped_budget' | 'cancelled' | 'failed'
    zastavene_dovod TEXT,

    modelov_spolu   INT NOT NULL DEFAULT 0,
    volani_spolu    INT NOT NULL DEFAULT 0,
    volani_ok       INT NOT NULL DEFAULT 0,
    tokenov_spolu   BIGINT NOT NULL DEFAULT 0,
    cena_usd        NUMERIC(10,6) NOT NULL DEFAULT 0,

    started_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    finished_at     TIMESTAMP,
    -- Kedy naposledy proces nieco zapisal. Ked dlho mlci, je mrtvy.
    heartbeat_at    TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS test_behy_stav_idx ON job.test_behy (status, started_at DESC);

COMMENT ON TABLE job.test_behy IS
    'Beh testu modelov: dva inzeraty, dve ulohy, rozpocet a stav';
COMMENT ON COLUMN job.test_behy.heartbeat_at IS
    'Kedy proces naposledy zapisal — podla toho sa pozna zaseknuty beh';

-- ============================================================
-- JOB.TEST_VYSLEDKY — jedno volanie modelu
--
-- Riadok = (beh, inzerat, model, uloha). Vysledky pribudaju priebezne,
-- takze obrazovka ukazuje postup aj pocas behu.
-- ============================================================
CREATE TABLE IF NOT EXISTS job.test_vysledky (
    id           BIGSERIAL PRIMARY KEY,
    beh_id       BIGINT   NOT NULL REFERENCES job.test_behy(id) ON DELETE CASCADE,

    inzerat      SMALLINT NOT NULL,          -- 1 alebo 2
    uloha        VARCHAR(10) NOT NULL,       -- 'parse' | 'vhodnost'

    model_id     VARCHAR(150) NOT NULL,
    model_db_id  INT      REFERENCES job.ai_models(id) ON DELETE SET NULL,
    je_free      BOOLEAN  NOT NULL DEFAULT FALSE,
    cena_1m      NUMERIC(10,4),              -- cena modelu za 1M tokenov v case testu

    -- ---------- vysledok ulohy 'parse' ----------
    nazov        VARCHAR(300),
    firma        VARCHAR(255),
    datum_zverejnenia      DATE,
    datum_zverejnenia_text VARCHAR(100),
    sumar        TEXT,                       -- max 10 viet
    -- Original inzeratu v peknej strukture + slovenska verzia. Ked je
    -- original po slovensky, obe su rovnake — tak sa zobrazenie nemusi
    -- rozhodovat, ktory stlpec vziat.
    html_original TEXT,
    html_sk       TEXT,
    orig_lang    VARCHAR(5),

    mzda_text    VARCHAR(200),
    mzda_min     NUMERIC(10,2),
    mzda_max     NUMERIC(10,2),
    mzda_mena    VARCHAR(3),
    mzda_obdobie VARCHAR(10),

    nastup       VARCHAR(100),
    uvazok       VARCHAR(30),
    uvazky       TEXT[],
    -- Miesto sa deli: mesto zvlast, zvysok popisu zvlast. Filtrovat sa da
    -- len podla mesta, ale ulica a poznamka ("obcasna praca z domu") su
    -- pre cloveka podstatne.
    mesto        VARCHAR(120),
    lokalita_zvysok TEXT,

    -- ---------- vysledok ulohy 'vhodnost' ----------
    skore        INT,
    zaradenie    VARCHAR(20),                -- vhodne | menej_vhodne | nevhodne
    hodnotenie   TEXT,                       -- slovny popis vhodnosti
    pre_argumenty  TEXT,
    proti_argumenty TEXT,

    -- ---------- metrika volania ----------
    status       VARCHAR(20) NOT NULL DEFAULT 'ok',
    chyba        TEXT,
    surova_odpoved TEXT,                     -- uklada sa len pri chybe
    prompt_tokens     INT,
    completion_tokens INT,
    total_tokens      INT,
    cena_usd     NUMERIC(10,6) NOT NULL DEFAULT 0,
    trvanie_ms   INT,

    created_at   TIMESTAMP NOT NULL DEFAULT NOW(),

    -- Ten isty model nema byt v jednom behu na tej istej ulohe dvakrat.
    UNIQUE (beh_id, inzerat, uloha, model_id)
);

CREATE INDEX IF NOT EXISTS test_vysledky_beh_idx
    ON job.test_vysledky (beh_id, uloha, inzerat, created_at);

COMMENT ON TABLE job.test_vysledky IS
    'Jedno volanie modelu v teste: riadok = beh + inzerat + model + uloha';
COMMENT ON COLUMN job.test_vysledky.mesto IS
    'Mesto oddelene od zvysku lokality — filtrovat sa da len podla neho';

INSERT INTO admin.schema_versions (version, description)
VALUES (13, 'Test modelov: job.test_behy + job.test_vysledky (tazenie aj vhodnost)')
ON CONFLICT (version) DO NOTHING;

COMMIT;
