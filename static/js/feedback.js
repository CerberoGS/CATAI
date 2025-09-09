/* ================== Sistema de Feedback Completo ================== */

class FeedbackSystem {
  constructor() {
    this.modalId = 'modal-fb';
    this.contextModule = null; // módulo sugerido por caller
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

  show(opts = {}) {
    // Permite forzar módulo/título/prefill
    this.contextModule = opts?.module || null;
    this.ensureModal();
    this.prepareModal(opts);
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

  prepareModal(opts = {}) {
    const fbBody = document.getElementById('fb-body');
    if (!fbBody) return;

    // Restaurar el título del modal
    const modalTitle = document.querySelector('#modal-fb .text-lg');
    if (modalTitle) {
      modalTitle.textContent = 'Enviar feedback';
    }

    // Restaurar los botones del modal
    const cancelBtn = document.getElementById('fb-cancel');
    const sendBtn = document.getElementById('fb-send');
    const msgSpan = document.getElementById('fb-msg');
    
    if (cancelBtn) {
      cancelBtn.textContent = 'Cancelar';
      cancelBtn.onclick = () => this.close();
    }
    
    if (sendBtn) {
      sendBtn.style.display = 'inline-block'; // Mostrar botón enviar
    }
    
    if (msgSpan) {
      msgSpan.textContent = '';
    }

    const moduleValue = this.contextModule || this.detectModule();

    fbBody.innerHTML = `
      <div>
        <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300">Tipo de feedback</label>
        <select id="fb-type" class="mt-1 w-full rounded-md border-gray-300 dark-mode:border-gray-600 dark-mode:bg-gray-700 dark-mode:text-gray-100 shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
          <option value="bug">🐛 Bug/Error</option>
          <option value="mejora">⚡ Mejora</option>
          <option value="ux">🎨 UX/Interfaz</option>
          <option value="idea">💡 Idea/Sugerencia</option>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300">Módulo</label>
        <select id="fb-module" class="mt-1 w-full rounded-md border-gray-300 dark-mode:border-gray-600 dark-mode:bg-gray-700 dark-mode:text-gray-100 shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
          <option value="index" ${moduleValue==='index'?'selected':''}>Index</option>
          <option value="journal" ${moduleValue==='journal'?'selected':''}>Journal</option>
          <option value="config" ${moduleValue==='config'?'selected':''}>Config</option>
          <option value="tester" ${moduleValue==='tester'?'selected':''}>Tester</option>
          <option value="admin" ${moduleValue==='admin'?'selected':''}>Admin</option>
          <option value="account" ${moduleValue==='account'?'selected':''}>Account</option>
          <option value="api" ${moduleValue==='api'?'selected':''}>API</option>
          <option value="otro" ${moduleValue==='otro'?'selected':''}>Otro</option>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300">Título</label>
        <input id="fb-title" class="mt-1 w-full rounded-md border-gray-300 dark-mode:border-gray-600 dark-mode:bg-gray-700 dark-mode:text-gray-100 shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="Describe brevemente el tema">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300">Descripción</label>
        <textarea id="fb-description" rows="4" class="mt-1 w-full rounded-md border-gray-300 dark-mode:border-gray-600 dark-mode:bg-gray-700 dark-mode:text-gray-100 shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="Proporciona más detalles..."></textarea>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300">Adjuntar archivos</label>
        <input id="fb-files" type="file" accept="image/*,.pdf,.txt" multiple class="mt-1 w-full text-sm text-gray-500 dark-mode:text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 dark-mode:file:bg-gray-700 dark-mode:file:text-gray-300"/>
        <div id="fb-previews" class="mt-2 grid grid-cols-4 gap-2"></div>
      </div>
      <div class="bg-gray-50 dark-mode:bg-gray-700 p-3 rounded text-xs">
        <strong class="text-gray-800 dark-mode:text-gray-200">Información del sistema:</strong><br>
        <span id="fb-diagnostics" class="text-gray-700 dark-mode:text-gray-300"></span>
      </div>
    `;

    this.generateDiagnostics();
    this.setupFileHandlers();
  }

  ensureModal() {
    let modal = document.getElementById(this.modalId);
    if (modal) return;
    modal = document.createElement('div');
    modal.id = this.modalId;
    modal.className = 'fixed inset-0 bg-black/50 hidden items-center justify-center z-50';
    modal.innerHTML = `
      <div class="bg-white dark-mode:bg-gray-800 w-full max-w-xl rounded-lg shadow-lg p-4 space-y-3 border border-gray-200 dark-mode:border-gray-600">
        <div class="flex items-center justify-between">
          <div class="text-lg font-semibold text-gray-900 dark-mode:text-gray-100">Enviar feedback</div>
          <button id="fb-close" class="text-gray-500 hover:text-gray-700 dark-mode:text-gray-400 dark-mode:hover:text-gray-200">✕</button>
        </div>
        <div id="fb-body" class="space-y-3"></div>
        <div class="flex items-center justify-end gap-2">
          <span id="fb-msg" class="text-sm text-gray-500 dark-mode:text-gray-400"></span>
          <button id="fb-cancel" class="py-1.5 px-3 rounded-md border text-gray-700 hover:bg-gray-50 dark-mode:text-gray-300 dark-mode:hover:bg-gray-700 dark-mode:border-gray-600">Cancelar</button>
          <button id="fb-send" class="py-1.5 px-3 rounded-md text-white bg-indigo-600 hover:bg-indigo-700 dark-mode:bg-indigo-500 dark-mode:hover:bg-indigo-600">Enviar</button>
        </div>
      </div>`;
    document.body.appendChild(modal);
    this.setupModalEventListeners();
  }

  detectModule() {
    try {
      const p = (location.pathname.split('/').pop() || '').toLowerCase();
      if (p.includes('journal')) return 'journal';
      if (p.includes('config')) return 'config';
      if (p.includes('tester')) return 'tester';
      if (p.includes('admin')) return 'admin';
      if (p.includes('account')) return 'account';
      if (p.includes('index')) return 'index';
      return 'index';
    } catch { return 'index'; }
  }

  prepareTriageModal() {
    const fbBody = document.getElementById('fb-body');
    if (!fbBody) return;

    fbBody.innerHTML = `
      <div class="mb-4 p-3 bg-yellow-50 dark-mode:bg-yellow-900 border border-yellow-200 dark-mode:border-yellow-700 rounded">
        <h3 class="text-sm font-medium text-yellow-800 dark-mode:text-yellow-200">Triage de Feedback</h3>
        <p class="text-xs text-yellow-700 dark-mode:text-yellow-300 mt-1">Gestiona todos los reportes de feedback del sistema</p>
      </div>
      <div class="grid md:grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300">Filtrar por estado</label>
          <select id="fb-triage-filter-status" class="mt-1 w-full rounded-md border-gray-300 dark-mode:border-gray-600 dark-mode:bg-gray-700 dark-mode:text-gray-100 shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
            <option value="">Todos</option>
            <option value="nuevo">Nuevo</option>
            <option value="en_progreso">En progreso</option>
            <option value="resuelto">Resuelto</option>
            <option value="rechazado">Rechazado</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300">Filtrar por tipo</label>
          <select id="fb-triage-filter-type" class="mt-1 w-full rounded-md border-gray-300 dark-mode:border-gray-600 dark-mode:bg-gray-700 dark-mode:text-gray-100 shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
            <option value="">Todos</option>
            <option value="bug">Bug/Error</option>
            <option value="mejora">Mejora</option>
            <option value="ux">UX/Interfaz</option>
            <option value="idea">Idea/Sugerencia</option>
          </select>
        </div>
      </div>
      <div id="fb-triage-list" class="mt-4 max-h-96 overflow-y-auto">
        <div class="text-center text-gray-500 dark-mode:text-gray-400 py-4">Cargando feedback...</div>
      </div>
    `;

    this.loadTriageData();
    this.setupTriageFilters();
  }

  prepareUserFeedbackModal() {
    const fbBody = document.getElementById('fb-body');
    if (!fbBody) return;

    // Cambiar el título del modal
    const modalTitle = document.querySelector('#modal-fb .text-lg');
    if (modalTitle) {
      modalTitle.textContent = 'Mis Feedback Enviados';
    }

    // Cambiar los botones del modal
    const cancelBtn = document.getElementById('fb-cancel');
    const sendBtn = document.getElementById('fb-send');
    const msgSpan = document.getElementById('fb-msg');
    
    if (cancelBtn) {
      cancelBtn.textContent = 'Cerrar';
      cancelBtn.onclick = () => this.close();
    }
    
    if (sendBtn) {
      sendBtn.style.display = 'none'; // Ocultar botón enviar
    }
    
    if (msgSpan) {
      msgSpan.textContent = '';
    }

    fbBody.innerHTML = `
      <div class="mb-4 p-3 bg-blue-50 dark-mode:bg-blue-900 border border-blue-200 dark-mode:border-blue-700 rounded">
        <h3 class="text-sm font-medium text-blue-800 dark-mode:text-blue-200">Mis Feedback Enviados</h3>
        <p class="text-xs text-blue-700 dark-mode:text-blue-300 mt-1">Aquí puedes ver el estado de tus reportes y comentarios del administrador</p>
      </div>
      <div class="grid md:grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300">Filtrar por estado</label>
          <select id="fb-user-filter-status" class="mt-1 w-full rounded-md border-gray-300 dark-mode:border-gray-600 dark-mode:bg-gray-700 dark-mode:text-gray-100 shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
            <option value="">Todos</option>
            <option value="nuevo">Nuevo</option>
            <option value="en_progreso">En progreso</option>
            <option value="resuelto">Resuelto</option>
            <option value="rechazado">Rechazado</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300">Filtrar por tipo</label>
          <select id="fb-user-filter-type" class="mt-1 w-full rounded-md border-gray-300 dark-mode:border-gray-600 dark-mode:bg-gray-700 dark-mode:text-gray-100 shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
            <option value="">Todos</option>
            <option value="bug">Bug/Error</option>
            <option value="mejora">Mejora</option>
            <option value="ux">UX/Interfaz</option>
            <option value="idea">Idea/Sugerencia</option>
          </select>
        </div>
      </div>
      <div id="fb-user-list" class="mt-4 max-h-96 overflow-y-auto">
        <div class="text-center text-gray-500 dark-mode:text-gray-400 py-4">Cargando tus feedback...</div>
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
        module: (document.getElementById('fb-module')?.value || this.contextModule || this.detectModule()),
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
      
      // La API devuelve { items: [], total: number }
      const items = res?.items || res || [];
      
      if (!Array.isArray(items) || items.length === 0) {
        list.innerHTML = '<div class="text-center text-gray-500 dark-mode:text-gray-400 py-4">No has enviado feedback aún</div>';
        return;
      }

      list.innerHTML = items.map(item => this.renderUserFeedbackItem(item)).join('');
      
      // Agregar event listeners para acciones de usuario
      this.setupUserFeedbackActions();
      
    } catch (e) {
      console.error('Error loading user feedback:', e);
      list.innerHTML = '<div class="text-center text-red-500 py-4">Error al cargar tus feedback: ' + e.message + '</div>';
    }
  }

  renderUserFeedbackItem(item) {
    const statusColors = {
      nuevo: 'bg-blue-100 text-blue-800 dark-mode:bg-blue-900 dark-mode:text-blue-200',
      en_progreso: 'bg-yellow-100 text-yellow-800 dark-mode:bg-yellow-900 dark-mode:text-yellow-200',
      resuelto: 'bg-green-100 text-green-800 dark-mode:bg-green-900 dark-mode:text-green-200',
      rechazado: 'bg-red-100 text-red-800 dark-mode:bg-red-900 dark-mode:text-red-200'
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
      <div class="border rounded-lg p-3 mb-3 bg-white dark-mode:bg-gray-800 border-gray-200 dark-mode:border-gray-600">
        <div class="flex items-start justify-between">
          <div class="flex-1">
            <div class="flex items-center gap-2 mb-2">
              <span class="text-lg">${typeIcons[item.type] || '📝'}</span>
              <h4 class="font-medium text-gray-900 dark-mode:text-gray-100">${item.title || 'Sin título'}</h4>
              <span class="px-2 py-1 text-xs rounded ${statusColors[item.status] || statusColors.nuevo}">
                ${statusLabels[item.status] || 'Nuevo'}
              </span>
            </div>
            <p class="text-sm text-gray-600 dark-mode:text-gray-300 mb-2">${item.description || 'Sin descripción'}</p>
            <div class="text-xs text-gray-500 dark-mode:text-gray-400">
              <span>ID: ${item.id}</span> | 
              <span>Enviado: ${new Date(item.created_at).toLocaleDateString()}</span>
              ${item.updated_at !== item.created_at ? 
                ` | <span>Actualizado: ${new Date(item.updated_at).toLocaleDateString()}</span>` : ''}
            </div>
            ${item.admin_notes ? `
              <div class="mt-2 p-2 bg-gray-50 dark-mode:bg-gray-700 rounded border-l-4 border-blue-400 dark-mode:border-blue-500">
                <div class="text-xs font-medium text-gray-700 dark-mode:text-gray-300 mb-1">Comentario del Administrador:</div>
                <div class="text-sm text-gray-600 dark-mode:text-gray-300">${item.admin_notes}</div>
              </div>
            ` : ''}
          </div>
          <div class="flex flex-col gap-1 ml-4">
            <button class="user-feedback-action px-2 py-1 text-xs bg-blue-100 text-blue-800 dark-mode:bg-blue-900 dark-mode:text-blue-200 rounded hover:bg-blue-200 dark-mode:hover:bg-blue-800" data-id="${item.id}" data-action="view">
              Ver Detalles
            </button>
          </div>
        </div>
      </div>
    `;
  }

  setupTriageFilters() {
    const statusFilter = document.getElementById('fb-triage-filter-status');
    const typeFilter = document.getElementById('fb-triage-filter-type');
    
    const applyFilters = () => {
      this.loadTriageData();
    };

    statusFilter?.addEventListener('change', applyFilters);
    typeFilter?.addEventListener('change', applyFilters);
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
      
      // La API devuelve { items: [], total: number }
      const items = res?.items || res || [];
      
      if (!Array.isArray(items) || items.length === 0) {
        list.innerHTML = '<div class="text-center text-gray-500 dark-mode:text-gray-400 py-4">No hay feedback disponible</div>';
        return;
      }

      list.innerHTML = items.map(item => this.renderTriageItem(item)).join('');
      
      // Agregar event listeners para acciones
      this.setupTriageActions();
      
    } catch (e) {
      console.error('Error loading triage data:', e);
      list.innerHTML = '<div class="text-center text-red-500 py-4">Error al cargar feedback: ' + e.message + '</div>';
    }
  }

  renderTriageItem(item) {
    const statusColors = {
      nuevo: 'bg-blue-100 text-blue-800 dark-mode:bg-blue-900 dark-mode:text-blue-200',
      en_progreso: 'bg-yellow-100 text-yellow-800 dark-mode:bg-yellow-900 dark-mode:text-yellow-200',
      resuelto: 'bg-green-100 text-green-800 dark-mode:bg-green-900 dark-mode:text-green-200',
      rechazado: 'bg-red-100 text-red-800 dark-mode:bg-red-900 dark-mode:text-red-200'
    };

    const typeIcons = {
      bug: '🐛',
      mejora: '⚡',
      ux: '🎨',
      idea: '💡'
    };

    return `
      <div class="border rounded-lg p-3 mb-3 bg-white dark-mode:bg-gray-800 border-gray-200 dark-mode:border-gray-600">
        <div class="flex items-start justify-between">
          <div class="flex-1">
            <div class="flex items-center gap-2 mb-2">
              <span class="text-lg">${typeIcons[item.type] || '📝'}</span>
              <h4 class="font-medium text-gray-900 dark-mode:text-gray-100">${item.title || 'Sin título'}</h4>
              <span class="px-2 py-1 text-xs rounded ${statusColors[item.status] || statusColors.nuevo}">
                ${item.status || 'nuevo'}
              </span>
            </div>
            <p class="text-sm text-gray-600 dark-mode:text-gray-300 mb-2">${item.description || 'Sin descripción'}</p>
            <div class="text-xs text-gray-500 dark-mode:text-gray-400">
              <span>ID: ${item.id}</span> | 
              <span>Usuario: ${item.user_email || 'Anónimo'}</span> | 
              <span>Creado: ${new Date(item.created_at).toLocaleDateString()}</span>
            </div>
          </div>
          <div class="flex flex-col gap-1 ml-4">
            <button class="triage-action px-2 py-1 text-xs bg-blue-100 text-blue-800 dark-mode:bg-blue-900 dark-mode:text-blue-200 rounded hover:bg-blue-200 dark-mode:hover:bg-blue-800" data-id="${item.id}" data-action="view">
              Ver
            </button>
            <button class="triage-action px-2 py-1 text-xs bg-green-100 text-green-800 dark-mode:bg-green-900 dark-mode:text-green-200 rounded hover:bg-green-200 dark-mode:hover:bg-green-800" data-id="${item.id}" data-action="resolve">
              Resolver
            </button>
            <button class="triage-action px-2 py-1 text-xs bg-red-100 text-red-800 dark-mode:bg-red-900 dark-mode:text-red-200 rounded hover:bg-red-200 dark-mode:hover:bg-red-800" data-id="${item.id}" data-action="close">
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
            await this.updateFeedbackStatus(id, 'resuelto');
            break;
          case 'close':
            await this.updateFeedbackStatus(id, 'rechazado');
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
          <div class="bg-white dark-mode:bg-gray-800 rounded-lg p-6 max-w-2xl max-h-96 overflow-y-auto border border-gray-200 dark-mode:border-gray-600">
            <div class="flex justify-between items-center mb-4">
              <h3 class="text-lg font-medium text-gray-900 dark-mode:text-gray-100">Mi Feedback #${id}</h3>
              <button class="text-gray-400 hover:text-gray-600 dark-mode:text-gray-300 dark-mode:hover:text-gray-100 text-xl font-bold" onclick="this.closest('.fixed').remove()">✕</button>
            </div>
            <div class="space-y-4 text-sm">
              <div class="grid md:grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300 mb-1">Título</label>
                  <div class="p-3 bg-gray-50 dark-mode:bg-gray-700 rounded-md text-gray-900 dark-mode:text-gray-100 border border-gray-200 dark-mode:border-gray-600">${res.title || 'Sin título'}</div>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300 mb-1">Tipo</label>
                  <div class="p-3 bg-gray-50 dark-mode:bg-gray-700 rounded-md text-gray-900 dark-mode:text-gray-100 border border-gray-200 dark-mode:border-gray-600">${res.type || 'Sin especificar'}</div>
                </div>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300 mb-1">Estado</label>
                <div class="p-3 bg-gray-50 dark-mode:bg-gray-700 rounded-md border border-gray-200 dark-mode:border-gray-600">
                  <span class="px-3 py-1 text-xs rounded-full font-medium ${
                    res.status === 'nuevo' ? 'bg-blue-100 text-blue-800 dark-mode:bg-blue-900 dark-mode:text-blue-200' :
                    res.status === 'en_progreso' ? 'bg-yellow-100 text-yellow-800 dark-mode:bg-yellow-900 dark-mode:text-yellow-200' :
                    res.status === 'resuelto' ? 'bg-green-100 text-green-800 dark-mode:bg-green-900 dark-mode:text-green-200' :
                    res.status === 'rechazado' ? 'bg-red-100 text-red-800 dark-mode:bg-red-900 dark-mode:text-red-200' :
                    'bg-gray-100 text-gray-800 dark-mode:bg-gray-600 dark-mode:text-gray-200'
                  }">
                    ${res.status === 'nuevo' ? 'Nuevo' :
                      res.status === 'en_progreso' ? 'En Progreso' :
                      res.status === 'resuelto' ? 'Resuelto' :
                      res.status === 'rechazado' ? 'Rechazado' : 
                      res.status || 'Sin estado'}
                  </span>
                </div>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300 mb-1">Descripción</label>
                <div class="p-3 bg-gray-50 dark-mode:bg-gray-700 rounded-md text-gray-900 dark-mode:text-gray-100 border border-gray-200 dark-mode:border-gray-600 whitespace-pre-wrap min-h-[80px]">${res.description || 'Sin descripción'}</div>
              </div>
              <div class="grid md:grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300 mb-1">Enviado</label>
                  <div class="p-3 bg-gray-50 dark-mode:bg-gray-700 rounded-md text-gray-900 dark-mode:text-gray-100 border border-gray-200 dark-mode:border-gray-600">${new Date(res.created_at).toLocaleString()}</div>
                </div>
                ${res.updated_at !== res.created_at ? 
                  `<div>
                    <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300 mb-1">Actualizado</label>
                    <div class="p-3 bg-gray-50 dark-mode:bg-gray-700 rounded-md text-gray-900 dark-mode:text-gray-100 border border-gray-200 dark-mode:border-gray-600">${new Date(res.updated_at).toLocaleString()}</div>
                  </div>` : ''}
              </div>
              ${res.admin_notes ? 
                `<div class="mt-4 p-4 bg-blue-50 dark-mode:bg-blue-900 rounded-lg border-l-4 border-blue-400 dark-mode:border-blue-500">
                  <div class="text-sm font-medium text-blue-800 dark-mode:text-blue-200 mb-2">Comentario del Administrador:</div>
                  <div class="text-sm text-blue-700 dark-mode:text-blue-300 whitespace-pre-wrap">${res.admin_notes}</div>
                </div>` : ''}
              ${res.attachments && res.attachments.length > 0 ? 
                `<div>
                  <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300 mb-1">Adjuntos</label>
                  <div class="p-3 bg-gray-50 dark-mode:bg-gray-700 rounded-md text-gray-900 dark-mode:text-gray-100 border border-gray-200 dark-mode:border-gray-600">${res.attachments.length} archivo(s) adjunto(s)</div>
                </div>` : ''}
              ${res.diagnostics_json ? 
                `<div>
                  <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300 mb-1">Datos técnicos</label>
                  <pre class="p-3 bg-gray-50 dark-mode:bg-gray-700 rounded-md text-xs overflow-auto max-h-32 text-gray-900 dark-mode:text-gray-100 border border-gray-200 dark-mode:border-gray-600">${JSON.stringify(res.diagnostics_json, null, 2)}</pre>
                </div>` : ''}
            </div>
            <div class="mt-6 text-center">
              <button class="px-6 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600 dark-mode:bg-gray-600 dark-mode:hover:bg-gray-700 transition-colors" onclick="this.closest('.fixed').remove()">
                Cerrar
              </button>
            </div>
          </div>
        `;
        document.body.appendChild(modal);
      }
    } catch (e) {
      Config.toast('Error al cargar feedback: ' + e.message);
    }
  }

  async viewFeedback(id) {
    // Función para administradores - modal editable completo
    try {
      const res = await Config.apiGet(`feedback_get_safe.php?id=${id}`, true);
      
      if (res) {
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
        modal.innerHTML = `
          <div class="bg-white dark-mode:bg-gray-800 w-full max-w-3xl rounded-lg shadow-lg p-4 space-y-3">
            <div class="flex items-center justify-between">
              <div class="text-lg font-semibold text-gray-900 dark-mode:text-gray-100">Detalle del feedback #${id}</div>
              <button class="text-gray-500 hover:text-gray-700 dark-mode:text-gray-400 dark-mode:hover:text-gray-200" onclick="this.closest('.fixed').remove()">✕</button>
            </div>
            <div class="space-y-3 text-sm">
              <div class="grid md:grid-cols-2 gap-3">
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300">Título</label>
                  <input id="admin-title" class="mt-1 w-full rounded-md border-gray-300 dark-mode:border-gray-600 dark-mode:bg-gray-700 dark-mode:text-gray-100 shadow-sm focus:ring-indigo-500 focus:border-indigo-500" value="${(res.title || '').replace(/"/g, '&quot;')}">
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300">Estado</label>
                  <select id="admin-status" class="mt-1 w-full rounded-md border-gray-300 dark-mode:border-gray-600 dark-mode:bg-gray-700 dark-mode:text-gray-100 shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                    <option value="nuevo" ${res.status==='nuevo'?'selected':''}>Nuevo</option>
                    <option value="en_progreso" ${res.status==='en_progreso'?'selected':''}>En progreso</option>
                    <option value="resuelto" ${res.status==='resuelto'?'selected':''}>Resuelto</option>
                    <option value="rechazado" ${res.status==='rechazado'?'selected':''}>Rechazado</option>
                  </select>
                </div>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300">Descripción</label>
                <textarea id="admin-description" rows="4" class="mt-1 w-full rounded-md border-gray-300 dark-mode:border-gray-600 dark-mode:bg-gray-700 dark-mode:text-gray-100 shadow-sm focus:ring-indigo-500 focus:border-indigo-500">${(res.description || '').replace(/</g,'&lt;').replace(/>/g,'&gt;')}</textarea>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300">Notas del administrador</label>
                <textarea id="admin-notes" rows="3" class="mt-1 w-full rounded-md border-gray-300 dark-mode:border-gray-600 dark-mode:bg-gray-700 dark-mode:text-gray-100 shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="Comentarios adicionales...">${(res.admin_notes || '').replace(/</g,'&lt;').replace(/>/g,'&gt;')}</textarea>
              </div>
              <div class="grid md:grid-cols-2 gap-3">
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300">Usuario</label>
                  <div class="mt-1 p-2 bg-gray-50 dark-mode:bg-gray-700 rounded text-gray-900 dark-mode:text-gray-100">${res.user_email || 'Anónimo'}</div>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300">Tipo</label>
                  <div class="mt-1 p-2 bg-gray-50 dark-mode:bg-gray-700 rounded text-gray-900 dark-mode:text-gray-100">${res.type || 'N/A'}</div>
                </div>
              </div>
              <div class="grid md:grid-cols-2 gap-3">
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300">Creado</label>
                  <div class="mt-1 p-2 bg-gray-50 dark-mode:bg-gray-700 rounded text-gray-900 dark-mode:text-gray-100">${new Date(res.created_at).toLocaleString()}</div>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300">Actualizado</label>
                  <div class="mt-1 p-2 bg-gray-50 dark-mode:bg-gray-700 rounded text-gray-900 dark-mode:text-gray-100">${new Date(res.updated_at).toLocaleString()}</div>
                </div>
              </div>
              ${res.attachments && res.attachments.length > 0 ? 
                `<div>
                  <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300">Adjuntos</label>
                  <div class="mt-1 grid grid-cols-3 gap-2" id="admin-attachments">
                    ${res.attachments.map(x=>`
                      <div class="relative">
                        <img src="${x.url}" class="rounded border max-h-24 object-cover w-full" alt="Adjunto"/>
                        <button data-del-att="${x.id}" class="absolute top-1 right-1 bg-white/80 dark-mode:bg-gray-800/80 rounded px-1 text-rose-700 dark-mode:text-rose-300 border dark-mode:border-gray-600">✕</button>
                      </div>
                    `).join('')}
                  </div>
                  <div class="mt-2">
                    <input id="admin-add-files" type="file" accept="image/*" multiple class="w-full text-sm text-gray-500 dark-mode:text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 dark-mode:file:bg-gray-700 dark-mode:file:text-gray-300">
                  </div>
                </div>` : 
                `<div>
                  <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300">Adjuntos</label>
                  <div class="mt-1">
                    <input id="admin-add-files" type="file" accept="image/*" multiple class="w-full text-sm text-gray-500 dark-mode:text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 dark-mode:file:bg-gray-700 dark-mode:file:text-gray-300">
                  </div>
                </div>`}
              ${res.diagnostics_json ? 
                `<div>
                  <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300">Datos técnicos</label>
                  <pre class="mt-1 bg-gray-50 dark-mode:bg-gray-700 p-2 rounded text-xs overflow-auto max-h-32 text-gray-900 dark-mode:text-gray-100">${JSON.stringify(res.diagnostics_json, null, 2)}</pre>
                </div>` : ''}
            </div>
            <div class="flex items-center justify-end gap-2">
              <span id="admin-msg" class="text-sm text-gray-500 dark-mode:text-gray-400"></span>
              <button class="px-4 py-2 bg-gray-500 text-white rounded hover:bg-gray-600 dark-mode:bg-gray-600 dark-mode:hover:bg-gray-700" onclick="this.closest('.fixed').remove()">Cancelar</button>
              <button id="admin-save" class="py-1.5 px-3 rounded-md text-white bg-indigo-600 hover:bg-indigo-700 dark-mode:bg-indigo-500 dark-mode:hover:bg-indigo-600">Guardar cambios</button>
            </div>
          </div>
        `;
        
        document.body.appendChild(modal);
        
        // Configurar event listeners para el modal de admin
        this.setupAdminModalEvents(modal, id);
      }
    } catch (e) {
      Config.toast('Error al cargar feedback: ' + e.message);
    }
  }

  setupAdminModalEvents(modal, feedbackId) {
    const saveBtn = modal.querySelector('#admin-save');
    const msgEl = modal.querySelector('#admin-msg');
    const addFilesInput = modal.querySelector('#admin-add-files');
    const attachmentsContainer = modal.querySelector('#admin-attachments');

    // Guardar cambios
    saveBtn?.addEventListener('click', async () => {
      try {
        msgEl.textContent = 'Guardando...';
        
        const payload = {
          id: feedbackId,
          status: modal.querySelector('#admin-status').value,
          title: modal.querySelector('#admin-title').value,
          description: modal.querySelector('#admin-description').value,
          admin_notes: modal.querySelector('#admin-notes').value
        };
        
        const res = await Config.apiPost('feedback_update_safe.php', payload, true);
        
        if (res && res.ok) {
          msgEl.textContent = 'Guardado correctamente';
          setTimeout(() => {
            modal.remove();
            this.loadTriageData(); // Recargar lista
          }, 1000);
        } else {
          msgEl.textContent = 'Error al guardar';
        }
      } catch (e) {
        msgEl.textContent = 'Error: ' + (e?.message || e);
      }
    });

    // Eliminar adjuntos
    modal.addEventListener('click', async (e) => {
      const btn = e.target.closest('button[data-del-att]');
      if (!btn) return;
      
      const attachmentId = parseInt(btn.getAttribute('data-del-att'), 10);
      if (!Number.isFinite(attachmentId)) return;
      
      if (!confirm('¿Eliminar adjunto?')) return;
      
      try {
        await Config.apiPost('feedback_attachment_delete_safe.php', { id: attachmentId }, true);
        btn.closest('.relative').remove();
        Config.toast('Adjunto eliminado');
      } catch (e) {
        Config.toast('Error al eliminar adjunto: ' + e.message);
      }
    });

    // Agregar archivos
    addFilesInput?.addEventListener('change', async (e) => {
      const files = Array.from(e.target.files || []);
      if (!files.length) return;
      
      try {
        msgEl.textContent = 'Subiendo archivos...';
        
        for (const file of files.slice(0, 6)) {
          const fd = new FormData();
          fd.append('file', file);
          
          const uploadRes = await fetch(`${Config.API_BASE}/feedback_upload.php`, {
            method: 'POST',
            headers: Config.withAuthHeaders({}, true),
            body: fd
          });
          
          const uploadData = await uploadRes.json();
          
          if (uploadRes.ok && uploadData?.ok) {
            await Config.apiPost('feedback_update_safe.php', {
              id: feedbackId,
              attachments: [{ url: uploadData.url, mime: uploadData.mime, size: uploadData.size }]
            }, true);
          }
        }
        
        msgEl.textContent = 'Archivos subidos';
        e.target.value = '';
        
        // Recargar modal para mostrar nuevos adjuntos
        setTimeout(() => {
          modal.remove();
          this.viewFeedback(feedbackId);
        }, 1000);
        
      } catch (e) {
        msgEl.textContent = 'Error al subir archivos: ' + e.message;
      }
    });
  }

  async updateFeedbackStatus(id, status) {
    // Solo administradores pueden cambiar estados
    if (!this.isAdmin()) {
      Config.toast('No tienes permisos para cambiar el estado del feedback');
      return;
    }

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
  show: (opts) => feedbackSystem.show(opts),
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
