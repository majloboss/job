import { useEffect, useState, useCallback, useRef } from 'react';
import { api } from '../api';
import './TestModelov.css';

// Test modelov — porovnanie, ktorý model najlepšie zvládne dve úlohy
// nad tým istým inzerátom:
//
//   1. Ťaženie údajov — názov, firma, mzda, miesto, HTML, preklad, súhrn
//   2. Vhodnosť — skóre a slovné hodnotenie podľa CV a preferencií
//
// Model dobrý na jedno nemusí byť dobrý na druhé — preto sa testujú obe
// naraz a nad tým istým vstupom.
//
// Test beží NA SERVERI. Výsledky pribúdajú priebežne, takže sa dá odísť
// z obrazovky aj zavrieť prehliadač a po návrate sa pokračuje tam, kde to je.

const ULOHY = {
  parse:    { text: 'Ťaženie údajov', popis: 'Čo model vytiahol z inzerátu' },
  vhodnost: { text: 'Vhodnosť',       popis: 'Ako posúdil vhodnosť pre teba' },
};

export default function TestModelov() {
  const [dta, setDta]         = useState(null);      // zoznam behov
  const [detail, setDetail]   = useState(null);      // otvorený beh
  const [behId, setBehId]     = useState(null);
  const [chyba, setChyba]     = useState(null);
  const [sprava, setSprava]   = useState(null);
  const [pracuje, setPracuje] = useState(false);

  const [url1, setUrl1] = useState('');
  const [url2, setUrl2] = useState('');
  const [maxModelov, setMaxModelov] = useState(20);
  const [strop1m, setStrop1m]       = useState(1.0);
  const [rozpocet, setRozpocet]     = useState(1.0);

  const [ulohaTab, setUlohaTab] = useState('parse');
  const [inzeratTab, setInzeratTab] = useState(1);
  const [otvoreny, setOtvoreny] = useState(null);    // rozkliknutý riadok

  const casovacRef = useRef(null);

  const nacitatZoznam = useCallback(async () => {
    try {
      setDta(await api('/v1/admin/test-modelov'));
    } catch (e) { setChyba(e.message); }
  }, []);

  const nacitatDetail = useCallback(async (id) => {
    if (!id) return;
    try {
      setDetail(await api('/v1/admin/test-modelov?beh=' + id));
    } catch (e) { setChyba(e.message); }
  }, []);

  useEffect(() => { nacitatZoznam(); }, [nacitatZoznam]);
  useEffect(() => { nacitatDetail(behId); }, [behId, nacitatDetail]);

  // Kým test beží, výsledky sa dopĺňajú samy — inak by človek musel
  // klikať na Obnoviť, aby videl, či sa niečo deje.
  const bezi = detail?.beh?.status === 'running';
  useEffect(() => {
    if (!bezi) return;
    casovacRef.current = setInterval(() => {
      nacitatDetail(behId);
      nacitatZoznam();
    }, 4000);
    return () => clearInterval(casovacRef.current);
  }, [bezi, behId, nacitatDetail, nacitatZoznam]);

  useEffect(() => {
    if (!sprava) return;
    const t = setTimeout(() => setSprava(null), 8000);
    return () => clearTimeout(t);
  }, [sprava]);

  async function spustit() {
    if (!url1.trim()) { setChyba('Zadaj adresu prvého inzerátu'); return; }
    setPracuje(true);
    setChyba(null);
    try {
      const r = await api('/v1/admin/test-modelov?akcia=spustit', {
        method: 'POST',
        body: { url1: url1.trim(), url2: url2.trim(),
                max_modelov: Number(maxModelov),
                cenovy_strop_1m: Number(strop1m),
                rozpocet_usd: Number(rozpocet) },
      });
      setSprava(r.sprava + (r.ma_cv ? '' : ' — POZOR: životopis nie je nahraný'));
      setBehId(r.beh_id);
      await nacitatZoznam();
    } catch (e) { setChyba(e.message); }
    finally { setPracuje(false); }
  }

  async function zrusit(id) {
    setPracuje(true);
    try {
      const r = await api('/v1/admin/test-modelov?akcia=zrusit',
                          { method: 'POST', body: { beh_id: id } });
      setSprava(r.sprava);
      await nacitatDetail(id);
    } catch (e) { setChyba(e.message); }
    finally { setPracuje(false); }
  }

  const beh = detail?.beh;
  const vysledky = (detail?.vysledky ?? [])
    .filter(v => v.uloha === ulohaTab && Number(v.inzerat) === inzeratTab);

  return (
    <div className="tmod">
      <h1>Test modelov</h1>
      <p className="tm-popis">
        Porovná, ktorý model najlepšie vytiahne údaje z inzerátu a ako posúdi
        jeho vhodnosť. Modely sa skúšajú <strong>od najlacnejších</strong> —
        najprv bezplatné, potom platené po zadaný strop. Test beží na serveri,
        takže môžeš odísť z obrazovky.
      </p>

      {chyba  && <p className="tm-chyba">{chyba}</p>}
      {sprava && <p className="tm-sprava">{sprava}</p>}

      {/* --- zadanie testu --- */}
      <section className="tm-zadanie">
        <h2>Nový test</h2>

        <label>
          <span>Adresa 1. inzerátu</span>
          <input type="url" value={url1} onChange={e => setUrl1(e.target.value)}
                 placeholder="https://www.profesia.sk/praca/firma/O1234567"
                 disabled={pracuje} />
        </label>

        <label>
          <span>Adresa 2. inzerátu</span>
          <input type="url" value={url2} onChange={e => setUrl2(e.target.value)}
                 placeholder="https://www.profesia.sk/praca/firma/O7654321"
                 disabled={pracuje} />
        </label>

        <div className="tm-parametre">
          <label>
            <span>Max. modelov</span>
            <input type="number" min="1" max="200" value={maxModelov}
                   onChange={e => setMaxModelov(e.target.value)} disabled={pracuje} />
          </label>
          <label>
            <span>Cenový strop $/1M</span>
            <input type="number" min="0" max="50" step="0.1" value={strop1m}
                   onChange={e => setStrop1m(e.target.value)} disabled={pracuje} />
          </label>
          <label>
            <span>Zastaviť pri $</span>
            <input type="number" min="0.01" max="20" step="0.5" value={rozpocet}
                   onChange={e => setRozpocet(e.target.value)} disabled={pracuje} />
          </label>

          <button className="tm-spustit" onClick={spustit} disabled={pracuje}>
            {pracuje ? 'Spúšťam…' : 'Spustiť test'}
          </button>
        </div>

        {dta?.pocty && (
          <p className="tm-info">
            V číselníku je <strong>{dta.pocty.free}</strong> bezplatných
            a <strong>{dta.pocty.do_1usd}</strong> platených do 1 $/1M.
            Každý model urobí 2 volania na inzerát (ťaženie + vhodnosť).
          </p>
        )}
      </section>

      {/* --- zoznam behov --- */}
      {dta?.behy?.length > 0 && (
        <section>
          <h2>Behy</h2>
          <div className="tm-obal">
            <table className="tm-tabulka">
              <thead>
                <tr>
                  <th>#</th><th>Inzerát</th><th>Stav</th>
                  <th className="cislo">Volaní</th><th className="cislo">Tokenov</th>
                  <th className="cislo">Cena</th><th>Spustené</th><th />
                </tr>
              </thead>
              <tbody>
                {dta.behy.map(b => (
                  <tr key={b.id} className={(behId === b.id ? 'vybrany ' : '') + b.status}
                      onClick={() => setBehId(b.id)}>
                    <td>{b.id}</td>
                    <td className="tm-nazov" title={b.url1}>
                      {b.nazov1 || b.url1}
                      {b.nazov2 && <span className="tm-druhy"> + {b.nazov2}</span>}
                    </td>
                    <td>
                      <span className={'tm-stav ' + b.status}>{popisStavu(b.status)}</span>
                      {b.zastavene_dovod && (
                        <span className="tm-dovod" title={b.zastavene_dovod}>!</span>
                      )}
                    </td>
                    <td className="cislo">
                      {b.volani_ok}/{b.volani_spolu}
                    </td>
                    <td className="cislo">{Number(b.tokenov_spolu).toLocaleString('sk-SK')}</td>
                    <td className="cislo">
                      ${Number(b.cena_usd).toFixed(4)}
                      <span className="tm-zo"> / ${Number(b.rozpocet_usd).toFixed(2)}</span>
                    </td>
                    <td>{cas(b.started_at)}</td>
                    <td>
                      {b.status === 'running' && (
                        <button className="tm-zrusit"
                                onClick={e => { e.stopPropagation(); zrusit(b.id); }}
                                disabled={pracuje}>Zastaviť</button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      {/* --- výsledky vybraného behu --- */}
      {beh && (
        <section className="tm-vysledky">
          <h2>
            Test #{beh.id}
            {bezi && <span className="tm-bezi">prebieha…</span>}
            <span className="tm-suhrn">
              {beh.volani_ok}/{beh.volani_spolu} volaní ·
              ${Number(beh.cena_usd).toFixed(4)} z ${Number(beh.rozpocet_usd).toFixed(2)}
            </span>
          </h2>

          {beh.zastavene_dovod && (
            <p className="tm-zastavene">{beh.zastavene_dovod}</p>
          )}

          {/* Bez CV a preferencií nemá úloha vhodnosti z čoho hodnotiť. */}
          {(!beh.cv_text || !beh.prefs_text) && (
            <p className="tm-upozornenie">
              {!beh.cv_text && 'Životopis nie je nahraný. '}
              {!beh.prefs_text && 'Preferencie nie sú vyplnené. '}
              Výsledky úlohy Vhodnosť budú preto nepresné.
            </p>
          )}

          <div className="tm-prepinace">
            <div className="tm-skupina">
              {[1, 2].map(n => (
                (n === 1 || beh.url2) && (
                  <button key={n}
                          className={inzeratTab === n ? 'aktivny' : ''}
                          onClick={() => setInzeratTab(n)}>
                    {n}. inzerát
                  </button>
                )
              ))}
            </div>
            <div className="tm-skupina">
              {Object.entries(ULOHY).map(([kod, u]) => (
                <button key={kod}
                        className={ulohaTab === kod ? 'aktivny' : ''}
                        onClick={() => setUlohaTab(kod)}>{u.text}</button>
              ))}
            </div>
          </div>

          <p className="tm-popis-ulohy">{ULOHY[ulohaTab].popis}</p>

          {vysledky.length === 0 ? (
            <p className="tm-prazdne">
              {bezi ? 'Čaká sa na prvé výsledky…' : 'Pre túto kombináciu nič nie je.'}
            </p>
          ) : (
            <div className="tm-obal">
              <table className="tm-tabulka tm-siroka">
                <thead>
                  {ulohaTab === 'parse' ? (
                    <tr>
                      <th>Model</th><th className="cislo">$/1M</th>
                      <th>Názov</th><th>Firma</th><th className="cislo">Mzda</th>
                      <th>Mesto</th><th>Úväzok</th><th>Nástup</th>
                      <th className="cislo">Tok.</th><th className="cislo">Čas</th>
                      <th className="cislo">Cena</th>
                    </tr>
                  ) : (
                    <tr>
                      <th>Model</th><th className="cislo">$/1M</th>
                      <th className="cislo">Skóre</th><th>Zaradenie</th>
                      <th>Hodnotenie</th>
                      <th className="cislo">Tok.</th><th className="cislo">Čas</th>
                      <th className="cislo">Cena</th>
                    </tr>
                  )}
                </thead>
                <tbody>
                  {vysledky.map(v => (
                    <Riadok key={v.id} v={v} uloha={ulohaTab}
                            otvoreny={otvoreny === v.id}
                            prepni={() => setOtvoreny(otvoreny === v.id ? null : v.id)} />
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
// Jeden riadok výsledku + rozkliknutý detail
// ------------------------------------------------------------
function Riadok({ v, uloha, otvoreny, prepni }) {
  const zlyhal = v.status !== 'ok';
  const stlpcov = uloha === 'parse' ? 11 : 8;

  return (
    <>
      <tr className={zlyhal ? 'zle' : ''} onClick={prepni}>
        <td className="tm-model">
          {v.model_id.replace(':free', '')}
          {v.je_free && <span className="tm-free">zadarmo</span>}
        </td>
        <td className="cislo">{v.je_free ? '—' : Number(v.cena_1m).toFixed(3)}</td>

        {uloha === 'parse' ? (
          <>
            <td className="tm-text">{v.nazov || <span className="tm-nic">—</span>}</td>
            <td className="tm-text">{v.firma || <span className="tm-nic">—</span>}</td>
            <td className="cislo">{mzda(v)}</td>
            <td>{v.mesto || <span className="tm-nic">—</span>}</td>
            <td>{(v.uvazky?.length ? v.uvazky.join(', ') : v.uvazok) ||
                 <span className="tm-nic">—</span>}</td>
            <td>{v.nastup || <span className="tm-nic">—</span>}</td>
          </>
        ) : (
          <>
            <td className="cislo">
              {v.skore !== null
                ? <span className={'tm-skore ' + (v.zaradenie || '')}>{v.skore}</span>
                : <span className="tm-nic">—</span>}
            </td>
            <td>{v.zaradenie || <span className="tm-nic">—</span>}</td>
            <td className="tm-hodnotenie">
              {v.hodnotenie
                ? v.hodnotenie.slice(0, 90) + (v.hodnotenie.length > 90 ? '…' : '')
                : <span className="tm-chyba-text">{v.chyba || '—'}</span>}
            </td>
          </>
        )}

        <td className="cislo">{v.total_tokens || '—'}</td>
        <td className="cislo">{v.trvanie_ms ? (v.trvanie_ms / 1000).toFixed(1) + 's' : '—'}</td>
        <td className="cislo">
          {Number(v.cena_usd) > 0 ? '$' + Number(v.cena_usd).toFixed(6)
                                  : <span className="tm-zadarmo">0</span>}
        </td>
      </tr>

      {otvoreny && (
        <tr className="tm-detail-riadok">
          <td colSpan={stlpcov}>
            {zlyhal && <p className="tm-chyba-text">{v.chyba}</p>}

            {uloha === 'parse' ? (
              <div className="tm-detail">
                {v.sumar && <><h4>Súhrn</h4><p>{v.sumar}</p></>}
                <dl>
                  {v.datum_zverejnenia && <><dt>Zverejnené</dt><dd>{v.datum_zverejnenia}</dd></>}
                  {v.datum_zverejnenia_text && (
                    <><dt>Zverejnené (text)</dt><dd>{v.datum_zverejnenia_text}</dd></>
                  )}
                  {v.mzda_text && <><dt>Mzda (text)</dt><dd>{v.mzda_text}</dd></>}
                  {v.lokalita_zvysok && <><dt>Lokalita</dt><dd>{v.lokalita_zvysok}</dd></>}
                  {v.orig_lang && <><dt>Jazyk</dt><dd>{v.orig_lang}</dd></>}
                </dl>
                {v.ma_html && <p className="tm-nic">HTML inzerátu je uložené v databáze.</p>}
              </div>
            ) : (
              <div className="tm-detail">
                {v.hodnotenie && <><h4>Hodnotenie</h4><p>{v.hodnotenie}</p></>}
                {v.pre_argumenty && (
                  <><h4>Hovorí pre</h4>
                    <ul>{v.pre_argumenty.split('\n').map((x, i) => <li key={i}>{x}</li>)}</ul></>
                )}
                {v.proti_argumenty && (
                  <><h4>Hovorí proti</h4>
                    <ul>{v.proti_argumenty.split('\n').map((x, i) => <li key={i}>{x}</li>)}</ul></>
                )}
              </div>
            )}
          </td>
        </tr>
      )}
    </>
  );
}

// ------------------------------------------------------------
function mzda(v) {
  if (v.mzda_min === null && v.mzda_max === null) return v.mzda_text || '—';
  const j = { month: '/mes', hour: '/h', day: '/deň', year: '/rok' }[v.mzda_obdobie] || '';
  const n = x => Math.round(Number(x)).toLocaleString('sk-SK');
  if (v.mzda_min && v.mzda_max) return `${n(v.mzda_min)}–${n(v.mzda_max)}${j}`;
  if (v.mzda_min) return `od ${n(v.mzda_min)}${j}`;
  return `do ${n(v.mzda_max)}${j}`;
}

function popisStavu(s) {
  return { running: 'beží', done: 'hotovo', stopped_budget: 'rozpočet',
           cancelled: 'zastavené', failed: 'zlyhalo' }[s] ?? s;
}

function cas(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  const dnes = new Date();
  const t = d.toLocaleTimeString('sk-SK', { hour: '2-digit', minute: '2-digit' });
  return d.toDateString() === dnes.toDateString()
    ? t : d.toLocaleDateString('sk-SK', { day: 'numeric', month: 'numeric' }) + ' ' + t;
}
