#!/usr/bin/env node
// Vykona beh testu modelov — to iste, co api/cron/test_modelov.php, ale
// v Node, aby sa dal spustit lokalne (PHP tu nie je nainstalovane).
//
// Na produkcii bezi PHP verzia spustena z obrazovky. Tento nastroj sluzi
// na overenie retazca a na rucne dobehnutie testu z prikazoveho riadka.
//
// Pouzitie: node tools/test_modelov_run.cjs <beh_id>
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
const BEH = Number(process.argv[2] || 0);
if (!BEH) { console.error('Pouzitie: node tools/test_modelov_run.cjs <beh_id>'); process.exit(1); }

const pauza = ms => new Promise(r => setTimeout(r, ms));

(async () => {
    const c = new Client({
        host: val(dbc, 'DB_HOST'), port: Number(val(dbc, 'DB_PORT')),
        database: val(dbc, 'DB_NAME'), user: val(dbc, 'DB_USER'),
        password: val(dbc, 'DB_PASS'), ssl: { rejectUnauthorized: false },
    });
    await c.connect();

    try {
        const beh = (await c.query('SELECT * FROM job.test_behy WHERE id=$1', [BEH])).rows[0];
        if (!beh) throw new Error('Beh neexistuje');

        const prompty = {};
        for (const kod of ['test_parse', 'test_vhodnost']) {
            const r = await c.query(
                'SELECT id, template FROM job.ai_prompts WHERE code=$1 AND is_active LIMIT 1', [kod]);
            if (!r.rows.length) throw new Error('Chyba prompt ' + kod);
            prompty[kod === 'test_parse' ? 'parse' : 'vhodnost'] = r.rows[0];
        }

        // Modely od najlacnejsich: bezplatne, potom platene po strop.
        const modely = (await c.query(
            `SELECT id, model_id, is_free, price_input_1m, price_output_1m
               FROM job.ai_models
              WHERE is_enabled AND unavailable_reason IS NULL AND is_text_only
                AND (context_length IS NULL OR context_length >= 16000)
                AND (is_free OR (price_input_1m IS NOT NULL AND price_output_1m IS NOT NULL
                                 AND price_input_1m + price_output_1m > 0
                                 AND price_input_1m + price_output_1m <= $1))
              ORDER BY is_free DESC,
                       COALESCE(price_input_1m,0) + COALESCE(price_output_1m,0) ASC, model_id
              LIMIT $2`, [beh.cenovy_strop_1m, beh.max_modelov])).rows;

        console.log(`Beh #${BEH}: ${modely.length} modelov, rozpočet $${beh.rozpocet_usd}`);
        await c.query('UPDATE job.test_behy SET modelov_spolu=$1 WHERE id=$2', [modely.length, BEH]);

        let cenaSpolu = Number(beh.cena_usd), stop = false;

        for (const cislo of [1, 2]) {
            if (stop) break;
            const text = beh['text' + cislo];
            if (!text) continue;

            for (const uloha of ['parse', 'vhodnost']) {
                if (stop) break;
                console.log(`\n--- Inzerát ${cislo}, úloha ${uloha} ---`);

                for (const model of modely) {
                    if (cenaSpolu >= Number(beh.rozpocet_usd)) {
                        stop = true;
                        const dovod = `Dosiahnutý rozpočet $${cenaSpolu.toFixed(4)}`;
                        await c.query(`UPDATE job.test_behy SET status='stopped_budget',
                                       finished_at=NOW(), zastavene_dovod=$1 WHERE id=$2`,
                                      [dovod, BEH]);
                        console.log('\nZASTAVENÉ: ' + dovod);
                        break;
                    }

                    const st = (await c.query('SELECT status FROM job.test_behy WHERE id=$1',
                                              [BEH])).rows[0].status;
                    if (st === 'cancelled') { stop = true; console.log('\nZRUŠENÉ.'); break; }

                    const v = await zavolaj(beh, cislo, uloha, model, prompty[uloha]);
                    cenaSpolu += v.cena;
                    await zapis(c, BEH, cislo, uloha, model, v);

                    await c.query(
                        `UPDATE job.test_behy SET volani_spolu=volani_spolu+1,
                            volani_ok=volani_ok+$1, tokenov_spolu=tokenov_spolu+$2,
                            cena_usd=cena_usd+$3, heartbeat_at=NOW() WHERE id=$4`,
                        [v.ok ? 1 : 0, v.tokenov, v.cena, BEH]);

                    console.log(`  ${model.model_id.padEnd(44).slice(0, 44)} `
                              + `${(v.ok ? 'OK' : 'chyba').padEnd(6)} `
                              + `${(v.ms / 1000).toFixed(1)}s ${String(v.tokenov).padStart(6)} tok. `
                              + `$${v.cena.toFixed(6)}  ${String(v.popis || v.chyba || '').slice(0, 38)}`);

                    await pauza(1200);   // rozostup, aby sa limit za minútu nevyčerpal naraz
                }
            }
        }

        if (!stop) {
            await c.query(`UPDATE job.test_behy SET status='done', finished_at=NOW() WHERE id=$1`, [BEH]);
        }
        console.log(`\nHotovo: $${cenaSpolu.toFixed(6)}`);
    } finally {
        await c.end();
    }
})().catch(e => { console.error('CHYBA:', e.message); process.exit(1); });


async function zavolaj(beh, cislo, uloha, model, prompt) {
    const text = String(beh['text' + cislo] || '').slice(0, 14000);
    let vstup, maxTokens;

    if (uloha === 'parse') {
        vstup = prompt.template.replace('{offer_text}', text);
        maxTokens = 8000;      // odpoveď obsahuje aj HTML a preklad
    } else {
        vstup = prompt.template
            .replace('{offer_text}', text)
            .replace('{cv_text}', String(beh.cv_text || '').slice(0, 6000) || '(životopis nie je nahraný)')
            .replace('{prefs_text}', String(beh.prefs_text || '') || '(preferencie nie sú vyplnené)');
        maxTokens = 2000;
    }

    const t0 = Date.now();
    let stav = 'ok', chyba = null, data = null, usage = null, obsah = '';

    for (let pokus = 1; pokus <= 3; pokus++) {
        try {
            const res = await fetch('https://openrouter.ai/api/v1/chat/completions', {
                method: 'POST',
                headers: { Authorization: 'Bearer ' + KEY, 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    model: model.model_id,
                    messages: [{ role: 'user', content: vstup }],
                    temperature: 0, max_tokens: maxTokens,
                    reasoning: { enabled: false },
                }),
            });
            const j = await res.json();
            usage = j.usage;
            if (!res.ok) { stav = 'failed'; chyba = j.error?.message || ('HTTP ' + res.status); }
            else {
                obsah = j.choices?.[0]?.message?.content ?? '';
                const m = obsah.match(/\{[\s\S]*\}/);
                if (!m) { stav = 'invalid_json'; chyba = 'Odpoveď nie je platný JSON'; }
                else {
                    try { data = JSON.parse(m[0]); stav = 'ok'; chyba = null; }
                    catch (e) { stav = 'invalid_json'; chyba = e.message; }
                }
            }
        } catch (e) { stav = 'failed'; chyba = e.message; }

        // Limit za minútu nie je chyba modelu — počkať a skúsiť znova.
        if (chyba && /rate limit|429|per-min/i.test(chyba) && pokus < 3) {
            await pauza(pokus * 20000);
            continue;
        }
        break;
    }

    const cena = ((usage?.prompt_tokens || 0) / 1e6 * Number(model.price_input_1m || 0))
               + ((usage?.completion_tokens || 0) / 1e6 * Number(model.price_output_1m || 0));

    const d = data || {};
    const ok = stav === 'ok' && (uloha === 'parse'
        ? !!(d.nazov && d.sumar)
        : (d.skore !== undefined && d.skore !== null && !isNaN(Number(d.skore))));

    if (!ok && !chyba) {
        chyba = uloha === 'parse' ? 'Model nevrátil názov alebo súhrn' : 'Model nevrátil skóre';
    }

    // Ked odpoved prisla, ale chyba v nej podstatny udaj, status NESMIE
    // zostat 'ok' — v tabulke by taky riadok vyzeral ako uspesny, hoci
    // model nevratil to hlavne, na co sa testuje.
    return {
        ok, data: d, status: ok ? 'ok' : (stav === 'ok' ? 'neuplne' : stav), chyba,
        surova: ok ? null : obsah.slice(0, 2000),
        tokenov: usage?.total_tokens || 0,
        prompt_tokens: usage?.prompt_tokens ?? null,
        completion_tokens: usage?.completion_tokens ?? null,
        cena, ms: Date.now() - t0,
        popis: uloha === 'parse' ? d.nazov : (d.skore != null ? 'skóre ' + d.skore : null),
    };
}


async function zapis(c, behId, cislo, uloha, model, v) {
    const d = v.data;
    const txt = (x, n) => (typeof x === 'string' && x.trim()) ? x.trim().slice(0, n) : null;
    const num = x => (x !== null && x !== undefined && !isNaN(Number(x))) ? Number(x) : null;
    const pole = x => (Array.isArray(x) && x.length)
        ? x.filter(y => typeof y === 'string' && y.trim()).map(y => y.trim().slice(0, 100)) : null;
    const riadky = x => (Array.isArray(x) && x.length)
        ? x.filter(y => typeof y === 'string').join('\n').slice(0, 2000) : null;

    // Model môže vrátiť dátum v nezmyselnom tvare a celý zápis by zlyhal.
    const datum = (typeof d.datum_zverejnenia === 'string'
                   && /^\d{4}-\d{2}-\d{2}$/.test(d.datum_zverejnenia)) ? d.datum_zverejnenia : null;
    const skore = (d.skore != null && !isNaN(Number(d.skore)))
        ? Math.max(0, Math.min(100, Math.round(Number(d.skore)))) : null;

    await c.query(
        `INSERT INTO job.test_vysledky
            (beh_id, inzerat, uloha, model_id, model_db_id, je_free, cena_1m,
             nazov, firma, datum_zverejnenia, datum_zverejnenia_text, sumar,
             html_original, html_sk, orig_lang,
             mzda_text, mzda_min, mzda_max, mzda_mena, mzda_obdobie,
             nastup, uvazok, uvazky, mesto, lokalita_zvysok,
             skore, zaradenie, hodnotenie, pre_argumenty, proti_argumenty,
             status, chyba, surova_odpoved,
             prompt_tokens, completion_tokens, total_tokens, cena_usd, trvanie_ms)
         VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17,$18,$19,$20,
                 $21,$22,$23::TEXT[],$24,$25,$26,$27,$28,$29,$30,$31,$32,$33,$34,$35,$36,$37,$38)
         ON CONFLICT (beh_id, inzerat, uloha, model_id) DO NOTHING`,
        [behId, cislo, uloha, model.model_id.slice(0, 150), model.id,
         model.is_free === true, model.is_free === true ? 0
            : Number(model.price_input_1m || 0) + Number(model.price_output_1m || 0),
         txt(d.nazov, 300), txt(d.firma, 255), datum, txt(d.datum_zverejnenia_text, 100),
         txt(d.sumar, 4000), txt(d.html_original, 100000), txt(d.html_sk, 100000),
         txt(d.orig_lang, 5),
         txt(d.mzda_text, 200), num(d.mzda_min), num(d.mzda_max),
         txt(d.mzda_mena, 3), txt(d.mzda_obdobie, 10),
         txt(d.nastup, 100), txt(d.uvazok, 30), pole(d.uvazky),
         txt(d.mesto, 120), txt(d.lokalita_zvysok, 2000),
         skore, txt(d.zaradenie, 20), txt(d.hodnotenie, 4000),
         riadky(d.pre), riadky(d.proti),
         v.status, v.chyba, v.surova,
         v.prompt_tokens, v.completion_tokens, v.tokenov, v.cena, v.ms]);
}
