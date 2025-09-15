<?php
// Test de diagnóstico profundo SIN shell_exec()
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Función para capturar errores fatales
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo json_encode([
            'ok' => false,
            'timestamp' => date('Y-m-d H:i:s'),
            'message' => 'Error fatal detectado',
            'fatal_error' => $error,
            'diagnosis' => 'Error fatal de PHP que termina el script'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
});

// Limpiar cualquier output buffer
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    $results = [];
    
    // Test 1: Verificar que PHP está funcionando
    $results['php_basic'] = [
        'working' => true,
        'version' => PHP_VERSION,
        'memory_limit' => ini_get('memory_limit'),
        'output_buffer_level' => ob_get_level(),
        'disabled_functions' => ini_get('disable_functions')
    ];
    
    // Test 2: Verificar que podemos hacer echo
    $results['echo_test'] = [
        'working' => true,
        'message' => 'Echo funciona correctamente'
    ];
    
    // Test 3: Verificar que podemos hacer json_encode
    $testData = ['test' => 'value', 'number' => 123];
    $jsonResult = json_encode($testData);
    $results['json_test'] = [
        'working' => $jsonResult !== false,
        'result' => $jsonResult,
        'error' => $jsonResult === false ? json_last_error_msg() : null
    ];
    
    // Test 4: Verificar archivos individuales
    $files = ['config.php', 'helpers.php', 'db.php', 'jwt.php'];
    $fileResults = [];
    
    foreach ($files as $file) {
        $filePath = __DIR__ . '/' . $file;
        $fileResults[$file] = [
            'exists' => file_exists($filePath),
            'readable' => is_readable($filePath),
            'size' => file_exists($filePath) ? filesize($filePath) : 0,
            'path' => $filePath,
            'permissions' => file_exists($filePath) ? substr(sprintf('%o', fileperms($filePath)), -4) : null
        ];
    }
    $results['file_checks'] = $fileResults;
    
    // Test 5: Verificar sintaxis de config.php usando tokenizer
    $configResults = [];
    $configPath = __DIR__ . '/config.php';
    
    if (file_exists($configPath)) {
        // Verificar sintaxis usando tokenizer (no requiere shell_exec)
        $tokens = @token_get_all(file_get_contents($configPath));
        $configResults['syntax_check'] = [
            'method' => 'tokenizer',
            'valid' => $tokens !== false,
            'token_count' => is_array($tokens) ? count($tokens) : 0,
            'error' => $tokens === false ? 'Error de sintaxis detectado' : null
        ];
        
        // Verificar que el archivo termina correctamente
        $content = file_get_contents($configPath);
        $configResults['file_analysis'] = [
            'size' => strlen($content),
            'ends_with_newline' => substr($content, -1) === "\n",
            'ends_with_semicolon' => substr(rtrim($content), -1) === ';',
            'has_opening_tag' => strpos($content, '<?php') !== false,
            'has_closing_tag' => strpos($content, '?>') !== false
        ];
    }
    
    // Test 6: Intentar incluir config.php con output buffering
    if (file_exists($configPath)) {
        ob_start();
        try {
            $configData = include $configPath;
            $output = ob_get_clean();
            
            $configResults['include_test'] = [
                'success' => true,
                'output_captured' => $output,
                'output_length' => strlen($output),
                'config_type' => gettype($configData),
                'config_keys' => is_array($configData) ? array_keys($configData) : [],
                'config_count' => is_array($configData) ? count($configData) : 0
            ];
        } catch (Throwable $e) {
            ob_end_clean();
            $configResults['include_test'] = [
                'success' => false,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ];
        }
    }
    
    $results['config_analysis'] = $configResults;
    
    // Test 7: Verificar funciones críticas después de cargar archivos
    $functionResults = [];
    
    // Intentar cargar helpers.php
    $helpersPath = __DIR__ . '/helpers.php';
    if (file_exists($helpersPath)) {
        ob_start();
        try {
            include $helpersPath;
            $helpersOutput = ob_get_clean();
            
            $functionResults['helpers_loaded'] = [
                'success' => true,
                'output_captured' => $helpersOutput,
                'output_length' => strlen($helpersOutput)
            ];
            
            // Verificar funciones después de cargar helpers
            $functionResults['functions_after_helpers'] = [
                'json_out' => function_exists('json_out'),
                'require_user' => function_exists('require_user'),
                'getApiUrl' => function_exists('getApiUrl'),
                'json_error' => function_exists('json_error'),
                'read_json_body' => function_exists('read_json_body')
            ];
            
        } catch (Throwable $e) {
            ob_end_clean();
            $functionResults['helpers_loaded'] = [
                'success' => false,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ];
        }
    }
    
    // Intentar cargar db.php
    $dbPath = __DIR__ . '/db.php';
    if (file_exists($dbPath)) {
        ob_start();
        try {
            include $dbPath;
            $dbOutput = ob_get_clean();
            
            $functionResults['db_loaded'] = [
                'success' => true,
                'output_captured' => $dbOutput,
                'output_length' => strlen($dbOutput)
            ];
            
            // Verificar funciones después de cargar db
            $functionResults['functions_after_db'] = [
                'db' => function_exists('db'),
                'pdo_available' => class_exists('PDO')
            ];
            
        } catch (Throwable $e) {
            ob_end_clean();
            $functionResults['db_loaded'] = [
                'success' => false,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ];
        }
    }
    
    // Intentar cargar jwt.php
    $jwtPath = __DIR__ . '/jwt.php';
    if (file_exists($jwtPath)) {
        ob_start();
        try {
            include $jwtPath;
            $jwtOutput = ob_get_clean();
            
            $functionResults['jwt_loaded'] = [
                'success' => true,
                'output_captured' => $jwtOutput,
                'output_length' => strlen($jwtOutput)
            ];
            
            // Verificar funciones después de cargar jwt
            $functionResults['functions_after_jwt'] = [
                'jwt_sign' => function_exists('jwt_sign'),
                'jwt_verify' => function_exists('jwt_verify'),
                'jwt_verify_hs256' => function_exists('jwt_verify_hs256')
            ];
            
        } catch (Throwable $e) {
            ob_end_clean();
            $functionResults['jwt_loaded'] = [
                'success' => false,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ];
        }
    }
    
    $results['function_analysis'] = $functionResults;
    
    // Test 8: Verificar que podemos devolver JSON
    $finalResult = [
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Diagnóstico profundo completado (sin shell_exec)',
        'results' => $results,
        'diagnosis' => [
            'php_working' => true,
            'json_working' => $results['json_test']['working'],
            'files_accessible' => array_reduce($fileResults, function($carry, $item) { return $carry && $item['exists']; }, true),
            'config_syntax_ok' => $configResults['syntax_check']['valid'] ?? false,
            'config_loadable' => $configResults['include_test']['success'] ?? false,
            'helpers_loadable' => $functionResults['helpers_loaded']['success'] ?? false,
            'db_loadable' => $functionResults['db_loaded']['success'] ?? false,
            'jwt_loadable' => $functionResults['jwt_loaded']['success'] ?? false,
            'ready_for_complex_endpoints' => false // Se determinará después
        ]
    ];
    
    // Determinar si está listo para endpoints complejos
    $finalResult['diagnosis']['ready_for_complex_endpoints'] = 
        $finalResult['diagnosis']['php_working'] &&
        $finalResult['diagnosis']['json_working'] &&
        $finalResult['diagnosis']['files_accessible'] &&
        $finalResult['diagnosis']['config_syntax_ok'] &&
        $finalResult['diagnosis']['config_loadable'] &&
        $finalResult['diagnosis']['helpers_loadable'] &&
        $finalResult['diagnosis']['db_loadable'] &&
        $finalResult['diagnosis']['jwt_loadable'];
    
    echo json_encode($finalResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Error en diagnóstico profundo',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], JSON_UNESCAPED_UNICODE);
}
