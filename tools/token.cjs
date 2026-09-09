#!/usr/bin/env node
// Vygeneruje JWT pre volanie API z prikazoveho riadka.
//
// Sluzi na testovanie endpointov bez prihlasovania cez prehliadac.
// Podpisuje sa rovnakym JWT_SECRET ako na serveri (api/config/db.php,
// mimo gitu), takze token plati aj v produkcii — narabaj s nim ako
// s heslom a nikam ho nezapisuj.
//
// Pouzitie:
//   node tools/token.cjs            token pre admina (user_id 1)
//   node tools/token.cjs 2          token pre ineho pouzivatela
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { Client } = require('pg');

const conf = fs.readFileSync(path.join(__dirname, '../api/config/db.php'), 'utf8');
const val = k => {
    const m = conf.match(new RegExp("define\\('" + k + "'\\s*,\\s*'([^']*)'"));
    if (!m) throw new Error('V db.php chyba ' + k);
    return m[1];
};

const b64 = o => Buffer.from(JSON.stringify(o)).toString('base64url');
const USER_ID = Number(process.argv[2] || 1);

(async () => {
    const c = new Client({
        host: val('DB_HOST'), port: Number(val('DB_PORT')), database: val('DB_NAME'),
        user: val('DB_USER'), password: val('DB_PASS'), ssl: { rejectUnauthorized: false },
    });
    await c.connect();
    const r = await c.query(
        'SELECT id, username, role, token_version FROM admin.users WHERE id = $1', [USER_ID]);
    await c.end();
    if (!r.rows.length) throw new Error('Pouzivatel ' + USER_ID + ' neexistuje');
    const u = r.rows[0];

    // Payload musi sediet s jwt_verify() a require_auth() v api/helpers/auth.php:
    // bez spravneho 'tv' (token_version) server token odmietne.
    const payload = {
        user_id: u.id,
        username: u.username,
        role: u.role,
        tv: u.token_version,
        exp: Math.floor(Date.now() / 1000) + 3600,   // hodina staci na testovanie
    };

    const hlavicka = b64({ alg: 'HS256', typ: 'JWT' });
    const telo = b64(payload);
    const podpis = crypto.createHmac('sha256', val('JWT_SECRET'))
                         .update(hlavicka + '.' + telo).digest('base64url');

    console.log(hlavicka + '.' + telo + '.' + podpis);
})().catch(e => { console.error('CHYBA:', e.message); process.exit(1); });
