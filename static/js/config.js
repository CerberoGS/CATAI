/* ================== Config ================== */
// Configuración centralizada de la aplicación
const APP_CONFIG = {
  // Rutas base (relativas para portabilidad)
  API_BASE: 'api',
  STATIC_BASE: 'static',
  
  // Páginas de la aplicación
  PAGES: {
    LOGIN: 'login.html',
    CONFIG: 'config.html', 
    JOURNAL: 'journal.html',
    ACCOUNT: 'account.html',
    ADMIN: 'admin.html',
    FEEDBACK: 'feedback.html',
    TESTER: 'tester.html'
  },
  
  // Recursos externos (CDNs)
  EXTERNAL: {
    TAILWIND: 'https://cdn.tailwindcss.com',
    FONTS: 'https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap'
  },
  
  // Configuración de la app
  APP: {
    NAME: 'Cerbero: Analizador de Mercado con IA',
    DEFAULT_LANG: 'es-US',
    VERSION: '1.0.0'
  }
};

// Mantener compatibilidad con código existente
const API_BASE = APP_CONFIG.API_BASE;

/* ================== Token helpers ================== */
// Migración: si existía 'token', lo mueve a 'auth_token'
(function migrateToken(){
  const legacy = localStorage.getItem('token');
  const current = localStorage.getItem('auth_token');
  if (!current && legacy) {
    localStorage.setItem('auth_token', legacy);
    localStorage.removeItem('token');
  }
})();

function getToken(){ return localStorage.getItem('auth_token') || ''; }
function setToken(t){ if (t) localStorage.setItem('auth_token', t); }
function clearToken(){ localStorage.removeItem('auth_token'); localStorage.removeItem('token'); }

/* ================== HTTP helpers ================== */
async function httpRaw(path, options) {
  const url = `${API_BASE}/${path}`;
  const r = await fetch(url, options);
  const txt = await r.text();
  let json;
  try { json = JSON.parse(txt); }
  catch {
    const snippet = (txt||'').slice(0,200).replace(/\s+/g,' ').trim();
    throw new Error('Respuesta no JSON del servidor' + (snippet ? ` — ${snippet}` : ''));
  }
  if (!r.ok) {
    const msg = json?.error ? `${json.error}${json.detail ? ' - ' + json.detail : ''}` : 'Error HTTP ' + r.status;
    throw new Error(msg);
  }
  return json;
}

function withAuthHeaders(headers={}, auth=true) {
  const h = { ...headers };
  if (auth) {
    const token = getToken();
    if (token) h['Authorization'] = `Bearer ${token}`;
  }
  return h;
}

const apiGet  = (path, auth=false) => httpRaw(path, { method:'GET', headers: withAuthHeaders({}, auth), cache:'no-store' });
const apiPost = (path, data={}, auth=false) => httpRaw(path, {
  method:'POST',
  headers: withAuthHeaders({ 'Content-Type':'application/json' }, auth),
  body: JSON.stringify(data),
  cache: 'no-store'
});

/* === Fallback para endpoints *_safe === (SAFE primero) */
async function postWithFallback(paths, data, auth=false){
  let lastErr;
  for (const p of paths){
    try { return await apiPost(p, data, auth); }
    catch(e){ lastErr = e; }
  }
  throw lastErr || new Error('Fallo en endpoints: ' + paths.join(', '));
}

async function getWithFallback(paths, auth=false){
  let lastErr;
  for (const p of paths){
    try { return await apiGet(p, auth); }
    catch(e){ lastErr = e; }
  }
  throw lastErr || new Error('Fallo en endpoints: ' + paths.join(', '));
}

/* ================== UI helpers ================== */
function toast(msg){ alert(msg); }
function btnBusy(btn, busy){
  if (!btn) return;
  btn.disabled = !!busy;
  btn.classList.toggle('opacity-60', !!busy);
}

/* =========== Mapeos proveedor (UI <-> servidor) =========== */
function uiToServerProvider(v){
  if (v === 'av') return 'alphavantage';
  if (v === 'local') return 'auto';
  return v;
}

function serverToUiProvider(v){
  if (v === 'alphavantage') return 'av';
  if (v === 'auto') return 'auto';
  if (v === 'tiingo') return 'tiingo';
  if (v === 'finnhub') return 'finnhub';
  if (v === 'polygon') return 'polygon';
  return 'auto';
}

// Función helper para formatear números
function fmt(x){ return (x==null || Number.isNaN(x)) ? '—' : (typeof x==='number' ? x.toFixed(2) : x); }

/* ================== Config helpers ================== */
// Funciones helper para usar la configuración centralizada
function getPageUrl(pageKey) {
  return APP_CONFIG.PAGES[pageKey] || pageKey;
}

function getApiUrl(endpoint) {
  return `${APP_CONFIG.API_BASE}/${endpoint}`;
}

function getStaticUrl(path) {
  return `${APP_CONFIG.STATIC_BASE}/${path}`;
}

function navigateToPage(pageKey) {
  window.location.href = getPageUrl(pageKey);
}

// Exportar funciones para uso en otros módulos
window.Config = {
  // Configuración centralizada
  APP_CONFIG,
  getPageUrl,
  getApiUrl,
  getStaticUrl,
  navigateToPage,
  
  // Compatibilidad con código existente
  API_BASE,
  getToken,
  setToken,
  clearToken,
  httpRaw,
  withAuthHeaders,
  apiGet,
  apiPost,
  postWithFallback,
  getWithFallback,
  toast,
  btnBusy,
  uiToServerProvider,
  serverToUiProvider,
  fmt
};
