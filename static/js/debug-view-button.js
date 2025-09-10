// Debugger para el botón "Ver" del Knowledge Base
class ViewButtonDebugger {
    constructor() {
        this.logFile = 'api/logs/debug_view_button_frontend.log';
        this.logs = [];
        this.init();
    }

    init() {
        // Interceptar console.log para capturar logs
        const originalLog = console.log;
        const originalError = console.error;
        const originalWarn = console.warn;

        console.log = (...args) => {
            this.log('LOG', args);
            originalLog.apply(console, args);
        };

        console.error = (...args) => {
            this.log('ERROR', args);
            originalError.apply(console, args);
        };

        console.warn = (...args) => {
            this.log('WARN', args);
            originalWarn.apply(console, args);
        };
    }

    log(level, data) {
        const timestamp = new Date().toISOString();
        const logEntry = {
            timestamp,
            level,
            data: data.map(item => typeof item === 'object' ? JSON.stringify(item) : String(item))
        };
        this.logs.push(logEntry);
    }

    async debugViewButton(knowledgeId) {
        this.log('DEBUG', [`=== DEBUG VIEW BUTTON START - ID: ${knowledgeId} ===`]);
        
        try {
            // Paso 1: Verificar elementos del DOM
            this.log('DEBUG', ['Step 1: Checking DOM elements...']);
            const modal = document.getElementById('knowledge-modal');
            const content = document.getElementById('knowledge-modal-content');
            
            this.log('DEBUG', ['Modal element:', modal]);
            this.log('DEBUG', ['Content element:', content]);
            
            if (!modal) {
                this.log('ERROR', ['Modal element not found']);
                return { success: false, error: 'Modal element not found' };
            }
            
            if (!content) {
                this.log('ERROR', ['Content element not found']);
                return { success: false, error: 'Content element not found' };
            }

            // Paso 2: Verificar token de autenticación
            this.log('DEBUG', ['Step 2: Checking authentication token...']);
            const token = this.getToken();
            this.log('DEBUG', ['Token available:', !!token]);
            
            if (!token) {
                this.log('ERROR', ['No authentication token']);
                return { success: false, error: 'No authentication token' };
            }

            // Paso 3: Verificar API base
            this.log('DEBUG', ['Step 3: Checking API base...']);
            const apiBase = this.getApiBase();
            this.log('DEBUG', ['API Base:', apiBase]);

            // Paso 4: Construir URL
            this.log('DEBUG', ['Step 4: Building URL...']);
            const url = `${apiBase}/debug_view_button.php?id=${knowledgeId}`;
            this.log('DEBUG', ['URL:', url]);

            // Paso 5: Realizar fetch
            this.log('DEBUG', ['Step 5: Making fetch request...']);
            const response = await fetch(url, {
                headers: {
                    'Authorization': `Bearer ${token}`
                }
            });

            this.log('DEBUG', ['Response status:', response.status]);
            this.log('DEBUG', ['Response ok:', response.ok]);

            if (!response.ok) {
                const errorText = await response.text();
                this.log('ERROR', ['Response not ok:', errorText]);
                return { success: false, error: `HTTP ${response.status}: ${errorText}` };
            }

            // Paso 6: Parsear respuesta
            this.log('DEBUG', ['Step 6: Parsing response...']);
            const data = await response.json();
            this.log('DEBUG', ['Response data:', data]);

            if (!data.ok) {
                this.log('ERROR', ['Response data not ok:', data]);
                return { success: false, error: 'Response data not ok' };
            }

            // Paso 7: Mostrar modal
            this.log('DEBUG', ['Step 7: Showing modal...']);
            this.showKnowledgeModal(data.knowledge);
            
            this.log('DEBUG', ['=== DEBUG VIEW BUTTON END - SUCCESS ===']);
            return { success: true, data };

        } catch (error) {
            this.log('ERROR', ['Exception in debugViewButton:', error.message, error.stack]);
            return { success: false, error: error.message };
        }
    }

    showKnowledgeModal(knowledge) {
        this.log('DEBUG', ['showKnowledgeModal called with:', knowledge]);
        
        const modal = document.getElementById('knowledge-modal');
        const content = document.getElementById('knowledge-modal-content');
        
        this.log('DEBUG', ['Modal element:', modal]);
        this.log('DEBUG', ['Content element:', content]);
        
        if (modal && content) {
            this.log('DEBUG', ['Building modal content...']);
            
            content.innerHTML = `
                <div class="space-y-4">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="text-3xl">${this.getFileIcon(knowledge.file_type)}</div>
                        <div>
                            <h4 class="text-lg font-semibold text-gray-900 dark-mode:text-gray-100">${knowledge.title}</h4>
                            <p class="text-sm text-gray-600 dark-mode:text-gray-300">${knowledge.original_filename}</p>
                        </div>
                    </div>
                    
                    <div class="bg-gray-50 dark-mode:bg-gray-700 rounded-lg p-4">
                        <h5 class="font-medium text-gray-900 dark-mode:text-gray-100 mb-2">Resumen</h5>
                        <p class="text-sm text-gray-700 dark-mode:text-gray-300">${knowledge.summary || 'Sin resumen disponible'}</p>
                    </div>
                    
                    <div class="bg-gray-50 dark-mode:bg-gray-700 rounded-lg p-4">
                        <h5 class="font-medium text-gray-900 dark-mode:text-gray-100 mb-2">Contenido Extraído</h5>
                        <div class="text-sm text-gray-700 dark-mode:text-gray-300 max-h-40 overflow-y-auto">
                            ${knowledge.content || 'Sin contenido disponible'}
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <div><strong>Tipo:</strong> ${knowledge.knowledge_type}</div>
                        <div><strong>Archivo:</strong> ${knowledge.file_type}</div>
                        <div><strong>Tamaño:</strong> ${this.formatFileSize(knowledge.file_size)}</div>
                        <div><strong>Confianza:</strong> ${(knowledge.confidence_score * 100).toFixed(1)}%</div>
                        <div><strong>Usos:</strong> ${knowledge.usage_count}</div>
                        <div><strong>Tasa de éxito:</strong> ${(knowledge.success_rate * 100).toFixed(1)}%</div>
                    </div>
                </div>
            `;
            
            this.log('DEBUG', ['Modal content built, showing modal...']);
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            this.log('DEBUG', ['Modal should be visible now']);
        } else {
            this.log('ERROR', ['Modal or content element not found']);
        }
    }

    getFileIcon(fileType) {
        const icons = {
            'pdf': '📄',
            'txt': '📝',
            'doc': '📄',
            'docx': '📄',
            'default': '📁'
        };
        return icons[fileType] || icons.default;
    }

    formatFileSize(bytes) {
        if (!bytes) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    getToken() {
        // Intentar obtener token de diferentes fuentes
        if (window.Config && window.Config.getToken) {
            return window.Config.getToken();
        }
        if (window.localStorage && window.localStorage.getItem('auth_token')) {
            return window.localStorage.getItem('auth_token');
        }
        return null;
    }

    getApiBase() {
        // Intentar obtener API base de diferentes fuentes
        if (window.aiDashboard && window.aiDashboard.apiBase) {
            return window.aiDashboard.apiBase;
        }
        return 'api';
    }

    async saveLogs() {
        try {
            const response = await fetch('api/log_debug.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${this.getToken()}`
                },
                body: JSON.stringify({
                    logs: this.logs,
                    source: 'view_button_debugger'
                })
            });
            
            if (response.ok) {
                this.log('DEBUG', ['Logs saved successfully']);
            } else {
                this.log('ERROR', ['Failed to save logs:', response.status]);
            }
        } catch (error) {
            this.log('ERROR', ['Error saving logs:', error.message]);
        }
    }
}

// Crear instancia global
window.viewButtonDebugger = new ViewButtonDebugger();

// Función para probar el botón "Ver" con debugging
window.debugViewButton = async function(knowledgeId) {
    const result = await window.viewButtonDebugger.debugViewButton(knowledgeId);
    await window.viewButtonDebugger.saveLogs();
    return result;
};
