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
    $pdo = db();
    
    // Lista de tablas a verificar
    $tablesToCheck = [
        'knowledge_base',
        'knowledge_files', 
        'ai_behavioral_patterns',
        'ai_learning_metrics',
        'ai_analysis_history',
        'analysis', // Esta puede no existir
        'users'
    ];
    
    $tableStatus = [];
    
    foreach ($tablesToCheck as $table) {
        try {
            // Usar consulta directa para evitar problemas de sintaxis con parámetros preparados
            $sql = "SHOW TABLES LIKE '" . $table . "'";
            $stmt = $pdo->query($sql);
            $exists = $stmt->rowCount() > 0;
            
            if ($exists) {
                // Contar registros para el usuario actual
                $count = 0;
                try {
                    if ($table === 'knowledge_base') {
                        $sql = "SELECT COUNT(*) as total FROM {$table} WHERE created_by = " . intval($user_id);
                    } elseif (in_array($table, ['knowledge_files', 'ai_behavioral_patterns', 'ai_learning_metrics', 'ai_analysis_history', 'analysis'])) {
                        $sql = "SELECT COUNT(*) as total FROM {$table} WHERE user_id = " . intval($user_id);
                    } else {
                        $sql = "SELECT COUNT(*) as total FROM {$table}";
                    }
                    
                    $stmt = $pdo->query($sql);
                    $count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
                } catch (Exception $e) {
                    $count = 0;
                }
                
                $tableStatus[$table] = [
                    'exists' => true,
                    'records' => $count,
                    'status' => 'OK'
                ];
            } else {
                $tableStatus[$table] = [
                    'exists' => false,
                    'records' => 0,
                    'status' => 'NO_EXISTS'
                ];
            }
        } catch (Exception $e) {
            $tableStatus[$table] = [
                'exists' => false,
                'records' => 0,
                'status' => 'ERROR: ' . $e->getMessage()
            ];
        }
    }
    
    // Calcular estado del sistema híbrido
    $hybridReady = $tableStatus['knowledge_base']['exists'] && 
                   $tableStatus['knowledge_base']['records'] > 0;
    
    $result = [
        'ok' => true,
        'user_id' => $user_id,
        'database_status' => $tableStatus,
        'hybrid_system_ready' => $hybridReady,
        'summary' => [
            'total_tables' => count($tablesToCheck),
            'existing_tables' => count(array_filter($tableStatus, fn($t) => $t['exists'])),
            'missing_tables' => count(array_filter($tableStatus, fn($t) => !$t['exists'])),
            'user_data_available' => $tableStatus['knowledge_base']['records'] > 0
        ],
        'recommendations' => [
            'hybrid_analysis' => $hybridReady ? 'Sistema híbrido completamente funcional' : 'Sistema híbrido parcialmente funcional',
            'missing_tables' => array_keys(array_filter($tableStatus, fn($t) => !$t['exists'])),
            'next_steps' => $hybridReady ? 'Sistema listo para análisis enriquecido' : 'Subir archivos de conocimiento para activar sistema híbrido'
        ]
    ];
    
    json_out($result);
    
} catch (Exception $e) {
    error_log("Error en check_database_tables.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}
?>
