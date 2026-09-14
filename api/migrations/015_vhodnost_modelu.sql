-- ============================================================
-- Migration 015: rucne znacky vhodnosti modelu
--
-- Test ukaze, co ktory model vytiahol, ale rozhodnutie "tento je na to
-- dobry" je na cloveku — cislo ani zhoda ho nenahradia. Preto sa ku
-- kazdemu vysledku da odskrtnut, ci je model vhodny na:
--
--   vhodnost_zber          — vytazenie udajov z inzeratu
--   vhodnost_vyhodnotenie  — posudenie vhodnosti pre pouzivatela
--
-- Znacka plati pre RIADOK, teda kombinaciu (beh, inzerat, model, uloha).
-- Model dobry na jednom inzerate nemusi obstat na druhom a prave to sa
-- ma dat zaznamenat.
-- ============================================================

BEGIN;

ALTER TABLE job.test_vysledky
    ADD COLUMN IF NOT EXISTS vhodnost_zber BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE job.test_vysledky
    ADD COLUMN IF NOT EXISTS vhodnost_vyhodnotenie BOOLEAN NOT NULL DEFAULT FALSE;

COMMENT ON COLUMN job.test_vysledky.vhodnost_zber IS
    'Rucna znacka: model je vhodny na vytazenie udajov z inzeratu';
COMMENT ON COLUMN job.test_vysledky.vhodnost_vyhodnotenie IS
    'Rucna znacka: model je vhodny na posudenie vhodnosti pre pouzivatela';

-- Filtrovanie "ukaz len oznacene" nema prechadzat cely beh.
CREATE INDEX IF NOT EXISTS test_vysledky_vhodnost_idx
    ON job.test_vysledky (beh_id, vhodnost_zber, vhodnost_vyhodnotenie);

INSERT INTO admin.schema_versions (version, description)
VALUES (15, 'Rucne znacky vhodnosti modelu na zber a vyhodnotenie')
ON CONFLICT (version) DO NOTHING;

COMMIT;
