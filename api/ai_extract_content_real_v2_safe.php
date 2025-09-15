<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

// Función helper robusta para operaciones DB con reconexión automática
function dbExecute(&$pdo, $sql, $params = []) {
    $maxRetries = 3;
    $retryDelay = 100; // ms
    
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            // Verificar conexión antes de cada operación
            $pdo->query('SELECT 1');
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            // Log de éxito si fue después de una reconexión
            if ($attempt > 1) {
                error_log("DB operación exitosa después de $attempt intentos");
            }
            
            return $stmt;
            
        } catch (PDOException $e) {
            // Si es "MySQL server has gone away" y no es el último intento
            if (strpos($e->getMessage(), 'MySQL server has gone away') !== false && $attempt < $maxRetries) {
                // Log del intento de reconexión
                error_log("DB reconexión intento $attempt: " . $e->getMessage());
                
                // Log adicional para debugging
                error_log("DB reconexión intento $attempt para consulta: " . substr($sql, 0, 100) . "...");
                
                // Recrear conexión PDO y actualizar la referencia
                global $CONFIG;
                $dsn = "mysql:host={$CONFIG['DB_HOST']};dbname={$CONFIG['DB_NAME']};charset=utf8mb4";
                $pdo = new PDO($dsn, $CONFIG['DB_USER'], $CONFIG['DB_PASS'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                
                // Esperar antes del siguiente intento
                usleep($retryDelay * 1000);
                $retryDelay *= 2; // Backoff exponencial
                
                continue;
            }
            
            // Si no es "MySQL server has gone away" o es el último intento, relanzar
            throw $e;
        }
    }
    
    throw new Exception("No se pudo ejecutar la consulta después de $maxRetries intentos");
}

try {
    header_remove('X-Powered-By');
    apply_cors();
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

    // Capturar cualquier output no deseado
    ob_start();

    // Autenticación
    $user = require_user();
    
    // Parámetros - leer del JSON body si es POST
    $input = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['CONTENT_TYPE']) && 
        strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true);
    }
    
    $knowledgeId = $_GET['knowledge_id'] ?? $_POST['knowledge_id'] ?? $input['knowledge_id'] ?? null;
    $fileId = $_GET['file_id'] ?? $_POST['file_id'] ?? $input['file_id'] ?? null;
    $provider = $_GET['provider'] ?? $_POST['provider'] ?? $input['provider'] ?? 'openai';
    $model = $_GET['model'] ?? $_POST['model'] ?? $input['model'] ?? 'gpt-4o-mini';
    
    if (!$knowledgeId && !$fileId) {
        json_error('knowledge_id o file_id requerido', 400);
    }

    require_once __DIR__ . '/db.php';
    $pdo = db();
    
    // 1) Obtener configuración del usuario
    $stmt = dbExecute($pdo, 'SELECT ai_provider, ai_model FROM user_settings WHERE user_id = ?', [$user['id']]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $aiProvider = $settings['ai_provider'] ?? 'openai';
    $aiModel = $settings['ai_model'] ?? 'gpt-4o-mini';
    
    // Si el modelo está en 'auto' o vacío, usar gpt-4o-mini por defecto
    if (empty($aiModel) || $aiModel === 'auto') {
        $aiModel = 'gpt-4o-mini';
    }
    
    // 2) Obtener información del archivo
    $kf = null;
    if ($fileId) {
        $stmt = dbExecute($pdo, 'SELECT * FROM knowledge_files WHERE id = ? AND user_id = ?', [$fileId, $user['id']]);
        $kf = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$kf && $knowledgeId) {
        // Fallback: buscar por knowledge_id
        $stmt = dbExecute($pdo, 'SELECT kf.* FROM knowledge_files kf 
                              JOIN knowledge_base kb ON kf.original_filename = kb.source_file 
                              WHERE kb.id = ? AND kf.user_id = ?', [$knowledgeId, $user['id']]);
        $kf = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$kf) {
        json_error('Archivo no encontrado', 404);
    }
    
    // 3) Construir ruta del archivo
    $filePath = __DIR__ . '/uploads/knowledge/' . (int)$user['id'] . '/' . $kf['stored_filename'];
    
    if (!file_exists($filePath) || !is_readable($filePath)) {
        json_error('Archivo no accesible: ' . basename($filePath), 404);
    }
    
    // 4) Verificar si ya tenemos openai_file_id
    $openaiFileId = $kf['openai_file_id'] ?? null;
    
    // 4) Obtener API key del usuario usando la función existente
    require_once __DIR__ . '/helpers.php';
    $apiKey = get_api_key_for($user['id'], $aiProvider);
    
    if (!$apiKey) {
        json_error("API key no encontrada para proveedor: $aiProvider", 400);
    }
    
    // 5) Verificar y subir archivo a OpenAI según checklist
    $fileUploadInfo = [];
    $startTime = microtime(true);
    
    // Marcar inicio de extracción
    dbExecute($pdo, '
        UPDATE knowledge_files 
        SET extraction_status = ?, last_extraction_started_at = NOW(), extraction_attempts = extraction_attempts + 1
        WHERE id = ?
    ', ['processing', $kf['id']]);
    
    // A. Verificar si el archivo existe en OpenAI (si tenemos openai_file_id)
    if ($openaiFileId) {
        $fileUploadInfo['action'] = 'verifying_existing_file';
        $fileUploadInfo['openai_file_id'] = $openaiFileId;
        
        // Verificar con GET /v1/files/{id}
        $ch = curl_init("https://api.openai.com/v1/files/$openaiFileId");
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $apiKey"],
            CURLOPT_RETURNTRANSFER => true
        ]);
        
        $verifyResponse = curl_exec($ch);
        $verifyHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $fileUploadInfo['verify_response_code'] = $verifyHttpCode;
        $fileUploadInfo['verify_response'] = $verifyResponse;
        
        // Si el archivo no existe (404), necesitamos subirlo de nuevo
        if ($verifyHttpCode === 404) {
            $fileUploadInfo['action'] = 'file_not_found_reuploading';
            $openaiFileId = null; // Reset para forzar re-upload
        } else if ($verifyHttpCode !== 200) {
            // Marcar error y salir
            dbExecute($pdo, '
                UPDATE knowledge_files 
                SET extraction_status = ?, last_error = ?, last_extraction_finished_at = NOW()
                WHERE id = ?
            ', ['failed', "Error verificando archivo en OpenAI ($verifyHttpCode): $verifyResponse", $kf['id']]);
            json_error("Error verificando archivo en OpenAI ($verifyHttpCode): $verifyResponse", 500);
        } else {
            $fileUploadInfo['action'] = 'using_existing_file';
            // Actualizar verificación exitosa
            dbExecute($pdo, 'UPDATE knowledge_files SET openai_file_verified_at = NOW() WHERE id = ?', [$kf['id']]);
        }
    }
    
    // B. Subir archivo si no tenemos openai_file_id o si la verificación falló
    if (!$openaiFileId) {
        $fileUploadInfo['action'] = 'uploading_new_file';
        $fileUploadInfo['file_path'] = $filePath;
        $fileUploadInfo['file_size_mb'] = round(filesize($filePath) / 1024 / 1024, 2);
        
        $ch = curl_init('https://api.openai.com/v1/files');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $apiKey"],
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => [
                'purpose' => 'assistants', // o 'user_data', ambos válidos
                'file' => new CURLFile($filePath, 'application/pdf', basename($filePath))
            ]
        ]);
        
        $fileResponse = curl_exec($ch);
        $fileHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $fileUploadInfo['upload_response_code'] = $fileHttpCode;
        $fileUploadInfo['upload_response'] = $fileResponse;
        
        if ($fileHttpCode !== 200) {
            json_error("Error subiendo archivo a OpenAI ($fileHttpCode): $fileResponse", 500);
        }
        
        $fileResult = json_decode($fileResponse, true);
        if (!isset($fileResult['id'])) {
            json_error('Respuesta inválida de OpenAI Files API: ' . $fileResponse, 500);
        }
        
        $openaiFileId = $fileResult['id'];
        $fileUploadInfo['openai_file_id'] = $openaiFileId;
        
        // Guardar el openai_file_id y marcar como verificado
        dbExecute($pdo, '
            UPDATE knowledge_files 
            SET openai_file_id = ?, openai_file_verified_at = NOW() 
            WHERE id = ?
        ', [$openaiFileId, $kf['id']]);
        $fileUploadInfo['database_updated'] = true;
    }
    
    // 6) Armar consulta con Responses API
    $schema = [
        "type" => "object",
        "required" => ["resumen", "puntos_clave", "estrategias", "gestion_riesgo", "recomendaciones"],
        "properties" => [
            "resumen" => ["type" => "string", "description" => "Resumen ejecutivo en 2-3 líneas"],
            "puntos_clave" => ["type" => "array", "items" => ["type" => "string"], "description" => "5-8 conceptos clave"],
            "estrategias" => ["type" => "array", "items" => ["type" => "string"], "description" => "3-5 estrategias de trading"],
            "gestion_riesgo" => ["type" => "array", "items" => ["type" => "string"], "description" => "2-3 puntos de gestión de riesgo"],
            "recomendaciones" => ["type" => "array", "items" => ["type" => "string"], "description" => "2-3 recomendaciones prácticas"]
        ],
        "additionalProperties" => false
    ];
    
    $payload = [
        "model" => $aiModel,
        "temperature" => 0.3,
        "max_output_tokens" => 1800,
        "text" => [
            "format" => [
                "type" => "json_schema",
                "name" => "knowledge_card",
                "schema" => $schema
            ]
        ],
        "input" => [[
            "role" => "user",
            "content" => [
                ["type" => "input_text", "text" => "Eres un analista de trading experto. Analiza este documento y extrae información valiosa para trading. Responde en español siguiendo exactamente el esquema JSON proporcionado. No inventes información que no esté en el documento."],
                ["type" => "input_file", "file_id" => $openaiFileId]
            ]
        ]]
    ];
    
    // 7) Llamar a OpenAI Responses API
    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json"
        ],
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 60
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    $apiCallInfo = [
        'endpoint' => 'https://api.openai.com/v1/responses',
        'payload' => $payload,
        'response_code' => $httpCode,
        'response_body' => $response,
        'curl_error' => $error
    ];
    
    if ($error) {
        json_error("Error cURL: $error", 500);
    }
    
    if ($httpCode !== 200) {
        json_error("Error OpenAI API ($httpCode): $response", 500);
    }
    
    // C. Parseo robusto de la respuesta según checklist
    $aiResponse = json_decode($response, true);
    if (!$aiResponse) {
        json_error("Respuesta inválida de OpenAI (no es JSON): " . $response, 500);
    }
    
    $summaryJson = null;
    
    // Si hay error en el JSON de OpenAI → reenvíalo al frontend (no lo tapes)
    if (isset($aiResponse['error'])) {
        json_error("Error de OpenAI: " . json_encode($aiResponse['error']), 500);
    }
    
    // Si output_text existe → úsalo
    if (isset($aiResponse['output_text'])) {
        $summaryJson = json_decode($aiResponse['output_text'], true);
        if (!$summaryJson) {
            json_error("JSON inválido en output_text: " . $aiResponse['output_text'], 500);
        }
    } 
    // Si no, concatena los text que haya dentro de output[].content[]
    else if (isset($aiResponse['output']) && is_array($aiResponse['output'])) {
        $textContent = '';
        foreach ($aiResponse['output'] as $output) {
            if (isset($output['content']) && is_array($output['content'])) {
                foreach ($output['content'] as $content) {
                    if (isset($content['text'])) {
                        $textContent .= $content['text'];
                    }
                }
            }
        }
        
        if ($textContent) {
            $summaryJson = json_decode($textContent, true);
            if (!$summaryJson) {
                json_error("JSON inválido en contenido concatenado: " . $textContent, 500);
            }
        } else {
            json_error("No se encontró contenido de texto en la respuesta", 500);
        }
    } else {
        json_error("Respuesta de OpenAI sin formato esperado: " . json_encode($aiResponse), 500);
    }
    
    // 8) Calcular métricas y actualizar knowledge_files con trazabilidad completa
    $endTime = microtime(true);
    $latencyMs = round(($endTime - $startTime) * 1000);
    
    // Calcular costo (tarifa actual de gpt-4o: $0.005 por 1K tokens)
    $costPer1kTokens = 0.005;
    $totalTokens = $aiResponse['usage']['total_tokens'] ?? 0;
    $costUsd = ($totalTokens / 1000) * $costPer1kTokens;
    
    // Actualizar knowledge_files con toda la información de trazabilidad
    $extractedItems = count($summaryJson['puntos_clave'] ?? []) + 
                     count($summaryJson['estrategias'] ?? []) + 
                     count($summaryJson['gestion_riesgo'] ?? []) + 
                     count($summaryJson['recomendaciones'] ?? []);
    
    dbExecute($pdo, '
        UPDATE knowledge_files 
        SET 
            extraction_status = ?,
            extracted_items = ?,
            last_extraction_finished_at = NOW(),
            last_extraction_model = ?,
            last_extraction_response_id = ?,
            last_extraction_input_tokens = ?,
            last_extraction_output_tokens = ?,
            last_extraction_total_tokens = ?,
            last_extraction_cost_usd = ?,
            last_error = NULL
        WHERE id = ?
    ', [
        'completed',
        $extractedItems,
        $aiModel,
        $aiResponse['id'] ?? null,
        $aiResponse['usage']['input_tokens'] ?? 0,
        $aiResponse['usage']['output_tokens'] ?? 0,
        $totalTokens,
        $costUsd,
        $kf['id']
    ]);
    
    // 9) Preparar datos para guardar en knowledge_base (SOLO SIMULACIÓN)
    $title = $kf['original_filename'] . ' - Análisis IA';
    $content = json_encode($summaryJson, JSON_UNESCAPED_UNICODE);
    $summary = $summaryJson['resumen'] ?? 'Resumen generado por IA';
    $tags = json_encode(['ai_extraction', 'trading', 'pdf'], JSON_UNESCAPED_UNICODE);
    
    $extractedItems = count($summaryJson['puntos_clave'] ?? []) + 
                     count($summaryJson['estrategias'] ?? []) + 
                     count($summaryJson['gestion_riesgo'] ?? []) + 
                     count($summaryJson['recomendaciones'] ?? []);
    
    // SIMULACIÓN: No guardamos realmente, solo preparamos los datos
    $knowledgeId = 'SIMULADO_' . time(); // ID simulado para la demo
    
    // Limpiar output buffer
    $leak = ob_get_clean();
    
    json_out([
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'file' => [
            'original_filename' => $kf['original_filename'],
            'stored_filename' => $kf['stored_filename'],
            'file_type' => $kf['file_type'],
            'size_mb' => round($kf['file_size'] / 1024 / 1024, 2)
        ],
        'ai' => [
            'provider' => $aiProvider,
            'model' => $aiModel,
            'openai_file_id' => $openaiFileId
        ],
        'summary' => $summaryJson,
        '🔍 DEBUG: INFORMACIÓN DE SUBIDA DE ARCHIVO' => $fileUploadInfo,
        '🔍 DEBUG: INFORMACIÓN DE LLAMADA A API' => $apiCallInfo,
        '📊 TRAZABILIDAD: MÉTRICAS DE EXTRACCIÓN' => [
            'latency_ms' => $latencyMs,
            'total_tokens' => $totalTokens,
            'cost_usd' => $costUsd,
            'model' => $aiModel,
            'response_id' => $aiResponse['id'] ?? null,
            'extracted_items' => $extractedItems,
            'status' => 'completed'
        ],
        '📋 SIMULACIÓN: DATOS QUE SE GUARDARÍAN EN KNOWLEDGE_BASE' => [
            'tabla' => 'knowledge_base',
            'operacion' => 'INSERT',
            'datos' => [
                'knowledge_type' => 'user_insight',
                'title' => $title,
                'content' => $content,
                'summary' => $summary,
                'tags' => json_decode($tags, true),
                'confidence_score' => 0.8,
                'created_by' => $user['id'],
                'source_file' => $kf['original_filename'],
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s')
            ],
            'knowledge_id_simulado' => $knowledgeId
        ],
        '📋 SIMULACIÓN: DATOS QUE SE ACTUALIZARÍAN EN KNOWLEDGE_FILES' => [
            'tabla' => 'knowledge_files',
            'operacion' => 'UPDATE',
            'where' => "id = {$kf['id']}",
            'datos' => [
                'extraction_status' => 'completed',
                'extracted_items' => $extractedItems,
                'openai_file_id' => $openaiFileId,
                'updated_at' => date('Y-m-d H:i:s')
            ]
        ],
        'leak' => $leak
    ]);

} catch (Throwable $e) {
    if (ob_get_level()) ob_end_clean();
    
    // Marcar error en knowledge_files si tenemos el ID
    if (isset($kf['id'])) {
        try {
            dbExecute($pdo, '
                UPDATE knowledge_files 
                SET extraction_status = ?, last_error = ?, last_extraction_finished_at = NOW()
                WHERE id = ?
            ', ['failed', $e->getMessage(), $kf['id']]);
        } catch (Throwable $dbError) {
            // Log del error de DB pero no fallar
            error_log("Error actualizando knowledge_files: " . $dbError->getMessage());
        }
    }
    
    json_error('Error interno: ' . $e->getMessage(), 500);
}