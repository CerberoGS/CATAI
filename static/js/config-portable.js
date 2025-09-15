/**
 * Configuración portable para frontend
 * Detecta automáticamente la URL base y proporciona helpers para rutas
 */

(function() {
  'use strict';

  // Detectar URL base automáticamente
  function detectBaseUrl() {
    const origin = window.location.origin;
    const pathname = window.location.pathname;
    
    // Extraer la ruta base (sin el archivo específico)
    const pathParts = pathname.split('/').filter(part => part);
    
    // Si estamos en un archivo HTML específico, removerlo
    if (pathParts.length > 0 && pathParts[pathParts.length - 1].endsWith('.html')) {
      pathParts.pop();
    }
    
    // Construir la URL base
    const basePath = pathParts.length > 0 ? '/' + pathParts.join('/') : '';
    return origin + basePath;
  }

  // Configuración portable
  const ConfigPortable = {
    // URL base detectada automáticamente
    BASE_URL: detectBaseUrl(),
    
    // URL de API (relativa a la base)
    get API_BASE_URL() {
      return this.BASE_URL + '/api';
    },
    
    // Helper para construir URLs de API
    getApiUrl: function(endpoint = '') {
      const apiBase = this.API_BASE_URL;
      return apiBase + (endpoint ? '/' + endpoint.replace(/^\//, '') : '');
    },
    
    // Helper para URLs estáticas
    getStaticUrl: function(path = '') {
      const staticBase = this.BASE_URL + '/static';
      return staticBase + (path ? '/' + path.replace(/^\//, '') : '');
    },
    
    // Helper para URLs de páginas
    getPageUrl: function(page = '') {
      const pageBase = this.BASE_URL;
      return pageBase + (page ? '/' + page.replace(/^\//, '') : '');
    }
  };

  // Exponer globalmente
  window.ConfigPortable = ConfigPortable;
  
  // Log para debugging (solo en desarrollo)
  if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
    console.log('ConfigPortable detectado:', {
      BASE_URL: ConfigPortable.BASE_URL,
      API_BASE_URL: ConfigPortable.API_BASE_URL,
      currentLocation: window.location.href
    });
  }
})();
