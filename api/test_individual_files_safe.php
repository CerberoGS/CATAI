<?php
// Test individual de cada archivo
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    $results = [];
    
    // Test 1: Solo config.php
    $results['config'] = ['loaded' => false, 'error' => null];
    try {
        $config = require __DIR__ . '/config.php';
        $results['config']['loaded'] = true;
        $results['config']['type'] = gettype($config);
        $results['config']['keys'] = is_array($config) ? array_keys($config) : [];
    } catch (Throwable $e) {
        $results['config']['error'] = $e->getMessage();
    }
    
    // Test 2: Solo helpers.php
    $results['helpers'] = ['loaded' => false, 'error' => null];
    try {
        require_once __DIR__ . '/helpers.php';
        $results['helpers']['loaded'] = true;
        $results['helpers']['functions'] = [
            'json_out' => function_exists('json_out'),
            'require_user' => function_exists('require_user'),
            'read_json_body' => function_exists('read_json_body')
        ];
    } catch (Throwable $e) {
        $results['helpers']['error'] = $e->getMessage();
    }
    
    // Test 3: Solo db.php
    $results['db'] = ['loaded' => false, 'error' => null];
    try {
        require_once __DIR__ . '/db.php';
        $results['db']['loaded'] = true;
        $results['db']['functions'] = [
            'db' => function_exists('db')
        ];
    } catch (Throwable $e) {
        $results['db']['error'] = $e->getMessage();
    }
    
    // Test 4: Solo jwt.php
    $results['jwt'] = ['loaded' => false, 'error' => null];
    try {
        require_once __DIR__ . '/jwt.php';
        $results['jwt']['loaded'] = true;
        $results['jwt']['functions'] = [
            'jwt_sign' => function_exists('jwt_sign'),
            'jwt_verify' => function_exists('jwt_verify'),
            'jwt_decode_user' => function_exists('jwt_decode_user')
        ];
    } catch (Throwable $e) {
        $results['jwt']['error'] = $e->getMessage();
    }
    
    echo json_encode([
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Test individual de archivos completado',
        'results' => $results,
        'summary' => [
            'config_loaded' => $results['config']['loaded'],
            'helpers_loaded' => $results['helpers']['loaded'],
            'db_loaded' => $results['db']['loaded'],
            'jwt_loaded' => $results['jwt']['loaded'],
            'all_loaded' => $results['config']['loaded'] && $results['helpers']['loaded'] && $results['db']['loaded'] && $results['jwt']['loaded']
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Error en test individual de archivos',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
}
