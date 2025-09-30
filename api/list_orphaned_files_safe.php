<?php
/**
 * Lista archivos huérfanos: archivos que tienen file_id pero no están en ningún Vector Store
 * SOLO LISTA - NO ELIMINA
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
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        json_error('Método no permitido', 405);
    }

    $pdo = db();

    // 1. Obtener TODOS los archivos del usuario
    $allFilesStmt = $pdo->prepare("
        SELECT id, original_filename, file_id, vector_store_id, upload_status, extraction_status, created_at
        FROM knowledge_files 
        WHERE user_id = ? 
        ORDER BY created_at DESC
    ");
    $allFilesStmt->execute([$user_id]);
    $allFiles = $allFilesStmt->fetchAll();

    $orphanedFiles = [];
    $safeFiles = [];
    $filesWithVS = [];

    // 2. Clasificar archivos
    foreach ($allFiles as $file) {
        if (!empty($file['file_id'])) {
            // Tiene file_id - verificar si está en algún VS
            if (!empty($file['vector_store_id'])) {
                // Tiene vector_store_id - está en un VS
                $filesWithVS[] = [
                    'id' => $file['id'],
                    'filename' => $file['original_filename'],
                    'file_id' => $file['file_id'],
                    'vector_store_id' => $file['vector_store_id'],
                    'upload_status' => $file['upload_status'],
                    'extraction_status' => $file['extraction_status'],
                    'created_at' => $file['created_at'],
                    'reason' => 'Tiene vector_store_id'
                ];
            } else {
                // Tiene file_id pero NO vector_store_id - verificar si realmente está en algún VS
                $vsCheckStmt = $pdo->prepare("
                    SELECT COUNT(*) as count, GROUP_CONCAT(avs.external_id) as vs_ids
                    FROM ai_vector_stores avs
                    JOIN ai_vector_documents avd ON avs.id = avd.vector_store_id
                    WHERE avs.owner_user_id = ? 
                    AND avd.file_id = ?
                ");
                $vsCheckStmt->execute([$user_id, $file['file_id']]);
                $vsCheck = $vsCheckStmt->fetch();

                if ($vsCheck['count'] > 0) {
                    // Está en un VS pero no tiene vector_store_id en knowledge_files
                    $filesWithVS[] = [
                        'id' => $file['id'],
                        'filename' => $file['original_filename'],
                        'file_id' => $file['file_id'],
                        'vector_store_id' => 'Encontrado en VS: ' . $vsCheck['vs_ids'],
                        'upload_status' => $file['upload_status'],
                        'extraction_status' => $file['extraction_status'],
                        'created_at' => $file['created_at'],
                        'reason' => 'Encontrado en ai_vector_documents'
                    ];
                } else {
                    // Realmente huérfano
                    $orphanedFiles[] = [
                        'id' => $file['id'],
                        'filename' => $file['original_filename'],
                        'file_id' => $file['file_id'],
                        'upload_status' => $file['upload_status'],
                        'extraction_status' => $file['extraction_status'],
                        'created_at' => $file['created_at'],
                        'reason' => 'Huérfano - tiene file_id pero no está en ningún VS'
                    ];
                }
            }
        } else {
            // No tiene file_id - no está en la IA
            $safeFiles[] = [
                'id' => $file['id'],
                'filename' => $file['original_filename'],
                'file_id' => null,
                'upload_status' => $file['upload_status'],
                'extraction_status' => $file['extraction_status'],
                'created_at' => $file['created_at'],
                'reason' => 'No tiene file_id - no está en la IA'
            ];
        }
    }

    json_out([
        'ok' => true,
        'user_id' => $user_id,
        'summary' => [
            'total_files' => count($allFiles),
            'orphaned_files' => count($orphanedFiles),
            'files_with_vs' => count($filesWithVS),
            'safe_files' => count($safeFiles)
        ],
        'orphaned_files' => $orphanedFiles,
        'files_with_vs' => $filesWithVS,
        'safe_files' => $safeFiles,
        'message' => "Análisis completado. " . count($orphanedFiles) . " archivos huérfanos encontrados."
    ]);

} catch (Exception $e) {
    error_log("Error en list_orphaned_files_safe.php: " . $e->getMessage());
    json_error('Error interno: ' . $e->getMessage(), 500);
}
?>
