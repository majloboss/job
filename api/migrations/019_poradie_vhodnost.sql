-- Poradie modelov pre VYHODNOTENIE VHODNOSTI (ucel 'eval').
--
-- Ciselnik mal poradie len pre zber ('parse'), takze druhy krok aplikacie
-- by sa zastavil hned na vybere modelu. Zaklada sa na tom istom poradi ako
-- zber — modely su overene a druhy krok zacina s rovnakou zostavou.
--
-- Ucely zostavaju oddelene zamerne: zber bezi raz za inzerat, vhodnost za
-- kazdeho pouzivatela zvlast, takze sa casom budu lisit aj modelmi aj
-- rozpoctom.
INSERT INTO job.ai_poradie (ucel, source_id, poradie, model_id, is_enabled)
SELECT 'eval', p.source_id, p.poradie, p.model_id, p.is_enabled
  FROM job.ai_poradie p
 WHERE p.ucel = 'parse'
   AND NOT EXISTS (SELECT 1 FROM job.ai_poradie e
                    WHERE e.ucel = 'eval'
                      AND e.model_id = p.model_id
                      AND e.source_id IS NOT DISTINCT FROM p.source_id);
