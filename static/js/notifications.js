/* ================== Sistema de Notificaciones y Feedback Visual Mejorado ================== */

// Sistema de notificaciones avanzado
class NotificationSystem {
  constructor() {
    this.container = null;
    this.notifications = new Map();
    this.init();
  }

  init() {
    // Crear contenedor de notificaciones si no existe
    if (!document.getElementById('notification-container')) {
      this.container = document.createElement('div');
      this.container.id = 'notification-container';
      this.container.className = 'fixed top-4 right-4 z-50 space-y-2';
      document.body.appendChild(this.container);
    } else {
      this.container = document.getElementById('notification-container');
    }
  }

  show(message, type = 'info', duration = 4000) {
    const id = Date.now() + Math.random();
    const notification = this.createNotification(id, message, type);
    
    this.container.appendChild(notification);
    this.notifications.set(id, notification);

    // Auto-remove después de la duración
    setTimeout(() => {
      this.remove(id);
    }, duration);

    return id;
  }

  createNotification(id, message, type) {
    const notification = document.createElement('div');
    notification.id = `notification-${id}`;
    
    const colors = {
      success: 'bg-green-500 text-white',
      error: 'bg-red-500 text-white',
      warning: 'bg-yellow-500 text-black',
      info: 'bg-blue-500 text-white',
      loading: 'bg-gray-500 text-white'
    };

    const icons = {
      success: '✅',
      error: '❌',
      warning: '⚠️',
      info: 'ℹ️',
      loading: '⏳'
    };

    notification.className = `${colors[type]} px-4 py-3 rounded-lg shadow-lg transform transition-all duration-300 ease-in-out translate-x-full opacity-0`;
    notification.innerHTML = `
      <div class="flex items-center space-x-2">
        <span class="text-lg">${icons[type]}</span>
        <span class="text-sm font-medium">${message}</span>
        <button onclick="notificationSystem.remove(${id})" class="ml-2 text-white hover:text-gray-200">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        </button>
      </div>
    `;

    // Animar entrada
    setTimeout(() => {
      notification.classList.remove('translate-x-full', 'opacity-0');
      notification.classList.add('translate-x-0', 'opacity-100');
    }, 10);

    return notification;
  }

  remove(id) {
    const notification = this.notifications.get(id);
    if (notification) {
      notification.classList.add('translate-x-full', 'opacity-0');
      setTimeout(() => {
        if (notification.parentNode) {
          notification.parentNode.removeChild(notification);
        }
        this.notifications.delete(id);
      }, 300);
    }
  }
}

// Inicializar sistema de notificaciones
const notificationSystem = new NotificationSystem();

// Función helper para mostrar notificaciones
function toast(message, type = 'info', duration = 4000) {
  return notificationSystem.show(message, type, duration);
}

// Sistema de indicadores de estado
class StatusIndicator {
  constructor(elementId) {
    this.element = document.getElementById(elementId);
    this.states = {
      idle: { text: 'Listo', class: 'text-gray-500', icon: '⚪' },
      loading: { text: 'Cargando...', class: 'text-blue-500', icon: '⏳' },
      success: { text: 'Completado', class: 'text-green-500', icon: '✅' },
      error: { text: 'Error', class: 'text-red-500', icon: '❌' }
    };
    this.currentState = 'idle';
  }

  setState(state, customText = null) {
    if (!this.element || !this.states[state]) return;
    
    this.currentState = state;
    const stateConfig = this.states[state];
    const text = customText || stateConfig.text;
    
    this.element.innerHTML = `<span class="${stateConfig.class}">${stateConfig.icon} ${text}</span>`;
  }

  reset() {
    this.setState('idle');
  }
}

// Inicializar indicadores de estado
const statusIndicators = {
  universe: new StatusIndicator('universe-status'),
  analysis: new StatusIndicator('analysis-status'),
  settings: new StatusIndicator('settings-status')
};

// Exportar para uso en otros módulos
window.Notifications = {
  system: notificationSystem,
  toast,
  StatusIndicator,
  statusIndicators
};
