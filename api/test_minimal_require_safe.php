<?php
// Test mínimo para identificar problema con require_once
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$results = [];
$currentTest = '';

try {
    // Test 1: Verificar que el archivo existe
    $currentTest = 'file_exists';
    $configPath = __DIR__ . '/config.php';
    $results['file_exists'] = [
        'config_path' => $configPath,
        'exists' => file_exists($configPath),
        'readable' => is_readable($configPath),
        'size' => file_exists($configPath) ? filesize($configPath) : 'N/A'
    ];

    // Test 2: Verificar sintaxis PHP básica
    $currentTest = 'php_syntax';
    $results['php_syntax'] = [
        'php_version' => PHP_VERSION,
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'error_reporting' => error_reporting()
    ];

    // Test 3: Intentar cargar config.php con output buffering
    $currentTest = 'load_config';
    ob_start();
    try {
        $config = require $configPath;
        $output = ob_get_clean();
        
        $results['load_config'] = [
            'success' => true,
            'config_type' => gettype($config),
            'config_keys' => is_array($config) ? array_keys($config) : 'not_array',
            'output_captured' => $output,
            'output_length' => strlen($output)
        ];
    } catch (Throwable $e) {
        ob_end_clean();
        $results['load_config'] = [
            'success' => false,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ];
        throw $e;
    }

    // Test 4: Verificar si hay problemas de encoding
    $currentTest = 'encoding';
    $fileContent = file_get_contents($configPath);
    $results['encoding'] = [
        'file_size' => strlen($fileContent),
        'has_bom' => substr($fileContent, 0, 3) === "\xEF\xBB\xBF",
        'first_chars' => substr($fileContent, 0, 50),
        'last_chars' => substr($fileContent, -50)
    ];

    // Respuesta exitosa
    echo json_encode([
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Test mínimo completado exitosamente',
        'current_test' => $currentTest,
        'results' => $results
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    // Respuesta de error
    echo json_encode([
        'ok' => false,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Test mínimo falló',
        'failed_at_test' => $currentTest,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'results' => $results
    ], JSON_UNESCAPED_UNICODE);
}
