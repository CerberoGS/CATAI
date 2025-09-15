<?php
declare(strict_types=1);

// Endpoint simple para diagnosticar el botón "Ver Detalles"
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jwt.php';

try {
    // Verificar token de autorización
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';
    
    if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
        json_error('Token de autorización requerido', 401);
        exit;
    }
    
    $token = substr($authHeader, 7);
    $user = jwt_verify($token);
    
    // Verificar parámetro ID
    $knowledgeId = $_GET['id'] ?? null;
    if (empty($knowledgeId)) {
        json_error('ID de conocimiento requerido', 400);
        exit;
    }
    
    // Buscar el conocimiento en la base de datos
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT kb.*, kf.original_filename, kf.file_type, kf.file_size, kf.mime_type
        FROM knowledge_base kb
        LEFT JOIN knowledge_files kf ON kb.source_file = kf.original_filename
        WHERE kb.id = ? AND kb.created_by = ?
    ");
    
    $stmt->execute([$knowledgeId, $user['id']]);
    $knowledge = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$knowledge) {
        json_error('Conocimiento no encontrado', 404);
        exit;
    }
    
    // Preparar respuesta
    $response = [
        'ok' => true,
        'knowledge' => $knowledge,
        'debug_info' => [
            'timestamp' => date('Y-m-d H:i:s'),
            'user_id' => $user['id'],
            'knowledge_id' => $knowledgeId,
            'file_found' => !empty($knowledge['original_filename']),
            'file_type' => $knowledge['file_type'] ?? 'unknown'
        ]
    ];
    
    json_out($response);
    
} catch (Exception $e) {
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}
