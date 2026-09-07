import { useState, useEffect } from 'react';
import { NavLink, Outlet, useNavigate, useLocation } from 'react-router-dom';

// Spolocny ram prihlasenej casti.
// Na mobile je navigacia skryta za tlacidlom, na sirokych displejoch je v riadku.

const ODKAZY = [
  { to: '/lab',         text: 'Laboratórium' },
  { to: '/dokumenty',   text: 'Dokumenty' },
  { to: '/preferencie', text: 'Preferencie' },
];

export default function App() {
  const [otvorene, setOtvorene] = useState(false);
  const navigate = useNavigate();
  const location = useLocation();

  // Po prechode na inu stranku sa menu zavrie samo.
  useEffect(() => { setOtvorene(false); }, [location.pathname]);

  // Kym je menu otvorene, stranka pod nim sa nesmie posuvat.
  useEffect(() => {
    document.body.style.overflow = otvorene ? 'hidden' : '';
    return () => { document.body.style.overflow = ''; };
  }, [otvorene]);

  function odhlasit() {
    localStorage.removeItem('token');
    localStorage.removeItem('role');
    navigate('/login', { replace: true });
  }

  return (
    <div className="app">
      <header className="app-hlavicka">
        <span className="app-logo">JOB</span>

        <button
          className="app-hamburger"
          onClick={() => setOtvorene(o => !o)}
          aria-label={otvorene ? 'Zavrieť menu' : 'Otvoriť menu'}
          aria-expanded={otvorene}
        >
          <span className={otvorene ? 'ikona-x' : 'ikona-menu'} aria-hidden="true" />
        </button>

        <nav className={'app-nav' + (otvorene ? ' otvorene' : '')}>
          {ODKAZY.map(o => (
            <NavLink key={o.to} to={o.to}>{o.text}</NavLink>
          ))}
          <button className="app-odhlasit" onClick={odhlasit}>Odhlásiť</button>
        </nav>
      </header>

      {/* Prekrytie: klik mimo menu ho zavrie. Len na mobile. */}
      {otvorene && <div className="app-prekrytie" onClick={() => setOtvorene(false)} />}

      <main>
        <Outlet />
      </main>
    </div>
  );
}
