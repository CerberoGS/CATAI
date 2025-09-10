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
    
    // 1. Verificar datos en knowledge_base
    $sql = "SELECT COUNT(*) as total, COUNT(CASE WHEN is_active = 1 THEN 1 END) as active 
            FROM knowledge_base WHERE created_by = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $knowledgeStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 2. Verificar datos en knowledge_files
    $sql = "SELECT COUNT(*) as total FROM knowledge_files WHERE user_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $filesStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 3. Probar extracción de palabras clave
    $keywords = extractKeywords($testPrompt);
    
    // 4. Probar búsqueda de conocimiento
    $knowledgeContext = getRelevantKnowledge($pdo, $user_id, $testPrompt);
    
    // 5. Probar patrones comportamentales
    $behavioralPatterns = getBehavioralPatterns($pdo, $user_id);
    
    // 6. Probar historial de análisis
    $analysisHistory = getAnalysisHistory($pdo, $user_id);
    
    // 7. Mostrar algunos registros de ejemplo
    $sql = "SELECT id, title, content, summary, tags, confidence_score 
            FROM knowledge_base 
            WHERE created_by = ? AND is_active = 1 
            LIMIT 3";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $sampleKnowledge = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $result = [
        'ok' => true,
        'user_id' => $user_id,
        'test_prompt' => $testPrompt,
        'knowledge_stats' => $knowledgeStats,
        'files_stats' => $filesStats,
        'extracted_keywords' => $keywords,
        'knowledge_context_found' => $knowledgeContext ? true : false,
        'knowledge_context_preview' => $knowledgeContext ? substr($knowledgeContext['content'], 0, 200) . '...' : null,
        'behavioral_patterns_found' => $behavioralPatterns ? true : false,
        'behavioral_patterns_preview' => $behavioralPatterns ? substr($behavioralPatterns, 0, 200) . '...' : null,
        'analysis_history_found' => $analysisHistory ? true : false,
        'analysis_history_preview' => $analysisHistory ? substr($analysisHistory, 0, 200) . '...' : null,
        'sample_knowledge' => $sampleKnowledge
    ];
    
    json_out($result);
    
} catch (Exception $e) {
    error_log("Error en debug_hybrid_system.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}

// Funciones auxiliares (copiadas de ai_analyze_hybrid.php)

function getRelevantKnowledge($pdo, $user_id, $prompt) {
    try {
        // Buscar conocimiento relevante basado en palabras clave del prompt
        $keywords = extractKeywords($prompt);
        
        if (empty($keywords)) {
            return null;
        }
        
        $placeholders = str_repeat('?,', count($keywords) - 1) . '?';
        $sql = "SELECT title, content, summary FROM knowledge_base 
                WHERE created_by = ? AND is_active = 1 
                AND (title LIKE ? OR content LIKE ? OR summary LIKE ?) 
                ORDER BY confidence_score DESC, usage_count DESC 
                LIMIT 3";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, "%{$keywords[0]}%", "%{$keywords[0]}%", "%{$keywords[0]}%"]);
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($results)) {
            return null;
        }
        
        $context = "Conocimiento relevante encontrado:\n";
        foreach ($results as $row) {
            $context .= "- " . $row['title'] . ": " . $row['summary'] . "\n";
        }
        
        return ['content' => $context];
        
    } catch (Exception $e) {
        error_log("Error obteniendo conocimiento relevante: " . $e->getMessage());
        return null;
    }
}

function getBehavioralPatterns($pdo, $user_id) {
    try {
        $sql = "SELECT pattern_type, description, confidence_score 
                FROM ai_behavioral_patterns 
                WHERE user_id = ? AND confidence_score > 0.7 
                ORDER BY confidence_score DESC 
                LIMIT 5";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id]);
        
        $patterns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($patterns)) {
            return null;
        }
        
        $context = "Patrones comportamentales identificados:\n";
        foreach ($patterns as $pattern) {
            $context .= "- " . $pattern['pattern_type'] . ": " . $pattern['description'] . " (Confianza: " . round($pattern['confidence_score'] * 100) . "%)\n";
        }
        
        return $context;
        
    } catch (Exception $e) {
        error_log("Error obteniendo patrones comportamentales: " . $e->getMessage());
        return null;
    }
}

function getAnalysisHistory($pdo, $user_id) {
    try {
        $sql = "SELECT symbol, outcome, notes, created_at 
                FROM analysis 
                WHERE user_id = ? AND outcome IN ('ganancia', 'profit') 
                ORDER BY created_at DESC 
                LIMIT 3";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id]);
        
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($history)) {
            return null;
        }
        
        $context = "Análisis exitosos anteriores:\n";
        foreach ($history as $analysis) {
            $context .= "- " . $analysis['symbol'] . " (" . $analysis['created_at'] . "): " . ($analysis['notes'] ?: 'Sin notas') . "\n";
        }
        
        return $context;
        
    } catch (Exception $e) {
        error_log("Error obteniendo historial de análisis: " . $e->getMessage());
        return null;
    }
}

function extractKeywords($prompt) {
    // Extraer palabras clave del prompt (simplificado)
    $words = preg_split('/\s+/', strtolower($prompt));
    $keywords = array_filter($words, function($word) {
        return strlen($word) > 3 && !in_array($word, ['con', 'para', 'que', 'los', 'las', 'del', 'una', 'este', 'esta']);
    });
    
    return array_slice($keywords, 0, 3); // Máximo 3 palabras clave
}
?>
