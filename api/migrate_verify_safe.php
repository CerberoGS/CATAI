<?php
declare(strict_types=1);

require_once 'common.php';

try {
    $user = require_user();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        json_error('Método no permitido');
    }
    
    $expected_tables = [
        'ai_learning_metrics',
        'ai_behavioral_patterns',
        'ai_analysis_history',
        'ai_learning_events',
        'ai_behavior_profiles'
    ];
    
    $tables_info = [];
    $all_exist = true;
    
    foreach ($expected_tables as $table_name) {
        try {
            // Verificar si la tabla existe
            $stmt = $pdo->query("SHOW TABLES LIKE '$table_name'");
            $exists = $stmt->rowCount() > 0;
            
            $rows = 0;
            if ($exists) {
                // Contar filas
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table_name");
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $rows = intval($result['count']);
            }
            
            $tables_info[] = [
                'name' => $table_name,
                'exists' => $exists,
                'rows' => $rows
            ];
            
            if (!$exists) {
                $all_exist = false;
            }
            
        } catch (Exception $e) {
            $tables_info[] = [
                'name' => $table_name,
                'exists' => false,
                'rows' => 0,
                'error' => $e->getMessage()
            ];
            $all_exist = false;
        }
    }
    
    // Verificar estructura de la tabla principal
    $structure_ok = true;
    if ($all_exist) {
        try {
            // Verificar que ai_learning_metrics tenga las columnas correctas
            $stmt = $pdo->query("DESCRIBE ai_learning_metrics");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $required_columns = ['id', 'user_id', 'total_analyses', 'success_rate', 'patterns_learned', 'accuracy_score'];
            
            foreach ($required_columns as $col) {
                if (!in_array($col, $columns)) {
                    $structure_ok = false;
                    break;
                }
            }
        } catch (Exception $e) {
            $structure_ok = false;
        }
    }
    
    // Verificar datos de usuarios
    $users_with_metrics = 0;
    $users_with_profiles = 0;
    
    if ($all_exist) {
        try {
            $stmt = $pdo->query("SELECT COUNT(DISTINCT user_id) as count FROM ai_learning_metrics");
            $users_with_metrics = intval($stmt->fetch(PDO::FETCH_ASSOC)['count']);
            
            $stmt = $pdo->query("SELECT COUNT(DISTINCT user_id) as count FROM ai_behavior_profiles");
            $users_with_profiles = intval($stmt->fetch(PDO::FETCH_ASSOC)['count']);
        } catch (Exception $e) {
            // Error no crítico
        }
    }
    
    json_out([
        'ok' => $all_exist && $structure_ok,
        'message' => $all_exist ? 'Todas las tablas existen' : 'Algunas tablas faltan',
        'tables' => $tables_info,
        'structure_ok' => $structure_ok,
        'users_with_metrics' => $users_with_metrics,
        'users_with_profiles' => $users_with_profiles,
        'migration_complete' => $all_exist && $structure_ok
    ]);
    
} catch (Exception $e) {
    error_log("Error in migrate_verify_safe.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage());
}
