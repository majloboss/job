-- ============================================================
-- Migration 006: offer_parse verzia 2 — odstranenie zastupnych znakov,
-- ktore modely kopirovali do vystupu
--
-- PRECO: prvy ostry test na inzerate O5355999 (6 bezplatnych modelov)
-- ukazal, ze nemotron-3.5-lightning vratil nevalidny JSON — v odpovedi
-- zostalo doslova:
--     "required": <true|false>
--
-- Sablona pouzivala <...> ako zastupny znak. V JSONE ale spicate zatvorky
-- nic neznamenaju, takze slabsi model ich povazoval za sucast pozadovaneho
-- tvaru a odpisal ich. Silnejsie modely to pochopili spravne, cim je chyba
-- este zakernejsia: prejavi sa az na modeli, ktory sa raz dostane do poradia.
--
-- Oprava: ziadne <...> vo vzorovom JSONE. Namiesto toho realne ukazkove
-- hodnoty a vysvetlenie typov pod nim. Model tak vidi platny JSON, ktory
-- moze napodobnit, nie schemu, ktoru musi interpretovat.
--
-- Druhy poznatok z testu: dva modely sa rozisli na 'employment_type'
-- (tpp vs. dohoda) pri inzerate, kde uvazok nie je vyslovne uvedeny.
-- Doplnene pravidlo, ze pri neuvedenom udaji sa vracia null, nehada sa.
-- ============================================================

BEGIN;

-- Stara verzia prestava byt aktivna; nemaze sa, aby sa dali porovnat
-- vysledky starych behov s novymi.
UPDATE job.ai_prompts SET is_active = FALSE
 WHERE code = 'offer_parse' AND version = 1;

INSERT INTO job.ai_prompts (code, version, template, note, is_active) VALUES
('offer_parse', 2,
'Si presny extraktor udajov z pracovnych inzeratov. Z textu inzeratu vytiahni
strukturovane udaje. NEHODNOT vhodnost pre uchadzaca, len zapis to, co je v inzerate.

## Text inzeratu
{offer_text}

## Uloha
Vrat VYLUCNE JSON objekt v presne takomto tvare, ziadny text navyse,
ziadne markdown znacky. Toto je UKAZKA so vzorovymi hodnotami:

{
  "title": "Vodic kamionu MKD",
  "company_name": "Dopravne sluzby s.r.o.",
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
- employment_type: tpp, dohoda, zivnost, brigada, internship, alebo null
- seniority: junior, medior, senior, alebo null
- remote_type: onsite, hybrid, remote
- languages[].level: A1, A2, B1, B2, C1, C2, alebo null
- languages[].required: true alebo false
- salary_min, salary_max, positions_count: cisla bez uvodzoviek, alebo null
- published_at, valid_until: datum v tvare RRRR-MM-DD, alebo null

PRAVIDLA:
- Co v inzerate NIE JE, daj null alebo prazdne pole. NIC SI NEVYMYSLAJ
  a NEHADAJ. Ked uvazok nie je uvedeny, employment_type je null — nie tpp.
- Mzdu preved na cisla: "Od 1 600 EUR/mesiac" znamena salary_min 1600,
  salary_max null, salary_period "month". "1 200 - 1 500 EUR" znamena
  min 1200, max 1500. Hodinova mzda ("6,50 EUR/hod") ma period "hour".
- published_at: ked je uvedeny relativny cas ("Pred 2 dnami", "Dnes"),
  daj null a povodny text zapis do published_at_raw — datum dopocita
  aplikacia, ktora pozna cas zberu.
- locations: uved miesto tak, ako je v inzerate. Ked je uvedena aj mestska
  cast, zapis oboje ako dve polozky.
- keywords: 5-15 vyrazov, ktore vystihuju NAPLN prace (co bude clovek
  robit), nie benefity ani nazov firmy. Male pismena, zakladny tvar slova.
- technologies: len konkretne pomenovane veci — programovacie jazyky,
  softver, stroje, vozidla, certifikaty. Ked ziadne nie su, vrat [].
- is_agency je true, ked firma hlada pracovnika PRE KLIENTA alebo ma
  v nazve "personalna agentura", "recruitment", "HR services".
- summary_sk pis VZDY po slovensky, aj ked je inzerat v inom jazyku.
- text_sk vypln IBA ked original nie je po slovensky; inak daj null.
  Ked prekladas, prekladaj verne a nic nevynechavaj.',
'Verzia 2 — vzorovy JSON namiesto zastupnych znakov <...>, ktore slabsie
modely kopirovali do vystupu; doplnene pravidlo nehadat neuvedeny uvazok',
TRUE)
ON CONFLICT (code, version) DO NOTHING;

INSERT INTO admin.schema_versions (version, description)
VALUES (6, 'offer_parse v2: vzorovy JSON namiesto zastupnych znakov')
ON CONFLICT (version) DO NOTHING;

COMMIT;
