#!/usr/bin/env node
// Otestuje tazenie udajov z inzeratu viacerymi modelmi a vyhodnoti zhodu.
//
// Robi to iste, co laboratorium v prehliadaci, ale z prikazoveho riadka —
// aby sa dalo overit, ci prompt a modely funguju, bez klikania.
//
// Pouzitie:
//   node tools/test_parse.cjs <url-inzeratu> [pocet-modelov]
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

const URL_INZERATU = process.argv[2];
const POCET = Number(process.argv[3] || 6);
if (!URL_INZERATU) {
    console.error('Pouzitie: node tools/test_parse.cjs <url-inzeratu> [pocet-modelov]');
    process.exit(1);
}

// HTML -> cisty text, rovnako ako or_html_to_text() v PHP.
function htmlNaText(html) {
    let s = html.replace(/<(script|style|noscript|svg|iframe)\b[^>]*>[\s\S]*?<\/\1>/gi, ' ');
    s = s.replace(/<(nav|footer|header)\b[^>]*>[\s\S]*?<\/\1>/gi, ' ');
    s = s.replace(/<br\s*\/?>|<\/(p|div|li|tr|h[1-6])>/gi, '\n');
    s = s.replace(/<[^>]+>/g, ' ');
    s = s.replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&').replace(/&lt;/g, '<')
         .replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'");
    s = s.replace(/[ \t ]+/g, ' ').replace(/\n\s*\n\s*\n+/g, '\n\n');
    return s.trim();
}

(async () => {
    const client = new Client({
        host: val(dbc, 'DB_HOST'), port: Number(val(dbc, 'DB_PORT')),
        database: val(dbc, 'DB_NAME'), user: val(dbc, 'DB_USER'),
        password: val(dbc, 'DB_PASS'), ssl: { rejectUnauthorized: false },
    });
    await client.connect();

    try {
        // --- prompt ---
        const p = await client.query(
            "SELECT id, template FROM job.ai_prompts WHERE code='offer_parse' AND is_active LIMIT 1");
        if (!p.rows.length) throw new Error('Chyba aktivny prompt offer_parse — spusti migraciu 005');
        const template = p.rows[0].template;

        // --- modely: bezplatne s dost velkym kontextom ---
        const m = await client.query(
            `SELECT model_id, name, context_length FROM job.ai_models
              WHERE is_free AND is_enabled AND unavailable_reason IS NULL AND is_text_only
                AND (context_length IS NULL OR context_length >= 16000)
              ORDER BY COALESCE(agree_rate,-1) DESC, context_length DESC NULLS LAST
              LIMIT $1`, [POCET]);
        console.log(`Modelov na test: ${m.rows.length}`);

        // --- stiahni inzerat ---
        console.log('Stahujem ' + URL_INZERATU);
        const r = await fetch(URL_INZERATU, {
            headers: { 'User-Agent': 'JobBot/1.0 (+https://job.fellow.sk)',
                       'Accept-Language': 'sk,cs,en;q=0.8' },
        });
        if (!r.ok) throw new Error('Inzerat sa nepodarilo stiahnut: HTTP ' + r.status);
        const html = await r.text();
        const text = htmlNaText(html).slice(0, 14000);
        console.log(`Text inzeratu: ${text.length} znakov\n`);

        const prompt = template.replace('{offer_text}', text);

        // --- volaj modely POSTUPNE (bezplatne maju limit za minutu) ---
        const vysledky = [];
        for (const model of m.rows) {
            process.stdout.write(`  ${model.model_id} … `);
            const t0 = Date.now();
            try {
                const res = await fetch('https://openrouter.ai/api/v1/chat/completions', {
                    method: 'POST',
                    headers: { Authorization: 'Bearer ' + KEY, 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        model: model.model_id,
                        messages: [{ role: 'user', content: prompt }],
                        temperature: 0, max_tokens: 4000,
                    }),
                });
                const ms = Date.now() - t0;
                const j = await res.json();

                if (!res.ok) {
                    console.log(`CHYBA: ${(j.error?.message || res.status).toString().slice(0, 70)}`);
                    vysledky.push({ model: model.model_id, ok: false });
                    continue;
                }

                const obsah = j.choices?.[0]?.message?.content ?? '';
                const zhoda = obsah.match(/\{[\s\S]*\}/);
                const data = zhoda ? JSON.parse(zhoda[0]) : null;

                if (!data?.title) {
                    console.log(`bez nazvu (${(ms/1000).toFixed(1)} s)`);
                    vysledky.push({ model: model.model_id, ok: false });
                    continue;
                }

                console.log(`OK "${data.title.slice(0, 40)}" ${(ms/1000).toFixed(1)} s`);
                vysledky.push({ model: model.model_id, ok: true, data, ms,
                                tokens: j.usage?.total_tokens });
            } catch (e) {
                console.log('CHYBA: ' + e.message.slice(0, 70));
                vysledky.push({ model: model.model_id, ok: false });
            }
        }

        // --- porovnanie kluc. poli ---
        const uspesne = vysledky.filter(v => v.ok);
        console.log(`\nUspesnych: ${uspesne.length} z ${vysledky.length}\n`);
        if (uspesne.length < 2) return;

        const POLIA = ['title', 'company_name', 'salary_min', 'salary_max', 'salary_period',
                       'employment_type', 'is_agency', 'industry', 'orig_lang',
                       'remote_type', 'locations', 'technologies'];

        for (const pole of POLIA) {
            const hlasy = {};
            for (const v of uspesne) {
                let h = v.data[pole];
                if (Array.isArray(h)) h = h.slice().sort().join('|');
                const k = (h === null || h === undefined || h === '') ? '(prazdne)' : String(h);
                hlasy[k] = (hlasy[k] || 0) + 1;
            }
            const zoradene = Object.entries(hlasy).sort((a, b) => b[1] - a[1]);
            const rozporne = zoradene.length > 1 ? '  ← ROZPOR' : '';
            console.log(`${pole.padEnd(16)} ${zoradene.map(([h, n]) =>
                `${String(h).slice(0, 42)} (${n}×)`).join('  |  ')}${rozporne}`);
        }

        // Kluc. slova sa zamerne neporovnavaju — kazdy model ich formuluje
        // inak, ale je uzitocne vidiet, co vratili.
        console.log('\nKluc. slova a technologie podla modelov:');
        for (const v of uspesne) {
            console.log(`  ${v.model.padEnd(45)} ${(v.data.keywords || []).slice(0, 8).join(', ')}`);
        }
    } finally {
        await client.end();
    }
})().catch(e => { console.error('CHYBA:', e.message); process.exit(1); });
