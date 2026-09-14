-- Portal, ktory sprostredkuva pracu pre nemenovaneho klienta.
--
-- ariva.sk, titans.eu a podobne kontraktorske portaly zamestnavatela
-- neuvadzaju — inzerat pisu sami za seba. Stlpec firmy tak zostaval prazdny
-- a v zozname to vyzeralo ako chyba zberu, hoci na zdroji ziadna firma nie je.
--
-- Priznak je na PORTALI, nie na inzerate: vyplyva z toho, ako portal funguje,
-- takze sa nema dohadovat pri kazdom inzerate zvlast.
ALTER TABLE job.sources
    ADD COLUMN IF NOT EXISTS je_agentura BOOLEAN NOT NULL DEFAULT FALSE;

COMMENT ON COLUMN job.sources.je_agentura IS
    'Portal sprostredkuje pracu pre nemenovaneho klienta — inzerat nema firmu';

-- Kontraktorske a personalne agentury zo zoznamu portalov.
UPDATE job.sources
   SET je_agentura = TRUE
 WHERE code IN ('ariva', 'titans', 'lugera', 'recrulab',
                'sourcefirst', 'successfirst');

-- Inzeraty z tychto portalov su agenturne — dolezite pre filter "bez agentur".
UPDATE job.offers o
   SET is_agency_offer = TRUE
  FROM job.sources s
 WHERE s.id = o.source_id
   AND s.je_agentura
   AND o.is_agency_offer IS NOT TRUE;
