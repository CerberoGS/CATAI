<?php
declare(strict_types=1);

require_once 'helpers.php';
require_once 'db.php';

// Aplicar CORS
apply_cors();

// Verificar autenticación
$user = require_user();

// Obtener ID del usuario
$user_id = $user['user_id'] ?? $user['id'] ?? null;
if (!$user_id) {
    json_error('Usuario no válido', 400);
}

// Obtener parámetros
$knowledge_id = (int)($_GET['id'] ?? 0);
if (!$knowledge_id) {
    json_error('ID de conocimiento requerido', 400);
}

// Logging detallado
error_log("=== DEBUG KNOWLEDGE GET ===");
error_log("User ID: " . $user_id);
error_log("Knowledge ID: " . $knowledge_id);

try {
    $pdo = db();
    
    // Consulta 1: Verificar si existe el conocimiento
    $stmt = $pdo->prepare("
        SELECT 
            id,
            knowledge_type,
            title,
            content,
            summary,
            tags,
            confidence_score,
            usage_count,
            success_rate,
            symbol,
            sector,
            source_type,
            source_file,
            is_public,
            is_active,
            created_at,
            updated_at,
            created_by
        FROM knowledge_base 
        WHERE id = ? AND created_by = ?
    ");
    
    $stmt->execute([$knowledge_id, $user_id]);
    $knowledge = $stmt->fetch(PDO::FETCH_ASSOC);
    
    error_log("Knowledge found: " . ($knowledge ? 'YES' : 'NO'));
    if ($knowledge) {
        error_log("Knowledge title: " . $knowledge['title']);
        error_log("Knowledge type: " . $knowledge['knowledge_type']);
        error_log("Source file: " . $knowledge['source_file']);
    }

    if (!$knowledge) {
        json_error('Conocimiento no encontrado', 404);
    }

    // Consulta 2: Obtener archivo asociado
    $stmt = $pdo->prepare("
        SELECT 
            kf.original_filename,
            kf.stored_filename,
            kf.file_type,
            kf.file_size,
            kf.mime_type,
            kf.upload_status,
            kf.extraction_status,
            kf.extracted_items
        FROM knowledge_files kf
        WHERE kf.original_filename = ? AND kf.user_id = ?
    ");
    
    $stmt->execute([$knowledge['source_file'], $user_id]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    
    error_log("File found: " . ($file ? 'YES' : 'NO'));
    if ($file) {
        error_log("File name: " . $file['original_filename']);
        error_log("File type: " . $file['file_type']);
    }

    if ($file) {
        $knowledge['original_filename'] = $file['original_filename'];
        $knowledge['stored_filename'] = $file['stored_filename'];
        $knowledge['file_type'] = $file['file_type'];
        $knowledge['file_size'] = $file['file_size'];
        $knowledge['mime_type'] = $file['mime_type'];
        $knowledge['upload_status'] = $file['upload_status'];
        $knowledge['extraction_status'] = $file['extraction_status'];
        $knowledge['extracted_items'] = $file['extracted_items'];
    }

    json_out([
        'ok' => true,
        'knowledge' => $knowledge,
        'debug' => [
            'user_id' => $user_id,
            'knowledge_id' => $knowledge_id,
            'knowledge_found' => !!$knowledge,
            'file_found' => !!$file
        ]
    ]);

} catch (Exception $e) {
    error_log("Error en debug_knowledge_get.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}
