-- Vychodzia adresa zberu moze byt dlha.
--
-- Portaly drzia filtre v adrese: titans.eu ma predvyplneny vyber miest ako
-- 19 parametrov label_city[] a taka adresa ma vyse 700 znakov. Stlpec mal
-- 500, takze sa nedala ulozit a zber by bezal bez filtra.
--
-- TEXT namiesto vacsieho VARCHAR: hranica poctu znakov tu nema co chranit,
-- adresa je taka, aku portal vygeneruje.
ALTER TABLE job.sources
    ALTER COLUMN url_kriteria TYPE TEXT;
