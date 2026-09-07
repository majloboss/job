# AI posudzovanie vhodnosti cez OpenRouter

## Princíp

Vhodnosť inzerátu neposudzuje pevný algoritmus, ale jazykový model. Dostane tri veci:

1. **Preferencie používateľa ako voľný text** — čo hľadá, napísané vlastnými slovami
2. **Životopis** — text vyťažený z nahratého CV
3. **Inzerát** — text stiahnutý zo zdrojového portálu

a vráti JSON so skóre 0–100, zaradením, krátkym zhrnutím, argumentmi pre/proti,
chýbajúcimi zručnosťami a rozparsovanými údajmi o inzeráte.

Model je použitý zámerne aj na **parsovanie** — vie sa zorientovať, aj keď portál zmení
štruktúru stránky, na rozdiel od pevného parsera.

## Prečo voľný text namiesto formulára

Používateľ napíše `„hľadám prácu v IT do 30 km od Dunajskej Lužnej, najlepšie na
polovičný úväzok, nechcem call centrum ani prácu na zmeny"` a model tomu rozumie
vrátane negácií a odtieňov, ktoré by sa do zaškrtávacích políčok nezmestili.

Štruktúrované polia (`job.user_preferences`, `job.user_pref_*` z migrácie 001) ostávajú
ako **voliteľný doplnok** — slúžia na rýchle SQL predfiltrovanie, aby sa do modelu
neposielali ponuky 300 km ďaleko. Model ich nepotrebuje.

Voľný text je v `job.user_preferences.free_text`. Pri jeho zmene sa zmažú doterajšie
riadky `job.user_offer_match` daného používateľa — posudky sa musia prepočítať.

## Dokumenty používateľa

`job.user_documents` — súbor na disku (`api/uploads/documents/`), v DB metadata
a **vyťažený text** (`extracted_text`), ktorý ide do promptu.

| Typ | `doc_type` |
|---|---|
| životopis | `cv` |
| motivačný list | `cover_letter` |
| certifikát | `certificate` |
| referencia | `reference` |
| portfólio | `portfolio` |
| iné | `other` |

- Podporované: PDF, DOCX, DOC, ODT, RTF, TXT, JPG, PNG (max 10 MB)
- **Hlavný dokument** (`is_primary`) je práve jeden na typ — garantuje partial unique index
- `use_for_ai` určuje, či dokument ide do promptu

**Vyťaženie textu** ([api/helpers/doc_text.php](../api/helpers/doc_text.php)) je zámerne bez
externých knižníc — hosting na Websupporte ich nemá. DOCX a ODT sú ZIP archívy, ktoré
otvorí vstavaný `ZipArchive`. PDF sa skúsi cez `pdftotext`, ak je na serveri; inak
vlastný minimálny extraktor, ktorý rozbalí FlateDecode prúdy a vyzbiera reťazce
z operátorov `Tj`/`TJ`.

Zo **skenovaného** PDF ani z obrázka sa text nevyťaží — dokument sa aj tak uloží,
`extract_error` povie prečo a do promptu nejde.

## Laboratórium modelov

Obrazovka [web/src/pages/AiLab.jsx](../web/src/pages/AiLab.jsx) — zadáš URL inzerátu,
vyberieš modely a porovnáš, ako ho posúdia.

### Prečo existuje

Ktoré modely sú na OpenRouteri bezplatné, sa v čase mení — model, ktorý bol včera zadarmo,
dnes vráti `This model is unavailable for free`. Namiesto hádania sa dá zoznam natiahnuť
naživo a otestovať na skutočnom inzeráte.

### Priebeh

```
POST /v1/admin/ai-lab            založí beh: stiahne inzerát, pripraví prompt
POST /v1/admin/ai-lab?step=1     otestuje JEDEN model
GET  /v1/admin/ai-lab?run_id=5   výsledky behu
```

Modely sa volajú **postupne, po jednom**. Bezplatné modely majú limit požiadaviek za
minútu a paralelné volanie by skončilo na HTTP 429. Frontend preto cyklí cez `?step=1`
a priebežne dopĺňa tabuľku — vidno, ako výsledky pribúdajú.

### Čo tabuľka ukazuje

| Stĺpec | Význam |
|---|---|
| Skóre | 0–100 od daného modelu |
| **Odchýlka** | rozdiel oproti mediánu ostatných modelov |
| Vhodnosť | `vhodne` / `menej_vhodne` / `nevhodne`, alebo dôvod zlyhania |
| Zhrnutie | jedna-dve vety od modelu |
| Tokeny, Čas | prevádzkové náklady |

**Odchýlka je hlavné kritérium.** Model, ktorý sa drží mediánu (do 5 bodov, zelené),
hodnotí ako ostatné. Veľká odchýlka znamená, že model hodnotí inak — čo môže byť lepšie
aj horšie, preto sa dá riadok rozkliknúť a pozrieť, čo model uviedol ako argumenty
a čo vyťažil z inzerátu.

Kliknutím na riadok sa zobrazí detail: pre/proti, chýbajúce zručnosti a vyťažené údaje
(profesia, úväzok, miesto, mzda, jazyky, agentúra).

### Čo sa ukladá

Každé posúdenie ide do `job.ai_evaluations` — vrátane surovej odpovede pri chybe, tokenov
a času. Beh je v `job.ai_lab_runs` spolu s textom inzerátu, kópiou CV a preferencií
v čase behu, takže sa dá presne zopakovať.

`job.ai_models` drží priebežnú štatistiku každého modelu (úspešné behy, priemerný čas).

### Výber víťaza

Po porovnaní zapíšeš víťaza do `api/config/openrouter.php` ako `OPENROUTER_MODEL`,
prípadne nastavíš `is_default` v `job.ai_models`.

## Verziované prompty

`job.ai_prompts` — `code` + `version`, práve jedna aktívna verzia od typu (partial unique
index). Umožňuje rozlíšiť, či horší výsledok spôsobil model alebo prompt.

Placeholdery: `{prefs_text}`, `{cv_text}`, `{offer_text}`.

Prvá verzia `offer_eval` je v migrácii [002_documents_ai.sql](../api/migrations/002_documents_ai.sql).

## Bezpečnosť

- **Kľúč OpenRoutera** je v `api/config/openrouter.php`, ktorý je v `.gitignore`
- **Sťahovať sa dá len z portálov v `job.sources`** (`or_check_url()`) — bez toho by
  endpoint slúžil na dopyty do vnútornej siete servera
- Vstup do modelu sa kráti na 14 000 znakov (`OR_MAX_INPUT_CHARS`) — free modely majú
  malý kontext
- Nahraté dokumenty sú v `api/uploads/`, ktorý je v `.gitignore`

## Obmedzenia

- Free modely majú nižšie limity požiadaviek za minútu — porovnanie 20 modelov trvá minúty
- Niektoré modely nevrátia platný JSON; zaznamená sa `status='invalid_json'` a surová odpoveď
- Model môže skóre nafabulovať — preto sa porovnáva s mediánom ostatných
- Sťahovanie inzerátu nespúšťa JavaScript; ak portál dopĺňa obsah skriptom, model ho neuvidí
