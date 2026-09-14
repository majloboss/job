import { useEffect, useState, useCallback, useRef, Fragment } from 'react';
import { api } from '../api';
import './TestModelov.css';

// Test modelov — porovnanie, ktorý model najlepšie zvládne dve úlohy
// nad tým istým inzerátom:
//
//   1. Zber — názov, firma, mzda, miesto, HTML, preklad, súhrn
//   2. Vyhodnotenie — skóre a slovné hodnotenie podľa CV a preferencií
//
// Model dobrý na jedno nemusí byť dobrý na druhé — preto sa testujú obe
// naraz a nad tým istým vstupom.
//
// Test beží NA SERVERI. Výsledky pribúdajú priebežne, takže sa dá odísť
// z obrazovky aj zavrieť prehliadač a po návrate sa pokračuje tam, kde to je.

// Kódy úloh ostávajú 'parse' a 'vhodnost' — sú v DB a v promptoch.
// Mení sa len to, ako sa volajú na obrazovke.
const ULOHY = {
  parse:    { text: 'Zber',         popis: 'Čo model vytiahol z inzerátu' },
  vhodnost: { text: 'Vyhodnotenie', popis: 'Ako posúdil vhodnosť pre teba' },
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
  // Otvorených riadkov môže byť VIAC naraz — o to ide: porovnať, čo
  // rôzne modely vytiahli z toho istého inzerátu, bez zatvárania predošlého.
  const [otvorene, setOtvorene] = useState(() => new Set());

  // Radenie klikom na hlavičku. Predvolene podľa ceny — modely idú v teste
  // od najlacnejších a v tom poradí sa aj porovnávajú.
  const [radenie, setRadenie] = useState({ stlpec: 'cena_1m', smer: 'asc' });

  // Filter na ručné značky — po prejdení výsledkov si nimi zúžiš zoznam
  // na modely, ktoré si označil za použiteľné.
  const [lenZber, setLenZber] = useState(false);
  const [lenVyhod, setLenVyhod] = useState(false);

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

  // Značka sa ukladá hneď po kliknutí a zároveň sa premietne do načítaných
  // dát — bez toho by políčko po kliku odskočilo späť, kým nedobehne server.
  async function oznac(vysledokId, pole, hodnota) {
    setDetail(d => ({
      ...d,
      vysledky: d.vysledky.map(v =>
        v.id === vysledokId ? { ...v, [pole]: hodnota } : v),
    }));
    try {
      await api('/v1/admin/test-modelov?akcia=vhodnost', {
        method: 'POST',
        body: { vysledok_id: vysledokId, pole, hodnota },
      });
    } catch (e) {
      setChyba(e.message);
      await nacitatDetail(behId);      // vrátiť na stav zo servera
    }
  }

  // Cieľ celého testu: nájsť model s najlepšími výsledkami a dostať ho do
  // prevádzky. Zaradí sa celé poradie naraz — bezplatné prvé, platený ako
  // poistka pre chvíľu, keď bezplatné vyčerpajú denný limit.
  async function zaradit() {
    const ucel = ulohaTab === 'parse' ? 'parse' : 'eval';
    setPracuje(true);
    setChyba(null);
    try {
      const r = await api('/v1/admin/test-modelov?akcia=zaradit', {
        method: 'POST', body: { beh_id: behId, ucel },
      });
      setSprava(r.sprava);
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
  let vysledky = (detail?.vysledky ?? [])
    .filter(v => v.uloha === ulohaTab && Number(v.inzerat) === inzeratTab)
    .filter(v => !lenZber || v.vhodnost_zber)
    .filter(v => !lenVyhod || v.vhodnost_vyhodnotenie);

  if (radenie.stlpec) {
    const zn = radenie.smer === 'asc' ? 1 : -1;
    vysledky = [...vysledky].sort((a, b) => {
      const x = a[radenie.stlpec], y = b[radenie.stlpec];
      // Prázdne hodnoty vždy dole — model, ktorý údaj nevrátil, nemá byť
      // hore len preto, že sa radí vzostupne.
      const xp = x === null || x === undefined || x === '';
      const yp = y === null || y === undefined || y === '';
      if (xp && yp) return 0;
      if (xp) return 1;
      if (yp) return -1;
      const cislo = !isNaN(Number(x)) && !isNaN(Number(y));
      return zn * (cislo ? Number(x) - Number(y)
                         : String(x).localeCompare(String(y), 'sk'));
    });
  }

  // Koľko modelov je označených pre práve zobrazenú úlohu. Ten istý model
  // pri oboch inzerátoch sa ráta raz — do poradia ide tiež raz.
  const znackaPole = ulohaTab === 'parse' ? 'vhodnost_zber' : 'vhodnost_vyhodnotenie';
  const oznacenych = new Set(
    (detail?.vysledky ?? [])
      .filter(v => v.uloha === ulohaTab && v[znackaPole] && v.model_db_id)
      .map(v => v.model_db_id)).size;

  function klikStlpec(kod) {
    setRadenie(r => r.stlpec === kod
      ? { stlpec: kod, smer: r.smer === 'asc' ? 'desc' : 'asc' }
      : { stlpec: kod, smer: 'asc' });
  }

  // Hlavička, na ktorú sa dá kliknúť. Šípka ukazuje aktívny stĺpec aj smer.
  const Hl = ({ kod, text, cislo }) => (
    <th className={(cislo ? 'cislo' : '') + (radenie.stlpec === kod ? ' radene' : '')}
        onClick={() => klikStlpec(kod)} title={'Zoradiť podľa: ' + text}>
      {text}
      {radenie.stlpec === kod && (
        <span className="tm-sipka">{radenie.smer === 'asc' ? '▲' : '▼'}</span>
      )}
    </th>
  );

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
                  <Fragment key={b.id}>
                  <tr className={(behId === b.id ? 'vybrany ' : '') + b.status}
                      onClick={() => setBehId(behId === b.id ? null : b.id)}>
                    <td>{b.id}</td>
                    <td className="tm-nazov" title={(b.nazov1 || b.url1)
                        + (b.nazov2 ? ' + ' + b.nazov2 : '')}>
                      {skrat(b.nazov1 || b.url1, 42)}
                      {b.nazov2 && (
                        <span className="tm-druhy"> + {skrat(b.nazov2, 32)}</span>
                      )}
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

                  {/* Po rozkliknutí behu sa ukážu testované inzeráty
                      s klikateľnými odkazmi na originál. */}
                  {behId === b.id && (
                    <tr className="tm-beh-detail">
                      <td colSpan={8}>
                        <div className="tm-odkazy">
                          <Odkaz cislo={1} nazov={b.nazov1} url={b.url1} />
                          {b.url2 && <Odkaz cislo={2} nazov={b.nazov2} url={b.url2} />}
                        </div>
                      </td>
                    </tr>
                  )}
                  </Fragment>
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

          {/* Odkaz na inzerát, ktorý sa práve prezerá — pri porovnávaní
              výťažkov treba vedieť skočiť na originál. */}
          <div className="tm-odkazy">
            <Odkaz cislo={inzeratTab}
                   nazov={inzeratTab === 1 ? beh.nazov1 : beh.nazov2}
                   url={inzeratTab === 1 ? beh.url1 : beh.url2} />
          </div>

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

          <div className="tm-lista">
            <p className="tm-popis-ulohy">{ULOHY[ulohaTab].popis}</p>

            {otvorene.size > 0 && (
              <button className="tm-zavri-vsetky" onClick={() => setOtvorene(new Set())}>
                Zavrieť všetky ({otvorene.size})
              </button>
            )}
            <button className="tm-zavri-vsetky"
                    onClick={() => setOtvorene(new Set(vysledky.map(v => v.id)))}>
              Rozbaliť všetky
            </button>

            {/* Filter na ručné značky — po prejdení výsledkov si ním zúžiš
                zoznam na modely, ktoré si označil za použiteľné. */}
            <label className="tm-filter">
              <input type="checkbox" checked={lenZber}
                     onChange={e => setLenZber(e.target.checked)} />
              len vhodné na Zber
            </label>
            <label className="tm-filter">
              <input type="checkbox" checked={lenVyhod}
                     onChange={e => setLenVyhod(e.target.checked)} />
              len vhodné na Vyhodnotenie
            </label>

            {/* Prenesie označené modely do poradia, ktoré aplikácia reálne
                používa — to je cieľ testu. */}
            {oznacenych > 0 && (
              <button className="tm-zaradit" onClick={zaradit} disabled={pracuje}>
                Zaradiť do poradia ({oznacenych})
              </button>
            )}

          </div>

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
                      <Hl kod="model_id" text="Model" />
                      <Hl kod="cena_1m" text="$/1M" cislo />
                      <Hl kod="nazov" text="Názov" />
                      <Hl kod="firma" text="Firma" />
                      <Hl kod="mzda_min" text="Mzda" cislo />
                      <Hl kod="mesto" text="Mesto" />
                      <Hl kod="uvazok" text="Úväzok" />
                      <Hl kod="nastup" text="Nástup" />
                      <Hl kod="total_tokens" text="Tok." cislo />
                      <Hl kod="trvanie_ms" text="Čas" cislo />
                      <Hl kod="cena_usd" text="Cena" cislo />
                      <Hl kod="vhodnost_zber" text="Zber" cislo />
                      <Hl kod="vhodnost_vyhodnotenie" text="Vyhod." cislo />
                    </tr>
                  ) : (
                    <tr>
                      <Hl kod="model_id" text="Model" />
                      <Hl kod="cena_1m" text="$/1M" cislo />
                      <Hl kod="skore" text="Skóre" cislo />
                      <Hl kod="zaradenie" text="Zaradenie" />
                      <Hl kod="hodnotenie" text="Hodnotenie" />
                      <Hl kod="total_tokens" text="Tok." cislo />
                      <Hl kod="trvanie_ms" text="Čas" cislo />
                      <Hl kod="cena_usd" text="Cena" cislo />
                      <Hl kod="vhodnost_zber" text="Zber" cislo />
                      <Hl kod="vhodnost_vyhodnotenie" text="Vyhod." cislo />
                    </tr>
                  )}
                </thead>
                <tbody>
                  {vysledky.map(v => (
                    <Riadok key={v.id} v={v} uloha={ulohaTab} oznac={oznac}
                            otvoreny={otvorene.has(v.id)}
                            prepni={() => setOtvorene(p => {
                              const n = new Set(p);
                              n.has(v.id) ? n.delete(v.id) : n.add(v.id);
                              return n;
                            })} />
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
// Odkaz na testovaný inzerát. Otvára sa v novom okne — test môže bežať
// a odchod zo stránky by prerušil sledovanie priebehu.
// ------------------------------------------------------------
function Odkaz({ cislo, nazov, url }) {
  if (!url) return null;
  return (
    <a className="tm-odkaz" href={url} target="_blank" rel="noreferrer noopener"
       onClick={e => e.stopPropagation()} title={url}>
      <span className="tm-odkaz-cislo">{cislo}</span>
      <span className="tm-odkaz-nazov">{nazov || url}</span>
      <span className="tm-odkaz-sipka">↗</span>
    </a>
  );
}

// ------------------------------------------------------------
// Jeden riadok výsledku + rozkliknutý detail
// ------------------------------------------------------------
function Riadok({ v, uloha, otvoreny, prepni, oznac }) {
  const zlyhal = v.status !== 'ok';
  const stlpcov = (uloha === 'parse' ? 11 : 8) + 2;   // + dve značky

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
            <td className="tm-text" title={v.nazov || ''}>
              {skrat(v.nazov, 34) || <span className="tm-nic">—</span>}
            </td>
            <td className="tm-text" title={v.firma || ''}>
              {skrat(v.firma, 28) || <span className="tm-nic">—</span>}
            </td>
            <td className="cislo">{mzda(v)}</td>
            <td title={v.mesto || ''}>
              {skrat(v.mesto, 18) || <span className="tm-nic">—</span>}
            </td>
            <td>{(v.uvazky?.length ? v.uvazky.join(', ') : v.uvazok) ||
                 <span className="tm-nic">—</span>}</td>
            <td title={v.nastup || ''}>
              {skrat(v.nastup, 16) || <span className="tm-nic">—</span>}
            </td>
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

        {/* Ručné značky. stopPropagation, aby klik na políčko neotváral
            zároveň detail riadka. */}
        <td className="cislo tm-znacka">
          <input type="checkbox" checked={!!v.vhodnost_zber}
                 onClick={e => e.stopPropagation()}
                 onChange={e => oznac(v.id, 'vhodnost_zber', e.target.checked)}
                 title="Model je vhodný na zber údajov" />
        </td>
        <td className="cislo tm-znacka">
          <input type="checkbox" checked={!!v.vhodnost_vyhodnotenie}
                 onClick={e => e.stopPropagation()}
                 onChange={e => oznac(v.id, 'vhodnost_vyhodnotenie', e.target.checked)}
                 title="Model je vhodný na vyhodnotenie" />
        </td>
      </tr>

      {otvoreny && (
        <tr className="tm-detail-riadok">
          <td colSpan={stlpcov}>
            <div className="tm-detail-hlava">
              <code>{v.model_id}</code>
              {v.je_free && <span className="tm-free">zadarmo</span>}
              <span className="tm-detail-meta">
                {v.total_tokens} tok. · {(v.trvanie_ms / 1000).toFixed(1)} s ·{' '}
                {Number(v.cena_usd) > 0
                  ? '$' + Number(v.cena_usd).toFixed(6)
                  : 'zadarmo'}
                {!v.je_free && ` (${Number(v.cena_1m).toFixed(3)} $/1M)`}
              </span>
            </div>

            {zlyhal && <p className="tm-chyba-text">{v.chyba}</p>}

            {uloha === 'parse' ? (
              <div className="tm-detail">
                {/* Vyťažené údaje pokope — aby sa dali porovnať medzi modelmi
                    bez preskakovania po stĺpcoch tabuľky. */}
                <dl className="tm-udaje">
                  <dt>Názov</dt><dd>{v.nazov || '—'}</dd>
                  <dt>Firma</dt><dd>{v.firma || '—'}</dd>
                  <dt>Zverejnené</dt>
                  <dd>{v.datum_zverejnenia || v.datum_zverejnenia_text || '—'}</dd>
                  <dt>Mzda</dt>
                  <dd>{mzda(v)}{v.mzda_text && v.mzda_text !== mzda(v)
                        ? ` (${v.mzda_text})` : ''}</dd>
                  <dt>Nástup</dt><dd>{v.nastup || '—'}</dd>
                  <dt>Úväzok</dt>
                  <dd>{v.uvazky?.length ? v.uvazky.join(', ') : (v.uvazok || '—')}</dd>
                  <dt>Mesto</dt><dd>{v.mesto || '—'}</dd>
                  <dt>Lokalita</dt><dd>{v.lokalita_zvysok || '—'}</dd>
                  <dt>Jazyk</dt><dd>{v.orig_lang || '—'}</dd>
                </dl>

                {v.sumar && (
                  <>
                    <h4>Súhrn</h4>
                    <p className="tm-sumar">{v.sumar}</p>
                  </>
                )}

                {/* Celý inzerát tak, ako ho model prepísal. HTML je na
                    serveri očistené — povolené sú len nadpisy, odseky
                    a zoznamy, ktoré prompt žiada.

                    Keď sa originál a preklad líšia, ukazujú sa OBA vedľa
                    seba — pri cudzojazyčnom inzeráte je práve porovnanie
                    prekladu to podstatné. */}
                {(v.html_sk || v.html_original) && (
                  <>
                    <h4>Inzerát prepísaný modelom</h4>
                    <div className={'tm-verzie' + (v.html_sk && v.html_original
                                    && v.html_sk !== v.html_original ? ' dve' : '')}>
                      {v.html_sk && (
                        <div className="tm-verzia">
                          <h5>
                            Slovensky
                            {v.orig_lang && v.orig_lang !== 'sk' && (
                              <span className="tm-preklad">preklad z {v.orig_lang}</span>
                            )}
                          </h5>
                          <div className="tm-html"
                               dangerouslySetInnerHTML={{ __html: v.html_sk }} />
                        </div>
                      )}
                      {v.html_original && v.html_original !== v.html_sk && (
                        <div className="tm-verzia">
                          <h5>Originál {v.orig_lang ? `(${v.orig_lang})` : ''}</h5>
                          <div className="tm-html"
                               dangerouslySetInnerHTML={{ __html: v.html_original }} />
                        </div>
                      )}
                    </div>
                  </>
                )}
              </div>
            ) : (
              <div className="tm-detail">
                {v.skore !== null && (
                  <p className="tm-skore-velke">
                    <span className={'tm-skore ' + (v.zaradenie || '')}>{v.skore}</span>
                    {' '}{v.zaradenie}
                  </p>
                )}
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
// Skrati text pre tabulku. Cely je vzdy v title a v rozkliknutom detaile —
// dlhy nazov by inak pretiekol do susedneho stlpca.
function skrat(t, n) {
  if (!t) return t;
  const s = String(t);
  return s.length > n ? s.slice(0, n - 1) + '…' : s;
}

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
