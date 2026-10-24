const TOKEN_KEY = 'vs_token';
function logout() { sessionStorage.removeItem(TOKEN_KEY); location.hash = '#/login'; location.reload(); }

async function api(path, { method = 'GET', body } = {}) {
  const headers = { 'Content-Type': 'application/json' };
  const t = sessionStorage.getItem(TOKEN_KEY);
  if (t) headers.Authorization = 'Bearer ' + t;
  let res;
  try {
    res = await fetch(window.APP_CONFIG.API_URL + path, { method, headers, body: body ? JSON.stringify(body) : undefined });
  } catch {
    throw new Error('No se pudo conectar con el servidor. Si usa Render gratis, espere ~1 minuto y reintente.');
  }
  let data = null;
  try { data = await res.json(); } catch { /* sin cuerpo */ }
  if (res.status === 401 && t) { logout(); throw new Error('Su sesión expiró'); }
  if (!res.ok) { const e = new Error((data && data.error) || 'Error ' + res.status); e.status = res.status; e.data = data; throw e; }
  return data;
}
