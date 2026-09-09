import { useEffect, useState, useCallback } from 'react';
import { api } from '../api';
import './Naklady.css';

// Prehlad nakladov na model.
//
// Odpoveda na jednu otazku: kolko ma stoji NAPLNENIE DB a kolko
// VYHODNOCOVANIE VHODNOSTI nad nou. Su to dve rozne veci:
//
//   Zber ('parse')     bezi RAZ NA INZERAT — rastie s poctom inzeratov
//   Vhodnost ('eval')  bezi raz na INZERAT A POUZIVATELA — rastie s oboma
//
// Preto sa nescitavaju do jedneho cisla. Pri jednom pouzivatelovi su
// pribliZne 1:1, pri desiatich je vhodnost desatnasobne drahsia.
//
// Testovacie volania z laboratoria sa do nakladov neratuju (call_type='live').

const UCELY = {
  parse: { text: 'Zber údajov',  popis: 'Vyťaženie údajov z inzerátu — raz na inzerát' },
  eval:  { text: 'Vhodnosť',     popis: 'Posúdenie pre používateľa — raz na inzerát a používateľa' },
};

const OBDOBIA = [
  { dni: 1,  text: 'dnes' },
  { dni: 7,  text: '7 dní' },
  { dni: 30, text: '30 dní' },
  { dni: 90, text: '90 dní' },
];

export default function Naklady() {
  const [dni, setDni]         = useState(7);
  const [ucel, setUcel]       = useState(null);   // null = len súhrny
  const [dta, setDta]         = useState(null);
  const [nacitava, setNacitava] = useState(true);
  const [chyba, setChyba]     = useState(null);
  const [radenie, setRadenie] = useState({ stlpec: 'vytazeny_o', smer: 'desc' });

  const nacitat = useCallback(async () => {
    setNacitava(true);
    setChyba(null);
    try {
      const q = new URLSearchParams({ dni });
      if (ucel) q.set('ucel', ucel);
      setDta(await api('/v1/admin/naklady?' + q));
    } catch (e) {
      setChyba(e.message);
    } finally {
      setNacitava(false);
    }
  }, [dni, ucel]);

  useEffect(() => { nacitat(); }, [nacitat]);

  const suhrn = dta?.suhrn ?? {};
  const stav  = dta?.stav;

  // Stlpce detailu sa lisia podla ucelu: pri vhodnosti pribuda pouzivatel
  // a skore, pri zbere cas stiahnutia zo stranky.
  const STLPCE = ucel === 'eval'
    ? [
        { kod: 'external_id', text: 'Inzerát' },
        { kod: 'username',    text: 'Používateľ' },
        { kod: 'vytazeny_o',  text: 'Posúdené' },
        { kod: 'model_id',    text: 'Model' },
        { kod: 'model_ms',    text: 'Trvanie', cislo: true },
        { kod: 'total_tokens',text: 'Tokeny',  cislo: true },
        { kod: 'cost_usd',    text: 'Cena',    cislo: true },
        { kod: 'score',       text: 'Skóre',   cislo: true },
      ]
    : [
        { kod: 'external_id', text: 'Inzerát' },
        { kod: 'stiahnuty_o', text: 'Stiahnuté' },
        { kod: 'fetch_ms',    text: 'Sťahovanie', cislo: true },
        { kod: 'vytazeny_o',  text: 'Vyťažené' },
        { kod: 'model_id',    text: 'Model' },
        { kod: 'model_ms',    text: 'Trvanie', cislo: true },
        { kod: 'total_tokens',text: 'Tokeny',  cislo: true },
        { kod: 'cost_usd',    text: 'Cena',    cislo: true },
        { kod: 'pokusov',     text: 'Pokusov', cislo: true },
      ];

  let detail = dta?.detail ?? [];
  if (radenie.stlpec) {
    const zn = radenie.smer === 'asc' ? 1 : -1;
    detail = [...detail].sort((a, b) => {
      const x = a[radenie.stlpec], y = b[radenie.stlpec];
      // Prázdne hodnoty vždy dole — nevyťažený inzerát nemá byť hore
      // len preto, že sa radí vzostupne.
      if (x == null && y == null) return 0;
      if (x == null) return 1;
      if (y == null) return -1;
      const cislo = !isNaN(Number(x)) && !isNaN(Number(y));
      return zn * (cislo ? Number(x) - Number(y) : String(x).localeCompare(String(y), 'sk'));
    });
  }

  function klikStlpec(kod) {
    setRadenie(r => r.stlpec === kod
      ? { stlpec: kod, smer: r.smer === 'asc' ? 'desc' : 'asc' }
      : { stlpec: kod, smer: 'desc' });
  }

  return (
    <div className="naklady">
      <h1>Náklady</h1>

      <div className="nak-obdobie">
        {OBDOBIA.map(o => (
          <button key={o.dni}
                  className={dni === o.dni ? 'aktivny' : ''}
                  onClick={() => setDni(o.dni)}>{o.text}</button>
        ))}
        <button className="nak-obnovit" onClick={nacitat} disabled={nacitava}>
          {nacitava ? 'Načítavam…' : 'Obnoviť'}
        </button>
      </div>

      {chyba && <p className="nak-chyba">{chyba}</p>}

      {/* --- dve karty vedla seba: zber vs. vhodnost --- */}
      <div className="nak-karty">
        {Object.entries(UCELY).map(([kod, u]) => {
          const s = suhrn[kod];
          return (
            <section key={kod}
                     className={'nak-karta' + (ucel === kod ? ' vybrana' : '')}
                     onClick={() => setUcel(ucel === kod ? null : kod)}>
              <h2>{u.text}</h2>
              <p className="nak-popis">{u.popis}</p>

              {!s ? (
                <p className="nak-prazdne">Zatiaľ žiadne volania.</p>
              ) : (
                <>
                  <div className="nak-cena">
                    ${Number(s.cena_spolu).toFixed(4)}
                    <span className="nak-cena-popis">za {dni} dní</span>
                  </div>
                  <dl className="nak-udaje">
                    <div><dt>Volaní</dt><dd>{s.volani}</dd></div>
                    <div><dt>Úspešných</dt>
                      <dd>{s.uspesnych}
                        {s.volani > 0 && (
                          <span className="nak-podiel">
                            {' '}({Math.round(100 * s.uspesnych / s.volani)} %)
                          </span>
                        )}
                      </dd></div>
                    <div><dt>Inzerátov</dt><dd>{s.inzeratov}</dd></div>
                    {kod === 'eval' && (
                      <div><dt>Používateľov</dt><dd>{s.pouzivatelov || '—'}</dd></div>
                    )}
                    <div><dt>Tokenov</dt>
                      <dd>{Number(s.tokenov).toLocaleString('sk-SK')}
                        <span className="nak-podiel"> ({s.tokenov_priemer} / volanie)</span>
                      </dd></div>
                    {/* Jediné číslo porovnateľné medzi účelmi — celková suma
                        sama o sebe nehovorí nič, lebo eval beží častejšie. */}
                    <div><dt>Cena / volanie</dt>
                      <dd>${Number(s.cena_na_volanie).toFixed(6)}</dd></div>
                    <div><dt>Priemerný čas</dt>
                      <dd>{(s.ms_priemer / 1000).toFixed(1)} s</dd></div>
                  </dl>
                  <p className="nak-rozklik">
                    {ucel === kod ? 'Klikni na skrytie detailu' : 'Klikni na detail'}
                  </p>
                </>
              )}
            </section>
          );
        })}
      </div>

      {/* --- stav naplnenia DB --- */}
      {stav && (
        <p className="nak-stav">
          V databáze <strong>{stav.inzeratov}</strong> aktívnych inzerátov,
          {' '}<strong>{stav.s_detailom}</strong> so stiahnutým detailom,
          {' '}<strong>{stav.vytazenych}</strong> vyťažených modelom.
          {stav.s_detailom > stav.vytazenych && (
            <span className="nak-caka">
              {' '}Čaká na vyťaženie: {stav.s_detailom - stav.vytazenych}
            </span>
          )}
        </p>
      )}

      {/* --- rozpad na modely --- */}
      {dta?.modely?.length > 0 && (
        <section>
          <h2>Podľa modelu</h2>
          <div className="nak-obal">
            <table className="nak-tabulka">
              <thead>
                <tr>
                  <th>Úloha</th><th>Model</th>
                  <th className="cislo">Volaní</th><th className="cislo">Úspešných</th>
                  <th className="cislo">Tokenov</th><th className="cislo">Cena</th>
                  <th className="cislo">Cena / volanie</th><th className="cislo">Čas</th>
                </tr>
              </thead>
              <tbody>
                {dta.modely.map((m, i) => (
                  <tr key={i}>
                    <td><span className={'nak-znacka ' + m.ucel}>
                      {UCELY[m.ucel]?.text ?? m.ucel}</span></td>
                    <td className="nak-model">{m.model_id}</td>
                    <td className="cislo">{m.volani}</td>
                    <td className="cislo">{m.uspesnych}</td>
                    <td className="cislo">{Number(m.tokenov).toLocaleString('sk-SK')}</td>
                    <td className="cislo">${Number(m.cena_spolu).toFixed(4)}</td>
                    <td className="cislo">${Number(m.cena_na_volanie).toFixed(6)}</td>
                    <td className="cislo">{(m.ms_priemer / 1000).toFixed(1)} s</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      {/* --- detail: riadok na volanie --- */}
      {ucel && (
        <section>
          <h2>
            Detail — {UCELY[ucel].text}
            <span className="nak-pocet">{detail.length} riadkov</span>
          </h2>

          {detail.length === 0 ? (
            <p className="nak-prazdne">Za zvolené obdobie nič.</p>
          ) : (
            <div className="nak-obal">
              <table className="nak-tabulka">
                <thead>
                  <tr>
                    {STLPCE.map(s => (
                      <th key={s.kod}
                          className={(s.cislo ? 'cislo' : '')
                                   + (radenie.stlpec === s.kod ? ' radene' : '')}
                          onClick={() => klikStlpec(s.kod)}>
                        {s.text}
                        {radenie.stlpec === s.kod && (
                          <span className="nak-sipka">{radenie.smer === 'asc' ? '▲' : '▼'}</span>
                        )}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {detail.map((r, i) => (
                    <tr key={i} className={r.model_status && r.model_status !== 'ok' ? 'zle' : ''}>
                      {STLPCE.map(s => (
                        <td key={s.kod} className={s.cislo ? 'cislo' : ''}>
                          {bunka(r, s.kod)}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      )}
    </div>
  );
}

// ------------------------------------------------------------
// Formatovanie jednej bunky
// ------------------------------------------------------------
function bunka(r, kod) {
  const v = r[kod];
  if (v === null || v === undefined) return <span className="nak-nic">—</span>;

  switch (kod) {
    case 'external_id':
      return (
        <span title={r.title || ''}>
          {v}
          {r.model_status && r.model_status !== 'ok' && (
            <span className="nak-zlyhalo" title={r.model_error || ''}>
              {r.model_status}
            </span>
          )}
        </span>
      );
    case 'stiahnuty_o':
    case 'vytazeny_o':
      return <span title={v}>{cas(v)}</span>;
    case 'fetch_ms':
    case 'model_ms':
      return v >= 1000 ? (v / 1000).toFixed(1) + ' s' : v + ' ms';
    case 'total_tokens':
      return Number(v).toLocaleString('sk-SK');
    case 'cost_usd':
      // Bezplatné modely stoja 0 — zobraziť "zadarmo" je čitateľnejšie
      // než $0.000000, ktoré vyzerá ako chýbajúci údaj.
      return Number(v) === 0 ? <span className="nak-zadarmo">zadarmo</span>
                             : '$' + Number(v).toFixed(6);
    case 'model_id':
      return <span className="nak-model" title={v}>{v.replace(':free', '')}</span>;
    case 'score':
      return <span className={'nak-skore ' + (r.bucket || '')}>{v}</span>;
    default:
      return String(v);
  }
}

function cas(iso) {
  const d = new Date(iso);
  const teraz = new Date();
  if (d.toDateString() === teraz.toDateString()) {
    return d.toLocaleTimeString('sk-SK', { hour: '2-digit', minute: '2-digit' });
  }
  return d.toLocaleDateString('sk-SK', { day: 'numeric', month: 'numeric' })
       + ' ' + d.toLocaleTimeString('sk-SK', { hour: '2-digit', minute: '2-digit' });
}
