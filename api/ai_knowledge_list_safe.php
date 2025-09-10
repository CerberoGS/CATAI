<?php
declare(strict_types=1);

require_once 'helpers.php';
require_once 'db.php';

try {
    $user = require_user();
    $user_id = $user['user_id'] ?? $user['id'] ?? null;
    
    if (!$user_id) {
        json_error('user_id_missing', 400, 'User ID not found in token');
    }
    
    // Obtener archivos de conocimiento del usuario
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT 
            kb.id,
            kb.knowledge_type,
            kb.title,
            kb.content,
            kb.summary,
            kb.tags,
            kb.confidence_score,
            kb.usage_count,
            kb.success_rate,
            kb.symbol,
            kb.sector,
            kb.source_type,
            kb.source_file,
            kb.is_public,
            kb.is_active,
            kb.created_at,
            kb.updated_at,
            kf.original_filename,
            kf.stored_filename,
            kf.file_type,
            kf.file_size,
            kf.mime_type,
            kf.upload_status,
            kf.extraction_status,
            kf.extracted_items
        FROM knowledge_base kb
        LEFT JOIN knowledge_files kf ON kb.source_file = kf.original_filename AND kf.user_id = ?
        WHERE kb.created_by = ? AND kb.is_active = 1
        ORDER BY kb.created_at DESC
    ");
    
    $stmt->execute([$user_id, $user_id]);
    $knowledge = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return json_out([
        'ok' => true,
        'knowledge' => $knowledge
    ]);

} catch (Exception $e) {
    error_log("Error en ai_knowledge_list_safe.php: " . $e->getMessage());
    return json_error('Error interno del servidor', 500);
}


