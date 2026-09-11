#!/usr/bin/env node
// Zalozi beh testu modelov z prikazoveho riadka — to iste, co robi
// obrazovka Test modelov pri kliku na "Spustiť test".
//
// Inzeraty sa stahuju RAZ a vsetky modely dostanu presne ten isty vstup;
// inak by sa porovnavali odpovede na rozne zadania.
//
// Pouzitie:
//   node tools/test_zaloz.cjs <url1> [url2] [max_modelov] [strop_1m] [rozpocet]
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
const [url1, url2, maxM, strop, rozp] = process.argv.slice(2);
if (!url1) {
    console.error('Pouzitie: node tools/test_zaloz.cjs <url1> [url2] [max] [strop] [rozpocet]');
    process.exit(1);
}

// HTML -> cisty text, rovnako ako or_html_to_text() v PHP.
function naText(h) {
    let s = h.replace(/<(script|style|noscript|svg|iframe)\b[^>]*>[\s\S]*?<\/\1>/gi, ' ')
             .replace(/<(nav|footer|header)\b[^>]*>[\s\S]*?<\/\1>/gi, ' ')
             .replace(/<br\s*\/?>|<\/(p|div|li|tr|h[1-6])>/gi, '\n')
             .replace(/<[^>]+>/g, ' ');
    s = s.replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&').replace(/&quot;/g, '"');
    return s.replace(/[ \t ]+/g, ' ').replace(/\n\s*\n\s*\n+/g, '\n\n').trim();
}

(async () => {
    const c = new Client({
        host: val(dbc, 'DB_HOST'), port: Number(val(dbc, 'DB_PORT')),
        database: val(dbc, 'DB_NAME'), user: val(dbc, 'DB_USER'),
        password: val(dbc, 'DB_PASS'), ssl: { rejectUnauthorized: false },
    });
    await c.connect();

    try {
        const texty = [], htmls = [], nazvy = [];
        for (const u of [url1, url2].filter(Boolean)) {
            const r = await fetch(u, {
                headers: { 'User-Agent': 'JobBot/1.0 (+https://job.fellow.sk)',
                           'Accept-Language': 'sk,cs;q=0.8' },
            });
            if (!r.ok) throw new Error(`${u} vratilo HTTP ${r.status}`);
            const h = await r.text();
            texty.push(naText(h));
            htmls.push(h);
            const m = h.match(/<h1[^>]*>([\s\S]*?)<\/h1>/);
            nazvy.push(m ? m[1].replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300) : null);
        }

        // Podklady pre ulohu 'vhodnost' — z Dokumentov a Preferencii.
        const cv = (await c.query(
            `SELECT extracted_text FROM job.user_documents
              WHERE user_id=1 AND doc_type='cv' AND extracted_text IS NOT NULL
              ORDER BY is_primary DESC LIMIT 1`)).rows[0]?.extracted_text || '';
        const prefs = (await c.query(
            'SELECT free_text FROM job.user_preferences WHERE user_id=1')).rows[0]?.free_text || '';

        const r = await c.query(
            `INSERT INTO job.test_behy
                (user_id, url1, url2, text1, text2, html1, html2, nazov1, nazov2,
                 cv_text, prefs_text, max_modelov, cenovy_strop_1m, rozpocet_usd)
             VALUES (1,$1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13) RETURNING id`,
            [url1, url2 || null, texty[0], texty[1] || null, htmls[0], htmls[1] || null,
             nazvy[0], nazvy[1] || null, cv, prefs,
             Number(maxM || 20), Number(strop || 1), Number(rozp || 1)]);

        console.log('beh_id: %d', r.rows[0].id);
        console.log('inzerát 1: %s (%d znakov)', nazvy[0] || '?', texty[0].length);
        if (url2) console.log('inzerát 2: %s (%d znakov)', nazvy[1] || '?', texty[1].length);
        console.log('CV: %d znakov, preferencie: %d znakov', cv.length, prefs.length);
        console.log('\nSpusti: node tools/test_modelov_run.cjs %d', r.rows[0].id);
    } finally {
        await c.end();
    }
})().catch(e => { console.error('CHYBA:', e.message); process.exit(1); });
