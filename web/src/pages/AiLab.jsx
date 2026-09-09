import { useEffect, useState, useRef } from 'react';
import { api } from '../api';
import './AiLab.css';

// Laboratorium modelov: zadaj URL inzeratu, spusti posudenie vsetkymi
// bezplatnymi modelmi a porovnaj vysledky.
//
// Modely sa volaju POSTUPNE, nie naraz — bezplatne modely maju limit
// poziadaviek za minutu a paralelne volanie by skoncilo na 429.
//
// Vysledky su karty, nie tabulka: na telefone by sa sedem stlpcov
// nezmestilo a vodorovne posuvanie sa zle ovlada.

export default function AiLab() {
  const [models, setModels]     = useState([]);
  const [selected, setSelected] = useState(new Set());
  const [url, setUrl]           = useState('');
  const [docs, setDocs]         = useState([]);
  const [docId, setDocId]       = useState('');

  // Aplikacia ziskava udaje v dvoch krokoch a kazdy sa testuje inak:
  //   'parse' — vytiahne z inzeratu udaje; hodnoti sa ZHODA s ostatnymi
  //   'eval'  — posudi vhodnost pre usera; hodnoti sa odchylka od medianu
  const [ucel, setUcel]         = useState('parse');
  const [zhoda, setZhoda]       = useState(null);

  const [loading, setLoading]   = useState(false);
  const [running, setRunning]   = useState(false);
  const [run, setRun]           = useState(null);
  const [results, setResults]   = useState([]);
  const [progress, setProgress] = useState({ done: 0, total: 0, current: null });
  const [error, setError]       = useState(null);
  const [expanded, setExpanded] = useState(null);
  const [zoznamOtvoreny, setZoznamOtvoreny] = useState(false);

  const cancelRef = useRef(false);

  // Zoznam sa nacita znova pri prepnuti ucelu — statistika (zhoda,
  // uspesnost) sa pocita per ucel a ma sa zobrazit tá aktualna.
  useEffect(() => { loadModels(); }, [ucel]);   // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { loadDocs(); }, []);

  async function loadModels() {
    setLoading(true);
    setError(null);
    try {
      // Zoznam ide z ciselnika, nie zivo z OpenRoutera: ciselnik uz vie aj
      // ceny, doterajsiu uspesnost a zhodu, takze sa da rozhodnut, co testovat.
      const r = await api('/v1/admin/ai-ciselnik?ucel=' + ucel);

      // Do laboratoria patria bezplatne modely s dost velkym kontextom —
      // inzerat aj s promptom ma okolo 14 000 znakov a kratsi kontext by
      // odpoved orezal. Platene sa testuju az ciele­ne, nie hromadne.
      const vhodne = r.modely.filter(m =>
        m.is_free && (!m.context_length || m.context_length >= 16000));

      setModels(vhodne.map(m => ({
        id: m.model_id, dbId: m.id, name: m.name,
        context: m.context_length, is_enabled: m.pouzitelny,
        last_error: m.unavailable_reason,
        lab_runs: m.lab_runs, lab_ok: m.lab_ok,
        agree_rate: m.agree_rate,
      })));

      // Zaskrtnu sa len pouzitelne. Vyradene su tie, ktore uz raz vratili
      // trvalu chybu (napr. dostupne len agentickym nastrojom) — nema zmysel
      // cakat na ne pri kazdom behu.
      setSelected(new Set(vhodne.filter(m => m.pouzitelny).map(m => m.model_id)));
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }

  async function loadDocs() {
    try {
      const r = await api('/v1/documents');
      const cvs = r.documents.filter(d => d.doc_type === 'cv');
      setDocs(cvs);
      const primary = cvs.find(d => d.is_primary);
      if (primary) setDocId(String(primary.id));
    } catch { /* dokumenty su nepovinne */ }
  }

  function toggle(id) {
    setSelected(prev => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  }

  async function start() {
    const chosen = models.filter(m => selected.has(m.id)).map(m => m.id);
    if (!url.trim())    { setError('Zadaj URL inzerátu'); return; }
    if (!chosen.length) { setError('Vyber aspoň jeden model'); return; }

    setError(null);
    setResults([]);
    setRun(null);
    setZhoda(null);
    setExpanded(null);
    setZoznamOtvoreny(false);
    setRunning(true);
    cancelRef.current = false;
    setProgress({ done: 0, total: chosen.length, current: null });

    let hotovych = 0;
    try {
      const r = await api('/v1/admin/ai-lab', {
        method: 'POST',
        body: { url: url.trim(), models: chosen, ucel,
                document_id: docId ? Number(docId) : null },
      });
      setRun(r);

      for (const model of chosen) {
        if (cancelRef.current) break;
        setProgress(p => ({ ...p, current: model }));
        try {
          const one = await api('/v1/admin/ai-lab?step=1', {
            method: 'POST',
            body: { run_id: r.run_id, model },
          });
          setResults(prev => zorad([...prev, one.result]));
          hotovych++;
        } catch (e) {
          setResults(prev => zorad([...prev, {
            model, ok: false, status: 'failed', error: e.message, score: null,
          }]));
        }
        setProgress(p => ({ ...p, done: p.done + 1 }));
      }

      // Zhoda sa vyhodnocuje az na konci — pri priebeznom pocitani by prve
      // dva modely urcili "vacsinu" a ostatne by sa im prisposobovali.
      // Na vacsinu treba aspon troch.
      if (ucel === 'parse' && hotovych >= 3) {
        try {
          const z = await api('/v1/admin/ai-lab?zhoda=1',
                              { method: 'POST', body: { run_id: r.run_id } });
          setZhoda(z);
        } catch { /* zhoda je doplnok, jej zlyhanie nesmie zhodit beh */ }
      }
    } catch (e) {
      setError(e.message);
    } finally {
      setRunning(false);
      setProgress(p => ({ ...p, current: null }));
    }
  }

  function zorad(list) {
    return [...list].sort((a, b) => {
      if (a.ok !== b.ok) return a.ok ? -1 : 1;     // uspesne hore
      if (!a.ok) return 0;
      // Pri tazani udajov sa este neda radit podla zhody (vyhodnoti sa az
      // na konci), preto rozhoduje rychlost.
      if (ucel === 'parse') return (a.ms ?? 0) - (b.ms ?? 0);
      return (b.score ?? 0) - (a.score ?? 0);
    });
  }

  // Podiel zhody modelu, ked uz je vyhodnotena.
  const zhodaModelu = model => zhoda?.zhoda?.modely?.[model] ?? null;

  const uspesne = results.filter(r => r.ok);
  const skore = uspesne.map(r => r.score).filter(s => s !== null).sort((a, b) => a - b);
  const median = skore.length
    ? (skore.length % 2
        ? skore[(skore.length - 1) / 2]
        : Math.round((skore[skore.length / 2 - 1] + skore[skore.length / 2]) / 2))
    : null;

  return (
    <div className="page lab">
      <h1>Laboratórium modelov</h1>
      <p className="page-sub">
        {ucel === 'parse'
          ? 'Porovnaj, ako jednotlivé modely vyťažia údaje z toho istého inzerátu. '
          + 'Rozhoduje zhoda s ostatnými — model osamote proti väčšine si údaje pravdepodobne vymýšľa.'
          : 'Porovnaj, ako jednotlivé modely posúdia vhodnosť inzerátu. '
          + 'Rozhoduje odchýlka od mediánu.'}
        {' '}Víťaza zaradíš do poradia v sekcii Modely.
      </p>

      {error && <div className="page-error">{error}</div>}

      {/* Kazdy krok aplikacie moze bezat na inom modeli, preto sa aj testuje
          zvlast. Prepnutie meni prompt aj sposob vyhodnotenia. */}
      <div className="lab-ucely">
        <button type="button" disabled={running}
                className={ucel === 'parse' ? 'aktivny' : ''}
                onClick={() => setUcel('parse')}>
          Zber údajov
        </button>
        <button type="button" disabled={running}
                className={ucel === 'eval' ? 'aktivny' : ''}
                onClick={() => setUcel('eval')}>
          Vhodnosť pre používateľa
        </button>
      </div>

      <label>
        <span>URL inzerátu</span>
        <input
          type="url"
          inputMode="url"
          value={url}
          onChange={e => setUrl(e.target.value)}
          placeholder="https://www.profesia.sk/praca/firma/O1234567"
          disabled={running}
        />
      </label>

      {/* Pri tazani udajov sa CV nepouziva — model ma z inzeratu vytiahnut
          fakty, nie posudit, komu sa hodia. */}
      {ucel === 'eval' && (
        <label>
          <span>Životopis do posudku</span>
          <select value={docId} onChange={e => setDocId(e.target.value)} disabled={running}>
            <option value="">(bez životopisu)</option>
            {docs.map(d => (
              <option key={d.id} value={d.id}>
                {d.title}{d.is_primary ? ' — hlavný' : ''}
              </option>
            ))}
          </select>
        </label>
      )}

      {/* Zoznam modelov je na mobile zbalený — býva ich aj 30. */}
      <section className="lab-modely">
        <button
          type="button"
          className="lab-modely-prepinac"
          onClick={() => setZoznamOtvoreny(o => !o)}
          aria-expanded={zoznamOtvoreny}
        >
          <span>Bezplatné modely</span>
          <span className="lab-pocet">
            {selected.size} / {models.filter(m => m.is_enabled).length}
            {models.some(m => !m.is_enabled) &&
              ` · ${models.filter(m => !m.is_enabled).length} nepoužiteľných`}
          </span>
          <span className={'lab-sipka' + (zoznamOtvoreny ? ' hore' : '')} aria-hidden="true" />
        </button>

        {zoznamOtvoreny && (
          <div className="lab-modely-obsah">
            <div className="lab-akcie">
              <button type="button"
                      onClick={() => setSelected(new Set(models.filter(m => m.is_enabled).map(m => m.id)))}
                      disabled={running}>Použiteľné</button>
              <button type="button" onClick={() => setSelected(new Set())}
                      disabled={running}>Žiadny</button>
              <button type="button" onClick={loadModels} disabled={running || loading}>
                {loading ? 'Načítavam…' : 'Obnoviť'}
              </button>
            </div>

            {loading && <p className="page-muted">Načítavam zoznam z OpenRoutera…</p>}

            <ul className="lab-zoznam">
              {models.map(m => (
                <li key={m.id} className={m.is_enabled ? '' : 'vyradeny'}>
                  <label>
                    <input type="checkbox" checked={selected.has(m.id)}
                           onChange={() => toggle(m.id)} disabled={running} />
                    <span className="lab-model-info">
                      <span className="lab-model-nazov">{m.name}</span>
                      <span className="lab-model-id">
                        {m.is_enabled ? m.id : (m.last_error || 'Nepoužiteľný')}
                      </span>
                    </span>
                    {m.lab_runs > 0 && (
                      <span className="lab-model-stat" title="úspešné behy / celkom">
                        {m.lab_ok}/{m.lab_runs}
                      </span>
                    )}
                  </label>
                </li>
              ))}
            </ul>
          </div>
        )}
      </section>

      <div className="lab-spustenie">
        <button className="lab-start" onClick={start} disabled={running || !models.length}>
          {running ? 'Prebieha posudzovanie…' : `Spustiť posúdenie (${selected.size})`}
        </button>
        {running && (
          <button className="lab-zastavit" onClick={() => { cancelRef.current = true; }}>
            Zastaviť
          </button>
        )}
      </div>

      {run && (
        <section className="lab-vstup">
          <h2>Vstup pre modely</h2>
          <dl>
            <div><dt>Inzerát</dt><dd>{run.title || '(bez názvu)'}</dd></div>
            <div><dt>Dĺžka</dt>
              <dd>{run.input_chars.toLocaleString('sk')} znakov
                  {run.truncated && <em className="lab-upozornenie"> — skrátené</em>}</dd></div>
            {ucel === 'eval' && (
              <>
                <div><dt>Životopis</dt><dd>{run.has_cv ? 'áno' : 'nie je nahraný'}</dd></div>
                <div><dt>Preferencie</dt><dd>{run.has_prefs ? 'áno' : 'nie sú vyplnené'}</dd></div>
              </>
            )}
          </dl>
          <details>
            <summary>Ukážka textu pre model</summary>
            <pre className="lab-nahlad">{run.preview}</pre>
          </details>
        </section>
      )}

      {(running || results.length > 0) && (
        <section className="lab-vysledky">
          <div className="lab-vysledky-hlavicka">
            <h2>Výsledky</h2>
            <span className="lab-postup">
              {progress.done} / {progress.total}
              {ucel === 'eval' && median !== null && <> · medián <strong>{median}</strong></>}
              {ucel === 'parse' && zhoda?.prehlad && (
                <> · zhodných <strong>{zhoda.prehlad.zhodnych}</strong> z {zhoda.prehlad.modelov}</>
              )}
            </span>
          </div>

          {progress.current && (
            <p className="lab-prave-bezi">
              práve beží <code>{progress.current}</code>
            </p>
          )}

          <ul className="lab-karty">
            {results.map(r => {
              const odchylka = (median !== null && r.score !== null) ? r.score - median : null;
              const zhodaR   = zhodaModelu(r.model);
              const otvorena = expanded === r.model;
              return (
                <li key={r.model} className={r.ok ? 'ok' : 'zle'}>
                  <button
                    className="lab-karta-hlava"
                    onClick={() => setExpanded(otvorena ? null : r.model)}
                    aria-expanded={otvorena}
                  >
                    {/* Pri tazani udajov je hlavnym cislom podiel zhody
                        s ostatnymi, pri posudzovani skore. */}
                    <span className="lab-karta-skore">
                      {ucel === 'parse'
                        ? (zhodaR !== null ? Math.round(zhodaR) + '%' : (r.ok ? '·' : '—'))
                        : (r.score ?? '—')}
                      {ucel === 'eval' && odchylka !== null && (
                        <em className={Math.abs(odchylka) <= 5 ? 'blizko' : 'daleko'}>
                          {odchylka > 0 ? `+${odchylka}` : odchylka}
                        </em>
                      )}
                      {ucel === 'parse' && zhodaR !== null && (
                        <em className={zhodaR >= 70 ? 'blizko' : 'daleko'}>
                          {zhodaR >= 70 ? 'zhoda' : 'líši sa'}
                        </em>
                      )}
                    </span>
                    <span className="lab-karta-text">
                      <code className="lab-karta-model">{r.model}</code>
                      <span className="lab-karta-stav">
                        {ucel === 'parse'
                          ? (r.ok
                              ? <span className="lab-znacka vhodne">
                                  {r.parsed?.title
                                    ? skrat(r.parsed.title, 40) : 'vyťažené'}
                                </span>
                              : <span className="lab-zlyhalo">{popisChyby(r.status)}</span>)
                          : (r.bucket
                              ? <span className={'lab-znacka ' + r.bucket}>{popisVhodnosti(r.bucket)}</span>
                              : <span className="lab-zlyhalo">{popisChyby(r.status)}</span>)}
                        {r.ms && <span className="lab-karta-cas">{(r.ms / 1000).toFixed(1)} s</span>}
                      </span>
                    </span>
                    <span className={'lab-sipka' + (otvorena ? ' hore' : '')} aria-hidden="true" />
                  </button>

                  {r.summary && <p className="lab-karta-zhrnutie">{r.summary}</p>}
                  {!r.summary && r.error && <p className="lab-karta-chyba">{r.error}</p>}

                  {otvorena && <Detail r={r} ucel={ucel} />}
                </li>
              );
            })}
          </ul>

          {!running && results.length > 0 && (
            <>
              {ucel === 'parse' && zhoda?.prehlad && <Zhoda prehlad={zhoda.prehlad} />}

              <p className="page-muted lab-vysvetlivka">
                {ucel === 'parse'
                  ? 'Klikni na kartu pre vyťažené údaje. Zhoda je podiel polí, '
                  + 'v ktorých sa model trafil s väčšinou; od 70 % sa berie ako zhodný.'
                  : 'Klikni na kartu pre detail. Odchýlka do 5 bodov od mediánu znamená, '
                  + 'že model hodnotí ako ostatné; väčšia odchýlka, že hodnotí inak.'}
              </p>
              <Naklady runId={run?.run_id} />
            </>
          )}
        </section>
      )}
    </div>
  );
}

// Kolko tokenov posudenie minulo a co by to stalo na platenych modeloch.
// Nacita sa az na poziadanie — je to dalsie volanie do OpenRoutera.
function Naklady({ runId }) {
  const [data, setData]   = useState(null);
  const [caka, setCaka]   = useState(false);
  const [chyba, setChyba] = useState(null);

  async function nacitat() {
    setCaka(true);
    setChyba(null);
    try {
      setData(await api('/v1/admin/ai-naklady' + (runId ? `?run_id=${runId}` : '')));
    } catch (e) {
      setChyba(e.message);
    } finally {
      setCaka(false);
    }
  }

  if (!data) {
    return (
      <div className="lab-naklady">
        <button type="button" className="lab-naklady-tlacidlo" onClick={nacitat} disabled={caka}>
          {caka ? 'Počítam…' : 'Koľko by to stálo na platených modeloch?'}
        </button>
        {chyba && <p className="lab-karta-chyba">{chyba}</p>}
      </div>
    );
  }

  const s = data.spotreba;
  return (
    <div className="lab-naklady">
      <h3>Náklady na jedno posúdenie</h3>
      <p className="page-muted">
        Spotreba: <strong>{s.vstup_tokenov.toLocaleString('sk')}</strong> tokenov na vstupe
        {' + '}<strong>{s.vystup_tokenov.toLocaleString('sk')}</strong> na výstupe
        {' '}({s.zdroj}, {s.meranych} meraní)
      </p>

      <div className="lab-naklady-tabulka">
        <table>
          <thead>
            <tr>
              <th>Model</th>
              <th className="num">1 posudok</th>
              <th className="num">100</th>
              <th className="num">1 000</th>
            </tr>
          </thead>
          <tbody>
            {data.modely.map(m => (
              <tr key={m.model}>
                <td><code>{m.model}</code></td>
                <td className="num">${m.za_posudok.toFixed(5)}</td>
                <td className="num">${m.za_100.toFixed(2)}</td>
                <td className="num">${m.za_1000.toFixed(2)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="page-muted lab-vysvetlivka">
        Ceny v USD za jeden inzerát a jedného používateľa. Bezplatné modely stoja 0,
        majú však limity požiadaviek a ich dostupnosť sa mení.
      </p>
    </div>
  );
}

function Detail({ r, ucel }) {
  const p = r.parsed || {};
  const jeParse = ucel === 'parse';

  return (
    <div className="lab-detail">
      {/* Pri tazani udajov ziadne pre/proti nie su — model nehodnoti. */}
      {!jeParse && (
        <>
          <Zoznam nadpis="Hovorí pre"   polozky={r.pros} />
          <Zoznam nadpis="Hovorí proti" polozky={r.cons} />
          <Zoznam nadpis="Čo mi chýba"  polozky={r.missing_skills} />
        </>
      )}

      <div className="lab-detail-blok">
        <h4>Vyťažené z inzerátu</h4>
        <dl className="lab-vytazene">
          {jeParse && <div><dt>Názov</dt><dd>{p.title ?? '—'}</dd></div>}
          {jeParse && <div><dt>Firma</dt><dd>{p.company_name ?? '—'}</dd></div>}
          <div><dt>Profesia</dt><dd>{p.profession ?? '—'}</dd></div>
          {jeParse && <div><dt>Odvetvie</dt><dd>{p.industry ?? '—'}</dd></div>}
          <div><dt>Úväzok</dt><dd>{p.employment_type ?? '—'}</dd></div>
          <div><dt>Miesto</dt><dd>{(p.locations || []).join(', ') || '—'}</dd></div>
          <div><dt>Mzda</dt>
            <dd>{p.salary_min || p.salary_max
                  ? `${p.salary_min ?? '?'}–${p.salary_max ?? '?'} / ${p.salary_period ?? '?'}`
                  : (p.salary_raw ?? '—')}</dd></div>
          <div><dt>Jazyky</dt>
            <dd>{(p.languages || []).map(l => `${l.code} ${l.level ?? ''}`).join(', ') || '—'}</dd></div>
          <div><dt>Agentúra</dt>
            <dd>{p.is_agency === true ? 'áno' : p.is_agency === false ? 'nie' : '—'}</dd></div>
          {jeParse && (
            <>
              <div><dt>Zverejnené</dt>
                <dd>{p.published_at ?? p.published_at_raw ?? '—'}</dd></div>
              <div><dt>Jazyk</dt><dd>{p.orig_lang ?? '—'}</dd></div>
              <div><dt>Režim</dt><dd>{p.remote_type ?? '—'}</dd></div>
            </>
          )}
        </dl>
      </div>

      {jeParse && (
        <>
          <Zoznam nadpis="Kľúčové slová" polozky={p.keywords} />
          <Zoznam nadpis="Technológie"   polozky={p.technologies} />

          {/* Preklad sa vypĺňa iba pri cudzojazyčnom inzeráte. */}
          {p.text_sk && (
            <div className="lab-detail-blok">
              <h4>Preklad do slovenčiny</h4>
              <pre className="lab-nahlad">{p.text_sk}</pre>
            </div>
          )}
        </>
      )}
    </div>
  );
}

function Zoznam({ nadpis, polozky }) {
  return (
    <div className="lab-detail-blok">
      <h4>{nadpis}</h4>
      {polozky?.length
        ? <ul>{polozky.map((x, i) => <li key={i}>{x}</li>)}</ul>
        : <p className="page-muted">—</p>}
    </div>
  );
}

// ------------------------------------------------------------
// Zhoda modelov po poliach.
//
// Najuzitocnejsia cast testu: ukaze, na com presne sa modely rozchadzaju.
// Ked sa vsetky zhodnu na nazve a rozidu na mzde, chyba je v prompte;
// ked sa jeden lisi vo vsetkom, je nepouzitelny.
// ------------------------------------------------------------
const POPIS_POLA = {
  title: 'Názov pozície', salary_min: 'Mzda od', salary_max: 'Mzda do',
  salary_period: 'Obdobie mzdy', company_name: 'Firma', employment_type: 'Úväzok',
  is_agency: 'Agentúra', remote_type: 'Režim', seniority: 'Úroveň',
  orig_lang: 'Jazyk', locations: 'Miesto', positions_count: 'Počet miest',
  industry: 'Odvetvie', technologies: 'Technológie',
};

function Zhoda({ prehlad }) {
  // Zaujimave su len polia, na ktorych sa modely nezhodli — pole, kde
  // odpovedali vsetci rovnako, netreba riesit.
  const rozporne = Object.entries(prehlad.polia).filter(([, p]) => p.rozporne);

  return (
    <section className="lab-zhoda">
      <h3>
        Zhoda modelov
        <span className="page-muted">
          {' '}{prehlad.zhodnych} z {prehlad.modelov} sa zhodlo s väčšinou
        </span>
      </h3>

      {rozporne.length === 0 ? (
        <p className="page-muted">
          Všetky modely vrátili rovnaké údaje — inzerát je jednoznačný.
        </p>
      ) : (
        <table className="lab-zhoda-tabulka">
          <thead>
            <tr><th>Pole</th><th>Čo modely vrátili</th></tr>
          </thead>
          <tbody>
            {rozporne.map(([pole, p]) => (
              <tr key={pole}>
                <td>{POPIS_POLA[pole] ?? pole}</td>
                <td>
                  {Object.entries(p.hodnoty).map(([h, pocet], i) => (
                    <span key={h} className={'lab-hodnota' + (i === 0 ? ' vacsina' : '')}>
                      {skrat(h, 40)} <em>{pocet}×</em>
                    </span>
                  ))}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}

function skrat(s, n) {
  const t = String(s ?? '');
  return t.length > n ? t.slice(0, n - 1) + '…' : t;
}

function popisVhodnosti(b) {
  return { vhodne: 'Vhodné', menej_vhodne: 'Menej vhodné', nevhodne: 'Nevhodné' }[b] ?? b;
}

function popisChyby(status) {
  return {
    failed: 'Zlyhalo',
    rate_limited: 'Limit požiadaviek',
    invalid_json: 'Neplatný JSON',
    truncated: 'Orezaná odpoveď',
  }[status] ?? 'Chyba';
}
