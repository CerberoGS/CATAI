<?php
declare(strict_types=1);

require_once 'helpers.php';
require_once 'db.php';

// Aplicar CORS
apply_cors();

// Verificar autenticación
$user = require_user();

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método no permitido', 405);
}

// Obtener ID del usuario
$user_id = $user['user_id'] ?? $user['id'] ?? null;
if (!$user_id) {
    json_error('Usuario no válido', 400);
}

// Obtener datos del request
$input = json_decode(file_get_contents('php://input'), true);
$knowledge_id = $input['knowledge_id'] ?? $input['id'] ?? null;

// Log para debugging
error_log("DEBUG DELETE - Input recibido: " . json_encode($input));
error_log("DEBUG DELETE - Knowledge ID extraído: " . $knowledge_id);

if (!$knowledge_id) {
    json_error('ID de conocimiento requerido', 400);
}

try {
    $pdo = db();
    
    // Obtener información del archivo antes de eliminarlo
    // Primero obtener el registro de knowledge_base
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
    $file_info = array_merge($knowledge_record, $file_info ?: []);
    
    if (!$file_info) {
        json_error('Archivo no encontrado', 404);
    }
    
    // Eliminar archivo físico si existe
    if (!empty($file_info['stored_filename'])) {
        $upload_dir = __DIR__ . '/uploads/knowledge/' . $user_id;
        $file_path = $upload_dir . '/' . $file_info['stored_filename'];
        
        if (file_exists($file_path)) {
            unlink($file_path);
        }
    }
    
    // Eliminar de knowledge_base
    $stmt = $pdo->prepare("DELETE FROM knowledge_base WHERE id = ? AND created_by = ?");
    $stmt->execute([$knowledge_id, $user_id]);
    
    // Eliminar de knowledge_files si existe
    if (!empty($file_info['id'])) {
        $stmt = $pdo->prepare("DELETE FROM knowledge_files WHERE id = ? AND user_id = ?");
        $stmt->execute([$file_info['id'], $user_id]);
    }
    
    json_out([
        'ok' => true,
        'message' => 'Archivo eliminado exitosamente',
        'deleted_file' => $file_info['original_filename']
    ]);
    
} catch (Exception $e) {
    error_log("Error eliminando conocimiento: " . $e->getMessage());
    json_error('Error interno del servidor', 500);
}


