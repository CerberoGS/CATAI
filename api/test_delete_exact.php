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

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método no permitido', 405);
}

// Obtener datos del request EXACTAMENTE como ai_knowledge_delete_safe.php
$input = json_decode(file_get_contents('php://input'), true);
$knowledge_id = $input['knowledge_id'] ?? $input['id'] ?? null;

// Log para debugging
error_log("TEST DELETE EXACT - Input recibido: " . json_encode($input));
error_log("TEST DELETE EXACT - Knowledge ID extraído: " . $knowledge_id);
error_log("TEST DELETE EXACT - User ID: " . $user_id);

if (!$knowledge_id) {
    json_error('ID de conocimiento requerido', 400);
}

try {
    $pdo = db();
    
    // Verificar que el conocimiento existe y pertenece al usuario
    $stmt = $pdo->prepare("
        SELECT id, title, source_file, created_by 
        FROM knowledge_base 
        WHERE id = ? AND created_by = ?
    ");
    $stmt->execute([$knowledge_id, $user_id]);
    $knowledge_record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$knowledge_record) {
        json_error('Conocimiento no encontrado o no pertenece al usuario', 404);
    }
    
    // Buscar el archivo correspondiente por nombre o por ID
    // Primero intentar por nombre exacto
    $stmt = $pdo->prepare("
        SELECT id, stored_filename, original_filename 
        FROM knowledge_files 
        WHERE original_filename = ? AND user_id = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$knowledge_record['source_file'], $user_id]);
    $file_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Si no se encuentra por nombre, buscar el más reciente del usuario
    if (!$file_info) {
        $stmt = $pdo->prepare("
            SELECT id, stored_filename, original_filename 
            FROM knowledge_files 
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$user_id]);
        $file_info = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Combinar información
    $knowledge = array_merge($knowledge_record, $file_info ?: []);
    
    if (!$knowledge) {
        json_error('Conocimiento no encontrado o no pertenece al usuario', 404);
    }
    
    // Verificar archivo físico
    $upload_dir = __DIR__ . '/uploads/knowledge/' . $user_id;
    $file_path = $upload_dir . '/' . $knowledge['stored_filename'];
    $file_exists = file_exists($file_path);
    
    // Simular eliminación (sin eliminar realmente)
    $result = [
        'ok' => true,
        'simulation' => true,
        'message' => 'Simulación exacta de eliminación completada',
        'knowledge_info' => $knowledge,
        'file_info' => [
            'upload_dir' => $upload_dir,
            'file_path' => $file_path,
            'file_exists' => $file_exists,
            'file_size' => $file_exists ? filesize($file_path) : 0
        ],
        'would_delete' => [
            'knowledge_base_record' => true,
            'knowledge_files_record' => !empty($knowledge['stored_filename']),
            'physical_file' => $file_exists
        ],
        'debug_info' => [
            'input_received' => $input,
            'knowledge_id_extracted' => $knowledge_id,
            'user_id' => $user_id,
            'sql_query_executed' => true,
            'endpoint_path' => 'api/test_delete_exact.php',
            'request_method' => $_SERVER['REQUEST_METHOD'],
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not_set'
        ]
    ];
    
    json_out($result);
    
} catch (Exception $e) {
    error_log("Error en test_delete_exact.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}
?>
