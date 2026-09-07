import { useEffect, useState } from 'react';
import { api } from '../api';

// Preferencie ako volny text — hlavny vstup pre model pri posudzovani vhodnosti.
// Doplnkove ciselne polia sluzia len na rychle predfiltrovanie inzeratov.

const PRIKLAD =
  'Hľadám prácu v IT do 30 km od Dunajskej Lužnej, najlepšie na polovičný úväzok ' +
  'alebo z domu. Zaujíma ma vývoj v PHP alebo Pythone. Nechcem call centrum, ' +
  'prácu na zmeny ani pozície cez personálne agentúry.';

export default function Preferences() {
  const [text, setText]       = useState('');
  const [maxKm, setMaxKm]     = useState('');
  const [mzda, setMzda]       = useState('');
  const [chyba, setChyba]     = useState(null);
  const [hlaska, setHlaska]   = useState(null);
  const [caka, setCaka]       = useState(false);

  useEffect(() => { nacitat(); }, []);

  async function nacitat() {
    try {
      const r = await api('/v1/preferences');
      const p = r.preferences || {};
      setText(p.free_text ?? '');
      setMaxKm(p.max_distance_km ?? '');
      setMzda(p.salary_min ?? '');
    } catch (e) {
      setChyba(e.message);
    }
  }

  async function ulozit(e) {
    e.preventDefault();
    setChyba(null);
    setHlaska(null);
    setCaka(true);
    try {
      const r = await api('/v1/preferences', {
        method: 'PUT',
        body: {
          free_text: text,
          max_distance_km: maxKm === '' ? null : Number(maxKm),
          salary_min:      mzda  === '' ? null : Number(mzda),
        },
      });
      setHlaska(r.recompute_needed
        ? 'Uložené. Zmenil sa text preferencií, posudky sa prepočítajú.'
        : 'Uložené.');
    } catch (e) {
      setChyba(e.message);
    } finally {
      setCaka(false);
    }
  }

  return (
    <div className="page">
      <h1>Preferencie</h1>
      <p className="page-sub">
        Napíš vlastnými slovami, akú prácu hľadáš. Text dostane model spolu
        s tvojím životopisom a inzerátom — rozumie aj tomu, čo <em>nechceš</em>.
      </p>

      {chyba  && <div className="page-error">{chyba}</div>}
      {hlaska && <div className="page-ok">{hlaska}</div>}

      <form onSubmit={ulozit}>
        <label className="pref-text">
          <span>Čo hľadám</span>
          <textarea
            value={text}
            onChange={e => setText(e.target.value)}
            rows={10}
            maxLength={8000}
            placeholder={PRIKLAD}
          />
          <small className="page-muted">{text.length} / 8000 znakov</small>
        </label>

        <fieldset className="pref-doplnky">
          <legend>Doplnkové filtre</legend>
          <p className="page-muted">
            Nepovinné. Slúžia len na predfiltrovanie, aby sa modelu neposielali
            ponuky, ktoré zjavne nesedia.
          </p>

          <label>
            <span>Maximálna vzdialenosť (km)</span>
            <input type="number" min="0" max="500" value={maxKm}
                   onChange={e => setMaxKm(e.target.value)} placeholder="50" />
          </label>

          <label>
            <span>Minimálna mzda (€ / mesiac)</span>
            <input type="number" min="0" step="50" value={mzda}
                   onChange={e => setMzda(e.target.value)} placeholder="1500" />
          </label>
        </fieldset>

        <button type="submit" disabled={caka}>
          {caka ? 'Ukladám…' : 'Uložiť preferencie'}
        </button>
      </form>
    </div>
  );
}
