# ZADANIE — projekt JOB

Agregátor pracovných ponúk zo slovenských pracovných portálov (v 1. fáze **www.profesia.sk**),
so **scraperom bežiacim v pravidelných intervaloch**, vlastnou databázou inzerátov a
**user manažmentom prevzatým z BetClub** (schéma `admin.*`).

Legenda stavov: ✅ = v produkcii (main) | 🟠 = iba develop | 🔲 = TODO

---

## 1. Cieľ

- V pravidelných intervaloch (cron) sťahovať pracovné inzeráty z profesia.sk.
- Ukladať ich do PostgreSQL — s históriou, dedupláciou a detekciou zmien.
- Umožniť používateľom definovať si **vlastné vyhľadávacie profily** (lokalita, kľúčové
  slová, typ úväzku, mzda) a dostávať **notifikácie na nové ponuky** (web push / e-mail).
- Poskytnúť webové UI (React PWA) na prehliadanie, filtrovanie, označovanie ponúk
  (uložené / nezaujíma ma / reagoval som).
- Voliteľne: automatické **skórovanie vhodnosti** ponuky voči profilu používateľa
  (pravidlové skórovanie ako v `agent_brigady`, neskôr LLM cez OpenRouter).

## 2. Technologický stack (zhodný s BetClub)

| Vrstva | Technológia |
|---|---|
| DB | PostgreSQL (Websupport), schémy `admin` + `job` |
| API | PHP 8, štruktúra `api/v1/...`, helpers `db.php` / `auth.php` / `response.php` |
| Auth | JWT + `token_version`, bcrypt heslá |
| Frontend | React 19 + Vite + `vite-plugin-pwa`, react-router |
| Scraper | Python 3 (requests + BeautifulSoup / lxml), zápis priamo do DB |
| Cron | `api/cron/*.php` + Python scraper spúšťaný z cronu |
| Notifikácie | Web Push (VAPID) + e-mail (rovnaký `mailer.php`) |
| Hosting | job.fellow.sk (prod) / devjob.fellow.sk (develop) |

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
- Surové HTML/JSON detailu sa ukladá do `job.offer_raw` kvôli re-parsovaniu bez
  opätovného sťahovania.

### 3.5 Interval

- Default: **každé 2 hodiny** medzi 06:00 a 22:00 (SK čas), zoznamy s `count_days=1`.
- Raz denne v noci: „full sync" s `count_days=7` na doplnenie toho, čo uniklo.
- Interval je konfigurovateľný per zdroj v `job.sources.scrape_interval_minutes`.

## 4. User manažment (prevzatý z BetClub)

Schéma **`admin.*` sa preberá 1:1** z `betclub/api/migrations/001_init.sql` a nadväzujúcich:

- `admin.users` — username (zmena iba raz), bcrypt heslo, meno, e-mail, telefón,
  avatar, role (`user`/`admin`), `is_active`, `token_version`, web push subscription
- `admin.invites` — pozvánkové tokeny (registrácia iba na pozvanie)
- `admin.password_reset_tokens` — jednorazový reset hesla cez e-mail
- `admin.login_logs` — audit prihlásení (IP, user agent, env)
- `admin.notification_settings` — per user, per typ notifikácie (push/e-mail)
- `admin.user_push_subscriptions` — viac zariadení na používateľa

**Nepreberá sa:** `admin.friend_groups` / `admin.group_members` (tipovacie skupiny nemajú
v JOB zmysel). Ak by v budúcnosti vznikli „zdieľané zoznamy ponúk", dajú sa doplniť.

## 5. Dátový model — prehľad tabuliek (schéma `job`)

| Tabuľka | Účel |
|---|---|
| `job.sources` | číselník portálov (profesia.sk, pracazarohom.sk, …) + interval |
| `job.companies` | zamestnávatelia (deduplikované podľa názvu + slug) |
| `job.locations` | číselník lokalít (obec, okres, kraj, GPS) |
| `job.offers` | hlavná tabuľka inzerátov |
| `job.offer_locations` | M:N — ponuka môže mať viac miest výkonu |
| `job.offer_raw` | surové HTML/JSON detailu (archív pre re-parsing) |
| `job.offer_history` | zmeny sledovaných polí v čase (mzda, titul, stav) |
| `job.tags` + `job.offer_tags` | štítky (home office, bez životopisu, TPP, dohoda…) |
| `job.scrape_runs` | log každého behu scrapera |
| `job.search_profiles` | vyhľadávacie profily používateľa |
| `job.profile_matches` | výsledky matchovania ponuka × profil vrátane skóre |
| `job.user_offer_status` | osobný stav ponuky (uložená / skryté / reagoval som) |
| `job.notification_log` | čo, komu a kedy bolo odoslané |

Detailné DDL: [api/migrations/001_init.sql](api/migrations/001_init.sql)

## 6. API endpointy (návrh)

```
POST   /api/v1/auth/login              prihlásenie (JWT)
POST   /api/v1/auth/register           registrácia cez invite token
POST   /api/v1/auth/password-reset     žiadosť o reset hesla
GET    /api/v1/profile                 profil používateľa
GET    /api/v1/offers                  zoznam ponúk (filtre, stránkovanie)
GET    /api/v1/offers/{id}             detail ponuky
POST   /api/v1/offers/{id}/status      označiť uložená/skrytá/reagoval som
GET    /api/v1/search-profiles         moje vyhľadávacie profily
POST   /api/v1/search-profiles         vytvoriť profil
PUT    /api/v1/search-profiles/{id}    upraviť
DELETE /api/v1/search-profiles/{id}    zmazať
GET    /api/v1/matches                 nové ponuky zodpovedajúce mojim profilom
GET    /api/v1/admin/scrape-runs       admin: história behov scrapera
POST   /api/v1/admin/scrape-run        admin: manuálne spustenie zberu
GET    /api/v1/admin/sources           admin: správa zdrojov a intervalov
```

## 7. Fázy realizácie

| # | Fáza | Stav |
|---|---|---|
| 1 | Založenie projektu, DB schéma `admin` + `job`, migrácia 001 | 🔲 |
| 2 | Python scraper profesia.sk — zoznamy + detaily, zápis do DB | 🔲 |
| 3 | Cron + `job.scrape_runs`, deaktivácia zmiznutých ponúk | 🔲 |
| 4 | PHP API: auth (prevzatý z BetClub) + `/offers` | 🔲 |
| 5 | React PWA: login, zoznam ponúk, filtre, detail | 🔲 |
| 6 | Vyhľadávacie profily + matchovanie + skórovanie | 🔲 |
| 7 | Notifikácie (web push + e-mail) na nové zhody | 🔲 |
| 8 | Admin sekcia (zdroje, behy scrapera, používatelia) | 🔲 |
| 9 | Ďalšie portály (pracazarohom.sk, kariera.sk, …) | 🔲 |

## 8. Právne a etické poznámky

- Obsah inzerátov je autorským dielom zadávateľov; **ukladáme ho pre osobné použitie
  a zobrazujeme vždy s odkazom na originál** na profesia.sk.
- Neposkytovať dáta tretím stranám a neprevádzkovať verejný klon portálu.
- Pred nasadením skontrolovať aktuálne `https://www.profesia.sk/robots.txt` a podmienky
  používania; rate limit držať nízky, aby zber nezaťažoval portál.
- Kontaktné údaje z inzerátov (meno, e-mail, telefón kontaktnej osoby) sú osobné údaje —
  buď ich neukladať, alebo ukladať s obmedzenou dobou uchovávania.

## 9. Pracovné pravidlá projektu

1. Push iba na `develop`, do `main` nikdy automaticky — iba na explicitný pokyn.
2. Komunikácia a commit správy v slovenčine, formát `vX.YZ - popis`.
3. Po každej zmene aktualizovať tento súbor (stavy ✅ / 🟠 / 🔲).
