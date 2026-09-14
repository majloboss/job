-- Portal moze mat VIAC vychodzich adries.
--
-- profesia.sk nevie dat vsetky ponuky na jednom zozname — kazdy kraj ma
-- vlastnu adresu a zber preto musi prejst niekolko kol. Doteraz mal portal
-- jedinu url_kriteria, takze sa dal zozbierat len jeden kraj.
--
-- Vazba je 1:N, ale drzi sa v POLI, nie vo vlastnej tabulke: je to zoznam
-- adries bez dalsich vlastnosti a samostatna tabulka by k nemu pridala JOIN
-- bez uzitku. url_kriteria zostava ako prva adresa, aby sa nemusel menit
-- kod, ktory s nou pracuje.
ALTER TABLE job.sources
    ADD COLUMN IF NOT EXISTS url_kriteria_dalsie TEXT[] NOT NULL DEFAULT '{}';

COMMENT ON COLUMN job.sources.url_kriteria_dalsie IS
    'Dalsie vychodzie adresy zberu popri url_kriteria — napr. kraje na profesia.sk';

-- profesia.sk: Bratislavsky kraj zostava hlavny, ostatne kola sa pridavaju.
UPDATE job.sources
   SET url_kriteria = 'https://www.profesia.sk/praca/bratislavsky-kraj/',
       url_kriteria_dalsie = ARRAY[
           'https://www.profesia.sk/praca/trnavsky-kraj/',
           'https://www.profesia.sk/praca/nitriansky-kraj/',
           'https://www.profesia.sk/praca/banskobystricky-kraj/',
           'https://www.profesia.sk/praca/zahranicie/'
       ]
 WHERE code = 'profesia';
