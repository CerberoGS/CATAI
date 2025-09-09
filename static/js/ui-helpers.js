/* ================== UI Helpers y Utilidades ================== */

// Sistema de notificaciones mejorado
class NotificationSystem {
  constructor() {
    this.container = null;
    this.notifications = new Map();
    this.init();
  }

  init() {
    this.createContainer();
  }

  createContainer() {
    this.container = document.createElement('div');
    this.container.id = 'notification-container';
    this.container.className = 'fixed top-4 right-4 z-50 space-y-2';
    document.body.appendChild(this.container);
  }

  show(message, type = 'info', duration = 5000) {
    const id = Date.now() + Math.random();
    const notification = this.createNotification(id, message, type);
    
    this.container.appendChild(notification);
    this.notifications.set(id, notification);

    // Animar entrada
    setTimeout(() => {
      notification.classList.add('opacity-100', 'translate-x-0');
    }, 10);

    // Auto-remover
    if (duration > 0) {
      setTimeout(() => {
        this.hide(id);
      }, duration);
    }

    return id;
  }

  createNotification(id, message, type) {
    const notification = document.createElement('div');
    notification.id = `notification-${id}`;
    notification.className = `
      max-w-sm w-full bg-white shadow-lg rounded-lg pointer-events-auto ring-1 ring-black ring-opacity-5 overflow-hidden
      transform transition-all duration-300 ease-in-out
      opacity-0 translate-x-full
    `;

    const colors = {
      success: 'bg-green-50 border-green-200 text-green-800',
      error: 'bg-red-50 border-red-200 text-red-800',
      warning: 'bg-yellow-50 border-yellow-200 text-yellow-800',
      info: 'bg-blue-50 border-blue-200 text-blue-800'
    };

    const icons = {
      success: '✅',
      error: '❌',
      warning: '⚠️',
      info: 'ℹ️'
    };

    notification.innerHTML = `
      <div class="p-4">
        <div class="flex items-start">
          <div class="flex-shrink-0">
            <span class="text-lg">${icons[type] || icons.info}</span>
          </div>
          <div class="ml-3 w-0 flex-1">
            <p class="text-sm font-medium">${message}</p>
          </div>
          <div class="ml-4 flex-shrink-0 flex">
            <button class="bg-white rounded-md inline-flex text-gray-400 hover:text-gray-500 focus:outline-none" onclick="window.UIHelpers.notifications.hide('${id}')">
              <span class="sr-only">Cerrar</span>
              <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
              </svg>
            </button>
          </div>
        </div>
      </div>
    `;

    return notification;
  }

  hide(id) {
    const notification = this.notifications.get(id);
    if (notification) {
      notification.classList.add('opacity-0', 'translate-x-full');
      setTimeout(() => {
        if (notification.parentNode) {
          notification.parentNode.removeChild(notification);
        }
        this.notifications.delete(id);
      }, 300);
    }
  }

  clear() {
    this.notifications.forEach((notification, id) => {
      this.hide(id);
    });
  }
}

// Sistema de indicadores de estado
class StatusIndicator {
  constructor(elementId, initialState = 'idle') {
    this.element = document.getElementById(elementId);
    this.state = initialState;
    this.states = {
      idle: { text: '', class: '', icon: '' },
      loading: { text: 'Cargando...', class: 'text-blue-600', icon: '⏳' },
      success: { text: 'Completado', class: 'text-green-600', icon: '✅' },
      error: { text: 'Error', class: 'text-red-600', icon: '❌' },
      warning: { text: 'Advertencia', class: 'text-yellow-600', icon: '⚠️' }
    };
    this.update(initialState);
  }

  setState(state, message = null) {
    this.state = state;
    this.update(state, message);
  }

  update(state, customMessage = null) {
    if (!this.element) return;

    const stateConfig = this.states[state] || this.states.idle;
    const message = customMessage || stateConfig.text;
    
    this.element.textContent = message;
    this.element.className = `text-sm ${stateConfig.class}`;
    
    if (stateConfig.icon) {
      this.element.innerHTML = `${stateConfig.icon} ${message}`;
    }
  }

  reset() {
    this.setState('idle');
  }
}

// Sistema de debouncing para optimización
class Debouncer {
  constructor(delay = 300) {
    this.delay = delay;
    this.timeouts = new Map();
  }

  debounce(key, callback) {
    // Cancelar timeout anterior si existe
    if (this.timeouts.has(key)) {
      clearTimeout(this.timeouts.get(key));
    }

    // Crear nuevo timeout
    const timeout = setTimeout(() => {
      callback();
      this.timeouts.delete(key);
    }, this.delay);

    this.timeouts.set(key, timeout);
  }

  cancel(key) {
    if (this.timeouts.has(key)) {
      clearTimeout(this.timeouts.get(key));
      this.timeouts.delete(key);
    }
  }

  cancelAll() {
    this.timeouts.forEach(timeout => clearTimeout(timeout));
    this.timeouts.clear();
  }
}

// Sistema de caché inteligente
class SmartCache {
  constructor(defaultTTL = 5 * 60 * 1000) { // 5 minutos por defecto
    this.cache = new Map();
    this.defaultTTL = defaultTTL;
  }

  set(key, value, ttl = null) {
    const expiration = Date.now() + (ttl || this.defaultTTL);
    this.cache.set(key, {
      value,
      expiration,
      ttl: ttl || this.defaultTTL
    });
  }

  get(key) {
    const item = this.cache.get(key);
    if (!item) return null;

    if (Date.now() > item.expiration) {
      this.cache.delete(key);
      return null;
    }

    return item.value;
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

  // Obtener estadísticas del caché
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

  // Limpiar elementos expirados
  cleanup() {
    const now = Date.now();
    this.cache.forEach((item, key) => {
      if (now > item.expiration) {
        this.cache.delete(key);
      }
    });
  }
}

// Optimizador de event listeners
class EventOptimizer {
  constructor() {
    this.listeners = new Map();
  }

  // Agregar listener con delegación de eventos
  addDelegatedListener(container, selector, event, handler) {
    const key = `${container.id || 'document'}-${selector}-${event}`;
    
    if (!this.listeners.has(key)) {
      const listener = (e) => {
        const target = e.target.closest(selector);
        if (target) {
          handler.call(target, e);
        }
      };
      
      container.addEventListener(event, listener);
      this.listeners.set(key, { container, event, listener });
    }
  }

  // Remover listener delegado
  removeDelegatedListener(container, selector, event) {
    const key = `${container.id || 'document'}-${selector}-${event}`;
    const listenerData = this.listeners.get(key);
    
    if (listenerData) {
      listenerData.container.removeEventListener(listenerData.event, listenerData.listener);
      this.listeners.delete(key);
    }
  }

  // Limpiar todos los listeners
  cleanup() {
    this.listeners.forEach(({ container, event, listener }) => {
      container.removeEventListener(event, listener);
    });
    this.listeners.clear();
  }
}

// Utilidades de formateo
const Formatters = {
  // Formatear números con decimales
  number(value, decimals = 2) {
    if (value == null || Number.isNaN(value)) return '—';
    return typeof value === 'number' ? value.toFixed(decimals) : value;
  },

  // Formatear porcentajes
  percentage(value, decimals = 2) {
    if (value == null || Number.isNaN(value)) return '—';
    return `${this.number(value, decimals)}%`;
  },

  // Formatear moneda
  currency(value, symbol = '$', decimals = 2) {
    if (value == null || Number.isNaN(value)) return '—';
    return `${symbol}${this.number(value, decimals)}`;
  },

  // Formatear fechas
  date(value, options = {}) {
    if (!value) return '—';
    const date = new Date(value);
    if (isNaN(date.getTime())) return '—';
    
    const defaultOptions = {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    };
    
    return date.toLocaleDateString('es-ES', { ...defaultOptions, ...options });
  },

  // Formatear texto con límite de caracteres
  text(value, maxLength = 100) {
    if (!value) return '—';
    if (value.length <= maxLength) return value;
    return value.substring(0, maxLength) + '...';
  }
};

// Utilidades de validación
const Validators = {
  email(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
  },

  symbol(symbol) {
    return /^[A-Z]{1,5}$/.test(symbol);
  },

  number(value, min = null, max = null) {
    const num = parseFloat(value);
    if (isNaN(num)) return false;
    if (min !== null && num < min) return false;
    if (max !== null && num > max) return false;
    return true;
  },

  required(value) {
    return value != null && value.toString().trim() !== '';
  }
};

// Utilidades de DOM
const DOMUtils = {
  // Crear elemento con clases y contenido
  createElement(tag, className = '', content = '') {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (content) element.innerHTML = content;
    return element;
  },

  // Mostrar/ocultar elemento
  toggle(element, show = null) {
    if (!element) return;
    if (show === null) {
      element.classList.toggle('hidden');
    } else {
      element.classList.toggle('hidden', !show);
    }
  },

  // Agregar/remover clases condicionalmente
  toggleClass(element, className, condition) {
    if (!element) return;
    element.classList.toggle(className, condition);
  },

  // Obtener valor de elemento de forma segura
  getValue(elementId, defaultValue = '') {
    const element = document.getElementById(elementId);
    return element ? element.value : defaultValue;
  },

  // Establecer valor de elemento de forma segura
  setValue(elementId, value) {
    const element = document.getElementById(elementId);
    if (element) element.value = value;
  }
};

// Inicializar sistemas globales
const notifications = new NotificationSystem();
const debouncer = new Debouncer();
const cache = new SmartCache();
const eventOptimizer = new EventOptimizer();

// Exportar para uso global
window.UIHelpers = {
  notifications,
  StatusIndicator,
  Debouncer,
  SmartCache,
  EventOptimizer,
  Formatters,
  Validators,
  DOMUtils,
  debouncer,
  cache,
  eventOptimizer
};

// Hacer funciones disponibles globalmente para compatibilidad
window.Notifications = notifications;
window.Formatters = Formatters;
window.Validators = Validators;
window.DOMUtils = DOMUtils;
