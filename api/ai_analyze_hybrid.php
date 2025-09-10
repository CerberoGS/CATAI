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

// Verificar método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método no permitido', 405);
}

// Obtener datos del request
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    json_error('Datos JSON inválidos');
}

$provider = $input['provider'] ?? 'auto';
$model = $input['model'] ?? null;
$prompt = $input['prompt'] ?? '';
$systemPrompt = $input['systemPrompt'] ?? '';
$useKnowledgeBase = $input['useKnowledgeBase'] ?? true;
$includeBehavioralPatterns = $input['includeBehavioralPatterns'] ?? true;
$includeAnalysisHistory = $input['includeAnalysisHistory'] ?? true;
$includeFiles = $input['includeFiles'] ?? [];

if (empty($prompt)) {
    json_error('Prompt requerido');
}

try {
    $pdo = db();
    
    // Inicializar contexto
    $contextSources = [];
    $contextLength = 0;
    $enrichedPrompt = $prompt;
    
    if ($useKnowledgeBase) {
        // 1. Obtener conocimiento relevante de la Knowledge Base (SIEMPRE incluido)
        $knowledgeContext = getRelevantKnowledge($pdo, $user_id, $prompt);
        if ($knowledgeContext) {
            $enrichedPrompt .= "\n\n=== CONOCIMIENTO RELEVANTE ===\n" . $knowledgeContext['content'];
            $contextSources[] = 'Knowledge Base';
            $contextLength += strlen($knowledgeContext['content']);
        }
        
        // 2. Obtener patrones comportamentales del usuario (solo si se solicita)
        if ($includeBehavioralPatterns) {
            $behavioralPatterns = getBehavioralPatterns($pdo, $user_id);
            if ($behavioralPatterns) {
                $enrichedPrompt .= "\n\n=== PATRONES COMPORTAMENTALES ===\n" . $behavioralPatterns;
                $contextSources[] = 'Patrones Comportamentales';
                $contextLength += strlen($behavioralPatterns);
            }
        }
        
        // 3. Obtener historial de análisis exitosos (solo si se solicita)
        if ($includeAnalysisHistory) {
            $analysisHistory = getAnalysisHistory($pdo, $user_id);
            if ($analysisHistory) {
                $enrichedPrompt .= "\n\n=== HISTORIAL DE ANÁLISIS ===\n" . $analysisHistory;
                $contextSources[] = 'Historial de Análisis';
                $contextLength += strlen($analysisHistory);
            }
        }
        
        // 4. Incluir archivos específicos si se solicitan
        if (!empty($includeFiles)) {
            $fileContext = getFileContext($pdo, $user_id, $includeFiles);
            if ($fileContext) {
                $enrichedPrompt .= "\n\n=== ARCHIVOS ESPECÍFICOS ===\n" . $fileContext;
                $contextSources[] = 'Archivos Específicos';
                $contextLength += strlen($fileContext);
            }
        }
    }
    
    // Llamar al endpoint de IA tradicional con el prompt enriquecido
    $aiRequest = [
        'provider' => $provider,
        'model' => $model,
        'prompt' => $enrichedPrompt,
        'systemPrompt' => $systemPrompt
    ];
    
    // Simular llamada al endpoint de IA (en producción sería una llamada HTTP interna)
    $aiResponse = callAIAnalysis($aiRequest);
    
    // Preparar respuesta
    $response = [
        'ok' => true,
        'text' => $aiResponse['text'] ?? '',
        'provider' => $aiResponse['provider'] ?? $provider,
        'model' => $aiResponse['model'] ?? $model,
        'context_sources' => $contextSources,
        'context_length' => $contextLength,
        'enriched_prompt_length' => strlen($enrichedPrompt),
        'original_prompt_length' => strlen($prompt)
    ];
    
    // Log del análisis híbrido
    error_log("AI Hybrid Analysis - User: $user_id, Provider: $provider, Mode: " . ($includeBehavioralPatterns ? 'Complete' : 'Knowledge') . ", Context Sources: " . implode(', ', $contextSources) . ", Context Length: $contextLength");
    
    json_out($response);
    
} catch (Exception $e) {
    error_log("Error en ai_analyze_hybrid.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}

// Funciones auxiliares

function getRelevantKnowledge($pdo, $user_id, $prompt) {
    try {
        // Usar la función mejorada de extracción de palabras clave
        $keywords = extractKeywordsImproved($prompt);
        
        error_log("DEBUG getRelevantKnowledge - user_id: $user_id, keywords: " . json_encode($keywords));
        
        if (empty($keywords)) {
            error_log("DEBUG getRelevantKnowledge - No keywords extracted");
            return null;
        }
        
        $context = [];
        $totalLength = 0;
        
        // Buscar con cada palabra clave
        foreach ($keywords as $keyword) {
            $sql = "SELECT id, title, content, summary, confidence_score 
                    FROM knowledge_base 
                    WHERE created_by = ? AND is_active = 1 
                    AND (title LIKE ? OR content LIKE ? OR summary LIKE ?)
                    ORDER BY confidence_score DESC, usage_count DESC
                    LIMIT 3";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$user_id, "%{$keyword}%", "%{$keyword}%", "%{$keyword}%"]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("DEBUG getRelevantKnowledge - Búsqueda para '{$keyword}': " . count($results) . " resultados");
            
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
        
        if (empty($context)) {
            error_log("DEBUG getRelevantKnowledge - No context found");
            return null;
        }
        
        $contextText = "Conocimiento relevante encontrado:\n";
        foreach ($context as $row) {
            $contextText .= "- " . $row['title'] . ": " . $row['summary'] . "\n";
        }
        
        error_log("DEBUG getRelevantKnowledge - Context generated: " . count($context) . " sources, " . strlen($contextText) . " chars");
        
        return ['content' => $contextText];
        
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

function getFileContext($pdo, $user_id, $fileIds) {
    try {
        $placeholders = str_repeat('?,', count($fileIds) - 1) . '?';
        $sql = "SELECT kf.original_filename, kb.content 
                FROM knowledge_files kf 
                JOIN knowledge_base kb ON kb.source_file = kf.original_filename 
                WHERE kf.user_id = ? AND kf.id IN ($placeholders)";
        
        $params = array_merge([$user_id], $fileIds);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($files)) {
            return null;
        }
        
        $context = "Contenido de archivos específicos:\n";
        foreach ($files as $file) {
            $context .= "- " . $file['original_filename'] . ": " . substr($file['content'], 0, 200) . "...\n";
        }
        
        return $context;
        
    } catch (Exception $e) {
        error_log("Error obteniendo contexto de archivos: " . $e->getMessage());
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

function extractKeywordsImproved($text) {
    // Función mejorada de extracción de palabras clave (copiada del debug_keyword_extraction.php)
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

function callAIAnalysis($request) {
    // En producción, aquí harías una llamada HTTP interna al endpoint ai_analyze.php
    // Por ahora, simulamos la respuesta
    
    $response = [
        'text' => "Análisis híbrido completado usando contexto enriquecido.\n\n" . 
                  "Prompt original: " . substr($request['prompt'], 0, 100) . "...\n" .
                  "Prompt enriquecido: " . strlen($request['prompt']) . " caracteres\n" .
                  "Proveedor: " . $request['provider'] . "\n" .
                  "Modelo: " . ($request['model'] ?: 'auto'),
        'provider' => $request['provider'],
        'model' => $request['model']
    ];
    
    return $response;
}
?>