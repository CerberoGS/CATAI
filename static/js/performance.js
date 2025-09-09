/* ================== Optimización de Rendimiento ================== */

// Sistema de debouncing para búsquedas
class Debouncer {
  constructor(delay = 300) {
    this.delay = delay;
    this.timeoutId = null;
  }

  debounce(func) {
    return (...args) => {
      clearTimeout(this.timeoutId);
      this.timeoutId = setTimeout(() => func.apply(this, args), this.delay);
    };
  }
}

// Caché inteligente con TTL
class SmartCache {
  constructor(maxSize = 50, defaultTTL = 300000) { // 5 minutos por defecto
    this.cache = new Map();
    this.maxSize = maxSize;
    this.defaultTTL = defaultTTL;
  }

  set(key, value, ttl = this.defaultTTL) {
    // Limpiar caché si está lleno
    if (this.cache.size >= this.maxSize) {
      const firstKey = this.cache.keys().next().value;
      this.cache.delete(firstKey);
    }

    this.cache.set(key, {
      value,
      timestamp: Date.now(),
      ttl
    });
  }

  get(key) {
    const item = this.cache.get(key);
    if (!item) return null;

    const now = Date.now();
    if (now - item.timestamp > item.ttl) {
      this.cache.delete(key);
      return null;
    }

    return item.value;
  }

  clear() {
    this.cache.clear();
  }

  cleanup() {
    const now = Date.now();
    for (const [key, item] of this.cache.entries()) {
      if (now - item.timestamp > item.ttl) {
        this.cache.delete(key);
      }
    }
  }
}

// Inicializar sistemas de optimización
const debouncer = new Debouncer(300);
const smartCache = new SmartCache();

// Optimización de eventos con delegación
function optimizeEventListeners() {
  // Delegar eventos para elementos dinámicos
  document.addEventListener('click', (e) => {
    if (e.target.matches('[data-action="toggle-favorite"]')) {
      // Manejar toggle de favoritos
      e.preventDefault();
      const symbol = e.target.dataset.symbol;
      if (symbol) {
        Favorites.manager.toggleFavorite(symbol);
      }
    }
  });

  // Limpiar caché periódicamente
  setInterval(() => {
    smartCache.cleanup();
  }, 60000); // Cada minuto
}

// Render Optimizer
class RenderOptimizer {
  constructor() {
    this.renderQueue = [];
    this.isProcessing = false;
  }

  queue(renderFunction, priority = 0) {
    this.renderQueue.push({ renderFunction, priority });
    this.renderQueue.sort((a, b) => b.priority - a.priority);
    
    if (!this.isProcessing) {
      this.processQueue();
    }
  }

  async processQueue() {
    this.isProcessing = true;
    
    while (this.renderQueue.length > 0) {
      const { renderFunction } = this.renderQueue.shift();
      try {
        await renderFunction();
      } catch (error) {
        console.error('Render error:', error);
      }
      
      // Permitir que el navegador respire
      await new Promise(resolve => setTimeout(resolve, 0));
    }
    
    this.isProcessing = false;
  }
}

const renderOptimizer = new RenderOptimizer();

// API Request Manager
class APIRequestManager {
  constructor() {
    this.activeRequests = new Map();
    this.requestQueue = [];
    this.maxConcurrent = 3;
  }

  async request(key, requestFunction) {
    // Si ya hay una request activa con la misma key, esperar
    if (this.activeRequests.has(key)) {
      return this.activeRequests.get(key);
    }

    // Si hay demasiadas requests activas, encolar
    if (this.activeRequests.size >= this.maxConcurrent) {
      return new Promise((resolve, reject) => {
        this.requestQueue.push({ key, requestFunction, resolve, reject });
      });
    }

    const promise = this.executeRequest(key, requestFunction);
    this.activeRequests.set(key, promise);
    
    return promise;
  }

  async executeRequest(key, requestFunction) {
    try {
      const result = await requestFunction();
      return result;
    } finally {
      this.activeRequests.delete(key);
      this.processQueue();
    }
  }

  processQueue() {
    if (this.requestQueue.length > 0 && this.activeRequests.size < this.maxConcurrent) {
      const { key, requestFunction, resolve, reject } = this.requestQueue.shift();
      this.request(key, requestFunction).then(resolve).catch(reject);
    }
  }
}

const apiRequestManager = new APIRequestManager();

// Inicializar optimizaciones
optimizeEventListeners();

// Exportar para uso en otros módulos
window.Performance = {
  debouncer,
  smartCache,
  renderOptimizer,
  apiRequestManager,
  optimizeEventListeners
};
