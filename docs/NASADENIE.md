# Nasadenie projektu JOB

Postup pri zakladaní hostingu a databázy. Prostredie kopíruje BetClub.

## 1. Hosting (Websupport / fellow.sk)

Jedno prostredie:

| Doména | FTP priečinok | Vetva |
|---|---|---|
| `job.fellow.sk` | `/sub/job/` | `main` |

Projekt zámerne nemá oddelený dev — pracuje sa priamo na `main`.

Požiadavky na PHP: **8.0+** s rozšíreniami `pdo_pgsql`, `curl`, `mbstring`, `zip`, `zlib`.
Overíš ich cez `health.php` (krok 6).

### Presmerovanie (.htaccess)

Štyri `.htaccess` súbory sú v repe a nahrajú sa deployom automaticky:

| Umiestnenie | Účel |
|---|---|
| `web/public/.htaccess` → `/sub/job/` | React router — `/lab` a `/dokumenty` fungujú aj po F5 |
| `api/.htaccess` | `/api/*` smeruje na `index.php` |
| `api/config/.htaccess` | zakáže prístup ku konfigurákom s heslami cez web |
| `api/uploads/.htaccess` | zakáže priame stiahnutie nahratých CV |

Posledné dva sú dôležité: bez nich by bol `db.php` s heslom k databáze
a nahraté životopisy dostupné cez URL komukoľvek.

## 2. Databáza

Jedna databáza na PostgreSQL — názov podľa toho, čo si vytvoril na Websupporte.

**DSN musí obsahovať port** — `db.r5.websupport.sk:5432`.

Migrácie sa spúšťajú **v poradí** a sú idempotentné (dajú sa pustiť opakovane):

```bash
psql -h <host> -p 5432 -U dbjob-admin -d <db> -f api/migrations/001_init.sql
psql -h <host> -p 5432 -U dbjob-admin -d <db> -f api/migrations/002_documents_ai.sql
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
define('DB_PORT',    '5432');           // DSN musí obsahovať port
define('DB_NAME',    '...');
define('DB_USER',    'dbjob-admin');
define('DB_PASS',    '...');
define('JWT_SECRET', '...');            // openssl rand -hex 32
define('APP_URL',    'https://job.fellow.sk');
define('CRON_SECRET','...');            // openssl rand -hex 32
```

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

Workflow [deploy.yml](../.github/workflows/deploy.yml) sa spustí po pushi na `main`.
Postaví React appku a nahrá ju cez FTP; assety idú pred `index.html`, aby stránka
nikdy nežiadala súbor, ktorý na serveri ešte nie je.

Konfiguráky s heslami (`db.php`, `openrouter.php`) a `uploads/` sú v `exclude` —
deploy ich neprepíše ani nezmaže.

## 5. Prvý admin

Po dobehnutí migrácií:

```
https://job.fellow.sk/api/setup_once.php?token=<CRON_SECRET>&username=majlo&password=<heslo>
```

Funguje **iba kým je `admin.users` prázdna** — druhý raz vráti 409.
Po použití súbor zmaž zo servera.

## 6. Kontrola nasadenia

```
https://job.fellow.sk/api/health.php?token=<CRON_SECRET>
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

1. ✅ Subdoména `job.fellow.sk` → `/sub/job/`
2. ✅ Databáza na PostgreSQL, používateľ `dbjob-admin`
3. ✅ `db.php` a `openrouter.php` v `/sub/job/api/config/`
4. 🔲 Spustiť migrácie 001 a 002
5. ✅ `FTP_PASSWORD` nastavený v GitHub Secrets
6. 🔲 Push na `main` → deploy prebehne sám
7. 🔲 `health.php?token=<CRON_SECRET>` → overiť nasadenie
8. 🔲 `setup_once.php` → vytvoriť admina, potom súbor zmazať
