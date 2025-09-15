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
        'message' => 'Prueba de conexión a base de datos completada',
        'database_connected' => true,
        'db_info' => []
    ];
    
    // Verificar conexión básica
    $stmt = $pdo->query("SELECT 1 as test");
    $test_result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($test_result && $test_result['test'] == 1) {
        $results['db_info']['basic_query'] = 'OK';
    } else {
        $results['db_info']['basic_query'] = 'FAILED';
    }
    
    // Verificar una tabla que sabemos que existe
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
        $users_table = $stmt->fetch() !== false;
        $results['db_info']['users_table'] = $users_table ? 'EXISTS' : 'NOT_FOUND';
    } catch (Exception $e) {
        $results['db_info']['users_table'] = 'ERROR: ' . $e->getMessage();
    }
    
    // Verificar una tabla de IA que sabemos que existe pero está vacía
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'ai_learning_metrics'");
        $ai_table = $stmt->fetch() !== false;
        if ($ai_table) {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM ai_learning_metrics");
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            $results['db_info']['ai_learning_metrics'] = "EXISTS - {$count} rows";
        } else {
            $results['db_info']['ai_learning_metrics'] = 'NOT_FOUND';
        }
    } catch (Exception $e) {
        $results['db_info']['ai_learning_metrics'] = 'ERROR: ' . $e->getMessage();
    }
    
    json_out($results);
    
} catch (Exception $e) {
    json_error('Error en conexión a base de datos: ' . $e->getMessage());
}
?>
