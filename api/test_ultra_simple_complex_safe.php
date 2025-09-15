<?php
// Test ultra simple para endpoints complejos
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    // Test 1: Solo config.php
    $configLoaded = false;
    $configError = null;
    
    try {
        $config = require __DIR__ . '/config.php';
        $configLoaded = true;
    } catch (Throwable $e) {
        $configError = $e->getMessage();
    }
    
    // Test 2: Solo helpers.php
    $helpersLoaded = false;
    $helpersError = null;
    
    try {
        require_once __DIR__ . '/helpers.php';
        $helpersLoaded = true;
    } catch (Throwable $e) {
        $helpersError = $e->getMessage();
    }
    
    // Test 3: Solo db.php
    $dbLoaded = false;
    $dbError = null;
    
    try {
        require_once __DIR__ . '/db.php';
        $dbLoaded = true;
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
    
    // Test 4: Solo jwt.php
    $jwtLoaded = false;
    $jwtError = null;
    
    try {
        require_once __DIR__ . '/jwt.php';
        $jwtLoaded = true;
    } catch (Throwable $e) {
        $jwtError = $e->getMessage();
    }
    
    // Test 5: Verificar funciones
    $functionsExist = [
        'json_out' => function_exists('json_out'),
        'require_user' => function_exists('require_user'),
        'db' => function_exists('db')
    ];
    
    echo json_encode([
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Test ultra simple completado',
        'file_loading' => [
            'config_loaded' => $configLoaded,
            'config_error' => $configError,
            'helpers_loaded' => $helpersLoaded,
            'helpers_error' => $helpersError,
            'db_loaded' => $dbLoaded,
            'db_error' => $dbError,
            'jwt_loaded' => $jwtLoaded,
            'jwt_error' => $jwtError
        ],
        'functions_exist' => $functionsExist,
        'diagnosis' => [
            'all_files_loaded' => $configLoaded && $helpersLoaded && $dbLoaded && $jwtLoaded,
            'all_functions_exist' => array_reduce($functionsExist, function($carry, $item) { return $carry && $item; }, true),
            'ready_for_complex_endpoints' => $configLoaded && $helpersLoaded && $dbLoaded && $jwtLoaded && array_reduce($functionsExist, function($carry, $item) { return $carry && $item; }, true)
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Error en test ultra simple',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
}
