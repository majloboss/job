-- ============================================================
-- Migration 005: prompt na vytazenie udajov z inzeratu ('offer_parse')
--
-- Krok 1 aplikacie je ZBER: stiahnut inzerat a spravne z neho vytazit udaje.
-- Doterajsi prompt 'offer_eval' robil posudenie aj parsovanie naraz, co pre
-- zber nesedi z dvoch dovodov:
--   1. pri zbere este nevieme, ktoremu pouzivatelovi sa ponuka posudzuje —
--      inzerat sa tazi RAZ a posudzuje sa potom pre kazdeho zvlast
--   2. do promptu by zbytocne islo CV a preferencie, co je pri stovkach
--      inzeratov denne zbytocne minutych tokenov
--
-- CO MODEL NEROBI: portal, URL a originalny text HTML vie scraper sam a
-- uklada ich priamo. Do promptu nejdu — bola by to len prilezitost na
-- halucinaciu udaja, ktory uz mame isty.
--
-- Polia zodpovedaju stlpcom job.offers a job.offer_content, aby sa odpoved
-- dala ulozit priamo.
--
-- Zadanie: ZADANIE_JOB.md, kapitola 3
-- ============================================================

BEGIN;

-- ------------------------------------------------------------
-- Kluc pre vyhladavanie: kluc. slova, technologie a odvetvie.
--
-- Tazi ich model, lebo portal ich neuvadza jednotne — v jednom inzerate su
-- v texte naplne prace, v druhom medzi poziadavkami. Ulozene v job.offers,
-- aby sa dalo filtrovat bez joinu.
-- ------------------------------------------------------------
ALTER TABLE job.offers
    ADD COLUMN IF NOT EXISTS keywords TEXT[];
ALTER TABLE job.offers
    ADD COLUMN IF NOT EXISTS technologies TEXT[];
ALTER TABLE job.offers
    ADD COLUMN IF NOT EXISTS industry VARCHAR(100);

-- Kratky sumar prace po slovensky — vytvara ho model, nie je to vytah
-- z inzeratu. Zobrazuje sa v zozname ponuk, aby sa nemusel otvarat detail.
ALTER TABLE job.offers
    ADD COLUMN IF NOT EXISTS summary_sk TEXT;

COMMENT ON COLUMN job.offers.keywords IS
    'Kluc. slova naplne prace, vytazene modelom — vstup pre vyhladavanie';
COMMENT ON COLUMN job.offers.technologies IS
    'Konkretne technologie, nastroje a stroje spomenute v inzerate';
COMMENT ON COLUMN job.offers.summary_sk IS
    'Kratky sumar po slovensky od modelu; zobrazuje sa v zozname ponuk';

-- Vyhladavanie v kluc. slovach a technologiach bez prechadzania celej tabulky.
CREATE INDEX IF NOT EXISTS offers_keywords_idx     ON job.offers USING GIN (keywords);
CREATE INDEX IF NOT EXISTS offers_technologies_idx ON job.offers USING GIN (technologies);
CREATE INDEX IF NOT EXISTS offers_industry_idx     ON job.offers (industry);

-- ------------------------------------------------------------
-- Prompt na vytazenie udajov
-- ------------------------------------------------------------
INSERT INTO job.ai_prompts (code, version, template, note, is_active) VALUES
('offer_parse', 1,
'Si presny extraktor udajov z pracovnych inzeratov. Z textu inzeratu vytiahni
strukturovane udaje. NEHODNOT vhodnost pre uchadzaca, len zapis to, co je v inzerate.

## Text inzeratu
{offer_text}

## Uloha
Vrat VYLUCNE JSON objekt, ziadny text navyse, ziadne markdown znacky:
{
  "title": "<nazov pozicie tak, ako je v inzerate>",
  "company_name": "<nazov zamestnavatela>",
  "is_agency": <true ak inzerat zadava personalna agentura a nie priamy zamestnavatel, inak false>,
  "published_at": "<YYYY-MM-DD datum zverejnenia, alebo null>",
  "published_at_raw": "<datum zverejnenia doslovne ako v inzerate, alebo null>",
  "valid_until": "<YYYY-MM-DD dokedy ponuka plati, alebo null>",
  "profession": "<profesia, napr. Vodic kamionu>",
  "industry": "<odvetvie, napr. Doprava a logistika, IT, Zdravotnictvo, Vyroba>",
  "orig_lang": "<kod jazyka inzeratu: sk | cs | en | de | hu | pl | uk>",
  "salary_raw": "<mzda doslovne ako v inzerate, alebo null>",
  "salary_min": <cislo alebo null>,
  "salary_max": <cislo alebo null>,
  "salary_currency": "<EUR | CZK | USD>",
  "salary_period": "<month | hour | year | null>",
  "employment_type": "<tpp | dohoda | zivnost | brigada | internship | null>",
  "contract_duration": "<trvanie pracovneho pomeru, alebo null>",
  "education_level": "<pozadovane vzdelanie, alebo null>",
  "seniority": "<junior | medior | senior | null>",
  "remote_type": "<onsite | hybrid | remote>",
  "positions_count": <pocet volnych miest alebo null>,
  "start_date": "<nastup: ihned, dohodou alebo datum, alebo null>",
  "locations": ["<mesto alebo obec vykonu prace>"],
  "languages": [{"code": "<sk|en|de|...>", "level": "<A1|A2|B1|B2|C1|C2|null>", "required": <true|false>}],
  "keywords": ["<kluc. slovo naplne prace>"],
  "technologies": ["<konkretna technologia, nastroj, stroj alebo system>"],
  "tags": ["<home office|na zmeny|bez zivotopisu|vhodne pre absolventa|...>"],
  "requirements": ["<pozadovana zrucnost alebo podmienka>"],
  "benefits": ["<ponukana vyhoda>"],
  "summary_sk": "<2-3 vety po SLOVENSKY, o com praca je: co bude clovek robit, kde a za kolko>",
  "text_sk": "<cely text inzeratu prelozeny do slovenciny; ak je original uz po slovensky, vrat null>"
}

PRAVIDLA:
- Co v inzerate nie je, daj null alebo prazdne pole. NIC SI NEVYMYSLAJ.
- Mzdu preved na cisla: "Od 1 600 EUR/mesiac" -> salary_min 1600, salary_max null,
  salary_period "month". "1 200 - 1 500 EUR" -> min 1200, max 1500.
  Hodinova mzda ("6,50 EUR/hod") -> salary_period "hour".
- published_at: ak je uvedeny relativny cas ("Pred 2 dnami", "Dnes"), daj null
  a povodny text zapis do published_at_raw — datum dopocita aplikacia.
- keywords: 5-15 vyrazov, ktore vystihuju NAPLN prace (co bude clovek robit),
  nie benefity ani nazov firmy. Male pismena, zakladny tvar slova.
- technologies: len konkretne pomenovane veci — programovacie jazyky, softver,
  stroje, vozidla, certifikaty. Ked ziadne nie su, vrat prazdne pole.
- is_agency: agentura sa pozna podla toho, ze hlada pracovnika pre klienta
  alebo ma v nazve "personalna agentura", "recruitment", "HR services", "s.r.o. — nabor".
- summary_sk pis VZDY po slovensky, aj ked je inzerat v inom jazyku.
- text_sk vypln IBA ak original nie je po slovensky. Prekladaj verne, nic nevynechavaj.',
'Prva verzia — vytazenie udajov pri zbere vratane kluc. slov, technologii a prekladu',
TRUE)
ON CONFLICT (code, version) DO NOTHING;

INSERT INTO admin.schema_versions (version, description)
VALUES (5, 'Prompt offer_parse + kluc. slova, technologie, odvetvie a sumar v job.offers')
ON CONFLICT (version) DO NOTHING;

COMMIT;
