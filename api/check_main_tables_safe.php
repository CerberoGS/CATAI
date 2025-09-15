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
    $pdo = get_pdo();
    
    $results = [
        'ok' => true,
        'user_id' => $user_id,
        'timestamp' => date('Y-m-d H:i:s'),
        'data' => []
    ];
    
    // Verificar Knowledge Base
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM knowledge_base WHERE created_by = ?");
        $stmt->execute([$user_id]);
        $knowledge_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        $results['data']['knowledge_base'] = (int)$knowledge_count;
    } catch (Exception $e) {
        $results['data']['knowledge_base'] = 'Error: ' . $e->getMessage();
    }
    
    // Verificar Knowledge Files
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM knowledge_files WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $files_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        $results['data']['knowledge_files'] = (int)$files_count;
    } catch (Exception $e) {
        $results['data']['knowledge_files'] = 'Error: ' . $e->getMessage();
    }
    
    // Verificar Analysis
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM analysis WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $analysis_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        $results['data']['analysis'] = (int)$analysis_count;
    } catch (Exception $e) {
        $results['data']['analysis'] = 'Error: ' . $e->getMessage();
    }
    
    // Verificar AI Analysis History
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM ai_analysis_history WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $ai_analysis_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        $results['data']['ai_analysis_history'] = (int)$ai_analysis_count;
    } catch (Exception $e) {
        $results['data']['ai_analysis_history'] = 'Error: ' . $e->getMessage();
    }
    
    json_out($results);
    
} catch (Exception $e) {
    json_error('Error general: ' . $e->getMessage());
}
?>
