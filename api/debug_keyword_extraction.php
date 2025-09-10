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
    
    // Función mejorada de extracción de palabras clave
    function extractKeywordsImproved($text) {
        // Convertir a minúsculas y limpiar
        $text = strtolower($text);
        $text = preg_replace('/[^\w\s]/', ' ', $text);
        
        // Palabras clave específicas de trading
        $tradingKeywords = [
            'trading', 'opciones', 'análisis', 'analisis', 'técnico', 'tecnico',
            'estrategias', 'mercado', 'volatilidad', 'soporte', 'resistencia',
            'patrones', 'indicadores', 'rsi', 'sma', 'ema', 'bollinger',
            'tsla', 'tesla', 'apple', 'aapl', 'microsoft', 'msft',
            'scalping', 'swing', 'day', 'trading', 'intraday'
        ];
        
        // Extraer palabras del texto
        $words = preg_split('/\s+/', $text);
        $words = array_filter($words, function($word) {
            return strlen($word) > 2; // Solo palabras de más de 2 caracteres
        });
        
        // Combinar palabras del texto con palabras clave específicas
        $allKeywords = array_merge($words, $tradingKeywords);
        
        // Eliminar duplicados y palabras comunes
        $commonWords = ['the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'el', 'la', 'de', 'en', 'con', 'por', 'para'];
        $allKeywords = array_diff($allKeywords, $commonWords);
        
        return array_unique($allKeywords);
    }
    
    // Probar con el prompt de prueba
    $testPrompt = "Analiza TSLA para trading de opciones con enfoque en volatilidad y soportes/resistencias";
    $keywords = extractKeywordsImproved($testPrompt);
    
    // Buscar con cada palabra clave
    $searchResults = [];
    foreach ($keywords as $keyword) {
        $sql = "SELECT id, title, content, summary 
                FROM knowledge_base 
                WHERE created_by = ? AND is_active = 1 
                AND (title LIKE ? OR content LIKE ? OR summary LIKE ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, "%{$keyword}%", "%{$keyword}%", "%{$keyword}%"]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($results) > 0) {
            $searchResults[$keyword] = [
                'count' => count($results),
                'results' => $results
            ];
        }
    }
    
    // Contar total de coincidencias únicas
    $uniqueMatches = [];
    foreach ($searchResults as $keyword => $data) {
        foreach ($data['results'] as $result) {
            $uniqueMatches[$result['id']] = $result;
        }
    }
    
    $result = [
        'ok' => true,
        'user_id' => $user_id,
        'test_prompt' => $testPrompt,
        'extracted_keywords' => $keywords,
        'keyword_search_results' => $searchResults,
        'total_unique_matches' => count($uniqueMatches),
        'unique_matches' => array_values($uniqueMatches),
        'message' => 'Análisis de extracción de palabras clave mejorado'
    ];
    
    json_out($result);
    
} catch (Exception $e) {
    error_log("Error en debug_keyword_extraction.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}
?>
