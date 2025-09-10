/* Reusable App Header: renders consistent top bar across pages */
(function(){
  function getJwtPayload(){
    try{ if (window.Auth && typeof Auth.getToken==='function'){
      const t = Auth.getToken(); return t ? Auth.decodeJwt(t) : null; }
    }catch{} return null;
  }

  function isAdmin(){
    const p = getJwtPayload();
    return !!(p && (p.role==='admin' || p.is_admin===true));
  }

  function logout(){
    try{ if (window.Config?.clearToken) Config.clearToken(); }catch{}
    try{ if (window.Auth?.clearToken) Auth.clearToken(); }catch{}
    try{ localStorage.removeItem('auth_token'); }catch{}
    window.location.href = 'login.html';
  }

  function toggleTheme(btn){
    const key = 'darkMode';
    const curr = localStorage.getItem(key) === 'true';
    const next = !curr; localStorage.setItem(key, String(next));
    document.body.classList.toggle('dark-mode', next);
    if (btn) btn.textContent = next ? '☀️ Modo Claro' : '🌙 Modo Oscuro';
  }

  function getFavoritesList(){
    try{
      if (window.Favorites?.getAll) return window.Favorites.getAll();
      const raw = localStorage.getItem('favorites') || localStorage.getItem('favorites_list');
      const arr = raw ? JSON.parse(raw) : [];
      return Array.isArray(arr)? arr : [];
    }catch{ return []; }
  }

  function openFavoritesPanel(){
    const items = getFavoritesList();
    const wrap = document.createElement('div');
    wrap.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
    const bodyCls = 'bg-white dark-mode:bg-gray-800 rounded-lg p-4 w-full max-w-xl max-h-[80vh] overflow-y-auto border border-gray-200 dark-mode:border-gray-700';
    wrap.innerHTML = `
      <div class="${bodyCls}">
        <div class="flex items-center justify-between mb-3">
          <div class="text-lg font-semibold text-gray-900 dark-mode:text-gray-100">⭐ Favoritos</div>
          <button class="text-gray-500 hover:text-gray-700 dark-mode:text-gray-300 dark-mode:hover:text-gray-100" data-close>✕</button>
        </div>
        ${items.length ? `
          <ul class="divide-y divide-gray-200 dark-mode:divide-gray-700">
            ${items.map(it => `<li class=\"py-2 text-sm\"><a class=\"text-indigo-600 hover:underline\" href=\"${it.url||'#'}\">${it.title||it.symbol||it.url||'Item'}</a></li>`).join('')}
          </ul>`
        : `<div class="text-sm text-gray-600 dark-mode:text-gray-300">No tienes favoritos aún.</div>`}
      </div>`;
    wrap.addEventListener('click', (e)=>{ if (e.target===wrap || e.target.closest('[data-close]')) wrap.remove(); });
    document.body.appendChild(wrap);
  }

  function getFavoritesCount(){
    try{
      if (window.Favorites?.getCount) return Number(window.Favorites.getCount())||0;
      const raw = localStorage.getItem('favorites') || localStorage.getItem('favorites_list');
      if (raw){ const arr = JSON.parse(raw); return Array.isArray(arr)? arr.length : 0; }
    }catch{}
    return 0;
  }

  function isAnalyzerPage(){
    const path = location.pathname.split('/').pop() || 'index.html';
    return ['index.html', 'market-config.html', 'ai.html', 'journal.html'].includes(path);
  }

  function getAnalyzerActivePage(){
    const path = location.pathname.split('/').pop() || 'index.html';
    const analyzerPages = {
      'index.html': 'Trading Desk',
      'market-config.html': 'Market Config', 
      'ai.html': 'AI Config',
      'journal.html': 'Journal'
    };
    return analyzerPages[path] || 'Trading Desk';
  }

  function render(root, active){
    if (!root) return;
    const dm = localStorage.getItem('darkMode') === 'true';
    if (dm) document.body.classList.add('dark-mode');
    const path = location.pathname.split('/').pop() || 'index.html';
    const isActive = (p)=> active ? active===p : path===p;
    const adminVisible = isAdmin();
    const isAnalyzer = isAnalyzerPage();
    const analyzerActive = getAnalyzerActivePage();

    const favCount = getFavoritesCount();
    root.innerHTML = `
      <style>
        .analyzer-submenu {
          opacity: 0;
          visibility: hidden;
          transform: translateY(-10px);
          transition: all 0.2s ease-in-out;
        }
        .analyzer-group:hover .analyzer-submenu {
          opacity: 1;
          visibility: visible;
          transform: translateY(0);
        }
      </style>
      <div class="w-full border-b dark-mode:border-gray-700 bg-white dark-mode:bg-gray-900">
        <div class="container mx-auto px-4 py-3 flex items-center justify-between">
          <div class="flex items-center gap-2">
            <span class="text-2xl">📈</span>
            <h1 class="text-xl md:text-2xl font-bold text-gray-900 dark-mode:text-gray-100">CATAI</h1>
          </div>
          <nav class="flex items-center gap-3 text-sm">
            <button id="hdr-theme" class="px-2 py-1 rounded bg-gray-100 dark-mode:bg-gray-800 text-gray-800 dark-mode:text-gray-100 hover:bg-gray-200 dark-mode:hover:bg-gray-700">
              ${dm ? '☀️ Modo Claro' : '🌙 Modo Oscuro'}
            </button>
            <div class="relative analyzer-group">
              <a href="index.html" class="px-2 py-1 rounded ${isAnalyzer?'bg-indigo-100 dark-mode:bg-indigo-900 text-indigo-700 dark-mode:text-indigo-200 font-medium':'text-gray-700 dark-mode:text-gray-300 hover:bg-gray-100 dark-mode:hover:bg-gray-800'} flex items-center gap-1">
                📊 Analizador ${isAnalyzer?'▼':''}
              </a>
              ${isAnalyzer ? `
                <div class="analyzer-submenu absolute top-full left-0 mt-1 w-48 bg-white dark-mode:bg-gray-800 rounded-lg shadow-lg border border-gray-200 dark-mode:border-gray-700 py-2 z-50">
                  <a href="market-config.html" class="block px-4 py-2 text-sm ${analyzerActive==='Market Config'?'bg-indigo-50 dark-mode:bg-indigo-900 text-indigo-700 dark-mode:text-indigo-200 font-medium':'text-gray-700 dark-mode:text-gray-300 hover:bg-gray-50 dark-mode:hover:bg-gray-700'}">
                    📊 Market Config
                  </a>
                  <a href="ai.html" class="block px-4 py-2 text-sm ${analyzerActive==='AI Config'?'bg-indigo-50 dark-mode:bg-indigo-900 text-indigo-700 dark-mode:text-indigo-200 font-medium':'text-gray-700 dark-mode:text-gray-300 hover:bg-gray-50 dark-mode:hover:bg-gray-700'}">
                    🧠 AI Config
                  </a>
                  <a href="index.html" class="block px-4 py-2 text-sm ${analyzerActive==='Trading Desk'?'bg-indigo-50 dark-mode:bg-indigo-900 text-indigo-700 dark-mode:text-indigo-200 font-medium':'text-gray-700 dark-mode:text-gray-300 hover:bg-gray-50 dark-mode:hover:bg-gray-700'}">
                    🎯 Trading Desk
                  </a>
                  <a href="journal.html" class="block px-4 py-2 text-sm ${analyzerActive==='Journal'?'bg-indigo-50 dark-mode:bg-indigo-900 text-indigo-700 dark-mode:text-indigo-200 font-medium':'text-gray-700 dark-mode:text-gray-300 hover:bg-gray-50 dark-mode:hover:bg-gray-700'}">
                    📝 Journal
                  </a>
                </div>
              ` : ''}
            </div>
            <a href="account.html" class="px-2 py-1 rounded ${isActive('account.html')?'bg-indigo-100 dark-mode:bg-indigo-900 text-indigo-700 dark-mode:text-indigo-200 font-medium':'text-gray-700 dark-mode:text-gray-300 hover:bg-gray-100 dark-mode:hover:bg-gray-800'}">👤 Mi Cuenta</a>
            ${adminVisible? `<a href="admin.html" class="px-2 py-1 rounded ${isActive('admin.html')?'bg-indigo-100 dark-mode:bg-indigo-900 text-indigo-700 dark-mode:text-indigo-200 font-medium':'text-gray-700 dark-mode:text-gray-300 hover:bg-gray-100 dark-mode:hover:bg-gray-800'}">⚙️ Admin</a>` : ''}
            <a href="config.html" class="px-2 py-1 rounded ${isActive('config.html')?'bg-indigo-100 dark-mode:bg-indigo-900 text-indigo-700 dark-mode:text-indigo-200 font-medium':'text-gray-700 dark-mode:text-gray-300 hover:bg-gray-100 dark-mode:hover:bg-gray-800'}">🔧 Configuración</a>
            <button id="hdr-feedback" class="px-2 py-1 rounded bg-gray-100 dark-mode:bg-gray-800 text-gray-700 dark-mode:text-gray-300 hover:bg-gray-200 dark-mode:hover:bg-gray-700">💬 Feedback</button>
            <a id="hdr-favs" href="#" class="px-2 py-1 rounded bg-gray-100 dark-mode:bg-gray-800 text-gray-700 dark-mode:text-gray-300 hover:bg-gray-200 dark-mode:hover:bg-gray-700 flex items-center gap-1">
              ⭐ Favoritos <span id="hdr-favs-badge" class="ml-1 inline-flex items-center justify-center text-xs px-1.5 rounded-full bg-yellow-200 text-yellow-900 ${favCount? '':'hidden'}">${favCount}</span>
            </a>
            <button id="hdr-logout" class="px-2 py-1 rounded bg-red-600 text-white hover:bg-red-700">Cerrar Sesión</button>
          </nav>
        </div>
      </div>`;

    const btnTheme = root.querySelector('#hdr-theme');
    btnTheme?.addEventListener('click', ()=> toggleTheme(btnTheme));
    root.querySelector('#hdr-logout')?.addEventListener('click', logout);
    function detectModule(){
      try{
        const p = (location.pathname.split('/').pop()||'').toLowerCase();
        if (p.includes('journal')) return 'journal';
        if (p.includes('config')) return 'config';
        if (p.includes('tester')) return 'tester';
        if (p.includes('admin')) return 'admin';
        if (p.includes('account')) return 'account';
        if (p.includes('index')) return 'index';
      }catch{}
      return 'index';
    }

    root.querySelector('#hdr-feedback')?.addEventListener('click', ()=>{
      try {
        if (window.Feedback?.show) window.Feedback.show({ module: detectModule() });
        else if (window.fbShow) window.fbShow(true);
      } catch {}
    });
    root.querySelector('#hdr-favs')?.addEventListener('click', (e)=>{
      e.preventDefault();
      try{ if (window.Favorites?.openPanel) return window.Favorites.openPanel(); }catch{}
      openFavoritesPanel();
    });
  }

  // Auto-mount
  document.addEventListener('DOMContentLoaded', ()=>{
    const mount = document.getElementById('app-header');
    if (mount) render(mount);
  });

  window.UIHeader = { render };
})();


