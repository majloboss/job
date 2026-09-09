-- ============================================================
-- Migration 008: prompt v4 — nazov a firmu berie scraper, nie model
--
-- PRECO: test promptu v3 na inzerate O5355999 ukazal, ze dva modely
-- KOMOLIA SLOVENCINU:
--   ling-3.0-flash-fin    "BISTRONOMY hlada cacnika/cacnicku"  (spravne: casnika)
--                          a v kluc. slovach "cacnik"
--   ling-3.0-flash-sante  "paroVINie vina"                      (spravne: parovanie)
--   nex-n2.5-mini         "degUtacne menu"                      (spravne: degustacne)
--
-- Je to horsie nez rozpor v strukture: takto skomoleny nazov by skoncil
-- v databaze a vo vyhladavani. A pri vyhodnocovani zhody by presiel bez
-- povsimnutia — nazov sa porovnava ako celok, takze preklep vyzera len
-- ako "ina hodnota".
--
-- RIESENIE: nazov pozicie a nazov firmy su PRESNE RETAZCE zo stranky.
-- Scraper ich vytiahne z HTML (h1, meta) a model ich nema preco prepisovat.
-- Model ma ratat len to, co sa musi ODVODIT: mzda z textu, uvazok, kluc.
-- slova, suhrn, preklad. Odstranuje to celu triedu chyby.
--
-- Polia title a company_name z promptu preto miznu. V job.offers zostavaju
-- — plni ich scraper.
--
-- DRUHA ZMENA: "na dohodu (brigady)" sa vracia ako DVE hodnoty
-- (dohoda + brigada), aby sa dali brigady filtrovat zvlast.
-- ============================================================

BEGIN;

UPDATE job.ai_prompts SET is_active = FALSE
 WHERE code = 'offer_parse' AND version = 3;

INSERT INTO job.ai_prompts (code, version, template, note, is_active) VALUES
('offer_parse', 4,
'Si presny extraktor udajov z pracovnych inzeratov. Z textu inzeratu vytiahni
strukturovane udaje. NEHODNOT vhodnost pre uchadzaca, len zapis to, co je v inzerate.

Nazov pozicie a nazov firmy NEVRACAJ — tie si aplikacia berie priamo zo stranky.
Tvojou ulohou je to, co sa musi odvodit z textu.

## Text inzeratu
{offer_text}

## Uloha
Vrat VYLUCNE JSON objekt v presne takomto tvare, ziadny text navyse,
ziadne markdown znacky. Toto je UKAZKA so vzorovymi hodnotami:

{
  "is_agency": false,
  "published_at": "2026-09-08",
  "published_at_raw": "Pred 2 dnami",
  "valid_until": null,
  "profession": "Vodic kamionu",
  "industry": "Doprava a logistika",
  "orig_lang": "sk",
  "salary_raw": "Od 1 600 EUR/mesiac",
  "salary_min": 1600,
  "salary_max": null,
  "salary_currency": "EUR",
  "salary_period": "month",
  "employment_type": "tpp",
  "employment_types": ["tpp", "dohoda"],
  "contract_duration": null,
  "education_level": "stredoskolske",
  "seniority": "medior",
  "remote_type": "onsite",
  "positions_count": 2,
  "start_date": "ihned",
  "locations": ["Bratislava", "Trnava"],
  "languages": [{"code": "en", "level": "B1", "required": true}],
  "keywords": ["vedenie kamionu", "medzinarodna doprava", "nakladka"],
  "technologies": ["tachograf", "vodicsky preukaz C+E"],
  "tags": ["na zmeny"],
  "requirements": ["vodicsky preukaz C+E", "prax 2 roky"],
  "benefits": ["stravne listky", "13. plat"],
  "summary_sk": "Dopravna firma hlada vodica kamionu na medzinarodne trasy. Nastup ihned, mzda od 1600 EUR.",
  "text_sk": null
}

POVOLENE HODNOTY:
- is_agency: true alebo false (bez uvodzoviek)
- orig_lang: sk, cs, en, de, hu, pl, uk
- salary_currency: EUR, CZK, USD
- salary_period: month, hour, year, alebo null
- employment_type: hlavny (prvy) typ z employment_types, alebo null
- employment_types: zoznam z hodnot tpp, dohoda, zivnost, brigada,
  internship; prazdny zoznam ked uvazok nie je uvedeny
- seniority: junior, medior, senior, alebo null
- remote_type: onsite, hybrid, remote
- languages[].level: A1, A2, B1, B2, C1, C2, alebo null
- languages[].required: true alebo false
- salary_min, salary_max, positions_count: cisla bez uvodzoviek, alebo null
- published_at, valid_until: datum v tvare RRRR-MM-DD, alebo null

PRAVIDLA:
- Co v inzerate NIE JE, daj null alebo prazdne pole. NIC SI NEVYMYSLAJ
  a NEHADAJ. Ked uvazok nie je uvedeny, employment_types je [] a
  employment_type null — nie tpp.
- Inzerat casto ponuka VIAC uvazkov naraz ("plny uvazok, na dohodu").
  Vtedy uved VSETKY do employment_types v poradi, v akom su v inzerate,
  a prvy z nich zopakuj v employment_type.
- Formulacia "na dohodu (brigady)" alebo "dohoda/brigada" znamena DVE
  hodnoty: "dohoda" aj "brigada". Uved obe.
- Mzdu preved na cisla: "Od 1 600 EUR/mesiac" znamena salary_min 1600,
  salary_max null, salary_period "month". "1 200 - 1 500 EUR" znamena
  min 1200, max 1500. Hodinova mzda ("6,50 EUR/hod") ma period "hour".
- published_at: ked je uvedeny relativny cas ("Pred 2 dnami", "Dnes"),
  daj null a povodny text zapis do published_at_raw — datum dopocita
  aplikacia, ktora pozna cas zberu.
- locations: uved iba NAZOV obce alebo mesta, bez ulice a cisla domu.
  Ked je uvedena aj mestska cast, zapis ju ako druhu polozku v tvare,
  v akom je v inzerate.
- keywords: 5-15 vyrazov, ktore vystihuju NAPLN prace (co bude clovek
  robit), nie benefity ani nazov firmy. Male pismena, zakladny tvar slova.
  Pis SPISOVNOU SLOVENCINOU, pozor na diakritiku a preklepy.
- technologies: len konkretne pomenovane veci — programovacie jazyky,
  softver, stroje, vozidla, certifikaty. Ked ziadne nie su, vrat [].
- is_agency je true, ked firma hlada pracovnika PRE KLIENTA alebo ma
  v nazve "personalna agentura", "recruitment", "HR services".
- summary_sk pis VZDY po slovensky, aj ked je inzerat v inom jazyku.
  Pozor na spravny pravopis a diakritiku.
- text_sk vypln IBA ked original nie je po slovensky; inak daj null.
  Ked prekladas, prekladaj verne a nic nevynechavaj.',
'Verzia 4 — nazov pozicie a firmy berie scraper z HTML (modely ich komolili);
"na dohodu (brigady)" = dohoda + brigada; dorazy na spisovnu slovencinu',
TRUE)
ON CONFLICT (code, version) DO NOTHING;

INSERT INTO admin.schema_versions (version, description)
VALUES (8, 'Prompt offer_parse v4: bez nazvu a firmy (berie ich scraper), brigada+dohoda')
ON CONFLICT (version) DO NOTHING;

COMMIT;
