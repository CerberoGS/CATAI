<?php
/**
 * Limpia archivos huérfanos: archivos que tienen file_id pero no están en ningún Vector Store
 */

require_once 'helpers.php';
require_once 'db.php';

try {
    $user = require_user();
    $user_id = $user['user_id'] ?? $user['id'] ?? null;
    
    if (!$user_id) {
        json_error('Usuario no autenticado', 401);
    }

    // Validación de método
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Método no permitido', 405);
    }

    $pdo = db();

    // 1. Obtener archivos del usuario que tienen file_id pero no vector_store_id
    $orphanedStmt = $pdo->prepare("
        SELECT id, original_filename, file_id, upload_status, created_at
        FROM knowledge_files 
        WHERE user_id = ? 
        AND file_id IS NOT NULL 
        AND file_id != ''
        AND (vector_store_id IS NULL OR vector_store_id = '')
        ORDER BY created_at DESC
    ");
    $orphanedStmt->execute([$user_id]);
    $orphanedFiles = $orphanedStmt->fetchAll();

    $deletedCount = 0;
    $deletedFiles = [];

    // 2. Para cada archivo huérfano, verificar si realmente no está en ningún VS
    foreach ($orphanedFiles as $file) {
        // Verificar si el file_id existe en algún VS del usuario
        $vsCheckStmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM ai_vector_stores avs
            JOIN ai_vector_documents avd ON avs.id = avd.vector_store_id
            WHERE avs.owner_user_id = ? 
            AND avd.file_id = ?
        ");
        $vsCheckStmt->execute([$user_id, $file['file_id']]);
        $vsCheck = $vsCheckStmt->fetch();

        // Si no está en ningún VS, es realmente huérfano
        if ($vsCheck['count'] == 0) {
            // Eliminar el archivo de la base de datos
            $deleteStmt = $pdo->prepare("DELETE FROM knowledge_files WHERE id = ? AND user_id = ?");
            $deleteStmt->execute([$file['id'], $user_id]);
            
            if ($deleteStmt->rowCount() > 0) {
                $deletedCount++;
                $deletedFiles[] = [
                    'id' => $file['id'],
                    'filename' => $file['original_filename'],
                    'file_id' => $file['file_id'],
                    'upload_status' => $file['upload_status'],
                    'created_at' => $file['created_at']
                ];
            }
        }
    }

    json_out([
        'ok' => true,
        'user_id' => $user_id,
        'orphaned_files_found' => count($orphanedFiles),
        'deleted_count' => $deletedCount,
        'deleted_files' => $deletedFiles,
        'message' => "Limpieza completada. Se encontraron " . count($orphanedFiles) . " archivos huérfanos y se eliminaron " . $deletedCount . " archivos."
    ]);

} catch (Exception $e) {
    error_log("Error en clean_orphaned_files_safe.php: " . $e->getMessage());
    json_error('Error interno: ' . $e->getMessage(), 500);
}
?>
