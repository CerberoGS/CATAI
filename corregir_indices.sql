-- =====================================================
-- CORRECCIÓN DE ÍNDICES - IA COMPORTAMENTAL
-- =====================================================

-- Verificar estructura de la tabla ai_learning_events
DESCRIBE ai_learning_events;

-- Crear el índice correcto para ai_learning_events
-- (usando las columnas que realmente existen)
CREATE INDEX IF NOT EXISTS idx_learning_events_impact 
ON ai_learning_events(user_id, created_at DESC);

-- Verificar que todos los índices se crearon correctamente
SHOW INDEX FROM ai_learning_metrics;
SHOW INDEX FROM ai_behavioral_patterns;
SHOW INDEX FROM ai_analysis_history;
SHOW INDEX FROM ai_learning_events;
SHOW INDEX FROM ai_behavior_profiles;

-- Verificar estado final de todas las tablas
SELECT 
    'ai_learning_metrics' as tabla, 
    COUNT(*) as filas,
    'Métricas de aprendizaje' as descripcion
FROM ai_learning_metrics
UNION ALL
SELECT 
    'ai_behavioral_patterns' as tabla, 
    COUNT(*) as filas,
    'Patrones comportamentales' as descripcion
FROM ai_behavioral_patterns
UNION ALL
SELECT 
    'ai_analysis_history' as tabla, 
    COUNT(*) as filas,
    'Historial de análisis' as descripcion
FROM ai_analysis_history
UNION ALL
SELECT 
    'ai_learning_events' as tabla, 
    COUNT(*) as filas,
    'Eventos de aprendizaje' as descripcion
FROM ai_learning_events
UNION ALL
SELECT 
    'ai_behavior_profiles' as tabla, 
    COUNT(*) as filas,
    'Perfiles comportamentales' as descripcion
FROM ai_behavior_profiles;

-- Verificar claves foráneas
SELECT 
    TABLE_NAME,
    COLUMN_NAME,
    CONSTRAINT_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE 
WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
AND REFERENCED_TABLE_NAME = 'users'
AND TABLE_NAME LIKE 'ai_%'
ORDER BY TABLE_NAME, COLUMN_NAME;
