-- ============================================================
-- Migration 011: rozsirenie ciselnika portalov + naplnenie 13 zdrojov
--
-- Doteraz mal job.sources iba base_url. Na skutocny zber to nestaci:
--
--   url_kriteria — adresa UZ S NASTAVENYMI FILTRAMI (kraj, obdobie, radius).
--                  Kazdy portal ma iny sposob filtrovania a skladat ho
--                  v kode by znamenalo trinast vynimiek. Ked sa filter
--                  zmeni, upravi sa riadok v DB, nie kod.
--
--   popis        — slovne usmernenie PRE MODEL aj pre scraper: ako sa na
--                  portali orientovat, kde je strankovanie, podla coho sa
--                  pozna neaktualna ponuka. Kazdy portal ma svoje zvlastnosti
--                  (OBSADENE v nazve, NEPRIJIMAME ZAUJEMCOV, tlacidlo
--                  "Dalsie ponuky") a natvrdo v kode by sa udrziavali zle.
--
-- Zadanie: ZADANIE_JOB.md, kapitola 3
-- ============================================================

BEGIN;

ALTER TABLE job.sources
    ADD COLUMN IF NOT EXISTS url_kriteria VARCHAR(500);
ALTER TABLE job.sources
    ADD COLUMN IF NOT EXISTS popis TEXT;

-- Kolko ponuk je na jednej strane vypisu. Scraper podla toho vie, ci ma
-- pokracovat na dalsiu stranu, alebo uz videl vsetko.
ALTER TABLE job.sources
    ADD COLUMN IF NOT EXISTS ponuk_na_stranu INT;

-- Portal, ktory potrebuje prihlasenie alebo javascript, sa zatial zbierat
-- neda — priznak drzi, ze o nom vieme, ale automat ho preskoci.
ALTER TABLE job.sources
    ADD COLUMN IF NOT EXISTS vyzaduje_prihlasenie BOOLEAN NOT NULL DEFAULT FALSE;

COMMENT ON COLUMN job.sources.url_kriteria IS
    'Adresa vypisu uz s nastavenymi filtrami (kraj, obdobie, radius)';
COMMENT ON COLUMN job.sources.popis IS
    'Slovne usmernenie pre model aj scraper: strankovanie, priznaky neaktualnych ponuk';

-- ============================================================
-- Naplnenie portalov
--
-- Existujuce (profesia, pracazarohom, kariera) sa UPDATUJU, aby sa
-- nestratili vazby z job.offers. Ostatne pribudaju.
-- ============================================================

-- --- profesia.sk (uz zbierame) ---
UPDATE job.sources SET
    url_kriteria = 'https://www.profesia.sk/praca/bratislava/?count_days=2&radius=radius100',
    popis = 'Link ma uz nastaveny filter na Bratislavu + 100 km za posledne dva dni. '
          || 'Pozor na strankovanie, na stranke je 20 ponuk. '
          || 'Detail ponuky je na /praca/<firma>/O<cislo>, kde O<cislo> je stabilny '
          || 'externy identifikator pouzitelny na deduplikaciu.',
    ponuk_na_stranu = 20,
    default_period_days = 2
 WHERE code = 'profesia';

-- --- pracazarohom.sk ---
UPDATE job.sources SET
    base_url = 'https://www.pracazarohom.sk',
    url_kriteria = 'https://www.pracazarohom.sk/ponuky/bratislavsky',
    popis = 'V linku je uz nastaveny filter na Bratislavsky kraj. Pri kazdom inzerate '
          || 'je uvedene, kedy bol zverejneny, SLOVNE (Len par minut, Len par hodin, '
          || 'Dnesne, Vcerajsie, 2 dni, Menej ako tyzden...). Pozor na strankovanie: '
          || 'skroluj a klikaj na "Dalsie ponuky", pokial neskonci info "2 dni".',
    default_period_days = 2
 WHERE code = 'pracazarohom';

-- --- kariera.zoznam.sk (povodny zaznam mal zlu adresu) ---
UPDATE job.sources SET
    name = 'Kariéra Zoznam.sk',
    base_url = 'https://kariera.zoznam.sk',
    url_kriteria = 'https://kariera.zoznam.sk/pracovne-ponuky/vsetky/bratislavsky-kraj',
    popis = 'Pri kazdej ponuke je uvedeny datum zverejnenia — zober vsetky ponuky '
          || 'za posledne dva dni. Pozor na strankovanie, na stranke je 20 ponuk.',
    ponuk_na_stranu = 20,
    default_period_days = 2
 WHERE code = 'kariera';

-- --- nove portaly ---
INSERT INTO job.sources
    (code, name, base_url, url_kriteria, popis, ponuk_na_stranu,
     country, is_active, scrape_interval_minutes, request_delay_ms,
     default_period_days, vyzaduje_prihlasenie)
VALUES
    ('successfirst', 'Success First', 'https://www.successfirst.eu',
     'https://www.successfirst.eu/sk/kariera/',
     'Vsetky pracovne ponuky na jednej stranke.',
     NULL, 'SK', TRUE, 120, 1500, 7, FALSE),

    ('ariva', 'Ariva', 'https://www.ariva.sk',
     'https://www.ariva.sk/pozicie',
     'Vsetky pracovne ponuky. POZOR: ak je v nazve pozicie text OBSADENE, '
     || 'ponuka uz nie je aktualna — preskoc ju.',
     NULL, 'SK', TRUE, 120, 1500, 7, FALSE),

    ('sourcefirst', 'Source First International', 'https://www.sourcefirstinternational.com',
     'https://www.sourcefirstinternational.com/sk/pracovne-ponuky',
     'Vsetky pracovne ponuky. Pozor na strankovanie, na jednej strane je len 25 ponuk.',
     25, 'SK', TRUE, 120, 1500, 7, FALSE),

    ('koderia', 'Koderia', 'https://koderia.sk',
     'https://koderia.sk/sk/jobs',
     'Vsetky pracovne ponuky. Portal je zamerany na IT.',
     NULL, 'SK', TRUE, 120, 1500, 7, FALSE),

    ('titans', 'Titans', 'https://join.titans.eu',
     'https://join.titans.eu/sk',
     'Vyhladaj a nastav filter "Iba otvorene projekty". Ak je pri ponuke napisane '
     || 'NEPRIJIMAME ZAUJEMCOV, ponuka uz nie je aktualna — preskoc ju. '
     || 'Pozor na strankovanie, na jednej strane je len 20 ponuk.',
     20, 'SK', TRUE, 120, 1500, 7, FALSE),

    ('recrulab', 'Recrulab', 'https://recrulab.sk',
     'https://recrulab.sk/pozicie/',
     'Vsetky pracovne ponuky.',
     NULL, 'SK', TRUE, 120, 1500, 7, FALSE),

    ('lugera', 'Lugera', 'https://lugera.sk',
     'https://lugera.sk/pracovne-ponuky/',
     'Nastav filter Bratislavsky kraj. Pozor na strankovanie, na jednej strane '
     || 'je len 10 ponuk.',
     10, 'SK', TRUE, 120, 1500, 7, FALSE),

    ('jooble', 'Jooble', 'https://sk.jooble.org',
     'https://sk.jooble.org/SearchResult?date=2&loc=10&rgns=Bratislava',
     'Zober vsetky ponuky podla filtra, ktory je uz nastaveny v linku '
     || '(Bratislava, posledne 2 dni). Pozor na strankovanie. '
     || 'Jooble je agregator — ponuky sa mozu prekryvat s inymi portalmi.',
     NULL, 'SK', TRUE, 120, 1500, 2, FALSE),

    -- LinkedIn vyzaduje prihlasenie a obsah dotahuje javascriptom, takze
    -- beznym stahovanim HTML sa nezbiera. Zaznam tu je, aby sa na portal
    -- nezabudlo, ale automat ho preskoci.
    ('linkedin', 'LinkedIn', 'https://www.linkedin.com',
     'https://www.linkedin.com/jobs/',
     'IBA sekcia "Jobs based on your preferences". Pozor na strankovanie, '
     || 'zober iba ponuky za posledny tyzden. Detail ponuky sa ziska po kliknuti '
     || 'na "About the job". VYZADUJE PRIHLASENIE a obsah sa dotahuje javascriptom '
     || '— beznym stahovanim HTML sa nezbiera.',
     NULL, 'SK', TRUE, 120, 2000, 7, TRUE)
ON CONFLICT (code) DO UPDATE SET
    name         = EXCLUDED.name,
    base_url     = EXCLUDED.base_url,
    url_kriteria = EXCLUDED.url_kriteria,
    popis        = EXCLUDED.popis,
    ponuk_na_stranu = EXCLUDED.ponuk_na_stranu,
    default_period_days  = EXCLUDED.default_period_days,
    vyzaduje_prihlasenie = EXCLUDED.vyzaduje_prihlasenie;

INSERT INTO admin.schema_versions (version, description)
VALUES (11, 'Portaly: url_kriteria, popis, ponuk_na_stranu + naplnenie 12 zdrojov')
ON CONFLICT (version) DO NOTHING;

COMMIT;
