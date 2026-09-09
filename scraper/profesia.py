#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Scraper profesia.sk — zoznamy a detaily inzeratov.

Dvojfazovy zber podla ZADANIE_JOB.md, kapitola 3.4:
  1. prejde stranky zoznamu a zisti external_id (O5356225)
  2. stiahne detail IBA pre inzeraty, ktore este nemame

Cely originalny HTML detailu sa uklada do job.offer_content — sluzi zaroven
ako archiv, takze sa da neskor prepasovat lepsim modelom bez opatovneho
stahovania z portalu.

Model tu NEBEZI. Tazenie udajov robi az api/cron/zber.php nad ulozenym HTML.
Oddelenie je zamerne: stahovanie a tazenie sa daju opakovat nezavisle.

Pouzitie:
    python scraper/profesia.py --dni 1 --limit 20
    python scraper/profesia.py --dni 7 --limit 0        (0 = bez limitu)
    python scraper/profesia.py --run-id 5               (beh zalozeny z API)

Vyzaduje: pip install requests psycopg2-binary
"""

import argparse
import html
import os
import re
import sys
import time
from datetime import date, datetime, timedelta

try:
    import requests
    import psycopg2
    import psycopg2.extras
except ImportError as e:
    sys.exit("Chyba kniznica: %s\nSpusti: pip install requests psycopg2-binary" % e)

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PORTAL = "https://www.profesia.sk"

# Vlastny User-Agent s kontaktom — poziadavka zo ZADANIA (kap. 3.4).
UA = "JobBot/1.0 (+https://job.fellow.sk; agregator pracovnych ponuk)"

# Rate limit: 1 request za 1,5 s, jedno vlakno. Zber nema zatazovat portal.
PAUZA_S = 1.5

DNES = date.today()
TERAZ = datetime.now()


# ============================================================
# Konfiguracia z api/config/db.php (mimo gitu)
# ============================================================
def nacitaj_db_config():
    cesta = os.path.join(BASE, "api", "config", "db.php")
    with open(cesta, encoding="utf-8") as f:
        obsah = f.read()

    def hodnota(kluc):
        m = re.search(r"define\('%s'\s*,\s*'([^']*)'" % kluc, obsah)
        if not m:
            raise RuntimeError("V db.php chyba %s" % kluc)
        return m.group(1)

    return dict(host=hodnota("DB_HOST"), port=int(hodnota("DB_PORT")),
                dbname=hodnota("DB_NAME"), user=hodnota("DB_USER"),
                password=hodnota("DB_PASS"), sslmode="require")


# ============================================================
# Parsovanie zoznamu
# ============================================================
# Kazdy inzerat je <li class="list-row"> s id="offerNNNNNNN".
RE_ROW = re.compile(r'<li class="list-row"\s*>(.*?)</li>', re.S)
RE_ID = re.compile(r'id="offer(\d+)"')
RE_HREF = re.compile(r'<h2><a\s+id="offer\d+"\s+href="([^"]+)"')
RE_TITLE = re.compile(r"<span class='title'>(.*?)</span>", re.S)
RE_EMPLOYER = re.compile(r"<span class='employer'>(.*?)</span>", re.S)
RE_LOCATION = re.compile(r"<span title=\"([^\"]*)\" class='job-location'")
RE_SALARY = re.compile(r'<span class="label label-bordered green[^"]*">(.*?)</span>', re.S)
RE_INFO = re.compile(r"<span\s+[^>]*class='info'>\s*<strong>(.*?)</strong>", re.S)


def ocisti(s):
    """HTML fragment -> cisty text."""
    if not s:
        return ""
    s = re.sub(r"<[^>]+>", " ", s)
    s = html.unescape(s)
    return re.sub(r"\s+", " ", s).strip()


def parsuj_zoznam(html_text):
    """Vrati zoznam inzeratov najdenych na jednej stranke vypisu."""
    polozky = []
    for blok in RE_ROW.findall(html_text):
        m_id = RE_ID.search(blok)
        m_href = RE_HREF.search(blok)
        if not m_id or not m_href:
            continue

        # Kanonicka URL BEZ search_id — ten sa meni pri kazdom vyhladavani
        # a rovnaky inzerat by tak mal zakazdym inu adresu.
        href = html.unescape(m_href.group(1)).split("?")[0]

        polozky.append({
            "external_id": "O" + m_id.group(1),
            "url": PORTAL + href,
            "title": ocisti(RE_TITLE.search(blok).group(1)) if RE_TITLE.search(blok) else "",
            "company": ocisti(RE_EMPLOYER.search(blok).group(1)) if RE_EMPLOYER.search(blok) else "",
            "location": html.unescape(RE_LOCATION.search(blok).group(1)) if RE_LOCATION.search(blok) else "",
            "salary_raw": ocisti(RE_SALARY.search(blok).group(1)) if RE_SALARY.search(blok) else "",
            "posted_raw": ocisti(RE_INFO.search(blok).group(1)) if RE_INFO.search(blok) else "",
        })
    return polozky


def datum_z_popisu(popis):
    """
    Relativny cas z vypisu -> kalendarny datum.

    Prevzate z agent_brigady/codes/build_data_*.py. Dolezite: datum zverejnenia
    na DETAILE je datum PRVEHO zverejnenia a pri opakovane obnovovanych
    inzeratoch je vyrazne starsi. Vypis sa riadi aktivitou, preto ma prednost.
    """
    s = (popis or "").strip().lower()
    if not s:
        return None

    # "Teraz" / "Pred 5 minutami" / "Pred 2 hodinami" je DNESNY den. Vracia sa
    # presny cas, nie polnoc: stlpec je TIMESTAMP a polnoc by sa pri prevode
    # casovych pasiem posunula na predchadzajuci den.
    if "teraz" in s or "minut" in s or "minút" in s or "hodin" in s:
        return TERAZ
    if "dnes" in s:
        return TERAZ
    if "včera" in s or "vcera" in s:
        return DNES - timedelta(days=1)
    if "predvčerom" in s or "predvcerom" in s:
        return DNES - timedelta(days=2)
    m = re.search(r"pred\s+(\d+)\s+d", s)
    if m:
        return DNES - timedelta(days=int(m.group(1)))
    if "týžd" in s or "tyzd" in s:
        return DNES - timedelta(days=7)
    return None


# ============================================================
# Stahovanie
# ============================================================
def stiahni(session, url):
    """Vracia (html, trvanie_ms, velkost_v_bajtoch) — metrika ide do DB."""
    t0 = time.time()
    r = session.get(url, timeout=30, headers={
        "User-Agent": UA,
        "Accept-Language": "sk,cs;q=0.8,en;q=0.5",
    })
    r.raise_for_status()
    r.encoding = "utf-8"
    return r.text, int((time.time() - t0) * 1000), len(r.content)


def html_na_text(h):
    """HTML -> cisty text. Rovnaka logika ako or_html_to_text() v PHP."""
    s = re.sub(r"<(script|style|noscript|svg|iframe)\b[^>]*>.*?</\1>", " ", h, flags=re.S | re.I)
    s = re.sub(r"<(nav|footer|header)\b[^>]*>.*?</\1>", " ", s, flags=re.S | re.I)
    s = re.sub(r"<br\s*/?>|</(p|div|li|tr|h[1-6])>", "\n", s, flags=re.I)
    s = re.sub(r"<[^>]+>", " ", s)
    s = html.unescape(s)
    s = re.sub(r"[ \t\xa0]+", " ", s)
    s = re.sub(r"\n\s*\n\s*\n+", "\n\n", s)
    return s.strip()


# ============================================================
# Zapis do DB
# ============================================================
def zaloz_beh(cur, source_id, dni, limit, run_type, user_id=None):
    cur.execute("""
        INSERT INTO job.scrape_runs
            (source_id, run_type, period_days, status, started_at, triggered_by)
        VALUES (%s, %s, %s, 'running', NOW(), %s)
        RETURNING id
    """, (source_id, run_type, dni, user_id))
    return cur.fetchone()[0]


def uloz_inzerat(cur, source_id, run_id, p):
    """
    Ulozi alebo aktualizuje inzerat zo zoznamu. Vracia 'new' alebo 'seen'.

    Detail sa NESTAHUJE tu — az v druhej faze, a len pre nove inzeraty.
    """
    posted = datum_z_popisu(p["posted_raw"])

    cur.execute("""
        INSERT INTO job.offers
            (source_id, external_id, url, title, company_name_raw,
             salary_raw, published_at, published_at_raw, last_seen_at, first_run_id)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, NOW(), %s)
        ON CONFLICT (source_id, external_id) DO UPDATE SET
            last_seen_at = NOW(),
            missing_runs = 0,
            is_active    = TRUE,
            updated_at   = NOW()
        RETURNING id, (xmax = 0) AS je_novy
    """, (source_id, p["external_id"], p["url"], p["title"][:300],
          p["company"][:255], p["salary_raw"][:200], posted, p["posted_raw"][:100],
          run_id))

    offer_id, je_novy = cur.fetchone()

    cur.execute("""
        INSERT INTO job.scrape_run_offers (run_id, offer_id, action)
        VALUES (%s, %s, %s)
        ON CONFLICT DO NOTHING
    """, (run_id, offer_id, "new" if je_novy else "seen"))

    return offer_id, je_novy


RE_HTML_LANG = re.compile(r'<html[^>]*lang="([a-z]{2})', re.I)


def jazyk_stranky(html_text):
    """
    Odhad jazyka z atributu <html lang="sk">.

    Presny jazyk urci az model pri tazeni udajov (moze sa lisit od atributu —
    portal je slovensky, ale konkretny inzerat byva v anglictine). Tu ide len
    o rozumnu vychodiskovu hodnotu: stlpec lang je NOT NULL a je sucastou
    unikatneho kluca (offer_id, lang), takze NULL sa pouzit neda.
    """
    m = RE_HTML_LANG.search(html_text[:2000])
    return m.group(1).lower() if m else "sk"


def uloz_detail(cur, offer_id, html_text, fetch_ms=None, fetch_bytes=None):
    """Ulozi komplet HTML detailu ako original. Nikdy sa neprepisuje prekladom."""
    text = html_na_text(html_text)
    lang = jazyk_stranky(html_text)
    cur.execute("""
        INSERT INTO job.offer_content (offer_id, lang, is_original, html_full, text_full)
        VALUES (%s, %s, TRUE, %s, %s)
        ON CONFLICT (offer_id, lang) DO UPDATE SET
            html_full = EXCLUDED.html_full,
            text_full = EXCLUDED.text_full
    """, (offer_id, lang, html_text, text))
    # Metrika stiahnutia — vstup pre prehlad nakladov (job.v_naklady_zber).
    cur.execute("""
        UPDATE job.offers
           SET detail_fetched_at = NOW(), fetch_ms = %s, fetch_bytes = %s
         WHERE id = %s
    """, (fetch_ms, fetch_bytes, offer_id))
    return len(text)


# ============================================================
# Hlavny beh
# ============================================================
def main():
    ap = argparse.ArgumentParser(description="Zber inzeratov z profesia.sk")
    ap.add_argument("--dni", type=int, default=1, help="ponuky za poslednych N dni")
    ap.add_argument("--limit", type=int, default=20,
                    help="max. poc*et inzeratov (0 = bez limitu)")
    ap.add_argument("--run-id", type=int, default=None,
                    help="ID uz zalozeneho behu (ked spusta API)")
    ap.add_argument("--bez-detailov", action="store_true",
                    help="len zoznamy, detaily nestahovat")
    args = ap.parse_args()

    conn = psycopg2.connect(**nacitaj_db_config())
    conn.autocommit = False
    cur = conn.cursor()

    cur.execute("SELECT id, request_delay_ms FROM job.sources WHERE code = 'profesia'")
    row = cur.fetchone()
    if not row:
        sys.exit("V job.sources nie je portal 'profesia'")
    source_id, delay_ms = row
    pauza = (delay_ms or 1500) / 1000.0

    run_id = args.run_id or zaloz_beh(cur, source_id, args.dni, args.limit, "manual")
    conn.commit()
    print("Beh #%d — profesia.sk, poslednych %d dni, limit %s"
          % (run_id, args.dni, args.limit or "bez limitu"))

    session = requests.Session()
    najdene = novych = videnych = detailov = chyb = 0

    try:
        # ---------- FAZA 1: zoznamy ----------
        stranka = 1
        nove_ids = []
        hotovo = False

        while not hotovo:
            url = "%s/praca/?count_days=%d&page_num=%d" % (PORTAL, args.dni, stranka)
            print("  zoznam, strana %d …" % stranka, end=" ")
            try:
                polozky = parsuj_zoznam(stiahni(session, url)[0])
            except Exception as e:
                print("CHYBA: %s" % e)
                chyb += 1
                break

            if not polozky:
                print("prazdna, koniec")
                break
            print("%d ponuk" % len(polozky))

            for p in polozky:
                offer_id, je_novy = uloz_inzerat(cur, source_id, run_id, p)
                najdene += 1
                if je_novy:
                    novych += 1
                    nove_ids.append((offer_id, p["url"]))
                else:
                    videnych += 1

                if args.limit and najdene >= args.limit:
                    hotovo = True
                    break

            conn.commit()
            stranka += 1
            if not hotovo:
                time.sleep(pauza)

        print("Zoznamy: %d najdenych, %d novych, %d uz znamych" % (najdene, novych, videnych))

        # ---------- FAZA 2: detaily len pre nove ----------
        if not args.bez_detailov and nove_ids:
            print("Detaily (%d):" % len(nove_ids))
            for offer_id, url in nove_ids:
                time.sleep(pauza)
                try:
                    h, ms, bajtov = stiahni(session, url)
                    znakov = uloz_detail(cur, offer_id, h, ms, bajtov)
                    detailov += 1
                    conn.commit()
                    print("  OK %s (%d znakov, %d ms)" % (url.split("/")[-1], znakov, ms))
                except Exception as e:
                    chyb += 1
                    conn.rollback()
                    print("  CHYBA %s: %s" % (url.split("/")[-1], e))

        cur.execute("""
            UPDATE job.scrape_runs
               SET status = 'done', finished_at = NOW(),
                   offers_found = %s, offers_new = %s,
                   details_fetched = %s, errors_count = %s
             WHERE id = %s
        """, (najdene, novych, detailov, chyb, run_id))
        conn.commit()

        print("\nHotovo: %d najdenych, %d novych, %d detailov, %d chyb"
              % (najdene, novych, detailov, chyb))
        print("Tazenie udajov modelom spusti: api/cron/zber.php")

    except Exception as e:
        conn.rollback()
        cur.execute("""
            UPDATE job.scrape_runs SET status = 'failed', finished_at = NOW(),
                   error_message = %s
             WHERE id = %s
        """, (str(e)[:500], run_id))
        conn.commit()
        raise
    finally:
        cur.close()
        conn.close()


if __name__ == "__main__":
    main()
