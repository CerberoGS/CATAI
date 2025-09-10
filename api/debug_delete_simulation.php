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

// Obtener datos del request
$input = json_decode(file_get_contents('php://input'), true);
$knowledge_id = $input['knowledge_id'] ?? $input['id'] ?? null;

// Log para debugging
error_log("DEBUG DELETE SIMULATION - Input recibido: " . json_encode($input));
error_log("DEBUG DELETE SIMULATION - Knowledge ID extraído: " . $knowledge_id);
error_log("DEBUG DELETE SIMULATION - User ID: " . $user_id);

if (!$knowledge_id) {
    json_error('ID de conocimiento requerido', 400);
}

try {
    $pdo = db();
    
    // Verificar que el conocimiento existe y pertenece al usuario
    $stmt = $pdo->prepare("
        SELECT kb.id, kb.title, kb.source_file, kf.stored_filename, kf.original_filename 
        FROM knowledge_base kb 
        LEFT JOIN knowledge_files kf ON kb.source_file = kf.id 
        WHERE kb.id = ? AND kb.created_by = ?
    ");
    $stmt->execute([$knowledge_id, $user_id]);
    $knowledge = $stmt->fetch(PDO::FETCH_ASSOC);
    
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
        'message' => 'Simulación de eliminación completada',
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
            'sql_query_executed' => true
        ]
    ];
    
    json_out($result);
    
} catch (Exception $e) {
    error_log("Error en debug_delete_simulation.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}
?>
