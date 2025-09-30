<?php
require_once 'helpers.php';
require_once 'db.php';

try {
    $user = require_user();
    $user_id = $user['user_id'] ?? $user['id'] ?? null;
    
    if (!$user_id) {
        json_error('Usuario no autenticado', 401);
    }

    $pdo = db();

    // Archivos SIN Vector Store (huérfanos)
    $orphanedStmt = $pdo->prepare("
        SELECT id, original_filename, file_id, upload_status, extraction_status
        FROM knowledge_files 
        WHERE user_id = ? 
        AND file_id IS NOT NULL 
        AND file_id != ''
        AND (vector_store_id IS NULL OR vector_store_id = '')
        ORDER BY created_at DESC
    ");
    $orphanedStmt->execute([$user_id]);
    $orphanedFiles = $orphanedStmt->fetchAll();

    // Archivos CON Vector Store
    $withVSStmt = $pdo->prepare("
        SELECT id, original_filename, file_id, vector_store_id, upload_status, extraction_status
        FROM knowledge_files 
        WHERE user_id = ? 
        AND file_id IS NOT NULL 
        AND file_id != ''
        AND vector_store_id IS NOT NULL 
        AND vector_store_id != ''
        ORDER BY created_at DESC
    ");
    $withVSStmt->execute([$user_id]);
    $withVSFiles = $withVSStmt->fetchAll();

    json_out([
        'ok' => true,
        'orphaned_count' => count($orphanedFiles),
        'with_vs_count' => count($withVSFiles),
        'orphaned_files' => $orphanedFiles,
        'with_vs_files' => $withVSFiles
    ]);

} catch (Exception $e) {
    json_error('Error: ' . $e->getMessage(), 500);
}
?>
