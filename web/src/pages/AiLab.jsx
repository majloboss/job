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

  const [loading, setLoading]   = useState(false);
  const [running, setRunning]   = useState(false);
  const [run, setRun]           = useState(null);
  const [results, setResults]   = useState([]);
  const [progress, setProgress] = useState({ done: 0, total: 0, current: null });
  const [error, setError]       = useState(null);
  const [expanded, setExpanded] = useState(null);
  const [zoznamOtvoreny, setZoznamOtvoreny] = useState(false);

  const cancelRef = useRef(false);

  useEffect(() => { loadModels(); loadDocs(); }, []);

  async function loadModels() {
    setLoading(true);
    setError(null);
    try {
      const r = await api('/v1/admin/ai-models');
      setModels(r.models);
      // Zaskrtnu sa len pouzitelne. Vyradene su tie, ktore uz raz vratili
      // trvalu chybu (napr. dostupne len agentickym nastrojom) — nema zmysel
      // cakat na ne pri kazdom behu.
      setSelected(new Set(r.models.filter(m => m.is_enabled).map(m => m.id)));
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
    setExpanded(null);
    setZoznamOtvoreny(false);
    setRunning(true);
    cancelRef.current = false;
    setProgress({ done: 0, total: chosen.length, current: null });

    try {
      const r = await api('/v1/admin/ai-lab', {
        method: 'POST',
        body: { url: url.trim(), models: chosen, document_id: docId ? Number(docId) : null },
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
        } catch (e) {
          setResults(prev => zorad([...prev, {
            model, ok: false, status: 'failed', error: e.message, score: null,
          }]));
        }
        setProgress(p => ({ ...p, done: p.done + 1 }));
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
      return (b.score ?? 0) - (a.score ?? 0);
    });
  }

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
        Zadaj adresu inzerátu a porovnaj, ako ho posúdia jednotlivé bezplatné modely.
        Podľa výsledku vyberieš ten, ktorý pôjde do produkcie.
      </p>

      {error && <div className="page-error">{error}</div>}

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
            <div><dt>Životopis</dt><dd>{run.has_cv ? 'áno' : 'nie je nahraný'}</dd></div>
            <div><dt>Preferencie</dt><dd>{run.has_prefs ? 'áno' : 'nie sú vyplnené'}</dd></div>
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
              {median !== null && <> · medián <strong>{median}</strong></>}
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
              const otvorena = expanded === r.model;
              return (
                <li key={r.model} className={r.ok ? 'ok' : 'zle'}>
                  <button
                    className="lab-karta-hlava"
                    onClick={() => setExpanded(otvorena ? null : r.model)}
                    aria-expanded={otvorena}
                  >
                    <span className="lab-karta-skore">
                      {r.score ?? '—'}
                      {odchylka !== null && (
                        <em className={Math.abs(odchylka) <= 5 ? 'blizko' : 'daleko'}>
                          {odchylka > 0 ? `+${odchylka}` : odchylka}
                        </em>
                      )}
                    </span>
                    <span className="lab-karta-text">
                      <code className="lab-karta-model">{r.model}</code>
                      <span className="lab-karta-stav">
                        {r.bucket
                          ? <span className={'lab-znacka ' + r.bucket}>{popisVhodnosti(r.bucket)}</span>
                          : <span className="lab-zlyhalo">{popisChyby(r.status)}</span>}
                        {r.ms && <span className="lab-karta-cas">{(r.ms / 1000).toFixed(1)} s</span>}
                      </span>
                    </span>
                    <span className={'lab-sipka' + (otvorena ? ' hore' : '')} aria-hidden="true" />
                  </button>

                  {r.summary && <p className="lab-karta-zhrnutie">{r.summary}</p>}
                  {!r.summary && r.error && <p className="lab-karta-chyba">{r.error}</p>}

                  {otvorena && <Detail r={r} />}
                </li>
              );
            })}
          </ul>

          {!running && results.length > 0 && (
            <p className="page-muted lab-vysvetlivka">
              Klikni na kartu pre detail. Odchýlka do 5 bodov od mediánu znamená,
              že model hodnotí ako ostatné; väčšia odchýlka, že hodnotí inak.
            </p>
          )}
        </section>
      )}
    </div>
  );
}

function Detail({ r }) {
  const p = r.parsed || {};
  return (
    <div className="lab-detail">
      <Zoznam nadpis="Hovorí pre"   polozky={r.pros} />
      <Zoznam nadpis="Hovorí proti" polozky={r.cons} />
      <Zoznam nadpis="Čo mi chýba"  polozky={r.missing_skills} />

      <div className="lab-detail-blok">
        <h4>Vyťažené z inzerátu</h4>
        <dl className="lab-vytazene">
          <div><dt>Profesia</dt><dd>{p.profession ?? '—'}</dd></div>
          <div><dt>Úväzok</dt><dd>{p.employment_type ?? '—'}</dd></div>
          <div><dt>Miesto</dt><dd>{(p.locations || []).join(', ') || '—'}</dd></div>
          <div><dt>Mzda</dt>
            <dd>{p.salary_min || p.salary_max
                  ? `${p.salary_min ?? '?'}–${p.salary_max ?? '?'} / ${p.salary_period ?? '?'}`
                  : '—'}</dd></div>
          <div><dt>Jazyky</dt>
            <dd>{(p.languages || []).map(l => `${l.code} ${l.level ?? ''}`).join(', ') || '—'}</dd></div>
          <div><dt>Agentúra</dt>
            <dd>{p.is_agency === true ? 'áno' : p.is_agency === false ? 'nie' : '—'}</dd></div>
        </dl>
      </div>
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
