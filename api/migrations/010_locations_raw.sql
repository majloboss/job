BEGIN;
ALTER TABLE job.offers ADD COLUMN IF NOT EXISTS locations_raw TEXT[];
COMMENT ON COLUMN job.offers.locations_raw IS
    'Miesta vykonu prace ako ich vratil model. Docasne, kym nie je naplneny ciselnik job.locations (faza 2) — potom sa znormalizuju do job.offer_locations, ktora vyzaduje location_id.';
CREATE INDEX IF NOT EXISTS offers_locations_raw_idx ON job.offers USING GIN (locations_raw);
INSERT INTO admin.schema_versions (version, description)
VALUES (10, 'job.offers.locations_raw - miesta z modelu, kym nie je ciselnik lokalit')
ON CONFLICT (version) DO NOTHING;
COMMIT;
