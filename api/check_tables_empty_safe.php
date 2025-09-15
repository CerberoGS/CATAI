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
        'message' => 'Verificación de tablas vacías completada',
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
            // Verificar si la tabla existe
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            $table_exists = $stmt->fetch() !== false;
            
            if ($table_exists) {
                // Contar total de registros
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$table`");
                $total_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                
                $results['tables_status'][$table] = [
                    'exists' => true,
                    'total_records' => (int)$total_count,
                    'status' => $total_count > 0 ? 'HAS_DATA' : 'EMPTY',
                    'is_normal' => $total_count == 0 ? 'YES' : 'NO'
                ];
            } else {
                $results['tables_status'][$table] = [
                    'exists' => false,
                    'total_records' => 0,
                    'status' => 'MISSING',
                    'is_normal' => 'NO'
                ];
            }
            
        } catch (Exception $e) {
            $results['tables_status'][$table] = [
                'exists' => false,
                'error' => $e->getMessage(),
                'status' => 'ERROR',
                'is_normal' => 'NO'
            ];
        }
    }
    
    // Calcular resumen
    $existing_tables = 0;
    $tables_with_data = 0;
    $empty_tables = 0;
    $missing_tables = 0;
    $normal_empty_tables = 0;
    
    foreach ($results['tables_status'] as $table => $data) {
        if ($data['exists']) {
            $existing_tables++;
            if ($data['status'] === 'HAS_DATA') {
                $tables_with_data++;
            } else {
                $empty_tables++;
                if ($data['is_normal'] === 'YES') {
                    $normal_empty_tables++;
                }
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
        'normal_empty_tables' => $normal_empty_tables,
        'diagnosis' => $normal_empty_tables > 0 ? 'NORMAL_EMPTY_TABLES' : 'ALL_GOOD'
    ];
    
    // Agregar recomendaciones
    $results['recommendations'] = [];
    if ($normal_empty_tables > 0) {
        $results['recommendations'][] = "Las tablas vacías son NORMALES para un usuario nuevo";
        $results['recommendations'][] = "Los endpoints de IA deben manejar tablas vacías correctamente";
        $results['recommendations'][] = "Necesitas interactuar con la app para generar datos";
    }
    if ($missing_tables > 0) {
        $results['recommendations'][] = "Algunas tablas no existen - necesitas ejecutar la migración de base de datos";
    }
    
    json_out($results);
    
} catch (Exception $e) {
    json_error('Error verificando tablas: ' . $e->getMessage());
}
?>
