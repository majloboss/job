#!/usr/bin/env node
// Vygeneruje sadu favikon z web/public/favicon.svg.
//
// Pouzitie:  node tools/gen_favicon.cjs
// Potrebuje: npm install sharp --no-save
//
// Vyrobi:
//   favicon-16.png, favicon-32.png  — klasicka favicon do zalozky
//   apple-touch-icon.png (180)      — ikona po pridani na plochu iPhonu
//   icon-192.png, icon-512.png      — Android / PWA
//   nahlad_favicon.png              — vsetky velkosti vedla seba na kontrolu
//
// SVG (favicon.svg) zostava hlavnym zdrojom — moderne prehliadace ho pouziju
// priamo a je ostre v kazdej velkosti.

const fs = require('fs');
const path = require('path');

let sharp;
try {
    sharp = require('sharp');
} catch {
    console.error('Chyba kniznica sharp:\n    npm install sharp --no-save');
    process.exit(1);
}

const KOREN = path.join(__dirname, '..');
const SVG = path.join(KOREN, 'web', 'public', 'favicon.svg');
const VYSTUP = path.join(KOREN, 'web', 'public');

if (!fs.existsSync(SVG)) {
    console.error('Chyba subor ' + SVG);
    process.exit(1);
}

const subory = [
    ['favicon-16.png', 16],
    ['favicon-32.png', 32],
    ['apple-touch-icon.png', 180],
    ['icon-192.png', 192],
    ['icon-512.png', 512],
];

(async () => {
    const svg = fs.readFileSync(SVG);

    for (const [nazov, velkost] of subory) {
        // Ikony na plochu maju mat pozadie — priehladna kresba by na tmavom
        // podklade zmizla. Favicony do zalozky nechavame priehladne.
        const naPlochu = velkost >= 180;

        let obraz = sharp(svg, { density: 384 }).resize(velkost, velkost, {
            fit: 'contain',
            background: naPlochu ? '#ffffff' : { r: 0, g: 0, b: 0, alpha: 0 },
        });
        if (naPlochu) obraz = obraz.flatten({ background: '#ffffff' });

        await obraz.png().toFile(path.join(VYSTUP, nazov));
        console.log('  ' + nazov.padEnd(24) + velkost + '×' + velkost);
    }

    // Nahlad: vsetky velkosti na jednom obrazku, aby sa dalo posudit,
    // ci je kresba citatelna aj pri 16 px.
    const nahlad = await sharp({
        create: { width: 480, height: 220, channels: 4,
                  background: { r: 255, g: 255, b: 255, alpha: 1 } },
    }).png().toBuffer();

    const vrstvy = [];
    let x = 20;
    for (const v of [16, 32, 64, 128]) {
        vrstvy.push({
            input: await sharp(svg, { density: 384 }).resize(v, v).png().toBuffer(),
            left: x, top: 40 + (128 - v),
        });
        x += v + 24;
    }
    // nahlad je len na vizualnu kontrolu, do repa nepatri (.gitignore)
    await sharp(nahlad).composite(vrstvy).toFile(path.join(KOREN, 'nahlad_favicon.png'));
    console.log('\n  nahlad_favicon.png       16 / 32 / 64 / 128 px vedla seba');
})();
