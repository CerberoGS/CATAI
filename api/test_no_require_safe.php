<?php
// Test sin require_once para identificar error fatal
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    // Test 1: Verificar que PHP funciona
    $phpWorking = true;
    $phpVersion = phpversion();
    $memoryLimit = ini_get('memory_limit');
    
    // Test 2: Verificar que podemos escribir JSON
    $jsonTest = json_encode(['test' => 'value']);
    $jsonWorking = $jsonTest !== false;
    
    // Test 3: Verificar que podemos leer archivos
    $configPath = __DIR__ . '/config.php';
    $configExists = file_exists($configPath);
    $configReadable = is_readable($configPath);
    $configSize = $configExists ? filesize($configPath) : 0;
    
    // Test 4: Verificar sintaxis PHP básica
    $syntaxTest = true;
    try {
        eval('$test = 1 + 1;');
    } catch (Throwable $e) {
        $syntaxTest = false;
    }
    
    // Test 5: Verificar que no hay output buffer activo
    $outputBufferActive = ob_get_level() > 0;
    
    echo json_encode([
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Test sin require_once completado',
        'php_info' => [
            'working' => $phpWorking,
            'version' => $phpVersion,
            'memory_limit' => $memoryLimit,
            'output_buffer_active' => $outputBufferActive
        ],
        'json_test' => [
            'working' => $jsonWorking,
            'test_value' => $jsonTest
        ],
        'file_tests' => [
            'config_exists' => $configExists,
            'config_readable' => $configReadable,
            'config_size' => $configSize,
            'config_path' => $configPath
        ],
        'syntax_test' => [
            'working' => $syntaxTest
        ],
        'diagnosis' => [
            'php_working' => $phpWorking,
            'json_working' => $jsonWorking,
            'files_accessible' => $configExists && $configReadable,
            'syntax_ok' => $syntaxTest,
            'no_output_buffer' => !$outputBufferActive,
            'ready_for_require' => $phpWorking && $jsonWorking && $configExists && $configReadable && $syntaxTest && !$outputBufferActive
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Error fatal en test sin require_once',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], JSON_UNESCAPED_UNICODE);
}
