<?php
declare(strict_types=1);

// Endpoint ultra simple para debug
header('Content-Type: application/json; charset=utf-8');
header_remove('X-Powered-By');

try {
    // Capturar cualquier salida accidental
    if (!ob_get_level()) { ob_start(); }
    
    $result = [
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Endpoint simple funcionando',
        'php_version' => PHP_VERSION,
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'get_params' => $_GET,
        'post_data' => $_POST,
        'raw_input' => file_get_contents('php://input') ?: '',
        'headers' => getallheaders() ?: []
    ];
    
    // Verificar si hay fugas de salida
    $leak = '';
    if (ob_get_level()) { $leak = ob_get_clean(); }
    if ($leak !== '') {
        $result['output_leak'] = $leak;
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
