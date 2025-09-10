<?php
declare(strict_types=1);

require_once 'helpers.php';
require_once 'db.php';

// Aplicar CORS
apply_cors();

try {
    $user = require_user();
    $user_id = $user['user_id'] ?? $user['id'] ?? null;
    
    $pdo = db();
    
    $knowledge_id = (int)($_GET['id'] ?? 0);
    if (!$knowledge_id) {
        return json_error('ID de conocimiento requerido', 400);
    }

    // Obtener conocimiento específico del usuario
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
            updated_at
        FROM knowledge_base 
        WHERE id = ? AND created_by = ?
    ");
    
    $stmt->execute([$knowledge_id, $user_id]);
    $knowledge = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$knowledge) {
        return json_error('Conocimiento no encontrado', 404);
    }

    // Obtener archivo asociado si existe
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

    return json_out([
        'ok' => true,
        'knowledge' => $knowledge
    ]);

} catch (Exception $e) {
    error_log("Error en ai_knowledge_get_safe.php: " . $e->getMessage());
    return json_error('Error interno del servidor', 500);
}
