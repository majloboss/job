-- Zaujem pouzivatela o ponuku: nevyhodnoteny / zaujem / nezaujem.
--
-- Pri opakovanom prezerani sa clovek znova a znova prehrabuje tymi istymi
-- ponukami, ktore uz raz zamietol. Priznak "nezaujem" ich odfiltruje.
--
-- Stav je na DVOJICI pouzivatel+ponuka, nie na ponuke: je to osobne
-- rozhodnutie a ta ista ponuka moze inemu cloveku sediet.
--
-- "Nevyhodnoteny" sa NEUKLADA — je to vychodzi stav a znamena, ze zaznam
-- este neexistuje. Ukladat ho by len plnilo tabulku riadkami bez informacie.
--
-- Tabulka uz existovala z pociatocnej schemy, ale bez obmedzenia hodnot
-- a nikde sa nepouzivala. Dopĺňa sa kontrola, index a komentare.
ALTER TABLE job.user_offer_status
    DROP CONSTRAINT IF EXISTS user_offer_status_status_chk;

ALTER TABLE job.user_offer_status
    ADD CONSTRAINT user_offer_status_status_chk
    CHECK (status IN ('zaujem', 'nezaujem'));

COMMENT ON COLUMN job.user_offer_status.status IS
    'zaujem / nezaujem. Chybajuci riadok = nevyhodnoteny (vychodzi stav).';

-- Vypis ponuk filtruje prave podla tohto: pre jedneho pouzivatela naraz.
CREATE INDEX IF NOT EXISTS user_offer_status_user_idx
    ON job.user_offer_status (user_id, status);
