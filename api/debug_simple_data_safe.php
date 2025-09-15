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
    
    // Verificar Knowledge Base
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM knowledge_base WHERE created_by = ?");
    $stmt->execute([$user_id]);
    $knowledge_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Verificar Knowledge Files
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM knowledge_files WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $files_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Verificar Analysis
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM analysis WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $analysis_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Verificar AI Analysis History
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM ai_analysis_history WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $ai_analysis_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Verificar Learning Metrics
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM ai_learning_metrics WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $metrics_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Verificar Behavioral Patterns
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM ai_behavioral_patterns WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $patterns_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Verificar Behavioral Profiles
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM ai_behavior_profiles WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $profile_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    json_out([
        'ok' => true,
        'user_id' => $user_id,
        'summary' => [
            'knowledge_base' => $knowledge_count,
            'knowledge_files' => $files_count,
            'analysis' => $analysis_count,
            'ai_analysis_history' => $ai_analysis_count,
            'learning_metrics' => $metrics_count,
            'behavioral_patterns' => $patterns_count,
            'behavioral_profiles' => $profile_count
        ],
        'diagnosis' => [
            'has_knowledge' => $knowledge_count > 0,
            'has_files' => $files_count > 0,
            'has_analysis' => $analysis_count > 0,
            'has_ai_analysis' => $ai_analysis_count > 0,
            'has_metrics' => $metrics_count > 0,
            'has_patterns' => $patterns_count > 0,
            'has_profile' => $profile_count > 0
        ]
    ]);
    
} catch (Exception $e) {
    json_error('Error: ' . $e->getMessage());
}
?>
