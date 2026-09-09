import { useEffect, useState, useCallback, useRef } from 'react';
import { api } from '../api';
import './Zber.css';

// Ručné spustenie zberu inzerátov.
//
// Zber je DVOJKROKOVÝ a oba kroky sa spúšťajú samostatne:
//   1. Stiahnutie — scraper prejde zoznamy portálu a uloží HTML inzerátov
//   2. Vyťaženie  — model z uloženého HTML vytiahne mzdu, úväzky, kľúč. slová
//
// Oddelenie je zámerné: ťaženie sa dá zopakovať lepším promptom bez
// opätovného sťahovania z portálu.

const OBDOBIA = [
  { dni: 1,  text: 'posledný deň' },
  { dni: 3,  text: '3 dni' },
  { dni: 7,  text: 'týždeň' },
  { dni: 14, text: '2 týždne' },
];

// Pri prvom behu sa oplatí obmedziť — chyba v ťažení sa prejaví na 20,
// nie na 500 inzerátoch.
const LIMITY = [10, 20, 50, 100, 0];

export default function Zber() {
  const [dta, setDta]         = useState(null);
  const [nacitava, setNacitava] = useState(true);
  const [chyba, setChyba]     = useState(null);
  const [sprava, setSprava]   = useState(null);
  const [pracuje, setPracuje] = useState(false);

  const [sourceId, setSourceId] = useState('');
  const [dni, setDni]           = useState(1);
  const [limit, setLimit]       = useState(20);

  const casovacRef = useRef(null);

  const nacitat = useCallback(async () => {
    try {
      const r = await api('/v1/admin/zber');
      setDta(r);
      if (!sourceId && r.portaly?.length) setSourceId(String(r.portaly[0].id));
      setChyba(null);
    } catch (e) {
      setChyba(e.message);
    } finally {
      setNacitava(false);
    }
  }, [sourceId]);

  useEffect(() => { nacitat(); }, [nacitat]);

  // Kým beh beží, stav sa obnovuje sám — scraper píše do DB priebežne
  // a človek by inak musel klikať na Obnoviť.
  const bezi = dta?.behy?.some(b => b.status === 'running');
  useEffect(() => {
    if (!bezi) return;
    casovacRef.current = setInterval(nacitat, 5000);
    return () => clearInterval(casovacRef.current);
  }, [bezi, nacitat]);

  useEffect(() => {
    if (!sprava) return;
    const t = setTimeout(() => setSprava(null), 8000);
    return () => clearTimeout(t);
  }, [sprava]);

  async function akcia(nazov, telo) {
    setPracuje(true);
    setChyba(null);
    try {
      const r = await api('/v1/admin/zber?akcia=' + nazov, { method: 'POST', body: telo });
      setSprava(r.sprava);
      await nacitat();
    } catch (e) {
      setChyba(e.message);
    } finally {
      setPracuje(false);
    }
  }

  const stav      = dta?.stav;
  const caka      = dta?.caka ?? 0;
  const neuplnych = dta?.neuplnych ?? 0;
  const maPython = dta?.python;

  return (
    <div className="zber">
      <h1>Zber inzerátov</h1>

      {chyba  && <p className="zb-chyba">{chyba}</p>}
      {sprava && <p className="zb-sprava">{sprava}</p>}

      {/* Bez knižníc sa zber zo servera spustiť nedá. Doinštalovať sa dajú
          rovno tu — na hostingu sa môžu stratiť pri zmene prostredia. */}
      {dta && !maPython && (
        <div className="zb-upozornenie">
          <strong>Na serveri nie sú pripravené Python knižnice.</strong>{' '}
          Skús ich doinštalovať, alebo spusti zber z príkazového riadka:
          <pre>python scraper/profesia.py --dni {dni} --limit {limit}</pre>
          <button className="zb-spustit" onClick={() => akcia('kniznice', {})}
                  disabled={pracuje}>
            {pracuje ? 'Inštalujem…' : 'Doinštalovať knižnice'}
          </button>
        </div>
      )}

      {/* --- stav databázy --- */}
      {stav && (
        <div className="zb-stav">
          <div className="zb-cislo">
            <strong>{stav.inzeratov}</strong>
            <span>inzerátov v DB</span>
          </div>
          <div className="zb-cislo">
            <strong>{stav.s_detailom}</strong>
            <span>so stiahnutým detailom</span>
          </div>
          <div className={'zb-cislo' + (caka > 0 ? ' caka' : '')}>
            <strong>{stav.vytazenych}</strong>
            <span>vyťažených modelom</span>
          </div>
        </div>
      )}

      {/* --- krok 1: stiahnutie --- */}
      <section className="zb-krok">
        <h2><span className="zb-cislo-kroku">1</span> Stiahnuť inzeráty</h2>
        <p className="zb-popis">
          Prejde zoznamy portálu a stiahne detail tých inzerátov, ktoré ešte nemáme.
          Rešpektuje sa odstup 1,5 s medzi požiadavkami, takže zber trvá minúty.
        </p>

        <div className="zb-nastavenia">
          <label>
            <span>Portál</span>
            <select value={sourceId} onChange={e => setSourceId(e.target.value)}
                    disabled={pracuje || bezi}>
              {(dta?.portaly ?? []).map(p => (
                <option key={p.id} value={p.id}>{p.name}</option>
              ))}
            </select>
          </label>

          <label>
            <span>Obdobie</span>
            <select value={dni} onChange={e => setDni(Number(e.target.value))}
                    disabled={pracuje || bezi}>
              {OBDOBIA.map(o => <option key={o.dni} value={o.dni}>{o.text}</option>)}
            </select>
          </label>

          <label>
            <span>Najviac inzerátov</span>
            <select value={limit} onChange={e => setLimit(Number(e.target.value))}
                    disabled={pracuje || bezi}>
              {LIMITY.map(l => (
                <option key={l} value={l}>{l === 0 ? 'bez limitu' : l}</option>
              ))}
            </select>
          </label>

          <button className="zb-spustit"
                  onClick={() => akcia('spustit',
                    { source_id: Number(sourceId), dni, limit })}
                  disabled={pracuje || bezi || !sourceId}>
            {bezi ? 'Zber beží…' : pracuje ? 'Spúšťam…' : 'Spustiť zber'}
          </button>
        </div>
      </section>

      {/* --- krok 2: vyťaženie --- */}
      <section className="zb-krok">
        <h2><span className="zb-cislo-kroku">2</span> Vyťažiť údaje modelom</h2>
        <p className="zb-popis">
          Model z uloženého HTML vytiahne mzdu, úväzky, kľúčové slová a súhrn.
          Dá sa spustiť opakovane — s lepším promptom bez sťahovania z portálu.
        </p>

        {caka > 0 ? (
          <p className="zb-caka-info">
            Na vyťaženie čaká <strong>{caka}</strong>{' '}
            {caka === 1 ? 'inzerát' : caka < 5 ? 'inzeráty' : 'inzerátov'}.
          </p>
        ) : (
          <p className="zb-popis">Všetky stiahnuté inzeráty sú vyťažené.</p>
        )}

        <div className="zb-tlacidla">
          <button className="zb-spustit"
                  onClick={() => akcia('vytazit', { limit: Math.min(caka || 20, 100) })}
                  disabled={pracuje || caka === 0}>
            {pracuje ? 'Spúšťam…' : `Vyťažiť (${Math.min(caka || 0, 100)})`}
          </button>

          {/* Inzeráty, kde model odpovedal, ale údaje sa nezapísali. Bežné
              ťaženie ich preskakuje — majú úspešné volanie. */}
          {neuplnych > 0 && (
            <button className="zb-opakovat"
                    onClick={() => akcia('vytazit', { limit: neuplnych, znova: true })}
                    disabled={pracuje}
                    title="Model odpovedal, ale údaje sa do inzerátu nezapísali">
              Doplniť neúplné ({neuplnych})
            </button>
          )}
        </div>
      </section>

      {/* --- história behov --- */}
      <section>
        <h2>
          História behov
          {bezi && <span className="zb-bezi">prebieha…</span>}
        </h2>

        {nacitava && !dta && <p className="zb-popis">Načítavam…</p>}

        {dta?.behy?.length === 0 && (
          <p className="zb-popis">Zatiaľ žiadny beh.</p>
        )}

        {dta?.behy?.length > 0 && (
          <div className="zb-obal">
            <table className="zb-tabulka">
              <thead>
                <tr>
                  <th>#</th><th>Portál</th><th>Typ</th><th>Začiatok</th>
                  <th className="cislo">Trvanie</th>
                  <th className="cislo">Nájdené</th><th className="cislo">Nové</th>
                  <th className="cislo">Detaily</th><th className="cislo">Vyťažené</th>
                  <th className="cislo">Cena</th><th>Stav</th><th />
                </tr>
              </thead>
              <tbody>
                {dta.behy.map(b => (
                  <tr key={b.id} className={b.status}>
                    <td>{b.id}</td>
                    <td>{b.portal}</td>
                    <td>
                      {b.run_type === 'manual' ? 'ručne' : b.run_type}
                      {b.spustil && <span className="zb-kto"> · {b.spustil}</span>}
                    </td>
                    <td title={b.started_at}>{cas(b.started_at)}</td>
                    <td className="cislo">{trvanie(b.trvanie_s)}</td>
                    <td className="cislo">{b.offers_found || 0}</td>
                    <td className="cislo">{b.offers_new || 0}</td>
                    <td className="cislo">{b.details_fetched || 0}</td>
                    <td className="cislo">{b.parsed_count || 0}</td>
                    <td className="cislo">
                      {Number(b.cost_usd) > 0
                        ? '$' + Number(b.cost_usd).toFixed(5)
                        : <span className="zb-zadarmo">zadarmo</span>}
                    </td>
                    <td>
                      <span className={'zb-stav-znacka ' + b.status}>{popisStavu(b.status)}</span>
                      {b.errors_count > 0 && (
                        <span className="zb-chyb" title={b.error_message || ''}>
                          {b.errors_count} chýb
                        </span>
                      )}
                    </td>
                    <td>
                      {b.status === 'running' && (
                        <button className="zb-zrusit"
                                onClick={() => akcia('zrusit', { run_id: b.id })}
                                disabled={pracuje}>Zrušiť</button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}

// ------------------------------------------------------------
// Pomocné formátovanie
// ------------------------------------------------------------
function popisStavu(s) {
  return { running: 'beží', done: 'hotovo', failed: 'zlyhalo',
           cancelled: 'zrušené' }[s] ?? s;
}

function trvanie(s) {
  if (s === null || s === undefined) return '—';
  if (s < 60) return s + ' s';
  return Math.floor(s / 60) + ' m ' + (s % 60) + ' s';
}

function cas(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  const dnes = new Date();
  const t = d.toLocaleTimeString('sk-SK', { hour: '2-digit', minute: '2-digit' });
  if (d.toDateString() === dnes.toDateString()) return t;
  return d.toLocaleDateString('sk-SK', { day: 'numeric', month: 'numeric' }) + ' ' + t;
}
