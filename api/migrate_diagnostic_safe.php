<?php
declare(strict_types=1);

require_once 'common.php';

try {
    $user = require_user();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        json_error('Método no permitido');
    }
    
    $diagnostic = [
        'timestamp' => date('Y-m-d H:i:s'),
        'user_id' => $user['id'],
        'database_connection' => false,
        'tables_status' => [],
        'errors' => [],
        'recommendations' => []
    ];
    
    // Verificar conexión a la base de datos
    try {
        $stmt = $pdo->query("SELECT 1");
        $diagnostic['database_connection'] = true;
    } catch (Exception $e) {
        $diagnostic['errors'][] = "Error de conexión a la base de datos: " . $e->getMessage();
        json_out($diagnostic);
        return;
    }
    
    // Verificar tablas existentes
    $expected_tables = [
        'ai_learning_metrics',
        'ai_behavioral_patterns',
        'ai_analysis_history',
        'ai_learning_events',
        'ai_behavior_profiles'
    ];
    
    foreach ($expected_tables as $table_name) {
        $table_info = [
            'name' => $table_name,
            'exists' => false,
            'rows' => 0,
            'structure_ok' => false,
            'columns' => [],
            'indexes' => [],
            'errors' => []
        ];
        
        try {
            // Verificar si la tabla existe
            $stmt = $pdo->query("SHOW TABLES LIKE '$table_name'");
            $table_info['exists'] = $stmt->rowCount() > 0;
            
            if ($table_info['exists']) {
                // Contar filas
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table_name");
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $table_info['rows'] = intval($result['count']);
                
                // Verificar estructura
                $stmt = $pdo->query("DESCRIBE $table_name");
                $table_info['columns'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Verificar índices
                $stmt = $pdo->query("SHOW INDEX FROM $table_name");
                $table_info['indexes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Verificar columnas requeridas según la tabla
                $required_columns = [];
                switch ($table_name) {
                    case 'ai_learning_metrics':
                        $required_columns = ['id', 'user_id', 'total_analyses', 'success_rate', 'patterns_learned', 'accuracy_score'];
                        break;
                    case 'ai_behavioral_patterns':
                        $required_columns = ['id', 'user_id', 'name', 'description', 'pattern_type', 'confidence', 'frequency'];
                        break;
                    case 'ai_analysis_history':
                        $required_columns = ['id', 'user_id', 'symbol', 'analysis_type', 'timeframe', 'content', 'ai_provider'];
                        break;
                    case 'ai_learning_events':
                        $required_columns = ['id', 'user_id', 'event_type', 'event_data', 'confidence_impact'];
                        break;
                    case 'ai_behavior_profiles':
                        $required_columns = ['id', 'user_id', 'trading_style', 'risk_tolerance', 'time_preference', 'preferred_indicators'];
                        break;
                }
                
                $existing_columns = array_column($table_info['columns'], 'Field');
                $missing_columns = array_diff($required_columns, $existing_columns);
                
                if (empty($missing_columns)) {
                    $table_info['structure_ok'] = true;
                } else {
                    $table_info['errors'][] = "Columnas faltantes: " . implode(', ', $missing_columns);
                }
                
            } else {
                $table_info['errors'][] = "Tabla no existe";
            }
            
        } catch (Exception $e) {
            $table_info['errors'][] = "Error verificando tabla: " . $e->getMessage();
        }
        
        $diagnostic['tables_status'][] = $table_info;
    }
    
    // Verificar usuarios y datos
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
        $total_users = intval($stmt->fetch(PDO::FETCH_ASSOC)['count']);
        $diagnostic['total_users'] = $total_users;
        
        if ($total_users > 0) {
            $stmt = $pdo->query("SELECT COUNT(DISTINCT user_id) as count FROM ai_learning_metrics");
            $users_with_metrics = intval($stmt->fetch(PDO::FETCH_ASSOC)['count']);
            $diagnostic['users_with_metrics'] = $users_with_metrics;
            
            $stmt = $pdo->query("SELECT COUNT(DISTINCT user_id) as count FROM ai_behavior_profiles");
            $users_with_profiles = intval($stmt->fetch(PDO::FETCH_ASSOC)['count']);
            $diagnostic['users_with_profiles'] = $users_with_profiles;
        }
        
    } catch (Exception $e) {
        $diagnostic['errors'][] = "Error verificando usuarios: " . $e->getMessage();
    }
    
    // Generar recomendaciones
    $missing_tables = array_filter($diagnostic['tables_status'], function($table) {
        return !$table['exists'];
    });
    
    if (!empty($missing_tables)) {
        $diagnostic['recommendations'][] = "Ejecutar migración para crear tablas faltantes";
    }
    
    $tables_with_errors = array_filter($diagnostic['tables_status'], function($table) {
        return !empty($table['errors']);
    });
    
    if (!empty($tables_with_errors)) {
        $diagnostic['recommendations'][] = "Revisar estructura de tablas con errores";
    }
    
    if ($diagnostic['total_users'] > 0 && $diagnostic['users_with_metrics'] == 0) {
        $diagnostic['recommendations'][] = "Ejecutar inserción de datos iniciales para usuarios existentes";
    }
    
    // Determinar estado general
    $all_tables_exist = count($missing_tables) == 0;
    $all_structures_ok = count(array_filter($diagnostic['tables_status'], function($table) {
        return $table['exists'] && !$table['structure_ok'];
    })) == 0;
    
    $diagnostic['migration_status'] = [
        'all_tables_exist' => $all_tables_exist,
        'all_structures_ok' => $all_structures_ok,
        'data_initialized' => $diagnostic['users_with_metrics'] > 0,
        'ready_for_use' => $all_tables_exist && $all_structures_ok
    ];
    
    json_out($diagnostic);
    
} catch (Exception $e) {
    error_log("Error in migrate_diagnostic_safe.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage());
}
