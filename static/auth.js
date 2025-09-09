// Minimal auth helper for JWT-based SPA
(function () {
  const LS_KEY = 'auth_token';

  function b64urlDecode(str) {
    try {
      str = str.replace(/-/g, '+').replace(/_/g, '/');
      const pad = str.length % 4;
      if (pad) str += '='.repeat(4 - pad);
      return atob(str);
    } catch (e) { return ''; }
  }

  function decodeJwt(token) {
    if (!token || typeof token !== 'string') return null;
    const parts = token.split('.');
    if (parts.length !== 3) return null;
    try {
      const payload = JSON.parse(b64urlDecode(parts[1]) || '{}');
      return payload && typeof payload === 'object' ? payload : null;
    } catch (e) { return null; }
  }

  function isExpired(payload) {
    try {
      if (!payload || typeof payload !== 'object') return true;
      if (typeof payload.exp !== 'number') return false; // no exp => assume not expired
      const now = Math.floor(Date.now() / 1000);
      return now >= payload.exp;
    } catch (e) { return true; }
  }

  function getToken() { return localStorage.getItem(LS_KEY) || ''; }
  function setToken(t) { if (t) localStorage.setItem(LS_KEY, t); }
  function clearToken() { localStorage.removeItem(LS_KEY); }

  function apiBase() {
    // Prefer absolute based on current location, keep compatibility with /bolsa/
    try { return new URL('api/', window.location.href).href.replace(/\/$/, ''); } catch { return '/api'; }
  }

  async function fetchJson(path, opts = {}) {
    const url = path.startsWith('http') ? path : (apiBase() + '/' + path.replace(/^\//, ''));
    const headers = Object.assign({ 'Accept': 'application/json' }, opts.headers || {});
    if (!('Content-Type' in headers) && (opts.method || 'GET').toUpperCase() !== 'GET') headers['Content-Type'] = 'application/json';
    const token = getToken();
    if (token) headers['Authorization'] = 'Bearer ' + token;
    const resp = await fetch(url, Object.assign({}, opts, { headers }));
    const text = await resp.text();
    let data = null; try { data = text ? JSON.parse(text) : null; } catch { data = { error: 'non_json', raw: text }; }
    return { ok: resp.ok, status: resp.status, data };
  }

  function requireAuth(opts = {}) {
    const t = getToken();
    const p = decodeJwt(t);
    if (!t || !p || isExpired(p)) {
      clearToken();
      const login = opts.login || 'login.html';
      if (window.location.pathname.endsWith('/' + login)) return;
      window.location.href = login + '?next=' + encodeURIComponent(window.location.pathname + window.location.search);
    }
    return p;
  }

  function requireAdmin() {
    const p = requireAuth();
    if (!p) return;
    const isAdmin = !!(p.is_admin || (p.role && String(p.role).toLowerCase() === 'admin'));
    if (!isAdmin) {
      alert('No autorizado: requiere rol admin');
      window.location.href = 'index.html';
    }
  }

  function logout() {
    clearToken();
    const login = 'login.html';
    window.location.href = login;
  }

  // expose
  window.Auth = { decodeJwt, isExpired, getToken, setToken, clearToken, fetchJson, requireAuth, requireAdmin, logout, apiBase };
})();

