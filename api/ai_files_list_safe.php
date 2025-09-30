<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

/**
 * Lista archivos subidos y procesados por el usuario
 */
try {
    $user = require_user();
    $user_id = $user['user_id'] ?? $user['id'] ?? null;
    
    if (!$user_id) {
        json_error('user_id_missing', 400, 'User ID not found in token');
    }
    
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        json_error('method_not_allowed', 405, 'Only GET method allowed');
    }

    // Parámetros de consulta
    $limit = min((int)($_GET['limit'] ?? 20), 100);
    $offset = max((int)($_GET['offset'] ?? 0), 0);
    $status = $_GET['status'] ?? '';
    $file_type = $_GET['file_type'] ?? '';

    $pdo = db();

    // Construir consulta
    $where_conditions = ['user_id = ?'];
    $params = [$user_id];

    if ($status !== '') {
        $where_conditions[] = 'upload_status = ?';
        $params[] = $status;
    }

    if ($file_type !== '') {
        $where_conditions[] = 'file_type = ?';
        $params[] = $file_type;
    }

    $where_clause = implode(' AND ', $where_conditions);

    // Contar total
    $count_sql = "SELECT COUNT(*) as total FROM knowledge_files WHERE $where_clause";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Obtener archivos
    $sql = "
        SELECT 
            id,
            original_filename,
            stored_filename,
            file_type,
            file_size,
            mime_type,
            upload_status,
            extraction_status,
            ai_file_id,
            vector_store_id,
            created_at,
            updated_at
        FROM knowledge_files 
        WHERE $where_clause
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Formatear respuesta
    $formatted_files = [];
    foreach ($files as $file) {
        $formatted_files[] = [
            'id' => (int)$file['id'],
            'filename' => $file['original_filename'],
            'file_type' => $file['file_type'],
            'file_size' => (int)$file['file_size'],
            'file_size_mb' => round($file['file_size'] / 1024 / 1024, 2),
            'upload_status' => $file['upload_status'],
            'extraction_status' => $file['extraction_status'],
            'ai_file_id' => $file['ai_file_id'],
            'vector_store_id' => $file['vector_store_id'],
            'created_at' => $file['created_at'],
            'updated_at' => $file['updated_at'],
            'is_processed' => $file['upload_status'] === 'processed',
            'has_extraction' => $file['extraction_status'] === 'extracted'
        ];
    }

    json_out([
        'ok' => true,
        'total' => (int)$total,
        'limit' => $limit,
        'offset' => $offset,
        'files' => $formatted_files,
        'summary' => [
            'total_files' => (int)$total,
            'processed_files' => count(array_filter($formatted_files, fn($f) => $f['is_processed'])),
            'extracted_files' => count(array_filter($formatted_files, fn($f) => $f['has_extraction']))
        ]
    ]);

} catch (Exception $e) {
    error_log("Error in ai_files_list_safe.php: " . $e->getMessage());
    json_error('internal_error', 500, 'Internal server error: ' . $e->getMessage());
}
