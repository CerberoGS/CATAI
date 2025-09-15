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
    
    // Lista de tablas que deberían existir
    $expected_tables = [
        'knowledge_base',
        'knowledge_files', 
        'analysis',
        'ai_analysis_history',
        'ai_learning_metrics',
        'ai_behavioral_patterns',
        'ai_behavior_profiles',
        'ai_learning_events',
        'knowledge_categories'
    ];
    
    foreach ($expected_tables as $table) {
        try {
            // Verificar si la tabla existe
            $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            $table_exists = $stmt->fetch() !== false;
            
            if ($table_exists) {
                // Contar total de registros
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM `$table`");
                $stmt->execute();
                $total_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                
                // Contar registros del usuario (si tiene columna user_id o created_by)
                $user_count = 0;
                try {
                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM `$table` WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    $user_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                } catch (Exception $e) {
                    try {
                        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM `$table` WHERE created_by = ?");
                        $stmt->execute([$user_id]);
                        $user_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                    } catch (Exception $e2) {
                        // Tabla no tiene columna de usuario
                        $user_count = 'N/A';
                    }
                }
                
                $results['tables'][$table] = [
                    'exists' => true,
                    'total_records' => (int)$total_count,
                    'user_records' => $user_count
                ];
            } else {
                $results['tables'][$table] = [
                    'exists' => false,
                    'total_records' => 0,
                    'user_records' => 0
                ];
            }
            
        } catch (Exception $e) {
            $results['tables'][$table] = [
                'exists' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    // Calcular resumen
    $existing_tables = 0;
    $missing_tables = 0;
    $total_user_data = 0;
    
    foreach ($results['tables'] as $table => $data) {
        if ($data['exists']) {
            $existing_tables++;
            if (is_numeric($data['user_records'])) {
                $total_user_data += $data['user_records'];
            }
        } else {
            $missing_tables++;
        }
    }
    
    $results['summary'] = [
        'expected_tables' => count($expected_tables),
        'existing_tables' => $existing_tables,
        'missing_tables' => $missing_tables,
        'total_user_data' => $total_user_data,
        'has_user_data' => $total_user_data > 0
    ];
    
    json_out($results);
    
} catch (Exception $e) {
    json_error('Error verificando tablas: ' . $e->getMessage());
}
?>
