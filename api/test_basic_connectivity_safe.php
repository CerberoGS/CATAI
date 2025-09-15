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
        'message' => 'Prueba básica de conectividad completada',
        'database_connected' => true,
        'tables_basic_check' => []
    ];
    
    // Solo verificar 3 tablas básicas
    $basic_tables = ['knowledge_base', 'users', 'user_settings'];
    
    foreach ($basic_tables as $table) {
        try {
            // Consulta ultra simple
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            $exists = $stmt->fetch() !== false;
            
            if ($exists) {
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$table`");
                $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                
                $results['tables_basic_check'][$table] = [
                    'exists' => true,
                    'count' => (int)$count
                ];
            } else {
                $results['tables_basic_check'][$table] = [
                    'exists' => false,
                    'count' => 0
                ];
            }
            
        } catch (Exception $e) {
            $results['tables_basic_check'][$table] = [
                'exists' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    json_out($results);
    
} catch (Exception $e) {
    json_error('Error en prueba básica: ' . $e->getMessage());
}
?>
