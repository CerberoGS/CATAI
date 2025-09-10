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
    
    // Función mejorada de extracción de palabras clave (copiada del debug_keyword_extraction.php)
    function extractKeywordsImproved($text) {
        $text = strtolower($text);
        $text = preg_replace('/[^\w\s]/', ' ', $text);
        
        $tradingKeywords = [
            'trading', 'opciones', 'análisis', 'analisis', 'técnico', 'tecnico',
            'estrategias', 'mercado', 'volatilidad', 'soporte', 'resistencia',
            'patrones', 'indicadores', 'rsi', 'sma', 'ema', 'bollinger',
            'tsla', 'tesla', 'apple', 'aapl', 'microsoft', 'msft',
            'scalping', 'swing', 'day', 'trading', 'intraday'
        ];
        
        $words = preg_split('/\s+/', $text);
        $words = array_filter($words, function($word) {
            return strlen($word) > 2;
        });
        
        $allKeywords = array_merge($words, $tradingKeywords);
        $commonWords = ['the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'el', 'la', 'de', 'en', 'con', 'por', 'para'];
        $allKeywords = array_diff($allKeywords, $commonWords);
        
        return array_unique($allKeywords);
    }
    
    // Función getRelevantKnowledge mejorada (simulando la del sistema híbrido)
    function getRelevantKnowledge($pdo, $user_id, $prompt, $limit = 3) {
        $keywords = extractKeywordsImproved($prompt);
        error_log("Keywords extraídas: " . json_encode($keywords));
        
        $context = [];
        $totalLength = 0;
        
        foreach ($keywords as $keyword) {
            $sql = "SELECT id, title, content, summary, confidence_score 
                    FROM knowledge_base 
                    WHERE created_by = ? AND is_active = 1 
                    AND (title LIKE ? OR content LIKE ? OR summary LIKE ?)
                    ORDER BY confidence_score DESC, usage_count DESC
                    LIMIT ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$user_id, "%{$keyword}%", "%{$keyword}%", "%{$keyword}%", $limit]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("Búsqueda para '{$keyword}': " . count($results) . " resultados");
            
            foreach ($results as $result) {
                // Evitar duplicados
                $exists = false;
                foreach ($context as $existing) {
                    if ($existing['id'] == $result['id']) {
                        $exists = true;
                        break;
                    }
                }
                
                if (!$exists) {
                    $context[] = $result;
                    $totalLength += strlen($result['content']);
                }
            }
        }
        
        // Limitar contexto total
        if ($totalLength > 4000) {
            $context = array_slice($context, 0, 2);
        }
        
        return [
            'context' => $context,
            'total_length' => $totalLength,
            'keywords_used' => $keywords
        ];
    }
    
    // Probar con el prompt de prueba
    $testPrompt = "Analiza TSLA para trading de opciones con enfoque en volatilidad y soportes/resistencias";
    
    // 1. Obtener contexto usando la función mejorada
    $knowledgeResult = getRelevantKnowledge($pdo, $user_id, $testPrompt);
    
    // 2. Verificar registros en knowledge_base
    $sql = "SELECT COUNT(*) as total FROM knowledge_base WHERE created_by = ? AND is_active = 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $knowledgeCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 3. Verificar archivos
    $sql = "SELECT COUNT(*) as total FROM knowledge_files WHERE user_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $filesCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $result = [
        'ok' => true,
        'user_id' => $user_id,
        'test_prompt' => $testPrompt,
        'knowledge_base_total' => $knowledgeCount,
        'knowledge_files_total' => $filesCount,
        'extracted_keywords' => $knowledgeResult['keywords_used'],
        'context_found' => count($knowledgeResult['context']),
        'context_length' => $knowledgeResult['total_length'],
        'context_sources' => $knowledgeResult['context'],
        'message' => 'Debug del contexto híbrido completado'
    ];
    
    json_out($result);
    
} catch (Exception $e) {
    error_log("Error en debug_hybrid_context.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}
?>
