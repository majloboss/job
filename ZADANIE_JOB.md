# ZADANIE — projekt JOB

Agregátor pracovných ponúk zo slovenských pracovných portálov (v 1. fáze **www.profesia.sk**),
so **scraperom bežiacim v pravidelných intervaloch**, vlastnou databázou inzerátov a
**user manažmentom prevzatým z BetClub** (schéma `admin.*`).

Legenda stavov: ✅ = nasadené | 🟠 = hotové v repe, nenasadené | 🔲 = TODO

---

## 1. Cieľ

- V pravidelných intervaloch (cron) sťahovať pracovné inzeráty z profesia.sk.
- Ukladať **komplet inzerát v HTML tak, ako bol na zdroji**; ak nie je po slovensky,
  doplniť aj **preloženú slovenskú verziu** (originál sa nikdy neprepisuje).
- Evidovať **čas zverejnenia** na portáli aj **čas pridania do našej DB**.
- Viesť číselníky: zdrojové portály, firmy (s rozlíšením **agentúra vs. priamy
  zamestnávateľ**), profesie, jazyky a úrovne, lokality.
- Používateľ **nahrá životopis a ďalšie dokumenty** do aplikácie.
- Používateľ zadá **preferencie ako voľný text** — vlastnými slovami, čo hľadá.
- Vhodnosť každého inzerátu posúdi **jazykový model cez OpenRouter**: dostane preferencie,
  životopis a inzerát, vráti skóre, stručný popis a argumenty pre/proti.
- Ku každému inzerátu sa eviduje **vzdialenosť od lokality používateľa**.
- Každých X minút sa dotiahnu nové inzeráty a rovno sa im vypočíta vhodnosť.
- **Ručné spustenie** vybraného portálu za zvolené obdobie — dotiahne to, čo ešte nemáme.
- Notifikácie na nové vhodné ponuky (web push / e-mail) a webové UI (React PWA).

## 2. Technologický stack (zhodný s BetClub)

| Vrstva | Technológia |
|---|---|
| DB | PostgreSQL (Websupport), schémy `admin` + `job` |
| API | PHP 8, štruktúra `api/v1/...`, helpers `db.php` / `auth.php` / `response.php` |
| Auth | JWT + `token_version`, bcrypt heslá |
| Frontend | React 19 + Vite + `vite-plugin-pwa`, react-router |
| Scraper | Python 3 (requests + BeautifulSoup / lxml), zápis priamo do DB |
| Cron | `api/cron/*.php` + Python scraper spúšťaný z cronu |
| Posudzovanie | OpenRouter (bezplatné modely), výber modelu cez laboratórium |
| Notifikácie | Web Push (VAPID) + e-mail (rovnaký `mailer.php`) |
| Hosting | job.fellow.sk (`/sub/job/`), jedno prostredie |
| Databáza | PostgreSQL na Websupporte |
| Deploy | GitHub Actions -> FTP z vetvy `main`, jediný secret `FTP_PASSWORD` |

Postup nasadenia: [docs/NASADENIE.md](docs/NASADENIE.md)

## 3. Zber dát z profesia.sk

### 3.1 Zdroj a URL štruktúra

- Zoznam ponúk: `https://www.profesia.sk/praca/[filtre]/?page_num=N`
  - filtre v ceste: lokalita (`bratislava`), typ (`na-dohodu-brigady`), odbor
  - query parametre: `count_days=7` (ponuky za posledných N dní), `radius=radius100`
- Detail ponuky: `https://www.profesia.sk/praca/<company-slug>/O<offer_id>`
  - **`O<offer_id>` je stabilný externý identifikátor** → kľúč pre dedupláciu.

### 3.2 Polia dostupné v zozname

`title`, `company`, `location`, `salary` (text, napr. „Od 1 600 EUR/mesiac"),
`posted_at` (relatívny čas — „Pred 1 minútou", „Dnes"), tagy (práca z domu,
„Reagujte bez životopisu"), typ úväzku.

### 3.3 Polia z detailu ponuky

Náplň práce, požiadavky (vzdelanie, jazyky, prax, vodičský preukaz), benefity,
informácie o zamestnávateľovi, dátum zverejnenia, kontaktná osoba, počet
voľných miest.

### 3.4 Pravidlá scrapovania

- **Rešpektovať `robots.txt` a rozumný rate limit** (1 request / 1–2 s, jedno vlákno).
- Vlastný `User-Agent` s kontaktom.
- Dvojfázový zber: (1) prejsť zoznamy → zistiť `external_id`, (2) stiahnuť detail iba pre
  ponuky, ktoré ešte nemáme (alebo im vypršala `detail_fetched_at`).
- Každý beh = jeden riadok v `job.scrape_runs` (počty: nájdené / nové / aktualizované / chyby).
- Ponuka, ktorá v N po sebe idúcich behoch nie je v zozname, sa označí `is_active = FALSE`
  a nastaví `closed_at` (netreba mazať — držíme históriu).
- Komplet HTML detailu sa ukladá do `job.offer_content` (`is_original = TRUE`) — slúži
  zároveň ako archív pre re-parsovanie bez opätovného sťahovania.
- Po stiahnutí detailu sa deteguje jazyk; ak nie je slovenský, zaradí sa do fronty na
  preklad (`translated_at IS NULL`).

### 3.5 Interval a ručné spustenie

- Default: **každé 2 hodiny** medzi 06:00 a 22:00 (SK čas), zoznamy s `count_days=1`.
- Raz denne v noci: „full sync" s `count_days=7` na doplnenie toho, čo uniklo.
- Interval je konfigurovateľný per zdroj v `job.sources.scrape_interval_minutes`,
  východiskové obdobie v `job.sources.default_period_days`.
- **Ručné spustenie:** používateľ v admin sekcii vyberie **portál** a **obdobie v dňoch**;
  beh sa zapíše ako `run_type = 'manual'` s `period_days` a `triggered_by = user_id`.
  Stiahne sa len to, čo v DB ešte nie je (podľa `source_id + external_id`), plus detaily
  inzerátov s `detail_fetched_at IS NULL`.
- Po každom zbere sa pre nové inzeráty prepočíta `job.user_offer_match`.

## 4. User manažment (prevzatý z BetClub)

Schéma **`admin.*` sa preberá 1:1** z `betclub/api/migrations/001_init.sql` a nadväzujúcich:

- `admin.users` — username (zmena iba raz), bcrypt heslo, meno, e-mail, telefón,
  avatar, role (`user`/`admin`), `is_active`, `token_version`, web push subscription
- `admin.invites` — pozvánkové tokeny (registrácia iba na pozvanie)
- `admin.password_reset_tokens` — jednorazový reset hesla cez e-mail
- `admin.login_logs` — audit prihlásení (IP, user agent, env)
- `admin.notification_settings` — per user, per typ notifikácie (push/e-mail)
- `admin.user_push_subscriptions` — viac zariadení na používateľa

**Rozšírenie oproti BetClub:** `admin.users` má navyše `home_location_id` a voliteľne presné
`home_lat`/`home_lon` — domovskú lokalitu, od ktorej sa počíta vzdialenosť k ponukám.

**Nepreberá sa:** `admin.friend_groups` / `admin.group_members` (tipovacie skupiny nemajú
v JOB zmysel). Ak by v budúcnosti vznikli „zdieľané zoznamy ponúk", dajú sa doplniť.

## 5. Dátový model

Detailný popis: [docs/DATOVY_MODEL.md](docs/DATOVY_MODEL.md) — DDL: [api/migrations/001_init.sql](api/migrations/001_init.sql)

### Číselníky

| Tabuľka | Účel |
|---|---|
| `job.sources` | zdrojové portály + interval zberu + rate limit |
| `job.companies` | firmy ponúkajúce prácu, vrátane príznaku **agentúra** (`is_agency`) |
| `job.company_aliases` | názov tej istej firmy na rôznych portáloch |
| `job.professions` | hierarchický číselník profesií (odbor → profesia) |
| `job.profession_mappings` | mapovanie textu odboru z portálu na našu profesiu |
| `job.languages` | číselník jazykov (ISO 639-1) |
| `job.language_levels` | úrovne A1–C2 s poradím na porovnávanie |
| `job.locations` | lokality (obec/okres/kraj/krajina) vrátane GPS |
| `job.tags` | štítky ponuky (home office, na zmeny, bez životopisu…) |

### Inzeráty

| Tabuľka | Účel |
|---|---|
| `job.offers` | hlavná tabuľka — mzda, typ úväzku, časy, stav zberu |
| `job.offer_content` | **komplet HTML inzerátu ako na zdroji + slovenský preklad** (riadok na jazyk) |
| `job.offer_locations` | M:N miesta výkonu práce |
| `job.offer_languages` | jazykové požiadavky inzerátu (jazyk + úroveň + povinný/výhodou) |
| `job.offer_tags` | štítky |
| `job.offer_history` | zmeny sledovaných polí v čase (mzda, titul, stav) |

**Originál sa nikdy neprepisuje.** Ak je inzerát v angličtine, pribudne druhý riadok
`job.offer_content` s `lang='sk'` — preklad sa dá kedykoľvek pregenerovať lepším modelom.
Pohľad `job.v_offers_sk` vráti pre zobrazenie slovenskú verziu, ak existuje, inak originál.

**Časy:** `published_at` = zverejnenie na portáli, `created_at` = pridanie do našej DB,
`last_seen_at` = posledné videnie v zoznamoch, `closed_at` = kedy ponuka zmizla.

### Scraping

| Tabuľka | Účel |
|---|---|
| `job.scrape_runs` | log každého behu: typ, obdobie v dňoch, počítadlá, kto spustil |
| `job.scrape_run_offers` | ktorý beh videl ktorý inzerát (`new`/`updated`/`seen`) |

### Používateľ: preferencie a vhodnosť

| Tabuľka | Účel |
|---|---|
| `job.user_preferences` | typ práce, mzda, max. dojazd, agentúry áno/nie, **náplň práce** (voľný text) |
| `job.user_pref_professions` | preferované / nechcené profesie (váha, záporná = nechcem) |
| `job.user_pref_locations` | preferované lokality s vlastným polomerom |
| `job.user_pref_languages` | jazyky, ktoré používateľ ovláda, s úrovňou |
| `job.user_pref_keywords` | kľúčové slová náplne práce (záporná váha = vylučujúce) |
| `job.user_offer_match` | **vypočítaná vhodnosť** — skóre, krátky popis, dôvody, vzdialenosť |
| `job.user_offer_status` | ručná akcia usera (uložené / skryté / reagoval som / pohovor) |
| `job.notification_log` | čo, komu a kedy bolo odoslané |

`admin.users` je rozšírená o `home_location_id` + `home_lat`/`home_lon` — domovskú lokalitu,
od ktorej sa počíta vzdialenosť.

### Funkcie

- `job.distance_km(lat1, lon1, lat2, lon2)` — Haversine, vzdialenosť v km
- `job.offer_distance_km(user_id, offer_id)` — najbližšie miesto výkonu od bydliska usera
- pohľad `job.v_offers_sk` — inzerát s obsahom v slovenčine (preklad, inak originál)

## 5a. Výpočet vhodnosti

Po každom zbere sa pre nové inzeráty vypočíta `job.user_offer_match` pre všetkých aktívnych
používateľov. Skóre 0–100, rozdelené do `vhodne` / `menej_vhodne` / `nevhodne`, s krátkym
popisom (`summary`) a rozpadom dôvodov (`reasons` ako JSON).

Vstupy do skóre: zhoda profesie, vzdialenosť vs. `max_distance_km`, typ úväzku, mzda voči
`salary_min`, jazykové požiadavky vs. znalosti usera, kľúčové slová náplne práce, penalizácia
za agentúru.

Prepočet sa spustí aj pri zmene preferencií (len pre daného usera) a pri nasadení novej
verzie skórovania (riadky so starším `scorer_version`).

Prvá verzia je **pravidlová** (`scored_by='rules'`) — vychádza zo skórovania, ktoré už je
funkčné v `agent_brigady/codes/build_data_*.py`. Neskôr voliteľne LLM (`scored_by='llm'`).

## 5b. Dokumenty a AI posudzovanie

Podrobne: [docs/AI_POSUDZOVANIE.md](docs/AI_POSUDZOVANIE.md) —
migrácia: [api/migrations/002_documents_ai.sql](api/migrations/002_documents_ai.sql)

### Dokumenty používateľa

| Tabuľka | Účel |
|---|---|
| `job.user_documents` | CV a ďalšie dokumenty — súbor na disku, v DB metadata + **vyťažený text** |

Podporované: PDF, DOCX, DOC, ODT, RTF, TXT, JPG, PNG (max 10 MB). Typy: `cv`,
`cover_letter`, `certificate`, `reference`, `portfolio`, `other`. Hlavný dokument
(`is_primary`) je práve jeden na typ. Vyťaženie textu je bez externých knižníc
(hosting ich nemá) — DOCX/ODT cez `ZipArchive`, PDF cez `pdftotext` alebo vlastný
extraktor. Zo skenu sa text nevyťaží; dokument sa uloží, ale do promptu nejde.

### Preferencie ako voľný text

`job.user_preferences.free_text` — používateľ napíše vlastnými slovami, čo hľadá.
Model rozumie aj negáciám a odtieňom, ktoré by sa do formulára nezmestili.
Štruktúrované polia ostávajú ako **voliteľný doplnok** na rýchle SQL predfiltrovanie
(neposielať do modelu ponuky 300 km ďaleko).

Zmena voľného textu zmaže doterajšie `job.user_offer_match` — posudky sa prepočítajú.

### AI posudzovanie

| Tabuľka | Účel |
|---|---|
| `job.ai_models` | číselník modelov na OpenRouteri + štatistika úspešnosti |
| `job.ai_prompts` | verziované prompty, práve jedna aktívna verzia od typu |
| `job.ai_evaluations` | výsledok posúdenia inzerátu modelom (skóre, pre/proti, tokeny) |
| `job.ai_lab_runs` | beh laboratória: jedna URL posúdená N modelmi |

Model dostane **preferencie + životopis + inzerát** a vráti JSON: skóre 0–100, zaradenie,
zhrnutie, argumenty pre/proti, chýbajúce zručnosti a **rozparsované údaje o inzeráte**
(profesia, mzda, jazyky, úväzok, agentúra). Parsovanie robí zámerne model — zorientuje sa
aj keď portál zmení štruktúru stránky.

### Laboratórium modelov

Obrazovka na porovnanie: zadáš URL inzerátu, vyberieš bezplatné modely a spustíš.
Ktoré modely sú zadarmo sa v čase mení, preto sa zoznam ťahá naživo z OpenRoutera.

Modely sa volajú **postupne, po jednom** — bezplatné majú limit požiadaviek za minútu
a paralelné volanie by skončilo na HTTP 429.

Tabuľka ukazuje skóre, **odchýlku od mediánu ostatných modelov**, zaradenie, zhrnutie,
tokeny a čas. Odchýlka je hlavné kritérium: model do 5 bodov od mediánu hodnotí ako
ostatné. Riadok sa dá rozkliknúť na detail (pre/proti, chýbajúce zručnosti, vyťažené údaje).

Víťaza zapíšeš do `api/config/openrouter.php` ako `OPENROUTER_MODEL`.


## 6. API endpointy (návrh)

```
POST   /api/v1/auth/login              prihlásenie (JWT)
POST   /api/v1/auth/register           registrácia cez invite token
POST   /api/v1/auth/password-reset     žiadosť o reset hesla
GET    /api/v1/profile                 profil používateľa
GET    /api/v1/offers                  zoznam ponúk (filtre, stránkovanie)
GET    /api/v1/offers/{id}             detail ponuky
POST   /api/v1/offers/{id}/status      označiť uložená/skrytá/reagoval som
GET    /api/v1/preferences             moje preferencie (voľný text + doplnky)
PUT    /api/v1/preferences             uložiť preferencie -> vyžiada prepočet vhodnosti
GET    /api/v1/documents               moje dokumenty (CV a ďalšie)
POST   /api/v1/documents               nahratie dokumentu (multipart, pole: file)
PATCH  /api/v1/documents               úprava metadát { id, title, doc_type, is_primary }
DELETE /api/v1/documents?id=5          zmazanie dokumentu
GET    /api/v1/matches                 moje ponuky zoradené podľa vhodnosti
GET    /api/v1/codebooks/{ciselnik}    professions | languages | locations | tags | sources
GET    /api/v1/admin/scrape-runs       admin: história behov zberu
POST   /api/v1/admin/scrape-run        admin: manuálne spustenie {source_id, period_days}
GET    /api/v1/admin/sources           admin: správa portálov a intervalov
PUT    /api/v1/admin/companies/{id}    admin: označiť firmu ako agentúru
GET    /api/v1/admin/ai-models         zoznam bezplatných modelov na OpenRouteri
POST   /api/v1/admin/ai-lab            laboratórium: založiť beh { url, models, document_id }
POST   /api/v1/admin/ai-lab?step=1     laboratórium: otestovať jeden model { run_id, model }
GET    /api/v1/admin/ai-lab?run_id=5   laboratórium: výsledky behu
```

## 7. Fázy realizácie

| # | Fáza | Stav |
|---|---|---|
| 1 | Založenie projektu, DB schéma `admin` + `job`, migrácia 001 | 🟠 |
| 1b | Dokumenty (CV) + AI posudzovanie + laboratórium modelov, migrácia 002 | 🟠 |
| 1c | Hosting, databáza, deploy workflow, prvý admin | 🟠 |
| 1d | React kostra: login, layout, dokumenty, preferencie | 🟠 |
| 2 | Naplnenie číselníkov (lokality SK s GPS, profesie, mapovania portálov) | 🔲 |
| 3 | Python scraper profesia.sk — zoznamy + detaily + HTML do `offer_content` | 🔲 |
| 4 | Detekcia jazyka + preklad EN→SK do `offer_content` | 🔲 |
| 5 | Cron + `job.scrape_runs`, deaktivácia zmiznutých ponúk | 🔲 |
| 6 | Manuálne spustenie zberu (portál + obdobie) | 🔲 |
| 7 | PHP API: auth (prevzatý z BetClub) + `/offers` + `/codebooks` | 🔲 |
| 8 | Napojenie posudzovania na zber: nové inzeráty -> `user_offer_match` | 🔲 |
| 9 | React PWA: login, zoznam ponúk podľa vhodnosti, detail, preferencie | 🔲 |
| 10 | Notifikácie (web push + e-mail) na nové vhodné ponuky | 🔲 |
| 11 | Admin sekcia (portály, behy zberu, firmy/agentúry, používatelia) | 🔲 |
| 12 | Ďalšie portály (pracazarohom.sk, kariera.sk, …) | 🔲 |

## 8. Právne a etické poznámky

- Obsah inzerátov je autorským dielom zadávateľov; **ukladáme ho pre osobné použitie
  a zobrazujeme vždy s odkazom na originál** na profesia.sk.
- Neposkytovať dáta tretím stranám a neprevádzkovať verejný klon portálu.
- Pred nasadením skontrolovať aktuálne `https://www.profesia.sk/robots.txt` a podmienky
  používania; rate limit držať nízky, aby zber nezaťažoval portál.
- Kontaktné údaje z inzerátov (meno, e-mail, telefón kontaktnej osoby) sú osobné údaje —
  buď ich neukladať, alebo ukladať s obmedzenou dobou uchovávania.

## 9. Pracovné pravidlá projektu

1. Projekt má **jedno prostredie** — pracuje sa priamo na vetve `main`.
2. Komunikácia a commit správy v slovenčine, formát `vX.YZ - popis`.
3. Po každej zmene aktualizovať tento súbor (stavy ✅ / 🔲).
