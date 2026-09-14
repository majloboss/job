#!/usr/bin/env node
// Zmaze vsetky inzeraty a naviazane data, aby sa dali stiahnut odznova.
//
// CO SA ZMAZE (kaskadou z job.offers):
//   job.offers             samotne inzeraty
//   job.offer_content      ulozene HTML a preklady
//   job.ai_evaluations     vysledky tazenia modelom
//   job.offer_locations    miesta vykonu prace
//   job.offer_languages    jazykove poziadavky
//   job.offer_tags         stitky
//   job.offer_history      historia zmien
//   job.scrape_run_offers  vazba na behy zberu
//   job.user_offer_match   vypocitane vhodnosti
//   job.user_offer_status  rucne akcie pouzivatela
//
// CO ZOSTANE:
//   job.test_vysledky      vysledky testu modelov — su to porovnania
//                          modelov, nie zbierane data
//   job.test_behy          behy testu
//   job.ai_lab_runs        laboratorium (odkaz na offer sa nastavi na NULL)
//   job.ai_poradie         poradie modelov
//   job.sources            ciselnik portalov
//
// Historia behov zberu (job.scrape_runs) sa maze tiez — bez inzeratov
// nema vypovednu hodnotu a v prehlade by matila.
//
// Pouzitie:
//   node tools/vycisti_inzeraty.cjs          ukaze, co by sa zmazalo
//   node tools/vycisti_inzeraty.cjs --ano    naozaj zmaze
const fs = require('fs');
const path = require('path');
const { Client } = require('pg');

const conf = fs.readFileSync(path.join(__dirname, '../api/config/db.php'), 'utf8');
const val = (k) => {
    const m = conf.match(new RegExp("define\\('" + k + "'\\s*,\\s*'([^']*)'"));
    if (!m) throw new Error('V db.php chyba ' + k);
    return m[1];
};

const POTVRDENE = process.argv.includes('--ano');

(async () => {
    const c = new Client({
        host: val('DB_HOST'), port: Number(val('DB_PORT')), database: val('DB_NAME'),
        user: val('DB_USER'), password: val('DB_PASS'), ssl: { rejectUnauthorized: false },
    });
    await c.connect();

    try {
        const stav = (await c.query(`
            SELECT (SELECT COUNT(*) FROM job.offers)            AS inzeratov,
                   (SELECT COUNT(*) FROM job.offer_content)     AS obsahu,
                   (SELECT COUNT(*) FROM job.ai_evaluations)    AS evaluacii,
                   (SELECT COUNT(*) FROM job.scrape_runs)       AS behov,
                   (SELECT COUNT(*) FROM job.test_vysledky)     AS testov`)).rows[0];

        console.log('Zmaže sa:');
        console.log('  inzerátov:        %s', stav.inzeratov);
        console.log('  uloženého HTML:   %s', stav.obsahu);
        console.log('  výsledkov ťaženia:%s', stav.evaluacii);
        console.log('  behov zberu:      %s', stav.behov);
        console.log('\nZostane:');
        console.log('  výsledkov testu modelov: %s', stav.testov);

        if (!POTVRDENE) {
            console.log('\nToto bol len náhľad. Naozaj zmazať:');
            console.log('  node tools/vycisti_inzeraty.cjs --ano');
            return;
        }

        // Vsetko naraz: pri ciastocnom zmazani by zostali inzeraty bez
        // obsahu alebo behy bez inzeratov a prehlad by klamal.
        await c.query('BEGIN');
        // ai_evaluations maju kaskadu cez offer_id, ale riadky z laboratoria
        // maju offer_id NULL — tie by zostali. Mazu sa len tie od zberu.
        await c.query("DELETE FROM job.ai_evaluations WHERE ucel = 'parse'");
        await c.query('DELETE FROM job.offers');
        await c.query('DELETE FROM job.scrape_runs');
        await c.query('COMMIT');

        const po = (await c.query(`
            SELECT (SELECT COUNT(*) FROM job.offers)        AS inzeratov,
                   (SELECT COUNT(*) FROM job.offer_content) AS obsahu,
                   (SELECT COUNT(*) FROM job.test_vysledky) AS testov`)).rows[0];

        console.log('\nHOTOVO. Zostalo: %s inzerátov, %s HTML, %s výsledkov testu',
                    po.inzeratov, po.obsahu, po.testov);
        console.log('\nĎalší krok — stiahnuť odznova:');
        console.log('  python scraper/zber_vsetky.py --limit 50');
    } finally {
        await c.end();
    }
})().catch(e => { console.error('CHYBA:', e.message); process.exit(1); });
