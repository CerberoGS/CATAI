<?php
declare(strict_types=1);

require_once 'common.php';

try {
    $user = require_user();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Método no permitido');
    }
    
    $users_processed = 0;
    $errors = [];
    
    // Obtener todos los usuarios existentes
    $stmt = $pdo->query("SELECT id FROM users");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($users as $user_data) {
        $user_id = $user_data['id'];
        
        try {
            // Insertar métricas de aprendizaje por defecto
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO ai_learning_metrics 
                (user_id, total_analyses, success_rate, patterns_learned, accuracy_score)
                VALUES (?, 0, 0, 0, 0)
            ");
            $stmt->execute([$user_id]);
            
            // Insertar perfil de comportamiento por defecto
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO ai_behavior_profiles 
                (user_id, trading_style, risk_tolerance, time_preference, preferred_indicators, analysis_depth)
                VALUES (?, 'balanced', 'moderate', 'intraday', ?, 'advanced')
            ");
            $default_indicators = json_encode(['rsi14', 'ema20', 'sma20']);
            $stmt->execute([$user_id, $default_indicators]);
            
            $users_processed++;
            
        } catch (Exception $e) {
            $errors[] = "Usuario $user_id: " . $e->getMessage();
        }
    }
    
    // Crear índices adicionales para optimización
    try {
        $index_queries = [
            "CREATE INDEX IF NOT EXISTS idx_learning_metrics_success ON ai_learning_metrics(user_id, success_rate DESC)",
            "CREATE INDEX IF NOT EXISTS idx_behavioral_patterns_frequency ON ai_behavioral_patterns(user_id, frequency DESC)",
            "CREATE INDEX IF NOT EXISTS idx_analysis_history_outcome ON ai_analysis_history(user_id, success_outcome, created_at DESC)",
            "CREATE INDEX IF NOT EXISTS idx_learning_events_impact ON ai_learning_events(user_id, confidence_impact DESC, created_at DESC)"
        ];
        
        foreach ($index_queries as $query) {
            try {
                $pdo->exec($query);
            } catch (Exception $e) {
                // Los índices pueden fallar si ya existen, no es crítico
                error_log("Index creation warning: " . $e->getMessage());
            }
        }
        
    } catch (Exception $e) {
        $errors[] = "Error creando índices: " . $e->getMessage();
    }
    
    json_out([
        'ok' => true,
        'message' => 'Datos iniciales insertados correctamente',
        'users_processed' => $users_processed,
        'total_users' => count($users),
        'errors' => $errors
    ]);
    
} catch (Exception $e) {
    error_log("Error in migrate_init_data_safe.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage());
}
