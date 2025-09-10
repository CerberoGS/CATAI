-- =====================================================
-- VERIFICACIÓN DE MIGRACIÓN IA COMPORTAMENTAL
-- =====================================================

-- 1. Verificar que todas las tablas existen
SHOW TABLES LIKE 'ai_%';

-- 2. Contar filas en cada tabla
SELECT 'ai_learning_metrics' as tabla, COUNT(*) as filas FROM ai_learning_metrics
UNION ALL
SELECT 'ai_behavioral_patterns' as tabla, COUNT(*) as filas FROM ai_behavioral_patterns
UNION ALL
SELECT 'ai_analysis_history' as tabla, COUNT(*) as filas FROM ai_analysis_history
UNION ALL
SELECT 'ai_learning_events' as tabla, COUNT(*) as filas FROM ai_learning_events
UNION ALL
SELECT 'ai_behavior_profiles' as tabla, COUNT(*) as filas FROM ai_behavior_profiles;

-- 3. Verificar usuarios con datos iniciales
SELECT 
    'Usuarios con métricas' as tipo,
    COUNT(DISTINCT user_id) as cantidad
FROM ai_learning_metrics
UNION ALL
SELECT 
    'Usuarios con perfiles' as tipo,
    COUNT(DISTINCT user_id) as cantidad
FROM ai_behavior_profiles;

-- 4. Verificar estructura de las tablas
DESCRIBE ai_learning_metrics;
DESCRIBE ai_behavior_profiles;

-- 5. Verificar claves foráneas
SELECT 
    TABLE_NAME,
    COLUMN_NAME,
    CONSTRAINT_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE 
WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
AND REFERENCED_TABLE_NAME = 'users'
AND TABLE_NAME LIKE 'ai_%';

-- 6. Verificar índices
SHOW INDEX FROM ai_learning_metrics;
SHOW INDEX FROM ai_behavior_profiles;

-- 7. Mostrar algunos datos de ejemplo
SELECT * FROM ai_learning_metrics LIMIT 5;
SELECT * FROM ai_behavior_profiles LIMIT 5;
