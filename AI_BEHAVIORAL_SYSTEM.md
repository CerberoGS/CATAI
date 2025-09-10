# 🧠 Sistema de IA Comportamental - CATAI

## 📋 Resumen

El Sistema de IA Comportamental es una funcionalidad avanzada que aprende de los patrones de trading del usuario y genera análisis más precisos y personalizados. Combina datos técnicos tradicionales con inteligencia artificial adaptativa.

## 🎯 Características Principales

### **1. Dashboard Interactivo**
- **Métricas en tiempo real**: Análisis totales, tasa de éxito, patrones aprendidos, precisión IA
- **Perfil de comportamiento**: Estilo de trading, tolerancia al riesgo, preferencia temporal
- **Visualizaciones modernas**: Gráficos, barras de progreso, tarjetas con gradientes

### **2. Análisis Inteligente**
- **Prompts adaptativos**: Se ajustan al perfil y historial del usuario
- **Contexto personalizado**: Incluye patrones aprendidos y métricas históricas
- **Múltiples niveles**: Básico, Avanzado, Experto
- **Tipos de análisis**: Comprehensivo, Técnico, Comportamental, Opciones

### **3. Sistema de Aprendizaje**
- **Detección de patrones**: Identifica automáticamente preferencias del usuario
- **Métricas de precisión**: Calcula y actualiza la efectividad de la IA
- **Historial de análisis**: Tracking completo de todos los análisis realizados
- **Insights personalizados**: Recomendaciones basadas en el comportamiento

## 🏗️ Arquitectura

### **Frontend**
- **`ai.html`**: Página principal con dashboard moderno
- **`static/js/ai-behavioral.js`**: Sistema JavaScript modular
- **Integración**: Compatible con header.js y hero.js existentes

### **Backend**
- **`api/ai_learning_metrics_safe.php`**: Gestión de métricas de aprendizaje
- **`api/ai_behavioral_patterns_safe.php`**: Detección y gestión de patrones
- **`api/ai_analysis_history_safe.php`**: Historial de análisis
- **`api/ai_analysis_save_safe.php`**: Guardado de análisis inteligentes

### **Base de Datos**
- **`ai_learning_metrics`**: Métricas de aprendizaje del usuario
- **`ai_behavioral_patterns`**: Patrones comportamentales detectados
- **`ai_analysis_history`**: Historial completo de análisis
- **`ai_learning_events`**: Eventos de aprendizaje
- **`ai_behavior_profiles`**: Perfiles de comportamiento

## 🚀 Instalación

### **1. Ejecutar Migración de Base de Datos**
```bash
php migrate_ai_behavioral.php
```

### **2. Verificar Tablas Creadas**
```sql
SHOW TABLES LIKE 'ai_%';
```

### **3. Configurar Permisos**
Asegurar que el usuario de la base de datos tenga permisos para crear tablas.

## 📊 Funcionalidades Detalladas

### **Dashboard de Métricas**
```javascript
// Métricas mostradas en tiempo real
{
  total_analyses: 0,        // Análisis totales realizados
  success_rate: 0,          // Tasa de éxito (%)
  patterns_learned: 0,      // Patrones detectados
  accuracy_score: 0         // Precisión de la IA (%)
}
```

### **Perfil de Comportamiento**
```javascript
// Perfil adaptativo del usuario
{
  trading_style: 'balanced',     // conservative, balanced, aggressive, expert
  risk_tolerance: 'moderate',    // low, moderate, high, extreme
  time_preference: 'intraday',   // scalping, intraday, swing, position
  preferred_indicators: [...],   // Indicadores favoritos
  analysis_depth: 'advanced'     // basic, advanced, expert
}
```

### **Análisis Inteligente**
El sistema genera prompts personalizados que incluyen:
- Datos técnicos del símbolo
- Datos de opciones (si aplica)
- Perfil de comportamiento del usuario
- Patrones aprendidos históricamente
- Métricas de precisión
- Contexto personalizado

## 🔧 Configuración

### **Niveles de Análisis**
- **Básico**: Análisis simple y directo
- **Avanzado**: Análisis detallado con múltiples perspectivas
- **Experto**: Análisis sofisticado con estrategias avanzadas

### **Tipos de Análisis**
- **Comprehensivo**: Combina técnico + comportamental + opciones
- **Técnico**: Solo análisis técnico tradicional
- **Comportamental**: Enfoque en patrones del usuario
- **Opciones**: Especializado en estrategias de opciones

### **Personalización**
- **Usar datos comportamentales**: Incluir patrones en el análisis
- **Aprender de feedback**: Actualizar métricas con resultados
- **Prompts adaptativos**: Ajustar prompts al perfil del usuario

## 📈 Flujo de Trabajo

### **1. Análisis Inicial**
1. Usuario selecciona símbolo y configuración
2. Sistema obtiene datos técnicos y de opciones
3. Se genera prompt personalizado basado en perfil
4. IA ejecuta análisis con contexto personalizado
5. Resultados se muestran en dashboard moderno

### **2. Aprendizaje Continuo**
1. Análisis se guarda en historial
2. Sistema detecta patrones comportamentales
3. Métricas se actualizan automáticamente
4. Perfil se refina con nueva información
5. Próximos análisis son más precisos

### **3. Insights Personalizados**
1. Sistema analiza historial del usuario
2. Genera insights basados en comportamiento
3. Proporciona recomendaciones específicas
4. Sugiere mejoras en estrategia

## 🎨 UI/UX

### **Diseño Moderno**
- **Gradientes**: Tarjetas con gradientes coloridos
- **Animaciones**: Transiciones suaves y efectos hover
- **Responsive**: Adaptable a todos los dispositivos
- **Modo oscuro**: Soporte completo para tema oscuro

### **Componentes Principales**
- **Métricas Cards**: Visualización de KPIs principales
- **Perfil Bars**: Barras de progreso para características
- **Analysis Panel**: Panel de análisis interactivo
- **Patterns List**: Lista de patrones aprendidos
- **Insights Feed**: Feed de insights personalizados

## 🔍 Monitoreo y Debugging

### **Logs del Sistema**
```php
// Logs en api/logs/
- ai_behavioral.log: Eventos del sistema comportamental
- ai_learning.log: Eventos de aprendizaje
- ai_analysis.log: Análisis realizados
```

### **Métricas de Rendimiento**
- Tiempo de respuesta de análisis
- Precisión de predicciones
- Frecuencia de uso de patrones
- Satisfacción del usuario

## 🚨 Consideraciones de Seguridad

### **Datos Sensibles**
- Perfiles de usuario cifrados
- Historial de análisis protegido
- Patrones comportamentales anónimos
- Métricas agregadas sin identificación

### **Validaciones**
- Sanitización de inputs
- Validación de tipos de datos
- Límites en consultas
- Rate limiting en análisis

## 🔮 Roadmap Futuro

### **Próximas Funcionalidades**
- **Machine Learning**: Algoritmos ML más avanzados
- **Sentiment Analysis**: Análisis de sentimiento del mercado
- **Portfolio Optimization**: Optimización de cartera
- **Risk Management**: Gestión avanzada de riesgo
- **Social Trading**: Comparación con otros traders

### **Mejoras Técnicas**
- **Caching**: Sistema de caché para análisis
- **Real-time**: Actualizaciones en tiempo real
- **Mobile App**: Aplicación móvil nativa
- **API REST**: API completa para integraciones

## 📚 Documentación Adicional

- **API Reference**: Documentación completa de endpoints
- **Database Schema**: Esquema detallado de tablas
- **JavaScript API**: Documentación de funciones JS
- **Troubleshooting**: Guía de solución de problemas

---

**Desarrollado por**: Sistema CATAI  
**Versión**: 1.0.0  
**Última actualización**: 2025-01-27
