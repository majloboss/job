#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Zber inzeratov zo VSETKYCH portalov v ciselniku job.sources.

Kazdy portal ma ine HTML, takze presny parser ako pre profesia.sk sa neda
napisat pre vsetky naraz. Tento skript preto pracuje VSEOBECNE:

  1. stiahne stranku s kriteriami (job.sources.url_kriteria)
  2. najde v nej odkazy, ktore vyzeraju ako detail ponuky
  3. stiahne detail kazdej ponuky a ulozi komplet HTML

Nazov a firmu z vypisu NEHADA — na to je model, ktory bezi v druhom kroku
(api/cron/zber.php) nad ulozenym HTML. Scraper ma jedinu ulohu: doniest
obsah a nestratit ziadnu ponuku.

Deduplikacia: external_id sa odvodzuje z URL detailu. Ta je na kazdom
portali stabilna, takze ten isty inzerat sa druhy raz nestiahne.

Pouzitie:
    python scraper/zber_vsetky.py                 vsetky portaly, 50 ponuk z kazdeho
    python scraper/zber_vsetky.py --limit 20      menej na portal
    python scraper/zber_vsetky.py --portal koderia   iba jeden portal
    python scraper/zber_vsetky.py --bez-detailov  len zoznamy, detaily nestahovat
    python scraper/zber_vsetky.py --doplnit-udaje  vytaz udaje z uz ulozeneho HTML

Vyzaduje: pip install requests psycopg2-binary
"""

import argparse
import hashlib
import unicodedata
import html
import os
import re
import sys
import time
from datetime import date, datetime, timedelta
from urllib.parse import urljoin, urlparse

try:
    import requests
    import psycopg2
except ImportError as e:
    sys.exit("Chyba kniznica: %s\nSpusti: pip install requests psycopg2-binary" % e)

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
UA = "JobBot/1.0 (+https://job.fellow.sk; agregator pracovnych ponuk)"
DNES = date.today()
TERAZ = datetime.now()


# ============================================================
# Pripojenie k DB
# ============================================================
def nacitaj_db_config():
    with open(os.path.join(BASE, "api", "config", "db.php"), encoding="utf-8") as f:
        obsah = f.read()

    def hodnota(kluc):
        m = re.search(r"define\('%s'\s*,\s*'([^']*)'" % kluc, obsah)
        if not m:
            raise RuntimeError("V db.php chyba %s" % kluc)
        return m.group(1)

    return dict(host=hodnota("DB_HOST"), port=int(hodnota("DB_PORT")),
                dbname=hodnota("DB_NAME"), user=hodnota("DB_USER"),
                password=hodnota("DB_PASS"))


def pripoj_db():
    """
    Na Websupporte sa db.r5.websupport.sk ZO SERVERA preklada na 127.0.0.1
    a take lokalne spojenie SSL nepodporuje. Zvonku je SSL povinne. Skusa sa
    preto najprv require a az pri odmietnuti prefer — opacne poradie by
    pripustilo nesifrovane spojenie zvonku.
    """
    conf = nacitaj_db_config()
    posledna = None
    for rezim in ("require", "prefer"):
        try:
            return psycopg2.connect(sslmode=rezim, **conf)
        except psycopg2.OperationalError as e:
            posledna = e
            if "does not support SSL" not in str(e):
                raise
    raise posledna


# ============================================================
# Stahovanie
# ============================================================
def stiahni(session, url, timeout=30):
    """Vracia (html, trvanie_ms, velkost). Vyhodi vynimku pri chybe."""
    t0 = time.time()
    r = session.get(url, timeout=timeout, headers={
        "User-Agent": UA,
        "Accept-Language": "sk,cs;q=0.8,en;q=0.5",
        "Accept": "text/html,application/xhtml+xml",
    })
    r.raise_for_status()

    # Kodovanie: requests pri chybajucej hlavicke hada Latin-1 a slovenska
    # diakritika sa rozpadne ("pozÃ­cie" namiesto "pozície"). Ariva presne
    # taka je. Preto sa berie deklaracia zo samotneho HTML, az potom odhad.
    if not re.search(r"charset", r.headers.get("content-type", ""), re.I):
        m = re.search(rb'charset=[\"]?([\w-]+)', r.content[:2000], re.I)
        r.encoding = (m.group(1).decode("ascii", "ignore") if m else "utf-8")

    return r.text, int((time.time() - t0) * 1000), len(r.content)


def html_na_text(h):
    s = re.sub(r"<(script|style|noscript|svg|iframe)\b[^>]*>.*?</\1>", " ", h, flags=re.S | re.I)
    s = re.sub(r"<(nav|footer|header)\b[^>]*>.*?</\1>", " ", s, flags=re.S | re.I)
    s = re.sub(r"<br\s*/?>|</(p|div|li|tr|h[1-6])>", "\n", s, flags=re.I)
    s = re.sub(r"<[^>]+>", " ", s)
    s = html.unescape(s)
    s = re.sub(r"[ \t\xa0]+", " ", s)
    s = re.sub(r"\n\s*\n\s*\n+", "\n\n", s)
    return s.strip()


RE_HTML_LANG = re.compile(r'<html[^>]*\blang="([a-z]{2})', re.I)


def jazyk_stranky(h):
    m = RE_HTML_LANG.search(h[:2000])
    return m.group(1).lower() if m else "sk"


# ============================================================
# Hladanie odkazov na ponuky
# ============================================================
RE_ODKAZ = re.compile(r'<a\b[^>]*\bhref="([^"#]+)"[^>]*>(.*?)</a>', re.S | re.I)

# Cesty, ktore na slovenskych portaloch vedu na DETAIL ponuky.
#
# Dva tvary, lebo portaly ich pisu opacne:
#   /praca/nieco, /jobs/uuid       — kluc. slovo na ZACIATKU cesty
#   /projektovy-manazer-praca      — kluc. slovo na KONCI (titans.eu)
KLUCE = r"praca|ponuk[ay]?|pozici[ae]|job|jobs|kariera|vacancy|offer|inzerat"
VZORY_DETAIL = re.compile(
    r"(/(%s)[/-][\w%%\-]{3,}"          # kluc na zaciatku
    r"|/[\w%%\-]{3,}-(%s)/?$)"         # kluc na konci
    % (KLUCE, KLUCE), re.I)

# Stranky, ktore vyzeraju ako ponuka, ale su to vypisy, filtre alebo
# navigacia. Bez toho by sa stahovali desiatky stran s nicim.
#
# /zameranie/ a /kategoria/ su filtre podla technologie (Java, React...).
# Na ariva.sk ich je 55 a vyzeraju ako ponuky — prvy zber z nich stiahol
# rozcestniky namiesto inzeratov.
VZORY_MIMO = re.compile(
    r"(/prihlasenie|/login|/registracia|/kontakt|/o-nas|/about|/gdpr|/cookies"
    r"|/podmienky|/blog|/clanok|/firmy|/spolocnost|/employers|/zamestnavatel"
    r"|/zameranie/|/kategoria/|/tag/|/stitok/"
    r"|\?page|&page|/page/|/strana|/filter|/hladat|/search\?|/rss|\.pdf$|\.jpg$)", re.I)


# LinkedIn: karty vracia verejne rozhranie pre neprihlasenych a odkaz vedie
# na sk.linkedin.com, teda na INU domenu nez base. Bezna kontrola domeny by
# ho zahodila, preto ma vlastnu vetvu.
RE_LI_ODKAZ = re.compile(r'href="(https://[a-z]{0,3}\.?linkedin\.com/jobs/view/[^"?]+)')
RE_LI_TITUL = re.compile(r'base-search-card__title[^>]*>\s*(.*?)\s*<', re.S)


def najdi_ponuky_linkedin(html_text):
    """Karty z verejneho rozhrania LinkedInu. Vracia rovnaky tvar ako najdi_ponuky()."""
    odkazy = RE_LI_ODKAZ.findall(html_text)
    tituly = RE_LI_TITUL.findall(html_text)
    vysledok = []
    videne = set()
    for i, u in enumerate(odkazy):
        u = u.split("?")[0]
        if u in videne:
            continue
        videne.add(u)
        t = tituly[i] if i < len(tituly) else ""
        t = re.sub(r"\s+", " ", html.unescape(re.sub(r"<[^>]+>", " ", t))).strip()
        vysledok.append((u, t[:300], False))
    return vysledok


# profesia.sk ma zoznam v <li class="list-row"> a kazdy udaj vo vlastnom
# prvku: nazov v .title, zamestnavatel v .employer, miesto v .job-location.
#
# Vseobecny parser tu zlyhaval dvakrat: zo stranky bral aj rozcestnik krajov
# ("Bratislavsky kraj") a ako nazov ponuky zobral text prveho odkazu v karte,
# co je stitok so mzdou ("1 300 EUR/mesiac"). Ked ma portal takto jasnu
# strukturu, oplati sa ju precitat priamo.
RE_PROF_KARTA = re.compile(r'<li class="list-row"[^>]*>(.*?)</li>', re.I | re.S)
RE_PROF_ODKAZ = re.compile(r'<a\b[^>]*\bhref="([^"]*?/O\d{4,}[^"]*)"', re.I)
RE_PROF_NAZOV = re.compile(r'<span[^>]*\bclass=\'title\'[^>]*>(.*?)</span>', re.I | re.S)


def najdi_ponuky_profesia(html_text, base):
    """Karty zo zoznamu profesia.sk. Vracia rovnaky tvar ako najdi_ponuky()."""
    najdene = []
    videne = set()

    for karta in RE_PROF_KARTA.findall(html_text):
        m = RE_PROF_ODKAZ.search(karta)
        if not m:
            continue
        url = urljoin(base, html.unescape(m.group(1))).split("?")[0].rstrip("/")
        if url in videne:
            continue
        videne.add(url)

        mn = RE_PROF_NAZOV.search(karta)
        nazov = ocisti_text(mn.group(1)) if mn else ""
        if not nazov:
            nazov = external_id_z_url(url)

        # Profesia neaktualne ponuky zo zoznamu odstranuje, takze priznak
        # tu prakticky nenastane — hlada sa vsak rovnako ako inde, keby
        # portal zaviedol oznacenie.
        najdene.append((url, nazov[:300],
                        bool(RE_UZAVRETE.search(ocisti_text(karta)))))
    return najdene


# titans.eu ma kazdy projekt ako <a class="it-project"> s nazvom v atribute
# data-position_name a stavom v texte karty.
#
# Vseobecny parser tu bral ako inzeraty adresy typu /sk/java-developer-praca,
# co su ROZCESTNIKY profesii, nie ponuky — detail takej stranky je cely zoznam
# projektov, takze v DB skoncilo dvadsat kopii toho isteho vypisu.
# Skutocny inzerat ma v adrese kod projektu: /sk/java-developer-260529DCZ.
RE_TITANS_KARTA = re.compile(
    r'<a\b[^>]*\bclass="[^"]*\bit-project\b[^"]*"[^>]*>', re.I)
RE_TITANS_HREF = re.compile(r'\bhref="([^"]+)"', re.I)
RE_TITANS_NAZOV = re.compile(r'\bdata-position_name="([^"]{2,200})"', re.I)


def najdi_ponuky_titans(html_text, base):
    """Projekty zo zoznamu titans.eu. Vracia rovnaky tvar ako najdi_ponuky()."""
    najdene = []
    videne = set()

    for m in RE_TITANS_KARTA.finditer(html_text):
        znacka = m.group(0)
        mh = RE_TITANS_HREF.search(znacka)
        if not mh:
            continue
        url = urljoin(base, html.unescape(mh.group(1))).split("?")[0].rstrip("/")
        if url in videne:
            continue
        videne.add(url)

        mn = RE_TITANS_NAZOV.search(znacka)
        nazov = ocisti_text(mn.group(1)) if mn else external_id_z_url(url)

        # Stav je v tele karty za znackou — "NEPRIJIMAME ZAUJEMCOV" alebo
        # "USPESNE OBSADENE". Berie sa useknuty kus po dalsiu kartu.
        koniec = html_text.find("</a>", m.end())
        telo = html_text[m.end():koniec if koniec > 0 else m.end() + 4000]
        najdene.append((url, nazov[:300], bool(RE_UZAVRETE.search(ocisti_text(telo)))))

    return najdene


# Ponuka, ktora uz nie je aktualna. Portaly to pisu do textu odkazu:
#   ariva.sk   "Databazovy admin - OBSADENE"
#   titans.eu  "NEPRIJIMAME ZAUJEMCOV"
# Taka ponuka sa zbiera tiez — do historie patri — ale oznaci sa ako
# uzavreta, aby nestrasila vo vypise aktualnych.
RE_UZAVRETE = re.compile(r"obsaden|neprij[ií]mame|uzavret|zrusen", re.I)


# Nazov pozicie v karte zoznamu. Portaly ho obalia do vlastneho prvku
# (ariva.sk: class="job-title", inde h2/h3), zatial co zvysok karty su
# dalsie stlpce — forma, lokalita, homeoffice, plat.
#
# Bez tohto sa do nazvu dostal cely text odkazu, teda napr.
#   "IT Admin Forma: Kontrakt / TPP Lokalita: Bratislava Senec ... Plat: 2200 - 5800"
# a v zozname sa nedalo precitat, o aku poziciu vlastne ide.
RE_NAZOV_V_KARTE = re.compile(
    r'<(h[1-4]|div|span|p)\b[^>]*class="[^"]*(?:job-title|offer-title|position-title'
    r'|job__title|jobTitle|job_title)[^"]*"[^>]*>',
    re.I)


def ocisti_text(kus, zachovaj_medzery=False):
    """
    HTML fragment na jednoriadkovy text.

    So zachovaj_medzery zostane viacnasobna medzera — niektore portaly nou
    oddeluju polozky zoznamu (ariva.sk miesta vykonu prace) a po zluceni
    by sa uz nedali rozlisit.
    """
    kus = re.sub(r"<[^>]+>", " ", kus)
    kus = html.unescape(kus)
    kus = kus.replace(u"\xa0", " ")
    if zachovaj_medzery:
        return re.sub(r"[ \t]{3,}", "  ", re.sub(r"[\r\n]+", "  ", kus)).strip()
    return re.sub(r"\s+", " ", kus).strip()


# Emoji a podobna grafika v nazve — na ariva.sk oznacuje zvyhodnenu ponuku.
RE_OZDOBY = re.compile(
    "[\U0001F000-\U0001FAFF←-⇿⌀-➿️⬀-⯿]")

# "Perl vyvojar - OBSADENE" -> "Perl vyvojar"; stav uz drzi closed_reason.
RE_PRIPONA_UZAVRETE = re.compile(
    r"\s*[-–—|(]?\s*(?:obsaden\w*|neprij[ií]mame\s+z[aá]ujemcov"
    r"|uzavret\w*|zrusen\w*)\s*\)?\s*$", re.I)


def nazov_z_karty(text_odkazu):
    """
    Nazov pozicie z karty. Ked karta nema rozpoznatelny prvok s nazvom,
    vrati None a volajuci pouzije cely text odkazu.

    Prvok sa uzavrie pocitanim vnorenia — nazov byva obaleny este v <b>
    a <span>, takze prve <\/div> patri im, nie prvku s nazvom.
    """
    m = RE_NAZOV_V_KARTE.search(text_odkazu)
    if not m:
        return None

    znacka = m.group(1).lower()
    hlbka = 1
    i = m.end()
    vzor = re.compile(r"<(/?)" + znacka + r"\b[^>]*>", re.I)
    while hlbka > 0:
        d = vzor.search(text_odkazu, i)
        if not d:
            break                       # neuzavreta znacka — vezmi zvysok
        hlbka += -1 if d.group(1) else 1
        i = d.start() if hlbka == 0 else d.end()

    nazov = ocisti_text(text_odkazu[m.end():i])

    # Dekoracie portalu do nazvu nepatria: ohnik je len grafika zvyhodnenej
    # ponuky a OBSADENE uz mame ako samostatny priznak.
    nazov = RE_OZDOBY.sub("", nazov)
    nazov = RE_PRIPONA_UZAVRETE.sub("", nazov).strip(" -–—·|,")

    # Prilis kratky vysledok je skor popiska stlpca nez nazov pozicie.
    return nazov if len(nazov) >= 3 else None


def najdi_ponuky(html_text, base, ponuk_na_stranu=None):
    """
    Vytiahne zo stranky odkazy, ktore vyzeraju ako detail ponuky.

    Vracia zoznam (url, text_odkazu, uzavrete) bez duplicit, v poradi
    vyskytu — portaly zvyknu mat najnovsie ponuky hore.
    """
    najdene = []
    videne = {}          # url -> index v najdene, aby sa dal nazov doplnit

    for href, text in RE_ODKAZ.findall(html_text):
        url = urljoin(base, html.unescape(href.strip()))

        # Cudzie domeny preskakujeme: agregatory odkazuju na inzeraty
        # inych portalov a tie by sa zbierali dvakrat.
        if urlparse(url).netloc != urlparse(base).netloc:
            continue
        if VZORY_MIMO.search(url) or not VZORY_DETAIL.search(url):
            continue

        url = url.split("?")[0].rstrip("/")

        # Odkaz spat na samotny vypis ("Pracovne ponuky" v zahlavi) vyzera
        # ako detail, lebo adresa obsahuje to iste klucove slovo. Inzerat to
        # nie je a jeho "detail" je cely zoznam.
        if url == base.split("?")[0].rstrip("/"):
            continue

        # Priznak uzavretia sa hlada v CELEJ karte — "OBSADENE" byva
        # pripisane za nazvom aj mimo neho.
        popis = ocisti_text(text)
        nazov = nazov_z_karty(text) or popis

        # Na jeden inzerat vedie z karty zvycajne VIAC odkazov: obrazok,
        # nadpis, tlacidlo. Obrazkovy odkaz nema ziadny text (nazov ma len
        # v aria-label), takze pri lugera.sk zostal nazov prazdny a ulozilo
        # sa cislo z adresy. Drzi sa preto prvy odkaz, ktory nazov NAOZAJ
        # nesie — poradie odkazov je vec sablony portalu.
        if url in videne:
            i = videne[url]
            if not najdene[i][1] and nazov:
                najdene[i] = (url, nazov[:300], najdene[i][2] or bool(RE_UZAVRETE.search(popis)))
            elif RE_UZAVRETE.search(popis):
                najdene[i] = (najdene[i][0], najdene[i][1], True)
            continue

        videne[url] = len(najdene)
        najdene.append((url, nazov[:300], bool(RE_UZAVRETE.search(popis))))

    # Ked nazov nenesie ziadny z odkazov, zostane aspon identifikator z adresy.
    return [(u, n or external_id_z_url(u), z) for u, n, z in najdene]


def external_id_z_url(url):
    """
    Stabilny identifikator ponuky odvodeny z URL.

    Ked je v adrese cislo alebo UUID, pouzije sa ono — je to najstabilnejsi
    prvok. Inak sa vezme hash celej cesty: je to stale stabilne (ta ista
    ponuka ma tu istu adresu) a kratke.
    """
    cesta = urlparse(url).path.rstrip("/")

    m = re.search(r"/O(\d{4,})", cesta)                 # profesia.sk
    if m:
        return "O" + m.group(1)
    m = re.search(r"([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})", cesta, re.I)
    if m:
        return m.group(1).lower()[:50]
    m = re.search(r"[/-](\d{5,})$", cesta)
    if m:
        return m.group(1)

    return "h" + hashlib.sha1(cesta.encode("utf-8")).hexdigest()[:16]


# ============================================================
# Zapis do DB
# ============================================================
def zaloz_beh(cur, source_id, dni, run_type="manual"):
    cur.execute("""
        INSERT INTO job.scrape_runs (source_id, run_type, period_days, status, started_at)
        VALUES (%s, %s, %s, 'running', NOW()) RETURNING id
    """, (source_id, run_type, dni))
    return cur.fetchone()[0]


def uloz_ponuku(cur, source_id, run_id, url, nazov, uzavrete=False):
    """
    Vracia (offer_id, je_novy, chyba_detail).

    Uzavreta ponuka sa uklada tiez — do historie patri a jej text sa moze
    hodit na porovnanie. Oznaci sa vsak is_active = FALSE s dovodom, aby
    nestrasila vo vypise aktualnych ponuk.

    Pri opakovanom zbere sa priznak PREPISUJE oboma smermi: ponuka moze byt
    znovu otvorena a naopak.

    Inzerat z agenturneho portalu (ariva.sk, titans.eu a pod.) sa oznaci
    priznakom z ciselnika — tieto portaly zamestnavatela neuvadzaju, takze
    stlpec firmy zostava prazdny a bez priznaku by to vyzeralo ako chyba.
    """
    ext = external_id_z_url(url)
    cur.execute("""
        INSERT INTO job.offers
            (source_id, external_id, url, title, last_seen_at, first_run_id,
             is_active, closed_at, closed_reason, is_agency_offer)
        VALUES (%s, %s, %s, %s, NOW(), %s, %s, %s, %s,
                (SELECT je_agentura FROM job.sources WHERE id = %s))
        ON CONFLICT (source_id, external_id) DO UPDATE SET
            last_seen_at  = NOW(),
            missing_runs  = 0,
            is_active     = EXCLUDED.is_active,
            closed_at     = EXCLUDED.closed_at,
            closed_reason = EXCLUDED.closed_reason,
            updated_at    = NOW()
        RETURNING id, (xmax = 0) AS je_novy, detail_fetched_at IS NULL AS chyba_detail
    """, (source_id, ext, url[:500], (nazov or ext)[:300], run_id,
          not uzavrete,
          datetime.now() if uzavrete else None,
          "obsadene" if uzavrete else None,
          source_id))
    offer_id, je_novy, chyba_detail = cur.fetchone()

    cur.execute("""
        INSERT INTO job.scrape_run_offers (run_id, offer_id, action)
        VALUES (%s, %s, %s) ON CONFLICT DO NOTHING
    """, (run_id, offer_id, "new" if je_novy else "seen"))
    return offer_id, je_novy, chyba_detail


# ============================================================
# Zakladne udaje z detailu ponuky
#
# Nazov, mzda, uvazok a spol. vie vytiazit aj model, ale ten bezi az
# v druhom kroku, stoji peniaze a pri niektorych poliach sa myli. Ked ich
# portal uvadza ako ciselnik vedla textu inzeratu, je spolahlivejsie
# precitat ich priamo — model potom dopĺňa uz len to, co treba naozaj
# odvodit z textu (suhrn, technologie, odvetvie).
# ============================================================

# ariva.sk: dvojica <li><b>Popis</b></li><li><span>hodnota</span></li>
RE_ARIVA_POLE = re.compile(
    r"<li[^>]*>(?:\s*<(?:img|i)\b[^>]*>)*\s*<b[^>]*>(.*?)</b>\s*</li>\s*"
    r"<li[^>]*>(.*?)</li>",
    re.I | re.S)

# Vseobecny tvar: <dt>Popis</dt><dd>hodnota</dd> alebo th/td v tabulke.
RE_POPIS_HODNOTA = re.compile(
    r"<(dt|th)\b[^>]*>(.*?)</\1>\s*<(dd|td)\b[^>]*>(.*?)</\3>",
    re.I | re.S)

# profesia.sk: <strong>Druh pracovneho pomeru</strong><br><span>plny uvazok</span>
# Medzi popisom a hodnotou byva <br> aj biele znaky, hodnot moze byt viac
# za sebou (mzda ma rozsah v jednom span a doplnok v druhom).
#
# Hodnota byva vnorena v dalsom span (miesto prace ma jobLocation > address),
# preto sa neberie po prvom </span>, ale az po zaciatok dalsieho <strong>
# alebo konca obalujuceho bloku — vnutorne znacky odstrani ocisti_text().
RE_STRONG_SPAN = re.compile(
    r"<strong[^>]*>([^<]{2,60})</strong>\s*(?:<br\s*/?>\s*)*"
    r"((?:<span[^>]*>(?:[^<]|<(?!/?strong)[^>]*>)*?</span>\s*)+)",
    re.I | re.S)


# titans.eu: <span class="info-title">Lokalita</span><span class="info-text">...</span>
# Popis aj hodnota su susedne prvky s vlastnou triedou.
RE_INFO_DVOJICA = re.compile(
    r'<span[^>]*\bclass="[^"]*\binfo-title\b[^"]*"[^>]*>(.*?)</span>\s*'
    r'<span[^>]*\bclass="[^"]*\binfo-text\b[^"]*"[^>]*>(.*?)</span>',
    re.I | re.S)


# lugera.sk: <span class="hs-meta-widget-title">Lokalita</span>
#            <span class="hs-meta-widget-data">Bratislavsky kraj</span>
# Hodnota byva zabalena este v odkaze na filter.
RE_META_DVOJICA = re.compile(
    r'<span[^>]*\bclass="[^"]*\bhs-meta-widget-title\b[^"]*"[^>]*>(.*?)</span>\s*'
    r'<span[^>]*\bclass="[^"]*\bhs-meta-widget-data\b[^"]*"[^>]*>(.*?)</span>',
    re.I | re.S)


# Ako sa jednotlive polia volaju na roznych portaloch.
POLIA = {
    "uvazok":   ("pracovny pomer", "pracovný pomer", "forma", "typ pracovneho pomeru",
                 "druh pracovneho pomeru", "druh pracovného pomeru", "uvazok", "úväzok",
                 "typ uvazku", "typ pracovneho vztahu", "stav"),
    "miesto":   ("miesto", "lokalita", "miesto vykonu prace", "miesto práce", "mesto",
                 "location"),
    "mzda":     ("odmena", "plat", "mzda", "ponukany plat", "ponúkaný plat", "salary",
                 "mzdove podmienky", "mzdové podmienky", "zakladna zlozka mzdy",
                 "sadzba", "rate", "hodinova sadzba", "cena", "ohodnotenie",
                 "zakladny plat", "základný plat", "informacie o plate"),
    "homeoffice": ("home office", "homeoffice", "praca z domu", "práca z domu", "remote"),
    # titans.eu: "Full-time" / "Part-time" pod popiskou o forme spoluprace.
    "forma":    ("forma spoluprace", "forma spolupráce", "typ uvazku", "uvazok"),
    "nastup":   ("datum nastupu", "dátum nástupu", "nastup", "nástup", "termin nastupu"),
    "firma":    ("spolocnost", "spoločnosť", "firma", "zamestnavatel", "zamestnávateľ",
                 "klient"),
    # lugera.sk drzi odbor ako samostatny udaj vedla lokality.
    "odvetvie": ("odvetvie", "oblast", "oblasť", "kategoria", "kategória", "sektor"),
}


def _kluc(popis):
    """
    Popis pola na porovnatelny tvar: bez diakritiky, malymi, bez dvojbodky.

    Doplnok v zatvorke sa zahadzuje — profesia.sk pise "Mzdove podmienky
    (brutto)" a nemalo by zmysel drzat kazdu variantu v zozname nazvov.
    """
    popis = ocisti_text(popis)
    popis = re.sub(r"\s*\([^)]*\)", "", popis).rstrip(":").strip().lower()
    return unicodedata.normalize("NFKD", popis).encode("ascii", "ignore").decode()


def udaje_z_detailu(h):
    """
    Dvojice popis/hodnota z detailu ponuky ako slovnik nasich klucov.
    Neznama polozka sa zahodi — do DB patria len polia, ktore mame.
    """
    dvojice = [(p, hod) for p, hod in RE_ARIVA_POLE.findall(h)]
    dvojice += [(p, hod) for _, p, _, hod in RE_POPIS_HODNOTA.findall(h)]
    dvojice += [(p, hod) for p, hod in RE_STRONG_SPAN.findall(h)]
    dvojice += [(p, hod) for p, hod in RE_INFO_DVOJICA.findall(h)]
    dvojice += [(p, hod) for p, hod in RE_META_DVOJICA.findall(h)]

    najdene = {}
    for popis, hodnota in dvojice:
        k = _kluc(popis)
        # Miesta oddeluje ariva.sk NIEKOLKYMI medzerami. ocisti_text() ich
        # zlucuje na jednu, takze "Bratislava   Senec" by splynulo do
        # jedineho nazvu — deli sa preto este pred cistenim.
        hodnota = ocisti_text(hodnota.replace("</li>", "  ").replace("<br>", "  ")
                                     .replace("<br/>", "  ").replace("<br />", "  "),
                              zachovaj_medzery=True)
        if not hodnota:
            continue
        for nase, nazvy in POLIA.items():
            if nase in najdene:
                continue            # prve vyskyt vyhrava — byva v zahlavi
            if any(k == unicodedata.normalize("NFKD", n).encode("ascii", "ignore").decode()
                   for n in nazvy):
                najdene[nase] = hodnota[:500]
                break
    return najdene


# Uvazok z volneho textu. Portal ich uvadza aj viac naraz
# ("Kontrakt / TPP"), preto sa vracia zoznam.
DRUHY_UVAZKU = [
    ("tpp",      (u"tpp", u"trvaly pracovny pomer", u"hlavny pracovny pomer",
                  u"plny uvazok", u"full-time", u"full time", u"fulltime")),
    ("zivnost",  (u"zivnost", u"kontrakt", u"contract", u"b2b", u"ico", u"freelance")),
    ("dohoda",   (u"dohoda", u"dohodu", u"dohodar")),
    ("brigada",  (u"brigada", u"brigadnik")),
    ("internship", (u"staz", u"internship", u"trainee", u"praktikant")),
]


def uvazky_z_textu(text):
    if not text:
        return []
    t = unicodedata.normalize("NFKD", text.lower()).encode("ascii", "ignore").decode()
    najdene = [kod for kod, slova in DRUHY_UVAZKU if any(w in t for w in slova)]
    return najdene


# "60%" -> hybrid, "100%" -> remote, chybajuce alebo 0 -> onsite.
RE_PERCENTA = re.compile(r"(\d{1,3})\s*%")


def rezim_z_homeoffice(text):
    if not text:
        return None
    m = RE_PERCENTA.search(text)
    if m:
        p = int(m.group(1))
        if p >= 100:
            return "remote"
        return "hybrid" if p > 0 else "onsite"
    t = unicodedata.normalize("NFKD", text.lower()).encode("ascii", "ignore").decode()
    if "remote" in t or "z domu" in t or "home office" in t:
        return "remote"
    return None


# "2200 - 5800 eur/mes na kontrakt" — rozsah, jedno cislo aj "od/do".
#
# Medzi cislami byva aj mena: titans.eu pise "3 200 € - 4 000 € / mesiac",
# takze sa pred oddelovacom pripusta znak meny — inak by sa rozsah
# nerozpoznal a zapisala by sa len dolna hranica.
RE_MZDA_ROZSAH = re.compile(
    r"(\d[\d\s\u00a0]{2,})\s*(?:€|EUR|eur|Kč|CZK)?\s*(?:-|–|—|do|az|až)\s*"
    r"(\d[\d\s\u00a0]{2,})")
RE_MZDA_JEDNO = re.compile(r"(\d[\d\s\u00a0]{2,})")


def _cislo(s):
    return int(re.sub(r"[^\d]", "", s))


def mzda_z_textu(text):
    """
    Vracia (min, max, obdobie) alebo (None, None, None).

    Hodnoty pod 100 sa ignoruju — byva to percento home office alebo
    pocet dni, nie mzda.
    """
    if not text:
        return (None, None, None)

    t = unicodedata.normalize("NFKD", text.lower()).encode("ascii", "ignore").decode()
    if "/hod" in t or "hodin" in t or "manday" in t or "/md" in t:
        obdobie = "hour" if "hod" in t else "day"
    elif "/rok" in t or "rocne" in t or "annum" in t:
        obdobie = "year"
    else:
        obdobie = "month"

    m = RE_MZDA_ROZSAH.search(text)
    if m:
        a, b = _cislo(m.group(1)), _cislo(m.group(2))
        if a >= 100 and b >= a:
            return (a, b, obdobie)

    for m in RE_MZDA_JEDNO.finditer(text):
        v = _cislo(m.group(1))
        if v >= 100:
            return (v, None, obdobie)
    return (None, None, None)


# Miesta su na ariva.sk zlepene medzerami: "Bratislava Senec Zilina".
# Viacslovne nazvy ("Liptovsky Mikulas") sa tym rozbiju, preto sa delí
# na dvoch a viac medzerach — tak ich portal oddeluje.
# Adresa s ulicou: "Mickiewiczova 9, Bratislava" — do lokality patri len
# mesto, cize posledna cast. Rozpozna sa podla cisla popisneho v prvej casti.
RE_ULICA = re.compile(r"\d", re.U)


def miesta_z_textu(text):
    """
    Zoznam miest vykonu prace.

    Portaly ich oddeluju roznym sposobom: ariva.sk niekolkymi medzerami,
    profesia.sk uvadza jednu adresu s ciarkou medzi ulicou a mestom. Ciarka
    preto NEDELI na dve lokality — z adresy sa vezme len mesto.
    """
    if not text:
        return []

    # Viacero lokalit: oddelovac je viacnasobna medzera, bodkociarka alebo
    # lomka. Ciarka nie — tu oddeluje casti jednej adresy.
    casti = [c.strip() for c in re.split(r"\s{2,}|\s*[;/|]\s*|\s*\u2022\s*", text.strip())
             if c.strip()]

    miesta = []
    for cast in casti:
        kusy = [k.strip() for k in cast.split(",") if k.strip()]
        # Adresa s ulicou: nechaj len poslednu cast, teda mesto.
        if len(kusy) > 1 and RE_ULICA.search(kusy[0]):
            kusy = [kusy[-1]]
        for k in kusy:
            # Doplnky typu "ubytovanie", "prenajom" ani zvysky cisel nie su
            # lokality — mesto ma pismena a nema cislo popisne.
            if len(k) < 2 or RE_ULICA.search(k):
                continue
            if k not in miesta:
                miesta.append(k)
    return miesta[:10]


# Zamestnavatel. profesia.sk ho pise do <h2> hned za <h1> s nazvom pozicie,
# ale nie vzdy — inzeraty personalnych agentur ten <h2> nemaju. Vtedy ho
# ma aspon hlavicka zamestnavatela alebo samotna adresa inzeratu
# (/praca/<firma>/O123456).
#
# ariva.sk a spol. zamestnavatela neuvadzaju vobec (su sprostredkovatelia),
# takze tam zostane prazdny a inzerat sa oznaci ako agenturny.
RE_FIRMA_H2 = re.compile(r"<h1[^>]*>.*?</h1>\s*<h2[^>]*>(.*?)</h2>", re.I | re.S)
RE_FIRMA_META = re.compile(
    r'<meta[^>]*\bproperty="og:site_name"[^>]*\bcontent="([^"]{2,120})"', re.I)
RE_FIRMA_TRIEDA = re.compile(
    r'<[a-z]+[^>]*\bclass="[^"]*\b(?:employer|company-name|company-title)\b[^"]*"[^>]*>(.*?)</',
    re.I | re.S)


def firma_z_url(url):
    """
    profesia.sk ma v adrese nazov zamestnavatela: /praca/grafton-slovakia/O123.
    Je to zaloha, ked ho stranka neuvadza v texte — nazov je odvodeny, takze
    "s.r.o." a diakritika chybaju, ale identifikuje firmu spolahlivo.
    """
    m = re.search(r"/praca/([a-z0-9][a-z0-9-]{2,60})/O\d+", url, re.I)
    if not m:
        return None
    return m.group(1).replace("-", " ").strip().title()[:200]


def firma_z_detailu(h, url=None):
    for vzor in (RE_FIRMA_H2, RE_FIRMA_TRIEDA, RE_FIRMA_META):
        m = vzor.search(h)
        if not m:
            continue
        firma = ocisti_text(m.group(1))
        # Nazov portalu nie je zamestnavatel.
        if 2 <= len(firma) <= 200 and "profesia" not in firma.lower():
            return firma
    return firma_z_url(url) if url else None


def uloz_udaje(cur, offer_id, h, url=None):
    """
    Zapise do ponuky udaje vycitane z detailu.

    Prepisuju sa len prazdne polia (COALESCE): ked uz nieco vyplnil model
    alebo skorsi beh, necha sa to tak.
    """
    u = udaje_z_detailu(h)
    if "firma" not in u:
        firma = firma_z_detailu(h, url)
        if firma:
            u["firma"] = firma
    if not u:
        return False

    uvazky = uvazky_z_textu(u.get("uvazok"))
    if not uvazky:
        uvazky = uvazky_z_textu(u.get("forma"))
    smin, smax, obdobie = mzda_z_textu(u.get("mzda"))
    miesta = miesta_z_textu(u.get("miesto"))
    rezim = rezim_z_homeoffice(u.get("homeoffice"))
    # titans.eu pise mieru prace z domu do LOKALITY ("100% Remote", "50% Remote"),
    # samostatne pole nema — rezim sa preto skusa odvodit aj z nej.
    if rezim is None:
        rezim = rezim_z_homeoffice(u.get("miesto"))

    # Texty sa orezavaju na dlzku stlpcov. profesia.sk pise k mzde este cely
    # odstavec ("+ bonusy a provizie, priemerny plat po zauceni je..."), takze
    # bez orezania zapis spadne na dlzke salary_raw.
    firma = (u.get("firma") or None)
    if firma:
        firma = firma[:255]
    mzda_text = (u.get("mzda") or None)
    if mzda_text:
        mzda_text = mzda_text[:200]

    cur.execute("""
        UPDATE job.offers SET
            company_name_raw = COALESCE(company_name_raw, %s),
            employment_type  = COALESCE(employment_type, %s),
            employment_types = CASE WHEN employment_types IS NULL OR employment_types = '{}'
                                    THEN %s ELSE employment_types END,
            remote_type      = COALESCE(remote_type, %s),
            locations_raw    = CASE WHEN locations_raw IS NULL OR locations_raw = '{}'
                                    THEN %s ELSE locations_raw END,
            salary_raw       = COALESCE(salary_raw, %s),
            salary_min       = COALESCE(salary_min, %s),
            salary_max       = COALESCE(salary_max, %s),
            salary_period    = COALESCE(salary_period, %s),
            salary_currency  = COALESCE(salary_currency, %s),
            industry         = COALESCE(industry, %s),
            updated_at       = NOW()
         WHERE id = %s
    """, (
        firma,
        uvazky[0] if uvazky else None,
        uvazky or None,
        rezim,
        miesta or None,
        mzda_text,
        smin, smax,
        obdobie if smin else None,
        "EUR" if smin else None,
        # Odvetvie uvadza len cast portalov (lugera.sk); inde ho doplni model.
        (u.get("odvetvie") or None) and u["odvetvie"][:100],
        offer_id,
    ))
    return True


def uloz_detail(cur, offer_id, h, fetch_ms, fetch_bytes, url=None):
    text = html_na_text(h)
    cur.execute("""
        INSERT INTO job.offer_content (offer_id, lang, is_original, html_full, text_full)
        VALUES (%s, %s, TRUE, %s, %s)
        ON CONFLICT (offer_id, lang) DO UPDATE
            SET html_full = EXCLUDED.html_full, text_full = EXCLUDED.text_full
    """, (offer_id, jazyk_stranky(h), h, text))
    cur.execute("""
        UPDATE job.offers SET detail_fetched_at = NOW(), fetch_ms = %s, fetch_bytes = %s
         WHERE id = %s
    """, (fetch_ms, fetch_bytes, offer_id))

    # Co portal uvadza ako ciselnik, precitame hned — netreba na to model.
    try:
        uloz_udaje(cur, offer_id, h, url)
    except Exception as e:
        # Vytazenie udajov je bonus; ked zlyha, ulozeny detail ma zostat.
        print("    POZN. udaje sa nevytazili: %s" % str(e)[:80])

    return len(text)


# Priebezny stav behu.
#
# Pocty sa predtym zapisovali AZ na konci, takze obrazovka celu tu dobu
# ukazovala same nuly a beh vyzeral zaseknuto — pri 239 inzeratoch Arivy
# to bolo aj osem minut.
def zapis_priebeh(conn, run_id, najdene, novych, detailov, chyb):
    if not run_id:
        return
    try:
        cur = conn.cursor()
        cur.execute("""
            UPDATE job.scrape_runs
               SET offers_found = %s, offers_new = %s,
                   details_fetched = %s, errors_count = %s
             WHERE id = %s
        """, (najdene, novych, detailov, chyb, run_id))
        cur.close()
        conn.commit()
    except Exception:
        # Stav je len informacia navyse — zber kvoli nemu nema padat.
        conn.rollback()


# ============================================================
# Zber jedneho portalu
# ============================================================
def zbieraj_portal(conn, zdroj, limit, bez_detailov, run_id=None):
    cur = conn.cursor()
    kod = zdroj["code"]
    pauza = (zdroj["request_delay_ms"] or 1500) / 1000.0

    # Portal moze mat viac vychodzich adries a zber ich prejde po kolach.
    # profesia.sk nedava vsetky ponuky na jednom zozname — kazdy kraj ma
    # vlastnu adresu, takze bez toho by sa zozbieral len jeden kraj.
    adresy = [zdroj["url_kriteria"] or zdroj["base_url"]]
    adresy += [a for a in (zdroj.get("url_kriteria_dalsie") or []) if a]
    url = adresy[0]

    print("\n" + "=" * 66)
    print("%s — %s%s" % (zdroj["name"], url,
                         ("  (+%d dalsich adries)" % (len(adresy) - 1))
                         if len(adresy) > 1 else ""))
    if zdroj["popis"]:
        print("  " + zdroj["popis"][:150].replace("\n", " "))
    print("=" * 66)

    if run_id is None:
        run_id = zaloz_beh(cur, zdroj["id"], zdroj["default_period_days"] or 2)
    conn.commit()

    session = requests.Session()
    najdene = novych = detailov = chyb = 0

    jeLinkedIn = kod == "linkedin"

    def rozober(html_text, adresa):
        if jeLinkedIn:
            return najdi_ponuky_linkedin(html_text)
        if kod == "profesia":
            return najdi_ponuky_profesia(html_text, adresa)
        if kod == "titans":
            return najdi_ponuky_titans(html_text, adresa)
        return najdi_ponuky(html_text, adresa, zdroj["ponuk_na_stranu"])

    ponuky = []
    videne_url = set()
    h = None
    for i, adresa in enumerate(adresy):
        if i:
            time.sleep(pauza)
        try:
            ha, _, _ = stiahni(session, adresa)
        except Exception as e:
            chyb += 1
            print("  CHYBA pri stahovani vypisu %s: %s" % (adresa[-40:], str(e)[:70]))
            continue
        if h is None:
            h = ha                       # prve HTML sa pouzije na strankovanie

        nove = [p for p in rozober(ha, adresa) if p[0] not in videne_url]
        videne_url.update(p[0] for p in nove)
        ponuky.extend(nove)
        if len(adresy) > 1:
            print("  %s: %d ponuk" % (adresa.rstrip("/").split("/")[-1], len(nove)))

    # Ani jedna adresa sa nestiahla — beh nema z coho pokracovat.
    if h is None:
        cur.execute("""UPDATE job.scrape_runs SET status='failed', finished_at=NOW(),
                       error_message=%s WHERE id=%s""",
                    ("Nepodarilo sa stiahnut ziadny vypis", run_id))
        conn.commit()
        cur.close()
        return 0, 0

    print("  Na vypise najdenych odkazov na ponuky: %d" % len(ponuky))

    # --- strankovanie ---
    # Ked portal uvadza pocet ponuk na stranu a nasli sme ich aspon tolko,
    # su pravdepodobne dalsie stranky. Skusaju sa bezne tvary adries.
    # LinkedIn strankuje parametrom start po 10 — ma vlastny cyklus, lebo
    # bezne tvary (page=, strana=) tu nefunguju.
    if jeLinkedIn and len(ponuky) < limit:
        videne = set(videne_url)
        for start in range(10, 400, 10):
            if len(ponuky) >= limit:
                break
            try:
                time.sleep(pauza)
                spojka = "&" if "?" in url else "?"
                h2, _, _ = stiahni(session, url + spojka + "start=%d" % start)
                nove = [p for p in najdi_ponuky_linkedin(h2) if p[0] not in videne]
                if not nove:
                    break
                videne.update(p[0] for p in nove)
                ponuky.extend(nove)
            except Exception:
                break
        print("    po strankovani: %d ponuk" % len(ponuky))

    na_stranu = zdroj["ponuk_na_stranu"]
    if not jeLinkedIn and na_stranu and len(ponuky) >= na_stranu and len(ponuky) < limit:
        for strana in range(2, 8):
            if len(ponuky) >= limit:
                break
            spojka = "&" if "?" in url else "?"
            for vzor in ("page=%d", "page_num=%d", "strana=%d", "p=%d"):
                dalsia = url + spojka + (vzor % strana)
                try:
                    time.sleep(pauza)
                    h2, _, _ = stiahni(session, dalsia)
                    nove = [p for p in rozober(h2, url) if p[0] not in videne_url]
                    if nove:
                        videne_url.update(p[0] for p in nove)
                        ponuky.extend(nove)
                        print("    strana %d (%s): +%d" % (strana, vzor % strana, len(nove)))
                        break
                except Exception:
                    continue
            else:
                break      # ziadny tvar adresy nezabral — strankovanie koncime

    # limit 0 znamena "bez obmedzenia" — ponuky[:0] by vratilo prazdny zoznam.
    if limit:
        ponuky = ponuky[:limit]
    print("  Spracujem: %d ponuk" % len(ponuky))

    # --- ulozenie ponuk ---
    # Na stiahnutie detailu sa caka kazda ponuka, ktora ho este NEMA — nielen
    # tie prave vlozene. Predtym rozhodoval priznak "novy riadok", takze ked
    # zber v prvom behu spadol alebo bezal s --bez-detailov, ponuka uz navzdy
    # zostala len s nazvom zo zoznamu a detail sa nedotiahol nikdy.
    na_detail = []
    uzavretych = 0
    for u, nazov, uzavrete in ponuky:
        if uzavrete:
            uzavretych += 1
        try:
            offer_id, je_novy, chyba_detail = uloz_ponuku(
                cur, zdroj["id"], run_id, u, nazov, uzavrete)
            najdene += 1
            if je_novy:
                novych += 1
            if chyba_detail:
                na_detail.append((offer_id, u))
        except Exception as e:
            chyb += 1
            conn.rollback()
            print("  CHYBA ulozenia %s: %s" % (u[-40:], str(e)[:60]))
    conn.commit()
    zapis_priebeh(conn, run_id, najdene, novych, detailov, chyb)
    print("  Ulozenych: %d, z toho novych: %d%s"
          % (najdene, novych,
             (", uzavretych: %d" % uzavretych) if uzavretych else ""))

    # --- detaily pre vsetky ponuky bez ulozeneho detailu ---
    if not bez_detailov and na_detail:
        print("  Detaily (%d):" % len(na_detail))
        for offer_id, u in na_detail:
            time.sleep(pauza)
            try:
                h2, ms, bajtov = stiahni(session, u)
                znakov = uloz_detail(cur, offer_id, h2, ms, bajtov, u)
                detailov += 1
                conn.commit()
                if detailov % 10 == 0 or detailov == len(na_detail):
                    zapis_priebeh(conn, run_id, najdene, novych, detailov, chyb)
                    print("    %d/%d hotovo" % (detailov, len(na_detail)))
            except Exception as e:
                chyb += 1
                conn.rollback()
                print("    CHYBA %s: %s" % (u[-40:], str(e)[:60]))

    cur.execute("""
        UPDATE job.scrape_runs SET status='done', finished_at=NOW(),
               offers_found=%s, offers_new=%s, details_fetched=%s, errors_count=%s
         WHERE id=%s
    """, (najdene, novych, detailov, chyb, run_id))
    conn.commit()
    cur.close()

    print("  HOTOVO: %d najdenych, %d novych, %d detailov, %d chyb"
          % (najdene, novych, detailov, chyb))
    return novych, detailov


# ============================================================
# Hlavny beh
# ============================================================
def doplnit_udaje(conn, kod_portalu=None):
    """
    Vytazi zakladne udaje z HTML, ktore uz je v DB.

    Po zmene parsera netreba stahovat znova — HTML uz mame a portal by sa
    zbytocne zatazil. Prazdne stlpce sa tak daju doplnit aj spatne.
    """
    cur = conn.cursor()
    sql = """SELECT o.id, c.html_full, o.url
               FROM job.offers o
               JOIN job.offer_content c ON c.offer_id = o.id AND c.is_original
              WHERE c.html_full IS NOT NULL"""
    params = []
    if kod_portalu:
        sql += " AND o.source_id = (SELECT id FROM job.sources WHERE code = %s)"
        params.append(kod_portalu)
    sql += " ORDER BY o.id"

    cur.execute(sql, params)
    riadky = cur.fetchall()
    print("Inzeratov s ulozenym HTML: %d" % len(riadky))

    doplnenych = bez_udajov = chyb = 0
    for offer_id, h, url in riadky:
        try:
            if uloz_udaje(cur, offer_id, h, url):
                doplnenych += 1
            else:
                bez_udajov += 1
            conn.commit()
        except Exception as e:
            chyb += 1
            conn.rollback()
            print("  CHYBA #%s: %s" % (offer_id, str(e)[:80]))

    # Agenturny priznak vyplyva z portalu — doplni sa rovno tu, aby sa
    # kvoli nemu nemusel spustat dalsi krok.
    cur.execute("""
        UPDATE job.offers o SET is_agency_offer = TRUE
          FROM job.sources s
         WHERE s.id = o.source_id AND s.je_agentura
           AND o.is_agency_offer IS NOT TRUE
    """)
    oznacenych = cur.rowcount
    conn.commit()
    cur.close()

    print("HOTOVO: %d doplnenych, %d bez rozpoznatelnych udajov, %d chyb"
          % (doplnenych, bez_udajov, chyb))
    if oznacenych:
        print("        %d inzeratov oznacenych ako agenturne" % oznacenych)


def main():
    ap = argparse.ArgumentParser(description="Zber zo vsetkych portalov")
    ap.add_argument("--limit", type=int, default=50, help="max. ponuk na portal")
    ap.add_argument("--portal", help="iba jeden portal (kod z job.sources)")
    ap.add_argument("--bez-detailov", action="store_true")
    ap.add_argument("--doplnit-udaje", action="store_true",
                    help="nestahuj nic, len vytaz udaje z uz ulozeneho HTML")
    # Ked zber spusta obrazovka, beh uz v DB existuje a skript ho ma prevziat.
    # Inak by vznikli dva zaznamy: jeden prazdny z obrazovky a jeden skutocny.
    ap.add_argument("--run-id", type=int, default=None,
                    help="ID uz zalozeneho behu (ked spusta API)")
    args = ap.parse_args()

    conn = pripoj_db()
    conn.autocommit = False

    if args.doplnit_udaje:
        doplnit_udaje(conn, args.portal)
        conn.close()
        return

    cur = conn.cursor()

    # LinkedIn a spol. sa preskakuju — vyzaduju prihlasenie a obsah dotahuju
    # javascriptom, takze beznym stahovanim HTML sa nezbieraju.
    sql = """SELECT id, code, name, base_url, url_kriteria, url_kriteria_dalsie,
                    popis, ponuk_na_stranu,
                    request_delay_ms, default_period_days
               FROM job.sources
              WHERE is_active AND NOT vyzaduje_prihlasenie"""
    params = []
    if args.portal:
        sql += " AND code = %s"
        params.append(args.portal)
    sql += " ORDER BY id"

    cur.execute(sql, params)
    stlpce = [d[0] for d in cur.description]
    zdroje = [dict(zip(stlpce, r)) for r in cur.fetchall()]
    cur.close()

    if not zdroje:
        sys.exit("Ziadny portal na zber (skontroluj --portal a is_active)")

    print("Portalov na zber: %d, limit %s na portal"
          % (len(zdroje), args.limit if args.limit else "bez obmedzenia"))
    zaciatok = time.time()
    spolu_novych = spolu_detailov = 0

    for z in zdroje:
        try:
            # --run-id plati len pre prvy portal; pri viacerych by sa
            # vysledky zlievali do jedneho behu.
            n, d = zbieraj_portal(conn, z, args.limit, args.bez_detailov,
                                  args.run_id if len(zdroje) == 1 else None)
            spolu_novych += n
            spolu_detailov += d
        except Exception as e:
            # Jeden nefunkcny portal nesmie zastavit zvysok.
            conn.rollback()
            print("  PORTAL ZLYHAL: %s" % str(e)[:120])

    minut = (time.time() - zaciatok) / 60
    print("\n" + "=" * 66)
    print("SPOLU: %d novych ponuk, %d detailov, %.1f minut"
          % (spolu_novych, spolu_detailov, minut))
    print("Dalsi krok — vytazenie udajov modelom:")
    print("  php api/cron/zber.php --limit=%d" % max(spolu_detailov, 1))
    conn.close()


if __name__ == "__main__":
    main()
