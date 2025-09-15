<?php
declare(strict_types=1);

require_once 'helpers.php';
require_once 'db.php';

// Aplicar CORS
apply_cors();

// Verificar autenticación
$user = require_user();
$user_id = $user['user_id'] ?? $user['id'] ?? null;

if (!$user_id) {
    json_error('Usuario no autenticado', 401);
}

try {
    $pdo = get_pdo();
    
    $results = [
        'ok' => true,
        'user_id' => $user_id,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Verificación ultra simple completada',
        'tables_status' => []
    ];
    
    // Lista de tablas críticas para IA
    $critical_tables = [
        'knowledge_base',
        'knowledge_files', 
        'ai_analysis_history',
        'ai_learning_metrics',
        'ai_behavioral_patterns',
        'ai_behavior_profiles',
        'ai_learning_events'
    ];
    
    foreach ($critical_tables as $table) {
        try {
            // Verificar si la tabla existe usando una consulta ultra simple
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            $table_exists = $stmt->fetch() !== false;
            
            if ($table_exists) {
                // Contar total de registros usando consulta ultra simple
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$table`");
                $total_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                
                $results['tables_status'][$table] = [
                    'exists' => true,
                    'total_records' => (int)$total_count,
                    'status' => $total_count > 0 ? 'HAS_DATA' : 'EMPTY'
                ];
            } else {
                $results['tables_status'][$table] = [
                    'exists' => false,
                    'total_records' => 0,
                    'status' => 'MISSING'
                ];
            }
            
        } catch (Exception $e) {
            $results['tables_status'][$table] = [
                'exists' => false,
                'error' => $e->getMessage(),
                'status' => 'ERROR'
            ];
        }
    }
    
    // Calcular resumen
    $existing_tables = 0;
    $tables_with_data = 0;
    $empty_tables = 0;
    $missing_tables = 0;
    
    foreach ($results['tables_status'] as $table => $data) {
        if ($data['exists']) {
            $existing_tables++;
            if ($data['status'] === 'HAS_DATA') {
                $tables_with_data++;
            } else {
                $empty_tables++;
            }
        } else {
            $missing_tables++;
        }
    }
    
    $results['summary'] = [
        'total_tables_checked' => count($critical_tables),
        'existing_tables' => $existing_tables,
        'tables_with_data' => $tables_with_data,
        'empty_tables' => $empty_tables,
        'missing_tables' => $missing_tables,
        'diagnosis' => $empty_tables > 0 ? 'SOME_TABLES_EMPTY' : 'ALL_GOOD'
    ];
    
    // Agregar recomendaciones
    $results['recommendations'] = [];
    if ($empty_tables > 0) {
        $results['recommendations'][] = "Algunas tablas están vacías - esto causa errores 500 en los endpoints";
        $results['recommendations'][] = "Necesitas inicializar datos de ejemplo o usar el botón 'Inicializar Datos IA' en ai.html";
    }
    if ($missing_tables > 0) {
        $results['recommendations'][] = "Algunas tablas no existen - necesitas ejecutar la migración de base de datos";
    }
    
    json_out($results);
    
} catch (Exception $e) {
    json_error('Error verificando tablas: ' . $e->getMessage());
}
?>
