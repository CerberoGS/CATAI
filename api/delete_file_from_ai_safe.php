<?php declare(strict_types=1);

require_once 'helpers.php';
require_once 'db.php';
require_once 'run_op_safe.php';

try {
    $user = require_user();
    $user_id = $user['user_id'] ?? $user['id'] ?? null;
    
    if (!$user_id) {
        json_error('user_id_missing', 400, 'User ID not found in token');
    }

    // Validación de método
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Método no permitido', 405);
    }

    // Obtener file_id del POST
    $input = json_decode(file_get_contents('php://input'), true);
    $file_id = $input['file_id'] ?? null;
    
    if (empty($file_id)) {
        json_error('file_id_required', 400, 'File ID es requerido');
    }

    $pdo = db();

    // 1. Verificar que el archivo existe y pertenece al usuario
    $fileStmt = $pdo->prepare("
        SELECT id, original_filename, openai_file_id, vector_store_id
        FROM knowledge_files 
        WHERE user_id = ? AND openai_file_id = ?
    ");
    $fileStmt->execute([$user_id, $file_id]);
    $file = $fileStmt->fetch();
    
    if (!$file) {
        json_error('file_not_found', 404, 'Archivo no encontrado o no pertenece al usuario');
    }

    // 2. Obtener provider_id de OpenAI
    $providerStmt = $pdo->prepare("SELECT id FROM ai_providers WHERE slug = 'openai'");
    $providerStmt->execute();
    $provider = $providerStmt->fetch();
    
    if (!$provider) {
        json_error('provider_not_found', 500, 'Proveedor OpenAI no encontrado');
    }

    // 3. Usar runOp para borrar el archivo
    error_log("Intentando borrar file_id: $file_id con provider_id: " . $provider['id']);
    
    $result = runOp($provider['id'], 'vs.delete', [
        'FILE_ID' => $file_id
    ]);

    error_log("Resultado de runOp: " . json_encode($result));

    if (!$result['ok']) {
        $errorDetails = $result['error'] ?? 'Error desconocido';
        $fullError = "Error en runOp vs.delete: " . json_encode($result);
        error_log($fullError);
        json_error('delete_failed', 500, "Error al borrar archivo: $errorDetails. Detalles completos: " . json_encode($result));
    }

    // 4. Verificar respuesta de OpenAI
    if (!isset($result['data']['deleted']) || !$result['data']['deleted']) {
        json_error('delete_not_confirmed', 500, 'OpenAI no confirmó el borrado');
    }

    // 5. Actualizar base de datos local
    $updateStmt = $pdo->prepare("
        UPDATE knowledge_files 
        SET openai_file_id = NULL, 
            upload_status = 'deleted',
            updated_at = NOW()
        WHERE id = ? AND user_id = ?
    ");
    $updateStmt->execute([$file['id'], $user_id]);

    json_out([
        'ok' => true,
        'message' => 'Archivo borrado exitosamente de la IA',
        'file_id' => $file_id,
        'filename' => $file['original_filename'],
        'deleted_from_db' => true,
        'openai_response' => $result['data']
    ]);

} catch (Throwable $e) {
    $errorMsg = "Error en delete_file_from_ai_safe.php: " . $e->getMessage() . " - Stack: " . $e->getTraceAsString();
    error_log($errorMsg);
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}
?>
