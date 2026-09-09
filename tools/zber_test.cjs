#!/usr/bin/env node
// Vytazenie udajov z ulozenych inzeratov — to iste, co api/cron/zber.php,
// ale v Node, aby sa dalo spustit lokalne (PHP tu nie je nainstalovane).
//
// Na produkcii bezi PHP verzia z cronu. Tento nastroj sluzi na overenie
// retazca a na rucne dotazenie inzeratov z prikazoveho riadka.
//
// Pouzitie:
//   node tools/zber_test.cjs [limit]
const fs = require('fs');
const path = require('path');
const { Client } = require('pg');

const conf = f => fs.readFileSync(path.join(__dirname, '..', f), 'utf8');
const val = (t, k) => {
    const m = t.match(new RegExp("define\\('" + k + "'\\s*,\\s*'([^']*)'"));
    if (!m) throw new Error('Chyba ' + k);
    return m[1];
};

const dbc = conf('api/config/db.php');
const KEY = val(conf('api/config/openrouter.php'), 'OPENROUTER_KEY');
const LIMIT = Number(process.argv[2] || 5);

(async () => {
    const c = new Client({
        host: val(dbc, 'DB_HOST'), port: Number(val(dbc, 'DB_PORT')),
        database: val(dbc, 'DB_NAME'), user: val(dbc, 'DB_USER'),
        password: val(dbc, 'DB_PASS'), ssl: { rejectUnauthorized: false },
    });
    await c.connect();

    try {
        const prompt = (await c.query(
            "SELECT id, template FROM job.ai_prompts WHERE code='offer_parse' AND is_active LIMIT 1"
        )).rows[0];

        // Model z poradia; ked poradie nie je nastavene, najlepsi bezplatny.
        const mr = await c.query(
            `SELECT m.id, m.model_id, m.price_input_1m, m.price_output_1m
               FROM job.ai_models m
               LEFT JOIN job.ai_poradie p ON p.model_id = m.id AND p.ucel = 'parse'
              WHERE m.is_enabled AND m.unavailable_reason IS NULL AND m.is_text_only
                AND (m.context_length IS NULL OR m.context_length >= 16000)
                AND m.is_free
              ORDER BY p.poradie NULLS LAST, COALESCE(m.agree_rate,-1) DESC, m.model_id
              LIMIT 1`);
        if (!mr.rows.length) throw new Error('V ciselniku nie je pouzitelny model');
        const model = mr.rows[0];
        console.log('Model:', model.model_id);

        const oz = await c.query(
            `SELECT o.id, o.external_id, o.source_id, c.text_full
               FROM job.offers o
               JOIN job.offer_content c ON c.offer_id = o.id AND c.is_original
              WHERE o.detail_fetched_at IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM job.ai_evaluations e
                                 WHERE e.offer_id = o.id AND e.ucel='parse' AND e.status='ok')
              ORDER BY o.created_at LIMIT $1`, [LIMIT]);

        if (!oz.rows.length) { console.log('Niet co vytazit.'); return; }
        console.log('Na vytazenie:', oz.rows.length, 'inzeratov\n');

        let ok = 0, chyb = 0, cenaSpolu = 0, tokSpolu = 0;

        for (const o of oz.rows) {
            process.stdout.write('  ' + o.external_id + ' … ');
            const t0 = Date.now();
            let stav = 'ok', chyba = null, data = null, usage = null;

            try {
                const res = await fetch('https://openrouter.ai/api/v1/chat/completions', {
                    method: 'POST',
                    headers: { Authorization: 'Bearer ' + KEY, 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        model: model.model_id,
                        messages: [{ role: 'user',
                                     content: prompt.template.replace('{offer_text}',
                                              (o.text_full || '').slice(0, 14000)) }],
                        temperature: 0, max_tokens: 4000,
                        reasoning: { enabled: false },
                    }),
                });
                const j = await res.json();
                usage = j.usage;
                if (!res.ok) { stav = 'failed'; chyba = j.error?.message || ('HTTP ' + res.status); }
                else {
                    const txt = j.choices?.[0]?.message?.content ?? '';
                    const m = txt.match(/\{[\s\S]*\}/);
                    if (!m) { stav = 'invalid_json'; chyba = 'Odpoved nie je platny JSON'; }
                    else {
                        try { data = JSON.parse(m[0]); }
                        catch (e) { stav = 'invalid_json'; chyba = e.message; }
                    }
                }
            } catch (e) { stav = 'failed'; chyba = e.message; }

            const ms = Date.now() - t0;
            // Cena podla cennika v case volania — ako ai_cena_volania() v PHP.
            const cena = ((usage?.prompt_tokens || 0) / 1e6 * Number(model.price_input_1m || 0))
                       + ((usage?.completion_tokens || 0) / 1e6 * Number(model.price_output_1m || 0));
            cenaSpolu += cena;
            tokSpolu += usage?.total_tokens || 0;

            await c.query(
                `INSERT INTO job.ai_evaluations
                    (offer_id, model_id, prompt_id, ucel, source_id, call_type,
                     summary, parsed, status, error,
                     prompt_tokens, completion_tokens, total_tokens, cost_usd, took_ms)
                 VALUES ($1,$2,$3,'parse',$4,'live',$5,$6,$7,$8,$9,$10,$11,$12,$13)`,
                [o.id, model.model_id, prompt.id, o.source_id,
                 data?.summary_sk ? String(data.summary_sk).slice(0, 1000) : null,
                 data ? JSON.stringify(data) : null, stav, chyba,
                 usage?.prompt_tokens ?? null, usage?.completion_tokens ?? null,
                 usage?.total_tokens ?? null, cena, ms]);

            if (stav !== 'ok' || !data?.summary_sk) {
                chyb++;
                console.log('CHYBA: ' + String(chyba || 'neuplne').slice(0, 60));
                continue;
            }

            const pole = v => (Array.isArray(v) && v.length)
                ? v.filter(x => typeof x === 'string' && x.trim()).map(x => x.trim().slice(0, 100))
                : null;

            await c.query(
                `UPDATE job.offers SET
                    profession_raw = COALESCE($1, profession_raw),
                    industry = COALESCE($2, industry),
                    summary_sk = COALESCE($3, summary_sk),
                    orig_lang = COALESCE($4, orig_lang),
                    salary_min = COALESCE($5, salary_min),
                    salary_max = COALESCE($6, salary_max),
                    salary_period = COALESCE($7, salary_period),
                    employment_type = COALESCE($8, employment_type),
                    employment_types = COALESCE($9::TEXT[], employment_types),
                    remote_type = COALESCE($10, remote_type),
                    seniority = COALESCE($11, seniority),
                    is_agency_offer = COALESCE($12, is_agency_offer),
                    keywords = COALESCE($13::TEXT[], keywords),
                    technologies = COALESCE($14::TEXT[], technologies),
                    locations_raw = COALESCE($15::TEXT[], locations_raw),
                    updated_at = NOW()
                  WHERE id = $16`,
                [data.profession ?? null, data.industry ?? null,
                 String(data.summary_sk).slice(0, 2000), data.orig_lang ?? null,
                 Number.isFinite(+data.salary_min) ? +data.salary_min : null,
                 Number.isFinite(+data.salary_max) ? +data.salary_max : null,
                 data.salary_period ?? null, data.employment_type ?? null,
                 pole(data.employment_types), data.remote_type ?? null,
                 data.seniority ?? null,
                 typeof data.is_agency === 'boolean' ? data.is_agency : null,
                 pole(data.keywords), pole(data.technologies), pole(data.locations),
                 o.id]);

            // Preklad — samostatny riadok, original sa nikdy neprepisuje.
            if (data.text_sk) {
                await c.query(
                    `INSERT INTO job.offer_content
                        (offer_id, lang, is_original, text_full, translated_by, translated_at)
                     VALUES ($1,'sk',FALSE,$2,'model',NOW())
                     ON CONFLICT (offer_id, lang) DO UPDATE
                        SET text_full = EXCLUDED.text_full, translated_at = NOW()`,
                    [o.id, data.text_sk]);
            }

            ok++;
            console.log(`OK "${String(data.profession).slice(0, 26)}" `
                      + `${usage?.total_tokens ?? '?'} tok., ${(ms / 1000).toFixed(1)}s, `
                      + `$${cena.toFixed(6)}`);
        }

        console.log(`\nHotovo: ${ok} vytazenych, ${chyb} chyb, ${tokSpolu} tokenov, `
                  + `$${cenaSpolu.toFixed(6)}`);
    } finally {
        await c.end();
    }
})().catch(e => { console.error('CHYBA:', e.message); process.exit(1); });
