<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
    $user = require_user();
    $userId = (int)($user['id'] ?? 0);
    
    if ($userId <= 0) {
        json_error('invalid-user', 401);
    }
    
    $pdo = db();
    
    // Buscar archivos duplicados por original_filename
    $duplicatesStmt = $pdo->prepare("
        SELECT 
            original_filename,
            COUNT(*) as count,
            GROUP_CONCAT(id ORDER BY created_at ASC) as file_ids
        FROM knowledge_files 
        WHERE user_id = ?
        GROUP BY original_filename
        HAVING COUNT(*) > 1
        ORDER BY count DESC
    ");
    $duplicatesStmt->execute([$userId]);
    $duplicates = $duplicatesStmt->fetchAll();
    
    $cleanedFiles = 0;
    $details = [];
    
    foreach ($duplicates as $duplicate) {
        $filename = $duplicate['original_filename'];
        $count = $duplicate['count'];
        $fileIds = explode(',', $duplicate['file_ids']);
        
        // Mantener el primer archivo (más antiguo) y eliminar los demás
        $keepId = array_shift($fileIds); // Primer archivo (más antiguo)
        $deleteIds = $fileIds; // Resto para eliminar
        
        $details[] = [
            'filename' => $filename,
            'total_duplicates' => $count,
            'kept_file_id' => $keepId,
            'deleted_file_ids' => $deleteIds
        ];
        
        // Eliminar archivos duplicados
        if (!empty($deleteIds)) {
            $placeholders = str_repeat('?,', count($deleteIds) - 1) . '?';
            $deleteStmt = $pdo->prepare("
                DELETE FROM knowledge_files 
                WHERE id IN ($placeholders) AND user_id = ?
            ");
            $deleteStmt->execute(array_merge($deleteIds, [$userId]));
            $cleanedFiles += count($deleteIds);
        }
    }
    
    json_out([
        'ok' => true,
        'cleanup_result' => [
            'total_duplicate_groups' => count($duplicates),
            'files_removed' => $cleanedFiles,
            'details' => $details
        ],
        'message' => "Limpieza completada: {$cleanedFiles} archivos duplicados eliminados"
    ]);
    
} catch (Throwable $e) {
    error_log("clean_duplicate_files_safe.php error: " . $e->getMessage());
    json_error('server_error', 500, $e->getMessage());
}
