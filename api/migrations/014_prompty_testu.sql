-- ============================================================
-- Migration 014: prompty pre test modelov
--
-- Dva prompty pre dve ulohy testu:
--   test_parse     — vytazenie udajov z inzeratu vratane HTML a prekladu
--   test_vhodnost  — posudenie vhodnosti pre konkretneho cloveka
--
-- Su ODDELENE od produkcnych 'offer_parse' a 'offer_eval', hoci su im
-- podobne. Dovod: test porovnava modely a musi im dat presne tie iste
-- zadanie po celu dobu behu. Keby sa pouzil produkcny prompt a ten sa
-- medzitym doladil, vysledky z rozneho casu by sa nedali porovnat.
--
-- Prompt 'test_parse' navyse ziada aj HTML inzeratu v peknej strukture,
-- co produkcny nerobi — tam HTML uklada scraper.
-- ============================================================

BEGIN;

INSERT INTO job.ai_prompts (code, version, template, note, is_active) VALUES
('test_parse', 1,
'Si presny extraktor udajov z pracovnych inzeratov. Z textu inzeratu vytiahni
vsetko, co sa da. NEHODNOT vhodnost pre uchadzaca, len zapis to, co v inzerate je.

## Text inzeratu
{offer_text}

## Uloha
Vrat VYLUCNE JSON objekt v presne takomto tvare, ziadny text navyse,
ziadne markdown znacky. Toto je UKAZKA so vzorovymi hodnotami:

{
  "nazov": "Vodic kamionu MKD",
  "firma": "Dopravne sluzby s.r.o.",
  "datum_zverejnenia": "2026-09-08",
  "datum_zverejnenia_text": "Pred 2 dnami",
  "orig_lang": "sk",
  "sumar": "Dopravna firma hlada vodica kamionu na medzinarodne trasy po zapadnej Europe. Praca je na turnusy 3 tyzdne von a tyzden doma. Vyzaduje sa vodicsky preukaz C+E a prax aspon dva roky. Firma poskytuje moderny vozovy park a stravne. Nastup je mozny ihned.",
  "html_original": "<h2>Napln prace</h2><ul><li>Vedenie kamionu</li></ul><h2>Poziadavky</h2><ul><li>Vodicsky preukaz C+E</li></ul>",
  "html_sk": "<h2>Napln prace</h2><ul><li>Vedenie kamionu</li></ul><h2>Poziadavky</h2><ul><li>Vodicsky preukaz C+E</li></ul>",
  "mzda_text": "Od 1 600 EUR/mesiac",
  "mzda_min": 1600,
  "mzda_max": null,
  "mzda_mena": "EUR",
  "mzda_obdobie": "month",
  "nastup": "ihned",
  "uvazok": "tpp",
  "uvazky": ["tpp"],
  "mesto": "Bratislava",
  "lokalita_zvysok": "Priemyselna 12, Bratislava-Ruzinov, moznost obcasnej prace z domu"
}

POVOLENE HODNOTY:
- orig_lang: sk, cs, en, de, hu, pl, uk
- mzda_mena: EUR, CZK, USD
- mzda_obdobie: month, hour, day, year, alebo null
- uvazok: hlavny (prvy) typ z uvazky, alebo null
- uvazky: zoznam z hodnot tpp, dohoda, zivnost, brigada, internship;
  prazdny zoznam ked uvazok nie je uvedeny
- mzda_min, mzda_max: cisla bez uvodzoviek, alebo null
- datum_zverejnenia: datum v tvare RRRR-MM-DD, alebo null

PRAVIDLA:
- Co v inzerate NIE JE, daj null alebo prazdne pole. NIC SI NEVYMYSLAJ.
- sumar: NAJVIAC 10 VIET po slovensky. Zhrn, co bude clovek robit, kde,
  za akych podmienok a za kolko. Pis vecne, nie reklamne.
- html_original: cely inzerat prepisany do CISTEHO HTML so strukturou —
  nadpisy <h2>, odseky <p>, zoznamy <ul><li>. Ziadne triedy, styly ani
  skripty. Zachovaj poradie a obsah povodneho inzeratu.
- html_sk: to iste po SLOVENSKY. Ked je original uz po slovensky, vloz
  presne to iste ako do html_original — zobrazenie sa tak nemusi
  rozhodovat, ktory stlpec vziat.
- datum_zverejnenia: ked je uvedeny relativny cas ("Pred 2 dnami", "Dnes"),
  daj null a povodny text zapis do datum_zverejnenia_text.
- mzdu preved na cisla: "Od 1 600 EUR/mesiac" znamena mzda_min 1600,
  mzda_max null, mzda_obdobie "month". Hodinova ("6,50 EUR/hod") ma
  obdobie "hour", denna sadzba ("180 EUR/den") ma "day".
- mesto: IBA nazov mesta alebo obce, bez ulice a cisla.
- lokalita_zvysok: vsetko ostatne o mieste vykonu prace — ulica, mestska
  cast, okres, poznamka o praci z domu.
- Inzerat casto ponuka VIAC uvazkov naraz ("plny uvazok, na dohodu").
  Vtedy uved vsetky do uvazky a prvy zopakuj v uvazok.',
'Test modelov — tazenie udajov vratane HTML a prekladu', TRUE),

('test_vhodnost', 1,
'Si skuseny personalny poradca. Posud, ako velmi sa TOMUTO konkretnemu
cloveku hodi tato pracovna ponuka.

## Co uchadzac hlada (jeho vlastne slova)
{prefs_text}

## Zivotopis uchadzaca
{cv_text}

## Pracovna ponuka
{offer_text}

## Uloha
Vrat VYLUCNE JSON objekt, ziadny text navyse, ziadne markdown znacky:

{
  "skore": 72,
  "zaradenie": "vhodne",
  "hodnotenie": "Ponuka dobre sedi na doterajsiu prax v projektovom riadeni IT. Poziadavka na anglictinu je splnena, mzda je nad uchadzacovym minimom. Chyba skusenost so SAP, ktoru inzerat uvadza ako vyhodu, nie podmienku.",
  "pre": ["prax v projektovom riadeni zodpoveda poziadavke", "mzda nad ocakavanim"],
  "proti": ["chyba skusenost so SAP", "praca vyzaduje cestovanie"]
}

POVOLENE HODNOTY:
- skore: cele cislo 0-100
- zaradenie: vhodne (60-100), menej_vhodne (26-59), nevhodne (0-25)

PRAVIDLA:
- Riad sa HLAVNE tym, co uchadzac napisal vlastnymi slovami — je to
  zavaznejsie nez zivotopis. Ked nieco vyslovne odmieta, daj nizke skore,
  aj keby ponuka inak sedela.
- hodnotenie: 3-5 viet po slovensky. Konkretne, s odkazom na to, co
  uchadzac hlada a co ma v zivotopise. Ziadne vseobecne frazy.
- pre / proti: kazdy argument jedna kratka veta. Ked nic nie je, prazdne pole.
- Hodnot triezvo. Vsetko nemoze byt vhodne.',
'Test modelov — posudenie vhodnosti pre konkretneho cloveka', TRUE)
ON CONFLICT (code, version) DO NOTHING;

INSERT INTO admin.schema_versions (version, description)
VALUES (14, 'Prompty test_parse a test_vhodnost pre test modelov')
ON CONFLICT (version) DO NOTHING;

COMMIT;
