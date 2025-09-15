<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

try {
    header_remove('X-Powered-By');
    apply_cors();
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

    // Autenticación
    $user = require_user();
    
    // Función helper robusta para operaciones DB con reconexión automática (copiada del archivo principal)
    function dbExecuteTest(&$pdo, $sql, $params = []) {
        $maxRetries = 3;
        $retryDelay = 100; // ms
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                // Verificar conexión antes de cada operación
                $pdo->query('SELECT 1');
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                
                // Log de éxito si fue después de una reconexión
                if ($attempt > 1) {
                    error_log("DB operación exitosa después de $attempt intentos");
                }
                
                return $stmt;
                
            } catch (PDOException $e) {
                // Si es "MySQL server has gone away" y no es el último intento
                if (strpos($e->getMessage(), 'MySQL server has gone away') !== false && $attempt < $maxRetries) {
                    // Log del intento de reconexión
                    error_log("DB reconexión intento $attempt: " . $e->getMessage());
                    
                    // Log adicional para debugging
                    error_log("DB reconexión intento $attempt para consulta: " . substr($sql, 0, 100) . "...");
                    
                    // Recrear conexión PDO y actualizar la referencia
                    global $CONFIG;
                    $dsn = "mysql:host={$CONFIG['DB_HOST']};dbname={$CONFIG['DB_NAME']};charset=utf8mb4";
                    $pdo = new PDO($dsn, $CONFIG['DB_USER'], $CONFIG['DB_PASS'], [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]);
                    
                    // Esperar antes del siguiente intento
                    usleep($retryDelay * 1000);
                    $retryDelay *= 2; // Backoff exponencial
                    
                    continue;
                }
                
                // Si no es "MySQL server has gone away" o es el último intento, relanzar
                throw $e;
            }
        }
        
        throw new Exception("No se pudo ejecutar la consulta después de $maxRetries intentos");
    }
    
    // Pruebas de estrés con múltiples consultas rápidas
    $results = [];
    $startTime = microtime(true);
    
    // Test 1: Múltiples consultas rápidas (simula "Ejecutar Todas las Pruebas")
    for ($i = 1; $i <= 5; $i++) {
        try {
            $stmt = dbExecuteTest($pdo, 'SELECT ai_provider, ai_model FROM user_settings WHERE user_id = ?', [$user['id']]);
            $results["test_rapido_$i"] = $stmt->fetch();
        } catch (Exception $e) {
            $results["test_rapido_$i_error"] = $e->getMessage();
        }
        
        // Pequeña pausa para simular el flujo real
        usleep(100000); // 100ms
    }
    
    // Test 2: Consulta a knowledge_files
    try {
        $stmt = dbExecuteTest($pdo, 'SELECT COUNT(*) as count FROM knowledge_files WHERE user_id = ?', [$user['id']]);
        $results['knowledge_files_count'] = $stmt->fetch()['count'];
    } catch (Exception $e) {
        $results['knowledge_files_error'] = $e->getMessage();
    }
    
    // Test 3: Consulta a knowledge_base
    try {
        $stmt = dbExecuteTest($pdo, 'SELECT COUNT(*) as count FROM knowledge_base WHERE created_by = ?', [$user['id']]);
        $results['knowledge_base_count'] = $stmt->fetch()['count'];
    } catch (Exception $e) {
        $results['knowledge_base_error'] = $e->getMessage();
    }
    
    $endTime = microtime(true);
    $totalTime = round(($endTime - $startTime) * 1000, 2); // ms
    
    // Información adicional
    $info = [
        'user_id' => $user['id'],
        'timestamp' => date('Y-m-d H:i:s'),
        'total_time_ms' => $totalTime,
        'tests_executed' => 7,
        'pdo_attributes' => [
            'ATTR_ERRMODE' => $pdo->getAttribute(PDO::ATTR_ERRMODE),
            'ATTR_DEFAULT_FETCH_MODE' => $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE),
            'ATTR_EMULATE_PREPARES' => $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),
        ]
    ];
    
    // Contar errores
    $errorCount = 0;
    foreach ($results as $key => $value) {
        if (strpos($key, '_error') !== false) {
            $errorCount++;
        }
    }
    
    json_ok([
        'ok' => true,
        'message' => 'Pruebas de estrés de conexión DB completadas',
        'info' => $info,
        'test_results' => $results,
        'error_count' => $errorCount,
        'success_rate' => round((7 - $errorCount) / 7 * 100, 2) . '%',
        'all_tests_passed' => $errorCount === 0
    ]);

} catch (Exception $e) {
    json_error('Error en prueba de estrés de conexión DB: ' . $e->getMessage());
}
?>
