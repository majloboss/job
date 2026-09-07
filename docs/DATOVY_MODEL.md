# Dátový model JOB

DDL: [api/migrations/001_init.sql](../api/migrations/001_init.sql)

## Prehľad

Dve schémy:
- **`admin.*`** — user manažment, prevzatý 1:1 z BetClub (kompatibilný kód auth vrstvy)
- **`job.*`** — číselníky, inzeráty, scraping, preferencie, vypočítaná vhodnosť

## Číselníky

| Tabuľka | Obsah | Poznámka |
|---|---|---|
| `job.sources` | zdrojové portály | interval zberu, rate limit, `default_period_days` per portál |
| `job.companies` | firmy ponúkajúce prácu | `is_agency` + `agency_source` (ako sme to zistili) |
| `job.company_aliases` | názov firmy na konkrétnom portáli | tá istá firma má na rôznych portáloch iný názov/slug |
| `job.professions` | profesie, hierarchicky (`parent_id`) | 20 odborov naseedovaných, podprofesie sa dopĺňajú |
| `job.profession_mappings` | text odboru z portálu → naša profesia | učí sa pri scrapovaní |
| `job.languages` | jazyky (ISO 639-1) | 12 naseedovaných |
| `job.language_levels` | úrovne A1–C2 s `rank` | `rank` umožňuje porovnanie „aspoň B2" |
| `job.locations` | lokality (obec/okres/kraj/krajina) | GPS `lat`/`lon` — bez nich sa nedá počítať vzdialenosť |
| `job.tags` | štítky ponuky | home office, bez životopisu, na zmeny… |

### Prečo je firma oddelená od aliasu

Ten istý zamestnávateľ vystupuje na profesia.sk ako „ABC s.r.o." a na pracazarohom.sk ako
„ABC". `job.companies.name_norm` (lower, bez diakritiky a právnej formy) je deduplikačný
kľúč; `job.company_aliases` drží, ako sa firma volá na ktorom portáli.

## Inzerát: originál + preklad

Kľúčové rozhodnutie: **obsah nie je v `job.offers`, ale v `job.offer_content`**, jeden riadok
na jazyk.

```
job.offers            (id, title, mzda, typ úväzku, časy, stav zberu…)
  └─ job.offer_content (offer_id, lang, is_original, html_full, text_full, …)
       ├─ lang='en', is_original=TRUE   ← komplet HTML tak, ako bolo na zdroji
       └─ lang='sk', is_original=FALSE  ← preložená verzia
```

- Slovenský inzerát má **jeden** riadok (`lang='sk'`, `is_original=TRUE`) — neprekladá sa.
- Anglický inzerát má **dva** — originál sa nikdy neprepisuje, preklad sa dá kedykoľvek
  pregenerovať lepším modelom.
- Partial unique index `offer_content_one_original_idx` garantuje práve jeden originál.
- Pohľad **`job.v_offers_sk`** vráti pre zobrazenie automaticky slovenskú verziu, ak
  existuje, inak originál (`title_display`, `text_display`, `html_display`,
  `html_original`, `has_translation`).

`html_full` = presné HTML zo zdroja (archív, re-parsing bez opätovného sťahovania),
`text_full` = ten istý obsah ako čistý text (fulltext, vstup pre preklad a skórovanie).

## Časy

| Stĺpec | Význam |
|---|---|
| `offers.published_at` | kedy bol inzerát zverejnený na portáli |
| `offers.published_at_raw` | pôvodný text („Pred 1 minútou") — kvôli auditu parsovania |
| `offers.created_at` | **kedy sme ho pridali do našej DB** |
| `offers.last_seen_at` | kedy sme ho naposledy videli v zoznamoch |
| `offers.closed_at` | kedy zmizol (po `missing_runs` behoch bez nálezu) |
| `offers.detail_fetched_at` | NULL = detail ešte nestiahnutý |
| `offers.translated_at` | NULL = preklad ešte neurobený |

Inzeráty sa nikdy nemažú — po zmiznutí sa iba `is_active = FALSE`.

## Preferencie používateľa

| Tabuľka | Obsah |
|---|---|
| `job.user_preferences` | typ práce, mzda, max. dojazd, agentúry áno/nie, náplň práce (voľný text), prah pre notifikácie |
| `job.user_pref_professions` | profesie s váhou (záporná = nechcem) |
| `job.user_pref_locations` | lokality s váhou a vlastným polomerom |
| `job.user_pref_languages` | jazyky, ktoré user ovláda, s úrovňou |
| `job.user_pref_keywords` | kľúčové slová pre náplň práce (záporná váha = vylučujúce) |

Domovská lokalita je na používateľovi: `admin.users.home_location_id` + voliteľne presné
`home_lat`/`home_lon` (ak zadá adresu, nie len obec).

## Vypočítaná vhodnosť

`job.user_offer_match` — jeden riadok na dvojicu (user, inzerát):

| Stĺpec | Význam |
|---|---|
| `score` | 0–100 |
| `bucket` | `vhodne` / `menej_vhodne` / `nevhodne` |
| `summary` | krátky popis vhodnosti, 1–2 vety (zobrazí sa v zozname) |
| `reasons` | JSON pole `[{"code","label","points"}]` — rozpad skóre |
| `distance_km` | vzdialenosť od lokality usera, NULL = neznáma |
| `scored_by` | `rules` / `llm` / `manual` |
| `scorer_version` | verzia algoritmu — umožní prepočítať len staré skóre |

**Vzdialenosť** počíta SQL funkcia `job.offer_distance_km(user_id, offer_id)` — Haversine
cez `job.distance_km()`, berie najbližšie z miest výkonu inzerátu a presné GPS usera, ak
ich má, inak GPS jeho domovskej obce.

Rozdiel oproti `job.user_offer_status`: `user_offer_match` je **počítaná** vhodnosť,
`user_offer_status` je **ručná** akcia usera (uložené / skryté / reagoval som / pozvánka
na pohovor).

## Kedy sa čo prepočítava

| Udalosť | Akcia |
|---|---|
| Zber nájde nový inzerát | vypočítaj `user_offer_match` pre všetkých aktívnych userov |
| User zmení preferencie | prepočítaj jeho riadky pre všetky aktívne inzeráty |
| Nasadí sa nová verzia skórovania | prepočítaj riadky so starším `scorer_version` |
| Inzerát sa deaktivuje | riadky ostávajú (história), len sa nezobrazujú |

## Beh zberu

`job.scrape_runs` = jeden riadok na beh:

- `run_type` — `incremental` (cron), `full` (nočný), `manual` (spustený používateľom)
- `period_days` — **za aké obdobie sa ťahalo**; manuálny beh si ho volí
- `triggered_by` — NULL pri crone, `user_id` pri manuálnom spustení
- počítadlá: nájdené / nové / aktualizované / zatvorené / detaily / preklady / zhody / chyby

`job.scrape_run_offers` drží, ktorý beh videl ktorý inzerát (`new`/`updated`/`seen`) —
dohľadateľnosť pôvodu každého záznamu.

### Manuálne spustenie

Používateľ v admin sekcii vyberie **portál** + **obdobie v dňoch** a spustí zber. Scraper
prejde zoznamy za dané obdobie a stiahne len to, čo v DB ešte nie je (podľa
`source_id + external_id`), plus detaily inzerátov s `detail_fetched_at IS NULL`.
