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
    
    $diagnostic = [
        'timestamp' => date('Y-m-d H:i:s'),
        'user_id' => $user['id'],
        'server_info' => [],
        'connection_tests' => [],
        'table_tests' => []
    ];
    
    // 1) Información del servidor
    $diagnostic['server_info'] = [
        'php_version' => PHP_VERSION,
        'pdo_mysql_version' => phpversion('pdo_mysql'),
        'mysql_version' => phpversion('mysql'),
        'mysqli_version' => phpversion('mysqli'),
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'
    ];
    
    // 2) Test de conexión básica
    try {
        require_once __DIR__ . '/db.php';
        $pdo = db();
        
        $diagnostic['connection_tests']['basic_connection'] = [
            'status' => 'success',
            'dsn' => 'masked_for_security',
            'driver' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
            'server_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
            'connection_status' => $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS)
        ];
        
        // 3) Test de consulta simple
        $stmt = $pdo->query('SELECT 1 as test_value, NOW() as current_time');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $diagnostic['connection_tests']['simple_query'] = [
            'status' => 'success',
            'result' => $result
        ];
        
        // 4) Test de consulta con parámetros
        $stmt = $pdo->prepare('SELECT ? as param_test');
        $stmt->execute(['test_param']);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $diagnostic['connection_tests']['parameterized_query'] = [
            'status' => 'success',
            'result' => $result
        ];
        
        // 5) Test de tablas específicas
        $tables = ['user_settings', 'knowledge_files', 'knowledge_base'];
        foreach ($tables as $table) {
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM $table WHERE user_id = ?");
                $stmt->execute([$user['id']]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $diagnostic['table_tests'][$table] = [
                    'status' => 'success',
                    'count' => $result['count']
                ];
            } catch (PDOException $e) {
                $diagnostic['table_tests'][$table] = [
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        // 6) Test de estructura de knowledge_files
        try {
            $stmt = $pdo->query("DESCRIBE knowledge_files");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $diagnostic['table_tests']['knowledge_files_structure'] = [
                'status' => 'success',
                'columns' => array_column($columns, 'Field')
            ];
        } catch (PDOException $e) {
            $diagnostic['table_tests']['knowledge_files_structure'] = [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
        
        // 7) Test de timeout y reconexión
        $startTime = microtime(true);
        $stmt = $pdo->query('SELECT SLEEP(1)');
        $endTime = microtime(true);
        
        $diagnostic['connection_tests']['timeout_test'] = [
            'status' => 'success',
            'sleep_duration' => round($endTime - $startTime, 2)
        ];
        
    } catch (PDOException $e) {
        $diagnostic['connection_tests']['basic_connection'] = [
            'status' => 'error',
            'error' => $e->getMessage(),
            'error_code' => $e->getCode()
        ];
    }
    
    json_out($diagnostic);
    
} catch (Throwable $e) {
    json_error('Error en diagnóstico: ' . $e->getMessage(), 500);
}
