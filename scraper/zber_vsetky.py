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

Vyzaduje: pip install requests psycopg2-binary
"""

import argparse
import hashlib
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
    r.encoding = r.apparent_encoding or "utf-8"
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
VZORY_MIMO = re.compile(
    r"(/prihlasenie|/login|/registracia|/kontakt|/o-nas|/about|/gdpr|/cookies"
    r"|/podmienky|/blog|/clanok|/firmy|/spolocnost|/employers|/zamestnavatel"
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
        vysledok.append((u, t[:300]))
    return vysledok


def najdi_ponuky(html_text, base, ponuk_na_stranu=None):
    """
    Vytiahne zo stranky odkazy, ktore vyzeraju ako detail ponuky.

    Vracia zoznam (url, text_odkazu) bez duplicit, v poradi vyskytu —
    portaly zvyknu mat najnovsie ponuky hore.
    """
    najdene = []
    videne = set()

    for href, text in RE_ODKAZ.findall(html_text):
        url = urljoin(base, html.unescape(href.strip()))

        # Cudzie domeny preskakujeme: agregatory odkazuju na inzeraty
        # inych portalov a tie by sa zbierali dvakrat.
        if urlparse(url).netloc != urlparse(base).netloc:
            continue
        if VZORY_MIMO.search(url) or not VZORY_DETAIL.search(url):
            continue

        url = url.split("?")[0].rstrip("/")
        if url in videne:
            continue
        videne.add(url)

        popis = re.sub(r"<[^>]+>", " ", text)
        popis = re.sub(r"\s+", " ", html.unescape(popis)).strip()
        najdene.append((url, popis[:300]))

    return najdene


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


def uloz_ponuku(cur, source_id, run_id, url, nazov):
    """Vracia (offer_id, je_novy)."""
    ext = external_id_z_url(url)
    cur.execute("""
        INSERT INTO job.offers
            (source_id, external_id, url, title, last_seen_at, first_run_id)
        VALUES (%s, %s, %s, %s, NOW(), %s)
        ON CONFLICT (source_id, external_id) DO UPDATE SET
            last_seen_at = NOW(), missing_runs = 0, is_active = TRUE, updated_at = NOW()
        RETURNING id, (xmax = 0) AS je_novy
    """, (source_id, ext, url[:500], (nazov or ext)[:300], run_id))
    offer_id, je_novy = cur.fetchone()

    cur.execute("""
        INSERT INTO job.scrape_run_offers (run_id, offer_id, action)
        VALUES (%s, %s, %s) ON CONFLICT DO NOTHING
    """, (run_id, offer_id, "new" if je_novy else "seen"))
    return offer_id, je_novy


def uloz_detail(cur, offer_id, h, fetch_ms, fetch_bytes):
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
    return len(text)


# ============================================================
# Zber jedneho portalu
# ============================================================
def zbieraj_portal(conn, zdroj, limit, bez_detailov):
    cur = conn.cursor()
    kod = zdroj["code"]
    url = zdroj["url_kriteria"] or zdroj["base_url"]
    pauza = (zdroj["request_delay_ms"] or 1500) / 1000.0

    print("\n" + "=" * 66)
    print("%s — %s" % (zdroj["name"], url))
    if zdroj["popis"]:
        print("  " + zdroj["popis"][:150].replace("\n", " "))
    print("=" * 66)

    run_id = zaloz_beh(cur, zdroj["id"], zdroj["default_period_days"] or 2)
    conn.commit()

    session = requests.Session()
    najdene = novych = detailov = chyb = 0

    try:
        h, _, _ = stiahni(session, url)
    except Exception as e:
        print("  CHYBA pri stahovani vypisu: %s" % str(e)[:90])
        cur.execute("""UPDATE job.scrape_runs SET status='failed', finished_at=NOW(),
                       error_message=%s WHERE id=%s""", (str(e)[:500], run_id))
        conn.commit()
        cur.close()
        return 0, 0

    jeLinkedIn = kod == "linkedin"
    ponuky = (najdi_ponuky_linkedin(h) if jeLinkedIn
              else najdi_ponuky(h, url, zdroj["ponuk_na_stranu"]))
    print("  Na vypise najdenych odkazov na ponuky: %d" % len(ponuky))

    # --- strankovanie ---
    # Ked portal uvadza pocet ponuk na stranu a nasli sme ich aspon tolko,
    # su pravdepodobne dalsie stranky. Skusaju sa bezne tvary adries.
    # LinkedIn strankuje parametrom start po 10 — ma vlastny cyklus, lebo
    # bezne tvary (page=, strana=) tu nefunguju.
    if jeLinkedIn and len(ponuky) < limit:
        videne = {u for u, _ in ponuky}
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
                videne.update(u for u, _ in nove)
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
                    nove = [p for p in najdi_ponuky(h2, url) if p[0] not in {x[0] for x in ponuky}]
                    if nove:
                        ponuky.extend(nove)
                        print("    strana %d (%s): +%d" % (strana, vzor % strana, len(nove)))
                        break
                except Exception:
                    continue
            else:
                break      # ziadny tvar adresy nezabral — strankovanie koncime

    ponuky = ponuky[:limit]
    print("  Spracujem: %d ponuk" % len(ponuky))

    # --- ulozenie ponuk ---
    nove_ids = []
    for u, nazov in ponuky:
        try:
            offer_id, je_novy = uloz_ponuku(cur, zdroj["id"], run_id, u, nazov)
            najdene += 1
            if je_novy:
                novych += 1
                nove_ids.append((offer_id, u))
        except Exception as e:
            chyb += 1
            conn.rollback()
            print("  CHYBA ulozenia %s: %s" % (u[-40:], str(e)[:60]))
    conn.commit()
    print("  Ulozenych: %d, z toho novych: %d" % (najdene, novych))

    # --- detaily len pre nove ---
    if not bez_detailov and nove_ids:
        print("  Detaily (%d):" % len(nove_ids))
        for offer_id, u in nove_ids:
            time.sleep(pauza)
            try:
                h2, ms, bajtov = stiahni(session, u)
                znakov = uloz_detail(cur, offer_id, h2, ms, bajtov)
                detailov += 1
                conn.commit()
                if detailov % 10 == 0 or detailov == len(nove_ids):
                    print("    %d/%d hotovo" % (detailov, len(nove_ids)))
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
def main():
    ap = argparse.ArgumentParser(description="Zber zo vsetkych portalov")
    ap.add_argument("--limit", type=int, default=50, help="max. ponuk na portal")
    ap.add_argument("--portal", help="iba jeden portal (kod z job.sources)")
    ap.add_argument("--bez-detailov", action="store_true")
    args = ap.parse_args()

    conn = pripoj_db()
    conn.autocommit = False
    cur = conn.cursor()

    # LinkedIn a spol. sa preskakuju — vyzaduju prihlasenie a obsah dotahuju
    # javascriptom, takze beznym stahovanim HTML sa nezbieraju.
    sql = """SELECT id, code, name, base_url, url_kriteria, popis, ponuk_na_stranu,
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

    print("Portalov na zber: %d, limit %d ponuk na portal" % (len(zdroje), args.limit))
    zaciatok = time.time()
    spolu_novych = spolu_detailov = 0

    for z in zdroje:
        try:
            n, d = zbieraj_portal(conn, z, args.limit, args.bez_detailov)
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
