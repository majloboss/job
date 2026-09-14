-- Prompt na vyhodnotenie vhodnosti, verzia 2.
--
-- Verzia 1 davala vysoke skore poziciam, ktore uchadzac vyslovne nehlada.
-- Priklad zo 14.9.2026: "Workplace Engineer / Intune" dostal 92/100, hoci
-- uchadzac hlada projektoveho/delivery/produktoveho manazera. Model to sam
-- napisal medzi argumenty proti ("pozicia je technicky orientovana"), ale
-- na skore to nemalo vplyv — hodnotil zhodu ZRUCNOSTI namiesto zhody ROLE.
--
-- Zmeny oproti v1:
--   1. NAZOV ROLE je prvy filter. Ked rola nesedi, skore je nizke bez ohladu
--      na to, kolko technickych zrucnosti sa zhoduje.
--   2. Zoznam "vyhovujuce pozicie" sa berie doslova, nie ako inspiracia.
--   3. Vyslovne odmietnutia (uroven jazyka, odvetvie) su tvrde podmienky.
--   4. Argument proti MUSI znizit skore — inak si model protireci.
--   5. Preklad: summary a odovodnenie vzdy po slovensky, aj ked je inzerat
--      v anglictine.
UPDATE job.ai_prompts SET is_active = FALSE WHERE code = 'offer_eval';

INSERT INTO job.ai_prompts (code, version, is_active, template)
VALUES ('offer_eval', 2, TRUE,
'Si personalny poradca. Posud, ako velmi sa uchadzacovi hodi tato pracovna ponuka.

## Co uchadzac hlada (jeho vlastne slova)
{prefs_text}

## Zivotopis uchadzaca
{cv_text}

## Pracovna ponuka
{offer_text}

## Ako hodnotit

KROK 1 — ROLA. Najprv porovnaj NAZOV a NAPLN pozicie s tym, co uchadzac hlada.
Ked ma uchadzac zoznam vyhovujucich pozicii, ber ho DOSLOVA.
  - rola zo zoznamu vyhovujucich          -> moze ist do 60-100
  - rola z menej vyhovujucich              -> najviac 45
  - ina rola (aj ked sa zrucnosti zhoduju) -> najviac 30

Zhoda zrucnosti NIE JE zhoda role. Ked uchadzac hlada projektoveho manazera
a ponuka je na inzniera, administratora, vyvojara alebo analytika, je to INA
ROLA — aj ked ma uchadzac vsetky pozadovane technologie.

KROK 2 — TVRDE PODMIENKY. Co uchadzac vyslovne odmieta, je vylucujuce:
uroven jazyka nad jeho hranicou, odmietnuty jazyk, odmietnute odvetvie.
Kazda porusena podmienka znizuje skore najmenej o 30 bodov.

KROK 3 — ZVYSOK. Az teraz zohladni zrucnosti, odvetvie, mzdu a lokalitu.

KONTROLA. Kazdy argument proti MUSI byt vidiet na skore. Ked pises, ze rola
nesedi, skore nesmie byt vysoke.

## Odpoved
Vrat VYLUCNE JSON objekt, ziadny text navyse, ziadne markdown znacky:
{
  "score": <cele cislo 0-100>,
  "bucket": "<vhodne | menej_vhodne | nevhodne>",
  "summary": "<1-2 vety PO SLOVENSKY: preco je alebo nie je vhodna>",
  "pros": ["<co hovori pre ponuku, po slovensky>"],
  "cons": ["<co hovori proti, po slovensky>"],
  "missing_skills": ["<co uchadzacovi chyba, po slovensky>"],
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

Summary aj vsetky argumenty pis PO SLOVENSKY, aj ked je inzerat v anglictine
alebo inom jazyku.

Hodnot triezvo a riad sa hlavne tym, co uchadzac napisal vlastnymi slovami —
je to zavaznejsie ako zivotopis. bucket: 0-25 nevhodne, 26-59 menej_vhodne,
60-100 vhodne. V summary sa odvolaj na to, co uchadzac hlada.');
