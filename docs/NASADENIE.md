# Nasadenie projektu JOB

Postup pri zakladaní hostingu a databázy. Prostredie kopíruje BetClub.

## 1. Hosting (Websupport / fellow.sk)

Dve subdomény:

| Prostredie | Doména | FTP priečinok | Vetva |
|---|---|---|---|
| produkcia | `job.fellow.sk` | `/sub/job/` | `main` |
| vývoj | `devjob.fellow.sk` | `/sub/devjob/` | `develop` |

**Pozor na `devjob`, nie `dev_job`** — bez podčiarkovníka. Detekcia prostredia v
[api/v1/auth/login.php](../api/v1/auth/login.php) sa riadi predponou `devjob`.

Požiadavky na PHP: **8.0+** s rozšíreniami `pdo_pgsql`, `curl`, `mbstring`, `zip`, `zlib`.
Overíš ich cez `health.php` (krok 6).

### Presmerovanie na API

V koreni každej subdomény musí `/api/*` smerovať na `api/index.php`. Ak to hosting
nerobí sám, `.htaccess` v `/sub/job/api/`:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^(.*)$ index.php [QSA,L]
```

## 2. Databáza

Dve databázy na PostgreSQL:

| Prostredie | Názov |
|---|---|
| produkcia | `DB-JOB` |
| vývoj | `DB-JOB-DEV` |

**DSN musí obsahovať port** — `db.r5.websupport.sk:5432`.

Migrácie sa spúšťajú **v poradí** a sú idempotentné (dajú sa pustiť opakovane):

```bash
psql -h db.r5.websupport.sk -p 5432 -U <user> -d DB-JOB-DEV \
     -f api/migrations/001_init.sql
psql -h db.r5.websupport.sk -p 5432 -U <user> -d DB-JOB-DEV \
     -f api/migrations/002_documents_ai.sql
```

Ak nemáš `psql`, obsah oboch súborov sa dá vložiť do webového SQL konzoly
Websupportu (Adminer/pgAdmin) — sú to čisté SQL skripty bez závislostí.

Po dobehnutí má `admin.schema_versions` dva riadky (verzie 1 a 2) a v schémach
`admin` + `job` je **37 tabuliek**.

## 3. Konfiguračné súbory

Tri súbory sa **nikdy nenahrávajú deployom** (sú v `.gitignore` aj v `exclude`
workflow-u) — na server ich dáš raz ručne cez FTP:

### `api/config/db.php`

Podľa [db.example.php](../api/config/db.example.php):

```php
<?php
define('DB_HOST',    'db.r5.websupport.sk');
define('DB_PORT',    '5432');
define('DB_NAME',    'DB-JOB-DEV');
define('DB_USER',    '...');
define('DB_PASS',    '...');
define('JWT_SECRET', '...');            // náhodný reťazec, min. 32 znakov
define('APP_URL',    'https://devjob.fellow.sk');
define('CRON_SECRET','...');            // náhodný token pre cron a setup
```

`JWT_SECRET` a `CRON_SECRET` vygeneruješ napr. `openssl rand -hex 32`.
**V produkcii a na deve musia byť rôzne** — inak token z dev prostredia
platí aj v produkcii.

### `api/config/openrouter.php`

Podľa [openrouter.example.php](../api/config/openrouter.example.php). Kľúč z
<https://openrouter.ai/keys>. Model sa vyberie neskôr cez laboratórium.

### `api/uploads/documents/`

Priečinok na nahraté CV. Vytvorí sa sám pri prvom nahratí, ale musí byť
zapisovateľný (práva `0775`). Do gitu nepatrí.

## 4. GitHub Actions

V repozitári `majloboss/job` → Settings → Secrets → Actions:

| Secret | Hodnota |
|---|---|
| `FTP_PASSWORD` | heslo k FTP účtu `ftp.fellow.sk` |

Je to **jediný secret** — konfigurácia s heslami k DB ide na server ručne (krok 3),
nie cez Actions.

Workflow [deploy.yml](../.github/workflows/deploy.yml) sa spustí po pushi na
`develop` (→ devjob) alebo `main` (→ job). Postaví React appku a nahrá ju cez FTP;
assety idú pred `index.html`, aby stránka nikdy nežiadala súbor, ktorý na serveri
ešte nie je.

## 5. Prvý admin

Po dobehnutí migrácií:

```
https://devjob.fellow.sk/api/setup_once.php?token=<CRON_SECRET>&username=majlo&password=<heslo>
```

Funguje **iba kým je `admin.users` prázdna** — druhý raz vráti 409. Po použití
súbor zmaž zo servera; do produkcie sa nenahráva vôbec (je v `exclude`).

## 6. Kontrola nasadenia

```
https://devjob.fellow.sk/api/health.php?token=<CRON_SECRET>
```

Vráti stav spojenia s DB, zoznam aplikovaných migrácií, počet tabuliek, dostupné
PHP rozšírenia a či je priečinok na dokumenty zapisovateľný.

Bez tokenu vráti len `{"ok":true,"api":"beží"}` — podrobnosti o serveri nepatria
na verejnosť.

### Čo skontrolovať

| Položka | Očakávané |
|---|---|
| `db_spojenie` | `true` |
| `migracie` | verzie 1 a 2 |
| `pocet_tabuliek` | 37 |
| `rozsirenia.pdo_pgsql` | `true` — bez toho nefunguje nič |
| `rozsirenia.zip` | `true` — vyťaženie textu z DOCX a ODT |
| `rozsirenia.zlib` | `true` — vyťaženie textu z PDF |
| `rozsirenia.curl` | `true` — OpenRouter a sťahovanie inzerátov |
| `pdftotext` | `true` je lepšie, `false` znamená slabší extraktor PDF |
| `uploads.zapisovatelny` | `true` |

## 7. Poradie krokov

1. Vytvoriť subdomény `job.fellow.sk` a `devjob.fellow.sk`
2. Vytvoriť databázy `DB-JOB` a `DB-JOB-DEV`
3. Spustiť migrácie 001 a 002 (najprv na DEV)
4. Nahrať `db.php` a `openrouter.php` cez FTP do `/sub/devjob/api/config/`
5. Nastaviť `FTP_PASSWORD` v GitHub Secrets
6. Push na `develop` → deploy prebehne sám
7. `health.php` → overiť, že všetko svieti
8. `setup_once.php` → vytvoriť admina, potom súbor zmazať

Produkciu (`main`) nasadzovať až keď dev prostredie funguje — a **len na
explicitný pokyn**, nikdy automaticky.
