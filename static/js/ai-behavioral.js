/* ================== IA Comportamental Dashboard ================== */

class AIBehavioralDashboard {
    constructor() {
        this.apiBase = 'api';
        this.isInitialized = false;
        this.metrics = null;
        this.profile = null;
        this.patterns = [];
        this.insights = [];
        this.history = [];
    }

    async initialize() {
        if (this.isInitialized) return;
        
        try {
            // Verificar autenticación
            const token = this.getToken();
            if (!token) {
                console.warn('Usuario no autenticado');
                return;
            }

            // Cargar configuración existente
            this.loadExistingConfig();
            
            // Cargar datos iniciales
            await this.loadDashboardData();
            
            // Configurar event listeners
            this.setupEventListeners();
            
            // Iniciar actualización automática
            this.startAutoRefresh();
            
            this.isInitialized = true;
            console.log('Dashboard de IA Comportamental inicializado');
        } catch (error) {
            console.error('Error inicializando dashboard:', error);
        }
    }

    loadExistingConfig() {
        // Cargar configuración existente desde localStorage
        try {
            const config = JSON.parse(localStorage.getItem('ai_behavioral_config') || '{}');
            
            // Aplicar configuración a los elementos del formulario
            if (config.ai_provider) {
                const providerSelect = document.getElementById('ai-provider');
                if (providerSelect) providerSelect.value = config.ai_provider;
            }
            
            if (config.ai_model) {
                const modelInput = document.getElementById('ai-model');
                if (modelInput) modelInput.value = config.ai_model;
            }
            
            if (config.analysis_level) {
                const levelSelect = document.getElementById('analysis-level');
                if (levelSelect) levelSelect.value = config.analysis_level;
            }
            
            if (config.trading_style) {
                const styleSelect = document.getElementById('trading-style');
                if (styleSelect) styleSelect.value = config.trading_style;
            }
            
            if (config.analysis_tone) {
                const toneSelect = document.getElementById('analysis-tone');
                if (toneSelect) toneSelect.value = config.analysis_tone;
            }
            
            if (config.use_behavioral_data !== undefined) {
                const behavioralCheck = document.getElementById('use-behavioral-data');
                if (behavioralCheck) behavioralCheck.checked = config.use_behavioral_data;
            }
            
            if (config.learn_from_feedback !== undefined) {
                const feedbackCheck = document.getElementById('learn-from-feedback');
                if (feedbackCheck) feedbackCheck.checked = config.learn_from_feedback;
            }
            
            if (config.adaptive_prompts !== undefined) {
                const adaptiveCheck = document.getElementById('adaptive-prompts');
                if (adaptiveCheck) adaptiveCheck.checked = config.adaptive_prompts;
            }
            
            if (config.risk_aware !== undefined) {
                const riskCheck = document.getElementById('risk-aware');
                if (riskCheck) riskCheck.checked = config.risk_aware;
            }
            
            console.log('Configuración existente cargada:', config);
        } catch (error) {
            console.warn('Error cargando configuración existente:', error);
        }
    }

    getToken() {
        return localStorage.getItem('auth_token');
    }

    async loadDashboardData() {
        try {
            // Cargar métricas de aprendizaje
            await this.loadLearningMetrics();
            
            // Cargar perfil comportamental
            await this.loadBehavioralProfile();
            
            // Cargar patrones aprendidos
            await this.loadLearnedPatterns();
            
            // Cargar insights personalizados
            await this.loadPersonalInsights();
            
            // Cargar historial de aprendizaje
            await this.loadLearningHistory();
            
        } catch (error) {
            console.error('Error cargando datos del dashboard:', error);
        }
    }

    async loadLearningMetrics() {
        try {
            const response = await fetch(`${this.apiBase}/ai_learning_metrics_safe.php`, {
                headers: {
                    'Authorization': `Bearer ${this.getToken()}`
                }
            });
            
            if (response.ok) {
                const data = await response.json();
                this.metrics = data.metrics;
                this.updateMetricsDisplay();
            }
        } catch (error) {
            console.error('Error cargando métricas:', error);
        }
    }

    async loadBehavioralProfile() {
        try {
            const response = await fetch(`${this.apiBase}/ai_behavioral_patterns_safe.php`, {
                headers: {
                    'Authorization': `Bearer ${this.getToken()}`
                }
            });
            
            if (response.ok) {
                const data = await response.json();
                this.profile = data.profile;
                this.patterns = data.patterns;
                this.updateProfileDisplay();
                this.updatePatternsDisplay();
            }
        } catch (error) {
            console.error('Error cargando perfil comportamental:', error);
        }
    }

    async loadLearnedPatterns() {
        // Los patrones ya se cargan en loadBehavioralProfile
        this.updatePatternsDisplay();
    }

    async loadPersonalInsights() {
        try {
            // Generar insights basados en métricas y patrones
            this.insights = this.generatePersonalInsights();
            this.updateInsightsDisplay();
        } catch (error) {
            console.error('Error generando insights:', error);
        }
    }

    async loadLearningHistory() {
        try {
            const response = await fetch(`${this.apiBase}/ai_analysis_history_safe.php?limit=10`, {
                headers: {
                    'Authorization': `Bearer ${this.getToken()}`
                }
            });
            
            if (response.ok) {
                const data = await response.json();
                this.history = data.analyses;
                this.updateHistoryDisplay();
            }
        } catch (error) {
            console.error('Error cargando historial:', error);
        }
    }

    updateMetricsDisplay() {
        if (!this.metrics) return;

        // Actualizar métricas
        document.getElementById('total-analyses').textContent = this.metrics.total_analyses || 0;
        document.getElementById('success-rate').textContent = `${(this.metrics.success_rate || 0).toFixed(1)}%`;
        document.getElementById('patterns-learned').textContent = this.metrics.patterns_learned || 0;
        document.getElementById('accuracy-score').textContent = `${(this.metrics.accuracy_score || 0).toFixed(1)}%`;
    }

    updateProfileDisplay() {
        if (!this.profile) return;

        // Actualizar perfil comportamental
        const tradingStyle = this.profile.trading_style || 'equilibrado';
        const riskTolerance = this.profile.risk_tolerance || 'moderada';
        const timePreference = this.profile.time_preference || 'intradia';

        // Mapear valores a porcentajes para las barras
        const styleMap = { 'conservador': 25, 'equilibrado': 50, 'agresivo': 75 };
        const riskMap = { 'baja': 25, 'moderada': 50, 'alta': 75 };
        const timeMap = { 'intradia': 25, 'swing': 50, 'largo_plazo': 75 };

        // Actualizar texto y barras
        document.getElementById('trading-style').textContent = tradingStyle.charAt(0).toUpperCase() + tradingStyle.slice(1);
        document.getElementById('trading-style-bar').style.width = `${styleMap[tradingStyle] || 50}%`;

        document.getElementById('risk-tolerance').textContent = riskTolerance.charAt(0).toUpperCase() + riskTolerance.slice(1);
        document.getElementById('risk-tolerance-bar').style.width = `${riskMap[riskTolerance] || 50}%`;

        document.getElementById('time-preference').textContent = timePreference.charAt(0).toUpperCase() + timePreference.slice(1);
        document.getElementById('time-preference-bar').style.width = `${timeMap[timePreference] || 50}%`;
    }

    updatePatternsDisplay() {
        const container = document.getElementById('learned-patterns');
        if (!container) return;

        if (this.patterns.length === 0) {
            container.innerHTML = `
                <div class="text-center py-8 text-gray-500 dark-mode:text-gray-400">
                    <div class="text-4xl mb-2">📊</div>
                    <p>Los patrones aparecerán aquí conforme uses el sistema</p>
                </div>
            `;
            return;
        }

        const patternsHtml = this.patterns.map(pattern => `
            <div class="bg-gray-50 dark-mode:bg-gray-700 rounded-lg p-4">
                <div class="flex justify-between items-start mb-2">
                    <h4 class="font-medium text-gray-900 dark-mode:text-gray-100">${pattern.pattern_type}</h4>
                    <span class="text-sm text-gray-500 dark-mode:text-gray-400">${pattern.frequency}x</span>
                </div>
                <p class="text-sm text-gray-600 dark-mode:text-gray-300">${pattern.pattern_data}</p>
                <div class="mt-2 flex justify-between items-center">
                    <span class="text-xs text-gray-500 dark-mode:text-gray-400">Confianza: ${(pattern.confidence * 100).toFixed(1)}%</span>
                    <span class="text-xs text-gray-500 dark-mode:text-gray-400">${new Date(pattern.last_seen).toLocaleDateString()}</span>
                </div>
            </div>
        `).join('');

        container.innerHTML = patternsHtml;
    }

    generatePersonalInsights() {
        const insights = [];
        
        if (this.metrics) {
            // Insight basado en tasa de éxito
            if (this.metrics.success_rate > 70) {
                insights.push({
                    type: 'success',
                    title: 'Excelente Rendimiento',
                    message: `Tu tasa de éxito del ${this.metrics.success_rate.toFixed(1)}% es muy buena. Mantén tu estrategia actual.`,
                    icon: '🎯'
                });
            } else if (this.metrics.success_rate < 40) {
                insights.push({
                    type: 'warning',
                    title: 'Oportunidad de Mejora',
                    message: `Tu tasa de éxito del ${this.metrics.success_rate.toFixed(1)}% puede mejorar. Considera ajustar tu estrategia.`,
                    icon: '⚠️'
                });
            }

            // Insight basado en número de análisis
            if (this.metrics.total_analyses > 50) {
                insights.push({
                    type: 'info',
                    title: 'Experiencia Consolidada',
                    message: `Con ${this.metrics.total_analyses} análisis realizados, tienes una base sólida de experiencia.`,
                    icon: '📈'
                });
            } else if (this.metrics.total_analyses < 10) {
                insights.push({
                    type: 'info',
                    title: 'Construyendo Experiencia',
                    message: `Realiza más análisis para que la IA pueda aprender mejor tus patrones.`,
                    icon: '🚀'
                });
            }
        }

        if (this.profile) {
            // Insight basado en perfil
            if (this.profile.trading_style === 'agresivo' && this.profile.risk_tolerance === 'alta') {
                insights.push({
                    type: 'info',
                    title: 'Perfil Agresivo',
                    message: 'Tu perfil agresivo requiere gestión de riesgo cuidadosa. Considera diversificar.',
                    icon: '⚡'
                });
            }
        }

        return insights;
    }

    updateInsightsDisplay() {
        const container = document.getElementById('personal-insights');
        if (!container) return;

        if (this.insights.length === 0) {
            container.innerHTML = `
                <div class="text-center py-8 text-gray-500 dark-mode:text-gray-400">
                    <div class="text-4xl mb-2">💡</div>
                    <p>Los insights se generarán basados en tu comportamiento</p>
                </div>
            `;
            return;
        }

        const insightsHtml = this.insights.map(insight => `
            <div class="bg-gray-50 dark-mode:bg-gray-700 rounded-lg p-4 border-l-4 ${this.getInsightBorderColor(insight.type)}">
                <div class="flex items-start gap-3">
                    <span class="text-2xl">${insight.icon}</span>
                    <div>
                        <h4 class="font-medium text-gray-900 dark-mode:text-gray-100">${insight.title}</h4>
                        <p class="text-sm text-gray-600 dark-mode:text-gray-300 mt-1">${insight.message}</p>
                    </div>
                </div>
            </div>
        `).join('');

        container.innerHTML = insightsHtml;
    }

    getInsightBorderColor(type) {
        const colors = {
            'success': 'border-green-500',
            'warning': 'border-yellow-500',
            'error': 'border-red-500',
            'info': 'border-blue-500'
        };
        return colors[type] || 'border-gray-500';
    }

    updateHistoryDisplay() {
        const container = document.getElementById('learning-history');
        if (!container) return;

        if (this.history.length === 0) {
            container.innerHTML = `
                <div class="text-center py-8 text-gray-500 dark-mode:text-gray-400">
                    <div class="text-4xl mb-2">📚</div>
                    <p>El historial de aprendizaje se mostrará aquí</p>
                </div>
            `;
            return;
        }

        const historyHtml = this.history.map(analysis => `
            <div class="bg-gray-50 dark-mode:bg-gray-700 rounded-lg p-4">
                <div class="flex justify-between items-start mb-2">
                    <h4 class="font-medium text-gray-900 dark-mode:text-gray-100">${analysis.symbol}</h4>
                    <span class="text-sm text-gray-500 dark-mode:text-gray-400">${new Date(analysis.created_at).toLocaleDateString()}</span>
                </div>
                <p class="text-sm text-gray-600 dark-mode:text-gray-300 mb-2">${analysis.analysis_text.substring(0, 100)}...</p>
                <div class="flex justify-between items-center">
                    <span class="text-xs px-2 py-1 rounded ${this.getOutcomeColor(analysis.outcome)}">${this.getOutcomeText(analysis.outcome)}</span>
                    <span class="text-xs text-gray-500 dark-mode:text-gray-400">Confianza: ${(analysis.confidence_score * 100).toFixed(1)}%</span>
                </div>
            </div>
        `).join('');

        container.innerHTML = historyHtml;
    }

    getOutcomeColor(outcome) {
        const colors = {
            'positive': 'bg-green-100 text-green-800 dark-mode:bg-green-900 dark-mode:text-green-200',
            'negative': 'bg-red-100 text-red-800 dark-mode:bg-red-900 dark-mode:text-red-200',
            'neutral': 'bg-gray-100 text-gray-800 dark-mode:bg-gray-900 dark-mode:text-gray-200'
        };
        return colors[outcome] || 'bg-gray-100 text-gray-800 dark-mode:bg-gray-900 dark-mode:text-gray-200';
    }

    getOutcomeText(outcome) {
        const texts = {
            'positive': 'Positivo',
            'negative': 'Negativo',
            'neutral': 'Neutro'
        };
        return texts[outcome] || 'Pendiente';
    }

    setupEventListeners() {
        // Botón de análisis
        const analyzeBtn = document.getElementById('analyze-btn');
        if (analyzeBtn) {
            analyzeBtn.addEventListener('click', () => this.performAnalysis());
        }

        // Botón de guardar análisis
        const saveBtn = document.getElementById('save-analysis-btn');
        if (saveBtn) {
            saveBtn.addEventListener('click', () => this.saveAnalysis());
        }

        // Botón de copiar análisis
        const copyBtn = document.getElementById('copy-analysis-btn');
        if (copyBtn) {
            copyBtn.addEventListener('click', () => this.copyAnalysis());
        }

        // Configuración avanzada
        this.setupAdvancedConfig();
        
        // Configuración de IA
        this.setupAIConfig();
        
        // Botón de actualizar métricas
        const refreshBtn = document.getElementById('refresh-metrics-btn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => this.refreshDashboard());
        }
        
        // Botón de guardar configuración
        const saveConfigBtn = document.getElementById('save-config-btn');
        if (saveConfigBtn) {
            saveConfigBtn.addEventListener('click', () => this.saveConfiguration());
        }

        // Knowledge Base
        this.setupKnowledgeBase();
        
        // Patrones mejorados
        this.setupPatternsVisualization();
    }

    setupAdvancedConfig() {
        // Botón para abrir configuración avanzada (si existe)
        const advancedBtn = document.getElementById('advanced-config-btn');
        if (advancedBtn) {
            advancedBtn.addEventListener('click', () => this.openAdvancedConfig());
        }

        // Botón para cerrar configuración avanzada
        const closeBtn = document.getElementById('close-advanced-modal');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => this.closeAdvancedConfig());
        }
    }

    setupAIConfig() {
        // Configurar proveedor de IA
        const aiProvider = document.getElementById('ai-provider');
        if (aiProvider) {
            aiProvider.addEventListener('change', () => this.updateAIConfig());
        }

        // Configurar nivel de análisis
        const analysisLevel = document.getElementById('analysis-level');
        if (analysisLevel) {
            analysisLevel.addEventListener('change', () => this.updateAIConfig());
        }

        // Configurar opciones de personalización
        const useBehavioralData = document.getElementById('use-behavioral-data');
        if (useBehavioralData) {
            useBehavioralData.addEventListener('change', () => this.updateAIConfig());
        }

        const learnFromFeedback = document.getElementById('learn-from-feedback');
        if (learnFromFeedback) {
            learnFromFeedback.addEventListener('change', () => this.updateAIConfig());
        }

        const adaptivePrompts = document.getElementById('adaptive-prompts');
        if (adaptivePrompts) {
            adaptivePrompts.addEventListener('change', () => this.updateAIConfig());
        }
    }

    openAdvancedConfig() {
        const modal = document.getElementById('advanced-config-modal');
        if (!modal) return;

        // Generar contenido de configuración avanzada
        const content = document.getElementById('advanced-config-content');
        if (content) {
            content.innerHTML = `
                <div class="space-y-6">
                    <div>
                        <h4 class="text-lg font-medium text-gray-900 dark-mode:text-gray-100 mb-4">Configuración de Aprendizaje</h4>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300 mb-2">Velocidad de Aprendizaje</label>
                                <select id="learning-speed" class="w-full rounded-lg border-gray-300 dark-mode:border-gray-600 dark-mode:bg-gray-700 dark-mode:text-gray-100 shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                                    <option value="slow">Lento (Conservador)</option>
                                    <option value="medium" selected>Medio (Equilibrado)</option>
                                    <option value="fast">Rápido (Agresivo)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300 mb-2">Sensibilidad a Patrones</label>
                                <input type="range" id="pattern-sensitivity" min="1" max="10" value="5" class="w-full">
                                <div class="flex justify-between text-xs text-gray-500 dark-mode:text-gray-400">
                                    <span>Baja</span>
                                    <span>Alta</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <h4 class="text-lg font-medium text-gray-900 dark-mode:text-gray-100 mb-4">Configuración de Análisis</h4>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300 mb-2">Profundidad de Análisis</label>
                                <select id="analysis-depth" class="w-full rounded-lg border-gray-300 dark-mode:border-gray-600 dark-mode:bg-gray-700 dark-mode:text-gray-100 shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                                    <option value="shallow">Superficial (Rápido)</option>
                                    <option value="medium" selected>Medio (Equilibrado)</option>
                                    <option value="deep">Profundo (Detallado)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300 mb-2">Incluir Análisis Técnico</label>
                                <input type="checkbox" id="include-technical" checked class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300 mb-2">Incluir Análisis Fundamental</label>
                                <input type="checkbox" id="include-fundamental" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                            </div>
                        </div>
                    </div>

                    <div>
                        <h4 class="text-lg font-medium text-gray-900 dark-mode:text-gray-100 mb-4">Configuración de Notificaciones</h4>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300 mb-2">Alertas de Patrones</label>
                                <input type="checkbox" id="pattern-alerts" checked class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark-mode:text-gray-300 mb-2">Alertas de Rendimiento</label>
                                <input type="checkbox" id="performance-alerts" checked class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    closeAdvancedConfig() {
        const modal = document.getElementById('advanced-config-modal');
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    }

    updateAIConfig() {
        // Guardar configuración en localStorage
        const config = {
            ai_provider: document.getElementById('ai-provider')?.value || 'auto',
            ai_model: document.getElementById('ai-model')?.value || '',
            analysis_level: document.getElementById('analysis-level')?.value || 'advanced',
            use_behavioral_data: document.getElementById('use-behavioral-data')?.checked || false,
            learn_from_feedback: document.getElementById('learn-from-feedback')?.checked || false,
            adaptive_prompts: document.getElementById('adaptive-prompts')?.checked || false
        };

        localStorage.setItem('ai_behavioral_config', JSON.stringify(config));
        console.log('Configuración de IA actualizada:', config);
        
        // Sincronizar con index.html si está disponible
        this.syncConfigWithIndex(config);
    }

    syncConfigWithIndex(config) {
        // Sincronizar configuración con index.html sin romper funcionalidad existente
        try {
            // Actualizar elementos en index.html si existen
            const indexAIProvider = document.getElementById('ai-provider');
            const indexAIModel = document.getElementById('ai-model');
            
            if (indexAIProvider && indexAIProvider.value !== config.ai_provider) {
                indexAIProvider.value = config.ai_provider;
                console.log('Sincronizado ai-provider con index.html');
            }
            
            if (indexAIModel && indexAIModel.value !== config.ai_model) {
                indexAIModel.value = config.ai_model;
                console.log('Sincronizado ai-model con index.html');
            }
            
            // Disparar evento personalizado para notificar cambios
            window.dispatchEvent(new CustomEvent('aiConfigChanged', { 
                detail: { config, source: 'ai.html' } 
            }));
            
        } catch (error) {
            console.warn('Error sincronizando configuración con index.html:', error);
        }
    }

    async updateBehavioralProfile() {
        try {
            const response = await fetch(`${this.apiBase}/ai_behavioral_patterns_safe.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${this.getToken()}`
                },
                body: JSON.stringify({
                    trading_style: this.profile?.trading_style || 'equilibrado',
                    risk_tolerance: this.profile?.risk_tolerance || 'moderada',
                    time_preference: this.profile?.time_preference || 'intradia',
                    analysis_frequency: this.metrics?.total_analyses || 0
                })
            });

            if (response.ok) {
                console.log('Perfil comportamental actualizado');
                await this.loadBehavioralProfile();
            }
        } catch (error) {
            console.error('Error actualizando perfil comportamental:', error);
        }
    }

    async createLearningEvent(eventType, eventData) {
        try {
            const response = await fetch(`${this.apiBase}/ai_learning_events_safe.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${this.getToken()}`
                },
                body: JSON.stringify({
                    event_type: eventType,
                    event_data: eventData,
                    confidence_impact: 0.1
                })
            });

            if (response.ok) {
                console.log('Evento de aprendizaje creado:', eventType);
                return true;
            }
        } catch (error) {
            console.error('Error creando evento de aprendizaje:', error);
        }
        return false;
    }

    async refreshDashboard() {
        try {
            await this.loadDashboardData();
            console.log('Dashboard actualizado');
        } catch (error) {
            console.error('Error actualizando dashboard:', error);
        }
    }

    // Función para mostrar notificaciones
    showNotification(message, type = 'info') {
        // Crear elemento de notificación
        const notification = document.createElement('div');
        notification.className = `fixed top-4 right-4 z-50 px-6 py-3 rounded-lg shadow-lg text-white ${
            type === 'success' ? 'bg-green-500' :
            type === 'error' ? 'bg-red-500' :
            type === 'warning' ? 'bg-yellow-500' :
            'bg-blue-500'
        }`;
        notification.textContent = message;

        document.body.appendChild(notification);

        // Remover después de 3 segundos
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 3000);
    }

    async performAnalysis() {
        const symbol = document.getElementById('analysis-symbol')?.value?.trim();
        if (!symbol) {
            alert('Ingresa un símbolo para analizar');
            return;
        }

        const analyzeBtn = document.getElementById('analyze-btn');
        const analyzeText = document.getElementById('analyze-text');
        const analyzeLoading = document.getElementById('analyze-loading');
        const resultsContainer = document.getElementById('analysis-results');

        try {
            // Mostrar estado de carga
            analyzeBtn.disabled = true;
            analyzeText.classList.add('hidden');
            analyzeLoading.classList.remove('hidden');

            // Realizar análisis usando el sistema comportamental
            const analysis = await this.runBehavioralAnalysis(symbol);
            
            // Mostrar resultados
            this.displayAnalysisResults(analysis);
            
        } catch (error) {
            console.error('Error en análisis:', error);
            resultsContainer.innerHTML = `
                <div class="text-center py-8 text-red-600">
                    <div class="text-4xl mb-2">❌</div>
                    <p>Error realizando análisis: ${error.message}</p>
                </div>
            `;
        } finally {
            // Restaurar estado del botón
            analyzeBtn.disabled = false;
            analyzeText.classList.remove('hidden');
            analyzeLoading.classList.add('hidden');
        }
    }

    async runBehavioralAnalysis(symbol) {
        // Obtener configuración del usuario
        const config = JSON.parse(localStorage.getItem('ai_behavioral_config') || '{}');
        const timeframe = document.getElementById('timeframe')?.value || '15min';
        const analysisType = document.getElementById('analysis-type')?.value || 'comprehensive';
        
        // Construir prompt personalizado
        let prompt = `Analiza el activo ${symbol} con enfoque comportamental:\n\n`;
        
        // Agregar contexto comportamental si está disponible
        if (this.profile) {
            prompt += `CONTEXTO COMPORTAMENTAL:\n`;
            prompt += `- Estilo de trading: ${this.profile.trading_style}\n`;
            prompt += `- Tolerancia al riesgo: ${this.profile.risk_tolerance}\n`;
            prompt += `- Preferencia temporal: ${this.profile.time_preference}\n\n`;
        }
        
        if (this.metrics) {
            prompt += `MÉTRICAS DE APRENDIZAJE:\n`;
            prompt += `- Análisis previos: ${this.metrics.total_analyses}\n`;
            prompt += `- Tasa de éxito: ${this.metrics.success_rate}%\n`;
            prompt += `- Patrones aprendidos: ${this.metrics.patterns_learned}\n\n`;
        }
        
        prompt += `CONFIGURACIÓN:\n`;
        prompt += `- Temporalidad: ${timeframe}\n`;
        prompt += `- Tipo de análisis: ${analysisType}\n`;
        prompt += `- Nivel: ${config.analysis_level || 'advanced'}\n\n`;
        
        prompt += `Proporciona un análisis detallado y personalizado para este usuario.`;

        // Usar el sistema de integración comportamental si está disponible
        if (window.AIBehavioral && window.AIBehavioral.isInitialized) {
            return await window.AIBehavioral.enhanceAnalysisWithBehavioralAI(symbol, prompt, config.ai_provider || 'auto');
        } else {
            // Fallback al análisis tradicional
            const response = await fetch(`${this.apiBase}/ai_analyze.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${this.getToken()}`
                },
                body: JSON.stringify({
                    provider: config.ai_provider || 'auto',
                    model: config.ai_model || '',
                    prompt: prompt,
                    systemPrompt: 'Eres un analista de opciones intradía con IA comportamental. Adapta tu análisis al perfil específico del usuario y proporciona recomendaciones personalizadas.'
                })
            });

            if (response.ok) {
                const result = await response.json();
                // Agregar información de configuración al resultado
                result.behavioral_enhanced = true;
                result.config_used = config;
                return result;
            } else {
                throw new Error('Error en análisis de IA');
            }
        }
    }

    displayAnalysisResults(analysis) {
        const container = document.getElementById('analysis-results');
        if (!container) return;

        // Obtener datos del análisis para mostrar información técnica
        const symbol = document.getElementById('analysis-symbol')?.value?.trim() || 'Símbolo';
        const timeframe = document.getElementById('timeframe')?.value || '15min';
        const analysisType = document.getElementById('analysis-type')?.value || 'comprehensive';
        
        // Simular datos técnicos para mostrar estructura similar a index.html
        const mockTechnicalData = this.generateMockTechnicalData(symbol, timeframe);
        
        const html = `
            <div class="analysis-results">
                <div class="space-y-4">
                    <!-- Header con información básica -->
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="inline-flex items-center gap-2 text-sm px-3 py-1 rounded-full bg-gray-100 text-gray-800 dark-mode:bg-gray-700 dark-mode:text-gray-100">Símbolo <strong>${symbol}</strong></span>
                        <span class="text-sm px-3 py-1 rounded-full bg-blue-100 text-blue-800 dark-mode:bg-blue-900 dark-mode:text-blue-200">Temporalidad: ${timeframe}</span>
                        <span class="text-sm px-3 py-1 rounded-full bg-purple-100 text-purple-800 dark-mode:bg-purple-900 dark-mode:text-purple-200">Tipo: ${analysisType}</span>
                        ${analysis.confidence_score ? `<span class="text-sm px-3 py-1 rounded-full bg-green-100 text-green-800 dark-mode:bg-green-900 dark-mode:text-green-200">Confianza: ${(analysis.confidence_score * 100).toFixed(1)}%</span>` : ''}
                    </div>

                    <!-- Análisis Comportamental Principal -->
                    <div class="bg-indigo-50 dark-mode:bg-indigo-900/30 rounded-lg p-4">
                        <h3 class="font-semibold text-indigo-900 dark-mode:text-indigo-100 mb-2">🧠 Análisis Comportamental</h3>
                        <div class="text-indigo-800 dark-mode:text-indigo-200 whitespace-pre-wrap">${analysis.text || 'Sin análisis disponible'}</div>
                    </div>
                    
                    <!-- Mejoras Comportamentales -->
                    ${analysis.behavioral_enhanced ? `
                        <div class="bg-green-50 dark-mode:bg-green-900/30 rounded-lg p-4">
                            <h3 class="font-semibold text-green-900 dark-mode:text-green-100 mb-2">✨ Mejoras Comportamentales</h3>
                            <p class="text-green-800 dark-mode:text-green-200">Este análisis incluye contexto comportamental personalizado y patrones de aprendizaje.</p>
                        </div>
                    ` : ''}
                    
                    <!-- Datos Técnicos Simulados -->
                    <div class="rounded-lg border border-gray-200 dark-mode:border-gray-700 overflow-hidden">
                        <div class="px-4 py-2 text-sm font-medium bg-gray-50 dark-mode:bg-gray-800">📊 Datos Técnicos (Simulados)</div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-left">
                                <thead class="bg-gray-50 dark-mode:bg-gray-800 text-xs uppercase">
                                    <tr>
                                        <th class="px-3 py-2">Indicador</th>
                                        <th class="px-3 py-2">Valor</th>
                                        <th class="px-3 py-2">Señal</th>
                                        <th class="px-3 py-2">Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${mockTechnicalData.map(item => `
                                        <tr class="border-b last:border-0 border-gray-200 dark-mode:border-gray-700">
                                            <td class="px-3 py-2 text-sm font-medium">${item.name}</td>
                                            <td class="px-3 py-2 text-sm">${item.value}</td>
                                            <td class="px-3 py-2 text-sm">${item.signal}</td>
                                            <td class="px-3 py-2 text-sm">
                                                <span class="px-2 py-1 rounded-full text-xs ${item.status === 'BUY' ? 'bg-green-100 text-green-800 dark-mode:bg-green-900 dark-mode:text-green-200' : item.status === 'SELL' ? 'bg-red-100 text-red-800 dark-mode:bg-red-900 dark-mode:text-red-200' : 'bg-yellow-100 text-yellow-800 dark-mode:bg-yellow-900 dark-mode:text-yellow-200'}">${item.status}</span>
                                            </td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Configuración Utilizada -->
                    ${analysis.config_used ? `
                        <div class="bg-gray-50 dark-mode:bg-gray-800 rounded-lg p-4">
                            <h3 class="font-semibold text-gray-900 dark-mode:text-gray-100 mb-2">⚙️ Configuración Utilizada</h3>
                            <div class="grid grid-cols-2 gap-4 text-sm text-gray-700 dark-mode:text-gray-300">
                                <div><strong>Proveedor:</strong> ${analysis.config_used.ai_provider || 'auto'}</div>
                                <div><strong>Nivel:</strong> ${analysis.config_used.analysis_level || 'advanced'}</div>
                                <div><strong>Datos comportamentales:</strong> ${analysis.config_used.use_behavioral_data ? 'Sí' : 'No'}</div>
                                <div><strong>Prompts adaptativos:</strong> ${analysis.config_used.adaptive_prompts ? 'Sí' : 'No'}</div>
                            </div>
                        </div>
                    ` : ''}
                </div>
            </div>
        `;

        container.innerHTML = html;
    }

    generateMockTechnicalData(symbol, timeframe) {
        // Generar datos técnicos simulados para mostrar estructura similar a index.html
        const basePrice = 150 + Math.random() * 100;
        const rsi = 30 + Math.random() * 40;
        const sma20 = basePrice * (0.95 + Math.random() * 0.1);
        const ema20 = basePrice * (0.96 + Math.random() * 0.08);
        
        return [
            {
                name: 'Precio Actual',
                value: `$${basePrice.toFixed(2)}`,
                signal: rsi < 30 ? 'Oversold' : rsi > 70 ? 'Overbought' : 'Neutral',
                status: rsi < 30 ? 'BUY' : rsi > 70 ? 'SELL' : 'NEUTRAL'
            },
            {
                name: 'RSI(14)',
                value: rsi.toFixed(2),
                signal: rsi < 30 ? 'Oversold' : rsi > 70 ? 'Overbought' : 'Neutral',
                status: rsi < 30 ? 'BUY' : rsi > 70 ? 'SELL' : 'NEUTRAL'
            },
            {
                name: 'SMA(20)',
                value: `$${sma20.toFixed(2)}`,
                signal: basePrice > sma20 ? 'Above' : 'Below',
                status: basePrice > sma20 ? 'BUY' : 'SELL'
            },
            {
                name: 'EMA(20)',
                value: `$${ema20.toFixed(2)}`,
                signal: basePrice > ema20 ? 'Above' : 'Below',
                status: basePrice > ema20 ? 'BUY' : 'SELL'
            },
            {
                name: 'Volumen',
                value: `${(Math.random() * 1000000).toFixed(0)}`,
                signal: 'Normal',
                status: 'NEUTRAL'
            }
        ];
    }

    async saveAnalysis() {
        const symbol = document.getElementById('analysis-symbol')?.value?.trim();
        const analysisContainer = document.getElementById('analysis-results');
        const timeframe = document.getElementById('timeframe')?.value || '15min';
        const analysisType = document.getElementById('analysis-type')?.value || 'comprehensive';
        const config = JSON.parse(localStorage.getItem('ai_behavioral_config') || '{}');
        
        if (!symbol) {
            this.showNotification('Ingresa un símbolo para guardar', 'warning');
            return;
        }

        if (!analysisContainer || analysisContainer.textContent.trim() === '') {
            this.showNotification('No hay análisis para guardar', 'warning');
            return;
        }

        // Extraer el texto del análisis de manera más robusta
        let analysisText = '';
        const analysisDiv = analysisContainer.querySelector('.analysis-results');
        if (analysisDiv) {
            // Obtener solo el texto del análisis principal
            const mainAnalysis = analysisDiv.querySelector('.bg-indigo-50 p, .bg-indigo-900\\/30 p');
            if (mainAnalysis) {
                analysisText = mainAnalysis.textContent.trim();
            } else {
                // Fallback: obtener todo el texto
                analysisText = analysisDiv.textContent.trim();
            }
        } else {
            // Fallback: obtener todo el texto del contenedor
            analysisText = analysisContainer.textContent.trim();
        }

        if (!analysisText || analysisText === 'Sin análisis disponible') {
            this.showNotification('No hay análisis válido para guardar', 'warning');
            return;
        }

        try {
            // Crear evento de aprendizaje
            await this.createLearningEvent('analysis_created', {
                symbol,
                timeframe,
                analysis_type: analysisType,
                config_used: config
            });

            const response = await fetch(`${this.apiBase}/ai_analysis_save_safe.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${this.getToken()}`
                },
                body: JSON.stringify({
                    symbol,
                    analysis_text: analysisText,
                    timeframe,
                    analysis_type: analysisType,
                    ai_provider: config.ai_provider || 'behavioral_ai',
                    behavioral_context: {
                        profile: this.profile,
                        metrics: this.metrics,
                        config: config
                    }
                })
            });

            if (response.ok) {
                const result = await response.json();
                if (result.ok) {
                    this.showNotification('Análisis guardado exitosamente', 'success');
                    // Recargar datos del dashboard
                    await this.loadDashboardData();
                } else {
                    this.showNotification(`Error: ${result.error || 'Error desconocido'}`, 'error');
                }
            } else {
                const errorData = await response.json().catch(() => ({}));
                this.showNotification(`Error ${response.status}: ${errorData.error || 'Error del servidor'}`, 'error');
            }
        } catch (error) {
            console.error('Error guardando análisis:', error);
            this.showNotification(`Error de conexión: ${error.message}`, 'error');
        }
    }

    async copyAnalysis() {
        const analysisContainer = document.getElementById('analysis-results');
        const symbol = document.getElementById('analysis-symbol')?.value?.trim() || 'Símbolo';
        const timeframe = document.getElementById('timeframe')?.value || '15min';
        const analysisType = document.getElementById('analysis-type')?.value || 'comprehensive';
        
        if (!analysisContainer || analysisContainer.textContent.trim() === '') {
            this.showNotification('No hay análisis para copiar', 'warning');
            return;
        }

        // Extraer el texto del análisis de manera más robusta
        let analysisText = '';
        const analysisDiv = analysisContainer.querySelector('.analysis-results');
        if (analysisDiv) {
            // Obtener solo el texto del análisis principal
            const mainAnalysis = analysisDiv.querySelector('.bg-indigo-50 p, .bg-indigo-900\\/30 p');
            if (mainAnalysis) {
                analysisText = mainAnalysis.textContent.trim();
            } else {
                // Fallback: obtener todo el texto
                analysisText = analysisDiv.textContent.trim();
            }
        } else {
            // Fallback: obtener todo el texto del contenedor
            analysisText = analysisContainer.textContent.trim();
        }

        if (!analysisText || analysisText === 'Sin análisis disponible') {
            this.showNotification('No hay análisis válido para copiar', 'warning');
            return;
        }

        try {
            // Crear texto formateado para copiar
            const formattedText = `ANÁLISIS COMPORTAMENTAL - ${symbol.toUpperCase()}
Temporalidad: ${timeframe} | Tipo: ${analysisType}
Fecha: ${new Date().toLocaleString()}

${analysisText}

---
Generado por CATAI - Sistema de IA Comportamental`;

            await navigator.clipboard.writeText(formattedText);
            this.showNotification('Análisis copiado al portapapeles', 'success');
        } catch (error) {
            console.error('Error copiando análisis:', error);
            this.showNotification('Error copiando análisis', 'error');
        }
    }

    async saveConfiguration() {
        try {
            // Recopilar configuración actual
            const config = {
                ai_provider: document.getElementById('ai-provider')?.value || 'auto',
                ai_model: document.getElementById('ai-model')?.value || '',
                analysis_level: document.getElementById('analysis-level')?.value || 'advanced',
                trading_style: document.getElementById('trading-style')?.value || 'balanced',
                analysis_tone: document.getElementById('analysis-tone')?.value || 'educational',
                use_behavioral_data: document.getElementById('use-behavioral-data')?.checked || true,
                learn_from_feedback: document.getElementById('learn-from-feedback')?.checked || true,
                adaptive_prompts: document.getElementById('adaptive-prompts')?.checked || true,
                risk_aware: document.getElementById('risk-aware')?.checked || true,
                include_options: document.getElementById('include-options')?.checked || false,
                fast_mode: document.getElementById('fast-mode')?.checked || false
            };

            // Guardar en localStorage
            localStorage.setItem('ai_behavioral_config', JSON.stringify(config));

            // Guardar en servidor
            const response = await fetch(`${this.apiBase}/settings_set_safe.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${this.getToken()}`
                },
                body: JSON.stringify({
                    ai_behavioral_config: config
                })
            });

            if (response.ok) {
                this.showNotification('Configuración guardada exitosamente', 'success');
                console.log('Configuración guardada:', config);
            } else {
                this.showNotification('Error al guardar en el servidor', 'error');
            }
        } catch (error) {
            console.error('Error guardando configuración:', error);
            this.showNotification('Error al guardar configuración', 'error');
        }
    }

    startAutoRefresh() {
        // Actualizar datos cada 30 segundos
        setInterval(() => {
            if (this.isInitialized) {
                this.loadDashboardData();
            }
        }, 30000);
    }

    // ================== KNOWLEDGE BASE FUNCTIONS ==================

    setupKnowledgeBase() {
        // Botón de subir archivos
        const uploadBtn = document.getElementById('upload-knowledge-btn');
        if (uploadBtn) {
            uploadBtn.addEventListener('click', () => this.toggleUploadZone());
        }

        // Botón de seleccionar archivos
        const selectBtn = document.getElementById('select-files-btn');
        if (selectBtn) {
            selectBtn.addEventListener('click', () => this.selectFiles());
        }

        // Input de archivos
        const fileInput = document.getElementById('knowledge-files');
        if (fileInput) {
            fileInput.addEventListener('change', (e) => this.handleFileSelection(e));
        }

        // Botón de actualizar knowledge
        const refreshBtn = document.getElementById('refresh-knowledge-btn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => this.loadKnowledgeBase());
        }

        // Zona de drag & drop
        const uploadZone = document.getElementById('upload-zone');
        if (uploadZone) {
            uploadZone.addEventListener('dragover', (e) => this.handleDragOver(e));
            uploadZone.addEventListener('drop', (e) => this.handleDrop(e));
        }

        // Cargar knowledge base inicial
        this.loadKnowledgeBase();
    }

    toggleUploadZone() {
        const uploadZone = document.getElementById('upload-zone');
        if (uploadZone) {
            uploadZone.classList.toggle('hidden');
        }
    }

    selectFiles() {
        const fileInput = document.getElementById('knowledge-files');
        if (fileInput) {
            fileInput.click();
        }
    }

    handleFileSelection(event) {
        const files = Array.from(event.target.files);
        if (files.length > 0) {
            this.uploadFiles(files);
        }
    }

    handleDragOver(event) {
        event.preventDefault();
        event.currentTarget.classList.add('border-indigo-500', 'bg-indigo-50', 'dark-mode:bg-indigo-900/20');
    }

    handleDrop(event) {
        event.preventDefault();
        event.currentTarget.classList.remove('border-indigo-500', 'bg-indigo-50', 'dark-mode:bg-indigo-900/20');
        
        const files = Array.from(event.dataTransfer.files);
        if (files.length > 0) {
            this.uploadFiles(files);
        }
    }

    async uploadFiles(files) {
        const formData = new FormData();
        files.forEach(file => {
            formData.append('files', file);
        });

        try {
            this.showNotification('Subiendo archivos...', 'info');
            
            const response = await fetch(`${this.apiBase}/ai_upload_knowledge_safe.php`, {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${this.getToken()}`
                },
                body: formData
            });

            if (response.ok) {
                const result = await response.json();
                if (result.ok) {
                    this.showNotification(`${result.uploaded_count} archivo(s) subido(s) exitosamente`, 'success');
                    await this.loadKnowledgeBase();
                    this.toggleUploadZone(); // Ocultar zona de subida
                } else {
                    this.showNotification(`Error: ${result.error}`, 'error');
                }
            } else {
                this.showNotification('Error subiendo archivos', 'error');
            }
        } catch (error) {
            console.error('Error subiendo archivos:', error);
            this.showNotification('Error de conexión', 'error');
        }
    }

    async loadKnowledgeBase() {
        try {
            const response = await fetch(`${this.apiBase}/ai_knowledge_list_safe.php`, {
                headers: {
                    'Authorization': `Bearer ${this.getToken()}`
                }
            });

            if (response.ok) {
                const data = await response.json();
                this.updateKnowledgeDisplay(data.knowledge || []);
            }
        } catch (error) {
            console.error('Error cargando knowledge base:', error);
        }
    }

    updateKnowledgeDisplay(knowledgeItems) {
        const container = document.getElementById('knowledge-list');
        if (!container) return;

        if (knowledgeItems.length === 0) {
            container.innerHTML = `
                <div class="text-center py-8 text-gray-500 dark-mode:text-gray-400">
                    <div class="text-4xl mb-2">📚</div>
                    <p>Los archivos de conocimiento aparecerán aquí</p>
                </div>
            `;
            return;
        }

        const knowledgeHtml = knowledgeItems.map(item => `
            <div class="bg-gray-50 dark-mode:bg-gray-700 rounded-lg p-4">
                <div class="flex justify-between items-start mb-2">
                    <div class="flex items-center gap-3">
                        <div class="text-2xl">${this.getFileIcon(item.file_type)}</div>
                        <div>
                            <h4 class="font-medium text-gray-900 dark-mode:text-gray-100">${item.title}</h4>
                            <p class="text-sm text-gray-600 dark-mode:text-gray-300">${item.original_filename}</p>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button data-action="view" data-id="${item.id}" class="px-2 py-1 text-xs bg-blue-100 dark-mode:bg-blue-900 text-blue-700 dark-mode:text-blue-200 rounded hover:bg-blue-200 dark-mode:hover:bg-blue-800">
                            👁️ Ver
                        </button>
                        <button data-action="delete" data-id="${item.id}" class="px-2 py-1 text-xs bg-red-100 dark-mode:bg-red-900 text-red-700 dark-mode:text-red-200 rounded hover:bg-red-200 dark-mode:hover:bg-red-800">
                            🗑️ Eliminar
                        </button>
                    </div>
                </div>
                <div class="text-sm text-gray-600 dark-mode:text-gray-300 mb-2">
                    ${item.summary || 'Sin resumen disponible'}
                </div>
                <div class="flex justify-between items-center text-xs text-gray-500 dark-mode:text-gray-400">
                    <span>Tipo: ${item.knowledge_type}</span>
                    <span>Confianza: ${(item.confidence_score * 100).toFixed(1)}%</span>
                    <span>${new Date(item.created_at).toLocaleDateString()}</span>
                </div>
            </div>
        `).join('');

        container.innerHTML = knowledgeHtml;
        
        // Agregar event listeners a los botones
        this.setupKnowledgeButtons();
    }

    setupKnowledgeButtons() {
        const container = document.getElementById('knowledge-list');
        if (!container) {
            console.log('Knowledge list container not found');
            return;
        }
        
        console.log('Setting up knowledge buttons');
        
        // Event listener para botones de conocimiento
        container.addEventListener('click', (e) => {
            console.log('Knowledge button clicked:', e.target);
            const button = e.target.closest('button[data-action]');
            if (!button) {
                console.log('No button with data-action found');
                return;
            }
            
            const action = button.dataset.action;
            const id = button.dataset.id;
            console.log('Button action:', action, 'ID:', id);
            
            if (action === 'view') {
                this.viewKnowledge(id);
            } else if (action === 'delete') {
                this.deleteKnowledge(id);
            }
        });
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

    async viewKnowledge(knowledgeId) {
        console.log('viewKnowledge called with ID:', knowledgeId);
        console.log('API Base:', this.apiBase);
        console.log('Token available:', !!this.getToken());
        
        try {
            const url = `${this.apiBase}/ai_knowledge_get_safe.php?id=${knowledgeId}`;
            console.log('Fetching URL:', url);
            
            const response = await fetch(url, {
                headers: {
                    'Authorization': `Bearer ${this.getToken()}`
                }
            });

            console.log('Response status:', response.status);
            console.log('Response ok:', response.ok);

            if (response.ok) {
                const data = await response.json();
                console.log('Response data:', data);
                this.showKnowledgeModal(data.knowledge);
            } else {
                const errorText = await response.text();
                console.error('Error response:', errorText);
                this.showNotification('Error obteniendo conocimiento', 'error');
            }
        } catch (error) {
            console.error('Error obteniendo conocimiento:', error);
            this.showNotification('Error obteniendo conocimiento', 'error');
        }
    }

    showKnowledgeModal(knowledge) {
        console.log('showKnowledgeModal called with:', knowledge);
        const modal = document.getElementById('knowledge-modal');
        const content = document.getElementById('knowledge-modal-content');
        
        console.log('Modal element:', modal);
        console.log('Content element:', content);
        
        if (modal && content) {
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
                        <div><strong>Confianza:</strong> ${(knowledge.confidence_score * 100).toFixed(1)}%</div>
                        <div><strong>Uso:</strong> ${knowledge.usage_count || 0} veces</div>
                        <div><strong>Tasa de éxito:</strong> ${(knowledge.success_rate * 100).toFixed(1)}%</div>
                    </div>
                    
                    <div class="mt-4 pt-4 border-t border-gray-200 dark-mode:border-gray-600">
                        <button onclick="extractContent(${knowledge.id})" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors">
                            📄 Extraer Contenido Real
                        </button>
                        <p class="text-xs text-gray-500 dark-mode:text-gray-400 mt-2">
                            Extrae el contenido real del archivo PDF/DOC
                        </p>
                    </div>
                </div>
            `;
            
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            console.log('Modal should be visible now');
        } else {
            console.error('Modal or content element not found');
        }
    }

    // Modal de confirmación personalizado para eliminar archivos
    async showDeleteConfirmation(message) {
        return new Promise((resolve) => {
            // Crear modal de confirmación
            const modal = document.createElement('div');
            modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
            modal.innerHTML = `
                <div class="bg-white dark-mode:bg-gray-800 rounded-lg p-6 max-w-md mx-4 shadow-xl">
                    <div class="flex items-center mb-4">
                        <div class="flex-shrink-0">
                            <svg class="h-6 w-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-lg font-medium text-gray-900 dark-mode:text-gray-100">
                                Confirmar Eliminación
                            </h3>
                        </div>
                    </div>
                    <div class="mb-6">
                        <p class="text-sm text-gray-500 dark-mode:text-gray-400">
                            ${message}
                        </p>
                    </div>
                    <div class="flex justify-end space-x-3">
                        <button id="cancel-delete" class="px-4 py-2 text-sm font-medium text-gray-700 dark-mode:text-gray-300 bg-gray-100 dark-mode:bg-gray-700 rounded-md hover:bg-gray-200 dark-mode:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500">
                            Cancelar
                        </button>
                        <button id="confirm-delete" class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500">
                            Eliminar
                        </button>
                    </div>
                </div>
            `;

            // Agregar al DOM
            document.body.appendChild(modal);

            // Event listeners
            const cancelBtn = modal.querySelector('#cancel-delete');
            const confirmBtn = modal.querySelector('#confirm-delete');

            cancelBtn.addEventListener('click', () => {
                document.body.removeChild(modal);
                resolve(false);
            });

            confirmBtn.addEventListener('click', () => {
                document.body.removeChild(modal);
                resolve(true);
            });

            // Cerrar con Escape
            const handleEscape = (e) => {
                if (e.key === 'Escape') {
                    document.body.removeChild(modal);
                    document.removeEventListener('keydown', handleEscape);
                    resolve(false);
                }
            };
            document.addEventListener('keydown', handleEscape);

            // Cerrar al hacer clic fuera del modal
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    document.body.removeChild(modal);
                    document.removeEventListener('keydown', handleEscape);
                    resolve(false);
                }
            });
        });
    }

    async deleteKnowledge(knowledgeId) {
        console.log('deleteKnowledge called with ID:', knowledgeId);
        
        // Usar modal personalizado en lugar de confirm() para compatibilidad con Firefox
        const confirmed = await this.showDeleteConfirmation('¿Estás seguro de que quieres eliminar este archivo de conocimiento?');
        
        if (!confirmed) {
            console.log('User cancelled deletion');
            return;
        }

        console.log('Proceeding with deletion for ID:', knowledgeId);

        try {
            const requestData = { knowledge_id: knowledgeId };
            console.log('Sending delete request:', requestData);
            
            const response = await fetch(`${this.apiBase}/ai_knowledge_delete_safe.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${this.getToken()}`
                },
                body: JSON.stringify({ knowledge_id: knowledgeId })
            });

            console.log('Response status:', response.status);
            
            if (response.ok) {
                const result = await response.json();
                console.log('Delete response:', result);
                
                if (result.ok) {
                    this.showNotification('Archivo eliminado exitosamente', 'success');
                    await this.loadKnowledgeBase();
                } else {
                    console.error('Delete failed:', result.error);
                    this.showNotification(`Error: ${result.error}`, 'error');
                }
            } else {
                const errorText = await response.text();
                console.error('HTTP Error:', response.status, errorText);
                this.showNotification(`Error HTTP ${response.status}: ${errorText}`, 'error');
            }
        } catch (error) {
            console.error('Error eliminando conocimiento:', error);
            this.showNotification('Error de conexión', 'error');
        }
    }

    // Cerrar modal de knowledge
    closeKnowledgeModal() {
        const modal = document.getElementById('knowledge-modal');
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    }

    // ================== PATTERNS VISUALIZATION FUNCTIONS ==================

    setupPatternsVisualization() {
        // Botón de actualizar patrones
        const refreshBtn = document.getElementById('refresh-patterns-btn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => this.loadPatternsData());
        }

        // Botón de ver gráficos
        const chartBtn = document.getElementById('view-patterns-chart-btn');
        if (chartBtn) {
            chartBtn.addEventListener('click', () => this.showPatternsChart());
        }

        // Cargar patrones inicial
        this.loadPatternsData();
    }

    async loadPatternsData() {
        try {
            const response = await fetch(`${this.apiBase}/ai_behavioral_patterns_safe.php`, {
                headers: {
                    'Authorization': `Bearer ${this.getToken()}`
                }
            });

            if (response.ok) {
                const data = await response.json();
                this.updatePatternsDisplay(data.patterns || []);
                this.updatePatternsSummary(data.patterns || []);
            }
        } catch (error) {
            console.error('Error cargando patrones:', error);
        }
    }

    updatePatternsSummary(patterns) {
        const totalPatterns = patterns.length;
        const highConfidencePatterns = patterns.filter(p => p.confidence_score > 0.8).length;
        const frequentPatterns = patterns.filter(p => p.frequency > 5).length;

        document.getElementById('total-patterns').textContent = totalPatterns;
        document.getElementById('high-confidence-patterns').textContent = highConfidencePatterns;
        document.getElementById('frequent-patterns').textContent = frequentPatterns;

        const summaryDiv = document.getElementById('patterns-summary');
        if (summaryDiv) {
            summaryDiv.classList.remove('hidden');
        }
    }

    updatePatternsDisplay(patterns) {
        const container = document.getElementById('learned-patterns');
        if (!container) return;

        if (patterns.length === 0) {
            container.innerHTML = `
                <div class="text-center py-8 text-gray-500 dark-mode:text-gray-400">
                    <div class="text-4xl mb-2">📊</div>
                    <p>Los patrones aparecerán aquí conforme uses el sistema</p>
                </div>
            `;
            return;
        }

        const patternsHtml = patterns.map(pattern => `
            <div class="bg-gray-50 dark-mode:bg-gray-700 rounded-lg p-4">
                <div class="flex justify-between items-start mb-3">
                    <div class="flex items-center gap-3">
                        <div class="text-2xl">${this.getPatternIcon(pattern.pattern_type)}</div>
                        <div>
                            <h4 class="font-medium text-gray-900 dark-mode:text-gray-100">${pattern.pattern_name}</h4>
                            <p class="text-sm text-gray-600 dark-mode:text-gray-300">${pattern.description}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="text-right">
                            <div class="text-sm font-medium text-gray-900 dark-mode:text-gray-100">
                                ${(pattern.confidence_score * 100).toFixed(1)}%
                            </div>
                            <div class="text-xs text-gray-500 dark-mode:text-gray-400">Confianza</div>
                        </div>
                        <div class="w-16 bg-gray-200 dark-mode:bg-gray-600 rounded-full h-2">
                            <div class="bg-indigo-600 h-2 rounded-full" style="width: ${pattern.confidence_score * 100}%"></div>
                        </div>
                    </div>
                </div>
                
                <div class="grid grid-cols-3 gap-4 text-sm">
                    <div>
                        <span class="text-gray-500 dark-mode:text-gray-400">Frecuencia:</span>
                        <span class="font-medium text-gray-900 dark-mode:text-gray-100">${pattern.frequency || 0}</span>
                    </div>
                    <div>
                        <span class="text-gray-500 dark-mode:text-gray-400">Éxito:</span>
                        <span class="font-medium text-gray-900 dark-mode:text-gray-100">${((pattern.success_rate || 0) * 100).toFixed(1)}%</span>
                    </div>
                    <div>
                        <span class="text-gray-500 dark-mode:text-gray-400">Último:</span>
                        <span class="font-medium text-gray-900 dark-mode:text-gray-100">${new Date(pattern.last_seen).toLocaleDateString()}</span>
                    </div>
                </div>
                
                ${pattern.conditions ? `
                    <div class="mt-3 pt-3 border-t border-gray-200 dark-mode:border-gray-600">
                        <div class="text-xs text-gray-500 dark-mode:text-gray-400 mb-1">Condiciones:</div>
                        <div class="text-sm text-gray-700 dark-mode:text-gray-300">${pattern.conditions}</div>
                    </div>
                ` : ''}
            </div>
        `).join('');

        container.innerHTML = patternsHtml;
    }

    getPatternIcon(patternType) {
        const icons = {
            'entry_pattern': '📈',
            'exit_pattern': '📉',
            'risk_pattern': '⚠️',
            'timing_pattern': '⏰',
            'indicator_pattern': '📊',
            'volume_pattern': '📊',
            'volatility_pattern': '🌊',
            'default': '🔍'
        };
        return icons[patternType] || icons.default;
    }

    showPatternsChart() {
        const modal = document.getElementById('patterns-chart-modal');
        const content = document.getElementById('patterns-chart-content');
        
        if (modal && content) {
            content.innerHTML = `
                <div class="space-y-6">
                    <div class="grid grid-cols-2 gap-6">
                        <!-- Gráfico de Confianza -->
                        <div class="bg-gray-50 dark-mode:bg-gray-700 rounded-lg p-4">
                            <h4 class="font-semibold text-gray-900 dark-mode:text-gray-100 mb-4">Distribución de Confianza</h4>
                            <div id="confidence-chart" class="h-48 flex items-center justify-center text-gray-500 dark-mode:text-gray-400">
                                <div class="text-center">
                                    <div class="text-4xl mb-2">📊</div>
                                    <p>Gráfico de confianza se generará aquí</p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Gráfico de Frecuencia -->
                        <div class="bg-gray-50 dark-mode:bg-gray-700 rounded-lg p-4">
                            <h4 class="font-semibold text-gray-900 dark-mode:text-gray-100 mb-4">Frecuencia de Patrones</h4>
                            <div id="frequency-chart" class="h-48 flex items-center justify-center text-gray-500 dark-mode:text-gray-400">
                                <div class="text-center">
                                    <div class="text-4xl mb-2">📈</div>
                                    <p>Gráfico de frecuencia se generará aquí</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Gráfico de Tendencias -->
                    <div class="bg-gray-50 dark-mode:bg-gray-700 rounded-lg p-4">
                        <h4 class="font-semibold text-gray-900 dark-mode:text-gray-100 mb-4">Tendencias de Aprendizaje</h4>
                        <div id="trends-chart" class="h-64 flex items-center justify-center text-gray-500 dark-mode:text-gray-400">
                            <div class="text-center">
                                <div class="text-4xl mb-2">📊</div>
                                <p>Gráfico de tendencias se generará aquí</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-center">
                        <button onclick="aiDashboard.generateCharts()" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors">
                            🔄 Generar Gráficos
                        </button>
                    </div>
                </div>
            `;
            
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            
            // Generar gráficos después de mostrar el modal
            setTimeout(() => this.generateCharts(), 100);
        }
    }

    generateCharts() {
        // Generar gráficos simples con CSS y datos
        this.generateConfidenceChart();
        this.generateFrequencyChart();
        this.generateTrendsChart();
    }

    generateConfidenceChart() {
        const container = document.getElementById('confidence-chart');
        if (!container) return;

        // Datos simulados para el gráfico
        const data = [
            { range: '0-20%', count: 2, color: 'bg-red-500' },
            { range: '21-40%', count: 3, color: 'bg-orange-500' },
            { range: '41-60%', count: 5, color: 'bg-yellow-500' },
            { range: '61-80%', count: 8, color: 'bg-blue-500' },
            { range: '81-100%', count: 12, color: 'bg-green-500' }
        ];

        const maxCount = Math.max(...data.map(d => d.count));
        
        container.innerHTML = `
            <div class="w-full space-y-2">
                ${data.map(item => `
                    <div class="flex items-center gap-2">
                        <div class="w-16 text-xs text-gray-600 dark-mode:text-gray-400">${item.range}</div>
                        <div class="flex-1 bg-gray-200 dark-mode:bg-gray-600 rounded-full h-4">
                            <div class="${item.color} h-4 rounded-full transition-all duration-500" style="width: ${(item.count / maxCount) * 100}%"></div>
                        </div>
                        <div class="w-8 text-xs text-gray-900 dark-mode:text-gray-100 text-right">${item.count}</div>
                    </div>
                `).join('')}
            </div>
        `;
    }

    generateFrequencyChart() {
        const container = document.getElementById('frequency-chart');
        if (!container) return;

        // Datos simulados
        const patterns = [
            { name: 'RSI Oversold', frequency: 15 },
            { name: 'Moving Average Cross', frequency: 12 },
            { name: 'Volume Spike', frequency: 8 },
            { name: 'Support Bounce', frequency: 6 },
            { name: 'Resistance Break', frequency: 4 }
        ];

        const maxFreq = Math.max(...patterns.map(p => p.frequency));
        
        container.innerHTML = `
            <div class="w-full space-y-3">
                ${patterns.map(pattern => `
                    <div class="flex items-center gap-3">
                        <div class="w-24 text-xs text-gray-600 dark-mode:text-gray-400 truncate">${pattern.name}</div>
                        <div class="flex-1 bg-gray-200 dark-mode:bg-gray-600 rounded-full h-3">
                            <div class="bg-indigo-600 h-3 rounded-full transition-all duration-500" style="width: ${(pattern.frequency / maxFreq) * 100}%"></div>
                        </div>
                        <div class="w-8 text-xs text-gray-900 dark-mode:text-gray-100 text-right">${pattern.frequency}</div>
                    </div>
                `).join('')}
            </div>
        `;
    }

    generateTrendsChart() {
        const container = document.getElementById('trends-chart');
        if (!container) return;

        // Datos simulados de tendencias
        const trends = [
            { month: 'Ene', patterns: 5, accuracy: 65 },
            { month: 'Feb', patterns: 8, accuracy: 72 },
            { month: 'Mar', patterns: 12, accuracy: 78 },
            { month: 'Abr', patterns: 15, accuracy: 82 },
            { month: 'May', patterns: 18, accuracy: 85 },
            { month: 'Jun', patterns: 22, accuracy: 88 }
        ];

        const maxPatterns = Math.max(...trends.map(t => t.patterns));
        const maxAccuracy = Math.max(...trends.map(t => t.accuracy));
        
        container.innerHTML = `
            <div class="w-full">
                <div class="flex justify-between items-end h-48 gap-2">
                    ${trends.map(trend => `
                        <div class="flex flex-col items-center gap-2 flex-1">
                            <div class="flex flex-col items-center gap-1">
                                <div class="w-full bg-indigo-200 dark-mode:bg-indigo-800 rounded-t" style="height: ${(trend.patterns / maxPatterns) * 120}px"></div>
                                <div class="w-full bg-green-200 dark-mode:bg-green-800 rounded-t" style="height: ${(trend.accuracy / maxAccuracy) * 120}px"></div>
                            </div>
                            <div class="text-xs text-gray-600 dark-mode:text-gray-400">${trend.month}</div>
                        </div>
                    `).join('')}
                </div>
                <div class="flex justify-center gap-6 mt-4">
                    <div class="flex items-center gap-2">
                        <div class="w-3 h-3 bg-indigo-500 rounded"></div>
                        <span class="text-xs text-gray-600 dark-mode:text-gray-400">Patrones</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="w-3 h-3 bg-green-500 rounded"></div>
                        <span class="text-xs text-gray-600 dark-mode:text-gray-400">Precisión (%)</span>
                    </div>
                </div>
            </div>
        `;
    }

    closePatternsChartModal() {
        const modal = document.getElementById('patterns-chart-modal');
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    }
}

// Inicializar dashboard cuando se carga la página
document.addEventListener('DOMContentLoaded', () => {
    window.AIBehavioralDashboard = new AIBehavioralDashboard();
    window.aiDashboard = window.AIBehavioralDashboard; // Alias para acceso global
    window.AIBehavioralDashboard.initialize();

    // Event listener para cerrar modal de knowledge
    const closeKnowledgeModal = document.getElementById('close-knowledge-modal');
    if (closeKnowledgeModal) {
        closeKnowledgeModal.addEventListener('click', () => {
            window.AIBehavioralDashboard.closeKnowledgeModal();
        });
    }

    // Event listener para cerrar modal de gráficos de patrones
    const closePatternsChartModal = document.getElementById('close-patterns-chart-modal');
    if (closePatternsChartModal) {
        closePatternsChartModal.addEventListener('click', () => {
            window.AIBehavioralDashboard.closePatternsChartModal();
        });
    }
});