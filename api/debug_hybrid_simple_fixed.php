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
    
    $testPrompt = "Analiza TSLA para trading de opciones con enfoque en volatilidad y soportes/resistencias";
    
    // 1. Verificar knowledge_base
    $sql = "SELECT COUNT(*) as total FROM knowledge_base WHERE created_by = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $knowledgeCount = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 2. Verificar knowledge_files
    $sql = "SELECT COUNT(*) as total FROM knowledge_files WHERE user_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $filesCount = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 3. Probar búsqueda de conocimiento
    $keywords = ['analiza', 'tsla', 'trading'];
    $sql = "SELECT id, title, content, summary FROM knowledge_base 
            WHERE created_by = ? AND is_active = 1 
            AND (title LIKE ? OR content LIKE ? OR summary LIKE ?) 
            LIMIT 3";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id, "%{$keywords[0]}%", "%{$keywords[0]}%", "%{$keywords[0]}%"]);
    $knowledgeResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 4. Verificar si hay contenido real
    $hasRealContent = false;
    foreach ($knowledgeResults as $row) {
        if (strpos($row['content'], 'Contenido extraído automáticamente') === false) {
            $hasRealContent = true;
            break;
        }
    }
    
    $result = [
        'ok' => true,
        'user_id' => $user_id,
        'test_prompt' => $testPrompt,
        'keywords' => $keywords,
        'knowledge_base_count' => $knowledgeCount['total'],
        'knowledge_files_count' => $filesCount['total'],
        'knowledge_search_results' => count($knowledgeResults),
        'has_real_content' => $hasRealContent,
        'sample_results' => $knowledgeResults,
        'message' => 'Diagnóstico híbrido simple completado'
    ];
    
    json_out($result);
    
} catch (Exception $e) {
    error_log("Error en debug_hybrid_simple_fixed.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}
?>
