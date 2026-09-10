BEGIN;

-- LinkedIn sa DA zbierat bez prihlasenia.
--
-- Povodne bol oznaceny ako vyzaduje_prihlasenie, lebo bezna stranka
-- /jobs/ obsah dotahuje javascriptom. LinkedIn ma vsak verejne rozhranie
-- pre neprihlasenych (jobs-guest/.../seeMoreJobPostings), ktore vracia
-- hotove HTML karty s odkazom aj datumom zverejnenia.
--
-- Overene 10.9.2026: 105+ unikatnych ponuk cez strankovanie po 10,
-- detail ponuky dostupny (21 800 znakov textu).
--
-- Pre porovnanie: agent_kariera to riesil cez prihlaseny prehliadac
-- a narazil na to, ze karty v zozname NEMAJU odkaz — job ID sa da ziskat
-- len klikom na kazdu kartu zvlast. Pri 71-82 ponukach to bolo neunosne
-- a zber sa obmedzil na 14 kuratovanych poloziek. Verejne rozhranie je
-- teda nielen jednoduchsie, ale aj uplnejsie.
UPDATE job.sources SET
    vyzaduje_prihlasenie = FALSE,
    url_kriteria = 'https://www.linkedin.com/jobs-guest/jobs/api/seeMoreJobPostings/search'
                || '?keywords=&location=Bratislava%2C%20Bratislava%2C%20Slovakia&f_TPR=r604800',
    popis = 'Verejne rozhranie pre neprihlasenych — prihlasenie NETREBA. '
         || 'f_TPR=r604800 je filter na posledny tyzden (v sekundach), '
         || 'location sa da zmenit na iny kraj. Strankovanie parametrom start '
         || 'po 10 ponuk (start=0,10,20...). Kazda karta ma odkaz na detail '
         || 'aj datum zverejnenia v atribute datetime. Detail ponuky je '
         || 'dostupny na sk.linkedin.com/jobs/view/<slug>-<id> a obsahuje '
         || 'plny text "About the job".',
    ponuk_na_stranu = 10,
    default_period_days = 7,
    request_delay_ms = 2000
 WHERE code = 'linkedin';

INSERT INTO admin.schema_versions (version, description)
VALUES (12, 'LinkedIn cez verejne rozhranie pre neprihlasenych — prihlasenie netreba')
ON CONFLICT (version) DO NOTHING;

COMMIT;
