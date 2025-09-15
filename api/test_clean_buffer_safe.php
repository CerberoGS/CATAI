<?php
// Test con output buffer limpio
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    // Limpiar cualquier output buffer activo
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Test 1: Verificar que el buffer está limpio
    $bufferClean = ob_get_level() === 0;
    
    // Test 2: Cargar config.php
    $configLoaded = false;
    $configError = null;
    $configData = null;
    
    try {
        $configData = require __DIR__ . '/config.php';
        $configLoaded = true;
    } catch (Throwable $e) {
        $configError = $e->getMessage();
    }
    
    // Test 3: Cargar helpers.php
    $helpersLoaded = false;
    $helpersError = null;
    
    try {
        require_once __DIR__ . '/helpers.php';
        $helpersLoaded = true;
    } catch (Throwable $e) {
        $helpersError = $e->getMessage();
    }
    
    // Test 4: Cargar db.php
    $dbLoaded = false;
    $dbError = null;
    
    try {
        require_once __DIR__ . '/db.php';
        $dbLoaded = true;
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
    
    // Test 5: Cargar jwt.php
    $jwtLoaded = false;
    $jwtError = null;
    
    try {
        require_once __DIR__ . '/jwt.php';
        $jwtLoaded = true;
    } catch (Throwable $e) {
        $jwtError = $e->getMessage();
    }
    
    // Test 6: Verificar funciones críticas
    $functionsExist = [
        'json_out' => function_exists('json_out'),
        'require_user' => function_exists('require_user'),
        'db' => function_exists('db'),
        'jwt_sign' => function_exists('jwt_sign'),
        'jwt_verify' => function_exists('jwt_verify')
    ];
    
    echo json_encode([
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Test con buffer limpio completado',
        'buffer_status' => [
            'initial_level' => ob_get_level(),
            'buffer_clean' => $bufferClean
        ],
        'file_loading' => [
            'config_loaded' => $configLoaded,
            'config_error' => $configError,
            'config_type' => $configData ? gettype($configData) : null,
            'config_keys' => $configData && is_array($configData) ? array_keys($configData) : [],
            'helpers_loaded' => $helpersLoaded,
            'helpers_error' => $helpersError,
            'db_loaded' => $dbLoaded,
            'db_error' => $dbError,
            'jwt_loaded' => $jwtLoaded,
            'jwt_error' => $jwtError
        ],
        'functions_exist' => $functionsExist,
        'diagnosis' => [
            'buffer_clean' => $bufferClean,
            'all_files_loaded' => $configLoaded && $helpersLoaded && $dbLoaded && $jwtLoaded,
            'all_functions_exist' => array_reduce($functionsExist, function($carry, $item) { return $carry && $item; }, true),
            'ready_for_complex_endpoints' => $bufferClean && $configLoaded && $helpersLoaded && $dbLoaded && $jwtLoaded && array_reduce($functionsExist, function($carry, $item) { return $carry && $item; }, true)
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Error en test con buffer limpio',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
}
