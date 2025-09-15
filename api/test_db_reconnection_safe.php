<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

try {
    header_remove('X-Powered-By');
    apply_cors();
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

    // Autenticación
    $user = require_user();
    
    // Función de prueba de reconexión
    function testDbReconnection($pdo) {
        $results = [];
        
        // Test 1: Consulta simple
        try {
            $stmt = $pdo->query('SELECT 1 as test');
            $results['test1_simple_query'] = $stmt->fetch()['test'];
        } catch (Exception $e) {
            $results['test1_error'] = $e->getMessage();
        }
        
        // Test 2: Consulta a user_settings
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) as count FROM user_settings WHERE user_id = ?');
            $stmt->execute([$user['id']]);
            $results['test2_user_settings'] = $stmt->fetch()['count'];
        } catch (Exception $e) {
            $results['test2_error'] = $e->getMessage();
        }
        
        // Test 3: Consulta a knowledge_files
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) as count FROM knowledge_files WHERE user_id = ?');
            $stmt->execute([$user['id']]);
            $results['test3_knowledge_files'] = $stmt->fetch()['count'];
        } catch (Exception $e) {
            $results['test3_error'] = $e->getMessage();
        }
        
        // Test 4: Consulta a knowledge_base
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) as count FROM knowledge_base WHERE created_by = ?');
            $stmt->execute([$user['id']]);
            $results['test4_knowledge_base'] = $stmt->fetch()['count'];
        } catch (Exception $e) {
            $results['test4_error'] = $e->getMessage();
        }
        
        return $results;
    }
    
    // Ejecutar pruebas
    $testResults = testDbReconnection($pdo);
    
    // Información adicional
    $info = [
        'user_id' => $user['id'],
        'timestamp' => date('Y-m-d H:i:s'),
        'pdo_attributes' => [
            'ATTR_ERRMODE' => $pdo->getAttribute(PDO::ATTR_ERRMODE),
            'ATTR_DEFAULT_FETCH_MODE' => $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE),
            'ATTR_EMULATE_PREPARES' => $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),
        ],
        'db_config' => [
            'host' => $CONFIG['DB_HOST'],
            'name' => $CONFIG['DB_NAME'],
            'user' => $CONFIG['DB_USER'],
            'charset' => 'utf8mb4'
        ]
    ];
    
    json_ok([
        'ok' => true,
        'message' => 'Pruebas de conexión DB completadas',
        'info' => $info,
        'test_results' => $testResults,
        'all_tests_passed' => !isset($testResults['test1_error']) && 
                             !isset($testResults['test2_error']) && 
                             !isset($testResults['test3_error']) && 
                             !isset($testResults['test4_error'])
    ]);

} catch (Exception $e) {
    json_error('Error en prueba de conexión DB: ' . $e->getMessage());
}
?>
