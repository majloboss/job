#!/usr/bin/env node
// Zosynchronizuje ciselnik job.ai_models s cennikom OpenRoutera.
//
// To iste robi endpoint /v1/admin/ai-ciselnik?akcia=sync, ale ten potrebuje
// prihlaseneho admina. Tento nastroj sluzi na prve naplnenie ciselnika a na
// kontrolu z prikazoveho riadka.
//
// Pouzitie: node tools/sync_cennik.cjs
const fs = require('fs');
const path = require('path');
const { Client } = require('pg');

const conf = f => fs.readFileSync(path.join(__dirname, '..', f), 'utf8');
const val = (text, key) => {
    const m = text.match(new RegExp("define\\('" + key + "'\\s*,\\s*'([^']*)'"));
    if (!m) throw new Error('Chyba ' + key);
    return m[1];
};

const db = conf('api/config/db.php');
const or = conf('api/config/openrouter.php');
const KEY = val(or, 'OPENROUTER_KEY');

(async () => {
    const res = await fetch('https://openrouter.ai/api/v1/models', {
        headers: { Authorization: 'Bearer ' + KEY },
    });
    if (!res.ok) throw new Error('OpenRouter vratil HTTP ' + res.status);
    const { data } = await res.json();

    const client = new Client({
        host: val(db, 'DB_HOST'), port: Number(val(db, 'DB_PORT')),
        database: val(db, 'DB_NAME'), user: val(db, 'DB_USER'),
        password: val(db, 'DB_PASS'), ssl: { rejectUnauthorized: false },
    });
    await client.connect();

    let pridanych = 0, aktualiz = 0;
    const videne = [];

    try {
        for (const m of data) {
            if (!m.id) continue;
            // Varianty ako :batch a -image nepotrebujeme.
            if (m.id.includes(':batch') || m.id.includes('-image')) continue;

            // Len modely, ktore vracaju VYLUCNE text. Hudobne a obrazkove
            // (lyria, veo) maju v modalitach aj 'text', ale za tokeny nic
            // nestoja — v poradi podla ceny by vysli ako najlacnejsie.
            const out = m.architecture?.output_modalities ?? ['text'];
            if (out.length !== 1 || out[0] !== 'text') continue;

            // OpenRouter uvadza cenu za JEDEN token, drzime za milion.
            // Zaporna hodnota = cena podla skutocne pouziteho modelu
            // (openrouter/auto); taku nevieme dopredu, uklada sa NULL.
            const cena = v => {
                if (v === null || v === undefined) return null;
                const f = parseFloat(v);
                return Number.isNaN(f) || f < 0 ? null : f * 1000000;
            };

            const r = await client.query(
                `INSERT INTO job.ai_models
                    (provider, model_id, name, is_free, price_input_1m, price_output_1m,
                     context_length, is_text_only, updated_at)
                 VALUES ('openrouter', $1, $2, $3, $4, $5, $6, TRUE, NOW())
                 ON CONFLICT (model_id) DO UPDATE SET
                    name = EXCLUDED.name, is_free = EXCLUDED.is_free,
                    price_input_1m = EXCLUDED.price_input_1m,
                    price_output_1m = EXCLUDED.price_output_1m,
                    context_length = EXCLUDED.context_length,
                    is_text_only = TRUE, updated_at = NOW()
                 RETURNING (xmax = 0) AS pridany`,
                [m.id, (m.name || m.id).slice(0, 200), m.id.endsWith(':free'),
                 cena(m.pricing?.prompt), cena(m.pricing?.completion),
                 m.context_length ?? null]);

            if (r.rows[0].pridany) pridanych++; else aktualiz++;
            videne.push(m.id);
        }

        // Modely, ktore z cennika zmizli, sa nemazu (evaluacie na ne odkazuju),
        // len sa vyradia z automatickeho vyberu.
        if (videne.length) {
            const u = await client.query(
                `UPDATE job.ai_models
                    SET is_enabled = FALSE,
                        unavailable_reason = 'Model uz nie je v cenniku OpenRoutera',
                        updated_at = NOW()
                  WHERE provider = 'openrouter' AND is_enabled
                    AND unavailable_reason IS NULL
                    AND model_id <> ALL($1)`, [videne]);
            if (u.rowCount) console.log(`Vyradenych (uz nie su v cenniku): ${u.rowCount}`);
        }

        console.log(`Hotovo: ${pridanych} novych, ${aktualiz} aktualizovanych, spolu ${videne.length}`);

        const s = await client.query(
            `SELECT count(*) FILTER (WHERE is_free AND is_enabled) AS free,
                    count(*) FILTER (WHERE NOT is_free AND is_enabled) AS platene,
                    count(*) FILTER (WHERE is_free AND is_enabled
                                       AND context_length >= 16000) AS free_velky_kontext
               FROM job.ai_models WHERE unavailable_reason IS NULL`);
        console.table(s.rows);
    } finally {
        await client.end();
    }
})().catch(e => { console.error('CHYBA:', e.message); process.exit(1); });
