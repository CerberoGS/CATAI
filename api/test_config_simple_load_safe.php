<?php
// Test para verificar que config simple se puede cargar
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    // Intentar cargar config simple
    $config = require __DIR__ . '/config_simple_safe.php';
    
    echo json_encode([
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Config simple cargado exitosamente',
        'config_keys' => array_keys($config),
        'base_url' => $config['BASE_URL'],
        'api_base_url' => $config['API_BASE_URL'],
        'db_host' => $config['DB_HOST'],
        'db_name' => $config['DB_NAME'],
        'jwt_secret_set' => !empty($config['JWT_SECRET'])
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Error cargando config simple',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
}
