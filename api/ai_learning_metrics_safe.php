<?php
declare(strict_types=1);

require_once 'helpers.php';
require_once 'db.php';

// Aplicar CORS
apply_cors();

try {
    $user = require_user();
    $user_id = $user['user_id'] ?? $user['id'] ?? null;
    
    if (!$user_id) {
        json_error('Usuario no válido', 400);
    }

    $pdo = db();

    // Obtener métricas de aprendizaje del usuario
    $stmt = $pdo->prepare("
        SELECT 
            total_analyses,
            successful_analyses,
            success_rate,
            patterns_learned,
            accuracy_score,
            last_analysis_date,
            created_at,
            updated_at
        FROM ai_learning_metrics 
        WHERE user_id = ?
    ");
    
    $stmt->execute([$user_id]);
    $metrics = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$metrics) {
        // Crear métricas por defecto si no existen
        $stmt = $pdo->prepare("
            INSERT INTO ai_learning_metrics 
            (user_id, total_analyses, successful_analyses, success_rate, patterns_learned, accuracy_score, last_analysis_date)
            VALUES (?, 0, 0, 0.0, 0, 0.0, NULL)
        ");
        $stmt->execute([$user_id]);
        
        $metrics = [
            'total_analyses' => 0,
            'successful_analyses' => 0,
            'success_rate' => 0.0,
            'patterns_learned' => 0,
            'accuracy_score' => 0.0,
            'last_analysis_date' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
    }

    json_out([
        'ok' => true,
        'metrics' => $metrics
    ]);

} catch (Exception $e) {
    error_log("Error en ai_learning_metrics_safe.php: " . $e->getMessage());
    json_error('Error obteniendo métricas de aprendizaje', 500);
}