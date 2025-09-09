/* ================== Main Application Entry Point ================== */

// Cargar módulos en orden de dependencias
// Módulos base (siempre cargados)
const baseModules = [
  'static/js/config.js',
  'static/js/auth-ui.js', 
  'static/js/ui-helpers.js',
  'static/js/notifications.js',
  'static/js/universe.js',
  'static/js/favorites.js',
  'static/js/performance.js',
  'static/js/analysis.js',
  'static/js/settings.js',
  'static/js/modals.js',
  'static/js/advanced-features.js',
  'static/js/feedback.js'
];

// Módulos específicos por página
const pageModules = {
  'journal.html': ['static/js/journal.js'],
  'index.html': [],
  'config.html': [],
  'account.html': [],
  'admin.html': [],
  'feedback.html': []
};

// Determinar qué módulos cargar según la página actual
function getModulesForCurrentPage() {
  const path = window.location.pathname.split('/').pop() || 'index.html';
  const specificModules = pageModules[path] || [];
  return [...baseModules, ...specificModules];
}

const modules = getModulesForCurrentPage();

// Función para cargar módulos dinámicamente
async function loadModules() {
  for (const module of modules) {
    try {
      await loadScript(module);
    } catch (error) {
      console.error(`Error loading module ${module}:`, error);
    }
  }
}

function loadScript(src) {
  return new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = src;
    script.onload = resolve;
    script.onerror = reject;
    document.head.appendChild(script);
  });
}

// Inicializar aplicación cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', async () => {
  try {
    await loadModules();
    
    // Inicializar componentes principales
    initializeApp();
    
  } catch (error) {
    console.error('Error initializing application:', error);
  }
});

function initializeApp() {
  // Restaurar sesión
  if (window.AuthUI) {
    AuthUI.tryRestoreSession();
  }
  
  // Inicializar otros componentes
  initializeUIComponents();
  initializeEventListeners();
}

function initializeUIComponents() {
  // Inicializar componentes de UI que no están en módulos específicos
  initializeAnalysisBlocks();
  initializeAutocomplete();
  initializeSettings();
}

function initializeAnalysisBlocks() {
  const blocks = document.getElementById('blocks');
  const defaultRes = ['60min','15min','5min'];
  
  function blockTemplate(i, reso) {
    return `
      <div class="border rounded-lg p-4">
        <div class="flex items-center justify-between mb-3">
          <h3 class="text-sm font-medium text-gray-700">Resolución ${i+1}</h3>
          <select class="reso text-xs border rounded px-2 py-1" data-i="${i}">
            <option value="1min">1 minuto</option>
            <option value="5min">5 minutos</option>
            <option value="15min">15 minutos</option>
            <option value="30min">30 minutos</option>
            <option value="60min">1 hora</option>
            <option value="daily">Diario</option>
            <option value="weekly">Semanal</option>
          </select>
        </div>
        <div class="grid grid-cols-2 gap-2 text-xs">
          <label class="flex items-center">
            <input type="checkbox" class="ind mr-2" data-k="rsi14" checked>
            RSI(14)
          </label>
          <label class="flex items-center">
            <input type="checkbox" class="ind mr-2" data-k="sma20" checked>
            SMA(20)
          </label>
          <label class="flex items-center">
            <input type="checkbox" class="ind mr-2" data-k="ema20" checked>
            EMA(20)
          </label>
          <label class="flex items-center">
            <input type="checkbox" class="ind mr-2" data-k="ema40">
            EMA(40)
          </label>
          <label class="flex items-center">
            <input type="checkbox" class="ind mr-2" data-k="ema100">
            EMA(100)
          </label>
          <label class="flex items-center">
            <input type="checkbox" class="ind mr-2" data-k="ema200">
            EMA(200)
          </label>
        </div>
      </div>
    `;
  }
  
  blocks.innerHTML = defaultRes.map((r,i)=>blockTemplate(i,r)).join('');
}

function initializeAutocomplete() {
  const symbolInput = document.getElementById('symbol');
  const list = document.getElementById('symbol-list');
  let searchTimeout = null;
  
  symbolInput.addEventListener('input', () => {
    const q = symbolInput.value.trim();
    if (!q) { 
      list.classList.add('hidden'); 
      list.innerHTML=''; 
      return; 
    }
    
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(async () => {
      try {
        const providerSel = document.getElementById('provider');
        const res = await Config.apiGet(`search.php?q=${encodeURIComponent(q)}&provider=${encodeURIComponent(providerSel.value)}`, false);
        if (!Array.isArray(res) || res.length===0) { 
          list.classList.add('hidden'); 
          list.innerHTML=''; 
          return; 
        }
        list.innerHTML = res.slice(0,30).map(r => `<div class="autocomplete-item" data-s="${r.symbol}">${r.symbol} — <span class="text-gray-500">${r.name || ''}</span></div>`).join('');
        list.classList.remove('hidden');
      } catch {
        list.classList.add('hidden');
        list.innerHTML='';
      }
    }, 300);
  });
  
  list.addEventListener('click', (e) => {
    const item = e.target.closest('.autocomplete-item');
    if (item) {
      const symbol = item.dataset.s;
      symbolInput.value = symbol;
      list.classList.add('hidden');
    }
  });
  
  document.addEventListener('click', (e) => { 
    if (!list.contains(e.target) && e.target !== symbolInput) {
      list.classList.add('hidden'); 
    } 
  });
}

function initializeSettings() {
  // Inicializar configuración de IA
  if (window.Settings?.buildAIProviders) {
    window.Settings.buildAIProviders();
  } else {
    buildAIProviders();
  }
  
  // Inicializar carga de universo
  if (window.Universe?.buildProviderSelect) {
    window.Universe.buildProviderSelect();
  } else if (window.Universe?.loadUniverse) {
    window.Universe.loadUniverse();
  }
}

async function buildAIProviders() {
  const sel = document.getElementById('ai-provider');
  const modelSelect = document.getElementById('ai-model');
  const suggestions = {
    'auto': ['Auto (sugerido)'],
    'gemini': ['gemini-1.5-flash', 'gemini-1.5-pro', 'gemini-1.0-pro'],
    'openai': ['gpt-4o', 'gpt-4o-mini', 'gpt-3.5-turbo'],
    'claude': ['claude-3-5-sonnet-20241022', 'claude-3-5-haiku-20241022'],
    'xai': ['grok-beta'],
    'deepseek': ['deepseek-chat', 'deepseek-coder']
  };
  
  try {
    const opts = Object.keys(suggestions).map(k => `<option value="${k}">${k}</option>`);
    sel.innerHTML = opts.join('');
    
    const rebuildModels = () => {
      const p = sel.value;
      const list = suggestions[p] || [];
      const prev = modelSelect.value;
      const opts = ['<option value="">Auto (sugerido)</option>'].concat(list.map(m => `<option value="${m}">${m}</option>`));
      modelSelect.innerHTML = opts.join('');
      if (prev && list.includes(prev)) modelSelect.value = prev; else modelSelect.value = '';
      modelSelect.disabled = (p === 'auto');
    };
    sel.addEventListener('change', rebuildModels);
    rebuildModels();
  } catch {}
}

function initializeEventListeners() {
  // Event listeners principales
  initializeAnalysisButton();
  initializeSettingsButtons();
  initializeFeedbackButton();
}

function initializeAnalysisButton() {
  const runBtn = document.getElementById('run');
  runBtn?.addEventListener('click', analyze);
}

function initializeSettingsButtons() {
  const btnLoadSettings = document.getElementById('btn-load-settings');
  const btnSaveSettings = document.getElementById('btn-save-settings');
  
  btnLoadSettings?.addEventListener('click', loadSettings);
  btnSaveSettings?.addEventListener('click', saveSettings);
}

function initializeFeedbackButton() {
  const btnFeedback = document.getElementById('btn-feedback');
  btnFeedback?.addEventListener('click', () => { 
    try { 
      if (window.Feedback?.show) {
        // Detectar módulo por página para preselección consistente
        const p = (location.pathname.split('/').pop()||'').toLowerCase();
        const mod = p.includes('journal')?'journal': p.includes('config')?'config': p.includes('tester')?'tester': p.includes('admin')?'admin': p.includes('account')?'account': 'index';
        window.Feedback.show({ module: mod });
      } else if (window.fbShow) {
        window.fbShow(true);
      }
    } catch {} 
  });
}

// Funciones principales (delegadas a módulos)
async function analyze() {
  if (window.Analysis?.analyze) {
    return window.Analysis.analyze();
  } else if (window.analyze) {
    return window.analyze();
  }
  console.error('Analysis module not loaded');
}

async function loadSettings() {
  if (window.Settings?.loadSettingsIntoUI) {
    return window.Settings.loadSettingsIntoUI();
  } else if (window.Settings?.loadSettings) {
    return window.Settings.loadSettings();
  }
  console.error('Settings module not loaded');
}

async function saveSettings() {
  if (window.Settings?.saveSettings) {
    return window.Settings.saveSettings();
  }
  console.error('Settings module not loaded');
}

// Exportar funciones globales necesarias
window.App = {
  initializeApp,
  analyze,
  loadSettings,
  saveSettings
};
