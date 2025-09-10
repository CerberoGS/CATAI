<?php
declare(strict_types=1);

require_once 'helpers.php';
require_once 'db.php';

// Aplicar CORS
apply_cors();

// Verificar autenticación
$user = require_user();
$user_id = $user['user_id'] ?? $user['id'] ?? null;

if (!$user_id) {
    json_error('Usuario no autenticado', 401);
}

try {
    $pdo = db();
    
    // Simular datos de entrada
    $testInputs = [
        ['knowledge_id' => 1],
        ['id' => 1],
        ['knowledge_id' => '1'],
        ['id' => '1']
    ];
    
    $results = [];
    
    foreach ($testInputs as $input) {
        $knowledge_id = $input['knowledge_id'] ?? $input['id'] ?? null;
        
        $results[] = [
            'input' => $input,
            'extracted_id' => $knowledge_id,
            'valid' => !empty($knowledge_id)
        ];
    }
    
    // Verificar si existe conocimiento para el usuario
    $sql = "SELECT id, title, source_file FROM knowledge_base WHERE created_by = ? LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $knowledge = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $result = [
        'ok' => true,
        'user_id' => $user_id,
        'test_inputs' => $results,
        'available_knowledge' => $knowledge,
        'delete_endpoint_ready' => !empty($knowledge),
        'message' => 'Prueba de extracción de parámetros completada'
    ];
    
    json_out($result);
    
} catch (Exception $e) {
    error_log("Error en test_delete_knowledge.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}
?>
