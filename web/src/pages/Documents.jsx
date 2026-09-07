import { useEffect, useState, useRef } from 'react';
import { api } from '../api';

// Zivotopis a dalsie dokumenty. Text sa z nich vytazi pri nahrati a ide
// do promptu pri posudzovani vhodnosti inzeratu.

const TYPY = {
  cv:           'Životopis',
  cover_letter: 'Motivačný list',
  certificate:  'Certifikát',
  reference:    'Referencia',
  portfolio:    'Portfólio',
  other:        'Iné',
};

export default function Documents() {
  const [docs, setDocs]   = useState([]);
  const [chyba, setChyba] = useState(null);
  const [caka, setCaka]   = useState(false);
  const [typ, setTyp]     = useState('cv');
  const subor = useRef(null);

  useEffect(() => { nacitat(); }, []);

  async function nacitat() {
    try {
      const r = await api('/v1/documents');
      setDocs(r.documents);
    } catch (e) {
      setChyba(e.message);
    }
  }

  async function nahrat(e) {
    e.preventDefault();
    const file = subor.current?.files?.[0];
    if (!file) { setChyba('Vyber súbor'); return; }

    setChyba(null);
    setCaka(true);
    try {
      const fd = new FormData();
      fd.append('file', file);
      fd.append('doc_type', typ);
      const r = await api('/v1/documents', { method: 'POST', raw: fd });
      if (r.extract_error) {
        setChyba(`Dokument je uložený, ale text sa nevyťažil: ${r.extract_error}`);
      }
      subor.current.value = '';
      await nacitat();
    } catch (e) {
      setChyba(e.message);
    } finally {
      setCaka(false);
    }
  }

  async function zmazat(id) {
    if (!confirm('Naozaj zmazať tento dokument?')) return;
    try {
      await api(`/v1/documents?id=${id}`, { method: 'DELETE' });
      await nacitat();
    } catch (e) {
      setChyba(e.message);
    }
  }

  async function nastavitHlavny(id) {
    try {
      await api('/v1/documents', { method: 'PATCH', body: { id, is_primary: true } });
      await nacitat();
    } catch (e) {
      setChyba(e.message);
    }
  }

  return (
    <div className="page">
      <h1>Dokumenty</h1>
      <p className="page-sub">
        Nahraj životopis — jeho text sa použije pri posudzovaní vhodnosti inzerátov.
        Podporované: PDF, DOCX, DOC, ODT, RTF, TXT, JPG, PNG (max 10 MB).
      </p>

      {chyba && <div className="page-error">{chyba}</div>}

      <form className="doc-upload" onSubmit={nahrat}>
        <select value={typ} onChange={e => setTyp(e.target.value)}>
          {Object.entries(TYPY).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>
        <input type="file" ref={subor}
               accept=".pdf,.docx,.doc,.odt,.rtf,.txt,.jpg,.jpeg,.png" />
        <button type="submit" disabled={caka}>
          {caka ? 'Nahrávam…' : 'Nahrať'}
        </button>
      </form>

      {docs.length === 0 && <p className="page-muted">Zatiaľ žiadne dokumenty.</p>}

      <ul className="doc-list">
        {docs.map(d => (
          <li key={d.id} className={d.is_primary ? 'hlavny' : ''}>
            <div className="doc-main">
              <strong>{d.title}</strong>
              <span className="doc-typ">{TYPY[d.doc_type] ?? d.doc_type}</span>
              {d.is_primary && <span className="doc-badge">hlavný</span>}
            </div>
            <div className="doc-meta">
              {d.original_name} · {(d.size_bytes / 1024).toFixed(0)} kB
              {d.text_chars
                ? ` · text ${Number(d.text_chars).toLocaleString('sk')} znakov`
                : ' · text sa nevyťažil'}
              {d.extract_error && <span className="doc-warn"> — {d.extract_error}</span>}
            </div>
            <div className="doc-akcie">
              {!d.is_primary && (
                <button onClick={() => nastavitHlavny(d.id)}>Nastaviť ako hlavný</button>
              )}
              <button className="zmazat" onClick={() => zmazat(d.id)}>Zmazať</button>
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
}
