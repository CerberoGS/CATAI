<?php
// Test solo config.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$results = [
    'ok' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'test' => 'config.php only'
];

// Test 1: Incluir solo config.php
try {
    require_once __DIR__ . '/config.php';
    $results['config'] = ['status' => 'OK', 'message' => 'config.php cargado correctamente'];
    
    // Verificar si las variables están definidas
    if (isset($CONFIG)) {
        $results['config']['variables'] = ['status' => 'OK', 'message' => 'Variable $CONFIG definida'];
    } else {
        $results['config']['variables'] = ['status' => 'WARNING', 'message' => 'Variable $CONFIG no definida'];
    }
    
} catch (Exception $e) {
    $results['config'] = ['status' => 'ERROR', 'message' => $e->getMessage()];
    $results['ok'] = false;
} catch (Error $e) {
    $results['config'] = ['status' => 'FATAL_ERROR', 'message' => $e->getMessage()];
    $results['ok'] = false;
}

echo json_encode($results);
?>
