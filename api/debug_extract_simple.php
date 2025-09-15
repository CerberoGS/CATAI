<?php
// Debug ultra simple para el botón "Extraer Contenido"
header('Content-Type: application/json');

try {
    // Verificar token
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';
    
    if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
        echo json_encode(['ok' => false, 'error' => 'Token requerido']);
        exit;
    }
    
    $token = substr($authHeader, 7);
    
    // Verificar ID
    $knowledgeId = $_POST['knowledge_id'] ?? null;
    if (empty($knowledgeId)) {
        echo json_encode(['ok' => false, 'error' => 'ID requerido']);
        exit;
    }
    
    // Respuesta simple
    echo json_encode([
        'ok' => true,
        'message' => 'Botón funcionando correctamente',
        'knowledge_id' => $knowledgeId,
        'timestamp' => date('Y-m-d H:i:s'),
        'debug' => 'Endpoint debug simple funcionando'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
