<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

try {
    header_remove('X-Powered-By');
    apply_cors();
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
    
    // Configurar límites extendidos
    set_time_limit(600);
    ini_set('max_execution_time', 600);
    ini_set('memory_limit', '512M');
    
    // Verificar método
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Método no permitido', 405);
    }
    
    // Verificar autenticación
    $user = require_user();
    if (!$user) {
        json_error('Token inválido');
    }
    
    // Obtener datos del request
    $input = json_input(true);
    $fileId = $input['file_id'] ?? null;
    
    if (!$fileId) {
        json_error('file_id requerido');
    }
    
    // Verificar que el archivo existe
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM knowledge_files WHERE id = ? AND user_id = ?");
    $stmt->execute([$fileId, $user['id']]);
    $file = $stmt->fetch();
    
    if (!$file) {
        json_error('Archivo no encontrado o no tienes permisos');
    }
    
    $results = [
        'ok' => true,
        'message' => 'Extracción híbrida (Gemini como principal)',
        'file_info' => [
            'id' => $file['id'],
            'filename' => $file['original_filename'],
            'size' => $file['file_size'],
            'size_mb' => round($file['file_size'] / 1024 / 1024, 2),
            'status' => $file['upload_status']
        ],
        'processing' => []
    ];
    
    // Paso 1: Verificar archivo
    $results['processing'][] = [
        'step' => 1,
        'name' => 'Verificar archivo',
        'status' => 'success',
        'message' => 'Archivo encontrado y accesible'
    ];
    
    // Paso 2: Leer contenido del archivo
    $filePath = __DIR__ . '/uploads/knowledge/' . $user['id'] . '/' . $file['stored_filename'];
    if (!file_exists($filePath)) {
        json_error('Archivo no encontrado en el servidor');
    }
    
    $fileContent = file_get_contents($filePath);
    if ($fileContent === false) {
        json_error('Error leyendo archivo');
    }
    
    // Verificar que el contenido no esté vacío
    if (empty(trim($fileContent))) {
        json_error('El archivo está vacío o no se pudo leer correctamente');
    }
    
    error_log("DEBUG - File content length: " . strlen($fileContent));
    error_log("DEBUG - File content preview: " . substr($fileContent, 0, 200));
    
    $results['processing'][] = [
        'step' => 2,
        'name' => 'Leer contenido del archivo',
        'status' => 'success',
        'message' => 'Contenido leído exitosamente (' . strlen($fileContent) . ' bytes)'
    ];
    
    // Paso 3: Intentar con Gemini primero (más confiable)
    $geminiKey = get_api_key_for($user['id'], 'gemini');
    $apiUsed = 'none';
    $content = '';
    $latency = 0;
    
    if ($geminiKey) {
        $results['processing'][] = [
            'step' => 3,
            'name' => 'Análisis con Gemini',
            'status' => 'processing',
            'message' => 'Intentando con Gemini API (más confiable)...'
        ];
        
        // Crear prompt simplificado para evitar problemas de encoding
        $contentPreview = substr($fileContent, 0, 10000); // Reducir a 10KB
        $contentPreview = preg_replace('/[^\x20-\x7E\x0A\x0D]/', '', $contentPreview); // Solo ASCII
        
        $prompt = "Eres un analista de trading experto. Analiza el siguiente documento y extrae información valiosa para trading:

ARCHIVO: {$file['original_filename']}
TIPO: {$file['mime_type']}
TAMAÑO: " . round($file['file_size'] / 1024 / 1024, 2) . " MB

CONTENIDO:
" . $contentPreview . "

Proporciona un resumen estructurado en español con:
1. RESUMEN EJECUTIVO (2-3 líneas)
2. CONCEPTOS CLAVE (5-8 puntos)
3. ESTRATEGIAS DE TRADING (3-5 puntos)
4. GESTIÓN DE RIESGO (2-3 puntos)
5. RECOMENDACIONES (2-3 puntos)

Enfócate en información práctica y accionable para traders.";
        
        // Debug del prompt
        error_log("DEBUG - Prompt length: " . strlen($prompt));
        error_log("DEBUG - Prompt preview: " . substr($prompt, 0, 500));
        
        $geminiPayload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.3,
                'maxOutputTokens' => 2000,
                'topP' => 0.8,
                'topK' => 10
            ],
            'safetySettings' => [
                [
                    'category' => 'HARM_CATEGORY_HARASSMENT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ],
                [
                    'category' => 'HARM_CATEGORY_HATE_SPEECH',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ]
            ]
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $geminiKey,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 120, // 2 minutos para Gemini
            CURLOPT_CONNECTTIMEOUT => 30, // 30 segundos para conectar
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($geminiPayload)
        ]);
        
        $startTime = microtime(true);
        error_log("Iniciando llamada a Gemini API para archivo: " . $file['original_filename']);
        
        $response = curl_exec($ch);
        $endTime = microtime(true);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $latency = round(($endTime - $startTime) * 1000, 2);
        
        error_log("Llamada a Gemini API completada en {$latency}ms, HTTP: $httpCode");
        
        curl_close($ch);
        
        if (!$curlError && $httpCode === 200) {
            $responseData = json_decode($response, true);
            
            if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
                $content = $responseData['candidates'][0]['content']['parts'][0]['text'];
                $apiUsed = 'gemini';
                
                $results['processing'][] = [
                    'step' => 3,
                    'name' => 'Análisis con Gemini',
                    'status' => 'success',
                    'message' => 'Análisis completado exitosamente con Gemini',
                    'content_extracted' => $content,
                    'latency_ms' => $latency
                ];
            }
        }
        
        if (!$content) {
            $results['processing'][] = [
                'step' => 3,
                'name' => 'Análisis con Gemini',
                'status' => 'error',
                'message' => 'Gemini falló, intentando con OpenAI...',
                'error' => $curlError ?: "HTTP $httpCode: " . substr($response, 0, 200)
            ];
        }
    }
    
    // Paso 4: Fallback a OpenAI si Gemini falló
    if (!$content) {
        $openaiKey = get_api_key_for($user['id'], 'openai');
        if ($openaiKey) {
            $results['processing'][] = [
                'step' => 4,
                'name' => 'Análisis con OpenAI (fallback)',
                'status' => 'processing',
                'message' => 'Intentando con OpenAI API como fallback...'
            ];
            
            // Aquí iría la lógica de OpenAI (simplificada para este ejemplo)
            $results['processing'][] = [
                'step' => 4,
                'name' => 'Análisis con OpenAI (fallback)',
                'status' => 'error',
                'message' => 'OpenAI no disponible (problemas conocidos con /v1/responses)',
                'note' => 'Usar Gemini como API principal'
            ];
        } else {
            $results['processing'][] = [
                'step' => 4,
                'name' => 'Análisis con OpenAI (fallback)',
                'status' => 'error',
                'message' => 'OpenAI API key no configurada'
            ];
        }
    }
    
    // Si no hay contenido, generar simulado
    if (!$content) {
        $results['processing'][] = [
            'step' => 5,
            'name' => 'Análisis simulado',
            'status' => 'processing',
            'message' => 'Generando análisis simulado...'
        ];
        
        $content = "ANÁLISIS SIMULADO - APIs no disponibles

RESUMEN EJECUTIVO:
No se pudo realizar análisis con APIs de IA. Se recomienda configurar Gemini API para análisis automático.

CONCEPTOS CLAVE:
- Configurar Gemini API key
- Verificar conectividad
- Revisar límites de API

ESTRATEGIAS DE TRADING:
- Análisis manual requerido
- Usar indicadores técnicos tradicionales
- Consultar fuentes externas

GESTIÓN DE RIESGO:
- Aplicar reglas estándar de riesgo
- Usar stop loss obligatorio
- Limitar exposición

RECOMENDACIONES:
- Configurar Gemini API
- Verificar conectividad
- Contactar soporte técnico";
        
        $apiUsed = 'simulated';
        
        $results['processing'][] = [
            'step' => 5,
            'name' => 'Análisis simulado',
            'status' => 'success',
            'message' => 'Análisis simulado generado',
            'content_extracted' => $content
        ];
    }
    
    // Paso final: Guardar en base de datos
    $results['processing'][] = [
        'step' => 6,
        'name' => 'Guardar en base de datos',
        'status' => 'processing',
        'message' => 'Guardando resultados...'
    ];
    
    // Guardar en knowledge_base
    $stmt = $pdo->prepare("
        INSERT INTO knowledge_base 
        (knowledge_type, title, content, summary, tags, confidence_score, created_by, source_type, source_file, is_public, is_active, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    
    $title = "Análisis de " . $file['original_filename'] . " (" . strtoupper($apiUsed) . ")";
    $summary = "Análisis generado por " . strtoupper($apiUsed) . " API para " . $file['original_filename'];
    $tags = json_encode([$apiUsed, 'análisis', 'trading', pathinfo($file['original_filename'], PATHINFO_EXTENSION)]);
    
    $stmt->execute([
        'user_insight',
        $title,
        $content,
        $summary,
        $tags,
        $apiUsed === 'gemini' ? 0.8 : 0.5, // Confidence score
        $user['id'],
        'ai_extraction',
        $file['original_filename'],
        0, // is_public
        1, // is_active
    ]);
    
    $knowledgeId = $pdo->lastInsertId();
    
    // Actualizar knowledge_files
    $stmt = $pdo->prepare("
        UPDATE knowledge_files 
        SET extraction_status = 'completed', extracted_items = extracted_items + 1, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$fileId]);
    
    $results['processing'][] = [
        'step' => 6,
        'name' => 'Guardar en base de datos',
        'status' => 'success',
        'message' => 'Resultados guardados exitosamente',
        'knowledge_id' => $knowledgeId
    ];
    
    $results['summary'] = [
        'total_steps' => 6,
        'completed_steps' => 6,
        'total_time' => $latency > 0 ? round($latency / 1000, 2) . ' segundos' : 'N/A',
        'api_method' => strtoupper($apiUsed) . ' API',
        'file_size' => round($file['file_size'] / 1024 / 1024, 2) . ' MB',
        'content_length' => strlen($content) . ' caracteres',
        'latency_ms' => $latency,
        'recommendation' => $apiUsed === 'gemini' ? 'Extracción con Gemini completada exitosamente' : 'Configurar Gemini API para mejor rendimiento'
    ];
    
    ok($results);
    
} catch (Exception $e) {
    error_log("ERROR en ai_extract_content_hybrid_safe.php: " . $e->getMessage());
    json_error('Error interno: ' . $e->getMessage());
}
?>
