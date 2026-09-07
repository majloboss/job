-- ============================================================
-- JOB - Migration 001: Initial schema
-- DB: DB-JOB (PostgreSQL)
-- Spusti ako: psql -h [host]:[port] -U [user] -d DB-JOB -f 001_init.sql
--
-- Schemy:
--   admin.*  user manazment (prevzaty z BetClub, 1:1 kompatibilny)
--   job.*    pracovne ponuky, scraping, vyhladavacie profily
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
-- Zdielane pre vsetky buduce aplikacie v DB-JOB.
-- Prevzate z BetClub vratane token_version (okamzity logout po zmene hesla).
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
    avatar            VARCHAR(255),                        -- nazov suboru avatara na serveri
    role              VARCHAR(10)  NOT NULL DEFAULT 'user',-- 'user' | 'admin'
    is_active         BOOLEAN      NOT NULL DEFAULT FALSE, -- TRUE po nastaveni username+hesla
    token_version     INT          NOT NULL DEFAULT 1,     -- inkrement = invalidacia vsetkych JWT
    fcm_token         VARCHAR(255),                        -- Firebase FCM token (Android)
    web_push_sub      TEXT,                                -- Web Push subscription JSON (PWA)
    created_at        TIMESTAMP    NOT NULL DEFAULT NOW()
);

-- ============================================================
-- ADMIN.INVITES - registracia iba na pozvanie
-- ============================================================
CREATE SEQUENCE IF NOT EXISTS admin.seq_invite START 1;

CREATE TABLE IF NOT EXISTS admin.invites (
    id            INT          PRIMARY KEY DEFAULT nextval('admin.seq_invite'),
    invite_token  VARCHAR(100) NOT NULL UNIQUE,
    sent_to       VARCHAR(150),                            -- komu bola pozvanka poslana
    created_by    INT          REFERENCES admin.users(id),
    created_at    TIMESTAMP    NOT NULL DEFAULT NOW(),
    used_at       TIMESTAMP,                               -- NULL = este nepouzity
    cancelled_at  TIMESTAMP,                               -- NULL = platna
    user_id       INT          REFERENCES admin.users(id)
);

-- ============================================================
-- ADMIN.PASSWORD_RESET_TOKENS - jednorazovy reset hesla cez email
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
-- notif_type: new_matches | daily_digest | profile_no_results | scrape_failed
-- ============================================================
CREATE TABLE IF NOT EXISTS admin.notification_settings (
    user_id        INT         REFERENCES admin.users(id) ON DELETE CASCADE NOT NULL,
    notif_type     VARCHAR(30) NOT NULL,
    push_enabled   BOOLEAN     NOT NULL DEFAULT TRUE,
    email_enabled  BOOLEAN     NOT NULL DEFAULT TRUE,
    PRIMARY KEY (user_id, notif_type)
);

-- ============================================================
-- JOB.SOURCES - ciselnik pracovnych portalov
-- ============================================================
CREATE TABLE IF NOT EXISTS job.sources (
    id                       SERIAL PRIMARY KEY,
    code                     VARCHAR(30)  NOT NULL UNIQUE,  -- 'profesia' | 'pracazarohom'
    name                     VARCHAR(100) NOT NULL,
    base_url                 VARCHAR(255) NOT NULL,
    is_active                BOOLEAN      NOT NULL DEFAULT TRUE,
    scrape_interval_minutes  INT          NOT NULL DEFAULT 120,
    request_delay_ms         INT          NOT NULL DEFAULT 1500, -- rate limit medzi requestmi
    last_scraped_at          TIMESTAMP,
    created_at               TIMESTAMP    NOT NULL DEFAULT NOW()
);

INSERT INTO job.sources (code, name, base_url) VALUES
    ('profesia',     'Profesia.sk',       'https://www.profesia.sk'),
    ('pracazarohom', 'Práca za rohom',    'https://www.pracazarohom.sk')
ON CONFLICT (code) DO NOTHING;

-- ============================================================
-- JOB.COMPANIES - zamestnavatelia
-- ============================================================
CREATE TABLE IF NOT EXISTS job.companies (
    id           SERIAL PRIMARY KEY,
    source_id    INT          NOT NULL REFERENCES job.sources(id),
    name         VARCHAR(255) NOT NULL,
    slug         VARCHAR(255),                    -- 'backend-accounting' z URL
    profile_url  VARCHAR(500),
    logo_url     VARCHAR(500),
    created_at   TIMESTAMP    NOT NULL DEFAULT NOW(),
    UNIQUE (source_id, name)
);
CREATE INDEX IF NOT EXISTS companies_name_idx ON job.companies(LOWER(name));

-- ============================================================
-- JOB.LOCATIONS - ciselnik lokalit
-- ============================================================
CREATE TABLE IF NOT EXISTS job.locations (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(150) NOT NULL,            -- 'Dunajská Lužná'
    name_norm   VARCHAR(150) NOT NULL,            -- 'dunajska luzna' (bez diakritiky, lower)
    district    VARCHAR(100),                     -- okres: 'Senec'
    region      VARCHAR(100),                     -- kraj: 'Bratislavský kraj'
    country     VARCHAR(2)   NOT NULL DEFAULT 'SK',
    lat         NUMERIC(9,6),
    lon         NUMERIC(9,6),
    UNIQUE (name_norm, district, country)
);
CREATE INDEX IF NOT EXISTS locations_name_norm_idx ON job.locations(name_norm);

-- ============================================================
-- JOB.OFFERS - hlavna tabulka inzeratov
-- external_id = 'O5354800' z profesia.sk -> deduplikacny kluc
-- ============================================================
CREATE TABLE IF NOT EXISTS job.offers (
    id                 BIGSERIAL PRIMARY KEY,
    source_id          INT          NOT NULL REFERENCES job.sources(id),
    external_id        VARCHAR(50)  NOT NULL,       -- 'O5354800'
    url                VARCHAR(500) NOT NULL,       -- kanonicka URL bez search_id
    title              VARCHAR(300) NOT NULL,
    company_id         INT          REFERENCES job.companies(id),
    company_name_raw   VARCHAR(255),                -- ako to bolo v inzerate

    -- mzda
    salary_raw         VARCHAR(200),                -- 'Od 1 600 EUR/mesiac'
    salary_min         NUMERIC(10,2),
    salary_max         NUMERIC(10,2),
    salary_currency    VARCHAR(3)   DEFAULT 'EUR',
    salary_period      VARCHAR(10),                 -- 'month' | 'hour' | 'year'

    -- klasifikacia
    employment_type    VARCHAR(30),                 -- 'tpp' | 'dohoda' | 'zivnost' | 'brigada'
    contract_duration  VARCHAR(50),                 -- 'na dobu určitú', ...
    education_level    VARCHAR(100),
    seniority          VARCHAR(50),
    remote_type        VARCHAR(20),                 -- 'onsite' | 'hybrid' | 'remote'
    positions_count    INT,
    industry           VARCHAR(150),                -- odbor / oblasť práce

    -- texty
    description        TEXT,
    requirements       TEXT,
    benefits           TEXT,

    -- casove udaje
    posted_at          TIMESTAMP,                   -- datum zverejnenia na portali
    posted_at_raw      VARCHAR(100),                -- 'Pred 1 minútou'
    valid_until        DATE,

    -- stav zberu
    is_active          BOOLEAN      NOT NULL DEFAULT TRUE,
    closed_at          TIMESTAMP,                   -- kedy zmizla zo zoznamov
    missing_runs       INT          NOT NULL DEFAULT 0, -- pocet behov bez nalezu
    detail_fetched_at  TIMESTAMP,                   -- NULL = detail este nestiahnuty
    content_hash       CHAR(64),                    -- sha256 sledovanych poli, detekcia zmien

    first_seen_at      TIMESTAMP    NOT NULL DEFAULT NOW(),
    last_seen_at       TIMESTAMP    NOT NULL DEFAULT NOW(),
    updated_at         TIMESTAMP    NOT NULL DEFAULT NOW(),

    UNIQUE (source_id, external_id)
);
CREATE INDEX IF NOT EXISTS offers_active_posted_idx  ON job.offers(is_active, posted_at DESC);
CREATE INDEX IF NOT EXISTS offers_company_idx        ON job.offers(company_id);
CREATE INDEX IF NOT EXISTS offers_detail_pending_idx ON job.offers(source_id) WHERE detail_fetched_at IS NULL;
-- fulltext nad titulkom a popisom (slovencina nema built-in config -> 'simple')
CREATE INDEX IF NOT EXISTS offers_fts_idx ON job.offers
    USING GIN (to_tsvector('simple', COALESCE(title,'') || ' ' || COALESCE(description,'')));

-- ============================================================
-- JOB.OFFER_LOCATIONS - ponuka moze mat viac miest vykonu
-- ============================================================
CREATE TABLE IF NOT EXISTS job.offer_locations (
    offer_id    BIGINT NOT NULL REFERENCES job.offers(id) ON DELETE CASCADE,
    location_id INT    NOT NULL REFERENCES job.locations(id),
    raw_text    VARCHAR(200),
    PRIMARY KEY (offer_id, location_id)
);

-- ============================================================
-- JOB.OFFER_RAW - surove HTML/JSON detailu (archiv pre re-parsing)
-- ============================================================
CREATE TABLE IF NOT EXISTS job.offer_raw (
    id          BIGSERIAL PRIMARY KEY,
    offer_id    BIGINT      NOT NULL REFERENCES job.offers(id) ON DELETE CASCADE,
    fetched_at  TIMESTAMP   NOT NULL DEFAULT NOW(),
    http_status INT,
    content     TEXT        NOT NULL,
    content_type VARCHAR(20) NOT NULL DEFAULT 'html'  -- 'html' | 'json'
);
CREATE INDEX IF NOT EXISTS offer_raw_offer_idx ON job.offer_raw(offer_id, fetched_at DESC);

-- ============================================================
-- JOB.OFFER_HISTORY - zmeny sledovanych poli v case
-- ============================================================
CREATE TABLE IF NOT EXISTS job.offer_history (
    id         BIGSERIAL PRIMARY KEY,
    offer_id   BIGINT      NOT NULL REFERENCES job.offers(id) ON DELETE CASCADE,
    field      VARCHAR(50) NOT NULL,     -- 'salary_raw' | 'title' | 'is_active' ...
    old_value  TEXT,
    new_value  TEXT,
    changed_at TIMESTAMP   NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS offer_history_offer_idx ON job.offer_history(offer_id, changed_at DESC);

-- ============================================================
-- JOB.TAGS / JOB.OFFER_TAGS - stitky
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
    ('travel',        'Vyžaduje cestovanie')
ON CONFLICT (code) DO NOTHING;

CREATE TABLE IF NOT EXISTS job.offer_tags (
    offer_id BIGINT NOT NULL REFERENCES job.offers(id) ON DELETE CASCADE,
    tag_id   INT    NOT NULL REFERENCES job.tags(id),
    PRIMARY KEY (offer_id, tag_id)
);

-- ============================================================
-- JOB.SCRAPE_RUNS - log kazdeho behu scrapera
-- ============================================================
CREATE TABLE IF NOT EXISTS job.scrape_runs (
    id                BIGSERIAL PRIMARY KEY,
    source_id         INT         NOT NULL REFERENCES job.sources(id),
    run_type          VARCHAR(20) NOT NULL DEFAULT 'incremental', -- 'incremental' | 'full' | 'manual'
    started_at        TIMESTAMP   NOT NULL DEFAULT NOW(),
    finished_at       TIMESTAMP,
    status            VARCHAR(20) NOT NULL DEFAULT 'running',     -- 'running'|'ok'|'partial'|'failed'
    pages_fetched     INT         NOT NULL DEFAULT 0,
    offers_found      INT         NOT NULL DEFAULT 0,
    offers_new        INT         NOT NULL DEFAULT 0,
    offers_updated    INT         NOT NULL DEFAULT 0,
    offers_closed     INT         NOT NULL DEFAULT 0,
    details_fetched   INT         NOT NULL DEFAULT 0,
    errors_count      INT         NOT NULL DEFAULT 0,
    error_message     TEXT,
    filter_params     TEXT,                                       -- pouzite URL filtre (JSON)
    triggered_by      INT         REFERENCES admin.users(id)      -- NULL = cron
);
CREATE INDEX IF NOT EXISTS scrape_runs_started_idx ON job.scrape_runs(started_at DESC);

-- ============================================================
-- JOB.SEARCH_PROFILES - vyhladavacie profily pouzivatela
-- ============================================================
CREATE TABLE IF NOT EXISTS job.search_profiles (
    id                 SERIAL PRIMARY KEY,
    user_id            INT          NOT NULL REFERENCES admin.users(id) ON DELETE CASCADE,
    name               VARCHAR(100) NOT NULL,
    is_active          BOOLEAN      NOT NULL DEFAULT TRUE,

    keywords           TEXT,          -- hladane slova, oddelene ciarkou
    exclude_keywords   TEXT,          -- vylucene slova
    location_ids       INT[],         -- preferovane lokality
    radius_km          INT,
    employment_types   VARCHAR(30)[], -- 'tpp','dohoda','brigada'
    remote_types       VARCHAR(20)[],
    salary_min         NUMERIC(10,2),
    industries         TEXT[],
    tag_codes          VARCHAR(50)[],

    notify_push        BOOLEAN      NOT NULL DEFAULT TRUE,
    notify_email       BOOLEAN      NOT NULL DEFAULT FALSE,
    min_score          INT          NOT NULL DEFAULT 60, -- notifikuj len nad tymto skore

    created_at         TIMESTAMP    NOT NULL DEFAULT NOW(),
    updated_at         TIMESTAMP    NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, name)
);

-- ============================================================
-- JOB.PROFILE_MATCHES - vysledky matchovania ponuka x profil
-- ============================================================
CREATE TABLE IF NOT EXISTS job.profile_matches (
    id          BIGSERIAL PRIMARY KEY,
    profile_id  INT       NOT NULL REFERENCES job.search_profiles(id) ON DELETE CASCADE,
    offer_id    BIGINT    NOT NULL REFERENCES job.offers(id) ON DELETE CASCADE,
    score       INT       NOT NULL,               -- 0-100
    bucket      VARCHAR(20),                      -- 'vhodne'|'menej_vhodne'|'nevhodne'
    reasons     TEXT,                             -- preco (JSON pole dovodov)
    notified_at TIMESTAMP,                        -- NULL = este neoznamene
    matched_at  TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (profile_id, offer_id)
);
CREATE INDEX IF NOT EXISTS profile_matches_pending_idx
    ON job.profile_matches(profile_id, score DESC) WHERE notified_at IS NULL;

-- ============================================================
-- JOB.USER_OFFER_STATUS - osobny stav ponuky
-- ============================================================
CREATE TABLE IF NOT EXISTS job.user_offer_status (
    user_id    INT         NOT NULL REFERENCES admin.users(id) ON DELETE CASCADE,
    offer_id   BIGINT      NOT NULL REFERENCES job.offers(id) ON DELETE CASCADE,
    status     VARCHAR(20) NOT NULL,   -- 'saved'|'hidden'|'applied'|'rejected'|'interview'
    note       TEXT,
    applied_at TIMESTAMP,
    updated_at TIMESTAMP   NOT NULL DEFAULT NOW(),
    PRIMARY KEY (user_id, offer_id)
);
CREATE INDEX IF NOT EXISTS user_offer_status_user_idx ON job.user_offer_status(user_id, status);

-- ============================================================
-- JOB.NOTIFICATION_LOG - co, komu a kedy bolo odoslane
-- ============================================================
CREATE TABLE IF NOT EXISTS job.notification_log (
    id          BIGSERIAL PRIMARY KEY,
    user_id     INT         NOT NULL REFERENCES admin.users(id) ON DELETE CASCADE,
    notif_type  VARCHAR(30) NOT NULL,
    channel     VARCHAR(10) NOT NULL,   -- 'push' | 'email'
    profile_id  INT         REFERENCES job.search_profiles(id) ON DELETE SET NULL,
    offers_count INT,
    payload     TEXT,
    status      VARCHAR(20) NOT NULL DEFAULT 'sent',  -- 'sent' | 'failed'
    error       TEXT,
    sent_at     TIMESTAMP   NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS notification_log_user_idx ON job.notification_log(user_id, sent_at DESC);

-- ============================================================
-- VERZIA
-- ============================================================
INSERT INTO admin.schema_versions (version, description)
VALUES (1, 'Initial schema: admin(users,invites,reset,logs,push,notif) + job(sources,companies,locations,offers,raw,history,tags,runs,profiles,matches,status,notiflog)')
ON CONFLICT (version) DO NOTHING;
