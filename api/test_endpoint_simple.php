<?php
declare(strict_types=1);

// Endpoint de prueba simple
error_log("=== TEST_ENDPOINT_SIMPLE.PHP EJECUTADO ===");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("=== POST REQUEST RECIBIDO ===");
    
    $input = json_decode(file_get_contents('php://input'), true);
    error_log("=== INPUT: " . json_encode($input) . " ===");
    
    echo json_encode([
        'ok' => true,
        'message' => 'Endpoint de prueba funcionando',
        'input' => $input,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
} else {
    error_log("=== GET REQUEST RECIBIDO ===");
    echo json_encode([
        'ok' => true,
        'message' => 'Endpoint de prueba funcionando (GET)',
        'method' => $_SERVER['REQUEST_METHOD'],
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
