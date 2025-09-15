<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

try {
    // Verificar que el endpoint se está llamando
    error_log("DEBUG: test_ai_extract_debug_safe.php llamado");
    
    // Verificar método
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Método no permitido', 405);
    }
    
    // Verificar headers
    $headers = getallheaders();
    error_log("DEBUG: Headers recibidos: " . json_encode($headers));
    
    // Verificar body
    $input = file_get_contents('php://input');
    error_log("DEBUG: Body recibido: " . $input);
    
    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        json_error('JSON inválido: ' . json_last_error_msg());
    }
    
    // Verificar token
    $token = $headers['Authorization'] ?? '';
    if (!preg_match('/Bearer\s+(.+)/', $token, $matches)) {
        json_error('Token de autorización requerido');
    }
    
    $jwt = $matches[1];
    $user = require_user();
    if (!$user) {
        json_error('Token inválido');
    }
    
    // Verificar file_id
    $fileId = $data['file_id'] ?? null;
    if (!$fileId) {
        json_error('file_id requerido');
    }
    
    // Verificar que el archivo existe
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM knowledge_files WHERE id = ? AND user_id = ?");
    $stmt->execute([$fileId, $user['id']]);
    $file = $stmt->fetch();
    
    if (!$file) {
        json_error('Archivo no encontrado o no tienes permisos');
    }
    
    // Respuesta de diagnóstico
    ok([
        'debug' => 'Endpoint funcionando correctamente',
        'user_id' => $user['id'],
        'file_id' => $fileId,
        'file_found' => true,
        'file_info' => [
            'original_filename' => $file['original_filename'],
            'file_size' => $file['file_size'],
            'upload_status' => $file['upload_status']
        ],
        'headers_received' => $headers,
        'body_received' => $data
    ]);
    
} catch (Exception $e) {
    error_log("ERROR en test_ai_extract_debug_safe.php: " . $e->getMessage());
    json_error('Error interno: ' . $e->getMessage());
}
?>
