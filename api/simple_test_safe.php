<?php
// Test ultra-simple sin dependencias
header('Content-Type: application/json; charset=utf-8');

try {
    echo json_encode([
        'ok' => true,
        'message' => 'Endpoint funcionando',
        'timestamp' => date('Y-m-d H:i:s'),
        'php_version' => PHP_VERSION,
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown'
    ]);
} catch (Exception $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
?>
