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

    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        json_error('Datos JSON inválidos', 400);
    }

    $symbol = $input['symbol'] ?? '';
    $analysis_text = $input['analysis_text'] ?? '';
    $timeframe = $input['timeframe'] ?? null;
    $outcome = $input['outcome'] ?? null;
    $traded = $input['traded'] ?? false;
    $behavioral_context = $input['behavioral_context'] ?? null;
    $ai_provider = $input['ai_provider'] ?? 'behavioral_ai';
    $analysis_type = $input['analysis_type'] ?? 'comprehensive';
    $confidence_score = $input['confidence_score'] ?? 0.5;

    if (empty($symbol) || empty($analysis_text)) {
        json_error('Símbolo y análisis son requeridos', 400);
    }

    // Guardar en el historial de análisis de IA
    $stmt = $pdo->prepare("
        INSERT INTO ai_analysis_history 
        (user_id, symbol, analysis_text, timeframe, outcome, traded, behavioral_context, ai_provider, analysis_type, confidence_score, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $behavioral_context_json = $behavioral_context ? json_encode($behavioral_context) : null;
    
    $stmt->execute([
        $user_id,
        $symbol,
        $analysis_text,
        $timeframe,
        $outcome,
        $traded ? 1 : 0,
        $behavioral_context_json,
        $ai_provider,
        $analysis_type,
        $confidence_score
    ]);

    $analysis_id = $pdo->lastInsertId();

    // Actualizar métricas de aprendizaje
    if ($outcome) {
        $success = ($outcome === 'positive' || $outcome === 'pos');
        
        // Actualizar métricas
        $stmt = $pdo->prepare("
            UPDATE ai_learning_metrics 
            SET 
                total_analyses = total_analyses + 1,
                successful_analyses = successful_analyses + ?,
                success_rate = (successful_analyses + ?) / (total_analyses + 1) * 100,
                last_analysis_date = NOW(),
                updated_at = NOW()
            WHERE user_id = ?
        ");
        
        $stmt->execute([$success ? 1 : 0, $success ? 1 : 0, $user_id]);
    }

    json_out([
        'ok' => true,
        'analysis_id' => $analysis_id,
        'message' => 'Análisis guardado con contexto comportamental'
    ]);

} catch (Exception $e) {
    error_log("Error en ai_analysis_save_safe.php: " . $e->getMessage());
    json_error('Error guardando análisis comportamental', 500);
}