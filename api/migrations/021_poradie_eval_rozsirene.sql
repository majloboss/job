-- Sirsie poradie modelov pre vyhodnotenie vhodnosti: 8 bezplatnych + 2 platene.
--
-- Poradie malo styri bezplatne modely a ziadny plateny. Kazdy bezplatny ma
-- vlastny denny limit a ked ich vycerpa, straz vyhodnocovanie vypne —
-- 14.9.2026 sa tak zastavilo po 19 posudkoch z 247, hoci minute bolo $0.
--
-- Vyber sa opiera o test modelov (job.test_vysledky, uloha 'vhodnost'):
-- beru sa tie s najvyssou uspesnostou a radia sa od najrychlejsich.
-- Sedem bezplatnych preslo vsetky pokusy; ako osmy je nemotron-3-super
-- (3 zo 4) — je posledny v poradi bezplatnych, takze jeho obcasne zlyhanie
-- len posunie zber na plateny model. Vypadavaju modely s uspesnostou
-- polovica a menej (cohere/north-mini-code, nemotron-3.5-lightning):
-- slaby model v poradi znamena zbytocne zlyhanie a cakanie.
--
-- Na konci su DVA platene zachranne modely. Bezia az ked vsetky bezplatne
-- zlyhaju, takze bezne nestoja nic. Pri ~5000 tokenoch na ponuku vyjde
-- cela davka 250 ponuk na jednotky centov:
--   openai/gpt-oss-20b  $0.16/1M  ≈ $0.20 za davku
--   deepseek-v4-flash   $0.27/1M  ≈ $0.33 za davku
-- Su to najlacnejsie z tych, ktore v teste preslo vsetky pokusy.
--
-- Pocet modelov v poradi nie je nicim obmedzeny — ani v DB, ani v kode.
DELETE FROM job.ai_poradie WHERE ucel = 'eval';

INSERT INTO job.ai_poradie (ucel, source_id, poradie, model_id, is_enabled)
SELECT 'eval', NULL, v.poradie, m.id, TRUE
  FROM (VALUES
        -- bezplatne, zoradene od najrychlejsich (priemer z testu)
        (1,  'inclusionai/ling-3.0-flash-fin:free'),      --  2,1 s
        (2,  'inclusionai/ling-3.0-flash-sante:free'),    --  2,2 s
        (3,  'nex-agi/nex-n2.5-mini:free'),               --  4,7 s
        (4,  'dots-studio/dots-3-note-preview:free'),     --  6,3 s
        (5,  'liquid/lfm-2.5-2.6b:free'),                 -- 27,0 s
        (6,  'nex-agi/nex-n2.5-pro:free'),                -- 32,0 s
        (7,  'nvidia/nemotron-3-ultra-550b-a55b:free'),   -- 75,1 s
        (8,  'nvidia/nemotron-3-super-120b-a12b:free'),   -- 24,3 s, 3 zo 4
        -- platene, az ked vsetky bezplatne zlyhaju
        (9,  'openai/gpt-oss-20b'),                       -- $0.16 / 1M
        (10, 'deepseek/deepseek-v4-flash')                -- $0.27 / 1M
       ) AS v(poradie, model_id)
  JOIN job.ai_models m ON m.model_id = v.model_id;
