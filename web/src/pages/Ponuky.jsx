import { useEffect, useState, useCallback, useRef } from 'react';
import { api } from '../api';
import './Ponuky.css';

// Zoznam pracovnych ponuk s filtrami a radenim podla stlpca.
//
// Ten isty komponent sluzi pouzivatelovi aj adminovi — obsah je rovnaky,
// admin len vidi navyse, ktory model inzerat vytazil a kolko to stalo.
// Nemalo by zmysel udrziavat dve takmer zhodne obrazovky.
//
// Radenie a strankovanie robi SERVER, nie prehliadac: pri tisickach inzeratov
// by sa nedalo zoradit to, co prave nie je nacitane.

const STLPCE = [
  { kod: 'title',        text: 'Pozícia',  hlavny: true },
  { kod: 'company',      text: 'Firma' },
  { kod: 'salary',       text: 'Mzda' },
  { kod: 'industry',     text: 'Odvetvie' },
  { kod: 'source',       text: 'Portál' },
  { kod: 'published_at', text: 'Zverejnené' },
  { kod: 'score',        text: 'Vhodnosť' },
];

const UVAZKY = {
  tpp: 'TPP', dohoda: 'Dohoda', zivnost: 'Živnosť',
  brigada: 'Brigáda', internship: 'Stáž',
};

const REZIMY = { onsite: 'Na pracovisku', hybrid: 'Hybridne', remote: 'Z domu' };

// Prazdny filter = "nefiltruj". Drzi sa v jednom objekte, aby sa dal naraz
// vynulovat aj poslat na server.
const PRAZDNY = {
  q: '', source_id: '', industry: '', employment_type: '', remote_type: '',
  salary_min: '', dni: '', bez_agentur: false, aj_bez_mzdy: true,
};

const STRANKA = 50;

export default function Ponuky() {
  const [filtre, setFiltre]   = useState(PRAZDNY);
  const [radit, setRadit]     = useState('published_at');
  const [smer, setSmer]       = useState('desc');
  const [offset, setOffset]   = useState(0);

  const [dta, setDta]         = useState(null);
  const [nacitava, setNacitava] = useState(true);
  const [chyba, setChyba]     = useState(null);
  const [detail, setDetail]   = useState(null);

  // Hladanie sa neposiela pri kazdom pismene — az ked pouzivatel prestane
  // pisat. Inak by kazde stlacenie klavesy znamenalo dopyt do DB.
  const [hladanie, setHladanie] = useState('');
  const casovacRef = useRef(null);

  useEffect(() => {
    if (casovacRef.current) clearTimeout(casovacRef.current);
    casovacRef.current = setTimeout(() => {
      setFiltre(f => ({ ...f, q: hladanie }));
      setOffset(0);
    }, 400);
    return () => clearTimeout(casovacRef.current);
  }, [hladanie]);

  const nacitat = useCallback(async () => {
    setNacitava(true);
    setChyba(null);
    try {
      const q = new URLSearchParams({ radit, smer, limit: STRANKA, offset });
      for (const [k, v] of Object.entries(filtre)) {
        if (v === '' || v === false) continue;
        q.set(k, v === true ? '1' : v);
      }
      setDta(await api('/v1/offers?' + q));
    } catch (e) {
      setChyba(e.message);
    } finally {
      setNacitava(false);
    }
  }, [filtre, radit, smer, offset]);

  useEffect(() => { nacitat(); }, [nacitat]);

  function klikStlpec(kod) {
    if (radit === kod) {
      setSmer(s => (s === 'asc' ? 'desc' : 'asc'));
    } else {
      setRadit(kod);
      // Text sa zvycajne cita od A, cisla a datumy od najvacsieho.
      setSmer(kod === 'title' || kod === 'company' || kod === 'industry' ? 'asc' : 'desc');
    }
    setOffset(0);
  }

  function zmenaFiltra(kluc, hodnota) {
    setFiltre(f => ({ ...f, [kluc]: hodnota }));
    setOffset(0);
  }

  async function otvorit(id) {
    setDetail({ nacitava: true });
    try {
      setDetail(await api('/v1/offers?id=' + id));
    } catch (e) {
      setDetail({ chyba: e.message });
    }
  }

  const offers = dta?.offers ?? [];
  const spolu  = dta?.spolu ?? 0;
  const c      = dta?.ciselniky ?? { portaly: [], odvetvia: [], uvazky: [] };
  const filtrujeSa = Object.entries(filtre)
    .some(([k, v]) => v !== PRAZDNY[k]);

  return (
    <div className="ponuky">
      <h1>
        Pracovné ponuky
        <span className="pon-pocet">
          {nacitava ? 'načítavam…' : `${spolu} ${sklonuj(spolu)}`}
        </span>
      </h1>

      {/* --- filtre --- */}
      <div className="pon-filtre">
        <input
          type="search" className="pon-hladat"
          placeholder="Hľadať v názve, firme, kľúčových slovách…"
          value={hladanie} onChange={e => setHladanie(e.target.value)}
        />

        <select value={filtre.source_id} onChange={e => zmenaFiltra('source_id', e.target.value)}>
          <option value="">Všetky portály</option>
          {c.portaly.map(p => (
            <option key={p.id} value={p.id}>{p.name} ({p.pocet})</option>
          ))}
        </select>

        <select value={filtre.industry} onChange={e => zmenaFiltra('industry', e.target.value)}>
          <option value="">Všetky odvetvia</option>
          {c.odvetvia.map(o => (
            <option key={o.industry} value={o.industry}>{o.industry} ({o.pocet})</option>
          ))}
        </select>

        <select value={filtre.employment_type}
                onChange={e => zmenaFiltra('employment_type', e.target.value)}>
          <option value="">Každý úväzok</option>
          {c.uvazky.map(u => (
            <option key={u.employment_type} value={u.employment_type}>
              {UVAZKY[u.employment_type] || u.employment_type} ({u.pocet})
            </option>
          ))}
        </select>

        <select value={filtre.remote_type} onChange={e => zmenaFiltra('remote_type', e.target.value)}>
          <option value="">Kdekoľvek</option>
          {Object.entries(REZIMY).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>

        <select value={filtre.dni} onChange={e => zmenaFiltra('dni', e.target.value)}>
          <option value="">Bez ohľadu na dátum</option>
          <option value="1">Za posledný deň</option>
          <option value="3">Za 3 dni</option>
          <option value="7">Za týždeň</option>
          <option value="30">Za mesiac</option>
        </select>

        <label className="pon-mzda">
          Mzda od
          <input
            type="number" min="0" step="50" placeholder="€"
            value={filtre.salary_min}
            onChange={e => zmenaFiltra('salary_min', e.target.value)}
          />
        </label>

        <label className="pon-prepinac">
          <input type="checkbox" checked={filtre.bez_agentur}
                 onChange={e => zmenaFiltra('bez_agentur', e.target.checked)} />
          bez agentúr
        </label>

        {/* Inzerat bez uvedenej mzdy tvori velku cast ponuk — pri filtri na
            mzdu sa preto standardne PONECHAVA, nie vyradi. */}
        {filtre.salary_min !== '' && (
          <label className="pon-prepinac">
            <input type="checkbox" checked={filtre.aj_bez_mzdy}
                   onChange={e => zmenaFiltra('aj_bez_mzdy', e.target.checked)} />
            aj bez uvedenej mzdy
          </label>
        )}

        {filtrujeSa && (
          <button className="pon-zrusit"
                  onClick={() => { setFiltre(PRAZDNY); setHladanie(''); setOffset(0); }}>
            Zrušiť filtre
          </button>
        )}
      </div>

      {chyba && <p className="pon-chyba">{chyba}</p>}

      {/* --- tabulka --- */}
      <div className="pon-obal">
        <table className="pon-tabulka">
          <thead>
            <tr>
              {STLPCE.map(s => (
                <th key={s.kod}
                    className={(s.hlavny ? 'hlavny' : '') + (radit === s.kod ? ' radene' : '')}
                    onClick={() => klikStlpec(s.kod)}
                    title={'Zoradiť podľa: ' + s.text}>
                  {s.text}
                  {radit === s.kod && (
                    <span className="pon-sipka">{smer === 'asc' ? '▲' : '▼'}</span>
                  )}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {offers.map(o => (
              <tr key={o.id} onClick={() => otvorit(o.id)}>
                <td className="hlavny">
                  <span className="pon-titul">{o.title_sk || o.title}</span>
                  {o.summary_sk && <span className="pon-sumar">{o.summary_sk}</span>}
                  {o.technologies?.length > 0 && (
                    <span className="pon-tagy">
                      {o.technologies.slice(0, 6).map(t => (
                        <span key={t} className="pon-tag">{t}</span>
                      ))}
                    </span>
                  )}
                </td>
                <td>
                  {o.company_name_raw}
                  {o.is_agency_offer && <span className="pon-agentura">agentúra</span>}
                </td>
                <td className="pon-cislo">{mzda(o)}</td>
                <td>{o.industry}</td>
                <td>{o.source_name}</td>
                <td className="pon-cislo" title={o.published_at || ''}>
                  {datum(o.published_at || o.created_at)}
                </td>
                <td className="pon-cislo">
                  {o.score !== null
                    ? <span className={'pon-skore ' + (o.bucket || '')}>{o.score}</span>
                    : <span className="pon-nic">—</span>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>

        {!nacitava && offers.length === 0 && (
          <p className="pon-prazdne">
            {spolu === 0 && !filtrujeSa
              ? 'Zatiaľ nemáme žiadne inzeráty — zber ešte nebežal.'
              : 'Žiadna ponuka nevyhovuje filtru.'}
          </p>
        )}
      </div>

      {/* --- strankovanie --- */}
      {spolu > STRANKA && (
        <div className="pon-strankovanie">
          <button onClick={() => setOffset(o => Math.max(0, o - STRANKA))}
                  disabled={offset === 0}>← Predchádzajúce</button>
          <span>{offset + 1}–{Math.min(offset + STRANKA, spolu)} z {spolu}</span>
          <button onClick={() => setOffset(o => o + STRANKA)}
                  disabled={offset + STRANKA >= spolu}>Ďalšie →</button>
        </div>
      )}

      {detail && <Detail data={detail} zavri={() => setDetail(null)} />}
    </div>
  );
}

// ------------------------------------------------------------
// Detail inzeratu v prekrytí.
//
// Original sa nikdy neprepisuje — ked je inzerat v inom jazyku, da sa
// prepnut medzi prekladom a originalom.
// ------------------------------------------------------------
function Detail({ data, zavri }) {
  const [jazyk, setJazyk] = useState('preklad');

  // Zavretie klavesou Esc — na desktope prirodzenejsie nez hladat krizik.
  useEffect(() => {
    const f = e => { if (e.key === 'Escape') zavri(); };
    window.addEventListener('keydown', f);
    return () => window.removeEventListener('keydown', f);
  }, [zavri]);

  if (data.nacitava) return <div className="pon-prekrytie"><div className="pon-detail">Načítavam…</div></div>;
  if (data.chyba)    return (
    <div className="pon-prekrytie" onClick={zavri}>
      <div className="pon-detail pon-chyba">{data.chyba}</div>
    </div>
  );

  const o = data.offer;
  const maPreklad = !!data.obsah?.preklad;
  const obsah = (jazyk === 'preklad' && maPreklad) ? data.obsah.preklad : data.obsah?.original;

  return (
    <div className="pon-prekrytie" onClick={zavri}>
      <div className="pon-detail" onClick={e => e.stopPropagation()}>
        <button className="pon-zavri" onClick={zavri} aria-label="Zavrieť">×</button>

        <h2>{o.title_sk || o.title}</h2>
        <p className="pon-meta">
          {o.company_name_raw}
          {o.is_agency_offer && <span className="pon-agentura">agentúra</span>}
          {' · '}{o.source_name}
          {' · '}{datum(o.published_at || o.created_at)}
        </p>

        <dl className="pon-udaje">
          {mzda(o) !== '—' && <><dt>Mzda</dt><dd>{o.salary_raw || mzda(o)}</dd></>}
          {o.employment_type && <><dt>Úväzok</dt><dd>{UVAZKY[o.employment_type] || o.employment_type}</dd></>}
          {o.remote_type && <><dt>Režim</dt><dd>{REZIMY[o.remote_type] || o.remote_type}</dd></>}
          {o.seniority && <><dt>Úroveň</dt><dd>{o.seniority}</dd></>}
          {o.industry && <><dt>Odvetvie</dt><dd>{o.industry}</dd></>}
          {o.locations?.length > 0 && (
            <><dt>Miesto</dt><dd>{o.locations.map(l => l.name).join(', ')}</dd></>
          )}
          {o.education_level && <><dt>Vzdelanie</dt><dd>{o.education_level}</dd></>}
          {o.start_date && <><dt>Nástup</dt><dd>{o.start_date}</dd></>}
        </dl>

        {o.summary_sk && <p className="pon-sumar-detail">{o.summary_sk}</p>}

        {o.technologies?.length > 0 && (
          <p className="pon-tagy">
            {o.technologies.map(t => <span key={t} className="pon-tag">{t}</span>)}
          </p>
        )}

        {maPreklad && (
          <div className="pon-jazyky">
            <button className={jazyk === 'preklad' ? 'aktivny' : ''}
                    onClick={() => setJazyk('preklad')}>Slovensky</button>
            <button className={jazyk === 'original' ? 'aktivny' : ''}
                    onClick={() => setJazyk('original')}>
              Originál ({data.obsah.original?.lang || o.orig_lang})
            </button>
          </div>
        )}

        {obsah?.text && <pre className="pon-text">{obsah.text}</pre>}

        {/* Adminovi navyse: cim a za kolko sa inzerat vytazil. */}
        {o.zber?.length > 0 && (
          <details className="pon-zber">
            <summary>Zber údajov ({o.zber.length})</summary>
            <table>
              <tbody>
                {o.zber.map((z, i) => (
                  <tr key={i}>
                    <td>{z.model_id}</td>
                    <td>{z.status}</td>
                    <td>{z.total_tokens} tok.</td>
                    <td>{z.cost_usd ? '$' + Number(z.cost_usd).toFixed(5) : '—'}</td>
                    <td>{z.took_ms ? Math.round(z.took_ms / 100) / 10 + ' s' : ''}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </details>
        )}

        <a className="pon-original" href={o.url} target="_blank" rel="noreferrer noopener">
          Otvoriť originál na {o.source_name} →
        </a>
      </div>
    </div>
  );
}

// ------------------------------------------------------------
// Pomocne formatovanie
// ------------------------------------------------------------
function mzda(o) {
  if (o.salary_min === null && o.salary_max === null) return o.salary_raw || '—';
  const jednotka = { month: '/mes.', hour: '/hod.', year: '/rok' }[o.salary_period] || '';
  const mena = o.salary_currency === 'EUR' ? '€' : (o.salary_currency || '');
  const n = v => Math.round(Number(v)).toLocaleString('sk-SK');
  if (o.salary_min && o.salary_max) return `${n(o.salary_min)}–${n(o.salary_max)} ${mena}${jednotka}`;
  if (o.salary_min) return `od ${n(o.salary_min)} ${mena}${jednotka}`;
  return `do ${n(o.salary_max)} ${mena}${jednotka}`;
}

function datum(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  const dnes = new Date();
  const rozdiel = Math.floor((dnes - d) / 86400000);
  if (rozdiel === 0) return 'dnes';
  if (rozdiel === 1) return 'včera';
  if (rozdiel < 7)   return `pred ${rozdiel} dňami`;
  return d.toLocaleDateString('sk-SK', { day: 'numeric', month: 'numeric', year: '2-digit' });
}

function sklonuj(n) {
  if (n === 1) return 'ponuka';
  if (n >= 2 && n <= 4) return 'ponuky';
  return 'ponúk';
}
