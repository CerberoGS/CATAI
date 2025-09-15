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
$prompt = $input['prompt'] ?? '';
$provider = $input['provider'] ?? 'auto';
$model = $input['model'] ?? '';
$systemPrompt = $input['systemPrompt'] ?? '';

// Nuevos parámetros para controlar el contexto
$useKnowledgeBase = $input['useKnowledgeBase'] ?? true;
$includeBehavioralPatterns = $input['includeBehavioralPatterns'] ?? false;
$includeAnalysisHistory = $input['includeAnalysisHistory'] ?? false;
$includeFiles = $input['includeFiles'] ?? true;

if (empty($prompt)) {
    json_error('Prompt requerido', 400);
}

try {
    $pdo = db();
    
    // Inicializar contexto
    $context = '';
    $contextSources = [];
    
    // 1. Knowledge Base (con caché simple)
    if ($useKnowledgeBase && $includeFiles) {
        $context .= getRelevantKnowledgeOptimized($pdo, $user_id, $prompt);
        if (!empty($context)) {
            $contextSources[] = 'Knowledge Base';
        }
    }
    
    // 2. Patrones comportamentales (solo si se solicita)
    if ($includeBehavioralPatterns) {
        $behavioralContext = getBehavioralPatterns($pdo, $user_id);
        if (!empty($behavioralContext)) {
            $context .= $behavioralContext;
            $contextSources[] = 'Patrones Comportamentales';
        }
    }
    
    // 3. Historial de análisis (solo si se solicita)
    if ($includeAnalysisHistory) {
        $historyContext = getAnalysisHistory($pdo, $user_id);
        if (!empty($historyContext)) {
            $context .= $historyContext;
            $contextSources[] = 'Historial de Análisis';
        }
    }
    
    // Construir prompt enriquecido
    $enrichedPrompt = $prompt;
    if (!empty($context)) {
        $enrichedPrompt = "Contexto relevante:\n" . $context . "\n\nAnálisis solicitado:\n" . $prompt;
    }
    
    // Llamar al proveedor de IA
    $aiResponse = callAIProvider($provider, $model, $enrichedPrompt, $systemPrompt);
    
    // Respuesta optimizada
    json_out([
        'text' => $aiResponse['text'],
        'provider' => $aiResponse['provider'],
        'model' => $aiResponse['model'],
        'context_sources' => $contextSources,
        'context_length' => strlen($context),
        'enriched_prompt_length' => strlen($enrichedPrompt),
        'optimization' => 'cached_knowledge_base'
    ]);
    
} catch (Exception $e) {
    error_log("Error en ai_analyze_hybrid_optimized.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}

// Función optimizada para Knowledge Base con caché simple
function getRelevantKnowledgeOptimized($pdo, $user_id, $prompt) {
    // Caché simple en memoria (solo para esta sesión)
    static $knowledgeCache = [];
    $cacheKey = md5($user_id . $prompt);
    
    if (isset($knowledgeCache[$cacheKey])) {
        return $knowledgeCache[$cacheKey];
    }
    
    // Extraer keywords de forma más eficiente
    $keywords = extractKeywordsFast($prompt);
    
    if (empty($keywords)) {
        return '';
    }
    
    // Búsqueda optimizada con LIMIT
    $keywordStr = implode('|', $keywords);
    $stmt = $pdo->prepare("
        SELECT title, summary, content 
        FROM knowledge_base 
        WHERE created_by = ? 
        AND is_active = 1
        AND (
            title REGEXP ? OR 
            summary REGEXP ? OR 
            content REGEXP ?
        )
        ORDER BY confidence_score DESC, usage_count DESC
        LIMIT 3
    ");
    $stmt->execute([$user_id, $keywordStr, $keywordStr, $keywordStr]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $context = '';
    foreach ($results as $result) {
        $context .= "• " . $result['title'] . ": " . $result['summary'] . "\n";
    }
    
    // Limitar longitud del contexto
    $context = substr($context, 0, 500);
    
    // Guardar en caché
    $knowledgeCache[$cacheKey] = $context;
    
    return $context;
}

// Función de extracción de keywords más rápida
function extractKeywordsFast($text) {
    // Limpiar texto
    $text = strtolower(trim($text));
    $text = preg_replace('/[^\w\s]/', ' ', $text);
    $words = array_filter(explode(' ', $text), function($word) {
        return strlen($word) > 3;
    });
    
    // Palabras clave de trading más comunes
    $tradingKeywords = ['trading', 'análisis', 'precio', 'mercado', 'tendencia', 'volatilidad', 'soporte', 'resistencia'];
    
    // Combinar palabras extraídas con keywords de trading
    $keywords = array_unique(array_merge($words, $tradingKeywords));
    
    // Limitar a 5 keywords máximo
    return array_slice($keywords, 0, 5);
}

// Función para patrones comportamentales (simplificada)
function getBehavioralPatterns($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT trading_style, risk_tolerance 
        FROM ai_behavioral_patterns 
        WHERE user_id = ? 
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $pattern = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($pattern) {
        return "Estilo de trading: " . $pattern['trading_style'] . ", Tolerancia al riesgo: " . $pattern['risk_tolerance'] . "\n";
    }
    
    return '';
}

// Función para historial de análisis (simplificada)
function getAnalysisHistory($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT symbol, outcome 
        FROM ai_analysis_history 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 3
    ");
    $stmt->execute([$user_id]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($history)) {
        $context = "Análisis recientes: ";
        foreach ($history as $item) {
            $context .= $item['symbol'] . " (" . $item['outcome'] . "), ";
        }
        return rtrim($context, ', ') . "\n";
    }
    
    return '';
}

// Función para llamar al proveedor de IA
function callAIProvider($provider, $model, $prompt, $systemPrompt) {
    // Implementación simplificada - usar el endpoint existente
    $data = [
        'provider' => $provider,
        'model' => $model,
        'prompt' => $prompt,
        'systemPrompt' => $systemPrompt
    ];
    
    // Llamada interna al endpoint de IA usando URL portable
    $baseUrl = getApiUrl('ai_analyze.php');
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $baseUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: ' . $_SERVER['HTTP_AUTHORIZATION']
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Para desarrollo
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        return json_decode($response, true);
    } else {
        throw new Exception('Error en proveedor de IA: ' . $httpCode);
    }
}
?>
