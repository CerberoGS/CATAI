<?php
declare(strict_types=1);

require_once 'helpers.php';
require_once 'db.php';

// Aplicar CORS
apply_cors();

// Verificar autenticación
$user = require_user();
$user_id = $user['user_id'] ?? $user['id'] ?? null;

if (!$user_id) {
    json_error('Usuario no autenticado', 401);
}

try {
    $pdo = db();
    
    // 1. Ver todos los registros del usuario
    $sql = "SELECT id, title, content, summary, source_file, created_at 
            FROM knowledge_base 
            WHERE created_by = ? 
            ORDER BY created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $allRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Verificar archivos pendientes
    $sql = "SELECT id, original_filename, extraction_status, created_at 
            FROM knowledge_files 
            WHERE user_id = ? 
            ORDER BY created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $allFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 3. Probar búsqueda específica
    $keywords = ['analiza', 'tsla', 'trading'];
    $searchResults = [];
    
    foreach ($keywords as $keyword) {
        $sql = "SELECT id, title, content, summary 
                FROM knowledge_base 
                WHERE created_by = ? AND is_active = 1 
                AND (title LIKE ? OR content LIKE ? OR summary LIKE ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, "%{$keyword}%", "%{$keyword}%", "%{$keyword}%"]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $searchResults[$keyword] = [
            'count' => count($results),
            'results' => $results
        ];
    }
    
    $result = [
        'ok' => true,
        'user_id' => $user_id,
        'total_knowledge_records' => count($allRecords),
        'total_files' => count($allFiles),
        'knowledge_records' => $allRecords,
        'files_status' => $allFiles,
        'search_by_keyword' => $searchResults,
        'message' => 'Análisis completo de contenido de conocimiento'
    ];
    
    json_out($result);
    
} catch (Exception $e) {
    error_log("Error en debug_knowledge_content.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}
?>
