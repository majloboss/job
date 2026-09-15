-- Ciselnik stavov zaujmu o ponuku.
--
-- Hodnoty boli natvrdo v kode (kontrola v DB, zoznam v PHP aj v obrazovke),
-- takze pridanie stvrteho stavu by znamenalo zmenu na troch miestach a
-- nasadenie. Teraz su na jednom mieste v DB a obrazovka ich len zobrazi.
--
-- "Nevyhodnoteny" je v ciselniku tiez, hoci sa NEUKLADA do user_offer_status:
-- je to vychodzi stav (chybajuci riadok) a obrazovka pre neho potrebuje
-- nazov, farbu aj poradie rovnako ako pre ostatne.
CREATE TABLE IF NOT EXISTS job.stavy_zaujmu (
    kod          VARCHAR(20) PRIMARY KEY,
    nazov        VARCHAR(50)  NOT NULL,
    popis        VARCHAR(200),
    farba        VARCHAR(20)  NOT NULL DEFAULT 'muted',
    poradie      SMALLINT     NOT NULL,
    -- Vychodzi stav sa nezapisuje do DB, len sa zobrazuje.
    je_vychodzi  BOOLEAN      NOT NULL DEFAULT FALSE,
    -- Skryt ponuku z bezneho vypisu?
    skryva       BOOLEAN      NOT NULL DEFAULT FALSE,
    is_active    BOOLEAN      NOT NULL DEFAULT TRUE
);

COMMENT ON TABLE job.stavy_zaujmu IS
    'Ciselnik stavov zaujmu pouzivatela o ponuku (stlpec Zaujem vo vypise)';

INSERT INTO job.stavy_zaujmu (kod, nazov, popis, farba, poradie, je_vychodzi, skryva)
VALUES
    ('nevyhodnoteny', 'Nevyhodnotený',
     'Ponuku som ešte nepozeral', 'muted', 1, TRUE, FALSE),
    ('zaujem', 'Záujem',
     'Ponuka ma zaujala', 'ok', 2, FALSE, FALSE),
    ('nezaujem', 'Nezáujem',
     'Ponuka ma nezaujala — skryje sa z výpisu', 'bad', 3, FALSE, TRUE)
ON CONFLICT (kod) DO UPDATE SET
    nazov = EXCLUDED.nazov, popis = EXCLUDED.popis, farba = EXCLUDED.farba,
    poradie = EXCLUDED.poradie, je_vychodzi = EXCLUDED.je_vychodzi,
    skryva = EXCLUDED.skryva;

-- Kontrola hodnot uz nie je zoznam v kode, ale vazba na ciselnik.
ALTER TABLE job.user_offer_status
    DROP CONSTRAINT IF EXISTS user_offer_status_status_chk;

-- Vychodzi stav sa do tabulky nezapisuje — riadok s nim by nic nehovoril.
DELETE FROM job.user_offer_status
 WHERE status IN (SELECT kod FROM job.stavy_zaujmu WHERE je_vychodzi);

ALTER TABLE job.user_offer_status
    DROP CONSTRAINT IF EXISTS user_offer_status_status_fkey;

ALTER TABLE job.user_offer_status
    ADD CONSTRAINT user_offer_status_status_fkey
    FOREIGN KEY (status) REFERENCES job.stavy_zaujmu (kod);

-- Cudzi kluc sam by pustil aj vychodzi stav. Ten sa vsak zapisovat nema —
-- znamena "ziadny zaznam" a riadok s nim by si protirecil.
CREATE OR REPLACE FUNCTION job.stav_zaujmu_nie_vychodzi() RETURNS TRIGGER AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM job.stavy_zaujmu
                WHERE kod = NEW.status AND je_vychodzi) THEN
        RAISE EXCEPTION
            'Vychodzi stav "%" sa nezapisuje — zrus zaznam namiesto toho',
            NEW.status;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS user_offer_status_nie_vychodzi ON job.user_offer_status;
CREATE TRIGGER user_offer_status_nie_vychodzi
    BEFORE INSERT OR UPDATE ON job.user_offer_status
    FOR EACH ROW EXECUTE FUNCTION job.stav_zaujmu_nie_vychodzi();
