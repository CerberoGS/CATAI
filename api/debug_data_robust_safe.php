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
        'tables' => []
    ];
    
    // Lista de tablas a verificar
    $tables_to_check = [
        'knowledge_base' => 'created_by',
        'knowledge_files' => 'user_id', 
        'analysis' => 'user_id',
        'ai_analysis_history' => 'user_id',
        'ai_learning_metrics' => 'user_id',
        'ai_behavioral_patterns' => 'user_id',
        'ai_behavior_profiles' => 'user_id'
    ];
    
    foreach ($tables_to_check as $table => $user_column) {
        try {
            // Verificar si la tabla existe
            $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            $table_exists = $stmt->fetch() !== false;
            
            if (!$table_exists) {
                $results['tables'][$table] = [
                    'exists' => false,
                    'count' => 0,
                    'error' => 'Tabla no existe'
                ];
                continue;
            }
            
            // Contar registros del usuario
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM `$table` WHERE `$user_column` = ?");
            $stmt->execute([$user_id]);
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            $results['tables'][$table] = [
                'exists' => true,
                'count' => (int)$count,
                'user_column' => $user_column
            ];
            
        } catch (Exception $e) {
            $results['tables'][$table] = [
                'exists' => false,
                'count' => 0,
                'error' => $e->getMessage()
            ];
        }
    }
    
    // Calcular resumen
    $total_records = 0;
    $existing_tables = 0;
    foreach ($results['tables'] as $table => $data) {
        if ($data['exists']) {
            $existing_tables++;
            $total_records += $data['count'];
        }
    }
    
    $results['summary'] = [
        'total_tables_checked' => count($tables_to_check),
        'existing_tables' => $existing_tables,
        'total_records' => $total_records,
        'has_data' => $total_records > 0
    ];
    
    json_out($results);
    
} catch (Exception $e) {
    json_error('Error en diagnóstico: ' . $e->getMessage());
}
?>
