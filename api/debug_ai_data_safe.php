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
    
    // Verificar métricas de aprendizaje
    $stmt = $pdo->prepare("SELECT * FROM ai_learning_metrics WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $metrics = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Verificar patrones comportamentales
    $stmt = $pdo->prepare("SELECT * FROM ai_behavioral_patterns WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $patterns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Verificar historial de análisis
    $stmt = $pdo->prepare("SELECT * FROM ai_analysis_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$user_id]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Verificar perfil comportamental
    $stmt = $pdo->prepare("SELECT * FROM ai_behavior_profiles WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    
    json_out([
        'ok' => true,
        'user_id' => $user_id,
        'metrics' => $metrics,
        'patterns_count' => count($patterns),
        'patterns' => $patterns,
        'history_count' => count($history),
        'history' => $history,
        'profile' => $profile,
        'debug_info' => [
            'has_metrics' => !empty($metrics),
            'has_patterns' => count($patterns) > 0,
            'has_history' => count($history) > 0,
            'has_profile' => !empty($profile)
        ]
    ]);
    
} catch (Exception $e) {
    json_error('Error verificando datos: ' . $e->getMessage());
}
?>
