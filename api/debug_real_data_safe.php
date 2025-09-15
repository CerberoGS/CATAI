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
    
    // 1. Verificar Knowledge Base (archivos subidos)
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM knowledge_base WHERE created_by = ?");
    $stmt->execute([$user_id]);
    $knowledge_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // 2. Verificar Knowledge Files (archivos físicos)
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM knowledge_files WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $files_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // 3. Verificar Analysis History (análisis guardados)
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM analysis WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $analysis_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // 4. Verificar AI Analysis History (análisis IA guardados)
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM ai_analysis_history WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $ai_analysis_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // 5. Verificar Learning Metrics
    $stmt = $pdo->prepare("SELECT * FROM ai_learning_metrics WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $metrics = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 6. Verificar Behavioral Patterns
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM ai_behavioral_patterns WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $patterns_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // 7. Verificar Behavioral Profiles
    $stmt = $pdo->prepare("SELECT * FROM ai_behavior_profiles WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 8. Obtener algunos ejemplos de datos
    $stmt = $pdo->prepare("SELECT id, title, source_file, created_at FROM knowledge_base WHERE created_by = ? ORDER BY created_at DESC LIMIT 3");
    $stmt->execute([$user_id]);
    $knowledge_examples = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("SELECT id, symbol, analysis_type, created_at FROM analysis WHERE user_id = ? ORDER BY created_at DESC LIMIT 3");
    $stmt->execute([$user_id]);
    $analysis_examples = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    json_out([
        'ok' => true,
        'user_id' => $user_id,
        'data_summary' => [
            'knowledge_base_count' => $knowledge_count,
            'knowledge_files_count' => $files_count,
            'analysis_count' => $analysis_count,
            'ai_analysis_count' => $ai_analysis_count,
            'patterns_count' => $patterns_count,
            'has_metrics' => !empty($metrics),
            'has_profile' => !empty($profile)
        ],
        'knowledge_examples' => $knowledge_examples,
        'analysis_examples' => $analysis_examples,
        'metrics' => $metrics,
        'profile' => $profile,
        'diagnosis' => [
            'has_knowledge' => $knowledge_count > 0,
            'has_files' => $files_count > 0,
            'has_analysis' => $analysis_count > 0,
            'has_ai_analysis' => $ai_analysis_count > 0,
            'has_patterns' => $patterns_count > 0,
            'has_metrics' => !empty($metrics),
            'has_profile' => !empty($profile)
        ]
    ]);
    
} catch (Exception $e) {
    json_error('Error verificando datos reales: ' . $e->getMessage());
}
?>
