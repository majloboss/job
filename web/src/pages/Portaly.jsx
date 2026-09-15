import { Fragment, useEffect, useState, useCallback } from 'react';
import { api } from '../api';
import './Portaly.css';

// Číselník portálov: názov, adresy zberu, príznak aktívneho zberu.
//
// Portál môže mať VIAC východzích adries. profesia.sk nedáva všetky ponuky
// na jednom zozname — každý kraj má vlastnú adresu a zber ich prejde po
// kolách. Preto sa adresy zadávajú ako text, riadok = jedna adresa.
//
// Počty zozbieraných ponúk sú pri každom portáli zámerne: bez nich sa nedá
// rozoznať nenastavený portál od pokazeného.

const PRAZDNY = {
  code: '', name: '', adresy: '', is_active: true, je_agentura: false,
  ponuk_na_stranu: '', request_delay_ms: 1500, popis: '',
};

export default function Portaly() {
  const [dta, setDta]           = useState(null);
  const [nacitava, setNacitava] = useState(true);
  const [chyba, setChyba]       = useState(null);
  const [sprava, setSprava]     = useState(null);
  const [pracuje, setPracuje]   = useState(false);

  // id práve upravovaného portálu, 'novy' pri zakladaní
  const [upravovany, setUpravovany] = useState(null);
  const [formular, setFormular]     = useState(PRAZDNY);
  const [mazany, setMazany]         = useState(null);

  const nacitat = useCallback(async () => {
    try {
      setDta(await api('/v1/admin/portaly'));
      setChyba(null);
    } catch (e) {
      setChyba(e.message);
    } finally {
      setNacitava(false);
    }
  }, []);

  useEffect(() => { nacitat(); }, [nacitat]);

  useEffect(() => {
    if (!sprava) return;
    const t = setTimeout(() => setSprava(null), 6000);
    return () => clearTimeout(t);
  }, [sprava]);

  async function akcia(nazov, telo) {
    setPracuje(true);
    setChyba(null);
    try {
      const r = await api('/v1/admin/portaly?akcia=' + nazov,
                          { method: 'POST', body: telo });
      setSprava(r.sprava);
      setUpravovany(null);
      setMazany(null);
      await nacitat();
    } catch (e) {
      setChyba(e.message);
    } finally {
      setPracuje(false);
    }
  }

  function upravit(p) {
    setUpravovany(p.id);
    setFormular({
      code: p.code,
      name: p.name,
      // Adresy ako text: vkladajú sa z prehliadača a riadok po riadku sa
      // najprirodzenejšie pridávajú aj mažú.
      adresy: [p.url_kriteria, ...(p.url_kriteria_dalsie || [])]
                .filter(Boolean).join('\n'),
      is_active: p.is_active,
      je_agentura: p.je_agentura,
      ponuk_na_stranu: p.ponuk_na_stranu ?? '',
      request_delay_ms: p.request_delay_ms ?? 1500,
      popis: p.popis ?? '',
    });
  }

  const portaly = dta?.portaly ?? [];

  return (
    <div className="portaly">
      <h1>
        Portály
        <span className="por-pocet">
          {nacitava ? 'načítavam…' : `${portaly.length} portálov`}
        </span>
      </h1>

      {chyba  && <p className="por-chyba">{chyba}</p>}
      {sprava && <p className="por-sprava">{sprava}</p>}

      {upravovany !== 'novy' && (
        <button className="por-pridat"
                onClick={() => { setUpravovany('novy'); setFormular(PRAZDNY); }}
                disabled={pracuje}>
          + Pridať portál
        </button>
      )}

      {upravovany === 'novy' && (
        <Formular
          data={formular} setData={setFormular} novy
          pracuje={pracuje}
          ulozit={() => akcia('pridat', formular)}
          zrusit={() => setUpravovany(null)}
        />
      )}

      <div className="por-obal">
        <table className="por-tabulka">
          <thead>
            <tr>
              <th>Portál</th>
              <th>Adresy zberu</th>
              <th className="cislo">Ponúk</th>
              <th className="cislo">Firma</th>
              <th className="cislo">Úväzok</th>
              <th className="cislo">Mzda</th>
              <th>Zber</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {portaly.map(p => {
              const adresy = [p.url_kriteria, ...(p.url_kriteria_dalsie || [])]
                               .filter(Boolean);
              const ponuk = Number(p.ponuk);
              return (
                <Fragment key={p.id}>
                <tr className={(p.is_active ? '' : 'vypnuty')
                               + (upravovany === p.id ? ' upravovany' : '')}>
                  <td>
                    <strong>{p.name}</strong>
                    <span className="por-kod">{p.code}</span>
                    {p.je_agentura && <span className="por-znacka">agentúra</span>}
                    {p.vyzaduje_prihlasenie && (
                      <span className="por-znacka prihlasenie">vyžaduje prihlásenie</span>
                    )}
                  </td>

                  <td className="por-adresy">
                    {adresy.map((a, i) => (
                      <a key={i} href={a} target="_blank" rel="noreferrer noopener"
                         title={a}>{skratit(a)}</a>
                    ))}
                    {adresy.length === 0 && <span className="por-nic">bez adresy</span>}
                  </td>

                  <td className="cislo">
                    {ponuk > 0 ? ponuk : <span className="por-nic">—</span>}
                    {ponuk > 0 && Number(p.platnych) !== ponuk && (
                      <span className="por-podrobnost">{p.platnych} platných</span>
                    )}
                  </td>

                  {/* Vyplnenosť stĺpcov ukazuje, či parser portálu naozaj
                      rozumie jeho štruktúre — prázdny stĺpec pri nenulovom
                      počte ponúk je chyba, nie vlastnosť portálu. */}
                  <Pokrytie hodnota={p.s_firmou}  z={ponuk} />
                  <Pokrytie hodnota={p.s_uvazkom} z={ponuk} />
                  <Pokrytie hodnota={p.s_mzdou}   z={ponuk} />

                  <td>
                    <label className="por-prepinac">
                      <input type="checkbox" checked={p.is_active}
                             disabled={pracuje}
                             onChange={e => akcia('ulozit', {
                               id: p.id, name: p.name,
                               adresy: adresy.join('\n'),
                               is_active: e.target.checked,
                               je_agentura: p.je_agentura,
                               ponuk_na_stranu: p.ponuk_na_stranu,
                               request_delay_ms: p.request_delay_ms,
                               popis: p.popis,
                             })} />
                      {p.is_active ? 'zapnutý' : 'vypnutý'}
                    </label>
                  </td>

                  <td className="por-akcie">
                    <button onClick={() => upravit(p)} disabled={pracuje}>Upraviť</button>
                    {mazany === p.id ? (
                      <>
                        <button className="por-zmazat-potvrd"
                                onClick={() => akcia('zmazat', { id: p.id, potvrdene: true })}
                                disabled={pracuje}>
                          Naozaj zmazať{ponuk > 0 ? ` (${ponuk} ponúk)` : ''}
                        </button>
                        <button onClick={() => setMazany(null)} disabled={pracuje}>Späť</button>
                      </>
                    ) : (
                      <button className="por-zmazat"
                              onClick={() => setMazany(p.id)} disabled={pracuje}>
                        Zmazať
                      </button>
                    )}
                  </td>
                </tr>

                {/* Formulár sa rozbalí HNEĎ pod upravovaným portálom.
                    Predtým bol pod celou tabuľkou, takže pri dlhšom zozname
                    nebolo vidieť, že sa vôbec otvoril. */}
                {upravovany === p.id && (
                  <tr className="por-formular-riadok">
                    <td colSpan={8}>
                      <Formular
                        data={formular} setData={setFormular}
                        pracuje={pracuje}
                        ulozit={() => akcia('ulozit', { ...formular, id: p.id })}
                        zrusit={() => setUpravovany(null)}
                      />
                    </td>
                  </tr>
                )}
                </Fragment>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}

// ------------------------------------------------------------
// Koľko ponúk má daný údaj vyplnený.
//
// Farba je rýchly signál: zelená znamená, že parser portálu funguje,
// červená pri nenulovom počte ponúk znamená, že ho treba doplniť.
// ------------------------------------------------------------
function Pokrytie({ hodnota, z }) {
  const n = Number(hodnota);
  if (!z) return <td className="cislo"><span className="por-nic">—</span></td>;
  const podiel = n / z;
  const trieda = podiel >= 0.9 ? 'dobre' : podiel > 0 ? 'ciastocne' : 'chyba';
  return (
    <td className="cislo">
      <span className={'por-pokrytie ' + trieda}>{n}</span>
    </td>
  );
}

// ------------------------------------------------------------
// Formulár portálu
// ------------------------------------------------------------
function Formular({ data, setData, novy, pracuje, ulozit, zrusit }) {
  const zmena = (k, v) => setData(d => ({ ...d, [k]: v }));
  const pocetAdries = data.adresy.split('\n').filter(r => r.trim()).length;

  return (
    <section className="por-formular">
      <h2>{novy ? 'Nový portál' : 'Úprava portálu ' + data.name}</h2>

      <div className="por-polia">
        {novy && (
          <label>
            <span>Kód</span>
            <input value={data.code} onChange={e => zmena('code', e.target.value)}
                   placeholder="napr. profesia" disabled={pracuje} />
            <em>Používa sa v scraperi: --portal {data.code || '<kód>'}</em>
          </label>
        )}

        <label>
          <span>Názov</span>
          <input value={data.name} onChange={e => zmena('name', e.target.value)}
                 placeholder="napr. Profesia.sk" disabled={pracuje} />
        </label>

        <label>
          <span>Ponúk na stranu</span>
          <input type="number" min="0" max="500" value={data.ponuk_na_stranu}
                 onChange={e => zmena('ponuk_na_stranu', e.target.value)}
                 placeholder="nechaj prázdne" disabled={pracuje} />
          <em>Podľa toho sa pozná, či má portál ďalšie strany.</em>
        </label>

        <label>
          <span>Odstup medzi požiadavkami (ms)</span>
          <input type="number" min="200" max="10000" step="100"
                 value={data.request_delay_ms}
                 onChange={e => zmena('request_delay_ms', e.target.value)}
                 disabled={pracuje} />
          <em>Nižšia hodnota zaťažuje portál viac.</em>
        </label>
      </div>

      <label className="por-adresy-pole">
        <span>Adresy zberu ({pocetAdries})</span>
        <textarea rows={Math.max(3, pocetAdries + 1)} value={data.adresy}
                  onChange={e => zmena('adresy', e.target.value)}
                  placeholder="https://www.profesia.sk/praca/bratislavsky-kraj/"
                  disabled={pracuje} />
        <em>
          Jedna adresa na riadok. Zber ich prejde po kolách — hodí sa, keď
          portál nedáva všetky ponuky na jednom zozname (napr. kraje).
        </em>
      </label>

      <label className="por-popis-pole">
        <span>Poznámka</span>
        <textarea rows={2} value={data.popis}
                  onChange={e => zmena('popis', e.target.value)}
                  placeholder="Na čo si dať pri tomto portáli pozor"
                  disabled={pracuje} />
      </label>

      <div className="por-prepinace">
        <label className="por-prepinac">
          <input type="checkbox" checked={data.is_active}
                 onChange={e => zmena('is_active', e.target.checked)}
                 disabled={pracuje} />
          zapnutý zber
        </label>
        <label className="por-prepinac">
          <input type="checkbox" checked={data.je_agentura}
                 onChange={e => zmena('je_agentura', e.target.checked)}
                 disabled={pracuje} />
          agentúra (neuvádza zamestnávateľa)
        </label>
      </div>

      <div className="por-tlacidla">
        <button className="por-ulozit" onClick={ulozit} disabled={pracuje}>
          {pracuje ? 'Ukladám…' : novy ? 'Pridať portál' : 'Uložiť'}
        </button>
        <button onClick={zrusit} disabled={pracuje}>Zrušiť</button>
      </div>
    </section>
  );
}

// Dlhé adresy s filtrami majú stovky znakov — v tabuľke sa ukáže začiatok
// a koniec, celá je v title.
function skratit(url) {
  const bez = url.replace(/^https?:\/\//, '');
  if (bez.length <= 52) return bez;
  return bez.slice(0, 34) + '…' + bez.slice(-14);
}
