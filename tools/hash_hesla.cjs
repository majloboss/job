#!/usr/bin/env node
// Vygeneruje bcrypt hash hesla pre stlpec admin.users.password.
//
// Pouzitie:
//   node tools/hash_hesla.cjs "mojeHeslo"
//
// Vysledok skopiruj do api/migrations/003_uzivatelia.sql namiesto ZMEN_MA_*.
//
// Hash zacina na $2y$ — presne to ocakava PHP password_verify(), ktorym
// aplikacia overuje prihlasenie. Kazde spustenie da iny hash (bcrypt do neho
// zamiesa nahodnu sol), to je v poriadku — oba budu fungovat.
//
// Kniznica: npm install bcryptjs --no-save

let bcrypt;
try {
    bcrypt = require('bcryptjs');
} catch {
    console.error('Chyba kniznica bcryptjs. Nainstaluj ju:\n');
    console.error('    npm install bcryptjs --no-save\n');
    process.exit(1);
}

const heslo = process.argv[2];

if (!heslo) {
    console.error('Pouzitie: node tools/hash_hesla.cjs "mojeHeslo"');
    process.exit(1);
}
if (heslo.length < 8) {
    console.error('Heslo ma mat aspon 8 znakov.');
    process.exit(1);
}

// 10 kol = rovnaka narocnost, aku pouziva PHP PASSWORD_BCRYPT
const hash = bcrypt.hashSync(heslo, 10);

// PHP generuje prefix $2y$, bcryptjs $2a$ alebo $2b$. Vsetky tri varianty su
// navzajom zamenitelne (lisia sa len oznacenim verzie, nie algoritmom) a PHP
// password_verify() ich overi vsetky. Kvoli jednotnosti s uctami vytvorenymi
// cez aplikaciu prepiseme prefix na $2y$.
const hashPhp = hash.replace(/^\$2[ab]\$/, '$2y$');

console.log('\nHash pre SQL skript:\n');
console.log('    ' + hashPhp + '\n');

// Overenie, ze hash naozaj sedi na zadane heslo.
const sedi = bcrypt.compareSync(heslo, hashPhp);
console.log(sedi ? 'Overene: hash sedi na zadane heslo.\n'
                 : '!! Overenie zlyhalo, hash nepouzivaj.\n');
