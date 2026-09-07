-- ============================================================
-- JOB - Migration 002: dokumenty pouzivatela + AI posudzovanie cez OpenRouter
--
-- Prinasa:
--   job.user_documents      CV a dalsie dokumenty usera
--   job.ai_models           ciselnik modelov na OpenRouteri (vratane :free)
--   job.ai_evaluations      vysledok posudenia inzeratu modelom
--   job.ai_lab_runs         laboratorium: porovnanie vsetkych free modelov na URL
--   job.ai_prompts          verziovane prompty (aby sa dali porovnat)
-- ============================================================

-- ============================================================
-- JOB.USER_DOCUMENTS - CV a dalsie dokumenty
--
-- Subor sa uklada na disk (uploads/documents/), v DB je iba metadata
-- a vytazeny text, ktory ide do promptu pri posudzovani vhodnosti.
-- ============================================================
CREATE TABLE IF NOT EXISTS job.user_documents (
    id             SERIAL PRIMARY KEY,
    user_id        INT          NOT NULL REFERENCES admin.users(id) ON DELETE CASCADE,
    doc_type       VARCHAR(20)  NOT NULL DEFAULT 'cv',
                   -- 'cv' | 'cover_letter' | 'certificate' | 'reference' | 'portfolio' | 'other'
    title          VARCHAR(200) NOT NULL,
    filename       VARCHAR(255) NOT NULL,        -- nazov suboru na serveri
    original_name  VARCHAR(255) NOT NULL,        -- ako sa subor volal u usera
    mime_type      VARCHAR(100) NOT NULL,
    size_bytes     INT          NOT NULL,
    lang           VARCHAR(5)   REFERENCES job.languages(code),

    -- vytazeny text (PDF/DOCX -> text), vstup pre AI posudzovanie
    extracted_text TEXT,
    extracted_at   TIMESTAMP,
    extract_error  TEXT,

    -- ktore CV sa pouzije pri posudzovani vhodnosti (prave jedno na usera)
    is_primary     BOOLEAN      NOT NULL DEFAULT FALSE,
    use_for_ai     BOOLEAN      NOT NULL DEFAULT TRUE,

    note           TEXT,
    created_at     TIMESTAMP    NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMP    NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS user_documents_user_idx ON job.user_documents(user_id, doc_type);
-- najviac jeden hlavny dokument daneho typu na usera
CREATE UNIQUE INDEX IF NOT EXISTS user_documents_primary_idx
    ON job.user_documents(user_id, doc_type) WHERE is_primary;

-- ============================================================
-- JOB.USER_PREFERENCES - preferencie ako VOLNY TEXT
--
-- Hlavny vstup pre posudzovanie je volny text, ktory user napise vlastnymi
-- slovami ("hladam pracu v IT do 30 km od Dunajskej Luznej, najlepsie na
-- polovicny uvazok, nechcem call centrum"). Spracuje ho model spolu s CV
-- a inzeratom.
--
-- Struktúrovane polia (user_preferences, user_pref_*) z migracie 001 ostavaju
-- ako VOLITELNY doplnok — sluzia na rychle SQL predfiltrovanie inzeratov
-- (napr. neposielat do modelu ponuky 300 km daleko). Model ich nepotrebuje.
-- ============================================================
ALTER TABLE job.user_preferences
    ADD COLUMN IF NOT EXISTS free_text TEXT;
ALTER TABLE job.user_preferences
    ADD COLUMN IF NOT EXISTS free_text_updated_at TIMESTAMP;

-- Ked user zmeni volny text, vsetky jeho posudenia treba prepocitat.
COMMENT ON COLUMN job.user_preferences.free_text IS
    'Preferencie vlastnymi slovami — hlavny vstup do AI posudzovania vhodnosti';

-- ============================================================
-- JOB.AI_MODELS - ciselnik modelov na OpenRouteri
--
-- Ktore modely su bezplatne sa v case meni, preto sa zoznam obnovuje
-- z /api/v1/models a drzi sa tu aj historia uspesnosti z laboratoria.
-- ============================================================
CREATE TABLE IF NOT EXISTS job.ai_models (
    id                SERIAL PRIMARY KEY,
    model_id          VARCHAR(150) NOT NULL UNIQUE,  -- 'google/gemma-4-31b-it:free'
    name              VARCHAR(200),
    is_free           BOOLEAN      NOT NULL DEFAULT FALSE,
    context_length    INT,
    is_enabled        BOOLEAN      NOT NULL DEFAULT TRUE,   -- pouzivat v laboratoriu
    is_default        BOOLEAN      NOT NULL DEFAULT FALSE,  -- vitaz, pouziva sa v produkcii

    -- suhrn z laboratoria (prepocitava sa po kazdom behu)
    lab_runs          INT          NOT NULL DEFAULT 0,
    lab_ok            INT          NOT NULL DEFAULT 0,
    avg_ms            INT,
    avg_score_diff    NUMERIC(5,2),      -- priemerna odchylka od mediánu ostatnych modelov
    last_tested_at    TIMESTAMP,
    last_error        TEXT,

    created_at        TIMESTAMP    NOT NULL DEFAULT NOW(),
    updated_at        TIMESTAMP    NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS ai_models_free_idx ON job.ai_models(is_free, is_enabled);
-- prave jeden predvoleny model (konstantny vyraz = najviac jeden riadok s TRUE)
CREATE UNIQUE INDEX IF NOT EXISTS ai_models_default_idx
    ON job.ai_models((TRUE)) WHERE is_default;

-- ============================================================
-- JOB.AI_PROMPTS - verziovane prompty
--
-- Aby sa dalo porovnat, ci horsi vysledok sposobil model alebo prompt.
-- ============================================================
CREATE TABLE IF NOT EXISTS job.ai_prompts (
    id          SERIAL PRIMARY KEY,
    code        VARCHAR(50)  NOT NULL,      -- 'offer_eval' | 'offer_parse' | 'translate'
    version     INT          NOT NULL,
    template    TEXT         NOT NULL,      -- s placeholdermi {offer_text}, {cv_text}, {prefs}
    note        TEXT,
    is_active   BOOLEAN      NOT NULL DEFAULT FALSE,
    created_at  TIMESTAMP    NOT NULL DEFAULT NOW(),
    UNIQUE (code, version)
);
-- prave jedna aktivna verzia od kazdeho typu promptu
CREATE UNIQUE INDEX IF NOT EXISTS ai_prompts_active_idx
    ON job.ai_prompts(code) WHERE is_active;

-- ============================================================
-- JOB.AI_EVALUATIONS - posudenie inzeratu modelom
--
-- offer_id je NULL pri laboratornom behu nad URL, ktoru este nemame v DB.
-- Vysledok sa preklapa do job.user_offer_match (scored_by='llm').
-- ============================================================
CREATE TABLE IF NOT EXISTS job.ai_evaluations (
    id            BIGSERIAL PRIMARY KEY,
    offer_id      BIGINT       REFERENCES job.offers(id) ON DELETE CASCADE,
    user_id       INT          REFERENCES admin.users(id) ON DELETE CASCADE,
    lab_run_id    BIGINT,                      -- FK sa doplna nizsie
    model_id      VARCHAR(150) NOT NULL,
    prompt_id     INT          REFERENCES job.ai_prompts(id),

    -- vstupna URL (laboratorium moze posudzovat este neulozeny inzerat)
    source_url    VARCHAR(500),

    -- vysledok posudenia
    score         INT,                         -- 0..100
    bucket        VARCHAR(20),                 -- 'vhodne'|'menej_vhodne'|'nevhodne'
    summary       VARCHAR(1000),               -- kratky popis vhodnosti
    pros          TEXT,                        -- JSON pole
    cons          TEXT,                        -- JSON pole
    missing_skills TEXT,                       -- JSON pole: co useru chyba
    reasons       TEXT,                        -- JSON pole {code,label,points}

    -- vytazene udaje o inzerate (model vie aj parsovat)
    parsed        TEXT,                        -- JSON: profesia, mzda, jazyky, typ uvazku...

    -- prevadzkove udaje
    status        VARCHAR(20)  NOT NULL DEFAULT 'ok',  -- 'ok'|'failed'|'invalid_json'|'timeout'
    error         TEXT,
    raw_response  TEXT,                        -- surova odpoved modelu (ladenie)
    prompt_tokens INT,
    completion_tokens INT,
    total_tokens  INT,
    cost_usd      NUMERIC(10,6),
    took_ms       INT,

    created_at    TIMESTAMP    NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS ai_evaluations_offer_idx ON job.ai_evaluations(offer_id, created_at DESC);
CREATE INDEX IF NOT EXISTS ai_evaluations_user_idx  ON job.ai_evaluations(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS ai_evaluations_model_idx ON job.ai_evaluations(model_id, created_at DESC);

-- ============================================================
-- JOB.AI_LAB_RUNS - laboratorium: porovnanie modelov na jednej URL
--
-- Jeden beh = jedna URL inzeratu posudena N modelmi.
-- Modely sa volaju postupne (free modely maju limit poziadaviek za minutu).
-- ============================================================
CREATE TABLE IF NOT EXISTS job.ai_lab_runs (
    id             BIGSERIAL PRIMARY KEY,
    user_id        INT          NOT NULL REFERENCES admin.users(id) ON DELETE CASCADE,
    source_url     VARCHAR(500) NOT NULL,
    source_id      INT          REFERENCES job.sources(id),
    offer_id       BIGINT       REFERENCES job.offers(id) ON DELETE SET NULL,
    prompt_id      INT          REFERENCES job.ai_prompts(id),

    -- co sa modelu poslalo (aby sa dal beh zopakovat identicky)
    offer_text     TEXT,
    offer_title    VARCHAR(300),
    offer_html     TEXT,                        -- komplet HTML tak, ako bolo na zdroji
    cv_document_id INT          REFERENCES job.user_documents(id) ON DELETE SET NULL,
    cv_text        TEXT,                        -- kopia CV v case behu
    prefs_text     TEXT,                        -- kopia volneho textu preferencii v case behu
    input_chars    INT,

    status         VARCHAR(20)  NOT NULL DEFAULT 'running',
                   -- 'running' | 'done' | 'failed' | 'cancelled'
    models_total   INT          NOT NULL DEFAULT 0,
    models_done    INT          NOT NULL DEFAULT 0,
    models_ok      INT          NOT NULL DEFAULT 0,
    error          TEXT,

    started_at     TIMESTAMP    NOT NULL DEFAULT NOW(),
    finished_at    TIMESTAMP
);
CREATE INDEX IF NOT EXISTS ai_lab_runs_user_idx ON job.ai_lab_runs(user_id, started_at DESC);

-- prepojenie evaluacii na laboratorny beh (az teraz, ked ai_lab_runs existuje)
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ai_evaluations_lab_run_fk') THEN
        ALTER TABLE job.ai_evaluations
            ADD CONSTRAINT ai_evaluations_lab_run_fk
            FOREIGN KEY (lab_run_id) REFERENCES job.ai_lab_runs(id) ON DELETE CASCADE;
    END IF;
END $$;
CREATE INDEX IF NOT EXISTS ai_evaluations_lab_idx ON job.ai_evaluations(lab_run_id);

-- ============================================================
-- Rozsirenie user_offer_match o vazbu na AI posudenie
-- ============================================================
ALTER TABLE job.user_offer_match
    ADD COLUMN IF NOT EXISTS ai_evaluation_id BIGINT REFERENCES job.ai_evaluations(id) ON DELETE SET NULL;
ALTER TABLE job.user_offer_match
    ADD COLUMN IF NOT EXISTS model_id VARCHAR(150);

-- ============================================================
-- Zakladny prompt na posudenie inzeratu (verzia 1)
-- ============================================================
INSERT INTO job.ai_prompts (code, version, template, note, is_active) VALUES
('offer_eval', 1,
'Si personalny poradca. Posud, ako velmi sa uchadzacovi hodi tato pracovna ponuka.

## Co uchadzac hlada (jeho vlastne slova)
{prefs_text}

## Zivotopis uchadzaca
{cv_text}

## Pracovna ponuka
{offer_text}

## Uloha
Vrat VYLUCNE JSON objekt, ziadny text navyse, ziadne markdown znacky:
{
  "score": <cele cislo 0-100, ako velmi sa ponuka hodi uchadzacovi>,
  "bucket": "<vhodne | menej_vhodne | nevhodne>",
  "summary": "<1-2 vety po slovensky, preco je alebo nie je vhodna>",
  "pros": ["<co hovori pre ponuku>"],
  "cons": ["<co hovori proti>"],
  "missing_skills": ["<co uchadzacovi chyba oproti poziadavkam>"],
  "parsed": {
    "profession": "<odbor prace>",
    "employment_type": "<tpp | dohoda | zivnost | brigada | internship>",
    "remote_type": "<onsite | hybrid | remote>",
    "salary_min": <cislo alebo null>,
    "salary_max": <cislo alebo null>,
    "salary_period": "<month | hour | year | null>",
    "locations": ["<mesto>"],
    "languages": [{"code": "<en>", "level": "<A1-C2>", "required": true}],
    "seniority": "<junior | medior | senior | null>",
    "is_agency": <true ak inzerat zadava personalna agentura, inak false>
  }
}

Hodnot triezvo a riad sa hlavne tym, co uchadzac napisal vlastnymi slovami —
je to zavaznejsie ako zivotopis. Ak vyslovne nieco odmieta, daj nizke skore aj
ked by inak ponuka sedela. bucket: 0-25 nevhodne, 26-59 menej_vhodne, 60-100 vhodne.
V summary sa odvolaj na to, co uchadzac hlada.',
'Prva verzia — volny text preferencii + CV + inzerat, posudenie a parsovanie naraz', TRUE)
ON CONFLICT (code, version) DO NOTHING;

-- ============================================================
-- VERZIA
-- ============================================================
INSERT INTO admin.schema_versions (version, description)
VALUES (2, 'Dokumenty usera (CV) + AI posudzovanie cez OpenRouter: ai_models, ai_prompts, ai_evaluations, ai_lab_runs')
ON CONFLICT (version) DO NOTHING;
