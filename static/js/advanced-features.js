/* ================== Características Avanzadas ================== */

// Sistema de favoritos y símbolos recientes
class FavoritesManager {
  constructor() {
    this.favorites = new Set();
    this.recent = [];
    this.maxRecent = 20;
    this.storageKey = 'bolsa_favorites';
    this.recentKey = 'bolsa_recent';
    this.init();
  }

  init() {
    this.loadFromStorage();
    this.setupUI();
    this.setupKeyboardShortcuts();
  }

  loadFromStorage() {
    try {
      const savedFavorites = localStorage.getItem(this.storageKey);
      if (savedFavorites) {
        this.favorites = new Set(JSON.parse(savedFavorites));
      }

      const savedRecent = localStorage.getItem(this.recentKey);
      if (savedRecent) {
        this.recent = JSON.parse(savedRecent);
      }
    } catch (e) {
      console.warn('Error loading favorites from storage:', e);
    }
  }

  saveToStorage() {
    try {
      localStorage.setItem(this.storageKey, JSON.stringify([...this.favorites]));
      localStorage.setItem(this.recentKey, JSON.stringify(this.recent));
    } catch (e) {
      console.warn('Error saving favorites to storage:', e);
    }
  }

  addFavorite(symbol) {
    if (!symbol) return;
    const upperSymbol = symbol.toUpperCase();
    this.favorites.add(upperSymbol);
    this.saveToStorage();
    this.updateUI();
  }

  removeFavorite(symbol) {
    if (!symbol) return;
    const upperSymbol = symbol.toUpperCase();
    this.favorites.delete(upperSymbol);
    this.saveToStorage();
    this.updateUI();
  }

  toggleFavorite(symbol) {
    if (!symbol) return;
    const upperSymbol = symbol.toUpperCase();
    if (this.favorites.has(upperSymbol)) {
      this.removeFavorite(upperSymbol);
    } else {
      this.addFavorite(upperSymbol);
    }
  }

  isFavorite(symbol) {
    if (!symbol) return false;
    return this.favorites.has(symbol.toUpperCase());
  }

  addRecent(symbol) {
    if (!symbol) return;
    const upperSymbol = symbol.toUpperCase();
    
    // Remover si ya existe
    this.recent = this.recent.filter(s => s !== upperSymbol);
    
    // Agregar al inicio
    this.recent.unshift(upperSymbol);
    
    // Limitar tamaño
    if (this.recent.length > this.maxRecent) {
      this.recent = this.recent.slice(0, this.maxRecent);
    }
    
    this.saveToStorage();
    this.updateUI();
  }

  getFavorites() {
    return [...this.favorites];
  }

  getRecent() {
    return [...this.recent];
  }

  setupUI() {
    this.createFavoritesUI();
    this.updateUI();
  }

  createFavoritesUI() {
    // Crear contenedor de favoritos si no existe
    let container = document.getElementById('favorites-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'favorites-container';
      container.className = 'mb-4 p-3 bg-gray-50 rounded-lg';
      
      const symbolInput = document.getElementById('symbol');
      if (symbolInput && symbolInput.parentNode) {
        symbolInput.parentNode.insertBefore(container, symbolInput.nextSibling);
      }
    }

    container.innerHTML = `
      <div class="flex items-center justify-between mb-2">
        <h3 class="text-sm font-medium text-gray-700">Favoritos</h3>
        <button id="toggle-favorites" class="text-xs text-indigo-600 hover:text-indigo-800">Mostrar/Ocultar</button>
      </div>
      <div id="favorites-list" class="flex flex-wrap gap-1 mb-2"></div>
      <div class="flex items-center justify-between">
        <h4 class="text-xs font-medium text-gray-600">Recientes</h4>
        <button id="clear-recent" class="text-xs text-gray-500 hover:text-gray-700">Limpiar</button>
      </div>
      <div id="recent-list" class="flex flex-wrap gap-1"></div>
    `;

    // Event listeners
    document.getElementById('toggle-favorites')?.addEventListener('click', () => {
      const list = document.getElementById('favorites-list');
      list?.classList.toggle('hidden');
    });

    document.getElementById('clear-recent')?.addEventListener('click', () => {
      this.recent = [];
      this.saveToStorage();
      this.updateUI();
    });
  }

  updateUI() {
    this.updateFavoritesList();
    this.updateRecentList();
    this.updateSymbolInput();
  }

  updateFavoritesList() {
    const list = document.getElementById('favorites-list');
    if (!list) return;

    if (this.favorites.size === 0) {
      list.innerHTML = '<span class="text-xs text-gray-500">No hay favoritos</span>';
      return;
    }

    list.innerHTML = [...this.favorites].map(symbol => `
      <button class="favorite-btn px-2 py-1 text-xs bg-indigo-100 text-indigo-800 rounded hover:bg-indigo-200" data-symbol="${symbol}">
        ⭐ ${symbol}
      </button>
    `).join('');

    // Event listeners para botones de favoritos
    list.querySelectorAll('.favorite-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const symbol = btn.dataset.symbol;
        this.selectSymbol(symbol);
      });
    });
  }

  updateRecentList() {
    const list = document.getElementById('recent-list');
    if (!list) return;

    if (this.recent.length === 0) {
      list.innerHTML = '<span class="text-xs text-gray-500">No hay símbolos recientes</span>';
      return;
    }

    list.innerHTML = this.recent.slice(0, 10).map(symbol => `
      <button class="recent-btn px-2 py-1 text-xs bg-gray-100 text-gray-700 rounded hover:bg-gray-200" data-symbol="${symbol}">
        🕒 ${symbol}
      </button>
    `).join('');

    // Event listeners para botones de recientes
    list.querySelectorAll('.recent-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const symbol = btn.dataset.symbol;
        this.selectSymbol(symbol);
      });
    });
  }

  updateSymbolInput() {
    const symbolInput = document.getElementById('symbol');
    if (!symbolInput) return;

    const currentSymbol = symbolInput.value.toUpperCase();
    const isFav = this.isFavorite(currentSymbol);

    // Agregar/quitar botón de favorito
    let favBtn = document.getElementById('favorite-toggle-btn');
    if (!favBtn) {
      favBtn = document.createElement('button');
      favBtn.id = 'favorite-toggle-btn';
      favBtn.className = 'ml-2 px-2 py-1 text-xs rounded';
      
      if (symbolInput.parentNode) {
        symbolInput.parentNode.appendChild(favBtn);
      }
    }

    favBtn.textContent = isFav ? '⭐ Quitar' : '⭐ Favorito';
    favBtn.className = `ml-2 px-2 py-1 text-xs rounded ${isFav ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-700'}`;
    
    favBtn.onclick = () => {
      this.toggleFavorite(currentSymbol);
    };
  }

  selectSymbol(symbol) {
    const symbolInput = document.getElementById('symbol');
    if (symbolInput) {
      symbolInput.value = symbol;
      symbolInput.dispatchEvent(new Event('input', { bubbles: true }));
    }
    
    // Agregar a recientes
    this.addRecent(symbol);
  }

  setupKeyboardShortcuts() {
    document.addEventListener('keydown', (e) => {
      // Ctrl+F para agregar/quitar favorito
      if (e.ctrlKey && e.key === 'f') {
        e.preventDefault();
        const symbolInput = document.getElementById('symbol');
        if (symbolInput && symbolInput.value) {
          this.toggleFavorite(symbolInput.value);
        }
      }
      
      // Ctrl+R para limpiar recientes
      if (e.ctrlKey && e.key === 'r') {
        e.preventDefault();
        this.recent = [];
        this.saveToStorage();
        this.updateUI();
      }
    });
  }
}

// Sistema de atajos de teclado avanzados
class KeyboardShortcuts {
  constructor() {
    this.shortcuts = new Map();
    this.init();
  }

  init() {
    this.setupDefaultShortcuts();
    this.setupEventListeners();
  }

  setupDefaultShortcuts() {
    // Atajos básicos
    this.add('ctrl+s', () => {
      const saveBtn = document.getElementById('btn-save-analysis');
      if (saveBtn) saveBtn.click();
    }, 'Guardar análisis');

    this.add('ctrl+enter', () => {
      const runBtn = document.getElementById('run');
      if (runBtn) runBtn.click();
    }, 'Ejecutar análisis');

    this.add('ctrl+l', () => {
      const loadBtn = document.getElementById('btn-load-settings');
      if (loadBtn) loadBtn.click();
    }, 'Cargar ajustes');

    this.add('ctrl+shift+s', () => {
      const saveSettingsBtn = document.getElementById('btn-save-settings');
      if (saveSettingsBtn) saveSettingsBtn.click();
    }, 'Guardar ajustes');

    this.add('ctrl+c', () => {
      const copyBtn = document.getElementById('btn-copy');
      if (copyBtn) copyBtn.click();
    }, 'Copiar resultados');

    this.add('ctrl+f', () => {
      const symbolInput = document.getElementById('symbol');
      if (symbolInput) {
        symbolInput.focus();
        symbolInput.select();
      }
    }, 'Enfocar símbolo');

    this.add('ctrl+shift+f', () => {
      const feedbackBtn = document.getElementById('btn-feedback');
      if (feedbackBtn) feedbackBtn.click();
    }, 'Abrir feedback');

    this.add('ctrl+shift+j', () => {
      window.location.href = '/bolsa/journal.html';
    }, 'Ir a Journal');

    this.add('ctrl+shift+c', () => {
      window.location.href = '/bolsa/config.html';
    }, 'Ir a Configuración');
  }

  add(keys, handler, description = '') {
    this.shortcuts.set(keys, { handler, description });
  }

  remove(keys) {
    this.shortcuts.delete(keys);
  }

  setupEventListeners() {
    document.addEventListener('keydown', (e) => {
      const key = this.getKeyString(e);
      const shortcut = this.shortcuts.get(key);
      
      if (shortcut) {
        e.preventDefault();
        shortcut.handler();
      }
    });
  }

  getKeyString(e) {
    const parts = [];
    
    if (e.ctrlKey) parts.push('ctrl');
    if (e.shiftKey) parts.push('shift');
    if (e.altKey) parts.push('alt');
    if (e.metaKey) parts.push('meta');
    
    parts.push(e.key.toLowerCase());
    
    return parts.join('+');
  }

  getShortcutsList() {
    const list = [];
    this.shortcuts.forEach((shortcut, keys) => {
      list.push({ keys, description: shortcut.description });
    });
    return list;
  }

  showHelp() {
    const shortcuts = this.getShortcutsList();
    const helpText = shortcuts.map(s => `${s.keys}: ${s.description}`).join('\n');
    
    if (window.UIHelpers?.notifications) {
      window.UIHelpers.notifications.show(`Atajos disponibles:\n${helpText}`, 'info', 10000);
    } else {
      alert(`Atajos disponibles:\n${helpText}`);
    }
  }
}

// Sistema de métricas y analytics
class MetricsCollector {
  constructor() {
    this.metrics = {
      analyses: 0,
      favorites: 0,
      errors: 0,
      sessionStart: Date.now(),
      lastActivity: Date.now()
    };
    this.init();
  }

  init() {
    this.setupActivityTracking();
    this.setupErrorTracking();
  }

  setupActivityTracking() {
    // Actualizar última actividad en eventos de usuario
    const events = ['click', 'keydown', 'mousemove', 'scroll'];
    events.forEach(event => {
      document.addEventListener(event, () => {
        this.metrics.lastActivity = Date.now();
      }, { passive: true });
    });
  }

  setupErrorTracking() {
    window.addEventListener('error', (e) => {
      this.metrics.errors++;
      this.logError(e.error, e.filename, e.lineno);
    });

    window.addEventListener('unhandledrejection', (e) => {
      this.metrics.errors++;
      this.logError(e.reason, 'Promise rejection');
    });
  }

  incrementAnalyses() {
    this.metrics.analyses++;
  }

  incrementFavorites() {
    this.metrics.favorites++;
  }

  logError(error, source = '', line = '') {
    console.error('Error tracked:', { error, source, line, timestamp: new Date().toISOString() });
    
    // Enviar error al servidor si está disponible
    if (Config?.API_BASE) {
      fetch(`${Config.API_BASE}/log_debug.php`, {
        method: 'POST',
        headers: Config.withAuthHeaders({ 'Content-Type': 'application/json' }, true),
        body: JSON.stringify({
          type: 'error',
          error: error?.message || String(error),
          source,
          line,
          stack: error?.stack,
          timestamp: new Date().toISOString()
        })
      }).catch(() => {}); // Ignorar errores de logging
    }
  }

  getSessionDuration() {
    return Date.now() - this.metrics.sessionStart;
  }

  getTimeSinceLastActivity() {
    return Date.now() - this.metrics.lastActivity;
  }

  getMetrics() {
    return {
      ...this.metrics,
      sessionDuration: this.getSessionDuration(),
      timeSinceLastActivity: this.getTimeSinceLastActivity()
    };
  }

  reset() {
    this.metrics = {
      analyses: 0,
      favorites: 0,
      errors: 0,
      sessionStart: Date.now(),
      lastActivity: Date.now()
    };
  }
}

// Sistema de caché inteligente para datos de mercado
class MarketDataCache {
  constructor() {
    this.cache = new Map();
    this.ttl = 5 * 60 * 1000; // 5 minutos
    this.maxSize = 100;
    this.init();
  }

  init() {
    // Limpiar caché cada 10 minutos
    setInterval(() => {
      this.cleanup();
    }, 10 * 60 * 1000);
  }

  set(key, data, customTTL = null) {
    const expiration = Date.now() + (customTTL || this.ttl);
    
    // Limitar tamaño del caché
    if (this.cache.size >= this.maxSize) {
      const firstKey = this.cache.keys().next().value;
      this.cache.delete(firstKey);
    }
    
    this.cache.set(key, {
      data,
      expiration,
      timestamp: Date.now()
    });
  }

  get(key) {
    const item = this.cache.get(key);
    if (!item) return null;

    if (Date.now() > item.expiration) {
      this.cache.delete(key);
      return null;
    }

    return item.data;
  }

  has(key) {
    return this.get(key) !== null;
  }

  delete(key) {
    this.cache.delete(key);
  }

  clear() {
    this.cache.clear();
  }

  cleanup() {
    const now = Date.now();
    this.cache.forEach((item, key) => {
      if (now > item.expiration) {
        this.cache.delete(key);
      }
    });
  }

  getStats() {
    const now = Date.now();
    let valid = 0;
    let expired = 0;

    this.cache.forEach(item => {
      if (now > item.expiration) {
        expired++;
      } else {
        valid++;
      }
    });

    return {
      total: this.cache.size,
      valid,
      expired,
      hitRate: valid / this.cache.size || 0
    };
  }
}

// Inicializar sistemas avanzados
const favoritesManager = new FavoritesManager();
const keyboardShortcuts = new KeyboardShortcuts();
const metricsCollector = new MetricsCollector();
const marketDataCache = new MarketDataCache();

// Exportar para uso global
window.AdvancedFeatures = {
  favorites: favoritesManager,
  shortcuts: keyboardShortcuts,
  metrics: metricsCollector,
  cache: marketDataCache
};

// Hacer funciones disponibles globalmente para compatibilidad
window.Favorites = favoritesManager;
window.KeyboardShortcuts = keyboardShortcuts;
window.Metrics = metricsCollector;
window.MarketCache = marketDataCache;

// Atajo para mostrar ayuda de teclado
window.showKeyboardHelp = () => keyboardShortcuts.showHelp();
