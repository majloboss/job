-- ============================================================
-- JOB - Migration 001: Initial schema
-- DB: DB-JOB (PostgreSQL)
-- Spusti ako: psql -h [host] -p [port] -U [user] -d DB-JOB -f 001_init.sql
--
-- Schemy:
--   admin.*  user manazment (prevzaty z BetClub, 1:1 kompatibilny)
--   job.*    ciselniky, inzeraty, scraping, preferencie a vhodnost
-- ============================================================

CREATE SCHEMA IF NOT EXISTS admin;
CREATE SCHEMA IF NOT EXISTS job;

-- ============================================================
-- ADMIN.SCHEMA_VERSIONS - sledovanie vsetkych migracii
-- ============================================================
CREATE TABLE IF NOT EXISTS admin.schema_versions (
    version     INT          PRIMARY KEY,
    description VARCHAR(200) NOT NULL,
    applied_at  TIMESTAMP    NOT NULL DEFAULT NOW()
);

-- ============================================================
-- ADMIN.USERS
-- Prevzate z BetClub vratane token_version (okamzity logout po zmene hesla).
-- home_location_id = odkial sa pocita vzdialenost k ponukam.
-- ============================================================
CREATE TABLE IF NOT EXISTS admin.users (
    id                SERIAL PRIMARY KEY,
    username          VARCHAR(50)  NOT NULL UNIQUE,
    password          VARCHAR(255) NOT NULL,               -- bcrypt hash
    username_changed  BOOLEAN      NOT NULL DEFAULT FALSE, -- username mozno zmenit iba raz
    first_name        VARCHAR(100),
    last_name         VARCHAR(100),
    email             VARCHAR(150),
    phone             VARCHAR(30),
    avatar            VARCHAR(255),
    role              VARCHAR(10)  NOT NULL DEFAULT 'user',-- 'user' | 'admin'
    is_active         BOOLEAN      NOT NULL DEFAULT FALSE,
    token_version     INT          NOT NULL DEFAULT 1,     -- inkrement = invalidacia vsetkych JWT
    fcm_token         VARCHAR(255),
    web_push_sub      TEXT,
    -- domovska lokalita (FK sa doplna nizsie, po vytvoreni job.locations)
    home_location_id  INT,
    home_lat          NUMERIC(9,6),                        -- presna adresa, ak ju user zada
    home_lon          NUMERIC(9,6),
    created_at        TIMESTAMP    NOT NULL DEFAULT NOW()
);

-- ============================================================
-- ADMIN.INVITES - registracia iba na pozvanie
-- ============================================================
CREATE SEQUENCE IF NOT EXISTS admin.seq_invite START 1;

CREATE TABLE IF NOT EXISTS admin.invites (
    id            INT          PRIMARY KEY DEFAULT nextval('admin.seq_invite'),
    invite_token  VARCHAR(100) NOT NULL UNIQUE,
    sent_to       VARCHAR(150),
    created_by    INT          REFERENCES admin.users(id),
    created_at    TIMESTAMP    NOT NULL DEFAULT NOW(),
    used_at       TIMESTAMP,
    cancelled_at  TIMESTAMP,
    user_id       INT          REFERENCES admin.users(id)
);

-- ============================================================
-- ADMIN.PASSWORD_RESET_TOKENS
-- ============================================================
CREATE TABLE IF NOT EXISTS admin.password_reset_tokens (
    id         SERIAL PRIMARY KEY,
    user_id    INT          NOT NULL REFERENCES admin.users(id) ON DELETE CASCADE,
    token      VARCHAR(64)  NOT NULL UNIQUE,
    expires_at TIMESTAMP    NOT NULL,
    used_at    TIMESTAMP,
    created_at TIMESTAMP    NOT NULL DEFAULT NOW()
);

-- ============================================================
-- ADMIN.LOGIN_LOGS - audit prihlaseni
-- ============================================================
CREATE TABLE IF NOT EXISTS admin.login_logs (
    id         SERIAL PRIMARY KEY,
    user_id    INT REFERENCES admin.users(id) ON DELETE SET NULL,
    username   VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    env        VARCHAR(10) NOT NULL DEFAULT 'main',        -- 'main' | 'dev'
    logged_at  TIMESTAMP   NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS login_logs_logged_at_idx ON admin.login_logs(logged_at DESC);

-- ============================================================
-- ADMIN.USER_PUSH_SUBSCRIPTIONS - viac zariadeni na pouzivatela
-- ============================================================
CREATE TABLE IF NOT EXISTS admin.user_push_subscriptions (
    id         SERIAL PRIMARY KEY,
    user_id    INT  NOT NULL REFERENCES admin.users(id) ON DELETE CASCADE,
    endpoint   TEXT NOT NULL,
    p256dh     TEXT NOT NULL,
    auth       TEXT NOT NULL,
    user_agent TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, endpoint)
);

-- ============================================================
-- ADMIN.NOTIFICATION_SETTINGS (per user, per typ)
-- notif_type: new_matches | daily_digest | scrape_failed
-- ============================================================
CREATE TABLE IF NOT EXISTS admin.notification_settings (
    user_id        INT         REFERENCES admin.users(id) ON DELETE CASCADE NOT NULL,
    notif_type     VARCHAR(30) NOT NULL,
    push_enabled   BOOLEAN     NOT NULL DEFAULT TRUE,
    email_enabled  BOOLEAN     NOT NULL DEFAULT TRUE,
    PRIMARY KEY (user_id, notif_type)
);


-- ############################################################
-- CISELNIKY
-- ############################################################

-- ============================================================
-- JOB.SOURCES - ciselnik zdrojovych portalov
-- ============================================================
CREATE TABLE IF NOT EXISTS job.sources (
    id                       SERIAL PRIMARY KEY,
    code                     VARCHAR(30)  NOT NULL UNIQUE,  -- 'profesia'
    name                     VARCHAR(100) NOT NULL,
    base_url                 VARCHAR(255) NOT NULL,
    country                  VARCHAR(2)   NOT NULL DEFAULT 'SK',
    is_active                BOOLEAN      NOT NULL DEFAULT TRUE,
    scrape_interval_minutes  INT          NOT NULL DEFAULT 120,
    request_delay_ms         INT          NOT NULL DEFAULT 1500, -- rate limit medzi requestmi
    default_period_days      INT          NOT NULL DEFAULT 1,    -- co dotiahnut pri beznom behu
    last_scraped_at          TIMESTAMP,
    notes                    TEXT,
    created_at               TIMESTAMP    NOT NULL DEFAULT NOW()
);

INSERT INTO job.sources (code, name, base_url) VALUES
    ('profesia',     'Profesia.sk',    'https://www.profesia.sk'),
    ('pracazarohom', 'Práca za rohom', 'https://www.pracazarohom.sk'),
    ('kariera',      'Kariera.sk',     'https://www.kariera.sk')
ON CONFLICT (code) DO NOTHING;

-- ============================================================
-- JOB.COMPANIES - ciselnik firiem ponukajucich pracu
-- is_agency: personalna agentura vs. priamy zamestnavatel
-- ============================================================
CREATE TABLE IF NOT EXISTS job.companies (
    id            SERIAL PRIMARY KEY,
    name          VARCHAR(255) NOT NULL,
    name_norm     VARCHAR(255) NOT NULL UNIQUE,  -- lower, bez diakritiky a pravnej formy
    ico           VARCHAR(20),                   -- ICO, ak sa poda zistit
    is_agency     BOOLEAN      NOT NULL DEFAULT FALSE,
    agency_source VARCHAR(20)  NOT NULL DEFAULT 'unknown',
                  -- 'manual' | 'name_pattern' | 'source_flag' | 'unknown'
    website       VARCHAR(500),
    logo_url      VARCHAR(500),
    description   TEXT,
    created_at    TIMESTAMP    NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMP    NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS companies_agency_idx ON job.companies(is_agency);

-- Firma ako ju uvadza konkretny portal (rozne portaly = rozne nazvy/slugy tej istej firmy)
CREATE TABLE IF NOT EXISTS job.company_aliases (
    id          SERIAL PRIMARY KEY,
    company_id  INT          NOT NULL REFERENCES job.companies(id) ON DELETE CASCADE,
    source_id   INT          NOT NULL REFERENCES job.sources(id),
    alias       VARCHAR(255) NOT NULL,
    slug        VARCHAR(255),                   -- 'backend-accounting' z URL profesie
    profile_url VARCHAR(500),
    UNIQUE (source_id, alias)
);

-- ============================================================
-- JOB.PROFESSIONS - ciselnik profesii (hierarchicky: odbor -> profesia)
-- ============================================================
CREATE TABLE IF NOT EXISTS job.professions (
    id          SERIAL PRIMARY KEY,
    parent_id   INT          REFERENCES job.professions(id),
    code        VARCHAR(50)  NOT NULL UNIQUE,
    name        VARCHAR(150) NOT NULL,
    name_en     VARCHAR(150),
    isco_code   VARCHAR(10),                    -- ISCO-08, ak sa doplni
    sort_order  INT          NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS professions_parent_idx ON job.professions(parent_id);

-- Zakladne odbory (potomkovia sa doplnia mapovanim z portalov)
INSERT INTO job.professions (code, name, sort_order) VALUES
    ('it',            'Informačné technológie',        10),
    ('economics',     'Ekonomika, financie, účtovníctvo', 20),
    ('admin',         'Administratíva',                30),
    ('management',    'Manažment',                     40),
    ('sales',         'Obchod a predaj',               50),
    ('marketing',     'Marketing a reklama',           60),
    ('production',    'Výroba a priemysel',            70),
    ('logistics',     'Doprava, špedícia, logistika',  80),
    ('construction',  'Stavebníctvo a reality',        90),
    ('gastro',        'Gastronómia a hotelierstvo',   100),
    ('retail',        'Maloobchod',                   110),
    ('healthcare',    'Zdravotníctvo a farmácia',     120),
    ('education',     'Školstvo a vzdelávanie',       130),
    ('law',           'Právo a legislatíva',          140),
    ('hr',            'Ľudské zdroje a personalistika',150),
    ('services',      'Služby',                       160),
    ('agriculture',   'Poľnohospodárstvo a lesníctvo',170),
    ('security',      'Ochrana a bezpečnosť',         180),
    ('culture',       'Kultúra a umenie',             190),
    ('other',         'Ostatné',                      999)
ON CONFLICT (code) DO NOTHING;

-- Mapovanie: nazov odboru/profesie z portalu -> nasa profesia
CREATE TABLE IF NOT EXISTS job.profession_mappings (
    id            SERIAL PRIMARY KEY,
    source_id     INT          NOT NULL REFERENCES job.sources(id),
    source_label  VARCHAR(200) NOT NULL,        -- presny text z portalu
    profession_id INT          NOT NULL REFERENCES job.professions(id),
    UNIQUE (source_id, source_label)
);

-- ============================================================
-- JOB.LANGUAGES + JOB.LANGUAGE_LEVELS - ciselnik jazykov a urovni
-- ============================================================
CREATE TABLE IF NOT EXISTS job.languages (
    id       SERIAL PRIMARY KEY,
    code     VARCHAR(5)   NOT NULL UNIQUE,      -- ISO 639-1: 'sk','en','de'
    name     VARCHAR(100) NOT NULL,
    name_en  VARCHAR(100) NOT NULL
);

INSERT INTO job.languages (code, name, name_en) VALUES
    ('sk', 'Slovenský',  'Slovak'),
    ('cs', 'Český',      'Czech'),
    ('en', 'Anglický',   'English'),
    ('de', 'Nemecký',    'German'),
    ('hu', 'Maďarský',   'Hungarian'),
    ('fr', 'Francúzsky', 'French'),
    ('es', 'Španielsky', 'Spanish'),
    ('it', 'Taliansky',  'Italian'),
    ('ru', 'Ruský',      'Russian'),
    ('pl', 'Poľský',     'Polish'),
    ('uk', 'Ukrajinský', 'Ukrainian'),
    ('nl', 'Holandský',  'Dutch')
ON CONFLICT (code) DO NOTHING;

-- Urovne podla CEFR + volne stupne, ktore pouzivaju portaly
CREATE TABLE IF NOT EXISTS job.language_levels (
    id       SERIAL PRIMARY KEY,
    code     VARCHAR(10)  NOT NULL UNIQUE,      -- 'A1'..'C2'
    name     VARCHAR(100) NOT NULL,
    rank     INT          NOT NULL UNIQUE       -- 1..6, na porovnavanie "aspon B2"
);

INSERT INTO job.language_levels (code, name, rank) VALUES
    ('A1', 'Úplný začiatočník',   1),
    ('A2', 'Mierne pokročilý',    2),
    ('B1', 'Stredne pokročilý',   3),
    ('B2', 'Pokročilý',           4),
    ('C1', 'Expert',              5),
    ('C2', 'Materinský jazyk',    6)
ON CONFLICT (code) DO NOTHING;

-- ============================================================
-- JOB.LOCATIONS - ciselnik lokalit (obec / okres / kraj / krajina)
-- GPS su povinne pre vypocet vzdialenosti; bez nich sa vzdialenost neuklada.
-- ============================================================
CREATE TABLE IF NOT EXISTS job.locations (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(150) NOT NULL,          -- 'Dunajská Lužná'
    name_norm   VARCHAR(150) NOT NULL,          -- 'dunajska luzna'
    level       VARCHAR(10)  NOT NULL DEFAULT 'city',
                -- 'city' | 'district' | 'region' | 'country'
    parent_id   INT          REFERENCES job.locations(id),
    district    VARCHAR(100),                   -- okres
    region      VARCHAR(100),                   -- kraj
    country     VARCHAR(2)   NOT NULL DEFAULT 'SK',
    lat         NUMERIC(9,6),
    lon         NUMERIC(9,6),
    UNIQUE (name_norm, district, country)
);
CREATE INDEX IF NOT EXISTS locations_name_norm_idx ON job.locations(name_norm);
CREATE INDEX IF NOT EXISTS locations_geo_idx       ON job.locations(lat, lon);

-- domovska lokalita usera (FK az teraz, ked job.locations existuje)
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'users_home_location_fk') THEN
        ALTER TABLE admin.users
            ADD CONSTRAINT users_home_location_fk
            FOREIGN KEY (home_location_id) REFERENCES job.locations(id);
    END IF;
END $$;

-- ============================================================
-- JOB.TAGS - stitky ponuky
-- ============================================================
CREATE TABLE IF NOT EXISTS job.tags (
    id    SERIAL PRIMARY KEY,
    code  VARCHAR(50)  NOT NULL UNIQUE,
    label VARCHAR(100) NOT NULL
);

INSERT INTO job.tags (code, label) VALUES
    ('home_office',   'Práca z domu'),
    ('no_cv',         'Reagujte bez životopisu'),
    ('part_time',     'Skrátený úväzok'),
    ('for_students',  'Vhodné pre študentov'),
    ('for_graduates', 'Vhodné pre absolventov'),
    ('travel',        'Vyžaduje cestovanie'),
    ('shifts',        'Práca na zmeny'),
    ('company_car',   'Služobné auto')
ON CONFLICT (code) DO NOTHING;


-- ############################################################
-- INZERATY
-- ############################################################

-- ============================================================
-- JOB.OFFERS - hlavna tabulka inzeratov
-- external_id = 'O5354800' z URL profesie -> deduplikacny kluc
--
-- Casy:
--   published_at = kedy bol inzerat zverejneny na portali
--   created_at   = kedy sme ho pridali do DB (prve videnie)
--   last_seen_at = kedy sme ho naposledy videli v zoznamoch
-- ============================================================
CREATE TABLE IF NOT EXISTS job.offers (
    id                 BIGSERIAL PRIMARY KEY,
    source_id          INT          NOT NULL REFERENCES job.sources(id),
    external_id        VARCHAR(50)  NOT NULL,      -- 'O5354800'
    url                VARCHAR(500) NOT NULL,      -- kanonicka URL bez search_id

    title              VARCHAR(300) NOT NULL,
    title_sk           VARCHAR(300),               -- preklad, ak original nie je po slovensky
    company_id         INT          REFERENCES job.companies(id),
    company_name_raw   VARCHAR(255),               -- ako to bolo v inzerate
    is_agency_offer    BOOLEAN,                    -- kopia z companies pre rychly filter
    profession_id      INT          REFERENCES job.professions(id),
    profession_raw     VARCHAR(200),               -- odbor ako ho uvadza portal

    -- jazyk inzeratu
    orig_lang          VARCHAR(5)   REFERENCES job.languages(code),
    lang_detected_conf NUMERIC(4,3),               -- 0..1 istota detekcie

    -- mzda
    salary_raw         VARCHAR(200),               -- 'Od 1 600 EUR/mesiac'
    salary_min         NUMERIC(10,2),
    salary_max         NUMERIC(10,2),
    salary_currency    VARCHAR(3)   DEFAULT 'EUR',
    salary_period      VARCHAR(10),                -- 'month' | 'hour' | 'year'

    -- klasifikacia
    employment_type    VARCHAR(30),                -- 'tpp'|'dohoda'|'zivnost'|'brigada'|'internship'
    contract_duration  VARCHAR(100),
    education_level    VARCHAR(100),
    seniority          VARCHAR(50),
    remote_type        VARCHAR(20),                -- 'onsite' | 'hybrid' | 'remote'
    positions_count    INT,
    start_date         VARCHAR(100),               -- 'ihneď', 'dohodou', konkretny datum

    -- stav zberu
    is_active          BOOLEAN      NOT NULL DEFAULT TRUE,
    closed_at          TIMESTAMP,                  -- kedy zmizla zo zoznamov
    missing_runs       INT          NOT NULL DEFAULT 0,
    detail_fetched_at  TIMESTAMP,                  -- NULL = detail este nestiahnuty
    translated_at      TIMESTAMP,                  -- NULL = preklad este neurobeny
    content_hash       CHAR(64),                   -- sha256 sledovanych poli, detekcia zmien

    -- casy
    published_at       TIMESTAMP,                  -- zverejnenie na portali
    published_at_raw   VARCHAR(100),               -- 'Pred 1 minútou'
    valid_until        DATE,
    created_at         TIMESTAMP    NOT NULL DEFAULT NOW(),  -- pridanie do nasej DB
    last_seen_at       TIMESTAMP    NOT NULL DEFAULT NOW(),
    updated_at         TIMESTAMP    NOT NULL DEFAULT NOW(),

    UNIQUE (source_id, external_id)
);
CREATE INDEX IF NOT EXISTS offers_active_published_idx ON job.offers(is_active, published_at DESC);
CREATE INDEX IF NOT EXISTS offers_created_idx          ON job.offers(created_at DESC);
CREATE INDEX IF NOT EXISTS offers_company_idx          ON job.offers(company_id);
CREATE INDEX IF NOT EXISTS offers_profession_idx       ON job.offers(profession_id);
CREATE INDEX IF NOT EXISTS offers_detail_pending_idx   ON job.offers(source_id)
    WHERE detail_fetched_at IS NULL;
CREATE INDEX IF NOT EXISTS offers_translate_pending_idx ON job.offers(id)
    WHERE translated_at IS NULL AND orig_lang IS NOT NULL AND orig_lang <> 'sk';

-- ============================================================
-- JOB.OFFER_CONTENT - komplet obsah inzeratu, original + preklady
--
-- Pre kazdy inzerat najmenej jeden riadok (is_original = TRUE) s HTML tak,
-- ako bolo na zdroji. Ak original nie je po slovensky, pribudne riadok
-- lang='sk', is_original=FALSE s prelozenou verziou.
-- ============================================================
CREATE TABLE IF NOT EXISTS job.offer_content (
    id            BIGSERIAL PRIMARY KEY,
    offer_id      BIGINT      NOT NULL REFERENCES job.offers(id) ON DELETE CASCADE,
    lang          VARCHAR(5)  NOT NULL REFERENCES job.languages(code),
    is_original   BOOLEAN     NOT NULL DEFAULT FALSE,

    html_full     TEXT,       -- komplet HTML inzeratu tak ako bolo na zdroji
    text_full     TEXT,       -- to iste ako cisty text (fulltext, preklad, LLM)

    -- rozparsovane sekcie (nepovinne, dopĺňa parser)
    description   TEXT,
    requirements  TEXT,
    benefits      TEXT,
    company_info  TEXT,

    -- preklad
    translated_by VARCHAR(30),          -- 'libretranslate' | 'openrouter' | 'manual'
    translated_at TIMESTAMP,

    created_at    TIMESTAMP   NOT NULL DEFAULT NOW(),
    UNIQUE (offer_id, lang)
);
-- prave jeden original na inzerat
CREATE UNIQUE INDEX IF NOT EXISTS offer_content_one_original_idx
    ON job.offer_content(offer_id) WHERE is_original;
-- fulltext nad slovenskym (alebo originalnym) textom
CREATE INDEX IF NOT EXISTS offer_content_fts_idx ON job.offer_content
    USING GIN (to_tsvector('simple', COALESCE(text_full, '')));

-- ============================================================
-- JOB.OFFER_LOCATIONS - ponuka moze mat viac miest vykonu
-- ============================================================
CREATE TABLE IF NOT EXISTS job.offer_locations (
    offer_id    BIGINT NOT NULL REFERENCES job.offers(id) ON DELETE CASCADE,
    location_id INT    NOT NULL REFERENCES job.locations(id),
    raw_text    VARCHAR(200),
    is_primary  BOOLEAN NOT NULL DEFAULT FALSE,
    PRIMARY KEY (offer_id, location_id)
);
CREATE INDEX IF NOT EXISTS offer_locations_loc_idx ON job.offer_locations(location_id);

-- ============================================================
-- JOB.OFFER_LANGUAGES - jazykove poziadavky inzeratu
-- ============================================================
CREATE TABLE IF NOT EXISTS job.offer_languages (
    offer_id    BIGINT  NOT NULL REFERENCES job.offers(id) ON DELETE CASCADE,
    language_id INT     NOT NULL REFERENCES job.languages(id),
    level_id    INT     REFERENCES job.language_levels(id),
    is_required BOOLEAN NOT NULL DEFAULT TRUE,   -- FALSE = vyhodou
    raw_text    VARCHAR(200),
    PRIMARY KEY (offer_id, language_id)
);

-- ============================================================
-- JOB.OFFER_TAGS
-- ============================================================
CREATE TABLE IF NOT EXISTS job.offer_tags (
    offer_id BIGINT NOT NULL REFERENCES job.offers(id) ON DELETE CASCADE,
    tag_id   INT    NOT NULL REFERENCES job.tags(id),
    PRIMARY KEY (offer_id, tag_id)
);

-- ============================================================
-- JOB.OFFER_HISTORY - zmeny sledovanych poli v case
-- ============================================================
CREATE TABLE IF NOT EXISTS job.offer_history (
    id         BIGSERIAL PRIMARY KEY,
    offer_id   BIGINT      NOT NULL REFERENCES job.offers(id) ON DELETE CASCADE,
    field      VARCHAR(50) NOT NULL,      -- 'salary_raw' | 'title' | 'is_active' ...
    old_value  TEXT,
    new_value  TEXT,
    changed_at TIMESTAMP   NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS offer_history_offer_idx ON job.offer_history(offer_id, changed_at DESC);


-- ############################################################
-- SCRAPING
-- ############################################################

-- ============================================================
-- JOB.SCRAPE_RUNS - log kazdeho behu zberu
--
-- Manualny beh: run_type='manual', source_id = vybrany portal,
-- period_days = obdobie, za ake sa ma dotiahnut to, co este nemame.
-- ============================================================
CREATE TABLE IF NOT EXISTS job.scrape_runs (
    id                BIGSERIAL PRIMARY KEY,
    source_id         INT         NOT NULL REFERENCES job.sources(id),
    run_type          VARCHAR(20) NOT NULL DEFAULT 'incremental',
                      -- 'incremental' (cron) | 'full' (nocny) | 'manual' (user)
    period_days       INT         NOT NULL DEFAULT 1,   -- za ake obdobie sa tahalo
    status            VARCHAR(20) NOT NULL DEFAULT 'running',
                      -- 'running' | 'ok' | 'partial' | 'failed' | 'cancelled'
    started_at        TIMESTAMP   NOT NULL DEFAULT NOW(),
    finished_at       TIMESTAMP,

    pages_fetched     INT         NOT NULL DEFAULT 0,
    offers_found      INT         NOT NULL DEFAULT 0,
    offers_new        INT         NOT NULL DEFAULT 0,
    offers_updated    INT         NOT NULL DEFAULT 0,
    offers_closed     INT         NOT NULL DEFAULT 0,
    details_fetched   INT         NOT NULL DEFAULT 0,
    translated_count  INT         NOT NULL DEFAULT 0,
    matches_computed  INT         NOT NULL DEFAULT 0,
    errors_count      INT         NOT NULL DEFAULT 0,
    error_message     TEXT,

    filter_params     TEXT,                             -- pouzite URL filtre (JSON)
    triggered_by      INT         REFERENCES admin.users(id)  -- NULL = cron
);
CREATE INDEX IF NOT EXISTS scrape_runs_started_idx ON job.scrape_runs(started_at DESC);
CREATE INDEX IF NOT EXISTS scrape_runs_source_idx  ON job.scrape_runs(source_id, started_at DESC);

-- Ktory beh ktory inzerat videl (dohladatelnost povodu dat)
CREATE TABLE IF NOT EXISTS job.scrape_run_offers (
    run_id   BIGINT      NOT NULL REFERENCES job.scrape_runs(id) ON DELETE CASCADE,
    offer_id BIGINT      NOT NULL REFERENCES job.offers(id) ON DELETE CASCADE,
    action   VARCHAR(10) NOT NULL,   -- 'new' | 'updated' | 'seen'
    PRIMARY KEY (run_id, offer_id)
);


-- ############################################################
-- PREFERENCIE POUZIVATELA A VHODNOST
-- ############################################################

-- ============================================================
-- JOB.USER_PREFERENCES - zakladne nastavenia hladania (1 riadok na usera)
-- Detailne preferencie (profesie, lokality, napln prace) su v tabulkach nizsie.
-- ============================================================
CREATE TABLE IF NOT EXISTS job.user_preferences (
    user_id            INT         PRIMARY KEY REFERENCES admin.users(id) ON DELETE CASCADE,

    -- typ prace
    employment_types   VARCHAR(30)[],       -- 'tpp','dohoda','zivnost','brigada'
    remote_types       VARCHAR(20)[],       -- 'onsite','hybrid','remote'
    seniority_levels   VARCHAR(50)[],

    -- mzda
    salary_min         NUMERIC(10,2),
    salary_period      VARCHAR(10)  NOT NULL DEFAULT 'month',
    salary_required    BOOLEAN      NOT NULL DEFAULT FALSE,  -- odmietnut ponuky bez mzdy

    -- dojazd
    max_distance_km    INT          NOT NULL DEFAULT 50,
    accept_unknown_distance BOOLEAN NOT NULL DEFAULT TRUE,   -- ponuka bez GPS

    -- agentury
    accept_agencies    BOOLEAN      NOT NULL DEFAULT TRUE,
    agency_penalty     INT          NOT NULL DEFAULT 10,     -- kolko bodov strhnut

    -- naplň prace: volny text, z ktoreho sa robi keyword/LLM matching
    job_content_wanted   TEXT,
    job_content_unwanted TEXT,

    -- notifikacie
    min_score_notify   INT          NOT NULL DEFAULT 60,
    notify_push        BOOLEAN      NOT NULL DEFAULT TRUE,
    notify_email       BOOLEAN      NOT NULL DEFAULT FALSE,

    updated_at         TIMESTAMP    NOT NULL DEFAULT NOW()
);

-- ============================================================
-- JOB.USER_PREF_PROFESSIONS - preferovane / nechcene profesie
-- ============================================================
CREATE TABLE IF NOT EXISTS job.user_pref_professions (
    user_id       INT NOT NULL REFERENCES admin.users(id) ON DELETE CASCADE,
    profession_id INT NOT NULL REFERENCES job.professions(id),
    weight        INT NOT NULL DEFAULT 100,   -- zaporne = nechcem tuto profesiu
    PRIMARY KEY (user_id, profession_id)
);

-- ============================================================
-- JOB.USER_PREF_LOCATIONS - preferovane lokality
-- ============================================================
CREATE TABLE IF NOT EXISTS job.user_pref_locations (
    user_id     INT NOT NULL REFERENCES admin.users(id) ON DELETE CASCADE,
    location_id INT NOT NULL REFERENCES job.locations(id),
    weight      INT NOT NULL DEFAULT 100,     -- zaporne = sem nechcem dochadzat
    radius_km   INT,                          -- okolie tejto lokality
    PRIMARY KEY (user_id, location_id)
);

-- ============================================================
-- JOB.USER_PREF_LANGUAGES - jazyky, ktore user ovlada
-- ============================================================
CREATE TABLE IF NOT EXISTS job.user_pref_languages (
    user_id     INT NOT NULL REFERENCES admin.users(id) ON DELETE CASCADE,
    language_id INT NOT NULL REFERENCES job.languages(id),
    level_id    INT NOT NULL REFERENCES job.language_levels(id),
    PRIMARY KEY (user_id, language_id)
);

-- ============================================================
-- JOB.USER_PREF_KEYWORDS - kľúčové slova pre naplň prace
-- ============================================================
CREATE TABLE IF NOT EXISTS job.user_pref_keywords (
    id       SERIAL PRIMARY KEY,
    user_id  INT          NOT NULL REFERENCES admin.users(id) ON DELETE CASCADE,
    keyword  VARCHAR(100) NOT NULL,
    weight   INT          NOT NULL DEFAULT 10,   -- zaporne = vylucujuce slovo
    UNIQUE (user_id, keyword)
);

-- ============================================================
-- JOB.USER_OFFER_MATCH - vypocitana vhodnost inzeratu pre usera
--
-- Jeden riadok na dvojicu (user, inzerat). Prepocitava sa po kazdom
-- zbere pre nove inzeraty a po zmene preferencii pre vsetky.
-- ============================================================
CREATE TABLE IF NOT EXISTS job.user_offer_match (
    user_id       INT         NOT NULL REFERENCES admin.users(id) ON DELETE CASCADE,
    offer_id      BIGINT      NOT NULL REFERENCES job.offers(id) ON DELETE CASCADE,

    score         INT         NOT NULL,          -- 0..100
    bucket        VARCHAR(20) NOT NULL,          -- 'vhodne'|'menej_vhodne'|'nevhodne'
    summary       VARCHAR(500) NOT NULL,         -- kratky popis vhodnosti (1-2 vety)
    reasons       TEXT,                          -- JSON pole: [{"code","label","points"}]

    distance_km   NUMERIC(7,2),                  -- vzdialenost od lokality usera, NULL = nezname
    distance_from_location_id INT REFERENCES job.locations(id),

    scored_by     VARCHAR(20) NOT NULL DEFAULT 'rules',  -- 'rules' | 'llm' | 'manual'
    scorer_version VARCHAR(20),                  -- verzia skorovacieho algoritmu
    computed_at   TIMESTAMP   NOT NULL DEFAULT NOW(),
    notified_at   TIMESTAMP,                     -- NULL = este neoznamene

    PRIMARY KEY (user_id, offer_id)
);
CREATE INDEX IF NOT EXISTS user_offer_match_score_idx
    ON job.user_offer_match(user_id, score DESC, computed_at DESC);
CREATE INDEX IF NOT EXISTS user_offer_match_notify_idx
    ON job.user_offer_match(user_id, score DESC) WHERE notified_at IS NULL;

-- ============================================================
-- JOB.USER_OFFER_STATUS - osobny stav ponuky (rucna akcia usera)
-- ============================================================
CREATE TABLE IF NOT EXISTS job.user_offer_status (
    user_id    INT         NOT NULL REFERENCES admin.users(id) ON DELETE CASCADE,
    offer_id   BIGINT      NOT NULL REFERENCES job.offers(id) ON DELETE CASCADE,
    status     VARCHAR(20) NOT NULL,   -- 'saved'|'hidden'|'applied'|'rejected'|'interview'|'offer'
    note       TEXT,
    applied_at TIMESTAMP,
    updated_at TIMESTAMP   NOT NULL DEFAULT NOW(),
    PRIMARY KEY (user_id, offer_id)
);
CREATE INDEX IF NOT EXISTS user_offer_status_user_idx ON job.user_offer_status(user_id, status);

-- ============================================================
-- JOB.NOTIFICATION_LOG
-- ============================================================
CREATE TABLE IF NOT EXISTS job.notification_log (
    id           BIGSERIAL PRIMARY KEY,
    user_id      INT         NOT NULL REFERENCES admin.users(id) ON DELETE CASCADE,
    notif_type   VARCHAR(30) NOT NULL,
    channel      VARCHAR(10) NOT NULL,   -- 'push' | 'email'
    offers_count INT,
    payload      TEXT,
    status       VARCHAR(20) NOT NULL DEFAULT 'sent',
    error        TEXT,
    sent_at      TIMESTAMP   NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS notification_log_user_idx ON job.notification_log(user_id, sent_at DESC);


-- ############################################################
-- FUNKCIE
-- ############################################################

-- ============================================================
-- Vzdialenost dvoch GPS bodov v km (Haversine).
-- NULL, ak chyba ktorykolvek suradnicovy udaj.
-- ============================================================
CREATE OR REPLACE FUNCTION job.distance_km(
    lat1 NUMERIC, lon1 NUMERIC, lat2 NUMERIC, lon2 NUMERIC
) RETURNS NUMERIC AS $$
DECLARE
    r  CONSTANT DOUBLE PRECISION := 6371.0;  -- polomer Zeme v km
    dl DOUBLE PRECISION;
    dp DOUBLE PRECISION;
    a  DOUBLE PRECISION;
BEGIN
    IF lat1 IS NULL OR lon1 IS NULL OR lat2 IS NULL OR lon2 IS NULL THEN
        RETURN NULL;
    END IF;
    dp := radians(lat2 - lat1);
    dl := radians(lon2 - lon1);
    a  := sin(dp / 2) ^ 2
          + cos(radians(lat1)) * cos(radians(lat2)) * sin(dl / 2) ^ 2;
    RETURN round((2 * r * asin(least(1, sqrt(a))))::NUMERIC, 2);
END;
$$ LANGUAGE plpgsql IMMUTABLE;

-- ============================================================
-- Najkratsia vzdialenost usera k lubovolnemu miestu vykonu inzeratu.
-- Berie presne GPS usera, ak ich ma; inak GPS jeho domovskej lokality.
-- ============================================================
CREATE OR REPLACE FUNCTION job.offer_distance_km(
    p_user_id INT, p_offer_id BIGINT
) RETURNS NUMERIC AS $$
DECLARE
    u_lat NUMERIC;
    u_lon NUMERIC;
BEGIN
    SELECT COALESCE(u.home_lat, l.lat), COALESCE(u.home_lon, l.lon)
      INTO u_lat, u_lon
      FROM admin.users u
      LEFT JOIN job.locations l ON l.id = u.home_location_id
     WHERE u.id = p_user_id;

    IF u_lat IS NULL OR u_lon IS NULL THEN
        RETURN NULL;
    END IF;

    RETURN (SELECT MIN(job.distance_km(u_lat, u_lon, l.lat, l.lon))
              FROM job.offer_locations ol
              JOIN job.locations l ON l.id = ol.location_id
             WHERE ol.offer_id = p_offer_id);
END;
$$ LANGUAGE plpgsql STABLE;


-- ############################################################
-- POHLADY
-- ############################################################

-- Inzerat s obsahom v slovencine (preklad, ak existuje, inak original)
CREATE OR REPLACE VIEW job.v_offers_sk AS
SELECT o.*,
       COALESCE(o.title_sk, o.title)                    AS title_display,
       COALESCE(sk.text_full, orig.text_full)           AS text_display,
       COALESCE(sk.html_full, orig.html_full)           AS html_display,
       orig.html_full                                   AS html_original,
       orig.lang                                        AS content_orig_lang,
       (sk.id IS NOT NULL)                              AS has_translation,
       c.name                                           AS company_name,
       c.is_agency                                      AS company_is_agency,
       p.name                                           AS profession_name,
       s.name                                           AS source_name
  FROM job.offers o
  LEFT JOIN job.offer_content sk   ON sk.offer_id   = o.id AND sk.lang = 'sk'
  LEFT JOIN job.offer_content orig ON orig.offer_id = o.id AND orig.is_original
  LEFT JOIN job.companies   c ON c.id = o.company_id
  LEFT JOIN job.professions p ON p.id = o.profession_id
  JOIN      job.sources     s ON s.id = o.source_id;


-- ============================================================
-- VERZIA
-- ============================================================
INSERT INTO admin.schema_versions (version, description)
VALUES (1, 'Initial schema: admin(users,invites,reset,logs,push,notif) + job(ciselniky, offers+content SK/orig, scraping, preferencie, vhodnost)')
ON CONFLICT (version) DO NOTHING;
