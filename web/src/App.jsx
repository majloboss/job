import { NavLink, Outlet, useNavigate } from 'react-router-dom';

// Spolocny ram prihlasenej casti: navigacia + odhlasenie.
export default function App() {
  const navigate = useNavigate();

  function odhlasit() {
    localStorage.removeItem('token');
    navigate('/login', { replace: true });
  }

  return (
    <div className="app">
      <nav className="app-nav">
        <span className="app-logo">JOB</span>
        <NavLink to="/lab">Laboratórium modelov</NavLink>
        <NavLink to="/dokumenty">Dokumenty</NavLink>
        <NavLink to="/preferencie">Preferencie</NavLink>
        <button className="app-logout" onClick={odhlasit}>Odhlásiť</button>
      </nav>
      <main>
        <Outlet />
      </main>
    </div>
  );
}
