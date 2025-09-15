/* ================== Integración IA Comportamental ================== */

class AIBehavioralIntegration {
    constructor() {
        this.apiBase = ConfigPortable.API_BASE_URL;
        this.isInitialized = false;
    }

    async initialize() {
        if (this.isInitialized) return;
        
        try {
            // Verificar que el usuario esté autenticado
            const token = this.getToken();
            if (!token) {
                console.warn('Usuario no autenticado para IA comportamental');
                return;
            }

            // Cargar perfil comportamental del usuario
            await this.loadBehavioralProfile();
            
            this.isInitialized = true;
            console.log('IA Comportamental integrada correctamente');
        } catch (error) {
            console.error('Error inicializando IA comportamental:', error);
        }
    }

    getToken() {
        return localStorage.getItem('auth_token');
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
                this.behavioralProfile = data;
                return data;
            }
        } catch (error) {
            console.error('Error cargando perfil comportamental:', error);
        }
        return null;
    }

    async getLearningMetrics() {
        try {
            const response = await fetch(`${this.apiBase}/ai_learning_metrics_safe.php`, {
                headers: {
                    'Authorization': `Bearer ${this.getToken()}`
                }
            });
            
            if (response.ok) {
                const data = await response.json();
                return data;
            }
        } catch (error) {
            console.error('Error obteniendo métricas de aprendizaje:', error);
        }
        return null;
    }

    async saveAnalysisWithBehavioralContext(analysisData) {
        try {
            // Obtener contexto comportamental actual
            const behavioralContext = await this.getBehavioralContext();
            
            // Enriquecer el análisis con contexto comportamental
            const enrichedAnalysis = {
                ...analysisData,
                behavioral_context: behavioralContext,
                ai_provider: 'behavioral_ai',
                analysis_type: 'comprehensive',
                confidence_score: this.calculateConfidenceScore(analysisData)
            };

            // Guardar en el historial de análisis de IA
            const response = await fetch(`${this.apiBase}/ai_analysis_save_safe.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${this.getToken()}`
                },
                body: JSON.stringify(enrichedAnalysis)
            });

            if (response.ok) {
                const result = await response.json();
                console.log('Análisis guardado con contexto comportamental:', result);
                return result;
            }
        } catch (error) {
            console.error('Error guardando análisis con contexto comportamental:', error);
        }
        return null;
    }

    async getBehavioralContext() {
        try {
            // Obtener métricas de aprendizaje
            const metrics = await this.getLearningMetrics();
            
            // Obtener patrones comportamentales
            const patterns = await this.loadBehavioralProfile();
            
            // Obtener historial reciente
            const recentHistory = await this.getRecentAnalysisHistory(5);
            
            return {
                learning_metrics: metrics,
                behavioral_patterns: patterns,
                recent_history: recentHistory,
                timestamp: new Date().toISOString()
            };
        } catch (error) {
            console.error('Error obteniendo contexto comportamental:', error);
            return null;
        }
    }

    async getRecentAnalysisHistory(limit = 5) {
        try {
            const response = await fetch(`${this.apiBase}/ai_analysis_history_safe.php?limit=${limit}`, {
                headers: {
                    'Authorization': `Bearer ${this.getToken()}`
                }
            });
            
            if (response.ok) {
                const data = await response.json();
                return data.analyses || [];
            }
        } catch (error) {
            console.error('Error obteniendo historial de análisis:', error);
        }
        return [];
    }

    calculateConfidenceScore(analysisData) {
        // Calcular score de confianza basado en:
        // - Métricas de aprendizaje del usuario
        // - Patrones comportamentales identificados
        // - Calidad del análisis actual
        
        let score = 0.5; // Base score
        
        // Ajustar basado en métricas de aprendizaje
        if (this.behavioralProfile?.success_rate) {
            score += (this.behavioralProfile.success_rate / 100) * 0.3;
        }
        
        // Ajustar basado en patrones aprendidos
        if (this.behavioralProfile?.patterns_learned) {
            score += Math.min(this.behavioralProfile.patterns_learned / 10, 0.2);
        }
        
        // Ajustar basado en calidad del análisis
        if (analysisData.symbol && analysisData.analysis_text) {
            score += 0.1; // Bonus por tener símbolo y análisis
        }
        
        return Math.min(Math.max(score, 0.1), 1.0); // Entre 0.1 y 1.0
    }

    async updateLearningMetrics(symbol, outcome, traded = false) {
        try {
            const eventData = {
                symbol,
                outcome,
                traded,
                timestamp: new Date().toISOString(),
                analysis_quality: this.calculateConfidenceScore({ symbol, analysis_text: '' })
            };

            // Crear evento de aprendizaje
            const response = await fetch(`${this.apiBase}/ai_learning_events_safe.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${this.getToken()}`
                },
                body: JSON.stringify({
                    event_type: 'analysis_outcome',
                    event_data: eventData,
                    confidence_impact: outcome === 'positive' ? 0.1 : (outcome === 'negative' ? -0.1 : 0)
                })
            });

            if (response.ok) {
                console.log('Métricas de aprendizaje actualizadas');
                return true;
            }
        } catch (error) {
            console.error('Error actualizando métricas de aprendizaje:', error);
        }
        return false;
    }

    generateBehavioralPrompt(symbol, technicalData, aiProvider = 'auto') {
        // Generar prompt enriquecido con contexto comportamental
        const context = this.behavioralProfile || {};
        const metrics = this.behavioralProfile?.learning_metrics || {};
        
        let prompt = `Analiza el activo ${symbol} con contexto comportamental personalizado:\n\n`;
        
        // Datos técnicos
        prompt += `DATOS TÉCNICOS:\n${technicalData}\n\n`;
        
        // Contexto comportamental
        if (metrics.total_analyses > 0) {
            prompt += `CONTEXTO COMPORTAMENTAL:\n`;
            prompt += `- Análisis previos: ${metrics.total_analyses}\n`;
            prompt += `- Tasa de éxito: ${metrics.success_rate}%\n`;
            prompt += `- Patrones aprendidos: ${metrics.patterns_learned}\n`;
            prompt += `- Precisión IA: ${metrics.accuracy_score}%\n\n`;
        }
        
        // Perfil de trading
        if (context.trading_style) {
            prompt += `PERFIL DE TRADING:\n`;
            prompt += `- Estilo: ${context.trading_style}\n`;
            prompt += `- Tolerancia al riesgo: ${context.risk_tolerance}\n`;
            prompt += `- Preferencia temporal: ${context.time_preference}\n\n`;
        }
        
        // Instrucciones específicas
        prompt += `INSTRUCCIONES:\n`;
        prompt += `- Adapta el análisis al perfil comportamental del usuario\n`;
        prompt += `- Considera el historial de éxito/fallo previo\n`;
        prompt += `- Proporciona recomendaciones personalizadas\n`;
        prompt += `- Incluye gestión de riesgo específica para este perfil\n`;
        
        return prompt;
    }

    async enhanceAnalysisWithBehavioralAI(symbol, technicalData, aiProvider = 'auto') {
        try {
            // Generar prompt enriquecido
            const behavioralPrompt = this.generateBehavioralPrompt(symbol, technicalData, aiProvider);
            
            // Llamar a la IA con el prompt enriquecido
            const response = await fetch(`${this.apiBase}/ai_analyze.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${this.getToken()}`
                },
                body: JSON.stringify({
                    provider: aiProvider,
                    prompt: behavioralPrompt,
                    systemPrompt: "Eres un analista de opciones intradía con IA comportamental. Adapta tu análisis al perfil específico del usuario, considerando su historial de trading y patrones de comportamiento. Proporciona recomendaciones personalizadas y gestión de riesgo específica."
                })
            });

            if (response.ok) {
                const data = await response.json();
                return {
                    ...data,
                    behavioral_enhanced: true,
                    confidence_score: this.calculateConfidenceScore({ symbol, analysis_text: data.text })
                };
            }
        } catch (error) {
            console.error('Error en análisis comportamental:', error);
        }
        return null;
    }
}

// Instancia global
window.AIBehavioral = new AIBehavioralIntegration();

// Inicializar cuando se carga la página
document.addEventListener('DOMContentLoaded', () => {
    window.AIBehavioral.initialize();
});

// Exportar para uso en otros módulos
window.AIBehavioralIntegration = AIBehavioralIntegration;
