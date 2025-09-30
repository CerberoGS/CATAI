<?php
// /api/test_endpoint_debug.php
error_log("=== TEST_ENDPOINT_DEBUG.PHP EJECUTADO ===");

require_once 'helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("=== POST REQUEST RECIBIDO ===");
    
    // Obtener el contenido raw primero
    $rawInput = file_get_contents('php://input');
    error_log("=== RAW INPUT: " . $rawInput . " ===");
    error_log("=== RAW INPUT LENGTH: " . strlen($rawInput) . " ===");
    
    // Decodificar JSON
    $input = json_decode($rawInput, true);
    error_log("=== INPUT DECODIFICADO: " . json_encode($input) . " ===");
    
    // Verificar si el JSON se cargó correctamente
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("=== ERROR JSON: " . json_last_error_msg() . " ===");
        error_log("=== RAW INPUT QUE CAUSÓ ERROR: " . $rawInput . " ===");
        json_error('JSON inválido: ' . json_last_error_msg());
        exit;
    }
    
    // Intentar autenticar usuario
    try {
        $user = require_user();
        error_log("=== USUARIO AUTENTICADO: " . $user['email'] . " ===");
    } catch (Exception $e) {
        error_log("=== ERROR AUTENTICACIÓN: " . $e->getMessage() . " ===");
        json_error('Error de autenticación: ' . $e->getMessage());
        exit;
    }
    
    $result = [
        'success' => true,
        'message' => 'Endpoint de debug funcionando correctamente',
        'input' => $input,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email']
        ],
        'timestamp' => date('Y-m-d H:i:s'),
        'server_info' => [
            'php_version' => PHP_VERSION,
            'request_method' => $_SERVER['REQUEST_METHOD'],
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'N/A',
            'content_length' => $_SERVER['CONTENT_LENGTH'] ?? 'N/A'
        ]
    ];
    
    error_log("=== RESULTADO: " . json_encode($result) . " ===");
    json_out($result);
    
} else {
    error_log("=== ERROR: MÉTODO NO PERMITIDO ===");
    json_error('Método no permitido');
}
?>
