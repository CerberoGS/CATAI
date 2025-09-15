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

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método no permitido', 405);
}

// Obtener datos del request
$input = json_decode(file_get_contents('php://input'), true);

$symbol = $input['symbol'] ?? '';
$analysis_type = $input['analysis_type'] ?? 'comprehensive';
$ai_response = $input['ai_response'] ?? '';
$provider = $input['provider'] ?? 'auto';
$model = $input['model'] ?? '';
$context_sources = $input['context_sources'] ?? [];
$context_length = $input['context_length'] ?? 0;
$behavioral_enhanced = $input['behavioral_enhanced'] ?? false;

if (empty($symbol) || empty($ai_response)) {
    json_error('Símbolo y respuesta de IA requeridos', 400);
}

try {
    $pdo = db();
    
    // Insertar análisis en historial
    $stmt = $pdo->prepare("
        INSERT INTO ai_analysis_history (
            user_id, symbol, analysis_type, ai_response, provider, model,
            context_sources, context_length, behavioral_enhanced, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $context_sources_json = json_encode($context_sources);
    
    $stmt->execute([
        $user_id,
        $symbol,
        $analysis_type,
        $ai_response,
        $provider,
        $model,
        $context_sources_json,
        $context_length,
        $behavioral_enhanced ? 1 : 0
    ]);
    
    $analysis_id = $pdo->lastInsertId();
    
    // Actualizar métricas de aprendizaje
    $this->updateLearningMetrics($pdo, $user_id, $symbol, $analysis_type);
    
    json_out([
        'ok' => true,
        'analysis_id' => $analysis_id,
        'message' => 'Análisis guardado exitosamente'
    ]);
    
} catch (Exception $e) {
    error_log("Error en ai_analysis_save_safe.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}

// Función para actualizar métricas de aprendizaje
function updateLearningMetrics($pdo, $user_id, $symbol, $analysis_type) {
    try {
        // Contar análisis totales
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total_analyses 
            FROM ai_analysis_history 
            WHERE user_id = ?
        ");
        $stmt->execute([$user_id]);
        $total_analyses = $stmt->fetch(PDO::FETCH_ASSOC)['total_analyses'];
        
        // Calcular tasa de éxito (simplificada)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as successful_analyses 
            FROM ai_analysis_history 
            WHERE user_id = ? AND behavioral_enhanced = 1
        ");
        $stmt->execute([$user_id]);
        $successful_analyses = $stmt->fetch(PDO::FETCH_ASSOC)['successful_analyses'];
        
        $success_rate = $total_analyses > 0 ? ($successful_analyses / $total_analyses) * 100 : 0;
        
        // Contar patrones aprendidos
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as patterns_learned 
            FROM ai_behavioral_patterns 
            WHERE user_id = ?
        ");
        $stmt->execute([$user_id]);
        $patterns_learned = $stmt->fetch(PDO::FETCH_ASSOC)['patterns_learned'];
        
        // Calcular precisión IA (simplificada)
        $accuracy_score = min(95, 60 + ($success_rate * 0.35));
        
        // Insertar o actualizar métricas
        $stmt = $pdo->prepare("
            INSERT INTO ai_learning_metrics (
                user_id, total_analyses, success_rate, patterns_learned, 
                accuracy_score, last_analysis_symbol, last_analysis_type, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                total_analyses = VALUES(total_analyses),
                success_rate = VALUES(success_rate),
                patterns_learned = VALUES(patterns_learned),
                accuracy_score = VALUES(accuracy_score),
                last_analysis_symbol = VALUES(last_analysis_symbol),
                last_analysis_type = VALUES(last_analysis_type),
                updated_at = NOW()
        ");
        
        $stmt->execute([
            $user_id,
            $total_analyses,
            $success_rate,
            $patterns_learned,
            $accuracy_score,
            $symbol,
            $analysis_type
        ]);
        
    } catch (Exception $e) {
        error_log("Error actualizando métricas de aprendizaje: " . $e->getMessage());
    }
}
?>