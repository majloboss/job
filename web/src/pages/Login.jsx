import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../api';

export default function Login() {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [chyba, setChyba]       = useState(null);
  const [caka, setCaka]         = useState(false);
  const navigate = useNavigate();

  async function odoslat(e) {
    e.preventDefault();
    setChyba(null);
    setCaka(true);
    try {
      const r = await api('/v1/auth/login', {
        method: 'POST',
        body: { username: username.trim(), password },
      });
      localStorage.setItem('token', r.token);
      localStorage.setItem('role', r.role);
      navigate('/', { replace: true });
    } catch (e) {
      setChyba(e.message);
    } finally {
      setCaka(false);
    }
  }

  return (
    <div className="login">
      <form className="login-box" onSubmit={odoslat}>
        <h1>JOB</h1>
        <p className="login-sub">Prihlásenie</p>

        {chyba && <div className="login-error">{chyba}</div>}

        <label>
          <span>Používateľské meno</span>
          <input value={username} onChange={e => setUsername(e.target.value)}
                 autoComplete="username" autoFocus required />
        </label>

        <label>
          <span>Heslo</span>
          <input type="password" value={password} onChange={e => setPassword(e.target.value)}
                 autoComplete="current-password" required />
        </label>

        <button type="submit" disabled={caka}>
          {caka ? 'Prihlasujem…' : 'Prihlásiť sa'}
        </button>
      </form>
    </div>
  );
}
