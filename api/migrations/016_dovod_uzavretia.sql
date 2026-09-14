-- ============================================================
-- Migration 016: dovod uzavretia inzeratu
--
-- is_active a closed_at uz existuju z migracie 001, ale nehovoria PRECO
-- je inzerat uzavrety. Rozlisenie je podstatne:
--
--   'obsadene'  — portal ponuku vyslovne oznacil (ariva.sk pise pri nazve
--                 " - OBSADENE"). Je to isty udaj priamo od zdroja.
--   'zmizol'    — ponuka prestala byt v zoznamoch. Moze to znamenat
--                 obsadenie, stiahnutie inzeratu aj chybu zberu.
--   'expiroval' — presiel datum platnosti uvedeny v inzerate.
--
-- Bez toho by sa nedalo povedat, ci je ponuka naozaj uzavreta, alebo
-- ju len zber prestal vidiet.
-- ============================================================

BEGIN;

ALTER TABLE job.offers
    ADD COLUMN IF NOT EXISTS closed_reason VARCHAR(20);

COMMENT ON COLUMN job.offers.closed_reason IS
    'Preco je inzerat uzavrety: obsadene | zmizol | expiroval';

-- Filter "ukaz len aktualne ponuky" je najcastejsi dopyt nad tabulkou.
CREATE INDEX IF NOT EXISTS offers_aktivne_idx
    ON job.offers (is_active, closed_reason);

INSERT INTO admin.schema_versions (version, description)
VALUES (16, 'Dovod uzavretia inzeratu (obsadene / zmizol / expiroval)')
ON CONFLICT (version) DO NOTHING;

COMMIT;
