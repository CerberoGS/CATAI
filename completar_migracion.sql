-- =====================================================
-- COMPLETAR MIGRACIÓN IA COMPORTAMENTAL
-- =====================================================
-- Este script completa los datos faltantes sin duplicar

-- 1. Insertar métricas faltantes (solo para usuarios que no tienen)
INSERT IGNORE INTO ai_learning_metrics (user_id, total_analyses, success_rate, patterns_learned, accuracy_score)
SELECT 
    u.id as user_id,
    0 as total_analyses,
    0.00 as success_rate,
    0 as patterns_learned,
    0.00 as accuracy_score
FROM users u
LEFT JOIN ai_learning_metrics alm ON u.id = alm.user_id
WHERE alm.user_id IS NULL;

-- 2. Insertar perfiles faltantes (solo para usuarios que no tienen)
INSERT IGNORE INTO ai_behavior_profiles (
    user_id, 
    trading_style, 
    risk_tolerance, 
    time_preference, 
    preferred_indicators, 
    analysis_depth
)
SELECT 
    u.id as user_id,
    'balanced' as trading_style,
    'moderate' as risk_tolerance,
    'intraday' as time_preference,
    '["rsi14", "ema20", "sma20"]' as preferred_indicators,
    'advanced' as analysis_depth
FROM users u
LEFT JOIN ai_behavior_profiles abp ON u.id = abp.user_id
WHERE abp.user_id IS NULL;

-- 3. Verificar que todos los usuarios tienen datos
SELECT 
    'Total usuarios' as tipo,
    COUNT(*) as cantidad
FROM users
UNION ALL
SELECT 
    'Usuarios con métricas' as tipo,
    COUNT(DISTINCT user_id) as cantidad
FROM ai_learning_metrics
UNION ALL
SELECT 
    'Usuarios con perfiles' as tipo,
    COUNT(DISTINCT user_id) as cantidad
FROM ai_behavior_profiles;

-- 4. Mostrar usuarios que podrían estar faltando
SELECT 
    u.id,
    u.email,
    u.name,
    CASE WHEN alm.user_id IS NULL THEN 'Sin métricas' ELSE 'Con métricas' END as estado_metricas,
    CASE WHEN abp.user_id IS NULL THEN 'Sin perfil' ELSE 'Con perfil' END as estado_perfil
FROM users u
LEFT JOIN ai_learning_metrics alm ON u.id = alm.user_id
LEFT JOIN ai_behavior_profiles abp ON u.id = abp.user_id
ORDER BY u.id;
