/* ================== Gestión de Universo y Símbolos ================== */

// Caché inteligente para el universo de empresas
const universeCache = {
  data: null,
  timestamp: 0,
  ttl: 5 * 60 * 1000 // 5 minutos
};

// Categorías avanzadas de sectores
const SECTOR_CATEGORIES = {
  banks: {
    name: 'Bancos y Financieras',
    symbols: ['JPM','BAC','C','WFC','GS','MS','USB','PNC','TFC','BK','SCHW','AXP','BLK','COF','DFS','SYF','ALLY','HBAN','RF','CFG']
  },
  etfs: {
    name: 'ETFs',
    symbols: ['SPY','QQQ','IWM','DIA','XLK','XLF','XLV','XLE','XLY','XLI','XLP','XLB','XLU','ARKK','VTI','VOO','SMH','SOXL','TLT','HYG','EFA','EEM','GLD','SLV','USO','UNG','TLT','IEF','SHY','LQD']
  },
  tech: {
    name: 'Tecnología',
    symbols: ['AAPL','MSFT','GOOGL','AMZN','META','TSLA','NVDA','NFLX','ADBE','CRM','ORCL','INTC','AMD','QCOM','AVGO','CSCO','IBM','NOW','SNOW','PLTR','ZM','ROKU','SQ','PYPL','UBER','LYFT','TWTR','SNAP','PINS','SPOT']
  },
  healthcare: {
    name: 'Salud',
    symbols: ['JNJ','PFE','UNH','ABBV','MRK','TMO','ABT','DHR','BMY','AMGN','GILD','BIIB','REGN','VRTX','ILMN','MRNA','BNTX','ZTS','CVS','WBA']
  },
  energy: {
    name: 'Energía',
    symbols: ['XOM','CVX','COP','EOG','SLB','OXY','KMI','PSX','VLO','MPC','HES','PXD','FANG','EQT','MRO','DVN','NOV','HAL','BKR','WMB']
  },
  consumer: {
    name: 'Consumo',
    symbols: ['KO','PEP','WMT','PG','JNJ','UNH','HD','MCD','NKE','SBUX','CMCSA','DIS','VZ','T','COST','TGT','LOW','ABBV','MRK','PM']
  }
};

let providerSel = null;
const symbolSelect = document.getElementById('symbol-select');
const universeInfo = document.getElementById('universe-info');

async function buildProviderSelect() {
  try {
    const resp = await Config.apiGet('user_keys_get_safe.php', true);
    const keys = resp.keys || {};
    const options = [];
    if (keys['tiingo']?.has) options.push({ value: 'tiingo', label: 'Tiingo' });
    if (keys['alphavantage']?.has) options.push({ value: 'av', label: 'Alpha Vantage' });
    if (keys['finnhub']?.has) options.push({ value: 'finnhub', label: 'Finnhub' });
    if (keys['polygon']?.has) options.push({ value: 'polygon', label: 'Polygon' });
    options.unshift({ value: 'auto', label: 'Auto (elige mejor disponible)' });

    const html = `
      <label class="block text-sm font-medium text-gray-700">Proveedor de datos</label>
      <div class="flex gap-2">
        <select id="provider" class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
          ${options.map(opt => `<option value="${opt.value}">${opt.label}</option>`).join('')}
        </select>
        <button id="btn-reload-universe" class="mt-1 px-3 rounded-md border text-gray-700 hover:bg-gray-50">Actualizar</button>
      </div>
    `;
    const container = document.getElementById('provider-container');
    if (container) {
      container.innerHTML = html;
      providerSel = document.getElementById('provider');
      providerSel?.addEventListener('change', loadUniverse);
      document.getElementById('btn-reload-universe')?.addEventListener('click', loadUniverse);
      loadUniverse();
    }
  } catch (e) {
    const container = document.getElementById('provider-container');
    if (container) {
      container.innerHTML = '<div class="text-red-600 text-sm">No se pudo cargar la lista de proveedores.</div>';
    }
  }
}

async function loadUniverseEnhanced() {
  if (!providerSel) providerSel = document.getElementById('provider');
  const prov = providerSel.value;
  
  // Verificar caché
  const now = Date.now();
  if (universeCache.data && (now - universeCache.timestamp) < universeCache.ttl) {
    buildEnhancedUniverseSelect(universeCache.data, prov.toUpperCase());
    Notifications.toast('Lista cargada desde caché', 'info', 2000);
    return;
  }
  
  // Mostrar estado de carga
  symbolSelect.innerHTML = `<option value="">Cargando lista…</option>`;
  universeInfo.textContent = '';
  
  try {
    const uni = await Config.apiGet(`universe.php?limit=3000&provider=${encodeURIComponent(prov)}`, false);
    
    // Actualizar caché
    universeCache.data = uni;
    universeCache.timestamp = now;
    
    buildEnhancedUniverseSelect(uni, prov.toUpperCase());
    Notifications.toast(`Lista de empresas cargada: ${uni.length} símbolos`, 'success');
  } catch (error) {
    console.error('Error loading universe:', error);
    symbolSelect.innerHTML = `<option value="">No se pudo cargar la lista. Usa el cuadro de búsqueda.</option>`;
    Notifications.toast('Error al cargar la lista de empresas', 'error');
  }
}

function buildEnhancedUniverseSelect(list, label) {
  if (!Array.isArray(list) || list.length === 0) {
    symbolSelect.innerHTML = `<option value="">Lista vacía (prueba otro proveedor)</option>`;
    return;
  }

  // Crear sets para categorización rápida
  const categorySets = {};
  Object.keys(SECTOR_CATEGORIES).forEach(key => {
    categorySets[key] = new Set(SECTOR_CATEGORIES[key].symbols);
  });

  // Organizar símbolos por categoría
  const categorizedOptions = {};
  Object.keys(SECTOR_CATEGORIES).forEach(key => {
    categorizedOptions[key] = [];
  });
  const uncategorizedOptions = [];

  for (const x of list) {
    const sym = (x.symbol || '').toUpperCase();
    const name = x.name || '';
    const labelOpt = `${sym} — ${name}`;
    const optionHtml = `<option value="${sym}">${labelOpt}</option>`;
    
    let categorized = false;
    for (const [category, symbolSet] of Object.entries(categorySets)) {
      if (symbolSet.has(sym)) {
        categorizedOptions[category].push(optionHtml);
        categorized = true;
        break;
      }
    }
    
    if (!categorized) {
      uncategorizedOptions.push(optionHtml);
    }
  }

  // Construir HTML del select con categorías
  let selectHTML = `<option value="">— Elegir de la lista —</option>`;
  
  // Añadir categorías en orden específico
  const categoryOrder = ['banks', 'etfs', 'tech', 'healthcare', 'energy', 'consumer'];
  categoryOrder.forEach(category => {
    if (categorizedOptions[category].length > 0) {
      selectHTML += `<optgroup label="${SECTOR_CATEGORIES[category].name}">${categorizedOptions[category].join('')}</optgroup>`;
    }
  });
  
  // Añadir acciones no categorizadas
  if (uncategorizedOptions.length > 0) {
    selectHTML += `<optgroup label="Otras Acciones">${uncategorizedOptions.join('')}</optgroup>`;
  }

  symbolSelect.innerHTML = selectHTML;
  universeInfo.textContent = `Fuente: ${label} | Símbolos: ${list.length} | Categorías: ${Object.keys(SECTOR_CATEGORIES).length}`;
}

// Función original para compatibilidad
async function loadUniverse() {
  if (!providerSel) providerSel = document.getElementById('provider');
  const prov = providerSel.value;
  symbolSelect.innerHTML = `<option value="">Cargando lista…</option>`;
  universeInfo.textContent = '';
  try {
    const uni = await Config.apiGet(`universe.php?limit=2000&provider=${encodeURIComponent(prov)}`, false);
    buildUniverseSelect(uni, prov.toUpperCase());
  } catch {
    symbolSelect.innerHTML = `<option value="">No se pudo cargar la lista. Usa el cuadro de búsqueda.</option>`;
  }
}

function buildUniverseSelect(list, label){
  if (!Array.isArray(list) || list.length===0) {
    symbolSelect.innerHTML = `<option value="">Lista vacía (prueba otro proveedor)</option>`;
    return;
  }
  const banks = ['JPM','BAC','C','WFC','GS','MS','USB','PNC','TFC','BK','SCHW','AXP','BLK'];
  const etfs  = ['SPY','QQQ','IWM','DIA','XLK','XLF','XLV','XLE','XLY','XLI','XLP','XLB','XLU','ARKK','VTI','VOO','SMH','SOXL','TLT','HYG'];
  const setBanks = new Set(banks), setEtfs = new Set(etfs);

  const optBanks = [], optEtfs = [], optStocks = [];
  for (const x of list) {
    const sym = (x.symbol || '').toUpperCase();
    const name = x.name || '';
    const labelOpt = `${sym} — ${name}`;
    if (setEtfs.has(sym)) optEtfs.push(`<option value="${sym}">${labelOpt}</option>`);
    else if (setBanks.has(sym)) optBanks.push(`<option value="${sym}">${labelOpt}</option>`);
    else optStocks.push(`<option value="${sym}">${labelOpt}</option>`);
  }
  symbolSelect.innerHTML =
    `<option value="">— Elegir de la lista —</option>
     <optgroup label="Instituciones (bancos/financieras)">${optBanks.join('')}</optgroup>
     <optgroup label="ETFs">${optEtfs.join('')}</optgroup>
     <optgroup label="Acciones">${optStocks.join('')}</optgroup>`;
  universeInfo.textContent = `Fuente: ${label} | Símbolos: ${list.length}`;
}

// Reemplazar la función original con la mejorada
loadUniverse = loadUniverseEnhanced;

// ================== Autocomplete ==================
const symbolInput = document.getElementById('symbol');
const list = document.getElementById('symbol-list');
let searchTimeout = null;

symbolInput?.addEventListener('input', () => {
  const q = symbolInput.value.trim();
  if (!q) { 
    list?.classList.add('hidden'); 
    if (list) list.innerHTML=''; 
    return; 
  }
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(async () => {
    try {
      const res = await Config.apiGet(`search.php?q=${encodeURIComponent(q)}&provider=${encodeURIComponent(providerSel?.value || 'auto')}`, false);
      if (!Array.isArray(res) || res.length===0) { 
        list?.classList.add('hidden'); 
        if (list) list.innerHTML=''; 
        return; 
      }
      if (list) {
        list.innerHTML = res.slice(0,30).map(r => `<div class="autocomplete-item" data-s="${r.symbol}">${r.symbol} — <span class="text-gray-500">${r.name || ''}</span></div>`).join('');
        list.classList.remove('hidden');
      }
    } catch {
      list?.classList.add('hidden');
      if (list) list.innerHTML='';
    }
  }, 250);
});

list?.addEventListener('click', (e) => {
  const item = e.target.closest('.autocomplete-item');
  if (item && symbolInput) { 
    symbolInput.value = item.dataset.s; 
    list.classList.add('hidden'); 
  }
});

document.addEventListener('click', (e)=>{ 
  if (!list?.contains(e.target) && e.target !== symbolInput) {
    list?.classList.add('hidden'); 
  } 
});

symbolSelect?.addEventListener('change', () => {
  const v = symbolSelect.value; 
  if (v) {
    const symbolInput = document.getElementById('symbol');
    if (symbolInput) symbolInput.value = v;
  }
});

// Exportar para uso en otros módulos
window.Universe = {
  buildProviderSelect,
  loadUniverse,
  loadUniverseEnhanced,
  buildUniverseSelect,
  buildEnhancedUniverseSelect,
  SECTOR_CATEGORIES,
  universeCache
};
