-- ============================================================
-- JOB - Vytvorenie pouzivatelov: admin + Milo
--
-- POZOR: do stlpca password nepatri heslo v citatelnej podobe, ale jeho
-- BCRYPT HASH. Aplikacia overuje heslo cez PHP password_verify(), ktore
-- ocakava hash zacinajuci na $2y$ — cisty text by neprihlasil nikoho.
--
-- AKO ZISKAT HASH (staci jeden zo sposobov):
--
--   1) Node.js — v priecinku projektu:
--        node tools/hash_hesla.cjs "mojeHeslo"
--
--   2) PHP na serveri:
--        php -r "echo password_hash('mojeHeslo', PASSWORD_BCRYPT), PHP_EOL;"
--
--   3) Online generator bcrypt (pouzi len na docasne heslo,
--      ktore si po prihlaseni hned zmenis)
--
-- Hash vyzera takto (60 znakov):
--   $2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy
--
-- Skopiruj ho do prislusneho riadku nizsie namiesto ZMEN_MA.
-- ============================================================

-- ============================================================
-- ADMIN — plne prava, vidi admin sekciu a laboratorium modelov
-- ============================================================
INSERT INTO admin.users
    (username, password, role, is_active, username_changed, first_name, email)
VALUES
    ('admin',
     'ZMEN_MA_HASH_ADMINA',          -- <<< sem bcrypt hash
     'admin',
     TRUE,                            -- ucet je rovno aktivny
     TRUE,                            -- meno uz netreba menit pri prvom prihlaseni
     'Admin',
     NULL)
ON CONFLICT (username) DO UPDATE
    SET password = EXCLUDED.password,   -- opakovane spustenie = zmena hesla
        role     = EXCLUDED.role,
        is_active = TRUE;

-- ============================================================
-- MILO — bezny pouzivatel
-- Ak ma mat tiez pravo na laboratorium modelov, zmen 'user' na 'admin'.
-- ============================================================
INSERT INTO admin.users
    (username, password, role, is_active, username_changed, first_name, email)
VALUES
    ('Milo',
     'ZMEN_MA_HASH_MILO',            -- <<< sem bcrypt hash
     'user',
     TRUE,
     TRUE,
     'Milo',
     NULL)
ON CONFLICT (username) DO UPDATE
    SET password = EXCLUDED.password,
        role     = EXCLUDED.role,
        is_active = TRUE;

-- ============================================================
-- Zakladne nastavenia notifikacii pre oboch (nepovinne, ale prakticke)
-- ============================================================
INSERT INTO admin.notification_settings (user_id, notif_type, push_enabled, email_enabled)
SELECT u.id, t.typ, TRUE, FALSE
  FROM admin.users u
 CROSS JOIN (VALUES ('new_matches'), ('daily_digest')) AS t(typ)
 WHERE u.username IN ('admin', 'Milo')
ON CONFLICT (user_id, notif_type) DO NOTHING;

-- ============================================================
-- Prazdne preferencie, aby sa dali rovno ukladat z appky
-- ============================================================
INSERT INTO job.user_preferences (user_id)
SELECT id FROM admin.users WHERE username IN ('admin', 'Milo')
ON CONFLICT (user_id) DO NOTHING;

-- ============================================================
-- KONTROLA — po spusteni musi vypisat dvoch pouzivatelov
-- a hash zacinajuci na $2y$ (nie ZMEN_MA...).
-- ============================================================
SELECT id,
       username,
       role,
       is_active,
       CASE WHEN password LIKE '$2%' THEN 'OK — bcrypt hash'
            ELSE '!! CHYBA: nie je to hash, prihlasenie nebude fungovat'
       END AS stav_hesla
  FROM admin.users
 ORDER BY id;
