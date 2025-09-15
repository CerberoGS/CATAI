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
    
    // Obtener API key de Gemini
    $geminiKey = get_api_key_for($user['id'], 'gemini');
    if (!$geminiKey) {
        json_error('Gemini API key no configurada');
    }
    
    $results = [
        'ok' => true,
        'message' => 'Extracción con Gemini API',
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
    
    $results['processing'][] = [
        'step' => 2,
        'name' => 'Leer contenido del archivo',
        'status' => 'success',
        'message' => 'Contenido leído exitosamente (' . strlen($fileContent) . ' bytes)'
    ];
    
    // Paso 3: Preparar prompt para Gemini
    $prompt = "Eres un analista de trading experto. Analiza el siguiente documento y extrae información valiosa para trading:

ARCHIVO: {$file['original_filename']}
TIPO: {$file['mime_type']}
TAMAÑO: " . round($file['file_size'] / 1024 / 1024, 2) . " MB

CONTENIDO:
" . substr($fileContent, 0, 50000) . " // Truncado a 50KB para evitar límites

Proporciona un resumen estructurado en español con:
1. RESUMEN EJECUTIVO (2-3 líneas)
2. CONCEPTOS CLAVE (5-8 puntos)
3. ESTRATEGIAS DE TRADING (3-5 puntos)
4. GESTIÓN DE RIESGO (2-3 puntos)
5. RECOMENDACIONES (2-3 puntos)

Enfócate en información práctica y accionable para traders.";
    
    $results['processing'][] = [
        'step' => 3,
        'name' => 'Preparar prompt para Gemini',
        'status' => 'success',
        'message' => 'Prompt preparado con ' . strlen($prompt) . ' caracteres'
    ];
    
    // Paso 4: Llamar a Gemini API
    $results['processing'][] = [
        'step' => 4,
        'name' => 'Análisis con Gemini',
        'status' => 'processing',
        'message' => 'Enviando a Gemini API...'
    ];
    
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
    
    if ($curlError) {
        if (strpos($curlError, 'timeout') !== false) {
            json_error('Timeout en llamada a Gemini API. Intenta con un archivo más pequeño.');
        }
        json_error('Error cURL: ' . $curlError);
    }
    
    if ($httpCode !== 200) {
        json_error('Error Gemini API (HTTP ' . $httpCode . '): ' . $response);
    }
    
    $responseData = json_decode($response, true);
    
    if (!isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        json_error('Respuesta de Gemini sin contenido: ' . $response);
    }
    
    $content = $responseData['candidates'][0]['content']['parts'][0]['text'];
    
    $results['processing'][] = [
        'step' => 4,
        'name' => 'Análisis con Gemini',
        'status' => 'success',
        'message' => 'Análisis completado exitosamente',
        'content_extracted' => $content,
        'latency_ms' => $latency
    ];
    
    // Paso 5: Guardar en base de datos
    $results['processing'][] = [
        'step' => 5,
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
    
    $title = "Análisis de " . $file['original_filename'];
    $summary = "Análisis generado por Gemini API para " . $file['original_filename'];
    $tags = json_encode(['gemini', 'análisis', 'trading', pathinfo($file['original_filename'], PATHINFO_EXTENSION)]);
    
    $stmt->execute([
        'user_insight',
        $title,
        $content,
        $summary,
        $tags,
        0.8, // Confidence score
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
        'step' => 5,
        'name' => 'Guardar en base de datos',
        'status' => 'success',
        'message' => 'Resultados guardados exitosamente',
        'knowledge_id' => $knowledgeId
    ];
    
    $results['summary'] = [
        'total_steps' => 5,
        'completed_steps' => 5,
        'total_time' => round(($endTime - $startTime), 2) . ' segundos',
        'api_method' => 'Gemini API (gemini-1.5-flash)',
        'file_size' => round($file['file_size'] / 1024 / 1024, 2) . ' MB',
        'content_length' => strlen($content) . ' caracteres',
        'latency_ms' => $latency,
        'recommendation' => 'Extracción con Gemini completada exitosamente'
    ];
    
    ok($results);
    
} catch (Exception $e) {
    error_log("ERROR en ai_extract_content_gemini_safe.php: " . $e->getMessage());
    json_error('Error interno: ' . $e->getMessage());
}
?>
