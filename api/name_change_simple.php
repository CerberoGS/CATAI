<?php
declare(strict_types=1);

// Endpoint súper simple para probar
header('Content-Type: application/json');

try {
    require_once 'helpers.php';
    require_once 'db.php';
    
    // Solo POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['error' => 'Método no permitido']);
        exit;
    }
    
    // Obtener datos del POST
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['error' => 'Datos JSON inválidos']);
        exit;
    }
    
    $new_name = $input['new_name'] ?? '';
    
    // Validaciones básicas
    if (empty($new_name)) {
        echo json_encode(['error' => 'Nuevo nombre requerido']);
        exit;
    }
    
    // Simular éxito
    echo json_encode([
        'ok' => true,
        'message' => 'Nombre actualizado correctamente',
        'new_name' => $new_name
    ]);
    
} catch (Exception $e) {
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
?>
