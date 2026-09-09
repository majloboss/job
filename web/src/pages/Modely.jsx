import { useEffect, useState, useCallback } from 'react';
import { api } from '../api';
import './Modely.css';

// Sprava ciselnika modelov a poradia, v akom sa maju skusat.
//
// Model do produkcie sa uz neberie z konstanty v konfiguraku — nastavuje sa
// tu. Aplikacia ziskava udaje v dvoch krokoch a kazdy moze bezat na inom
// modeli:
//   'parse' — vytazenie udajov z inzeratu pri zbere (na portal)
//   'eval'  — posudenie vhodnosti pre pouzivatela
//
// Poradie je zoznam: ked prvy model prestane fungovat (vycerpany denny limit,
// vypadok), aplikacia sa sama prepne na dalsi.

const UCELY = [
  { kod: 'parse', text: 'Zber údajov',  popis: 'Vyťaženie údajov z inzerátu pri zbere' },
  { kod: 'eval',  text: 'Vhodnosť',     popis: 'Posúdenie vhodnosti ponuky pre používateľa' },
];

// Zoradovanie ciselnika. Kazdy stlpec vie hodnotu, podla ktorej sa radi —
// vratane toho, kam patria prazdne hodnoty (vzdy dolu, nech sa radi hore
// alebo dole).
const STLPCE = [
  { kod: 'model_id',   text: 'Model',    typ: 'text' },
  { kod: 'is_free',    text: 'Zadarmo',  typ: 'bool',  uzky: true },
  { kod: 'cena',       text: 'Cena/1M',  typ: 'cislo', uzky: true,
    hodnota: m => (m.price_input_1m ?? null) === null ? null
                 : Number(m.price_input_1m) + Number(m.price_output_1m ?? 0) },
  { kod: 'context_length', text: 'Kontext', typ: 'cislo', uzky: true },
  { kod: 'agree_rate', text: 'Zhoda',    typ: 'cislo', uzky: true },
  { kod: 'success_rate', text: 'Úspech', typ: 'cislo', uzky: true },
  { kod: 'lab_runs',   text: 'Testov',   typ: 'cislo', uzky: true },
  { kod: 'avg_ms',     text: 'Čas',      typ: 'cislo', uzky: true },
];

export default function Modely() {
  const [ucel, setUcel]         = useState('parse');
  const [sourceId, setSourceId] = useState('');
  const [dta, setDta]           = useState(null);
  const [nacitava, setNacitava] = useState(true);
  const [chyba, setChyba]       = useState(null);
  const [sprava, setSprava]     = useState(null);
  const [pracuje, setPracuje]   = useState(false);

  // Poradie sa upravuje lokalne a uklada az na tlacidlo — inak by kazde
  // posunutie riadka znamenalo volanie servera.
  const [poradie, setPoradie]   = useState([]);
  const [zmenene, setZmenene]   = useState(false);

  const [radenie, setRadenie]   = useState({ stlpec: null, smer: 'asc' });
  const [filter, setFilter]     = useState('');
  const [lenPouzitelne, setLenPouzitelne] = useState(true);

  const nacitat = useCallback(async () => {
    setNacitava(true);
    setChyba(null);
    try {
      const q = new URLSearchParams({ ucel });
      if (ucel === 'parse' && sourceId) q.set('source_id', sourceId);
      const r = await api('/v1/admin/ai-ciselnik?' + q);
      setDta(r);
      setPoradie(r.poradie.map(p => p.model_db_id));
      setZmenene(false);
    } catch (e) {
      setChyba(e.message);
    } finally {
      setNacitava(false);
    }
  }, [ucel, sourceId]);

  useEffect(() => { nacitat(); }, [nacitat]);

  // Hlaska zmizne sama, aby na obrazovke nezostavala visiet.
  useEffect(() => {
    if (!sprava) return;
    const t = setTimeout(() => setSprava(null), 5000);
    return () => clearTimeout(t);
  }, [sprava]);

  async function akcia(nazov, telo, hlaska) {
    setPracuje(true);
    setChyba(null);
    try {
      const r = await api('/v1/admin/ai-ciselnik?akcia=' + nazov,
                          { method: 'POST', body: telo });
      setSprava(r.sprava || hlaska);
      await nacitat();
    } catch (e) {
      setChyba(e.message);
    } finally {
      setPracuje(false);
    }
  }

  const modely = dta?.modely ?? [];
  const vPoradi = new Set(poradie);

  // --- filtrovanie a zoradenie ciselnika ---
  let zoznam = modely.filter(m => {
    if (lenPouzitelne && !m.pouzitelny) return false;
    if (!filter) return true;
    const f = filter.toLowerCase();
    return m.model_id.toLowerCase().includes(f)
        || (m.name || '').toLowerCase().includes(f);
  });

  if (radenie.stlpec) {
    const s = STLPCE.find(c => c.kod === radenie.stlpec);
    const hod = m => s.hodnota ? s.hodnota(m) : m[s.kod];
    const znamienko = radenie.smer === 'asc' ? 1 : -1;

    zoznam = [...zoznam].sort((a, b) => {
      const x = hod(a), y = hod(b);
      // Prazdne hodnoty idu vzdy dolu — model bez zmeranej zhody nema byt
      // hore len preto, ze sa radi vzostupne.
      const xp = x === null || x === undefined || x === '';
      const yp = y === null || y === undefined || y === '';
      if (xp && yp) return 0;
      if (xp) return 1;
      if (yp) return -1;
      if (s.typ === 'text') return znamienko * String(x).localeCompare(String(y), 'sk');
      if (s.typ === 'bool') return znamienko * ((y ? 1 : 0) - (x ? 1 : 0));
      return znamienko * (Number(x) - Number(y));
    });
  }

  function klikStlpec(kod) {
    setRadenie(r => r.stlpec === kod
      ? { stlpec: kod, smer: r.smer === 'asc' ? 'desc' : 'asc' }
      : { stlpec: kod, smer: kod === 'model_id' ? 'asc' : 'desc' });
  }

  // --- uprava poradia ---
  function pridatDoPoradia(id) {
    if (vPoradi.has(id)) return;
    setPoradie(p => [...p, id]);
    setZmenene(true);
  }
  function odobrat(id) {
    setPoradie(p => p.filter(x => x !== id));
    setZmenene(true);
  }
  function posunut(i, o) {
    const j = i + o;
    if (j < 0 || j >= poradie.length) return;
    const n = [...poradie];
    [n[i], n[j]] = [n[j], n[i]];
    setPoradie(n);
    setZmenene(true);
  }

  const modelPodlaId = id => modely.find(m => m.id === id);
  const stav = dta?.stav;
  const aktivny = dta?.aktivny;

  return (
    <div className="modely">
      <h1>Modely</h1>

      {/* --- vyber ulohy --- */}
      <div className="mod-ucely">
        {UCELY.map(u => (
          <button
            key={u.kod}
            className={'mod-ucel' + (ucel === u.kod ? ' aktivny' : '')}
            onClick={() => { setUcel(u.kod); setSourceId(''); }}
            title={u.popis}
          >{u.text}</button>
        ))}

        {/* Posudenie vhodnosti sa robi nad uz vytazenym inzeratom —
            na portale nezavisi, preto sa vyber portalu ukazuje len pri zbere. */}
        {ucel === 'parse' && (
          <select value={sourceId} onChange={e => setSourceId(e.target.value)}>
            <option value="">Všetky portály</option>
            {(dta?.portaly ?? []).map(p => (
              <option key={p.id} value={p.id}>{p.name}</option>
            ))}
          </select>
        )}

        <button className="mod-sync" onClick={() => akcia('sync', {}, 'Cenník zosynchronizovaný')}
                disabled={pracuje}>
          {pracuje ? 'Pracujem…' : 'Načítať cenník'}
        </button>
      </div>

      {chyba  && <p className="mod-chyba">{chyba}</p>}
      {sprava && <p className="mod-sprava">{sprava}</p>}

      {nacitava && <p className="mod-nacitava">Načítavam…</p>}

      {dta && (
        <>
          {/* --- co bezi prave teraz --- */}
          <section className={'mod-aktivny' + (aktivny.vypnute ? ' vypnute' : '')}>
            <div>
              <span className="mod-stitok">Práve sa použije</span>
              <strong>{aktivny.model || '— žiadny model —'}</strong>
              <span className="mod-dovod">{aktivny.dovod}</span>
            </div>
            <div className="mod-rozpocet">
              <label>
                Denný strop&nbsp;$
                <input
                  type="number" step="0.1" min="0"
                  defaultValue={stav?.daily_budget_usd ?? 1}
                  onBlur={e => akcia('rozpocet',
                    { ucel, source_id: sourceId, budget: parseFloat(e.target.value) })}
                />
              </label>
              <span className="mod-minute">
                dnes minuté ${Number(stav?.spent_usd ?? 0).toFixed(4)}
                {stav?.calls_count ? ` / ${stav.calls_count} volaní` : ''}
                {stav?.prepnuti_dnes > 0 && ` / ${stav.prepnuti_dnes}× prepnuté`}
              </span>
              {aktivny.vypnute
                ? <button onClick={() => akcia('stav', { ucel, source_id: sourceId, is_enabled: true })}>
                    Znova zapnúť
                  </button>
                : <button className="mod-vypnut"
                          onClick={() => akcia('stav', { ucel, source_id: sourceId, is_enabled: false,
                                                         dovod: 'Vypnuté správcom' })}>
                    Vypnúť
                  </button>}
            </div>
          </section>

          {/* --- poradie --- */}
          <section className="mod-poradie">
            <h2>
              Poradie modelov
              {zmenene && (
                <button className="mod-ulozit"
                        onClick={() => akcia('poradie',
                          { ucel, source_id: sourceId || null, modely: poradie },
                          'Poradie uložené')}
                        disabled={pracuje}>
                  Uložiť poradie
                </button>
              )}
            </h2>
            <p className="mod-popis">
              Keď prvý model prestane fungovať, aplikácia sa sama prepne na ďalší.
              Osvedčené je dať dopredu dva-tri bezplatné a za ne lacný platený ako poistku.
            </p>

            {poradie.length === 0 && (
              <p className="mod-prazdne">
                Poradie nie je nastavené — použije sa najlepší bezplatný model z číselníka.
                Pridaj modely tlačidlom <strong>+</strong> v tabuľke nižšie.
              </p>
            )}

            <ol className="mod-zoznam">
              {poradie.map((id, i) => {
                const m = modelPodlaId(id);
                if (!m) return null;
                return (
                  <li key={id} className={m.pouzitelny ? '' : 'nepouzitelny'}>
                    <span className="mod-cislo">{i + 1}.</span>
                    <span className="mod-nazov">
                      {m.model_id}
                      {m.is_free && <span className="mod-free">zadarmo</span>}
                      {!m.pouzitelny && (
                        <span className="mod-vyradeny" title={m.unavailable_reason}>vyradený</span>
                      )}
                    </span>
                    <span className="mod-cisla">
                      {m.agree_rate !== null && <span title="Zhoda s ostatnými">{m.agree_rate} %</span>}
                      {!m.is_free && <span title="Cena za volanie">${Number(m.cena_volania).toFixed(5)}</span>}
                    </span>
                    <span className="mod-tlacidla">
                      <button onClick={() => posunut(i, -1)} disabled={i === 0} aria-label="Vyššie">↑</button>
                      <button onClick={() => posunut(i, 1)} disabled={i === poradie.length - 1} aria-label="Nižšie">↓</button>
                      <button onClick={() => odobrat(id)} aria-label="Odobrať">×</button>
                    </span>
                  </li>
                );
              })}
            </ol>
          </section>

          {/* --- ciselnik --- */}
          <section className="mod-ciselnik">
            <h2>
              Číselník modelov
              <span className="mod-pocty">
                {dta.pocty.pouzitelnych} použiteľných z {dta.pocty.spolu}
                {' · '}{dta.pocty.free} bezplatných
              </span>
            </h2>

            <div className="mod-filtre">
              <input
                type="search" placeholder="Hľadať model…"
                value={filter} onChange={e => setFilter(e.target.value)}
              />
              <label>
                <input type="checkbox" checked={lenPouzitelne}
                       onChange={e => setLenPouzitelne(e.target.checked)} />
                len použiteľné
              </label>
            </div>

            <div className="mod-tabulka-obal">
              <table className="mod-tabulka">
                <thead>
                  <tr>
                    <th className="uzky" />
                    {STLPCE.map(s => (
                      <th key={s.kod} className={s.uzky ? 'uzky' : ''}
                          onClick={() => klikStlpec(s.kod)}>
                        {s.text}
                        {radenie.stlpec === s.kod && (
                          <span className="mod-sipka">{radenie.smer === 'asc' ? '▲' : '▼'}</span>
                        )}
                      </th>
                    ))}
                    <th className="uzky" />
                  </tr>
                </thead>
                <tbody>
                  {zoznam.map(m => (
                    <tr key={m.id} className={m.pouzitelny ? '' : 'nepouzitelny'}>
                      <td className="uzky">
                        <button className="mod-pridat"
                                onClick={() => pridatDoPoradia(m.id)}
                                disabled={vPoradi.has(m.id)}
                                title={vPoradi.has(m.id) ? 'Už je v poradí' : 'Pridať do poradia'}>
                          {vPoradi.has(m.id) ? '✓' : '+'}
                        </button>
                      </td>
                      <td className="mod-bunka-model">
                        <span className="mod-id">{m.model_id}</span>
                        {m.unavailable_reason && (
                          <span className="mod-dovod-maly">{m.unavailable_reason}</span>
                        )}
                      </td>
                      <td className="uzky">{m.is_free ? 'áno' : ''}</td>
                      <td className="uzky">
                        {m.is_free ? '—'
                         : m.price_input_1m === null ? '?'
                         : '$' + (Number(m.price_input_1m) + Number(m.price_output_1m ?? 0)).toFixed(2)}
                      </td>
                      <td className="uzky">
                        {m.context_length ? Math.round(m.context_length / 1000) + 'k' : ''}
                      </td>
                      <td className="uzky">{m.agree_rate !== null ? m.agree_rate + ' %' : ''}</td>
                      <td className="uzky">{m.success_rate !== null ? m.success_rate + ' %' : ''}</td>
                      <td className="uzky">{m.lab_runs || ''}</td>
                      <td className="uzky">{m.avg_ms ? Math.round(m.avg_ms / 100) / 10 + ' s' : ''}</td>
                      <td className="uzky">
                        <button className="mod-prepnut"
                                onClick={() => akcia('model',
                                  { model_id: m.id, is_enabled: !m.pouzitelny })}
                                title={m.pouzitelny ? 'Vyradiť z výberu' : 'Vrátiť do výberu'}>
                          {m.pouzitelny ? '⨯' : '↺'}
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {zoznam.length === 0 && <p className="mod-prazdne">Nič nevyhovuje filtru.</p>}
            </div>
          </section>
        </>
      )}
    </div>
  );
}
