/* ================== Sistema de Gestión de Modales ================== */

class ModalManager {
  constructor() {
    this.activeModal = null;
    this.modals = new Map();
    this.init();
  }

  init() {
    this.setupGlobalListeners();
  }

  setupGlobalListeners() {
    // Cerrar modal con Escape
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && this.activeModal) {
        this.close(this.activeModal);
      }
    });

    // Cerrar modal al hacer clic fuera
    document.addEventListener('click', (e) => {
      if (this.activeModal && e.target === this.activeModal) {
        this.close(this.activeModal);
      }
    });
  }

  register(modalId, options = {}) {
    const modal = document.getElementById(modalId);
    if (!modal) return null;

    const config = {
      closable: true,
      backdrop: true,
      keyboard: true,
      ...options
    };

    this.modals.set(modalId, { element: modal, config });
    return modal;
  }

  show(modalId, options = {}) {
    const modalData = this.modals.get(modalId);
    if (!modalData) {
      const modal = this.register(modalId, options);
      if (!modal) return false;
      modalData = this.modals.get(modalId);
    }

    const { element, config } = modalData;
    const finalConfig = { ...config, ...options };

    // Cerrar modal activo si existe
    if (this.activeModal && this.activeModal !== element) {
      this.close(this.activeModal);
    }

    // Mostrar modal
    element.classList.remove('hidden');
    element.classList.add('flex');
    this.activeModal = element;

    // Agregar clase de animación
    setTimeout(() => {
      element.classList.add('opacity-100');
    }, 10);

    // Enfocar primer elemento interactivo
    const focusable = element.querySelector('input, button, select, textarea');
    if (focusable) {
      setTimeout(() => focusable.focus(), 100);
    }

    return true;
  }

  close(modalId) {
    const modal = typeof modalId === 'string' ? document.getElementById(modalId) : modalId;
    if (!modal) return false;

    // Animar salida
    modal.classList.remove('opacity-100');
    
    setTimeout(() => {
      modal.classList.add('hidden');
      modal.classList.remove('flex');
      
      if (this.activeModal === modal) {
        this.activeModal = null;
      }
    }, 300);

    return true;
  }

  toggle(modalId, options = {}) {
    const modal = document.getElementById(modalId);
    if (!modal) return false;

    if (modal.classList.contains('hidden')) {
      return this.show(modalId, options);
    } else {
      return this.close(modalId);
    }
  }

  closeAll() {
    this.modals.forEach(({ element }) => {
      if (!element.classList.contains('hidden')) {
        this.close(element);
      }
    });
  }
}

// Modal específico para guardar análisis
class SaveAnalysisModal {
  constructor() {
    this.modalId = 'modal-save';
    this.modalManager = new ModalManager();
    this.init();
  }

  init() {
    this.modalManager.register(this.modalId);
    this.setupEventListeners();
  }

  setupEventListeners() {
    // Botones de cerrar
    const closeBtn = document.getElementById('ms-close');
    const cancelBtn = document.getElementById('ms-cancel');
    
    closeBtn?.addEventListener('click', () => this.close());
    cancelBtn?.addEventListener('click', () => this.close());

    // Botón de guardar
    const saveBtn = document.getElementById('ms-save');
    saveBtn?.addEventListener('click', () => this.save());
  }

  show(analysisData = {}) {
    this.prepareModal(analysisData);
    return this.modalManager.show(this.modalId);
  }

  close() {
    return this.modalManager.close(this.modalId);
  }

  prepareModal(analysisData) {
    const symbol = analysisData.symbol || (document.getElementById('symbol')?.value || document.getElementById('symbol-select')?.value || '').toUpperCase();
    const analysisText = analysisData.text || document.getElementById('out')?.textContent || '';
    
    // Generar título inteligente
    const suggestedTitle = this.generateSmartTitle(symbol, analysisText);
    
    // Generar contenido del modal dinámicamente
    const msBody = document.getElementById('ms-body');
    if (msBody) {
      msBody.innerHTML = `
        <div class="grid md:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm text-gray-700">Título</label>
            <input id="ms-title" class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:ring-indigo-500 focus:border-indigo-500" value="${suggestedTitle.replace(/"/g,'&quot;')}">
          </div>
          <div>
            <label class="block text-sm text-gray-700">Resultado</label>
            <select id="ms-outcome" class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
              <option value="">--</option>
              <option value="pos">Positivo</option>
              <option value="neg">Negativo</option>
              <option value="neutro">Neutro</option>
            </select>
          </div>
          <div class="flex items-center gap-2">
            <input id="ms-traded" type="checkbox">
            <label class="text-sm text-gray-700">Operé según este análisis</label>
          </div>
        </div>
        <div>
          <label class="block text-sm text-gray-700">Notas</label>
          <textarea id="ms-notes" rows="4" class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="Entrada, gestión, salida, aprendizajes..."></textarea>
        </div>
        <div>
          <label class="block text-sm text-gray-700">Análisis (texto)</label>
          <pre class="bg-gray-50 p-2 rounded text-xs overflow-auto max-h-64 text-gray-800">${(analysisText||'No hay análisis para mostrar').replace(/</g,'&lt;')}</pre>
        </div>
        <div>
          <label class="block text-sm text-gray-700">Adjuntar capturas</label>
          <input id="ms-files" type="file" accept="image/*" multiple class="mt-1"/>
          <div id="ms-previews" class="mt-2 grid grid-cols-4 gap-2"></div>
        </div>
      `;
    }

    // Limpiar mensaje
    const msMsg = document.getElementById('ms-msg');
    if (msMsg) msMsg.textContent = '';
  }

  generateSmartTitle(symbol, analysisText) {
    if (!symbol || !analysisText) return `Análisis ${symbol || 'Símbolo'}`;
    
    const text = analysisText.toLowerCase();
    const now = new Date();
    const dateStr = now.toLocaleDateString('es-ES');
    
    // Detectar recomendación
    let recommendation = '';
    if (text.includes('comprar') || text.includes('buy')) {
      recommendation = 'COMPRA';
    } else if (text.includes('vender') || text.includes('sell')) {
      recommendation = 'VENTA';
    } else if (text.includes('neutral')) {
      recommendation = 'NEUTRAL';
    }
    
    // Detectar temporalidad
    let timeframe = '';
    if (text.includes('1min') || text.includes('1 min')) {
      timeframe = '1min';
    } else if (text.includes('5min') || text.includes('5 min')) {
      timeframe = '5min';
    } else if (text.includes('15min') || text.includes('15 min')) {
      timeframe = '15min';
    } else if (text.includes('30min') || text.includes('30 min')) {
      timeframe = '30min';
    } else if (text.includes('60min') || text.includes('1 hora')) {
      timeframe = '1h';
    } else if (text.includes('daily') || text.includes('diario')) {
      timeframe = 'Diario';
    } else if (text.includes('weekly') || text.includes('semanal')) {
      timeframe = 'Semanal';
    }
    
    // Detectar señales técnicas
    let signals = [];
    if (text.includes('rsi') && (text.includes('< 30') || text.includes('sobreventa'))) {
      signals.push('RSI Oversold');
    }
    if (text.includes('rsi') && (text.includes('> 70') || text.includes('sobrecompra'))) {
      signals.push('RSI Overbought');
    }
    if (text.includes('ema') && text.includes('cruce')) {
      signals.push('EMA Cross');
    }
    if (text.includes('rompimiento') || text.includes('breakout')) {
      signals.push('Breakout');
    }
    if (text.includes('soporte') || text.includes('support')) {
      signals.push('Support');
    }
    if (text.includes('resistencia') || text.includes('resistance')) {
      signals.push('Resistance');
    }
    
    // Construir título
    let title = `${symbol}`;
    
    if (timeframe) {
      title += ` ${timeframe}`;
    }
    
    if (recommendation) {
      title += ` - ${recommendation}`;
    }
    
    if (signals.length > 0) {
      title += ` (${signals.slice(0, 2).join(', ')})`;
    }
    
    title += ` - ${dateStr}`;
    
    return title;
  }

  async uploadImages() {
    const filesInput = document.getElementById('ms-files');
    const files = Array.from(filesInput?.files||[]);
    const out = [];
    
    for (const f of files.slice(0,6)){
      const fd = new FormData(); 
      fd.append('file', f);
      const url = `${Config.API_BASE}/analysis_upload.php`;
      const r = await fetch(url, { 
        method:'POST', 
        headers: Config.withAuthHeaders({}, true), 
        body: fd 
      });
      const txt = await r.text(); 
      let j; 
      try{ 
        j = JSON.parse(txt); 
      } catch { 
        throw new Error('Respuesta no JSON'); 
      }
      if (!r.ok || !j?.ok) throw new Error(j?.error || 'Fallo al subir');
      out.push({ url: j.url, mime: j.mime, size: j.size });
    }
    return out;
  }

  async save() {
    try {
      const msMsg = document.getElementById('ms-msg');
      if (msMsg) msMsg.textContent = 'Guardando...';
      
      const symbol = (document.getElementById('symbol')?.value || document.getElementById('symbol-select')?.value || '').toUpperCase();
      if (!symbol) { 
        if (msMsg) msMsg.textContent='Ingresa un símbolo primero'; 
        return; 
      }
      
      const settings = window.Analysis?.collectSettingsFromUI() || {};
      settings.symbol = symbol;
      const analysisText = document.getElementById('out')?.textContent || '';
      let atts = [];
      
      try{ 
        atts = await this.uploadImages(); 
      } catch(e){ 
        atts = []; 
      }
      
      // Obtener valores de los elementos dinámicos del modal
      const title = document.getElementById('ms-title')?.value?.trim() || null;
      const notes = document.getElementById('ms-notes')?.value || null;
      const traded = document.getElementById('ms-traded')?.checked || false;
      const outcome = document.getElementById('ms-outcome')?.value || '';
      
      const payload = {
        symbol,
        title,
        timeframe: (Array.isArray(settings.resolutions_json) && settings.resolutions_json[0]) ? settings.resolutions_json[0] : null,
        analysis_text: analysisText,
        snapshot_json: settings,
        user_notes: notes,
        traded: !!traded,
        outcome: outcome,
        attachments: atts,
      };
      
      const res = await Config.postWithFallback(['analysis_save_safe.php','analysis_save.php'], payload, true);
      
      if (res && res.ok) { 
        if (msMsg) msMsg.textContent = 'Guardado'; 
        this.close(); 
        Config.toast('Análisis guardado.'); 
      } else { 
        if (msMsg) msMsg.textContent = 'Guardado parcial'; 
      }
    } catch(e){ 
      const msMsg = document.getElementById('ms-msg');
      if (msMsg) msMsg.textContent = 'Error: '+(e?.message||e); 
    }
  }
}

// Modal específico para feedback
class FeedbackModal {
  constructor() {
    this.modalId = 'modal-fb';
    this.modalManager = new ModalManager();
    this.init();
  }

  init() {
    this.modalManager.register(this.modalId);
    this.setupEventListeners();
  }

  setupEventListeners() {
    // Botones de cerrar
    const closeBtn = document.getElementById('fb-close');
    const cancelBtn = document.getElementById('fb-cancel');
    
    closeBtn?.addEventListener('click', () => this.close());
    cancelBtn?.addEventListener('click', () => this.close());

    // Botón de enviar
    const sendBtn = document.getElementById('fb-send');
    sendBtn?.addEventListener('click', () => this.send());

    // Delegación de eventos para archivos
    document.addEventListener('change', (e)=>{
      const inp = e.target.closest('#fb-files'); 
      if (!inp) return;
      const files = Array.from(inp.files||[]);
      const previews = document.getElementById('fb-previews');
      if (!previews) return;
      
      previews.innerHTML='';
      for (const f of files.slice(0,6)){
        const url = URL.createObjectURL(f);
        const img = document.createElement('img'); 
        img.src=url; 
        img.className='rounded border max-h-24 object-cover w-full';
        previews.appendChild(img);
      }
    });
  }

  show() {
    this.prepareModal();
    return this.modalManager.show(this.modalId);
  }

  close() {
    return this.modalManager.close(this.modalId);
  }

  prepareModal() {
    const fbBody = document.getElementById('fb-body');
    if (fbBody) {
      fbBody.innerHTML = `
        <div>
          <label class="block text-sm text-gray-700">Tipo de feedback</label>
          <select id="fb-type" class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
            <option value="bug">🐛 Bug/Error</option>
            <option value="feature">💡 Solicitud de función</option>
            <option value="improvement">⚡ Mejora</option>
            <option value="question">❓ Pregunta</option>
            <option value="other">📝 Otro</option>
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
    }

    // Generar diagnósticos
    this.generateDiagnostics();
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
      language: navigator.language
    };

    diagnostics.textContent = Object.entries(info)
      .map(([key, value]) => `${key}: ${value}`)
      .join(' | ');
  }

  async uploadFiles() {
    const filesInput = document.getElementById('fb-files');
    const files = Array.from(filesInput?.files||[]);
    const out = [];
    
    for (const f of files.slice(0,6)){
      const fd = new FormData(); 
      fd.append('file', f);
      const url = `${Config.API_BASE}/feedback_upload.php`;
      const r = await fetch(url, { 
        method:'POST', 
        headers: Config.withAuthHeaders({}, true), 
        body: fd 
      });
      const txt = await r.text(); 
      let j; 
      try{ 
        j = JSON.parse(txt); 
      } catch { 
        throw new Error('Respuesta no JSON'); 
      }
      if (!r.ok || !j?.ok) throw new Error(j?.error || 'Fallo al subir');
      out.push({ url: j.url, mime: j.mime, size: j.size });
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
      try{ 
        attachments = await this.uploadFiles(); 
      } catch(e){ 
        attachments = []; 
      }
      
      const payload = {
        type,
        title,
        description,
        attachments,
        diagnostics: document.getElementById('fb-diagnostics')?.textContent || ''
      };
      
      const res = await Config.postWithFallback(['feedback_save_safe.php','feedback_save.php'], payload, true);
      
      if (res && res.ok) { 
        if (fbMsg) fbMsg.textContent = 'Enviado'; 
        this.close(); 
        Config.toast('Feedback enviado correctamente.'); 
      } else { 
        if (fbMsg) fbMsg.textContent = 'Error al enviar'; 
      }
    } catch(e){ 
      const fbMsg = document.getElementById('fb-msg');
      if (fbMsg) fbMsg.textContent = 'Error: '+(e?.message||e); 
    }
  }
}

// Inicializar modales
const modalManager = new ModalManager();
const saveAnalysisModal = new SaveAnalysisModal();
const feedbackModal = new FeedbackModal();

// Exportar para uso global
window.Modals = {
  manager: modalManager,
  saveAnalysis: saveAnalysisModal,
  feedback: feedbackModal,
  show: (modalId, options) => modalManager.show(modalId, options),
  close: (modalId) => modalManager.close(modalId),
  toggle: (modalId, options) => modalManager.toggle(modalId, options)
};

// Hacer funciones disponibles globalmente para compatibilidad
window.msShow = (show) => show ? saveAnalysisModal.show() : saveAnalysisModal.close();
window.fbShow = (show) => show ? feedbackModal.show() : feedbackModal.close();
