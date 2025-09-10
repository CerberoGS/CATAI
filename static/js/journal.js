/* Journal Module - Gestión de análisis guardados */
(function() {
  'use strict';

  // Configuración de entorno
  const isProduction = window.location.hostname === 'cerberogrowthsolutions.com';
  const API_BASE = 'api';
  
  // Logging en archivo
  async function logToFile(level, message, data = null) {
    try {
      const logEntry = {
        timestamp: new Date().toISOString(),
        level: level,
        message: message,
        data: data,
        url: window.location.href,
        userAgent: navigator.userAgent
      };
      
      await fetch(`${API_BASE}/log_debug.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${getToken()}`
        },
        body: JSON.stringify(logEntry)
      });
    } catch (error) {
      console.error('Error enviando log:', error);
    }
  }

  // Funciones de utilidad
  function getToken() { 
    return localStorage.getItem('auth_token') || ''; 
  }
  
  function withAuthHeaders(headers = {}) { 
    const h = { ...headers }; 
    const t = getToken(); 
    if (t) h['Authorization'] = 'Bearer ' + t; 
    return h; 
  }
  
  async function httpRaw(path, options) { 
    const url = `${API_BASE}/${path}`;
    
    // Log de la llamada
    await logToFile('DEBUG', `Llamada API: ${url}`, {
      method: options.method,
      headers: options.headers
    });
    
    const r = await fetch(url, options); 
    const txt = await r.text(); 
    
    // Log de la respuesta
    await logToFile('DEBUG', `Respuesta API: ${r.status} ${r.statusText}`, {
      url: url,
      status: r.status,
      contentLength: txt.length
    });
    
    let j; 
    try { 
      j = JSON.parse(txt);
    } catch { 
      await logToFile('ERROR', 'Error parseando JSON', {
        url: url,
        response: txt.substring(0, 500)
      });
      throw new Error('no JSON'); 
    } 
    
    if (!r.ok) { 
      await logToFile('ERROR', `Error HTTP: ${j?.error || ('HTTP ' + r.status)}`, {
        url: url,
        status: r.status,
        error: j?.error
      });
      throw new Error(j?.error || ('HTTP ' + r.status)); 
    } 
    return j; 
  }

  const apiGet = (p) => httpRaw(p, { method: 'GET', headers: withAuthHeaders(), cache: 'no-store' });
  const apiPost = (p, d) => httpRaw(p, { method: 'POST', headers: withAuthHeaders({ 'Content-Type': 'application/json' }), body: JSON.stringify(d), cache: 'no-store' });

  // Estado de la aplicación
  let offset = 0;
  let limit = 10;
  let total = 0;
  let current = null;

  // Elementos DOM
  const listEl = document.getElementById('list');
  const pageInfo = document.getElementById('page-info');
  const modal = document.getElementById('modal');
  const mBody = document.getElementById('m-body');
  const mClose = document.getElementById('m-close');
  const mMsg = document.getElementById('m-msg');
  const mSave = document.getElementById('m-save');
  const mReopen = document.getElementById('m-reopen');

  // Funciones de renderizado
  function chip(v, klass) { 
    return `<span class="px-2 py-0.5 rounded-full text-xs ${klass}">${v}</span>`; 
  }

  function renderList(items) {
    if (!listEl) {
      console.error('Elemento #list no encontrado en el DOM');
      return;
    }
    
    if (!items.length) { 
      listEl.innerHTML = '<div class="text-gray-500">Sin resultados.</div>'; 
      return; 
    }
    
    listEl.innerHTML = items.map(it => {
      const oc = it.outcome === 'pos' ? chip('Positivo', 'bg-green-100 text-green-800') : 
                 it.outcome === 'neg' ? chip('Negativo', 'bg-rose-100 text-rose-800') : 
                 it.outcome === 'neutro' ? chip('Neutro', 'bg-gray-100 text-gray-800') : '';
      const tr = it.traded ? chip('Operé', 'bg-blue-100 text-blue-800') : '';
      const title = it.title ? it.title : `${it.symbol} ${it.timeframe || ''}`.trim();
      
      return `
        <div class="bg-white rounded-lg border p-3 flex items-center justify-between">
          <div>
            <div class="font-medium text-gray-900">${title}</div>
            <div class="text-xs text-gray-500">${new Date(it.created_at.replace(' ', 'T')).toLocaleString()} · ${it.symbol} ${it.timeframe || ''}</div>
            <div class="mt-1 flex gap-2">${oc} ${tr}</div>
          </div>
          <div class="flex gap-2">
            <button class="px-2 py-1 rounded border text-gray-700 hover:bg-gray-50" data-view="${it.id}">Ver</button>
            <button class="px-2 py-1 rounded border text-rose-700 hover:bg-rose-50" data-del="${it.id}">Eliminar</button>
          </div>
        </div>`;
    }).join('');
  }

  // Funciones principales
  async function loadPage() {
    await logToFile('INFO', 'Iniciando carga de página de análisis');
    
    const qs = new URLSearchParams();
    qs.set('limit', String(limit)); 
    qs.set('offset', String(offset));
    
    const sym = document.getElementById('f-symbol').value.trim().toUpperCase(); 
    if (sym) qs.set('symbol', sym);
    
    const oc = document.getElementById('f-outcome').value; 
    if (oc) qs.set('outcome', oc);
    
    const tr = document.getElementById('f-traded').value; 
    if (tr !== '') qs.set('traded', tr);
    
    const q = document.getElementById('f-q').value.trim(); 
    if (q) qs.set('q', q);
    
    const from = document.getElementById('f-from')?.value || '';
    const to = document.getElementById('f-to')?.value || '';
    if (from) qs.set('from', from);
    if (to) qs.set('to', to);
    
    await logToFile('DEBUG', 'Parámetros de búsqueda', {
      params: qs.toString(),
      offset: offset,
      limit: limit
    });
    
    try {
      const j = await apiGet('analysis_list_safe.php?' + qs.toString());
      total = j.total || 0; 
      renderList(j.items || []);
      
      if (pageInfo) {
        pageInfo.textContent = `${Math.floor(offset / limit) + 1} / ${Math.max(1, Math.ceil(total / limit))} (total ${total})`;
      }
      
      await logToFile('INFO', 'Análisis cargados exitosamente', {
        total: total,
        items: j.items?.length || 0
      });
    } catch (error) {
      await logToFile('ERROR', 'Error cargando análisis', {
        error: error.message,
        params: qs.toString()
      });
      if (listEl) {
        listEl.innerHTML = '<div class="text-red-500">Error cargando análisis: ' + error.message + '</div>';
      }
    }
  }

  function mShow(b) { 
    modal.classList.toggle('hidden', !b); 
    modal.classList.toggle('flex', !!b); 
  }

  async function viewAnalysis(id) {
    await logToFile('INFO', 'Visualizando análisis', { id: id });
    
    try {
      const j = await apiGet('analysis_get_safe.php?id=' + id);
      current = j;
      const a = j.analysis || {};
      const atts = j.attachments || [];
      
      mBody.innerHTML = `
        <div class="grid md:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm text-gray-700">Título</label>
            <input id="m-title" class="mt-1 w-full rounded-md border-gray-300 shadow-sm" value="${(a.title || '').replace(/"/g, '&quot;')}">
          </div>
          <div>
            <label class="block text-sm text-gray-700">Resultado</label>
            <select id="m-outcome" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
              <option value="" ${!a.outcome ? 'selected' : ''}>--</option>
              <option value="pos" ${a.outcome === 'pos' ? 'selected' : ''}>Positivo</option>
              <option value="neg" ${a.outcome === 'neg' ? 'selected' : ''}>Negativo</option>
              <option value="neutro" ${a.outcome === 'neutro' ? 'selected' : ''}>Neutro</option>
            </select>
          </div>
          <div class="flex items-center gap-2">
            <input id="m-traded" type="checkbox" ${a.traded ? 'checked' : ''}>
            <label class="text-sm text-gray-700">Operé según este análisis</label>
          </div>
        </div>
        <div>
          <label class="block text-sm text-gray-700">Notas</label>
          <textarea id="m-notes" rows="4" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">${(a.user_notes || '').replace(/</g, '&lt;')}</textarea>
        </div>
        <div>
          <label class="block text-sm text-gray-700">Análisis (texto)</label>
          <pre class="bg-gray-50 p-2 rounded text-xs overflow-auto max-h-64">${(a.analysis_text || '').replace(/</g, '&lt;')}</pre>
        </div>
        <div class="grid grid-cols-3 gap-2">${atts.map(x => `<img src="${x.url}" class="rounded border" alt="cap"/>`).join('')}</div>
      `;
      
      mShow(true);
      
      // Controles adicionales
      try {
        const extra = document.createElement('div');
        extra.className = 'space-y-2';
        extra.innerHTML = `
          <div class="grid md:grid-cols-2 gap-3">
            <div>
              <label class="block text-sm text-gray-700">PnL</label>
              <input id="m-pnl" type="number" step="0.01" class="mt-1 w-full rounded-md border-gray-300 shadow-sm" value="${a.pnl != null ? a.pnl : ''}">
            </div>
            <div>
              <label class="block text-sm text-gray-700">Moneda</label>
              <input id="m-currency" class="mt-1 w-full rounded-md border-gray-300 shadow-sm" maxlength="8" placeholder="USD" value="${(a.currency || '').replace(/"/g, '&quot;')}">
            </div>
          </div>
          <div>
            <label class="block text-sm text-gray-700">Adjuntos</label>
            <div class="grid grid-cols-3 gap-2" id="m-atts"></div>
            <div class="mt-2"><input id="m-add-files" type="file" accept="image/*" multiple></div>
          </div>`;
        mBody.appendChild(extra);
        
        const atWrap = document.getElementById('m-atts');
        if (atWrap) {
          atWrap.innerHTML = atts.map(x => `<div class="relative"><img src="${x.url}" class="rounded border" alt="cap"/><button data-del-att="${x.id}" class="absolute top-1 right-1 bg-white/80 rounded px-1 text-rose-700 border">✕</button></div>`).join('');
        }
      } catch (e) {
        await logToFile('WARN', 'Error creando controles adicionales', { error: e.message });
      }
    } catch (error) {
      await logToFile('ERROR', 'Error cargando análisis', { id: id, error: error.message });
      alert('Error cargando análisis: ' + error.message);
    }
  }

  async function deleteAnalysis(id) {
    await logToFile('INFO', 'Eliminando análisis', { id: id });
    
    try {
      await apiPost('analysis_delete_safe.php', { id });
      await loadPage();
      await logToFile('INFO', 'Análisis eliminado exitosamente', { id: id });
    } catch (error) {
      await logToFile('ERROR', 'Error eliminando análisis', { id: id, error: error.message });
      alert('Error eliminando análisis: ' + error.message);
    }
  }

  async function saveAnalysis() {
    if (!current?.analysis?.id) return;
    
    await logToFile('INFO', 'Guardando análisis', { id: current.analysis.id });
    
    try {
      const payload = {
        id: current.analysis.id,
        title: document.getElementById('m-title').value,
        user_notes: document.getElementById('m-notes').value,
        traded: document.getElementById('m-traded').checked,
        outcome: document.getElementById('m-outcome').value,
        pnl: (function() { 
          const v = parseFloat(document.getElementById('m-pnl')?.value || ''); 
          return Number.isFinite(v) ? v : null; 
        })(),
        currency: document.getElementById('m-currency')?.value || null,
      };
      
      const r = await apiPost('analysis_update_safe.php', payload);
      if (r && r.ok) { 
        mMsg.textContent = 'Guardado'; 
        await loadPage();
        await logToFile('INFO', 'Análisis guardado exitosamente', { id: current.analysis.id });
      } else { 
        mMsg.textContent = 'Guardado parcial'; 
        await logToFile('WARN', 'Guardado parcial', { id: current.analysis.id });
      }
    } catch (error) {
      mMsg.textContent = 'Error: ' + (error?.message || error);
      await logToFile('ERROR', 'Error guardando análisis', { 
        id: current.analysis.id, 
        error: error.message 
      });
    }
  }

  function reopenAnalysis() {
    try {
      const snap = current?.analysis?.snapshot_json || {};
      if (snap) {
        const enriched = { ...snap, symbol: (current.analysis?.symbol || '') };
        localStorage.setItem('snapshot_to_load', JSON.stringify(enriched));
        window.location.href = 'index.html#reopen';
        logToFile('INFO', 'Reabriendo análisis en analizador', { 
          symbol: current.analysis?.symbol 
        });
      }
    } catch (error) {
      logToFile('ERROR', 'Error reabriendo análisis', { error: error.message });
    }
  }

  // Event listeners
  function setupEventListeners() {
    // Verificar que los elementos existan antes de agregar listeners
    const btnSearch = document.getElementById('btn-search');
    const btnPrev = document.getElementById('prev');
    const btnNext = document.getElementById('next');
    
    if (btnSearch) {
      btnSearch.addEventListener('click', () => { 
        offset = 0; 
        loadPage().catch(() => {}); 
      });
    }
    
    if (btnPrev) {
      btnPrev.addEventListener('click', () => { 
        offset = Math.max(0, offset - limit); 
        loadPage().catch(() => {}); 
      });
    }
    
    if (btnNext) {
      btnNext.addEventListener('click', () => { 
        const max = Math.max(0, Math.ceil(total / limit) - 1) * limit; 
        offset = Math.min(max, offset + limit); 
        loadPage().catch(() => {}); 
      });
    }

    // Modal - verificar que los elementos existan
    if (mClose) mClose.addEventListener('click', () => mShow(false));
    if (mSave) mSave.addEventListener('click', saveAnalysis);
    if (mReopen) mReopen.addEventListener('click', reopenAnalysis);

    // Lista de análisis - verificar que el elemento exista
    if (listEl) {
      listEl.addEventListener('click', async (e) => {
      const delBtn = e.target.closest('button[data-del]');
      if (delBtn) {
        const id = parseInt(delBtn.getAttribute('data-del'), 10);
        if (Number.isFinite(id) && confirm('¿Eliminar análisis?')) {
          await deleteAnalysis(id);
        }
        return;
      }
      
        const btn = e.target.closest('button[data-view]'); 
        if (!btn) return;
        
        const id = parseInt(btn.getAttribute('data-view'), 10);
        await viewAnalysis(id);
      });
    }

    // Eliminar adjuntos - verificar que el elemento exista
    if (mBody) {
      mBody.addEventListener('click', async (e) => {
        const btn = e.target.closest('button[data-del-att]'); 
        if (!btn) return;
        
        const id = parseInt(btn.getAttribute('data-del-att'), 10); 
        if (!Number.isFinite(id)) return;
        
        if (!confirm('¿Eliminar adjunto?')) return;
        
        try {
          await apiPost('analysis_attachment_delete_safe.php', { id });
          // Refrescar adjuntos
          if (current?.analysis?.id) {
            const j = await apiGet('analysis_get_safe.php?id=' + current.analysis.id);
            current = j;
            const atts = j.attachments || [];
            const atWrap = document.getElementById('m-atts');
            if (atWrap) { 
              atWrap.innerHTML = atts.map(x => `<div class="relative"><img src="${x.url}" class="rounded border" alt="cap"/><button data-del-att="${x.id}" class="absolute top-1 right-1 bg-white/80 rounded px-1 text-rose-700 border">✕</button></div>`).join(''); 
            }
          }
          await logToFile('INFO', 'Adjunto eliminado', { id: id });
        } catch (error) {
          await logToFile('ERROR', 'Error eliminando adjunto', { id: id, error: error.message });
          alert('Error eliminando adjunto: ' + error.message);
        }
      });
    }

    // Agregar adjuntos - verificar que el elemento exista
    if (mBody) {
      mBody.addEventListener('change', async (e) => {
        const inp = e.target.closest('#m-add-files'); 
        if (!inp) return;
        
        const files = Array.from(inp.files || []); 
        if (!files.length || !current?.analysis?.id) return;
        
        await logToFile('INFO', 'Subiendo adjuntos', { 
          count: files.length, 
          analysisId: current.analysis.id 
        });
        
        for (const f of files.slice(0, 6)) {
          const fd = new FormData(); 
          fd.append('file', f);
          
          try {
            const r = await fetch(`${API_BASE}/analysis_upload.php`, { 
              method: 'POST', 
              headers: withAuthHeaders(), 
              body: fd 
            });
            const txt = await r.text(); 
            let j; 
            try { 
              j = JSON.parse(txt);
            } catch { 
              continue; 
            }
            
            if (r.ok && j?.ok) {
              await apiPost('analysis_update_safe.php', { 
                id: current.analysis.id, 
                attachments: [{ url: j.url, mime: j.mime, size: j.size }] 
              });
            }
          } catch (error) {
            await logToFile('ERROR', 'Error subiendo archivo', { 
              fileName: f.name, 
              error: error.message 
            });
          }
        }
        
        // Refresh
        try {
          const j2 = await apiGet('analysis_get_safe.php?id=' + current.analysis.id);
          current = j2;
          const atts = j2.attachments || [];
          const atWrap = document.getElementById('m-atts');
          if (atWrap) { 
            atWrap.innerHTML = atts.map(x => `<div class="relative"><img src="${x.url}" class="rounded border" alt="cap"/><button data-del-att="${x.id}" class="absolute top-1 right-1 bg-white/80 rounded px-1 text-rose-700 border">✕</button></div>`).join(''); 
          }
          e.target.value = '';
          await logToFile('INFO', 'Adjuntos actualizados', { count: atts.length });
        } catch (error) {
          await logToFile('ERROR', 'Error refrescando adjuntos', { error: error.message });
        }
      });
    }
  }

  // Inicialización
  async function init() {
    await logToFile('INFO', 'Inicializando módulo Journal');
    
    // Verificar que los elementos DOM críticos existan
    const criticalElements = {
      list: document.getElementById('list'),
      pageInfo: document.getElementById('page-info'),
      modal: document.getElementById('modal'),
      mBody: document.getElementById('m-body'),
      mClose: document.getElementById('m-close'),
      mSave: document.getElementById('m-save'),
      mReopen: document.getElementById('m-reopen')
    };
    
    const missingElements = Object.entries(criticalElements)
      .filter(([name, element]) => !element)
      .map(([name]) => name);
    
    if (missingElements.length > 0) {
      await logToFile('ERROR', 'Elementos DOM faltantes', { missing: missingElements });
      console.error('Elementos DOM faltantes:', missingElements);
      return;
    }
    
    try {
      setupEventListeners();
      await loadPage();
      await logToFile('INFO', 'Módulo Journal inicializado exitosamente');
    } catch (error) {
      await logToFile('ERROR', 'Error inicializando Journal', { error: error.message });
      console.error('Error inicializando Journal:', error);
    }
  }

  // Exportar funciones públicas
  window.Journal = {
    init,
    loadPage,
    viewAnalysis,
    deleteAnalysis,
    saveAnalysis,
    reopenAnalysis,
    logToFile
  };

  // Auto-inicializar solo si estamos en journal.html
  function shouldInitialize() {
    const path = window.location.pathname.split('/').pop();
    return path === 'journal.html';
  }

  // Auto-inicializar si el DOM está listo y estamos en journal.html
  if (shouldInitialize()) {
    // Función para intentar inicializar con reintentos
    function tryInit(retries = 5) {
      const criticalElements = ['list', 'page-info', 'modal', 'm-body', 'm-close', 'm-save', 'm-reopen'];
      const missingElements = criticalElements.filter(id => !document.getElementById(id));
      
      if (missingElements.length === 0) {
        init();
      } else if (retries > 0) {
        console.log(`Elementos faltantes: ${missingElements.join(', ')}. Reintentando en 200ms...`);
        setTimeout(() => tryInit(retries - 1), 200);
      } else {
        console.error('No se pudieron encontrar los elementos DOM después de múltiples intentos:', missingElements);
      }
    }
    
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => {
        setTimeout(() => tryInit(), 500);
      });
    } else {
      setTimeout(() => tryInit(), 500);
    }
  }

})();
