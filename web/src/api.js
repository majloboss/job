// Tenka obalka nad fetch: doplni token, rozbali { ok, data } a chybu prevedie
// na vynimku so slovenskou hlaskou.
const BASE = import.meta.env.VITE_API_URL || '/api';

export async function api(path, { method = 'GET', body = null, raw = null } = {}) {
  const token = localStorage.getItem('token');
  const headers = {};
  if (token) headers.Authorization = `Bearer ${token}`;
  if (body && !raw) headers['Content-Type'] = 'application/json';

  const res = await fetch(BASE + path, {
    method,
    headers,
    body: raw ? raw : (body ? JSON.stringify(body) : null),
  });

  let json;
  try {
    json = await res.json();
  } catch {
    throw new Error(`Server vrátil neplatnú odpoveď (HTTP ${res.status})`);
  }

  if (!res.ok || !json.ok) {
    if (res.status === 401) {
      localStorage.removeItem('token');
      window.location.href = '/login';
    }
    throw new Error(json.error || `Chyba servera (HTTP ${res.status})`);
  }
  return json.data;
}
