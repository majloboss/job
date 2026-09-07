import { useEffect, useState, useRef, Fragment } from 'react';
import { api } from '../api';
import './AiLab.css';

// Laboratorium modelov: zadaj URL inzeratu, spusti posudenie vsetkymi
// bezplatnymi modelmi a porovnaj vysledky.
//
// Modely sa volaju POSTUPNE, nie naraz — bezplatne modely maju limit
// poziadaviek za minutu a paralelne volanie by skoncilo na 429.

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

  const cancelRef = useRef(false);

  useEffect(() => { loadModels(); loadDocs(); }, []);

  async function loadModels() {
    setLoading(true);
    setError(null);
    try {
      const r = await api('/v1/admin/ai-models');
      setModels(r.models);
      // predvolene zaskrtni vsetky - to je zmysel porovnania
      setSelected(new Set(r.models.map(m => m.id)));
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
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  }

  async function start() {
    const chosen = models.filter(m => selected.has(m.id)).map(m => m.id);
    if (!url.trim())  { setError('Zadaj URL inzerátu'); return; }
    if (!chosen.length) { setError('Vyber aspoň jeden model'); return; }

    setError(null);
    setResults([]);
    setRun(null);
    setExpanded(null);
    setRunning(true);
    cancelRef.current = false;
    setProgress({ done: 0, total: chosen.length, current: null });

    try {
      // 1. zaloz beh - stiahne inzerat a pripravi prompt
      const r = await api('/v1/admin/ai-lab', {
        method: 'POST',
        body: { url: url.trim(), models: chosen, document_id: docId ? Number(docId) : null },
      });
      setRun(r);

      // 2. otestuj modely po jednom
      for (const model of chosen) {
        if (cancelRef.current) break;
        setProgress(p => ({ ...p, current: model }));
        try {
          const one = await api('/v1/admin/ai-lab?step=1', {
            method: 'POST',
            body: { run_id: r.run_id, model },
          });
          setResults(prev => sortResults([...prev, one.result]));
        } catch (e) {
          setResults(prev => sortResults([...prev, {
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

  function sortResults(list) {
    return [...list].sort((a, b) => {
      if (a.ok !== b.ok) return a.ok ? -1 : 1;      // uspesne hore
      if (!a.ok) return 0;
      return (b.score ?? 0) - (a.score ?? 0);       // potom podla skore
    });
  }

  const ok = results.filter(r => r.ok);
  const scores = ok.map(r => r.score).filter(s => s !== null).sort((a, b) => a - b);
  const median = scores.length
    ? (scores.length % 2
        ? scores[(scores.length - 1) / 2]
        : Math.round((scores[scores.length / 2 - 1] + scores[scores.length / 2]) / 2))
    : null;

  return (
    <div className="lab">
      <header className="lab-head">
        <h1>Laboratórium modelov</h1>
        <p className="lab-sub">
          Zadaj adresu inzerátu a porovnaj, ako ho posúdia jednotlivé bezplatné modely.
          Podľa výsledku vyberieš ten, ktorý pôjde do produkcie.
        </p>
      </header>

      {error && <div className="lab-error">{error}</div>}

      <section className="lab-form">
        <label className="lab-field">
          <span>URL inzerátu</span>
          <input
            type="url"
            value={url}
            onChange={e => setUrl(e.target.value)}
            placeholder="https://www.profesia.sk/praca/firma/O1234567"
            disabled={running}
          />
        </label>

        <label className="lab-field lab-field-narrow">
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
      </section>

      <section className="lab-models">
        <div className="lab-models-head">
          <h2>
            Bezplatné modely
            <span className="lab-count">
              {selected.size} / {models.length} vybraných
            </span>
          </h2>
          <div className="lab-actions">
            <button type="button" onClick={() => setSelected(new Set(models.map(m => m.id)))}
                    disabled={running}>Všetky</button>
            <button type="button" onClick={() => setSelected(new Set())}
                    disabled={running}>Žiadny</button>
            <button type="button" onClick={loadModels} disabled={running || loading}>
              {loading ? 'Načítavam…' : 'Obnoviť zoznam'}
            </button>
          </div>
        </div>

        {loading && <p className="lab-muted">Načítavam zoznam modelov z OpenRoutera…</p>}

        <ul className="lab-model-list">
          {models.map(m => (
            <li key={m.id}>
              <label>
                <input type="checkbox" checked={selected.has(m.id)}
                       onChange={() => toggle(m.id)} disabled={running} />
                <span className="lab-model-name">{m.name}</span>
                <span className="lab-model-id">{m.id}</span>
                {m.context && <span className="lab-model-ctx">{(m.context / 1000).toFixed(0)}k</span>}
                {m.lab_runs > 0 && (
                  <span className="lab-model-stat" title="úspešné behy / celkom">
                    {m.lab_ok}/{m.lab_runs}
                    {m.avg_ms ? ` · ${(m.avg_ms / 1000).toFixed(1)}s` : ''}
                  </span>
                )}
              </label>
            </li>
          ))}
        </ul>
      </section>

      <div className="lab-run">
        <button className="lab-start" onClick={start} disabled={running || !models.length}>
          {running ? 'Prebieha posudzovanie…' : `Spustiť posúdenie (${selected.size})`}
        </button>
        {running && (
          <button className="lab-cancel" onClick={() => { cancelRef.current = true; }}>
            Zastaviť
          </button>
        )}
      </div>

      {run && (
        <section className="lab-input">
          <h2>Vstup pre modely</h2>
          <dl>
            <div><dt>Inzerát</dt><dd>{run.title || '(bez názvu)'}</dd></div>
            <div><dt>Dĺžka textu</dt>
              <dd>{run.input_chars.toLocaleString('sk')} znakov
                  {run.truncated && <em className="lab-warn"> — skrátené pre modely</em>}</dd></div>
            <div><dt>Životopis</dt><dd>{run.has_cv ? 'áno' : 'nie je nahraný'}</dd></div>
            <div><dt>Preferencie</dt><dd>{run.has_prefs ? 'áno' : 'nie sú vyplnené'}</dd></div>
          </dl>
          <details>
            <summary>Ukážka textu, ktorý ide do modelu</summary>
            <pre className="lab-preview">{run.preview}</pre>
          </details>
        </section>
      )}

      {(running || results.length > 0) && (
        <section className="lab-results">
          <div className="lab-results-head">
            <h2>Výsledky</h2>
            <span className="lab-progress">
              {progress.done} / {progress.total}
              {progress.current && <> · práve beží <code>{progress.current}</code></>}
            </span>
            {median !== null && (
              <span className="lab-median">Medián skóre: <strong>{median}</strong></span>
            )}
          </div>

          <table className="lab-table">
            <thead>
              <tr>
                <th>Model</th>
                <th className="num">Skóre</th>
                <th className="num">Odchýlka</th>
                <th>Vhodnosť</th>
                <th>Zhrnutie</th>
                <th className="num">Tokeny</th>
                <th className="num">Čas</th>
              </tr>
            </thead>
            <tbody>
              {results.map(r => {
                const diff = (median !== null && r.score !== null) ? r.score - median : null;
                return (
                  <Fragment key={r.model}>
                    <tr className={r.ok ? 'ok' : 'bad'}
                        onClick={() => setExpanded(expanded === r.model ? null : r.model)}>
                      <td className="lab-td-model">
                        <code>{r.model}</code>
                      </td>
                      <td className="num">{r.score ?? '—'}</td>
                      <td className={'num ' + (diff === null ? '' : Math.abs(diff) <= 5 ? 'near' : 'far')}>
                        {diff === null ? '—' : (diff > 0 ? `+${diff}` : diff)}
                      </td>
                      <td>
                        {r.bucket
                          ? <span className={'lab-bucket ' + r.bucket}>{bucketLabel(r.bucket)}</span>
                          : <span className="lab-fail">{failLabel(r.status)}</span>}
                      </td>
                      <td className="lab-td-summary">{r.summary || r.error || '—'}</td>
                      <td className="num">{r.tokens ?? '—'}</td>
                      <td className="num">{r.ms ? `${(r.ms / 1000).toFixed(1)}s` : '—'}</td>
                    </tr>
                    {expanded === r.model && (
                      <tr className="lab-detail">
                        <td colSpan={7}>
                          <Detail r={r} />
                        </td>
                      </tr>
                    )}
                  </Fragment>
                );
              })}
            </tbody>
          </table>

          {!running && results.length > 0 && (
            <p className="lab-muted">
              Klikni na riadok pre detail. Modely s odchýlkou do 5 bodov od mediánu
              sa zhodujú s ostatnými; veľká odchýlka znamená, že model hodnotí inak.
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
    <div className="lab-detail-grid">
      <div>
        <h4>Hovorí pre</h4>
        <ul>{(r.pros || []).map((x, i) => <li key={i}>{x}</li>)}</ul>
        {!r.pros?.length && <p className="lab-muted">—</p>}
      </div>
      <div>
        <h4>Hovorí proti</h4>
        <ul>{(r.cons || []).map((x, i) => <li key={i}>{x}</li>)}</ul>
        {!r.cons?.length && <p className="lab-muted">—</p>}
      </div>
      <div>
        <h4>Čo mi chýba</h4>
        <ul>{(r.missing_skills || []).map((x, i) => <li key={i}>{x}</li>)}</ul>
        {!r.missing_skills?.length && <p className="lab-muted">—</p>}
      </div>
      <div>
        <h4>Vyťažené z inzerátu</h4>
        <dl className="lab-parsed">
          <div><dt>Profesia</dt><dd>{p.profession ?? '—'}</dd></div>
          <div><dt>Úväzok</dt><dd>{p.employment_type ?? '—'}</dd></div>
          <div><dt>Miesto</dt><dd>{(p.locations || []).join(', ') || '—'}</dd></div>
          <div><dt>Mzda</dt>
            <dd>{p.salary_min || p.salary_max
                  ? `${p.salary_min ?? '?'}–${p.salary_max ?? '?'} / ${p.salary_period ?? '?'}`
                  : '—'}</dd></div>
          <div><dt>Jazyky</dt>
            <dd>{(p.languages || []).map(l => `${l.code} ${l.level ?? ''}`).join(', ') || '—'}</dd></div>
          <div><dt>Agentúra</dt><dd>{p.is_agency === true ? 'áno' : p.is_agency === false ? 'nie' : '—'}</dd></div>
        </dl>
      </div>
    </div>
  );
}

function bucketLabel(b) {
  return { vhodne: 'Vhodné', menej_vhodne: 'Menej vhodné', nevhodne: 'Nevhodné' }[b] ?? b;
}

function failLabel(status) {
  return {
    failed: 'Zlyhalo', rate_limited: 'Limit požiadaviek',
    invalid_json: 'Neplatný JSON', truncated: 'Orezaná odpoveď',
  }[status] ?? 'Chyba';
}
