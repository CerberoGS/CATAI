// Sistema de notificaciones mejorado para Bolsa AI
(function() {
  'use strict';

  class NotificationSystem {
    constructor() {
      this.container = null;
      this.notifications = new Map();
      this.maxNotifications = 5;
      this.defaultDuration = 5000;
      this.init();
    }

    init() {
      // Crear contenedor si no existe
      if (!document.getElementById('notification-container')) {
        const container = document.createElement('div');
        container.id = 'notification-container';
        container.className = 'fixed top-4 right-4 z-50 space-y-2 max-w-sm';
        document.body.appendChild(container);
      }
      
      this.container = document.getElementById('notification-container');
      this.loadNotifications();
      this.startPolling();
    }

    show(message, type = 'info', options = {}) {
      const id = this.generateId();
      const duration = options.duration || this.defaultDuration;
      const persistent = options.persistent || false;
      const actions = options.actions || [];

      const notification = this.createNotification(id, message, type, actions);
      this.container.appendChild(notification);
      this.notifications.set(id, notification);

      // Limitar número de notificaciones
      this.limitNotifications();

      // Auto-remove si no es persistente
      if (!persistent && duration > 0) {
        setTimeout(() => {
          this.remove(id);
        }, duration);
      }

      // Animación de entrada
      requestAnimationFrame(() => {
        notification.classList.add('animate-in');
      });

      return id;
    }

    createNotification(id, message, type, actions) {
      const notification = document.createElement('div');
      notification.id = `notification-${id}`;
      notification.className = `notification-item bg-white border-l-4 shadow-lg rounded-lg p-4 transform transition-all duration-300 ${
        type === 'success' ? 'border-green-500' : 
        type === 'error' ? 'border-red-500' : 
        type === 'warning' ? 'border-yellow-500' : 'border-blue-500'
      }`;

      const icon = this.getIcon(type);
      const actionsHtml = actions.length > 0 ? this.createActionsHtml(actions, id) : '';

      notification.innerHTML = `
        <div class="flex items-start">
          <div class="flex-shrink-0">
            ${icon}
          </div>
          <div class="ml-3 flex-1">
            <p class="text-sm font-medium text-gray-900">${message}</p>
            ${actionsHtml}
          </div>
          <div class="ml-4 flex-shrink-0">
            <button onclick="window.notificationSystem.remove('${id}')" 
                    class="text-gray-400 hover:text-gray-600 transition-colors">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
              </svg>
            </button>
          </div>
        </div>
      `;

      return notification;
    }

    getIcon(type) {
      const iconClass = `w-5 h-5 ${
        type === 'success' ? 'text-green-500' : 
        type === 'error' ? 'text-red-500' : 
        type === 'warning' ? 'text-yellow-500' : 'text-blue-500'
      }`;

      const icons = {
        success: `<svg class="${iconClass}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>`,
        error: `<svg class="${iconClass}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>`,
        warning: `<svg class="${iconClass}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
        </svg>`,
        info: `<svg class="${iconClass}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>`
      };

      return icons[type] || icons.info;
    }

    createActionsHtml(actions, notificationId) {
      const actionsHtml = actions.map(action => 
        `<button onclick="window.notificationSystem.handleAction('${notificationId}', '${action.id}')" 
                class="mr-2 text-sm font-medium ${action.class || 'text-blue-600 hover:text-blue-800'}">
          ${action.label}
        </button>`
      ).join('');

      return `<div class="mt-2">${actionsHtml}</div>`;
    }

    remove(id) {
      const notification = this.notifications.get(id);
      if (notification) {
        notification.classList.add('animate-out');
        setTimeout(() => {
          if (notification.parentElement) {
            notification.remove();
          }
          this.notifications.delete(id);
        }, 300);
      }
    }

    limitNotifications() {
      if (this.notifications.size > this.maxNotifications) {
        const oldestId = this.notifications.keys().next().value;
        this.remove(oldestId);
      }
    }

    generateId() {
      return 'notif_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }

    handleAction(notificationId, actionId) {
      // Emitir evento personalizado para manejar acciones
      const event = new CustomEvent('notificationAction', {
        detail: { notificationId, actionId }
      });
      document.dispatchEvent(event);
      
      this.remove(notificationId);
    }

    async loadNotifications() {
      try {
        if (window.Auth && window.Auth.getToken()) {
          const response = await window.Auth.fetchJson('notifications_safe.php?unread_only=true&limit=5');
          if (response.ok && response.data) {
            response.data.notifications.forEach(notif => {
              this.show(notif.message, notif.type, {
                persistent: true,
                actions: [
                  { id: 'mark_read', label: 'Marcar como leída', class: 'text-gray-600 hover:text-gray-800' }
                ]
              });
            });
          }
        }
      } catch (error) {
        console.error('Error loading notifications:', error);
      }
    }

    startPolling() {
      // Polling cada 30 segundos para nuevas notificaciones
      setInterval(() => {
        this.loadNotifications();
      }, 30000);
    }

    // Métodos de conveniencia
    success(message, options = {}) {
      return this.show(message, 'success', options);
    }

    error(message, options = {}) {
      return this.show(message, 'error', options);
    }

    warning(message, options = {}) {
      return this.show(message, 'warning', options);
    }

    info(message, options = {}) {
      return this.show(message, 'info', options);
    }

    // Notificaciones específicas de la aplicación
    analysisComplete(symbol, duration) {
      return this.success(`Análisis de ${symbol} completado en ${duration}ms`, {
        actions: [
          { id: 'view_results', label: 'Ver resultados', class: 'text-blue-600 hover:text-blue-800' },
          { id: 'save_analysis', label: 'Guardar', class: 'text-green-600 hover:text-green-800' }
        ]
      });
    }

    analysisError(symbol, error) {
      return this.error(`Error en análisis de ${symbol}: ${error}`, {
        actions: [
          { id: 'retry', label: 'Reintentar', class: 'text-blue-600 hover:text-blue-800' },
          { id: 'report_bug', label: 'Reportar error', class: 'text-red-600 hover:text-red-800' }
        ]
      });
    }

    quotaWarning(endpoint, used, limit) {
      return this.warning(`Cuota de ${endpoint}: ${used}/${limit} usos hoy`, {
        persistent: true,
        actions: [
          { id: 'upgrade', label: 'Actualizar plan', class: 'text-blue-600 hover:text-blue-800' }
        ]
      });
    }

    systemUpdate(message) {
      return this.info(`Actualización del sistema: ${message}`, {
        persistent: true,
        actions: [
          { id: 'reload', label: 'Recargar página', class: 'text-blue-600 hover:text-blue-800' }
        ]
      });
    }
  }

  // Inicializar sistema de notificaciones
  window.notificationSystem = new NotificationSystem();

  // Manejar acciones de notificaciones
  document.addEventListener('notificationAction', (event) => {
    const { notificationId, actionId } = event.detail;
    
    switch (actionId) {
      case 'mark_read':
        // Marcar notificación como leída en el servidor
        if (window.Auth) {
          window.Auth.fetchJson('notifications_safe.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'mark_read', id: notificationId })
          });
        }
        break;
        
      case 'view_results':
        // Scroll a resultados
        const resultsSection = document.getElementById('results-section');
        if (resultsSection) {
          resultsSection.scrollIntoView({ behavior: 'smooth' });
        }
        break;
        
      case 'save_analysis':
        // Guardar análisis actual
        const saveButton = document.getElementById('btn-save-analysis');
        if (saveButton) {
          saveButton.click();
        }
        break;
        
      case 'retry':
        // Reintentar análisis
        const analyzeButton = document.getElementById('btn-analyze');
        if (analyzeButton) {
          analyzeButton.click();
        }
        break;
        
      case 'reload':
        // Recargar página
        window.location.reload();
        break;
        
      default:
        console.log('Acción de notificación no manejada:', actionId);
    }
  });

  // CSS para animaciones
  const style = document.createElement('style');
  style.textContent = `
    .notification-item {
      transform: translateX(100%);
      opacity: 0;
    }
    
    .notification-item.animate-in {
      transform: translateX(0);
      opacity: 1;
    }
    
    .notification-item.animate-out {
      transform: translateX(100%);
      opacity: 0;
    }
    
    @media (prefers-reduced-motion: reduce) {
      .notification-item {
        transition: none;
      }
    }
  `;
  document.head.appendChild(style);

})();
