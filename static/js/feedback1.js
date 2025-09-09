/* ================== Sistema de Feedback Completo ================== */

class FeedbackSystem {
  constructor() {
    this.modalId = 'modal-fb';
    this.init();
  }

  init() {
    this.setupEventListeners();
    this.updateTriageButtonVisibility();
  }

  updateTriageButtonVisibility() {
    const triageBtn = document.getElementById('btn-feedback-triage');
    if (triageBtn) {
      if (this.isAdmin()) {
        triageBtn.classList.remove('hidden');
      } else {
        triageBtn.classList.add('hidden');
      }
    }
  }

  setupEventListeners() {
    // Botones principales de feedback (hay dos con el mismo ID)
    const feedbackBtns = document.querySelectorAll('#btn-feedback');
    feedbackBtns.forEach(btn => {
      btn.addEventListener('click', () => {
        this.show();
      });
    });

    // Botón de triage (admin)
    const triageBtn = document.getElementById('btn-feedback-triage');
    triageBtn?.addEventListener('click', () => {
      this.showTriage();
    });

    // Event listeners del modal
    this.setupModalEventListeners();
  }

  setupModalEventListeners() {
    // Botón cerrar modal
    const closeBtn = document.getElementById('fb-close');
    closeBtn?.addEventListener('click', () => {
      this.close();
    });

    // Botón cancelar
    const cancelBtn = document.getElementById('fb-cancel');
    cancelBtn?.addEventListener('click', () => {
      this.close();
    });

    // Botón enviar
    const sendBtn = document.getElementById('fb-send');
    sendBtn?.addEventListener('click', () => {
      this.send();
    });

    // Cerrar modal al hacer clic fuera
    const modal = document.getElementById('modal-fb');
    modal?.addEventListener('click', (e) => {
      if (e.target === modal) {
        this.close();
      }
    });
  }

  show() {
    this.prepareModal();
    const modal = document.getElementById('modal-fb');
    if (modal) {
      modal.classList.remove('hidden');
      modal.classList.add('flex');
    }
  }

  close() {
    const modal = document.getElementById('modal-fb');
    if (modal) {
      modal.classList.add('hidden');
      modal.classList.remove('flex');
    }
  }

  showTriage() {
    // Verificar si el usuario es administrador
    if (!this.isAdmin()) {
      // Si no es admin, mostrar vista de usuario regular
      this.showUserFeedback();
      return;
    }
    
    // Si es admin, mostrar vista de triage completa
    this.prepareTriageModal();
    const modal = document.getElementById('modal-fb');
    if (modal) {
      modal.classList.remove('hidden');
      modal.classList.add('flex');
    }
  }

  isAdmin() {
    // Verificar si el usuario actual es administrador
    try {
      const user = window.Auth?.getUser() || {};
      return user.role === 'admin' || 
             (Array.isArray(user.roles) && user.roles.includes('admin')) ||
             user.is_admin === true;
    } catch (e) {
      return false;
    }
  }

  showUserFeedback() {
    // Vista para usuarios regulares - solo sus propios feedbacks
    this.prepareUserFeedbackModal();
    const modal = document.getElementById('modal-fb');
    if (modal) {
      modal.classList.remove('hidden');
      modal.classList.add('flex');
    }
  }

  prepareModal() {
    const fbBody = document.getElementById('fb-body');
    if (!fbBody) return;

    fbBody.innerHTML = `
      <div>
        <label class="block text-sm text-gray-700">Tipo de feedback</label>
        <select id="fb-type" class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
          <option value="bug">🐛 Bug/Error</option>
          <option value="mejora">⚡ Mejora</option>
          <option value="ux">🎨 UX/Interfaz</option>
          <option value="idea">💡 Idea/Sugerencia</option>
        </select>
      </div>
      <div>
        <label class="block text-sm text-gray-700">Título</label>
        <input id="fb-title" class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="Describe brevemente el tema">
      </div>
      <div>
        <label class="block text-sm text-gray-700">Descripción</label>
        <textarea id="fb-description" rows="4" class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="Proporciona más detalles..."></textarea>
      </div>
      <div>
        <label class="block text-sm text-gray-700">Adjuntar archivos</label>
        <input id="fb-files" type="file" accept="image/*,.pdf,.txt" multiple class="mt-1"/>
        <div id="fb-previews" class="mt-2 grid grid-cols-4 gap-2"></div>
      </div>
      <div class="bg-gray-50 p-3 rounded text-xs">
        <strong>Información del sistema:</strong><br>
        <span id="fb-diagnostics"></span>
      </div>
    `;

    this.generateDiagnostics();
    this.setupFileHandlers();
  }

  prepareUserFeedbackModal() {
    const fbBody = document.getElementById('fb-body');
    if (!fbBody) return;

    fbBody.innerHTML = `
      <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded">
        <h3 class="text-sm font-medium text-blue-800">Mis Feedback Enviados</h3>
        <p class="text-xs text-blue-700 mt-1">Aquí puedes ver el estado de tus reportes y comentarios del administrador</p>
      </div>
      <div>
        <label class="block text-sm text-gray-700">Filtrar por estado</label>
        <select id="fb-user-filter-status" class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
          <option value="">Todos</option>
          <option value="nuevo">Nuevo</option>
          <option value="en_progreso">En progreso</option>
          <option value="resuelto">Resuelto</option>
          <option value="rechazado">Rechazado</option>
        </select>
      </div>
      <div>
        <label class="block text-sm text-gray-700">Filtrar por tipo</label>
        <select id="fb-user-filter-type" class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
          <option value="">Todos</option>
          <option value="bug">Bug/Error</option>
          <option value="mejora">Mejora</option>
          <option value="ux">UX/Interfaz</option>
          <option value="idea">Idea/Sugerencia</option>
        </select>
      </div>
      <div id="fb-user-list" class="mt-4 max-h-96 overflow-y-auto">
        <div class="text-center text-gray-500 py-4">Cargando tus feedback...</div>
      </div>
    `;

    this.loadUserFeedbackData();
    this.setupUserFeedbackFilters();
  }

  generateDiagnostics() {
    const diagnostics = document.getElementById('fb-diagnostics');
    if (!diagnostics) return;

    const info = {
      userAgent: navigator.userAgent,
      timestamp: new Date().toISOString(),
      url: window.location.href,
      screen: `${screen.width}x${screen.height}`,
      viewport: `${window.innerWidth}x${window.innerHeight}`,
      timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
      language: navigator.language,
      cookies: navigator.cookieEnabled,
      localStorage: (() => {
        try {
          localStorage.setItem('test', 'test');
          localStorage.removeItem('test');
          return 'Disponible';
        } catch {
          return 'No disponible';
        }
      })(),
      sessionStorage: (() => {
        try {
          sessionStorage.setItem('test', 'test');
          sessionStorage.removeItem('test');
          return 'Disponible';
        } catch {
          return 'No disponible';
        }
      })()
    };

    diagnostics.textContent = Object.entries(info)
      .map(([key, value]) => `${key}: ${value}`)
      .join(' | ');
  }

  setupFileHandlers() {
    const filesInput = document.getElementById('fb-files');
    const previews = document.getElementById('fb-previews');

    filesInput?.addEventListener('change', (e) => {
      const files = Array.from(e.target.files || []);
      if (!previews) return;

      previews.innerHTML = '';
      files.slice(0, 6).forEach(file => {
        const url = URL.createObjectURL(file);
        const img = document.createElement('img');
        img.src = url;
        img.className = 'rounded border max-h-24 object-cover w-full';
        img.title = file.name;
        previews.appendChild(img);
      });
    });
  }

  async uploadFiles() {
    const filesInput = document.getElementById('fb-files');
    const files = Array.from(filesInput?.files || []);
    const out = [];

    for (const f of files.slice(0, 6)) {
      const fd = new FormData();
      fd.append('file', f);
      const url = `${Config.API_BASE}/feedback_upload.php`;
      
      try {
        const r = await fetch(url, {
          method: 'POST',
          headers: Config.withAuthHeaders({}, true),
          body: fd
        });
        
        const txt = await r.text();
        let j;
        try {
          j = JSON.parse(txt);
        } catch {
          throw new Error('Respuesta no JSON');
        }
        
        if (!r.ok || !j?.ok) {
          throw new Error(j?.error || 'Fallo al subir');
        }
        
        out.push({ url: j.url, mime: j.mime, size: j.size, name: f.name });
      } catch (e) {
        console.warn('Error uploading file:', e);
        // Continuar con otros archivos
      }
    }
    
    return out;
  }

  async send() {
    try {
      const fbMsg = document.getElementById('fb-msg');
      if (fbMsg) fbMsg.textContent = 'Enviando...';

      const type = document.getElementById('fb-type')?.value || 'other';
      const title = document.getElementById('fb-title')?.value?.trim();
      const description = document.getElementById('fb-description')?.value?.trim();

      if (!title || !description) {
        if (fbMsg) fbMsg.textContent = 'Título y descripción son requeridos';
        return;
      }

      let attachments = [];
      try {
        attachments = await this.uploadFiles();
      } catch (e) {
        attachments = [];
      }

      const payload = {
        type,
        severity: 'sugerencia', // Default severity
        module: 'index', // Default module
        title,
        description,
        attachments,
        diagnostics_json: document.getElementById('fb-diagnostics')?.textContent || ''
      };

      const res = await Config.postWithFallback(['feedback_save_safe.php', 'feedback_save.php'], payload, true);

      if (res && res.ok) {
        if (fbMsg) fbMsg.textContent = 'Enviado';
        this.close();
        Config.toast('Feedback enviado correctamente.');
        
        // Limpiar formulario
        this.clearForm();
      } else {
        if (fbMsg) fbMsg.textContent = 'Error al enviar';
      }
    } catch (e) {
      const fbMsg = document.getElementById('fb-msg');
      if (fbMsg) fbMsg.textContent = 'Error: ' + (e?.message || e);
    }
  }

  clearForm() {
    const title = document.getElementById('fb-title');
    const description = document.getElementById('fb-description');
    const files = document.getElementById('fb-files');
    const previews = document.getElementById('fb-previews');

    if (title) title.value = '';
    if (description) description.value = '';
    if (files) files.value = '';
    if (previews) previews.innerHTML = '';
  }

  async loadUserFeedbackData() {
    const list = document.getElementById('fb-user-list');
    if (!list) return;

    try {
      // Cargar solo los feedback del usuario actual
      const res = await Config.apiGet('feedback_list_safe.php?limit=50', true);
      
      if (!Array.isArray(res) || res.length === 0) {
        list.innerHTML = '<div class="text-center text-gray-500 py-4">No has enviado feedback aún</div>';
        return;
      }

      list.innerHTML = res.map(item => this.renderUserFeedbackItem(item)).join('');
      
      // Agregar event listeners para acciones de usuario
      this.setupUserFeedbackActions();
      
    } catch (e) {
      list.innerHTML = '<div class="text-center text-red-500 py-4">Error al cargar tus feedback</div>';
    }
  }

  renderUserFeedbackItem(item) {
    const statusColors = {
      nuevo: 'bg-blue-100 text-blue-800',
      en_progreso: 'bg-yellow-100 text-yellow-800',
      resuelto: 'bg-green-100 text-green-800',
      rechazado: 'bg-red-100 text-red-800'
    };

    const typeIcons = {
      bug: '🐛',
      mejora: '⚡',
      ux: '🎨',
      idea: '💡'
    };

    const statusLabels = {
      nuevo: 'Nuevo',
      en_progreso: 'En Progreso',
      resuelto: 'Resuelto',
      rechazado: 'Rechazado'
    };

    return `
      <div class="border rounded-lg p-3 mb-3 bg-white">
        <div class="flex items-start justify-between">
          <div class="flex-1">
            <div class="flex items-center gap-2 mb-2">
              <span class="text-lg">${typeIcons[item.type] || '📝'}</span>
              <h4 class="font-medium text-gray-900">${item.title || 'Sin título'}</h4>
              <span class="px-2 py-1 text-xs rounded ${statusColors[item.status] || statusColors.nuevo}">
                ${statusLabels[item.status] || 'Nuevo'}
              </span>
            </div>
            <p class="text-sm text-gray-600 mb-2">${item.description || 'Sin descripción'}</p>
            <div class="text-xs text-gray-500">
              <span>ID: ${item.id}</span> | 
              <span>Enviado: ${new Date(item.created_at).toLocaleDateString()}</span>
              ${item.updated_at !== item.created_at ? 
                ` | <span>Actualizado: ${new Date(item.updated_at).toLocaleDateString()}</span>` : ''}
            </div>
            ${item.admin_notes ? `
              <div class="mt-2 p-2 bg-gray-50 rounded border-l-4 border-blue-400">
                <div class="text-xs font-medium text-gray-700 mb-1">Comentario del Administrador:</div>
                <div class="text-sm text-gray-600">${item.admin_notes}</div>
              </div>
            ` : ''}
          </div>
          <div class="flex flex-col gap-1 ml-4">
            <button class="user-feedback-action px-2 py-1 text-xs bg-blue-100 text-blue-800 rounded hover:bg-blue-200" data-id="${item.id}" data-action="view">
              Ver Detalles
            </button>
          </div>
        </div>
      </div>
    `;
  }

  setupUserFeedbackFilters() {
    const statusFilter = document.getElementById('fb-user-filter-status');
    const typeFilter = document.getElementById('fb-user-filter-type');
    
    const applyFilters = () => {
      this.loadUserFeedbackData();
    };

    statusFilter?.addEventListener('change', applyFilters);
    typeFilter?.addEventListener('change', applyFilters);
  }

  async loadTriageData() {
    const list = document.getElementById('fb-triage-list');
    if (!list) return;

    try {
      const res = await Config.apiGet('feedback_list_safe.php?limit=50', true);
      
      if (!Array.isArray(res) || res.length === 0) {
        list.innerHTML = '<div class="text-center text-gray-500 py-4">No hay feedback disponible</div>';
        return;
      }

      list.innerHTML = res.map(item => this.renderTriageItem(item)).join('');
      
      // Agregar event listeners para acciones
      this.setupTriageActions();
      
    } catch (e) {
      list.innerHTML = '<div class="text-center text-red-500 py-4">Error al cargar feedback</div>';
    }
  }

  renderTriageItem(item) {
    const statusColors = {
      pending: 'bg-yellow-100 text-yellow-800',
      in_progress: 'bg-blue-100 text-blue-800',
      resolved: 'bg-green-100 text-green-800',
      closed: 'bg-gray-100 text-gray-800'
    };

    const typeIcons = {
      bug: '🐛',
      mejora: '⚡',
      ux: '🎨',
      idea: '💡'
    };

    return `
      <div class="border rounded-lg p-3 mb-3 bg-white">
        <div class="flex items-start justify-between">
          <div class="flex-1">
            <div class="flex items-center gap-2 mb-2">
              <span class="text-lg">${typeIcons[item.type] || '📝'}</span>
              <h4 class="font-medium text-gray-900">${item.title || 'Sin título'}</h4>
              <span class="px-2 py-1 text-xs rounded ${statusColors[item.status] || statusColors.pending}">
                ${item.status || 'pending'}
              </span>
            </div>
            <p class="text-sm text-gray-600 mb-2">${item.description || 'Sin descripción'}</p>
            <div class="text-xs text-gray-500">
              <span>ID: ${item.id}</span> | 
              <span>Usuario: ${item.user_email || 'Anónimo'}</span> | 
              <span>Creado: ${new Date(item.created_at).toLocaleDateString()}</span>
            </div>
          </div>
          <div class="flex flex-col gap-1 ml-4">
            <button class="triage-action px-2 py-1 text-xs bg-blue-100 text-blue-800 rounded hover:bg-blue-200" data-id="${item.id}" data-action="view">
              Ver
            </button>
            <button class="triage-action px-2 py-1 text-xs bg-green-100 text-green-800 rounded hover:bg-green-200" data-id="${item.id}" data-action="resolve">
              Resolver
            </button>
            <button class="triage-action px-2 py-1 text-xs bg-red-100 text-red-800 rounded hover:bg-red-200" data-id="${item.id}" data-action="close">
              Cerrar
            </button>
          </div>
        </div>
      </div>
    `;
  }

  setupTriageActions() {
    const list = document.getElementById('fb-triage-list');
    if (!list) return;

    list.addEventListener('click', async (e) => {
      const btn = e.target.closest('.triage-action');
      if (!btn) return;

      const id = btn.dataset.id;
      const action = btn.dataset.action;

      try {
        switch (action) {
          case 'view':
            await this.viewFeedback(id);
            break;
          case 'resolve':
            await this.updateFeedbackStatus(id, 'resolved');
            break;
          case 'close':
            await this.updateFeedbackStatus(id, 'closed');
            break;
        }
      } catch (e) {
        Config.toast('Error al procesar acción: ' + e.message);
      }
    });
  }

  setupUserFeedbackActions() {
    const list = document.getElementById('fb-user-list');
    if (!list) return;

    list.addEventListener('click', async (e) => {
      const btn = e.target.closest('.user-feedback-action');
      if (!btn) return;

      const id = btn.dataset.id;
      const action = btn.dataset.action;

      try {
        switch (action) {
          case 'view':
            await this.viewUserFeedback(id);
            break;
        }
      } catch (e) {
        Config.toast('Error al procesar acción: ' + e.message);
      }
    });
  }

  async viewUserFeedback(id) {
    try {
      const res = await Config.apiGet(`feedback_get_safe.php?id=${id}`, true);
      
      if (res) {
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
        modal.innerHTML = `
          <div class="bg-white rounded-lg p-6 max-w-2xl max-h-96 overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
              <h3 class="text-lg font-medium">Mi Feedback #${id}</h3>
              <button class="text-gray-400 hover:text-gray-600" onclick="this.closest('.fixed').remove()">✕</button>
            </div>
            <div class="space-y-3">
              <div><strong>Título:</strong> ${res.title || 'N/A'}</div>
              <div><strong>Tipo:</strong> ${res.type || 'N/A'}</div>
              <div><strong>Estado:</strong> ${res.status || 'N/A'}</div>
              <div><strong>Descripción:</strong><br>${res.description || 'N/A'}</div>
              <div><strong>Enviado:</strong> ${new Date(res.created_at).toLocaleString()}</div>
              ${res.updated_at !== res.created_at ? 
                `<div><strong>Actualizado:</strong> ${new Date(res.updated_at).toLocaleString()}</div>` : ''}
              ${res.admin_notes ? 
                `<div class="mt-3 p-3 bg-blue-50 rounded border-l-4 border-blue-400">
                  <div><strong>Comentario del Administrador:</strong></div>
                  <div class="mt-1">${res.admin_notes}</div>
                </div>` : ''}
              ${res.attachments && res.attachments.length > 0 ? 
                `<div><strong>Adjuntos:</strong> ${res.attachments.length} archivo(s)</div>` : ''}
            </div>
          </div>
        `;
        document.body.appendChild(modal);
      }
    } catch (e) {
      Config.toast('Error al cargar feedback: ' + e.message);
    }
  }

  async updateFeedbackStatus(id, status) {
    try {
      const res = await Config.apiPost('feedback_update_safe.php', {
        id,
        status
      }, true);

      if (res && res.ok) {
        Config.toast(`Feedback ${status} correctamente`);
        this.loadTriageData(); // Recargar lista
      } else {
        throw new Error('Error al actualizar estado');
      }
    } catch (e) {
      Config.toast('Error al actualizar estado: ' + e.message);
    }
  }

  showLegacyModal() {
    // Fallback para cuando no hay sistema de modales
    const modal = document.getElementById('modal-fb');
    if (modal) {
      modal.classList.remove('hidden');
      modal.classList.add('flex');
    }
  }
}

// Inicializar sistema de feedback
const feedbackSystem = new FeedbackSystem();

// Exportar para uso global
window.Feedback = {
  show: () => feedbackSystem.show(),
  showTriage: () => feedbackSystem.showTriage(),
  showUserFeedback: () => feedbackSystem.showUserFeedback(),
  close: () => feedbackSystem.close(),
  send: () => feedbackSystem.send(),
  clearForm: () => feedbackSystem.clearForm(),
  isAdmin: () => feedbackSystem.isAdmin()
};

// Hacer funciones disponibles globalmente para compatibilidad
window.fbShow = (show) => show ? feedbackSystem.show() : feedbackSystem.close();
window.fbInitModule = () => feedbackSystem.init();
window.fbUploadFiles = () => feedbackSystem.uploadFiles();
window.buildDiagnostics = () => feedbackSystem.generateDiagnostics();
