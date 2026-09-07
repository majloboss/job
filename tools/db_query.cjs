#!/usr/bin/env node
// Spusti SQL dopyt na databazu JOB a vypise vysledok.
// Pripojenie berie z api/config/db.php (mimo gitu).
// Pouzitie: node tools/db_query.cjs "SELECT ..."   alebo   -f subor.sql
const fs = require('fs');
const path = require('path');
const { Client } = require('pg');

const args = process.argv.slice(2);
const sql = args[0] === '-f' ? fs.readFileSync(args[1], 'utf8') : args.join(' ');
if (!sql) { console.error('Pouzitie: node tools/db_query.cjs "<sql>" | -f <subor.sql>'); process.exit(1); }

const conf = fs.readFileSync(path.join(__dirname, '../api/config/db.php'), 'utf8');
const val = key => {
    const m = conf.match(new RegExp("define\\('" + key + "'\\s*,\\s*'([^']*)'"));
    if (!m) throw new Error('V db.php chyba ' + key);
    return m[1];
};

(async () => {
    const client = new Client({
        host: val('DB_HOST'), port: Number(val('DB_PORT')), database: val('DB_NAME'),
        user: val('DB_USER'), password: val('DB_PASS'), ssl: { rejectUnauthorized: false },
    });
    await client.connect();
    try {
        const res = await client.query(sql);
        for (const r of Array.isArray(res) ? res : [res]) {
            if (r.rows && r.rows.length) console.table(r.rows);
            else console.log(r.command, r.rowCount ?? '');
        }
    } finally {
        await client.end();
    }
})().catch(e => { console.error('CHYBA:', e.message); process.exit(1); });
