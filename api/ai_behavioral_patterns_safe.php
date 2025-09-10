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

    // Obtener patrones comportamentales del usuario
    $stmt = $pdo->prepare("
        SELECT 
            pattern_type,
            pattern_data,
            frequency,
            confidence,
            last_seen,
            created_at
        FROM ai_behavioral_patterns 
        WHERE user_id = ?
        ORDER BY frequency DESC, confidence DESC
    ");
    
    $stmt->execute([$user_id]);
    $patterns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Obtener perfil comportamental
    $stmt = $pdo->prepare("
        SELECT 
            trading_style,
            risk_tolerance,
            time_preference,
            preferred_symbols,
            analysis_frequency,
            success_patterns,
            failure_patterns,
            created_at,
            updated_at
        FROM ai_behavior_profiles 
        WHERE user_id = ?
    ");
    
    $stmt->execute([$user_id]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        // Crear perfil por defecto si no existe
        $stmt = $pdo->prepare("
            INSERT INTO ai_behavior_profiles 
            (user_id, trading_style, risk_tolerance, time_preference, preferred_symbols, analysis_frequency, success_patterns, failure_patterns)
            VALUES (?, 'equilibrado', 'moderada', 'intradia', '[]', 0, '{}', '{}')
        ");
        $stmt->execute([$user_id]);
        
        $profile = [
            'trading_style' => 'equilibrado',
            'risk_tolerance' => 'moderada',
            'time_preference' => 'intradia',
            'preferred_symbols' => '[]',
            'analysis_frequency' => 0,
            'success_patterns' => '{}',
            'failure_patterns' => '{}',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
    }

    json_out([
        'ok' => true,
        'patterns' => $patterns,
        'profile' => $profile
    ]);

} catch (Exception $e) {
    error_log("Error en ai_behavioral_patterns_safe.php: " . $e->getMessage());
    json_error('Error obteniendo patrones comportamentales', 500);
}