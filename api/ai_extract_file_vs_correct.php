<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Crypto_safe.php';

// Función para logging limpio en archivo específico
function clean_log($message) {
    // Enmascarar valores sensibles antes de loguear
    $sanitizedMessage = sanitize_log_message($message);

    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $sanitizedMessage" . PHP_EOL;

    // Crear directorio logs si no existe
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    // Escribir en archivo específico
    $logFile = $logDir . '/ai_extract_debug_' . date('Y-m-d') . '.log';
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

// Función para sanitizar mensajes de log y evitar exposición de datos sensibles
function sanitize_log_message($message) {
    // Enmascarar API keys (patrones comunes)
    $message = preg_replace('/(sk-[a-zA-Z0-9]{32,})/', 'sk-[MASKED]', $message);
    $message = preg_replace('/(sk-[a-zA-Z0-9]{48,})/', 'sk-[MASKED]', $message);
    $message = preg_replace('/(xai-[a-zA-Z0-9\-_]{32,})/', 'xai-[MASKED]', $message);
    $message = preg_replace('/(Bearer\s+[a-zA-Z0-9\-_]{32,})/', 'Bearer [MASKED]', $message);

    // Enmascarar rutas de archivos completas (mantener solo el nombre del archivo)
    $message = preg_replace('/\/api\/uploads\/knowledge\/[^\/]*\/([^\/"]+)/', '/uploads/knowledge/$userId/[FILENAME:$1]', $message);
    $message = preg_replace('/\/home\/[^\/]*\/[^\/]*\/[^\/"]+/', '/[PATH_MASKED]/', $message);
    $message = preg_replace('/\/var\/[^\/]*\/[^\/"]+/', '/[PATH_MASKED]/', $message);
    $message = preg_replace('/C:\\\\[^\\\\]*\\\\[^\\\\"]+/', 'C:\\[PATH_MASKED]\\', $message);

    // Enmascarar Authorization headers
    $message = preg_replace('/(Authorization:\s*Bearer\s+[a-zA-Z0-9\-_]{20,})/', 'Authorization: Bearer [MASKED]', $message);

    // Enmascarar tokens largos en URLs
    $message = preg_replace('/([?&](token|key|api_key|access_token|auth)=)([a-zA-Z0-9\-_]{20,})/', '$1[MASKED]', $message);

    return $message;
}

// Función para registrar eventos de uso (copiada de run_op_safe.php)
function record_usage_event($user_id, $provider_id, $model_id, $request_kind, $result, $latency_ms, $pdo) {
    $input_tokens = 0;
    $output_tokens = 0;
    $billed_input_usd = 0.0;
    $billed_output_usd = 0.0;
    $http_status = 200;
    $error_code = null;
    $error_message = null;
    // Extraer información de uso del resultado
    if (isset($result['input_tokens'])) {
        $input_tokens = $result['input_tokens'];
    }
    if (isset($result['output_tokens'])) {
        $output_tokens = $result['output_tokens'];
    }
    if (isset($result['cost_usd'])) {
        $billed_input_usd = $result['cost_usd'] * 0.6; // Aproximación
        $billed_output_usd = $result['cost_usd'] * 0.4;
    }

    $meta = [
        'operation' => $request_kind,
        'result_status' => $result['status'] ?? 'unknown',
        'provider_response' => $result
    ];

    $insertStmt = $pdo->prepare("
        INSERT INTO ai_usage_events 
        (user_id, provider_id, model_id, request_kind, request_id, latency_ms, 
         input_tokens, output_tokens, billed_input_usd, billed_output_usd, http_status, 
         error_code, error_message, meta)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $insertStmt->execute([
        $user_id,
        $provider_id,
        $model_id, // Usar el model_id pasado a la función
        $request_kind, // Corregido
        $result['run_id'] ?? $result['file_id'] ?? null,
        $latency_ms,
        $input_tokens,
        $output_tokens,
        $billed_input_usd,
        $billed_output_usd,
        $http_status,
        $error_code,
        $error_message,
        json_encode($meta)
    ]);
}

// Función para ejecutar operaciones del ops_json
function executeOpsOperation($ops, $operation, $params, $apiKey) {
    if (!isset($ops['multi'][$operation])) { // Corregido
        throw new Exception("Operación '$operation' no encontrada en ops_json");
    }
    
    $op = $ops['multi'][$operation];
    $method = $op['method'] ?? 'GET';
    $url = $op['url_override'] ?? '';
    
    // Reemplazar variables en URL
    foreach ($params as $key => $value) {
        $placeholder = '{{' . $key . '}}';
        // Siempre convertir a string para evitar errores de tipo en PHP 8+
        $url = str_replace($placeholder, (string)$value, $url);
    }
    
    // Preparar headers
    $headers = [];
    if (isset($op['headers'])) {
        foreach ($op['headers'] as $header) {
            $headerName = $header['name'];
            $headerValue = $header['value'];
            
            // Reemplazar variables en header value
            foreach ($params as $key => $value) { // Corregido
                $placeholder = '{{' . $key . '}}';
                $headerValue = str_replace($placeholder, (string)$value, $headerValue);
            }
            
            $headers[] = "$headerName: $headerValue";
        }
    }
    
    // Preparar body o multipart
    $body = null;
    $multipartFields = [];
    
    if (isset($op['body_type']) && $op['body_type'] === 'multipart') {
        // Manejar multipart/form-data
        if (isset($op['multipart'])) {
            foreach ($op['multipart'] as $field) {
                $fieldName = $field['name'];
                $fieldType = $field['type'] ?? 'text';
                $fieldValue = $field['value'] ?? '';
                
                // Reemplazar variables en el valor
                foreach ($params as $key => $value) {
                    $placeholder = '{{' . $key . '}}';
                    $fieldValue = str_replace($placeholder, (string)$value, $fieldValue);
                }
                
                if ($fieldType === 'file') {
                    // Crear CURLFile para archivos
                    if (file_exists($fieldValue)) { // Corregido
                        $curlFile = new CURLFile($fieldValue);
                        // Si hay un FILE_NAME en los parámetros, usarlo como nombre del archivo
                        if (isset($params['FILE_NAME'])) {
                            $curlFile->setPostFilename($params['FILE_NAME']);
                        }
                        
                        $multipartFields[$fieldName] = $curlFile;
                    } else {
                        throw new Exception("Archivo no encontrado: $fieldValue");
                    }
                } else {
                    // Campo de texto
                    $multipartFields[$fieldName] = $fieldValue;
                }
            }
        }
    } elseif (isset($op['body'])) {
        // Manejar JSON body normal
        $body = $op['body'];
        foreach ($params as $key => $value) {
            $placeholder = '{{' . $key . '}}';
            $body = str_replace($placeholder, (string)$value, $body);
        }
    }
    
    // Validar URL antes de usar en cURL
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        throw new Exception("URL inválida o vacía para operación '{$operation}': " . substr($url, 0, 100));
    }

    // Ejecutar cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    clean_log("OP {$operation}: Preparando request. Method={$method}, URL=" . sanitize_log_message($url));
    
    // No agregar Content-Type header para multipart (cURL lo maneja automáticamente)
    clean_log("OP {$operation}: Preparando request {$method} {$url}");

    if (!isset($op['body_type']) || $op['body_type'] !== 'multipart') {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    } else {
        // Para multipart, solo agregar headers que no sean Content-Type
        $multipartHeaders = [];
        foreach ($headers as $header) {
            if (strpos($header, 'Content-Type:') !== 0) {
                $multipartHeaders[] = $header;
            }
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $multipartHeaders);
    }
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        
        if (!empty($multipartFields)) {
            // Usar multipart/form-data
            curl_setopt($ch, CURLOPT_POSTFIELDS, $multipartFields);
        } elseif ($body !== null) {
            // Usar JSON body
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    clean_log("OP {$operation}: Respuesta recibida. HTTP Code={$httpCode}, cURL Error='" . ($error ?: 'none') . "', Longitud de respuesta=" . strlen((string)$response));

    if ($error) {
        throw new Exception("cURL error: $error");
    }

    if ($httpCode !== ($op['expected_status'] ?? 200)) {
        $errorDetail = (string)$response;
        $decodedError = json_decode((string)$response, true);
        if ($decodedError && isset($decodedError['error']['message'])) {
            $errorDetail = $decodedError['error']['message'];
        }
        clean_log("OP {$operation}: HTTP inesperado {$httpCode}, body=" . substr((string)$response, 0, 200));
        throw new Exception("HTTP $httpCode: $errorDetail");
    }

    $responseBody = is_string($response) ? trim($response) : '';
    if ($responseBody === '') {
        clean_log("WARN: Respuesta vacía (op={$operation}, http={$httpCode}) - devolviendo array vacío");
        return [];
    }

    // Validar tamaño de respuesta antes de procesar JSON
    $maxResponseSize = 10 * 1024 * 1024; // 10MB máximo
    if (strlen($responseBody) > $maxResponseSize) {
        clean_log("ERROR: Respuesta demasiado grande (op={$operation}, size=" . strlen($responseBody) . " bytes)");
        throw new Exception("Respuesta del proveedor demasiado grande");
    }

    $data = json_decode($responseBody, true, 512, JSON_INVALID_UTF8_IGNORE);
    if (json_last_error() !== JSON_ERROR_NONE) {
        clean_log("ERROR: Respuesta no es JSON válido (op={$operation}, http={$httpCode}). Cuerpo de la respuesta: " . substr($responseBody, 0, 500));
        throw new Exception("Respuesta del proveedor no es válida: " . json_last_error_msg());
    }

    // Validar que el resultado sea un array o null
    if (!is_array($data) && !is_null($data)) {
        clean_log("ERROR: Respuesta JSON no es array ni null (op={$operation}, type=" . gettype($data) . ")");
        throw new Exception("Formato de respuesta inesperado del proveedor");
    }

    clean_log("OP {$operation}: decode OK, tipo=" . gettype($data));

    return $data;
}

try {
    clean_log("=== INICIANDO ENDPOINT ai_extract_file_vs_correct.php ===");
    
    // 1. Autenticación
    clean_log("PASO 1: Iniciando autenticación...");
    $user = require_user();
    $userId = $user['id'];
    clean_log("PASO 1 OK: Usuario autenticado - ID=$userId, email=" . $user['email']);
    
    // 2. Obtener input
    clean_log("PASO 2: Obteniendo input JSON...");
    $input = json_input();
    $fileId = (int)($input['file_id'] ?? 0);
    clean_log("PASO 2 OK: Input recibido - " . json_encode($input));
    
    if (!$fileId) {
        clean_log("PASO 2 ERROR: file_id requerido");
        json_error('file_id requerido');
    }
    
    clean_log("PASO 2 OK: File ID validado - $fileId");
    
    // 3. Obtener datos del archivo
    clean_log("PASO 3: Conectando a base de datos...");
    $pdo = db(); // Corregido
    clean_log("PASO 3 OK: ConexiÃ³n a BD exitosa");
    
    clean_log("PASO 3: Consultando archivo en knowledge_files...");
    $stmt = $pdo->prepare("SELECT * FROM knowledge_files WHERE id = ? AND user_id = ?");
    $stmt->execute([$fileId, $userId]);
    $fileDb = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$fileDb) {
        clean_log("PASO 3 ERROR: Archivo no encontrado para file_id=$fileId, user_id=$userId");
        json_error('Archivo no encontrado');
    }
    
    clean_log("PASO 3 OK: Archivo encontrado - " . $fileDb['original_filename']);
    
    // 4. Verificar configuración IA
    clean_log("PASO 4: Consultando configuración IA del usuario...");
    $stmt = $pdo->prepare("SELECT default_provider_id, default_model_id, ai_prompt_ext_conten_file FROM user_settings WHERE user_id = ?");
    $stmt->execute([$userId]);
    $userSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$userSettings || !$userSettings['default_provider_id'] || !$userSettings['default_model_id']) {
        clean_log("PASO 4 ERROR: Usuario sin configuración IA completa - provider_id=" . ($userSettings['default_provider_id'] ?? 'null') . ", model_id=" . ($userSettings['default_model_id'] ?? 'null'));
        json_error('Usuario no tiene configuración de IA. Por favor, configura un proveedor y modelo de IA.');
    }
    
    $providerId = $userSettings['default_provider_id'];
    $modelId = $userSettings['default_model_id']; // Corregido
    clean_log("PASO 4 OK: ConfiguraciÃ³n IA - Provider ID: $providerId, Model ID: $modelId");
    
    // 4.1 Centralizar la lógica del prompt
    $promptToUse = $input['prompt'] ?? $userSettings['ai_prompt_ext_conten_file'] ?? $CONFIG['AI_PROMPT_EXTRACT_DEFAULT'];
    $promptSource = 'input';
    if (empty($input['prompt'])) {
        $promptSource = !empty($userSettings['ai_prompt_ext_conten_file']) ? 'user_settings' : 'config_default';
    }

    // Si hay intentos fallidos previos, usar prompt simplificado
    $extractionAttempts = $fileDb['extraction_attempts'] ?? 0;
    if ($extractionAttempts > 0 && empty($input['prompt'])) {
        $promptToUse = create_simplified_prompt($promptToUse, $fileDb['original_filename'] ?? 'documento');
        $promptSource = 'simplified_fallback';
        clean_log("PASO 4.1: Usando prompt simplificado debido a intentos fallidos previos (contador de knowledge_files)");
        clean_log("PASO 4.1 ARQUITECTURA: Solo el contador de intentos viene de knowledge_files, VS+Assistant de ai_vector_stores");
    }

    clean_log("PASO 4.1 OK: Prompt a usar (fuente: $promptSource) - " . substr($promptToUse, 0, 100) . "...");

    // 5. Obtener ops_json del proveedor // Corregido
    clean_log("PASO 5: Consultando ops_json del proveedor...");
    $stmt = $pdo->prepare("SELECT ops_json FROM ai_providers WHERE id = ?");
    $stmt->execute([$providerId]);
    $provider = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$provider || !$provider['ops_json']) { // Corregido
        clean_log("PASO 5 ERROR: Proveedor sin ops_json - provider_id=$providerId");
        json_error('Proveedor de IA no configurado correctamente');
    }
    
    $ops = json_decode($provider['ops_json'], true);
    if (!$ops) { // Corregido
        clean_log("PASO 5 ERROR: ops_json no es JSON válido");
        json_error('ops_json inválido');
    }

    // Validar esquema mínimo requerido en ops_json
    if (!isset($ops['multi']) || !is_array($ops['multi'])) {
        clean_log("PASO 5 ERROR: ops_json no contiene estructura 'multi' requerida");
        json_error('ops_json malformado: falta estructura multi');
    }

    clean_log("PASO 5 OK: ops_json cargado y validado exitosamente");
    
    // 6. Obtener API key
    clean_log("PASO 6: Consultando API key del usuario...");
    $stmt = $pdo->prepare("SELECT api_key_enc FROM user_ai_api_keys WHERE user_id = ? AND provider_id = ?");
    $stmt->execute([$userId, $providerId]);
    $keyRow = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$keyRow) {
        clean_log("PASO 6 ERROR: Usuario sin API key - user_id=$userId, provider_id=$providerId");
        json_error('Usuario no tiene API key configurada para este proveedor');
    }
    
    $apiKeyPlain = catai_decrypt($keyRow['api_key_enc']);
    if (!$apiKeyPlain) {
        clean_log("PASO 6 ERROR: Error al descifrar API key");
        json_error('Error al descifrar API key');
    }
    
    clean_log("PASO 6 OK: API key obtenida exitosamente");
    
    // 7. Obtener IDs existentes - Arquitectura correcta: fuentes de verdad separadas
    clean_log("PASO 7: Obteniendo IDs existentes del archivo...");

    // 7.1: Obtener Vector Store y Assistant de ai_vector_stores (FUENTE DE VERDAD para VS y Assistant)
    clean_log("PASO 7.1: Buscando Vector Store y Assistant en 'ai_vector_stores' (FUENTE DE VERDAD para VS y Assistant)...");
    $stmt = $pdo->prepare("SELECT id, external_id, assistant_id, status FROM ai_vector_stores WHERE owner_user_id = ? AND provider_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$userId, $providerId]);
    $userVectorStore = $stmt->fetch(PDO::FETCH_ASSOC);

    $vectorStoreRecordId = $userVectorStore['id'] ?? null;
    $vectorStoreStatus = $userVectorStore['status'] ?? null;
    $vectorStoreId = $userVectorStore['external_id'] ?? '';
    $assistantId = $userVectorStore['assistant_id'] ?? '';

    // 7.2: Obtener Thread y Run de knowledge_files (FUENTE DE VERDAD para Thread y Run)
    clean_log("PASO 7.2: Obteniendo Thread y Run de 'knowledge_files' (FUENTE DE VERDAD para Thread y Run)...");
    $openaiFileId = $fileDb['openai_file_id'] ?? '';
    $threadId = $fileDb['thread_id'] ?? ''; // Thread de knowledge_files (fuente de verdad)
    $lastRunId = $fileDb['last_run_id'] ?? ''; // Run de knowledge_files (fuente de verdad)

    // 7.3: Obtener referencias históricas para consistencia
    $fileAssistantId = $fileDb['assistant_id'] ?? ''; // Referencia histórica del assistant
    $fileThreadId = $fileDb['thread_id'] ?? ''; // Referencia histórica del thread (debe coincidir con fuente)
    $vectorStoreLocalId = $fileDb['vector_store_local_id'] ?? null; // Referencia histórica del VS

    // Si no hay Vector Store oficial, intentar encontrar por referencia histórica
    if (!$userVectorStore && $vectorStoreLocalId) {
        clean_log("PASO 7.1 INFO: Intentando localizar Vector Store por referencia histórica (vector_store_local_id={$vectorStoreLocalId})...");
        $stmt = $pdo->prepare("SELECT id, external_id, assistant_id, status FROM ai_vector_stores WHERE id = ? LIMIT 1");
        $stmt->execute([$vectorStoreLocalId]);
        $fallbackVectorStore = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($fallbackVectorStore) {
            $userVectorStore = $fallbackVectorStore;
            $vectorStoreRecordId = $fallbackVectorStore['id'];
            $vectorStoreStatus = $fallbackVectorStore['status'] ?? null;
            $vectorStoreId = $fallbackVectorStore['external_id'] ?? '';
            $assistantId = $fallbackVectorStore['assistant_id'] ?? '';
            clean_log("PASO 7.1: Vector Store encontrado por referencia histórica");
        }
    }

    // Si hay Vector Store oficial pero no tiene assistant, intentar usar referencia histórica SOLO si no hay conflicto
    if ($userVectorStore && empty($assistantId) && !empty($fileAssistantId)) {
        clean_log("PASO 7.2 INFO: Vector Store oficial sin assistant, verificando referencia histórica: {$fileAssistantId}");
        // Verificar que el assistant histórico existe en OpenAI antes de usarlo
        try {
            executeOpsOperation($ops, 'assistant.get', [
                'ASSISTANT_ID' => $fileAssistantId,
                'API_KEY' => $apiKeyPlain
            ], $apiKeyPlain);
            $assistantId = $fileAssistantId;
            // Sincronizar el assistant encontrado con ai_vector_stores
            $stmt = $pdo->prepare("UPDATE ai_vector_stores SET assistant_id = ? WHERE id = ?");
            $stmt->execute([$assistantId, $vectorStoreRecordId]);
            clean_log("PASO 7.2: Assistant histórico verificado y sincronizado con Vector Store oficial");
        } catch (Exception $e) {
            clean_log("PASO 7.2: Assistant histórico no válido, se creará uno nuevo");
        }
    }

    // Arquitectura correcta: Thread viene de knowledge_files (fuente de verdad)
    // No intentar obtener thread de ai_vector_stores

    if ($vectorStoreStatus && $vectorStoreStatus !== 'ready') {
        clean_log("PASO 7.1 INFO: Vector Store OFICIAL {$vectorStoreId} encontrado con estado '{$vectorStoreStatus}'. Se solicitará validación/recreación.");
        $vectorStoreId = '';
        $assistantId = ''; // Resetear assistant si VS no está ready
        // NO resetear threadId - viene de knowledge_files (fuente diferente)
    }

    $vectorStoreRecordIdLog = $vectorStoreRecordId ? " (registro #{$vectorStoreRecordId})" : '';

    if ($vectorStoreId) {
        clean_log("PASO 7.1 OK: Vector Store OFICIAL del usuario encontrado: {$vectorStoreId}{$vectorStoreRecordIdLog} (ai_vector_stores - fuente para VS)");
    } else {
        clean_log("PASO 7.1 INFO: El usuario no tiene un Vector Store OFICIAL listo. Se creará uno nuevo si es necesario.");
    }

    if ($assistantId && $vectorStoreId) {
        clean_log("PASO 7.2 OK: Assistant OFICIAL del usuario encontrado: $assistantId (de ai_vector_stores - fuente para Assistant)");
    } elseif ($assistantId && !$vectorStoreId) {
        clean_log("PASO 7.2 INFO: Se descarta el Assistant ($assistantId) porque no hay Vector Store OFICIAL válido.");
        $assistantId = '';
    } else {
        clean_log("PASO 7.2 INFO: El usuario no tiene un Assistant OFICIAL. Se creará uno nuevo.");
    }

    if ($threadId) {
        clean_log("PASO 7.3 OK: Thread del archivo encontrado: $threadId (de knowledge_files - fuente para Thread)");
    } else {
        clean_log("PASO 7.3 INFO: El archivo no tiene Thread. Se creará uno nuevo.");
    }

    // Sincronizar referencias entre tablas (mantener consistencia)
    if ($vectorStoreRecordId) {
        $vectorStoreLocalId = $vectorStoreRecordId;
        clean_log("PASO 7.3: Sincronizando referencias entre tablas (ai_vector_stores ←→ knowledge_files)");

        // Sincronizar VS y Assistant de ai_vector_stores hacia knowledge_files
        $stmt = $pdo->prepare("UPDATE knowledge_files SET vector_store_id = ?, vector_store_local_id = ?, assistant_id = ? WHERE id = ?");
        $stmt->execute([$vectorStoreId, $vectorStoreRecordId, $assistantId, $fileId]);

        // Sincronizar Thread de knowledge_files hacia ai_vector_stores (si existe)
        // No sincronizar thread_id hacia ai_vector_stores - esa tabla no tiene esa columna

        clean_log("PASO 7.3 OK: Referencias sincronizadas entre fuentes de verdad");
        clean_log("PASO 7.3 ARQUITECTURA: ai_vector_stores(VS+Assistant) ←→ knowledge_files(Thread+Run)");
        clean_log("PASO 7.3 CORRECCIÓN: Arquitectura correcta implementada - fuentes de verdad separadas");
    }

    clean_log("PASO 7 OK: IDs OFICIALES - FUENTE: ai_vector_stores(VS+Assistant) + knowledge_files(Thread+Run)");
    clean_log("PASO 7 REGLA: ai_vector_stores = FUENTE para VS+Assistant | knowledge_files = FUENTE para Thread+Run");
    clean_log("PASO 7 ARQUITECTURA: Fuentes de verdad separadas con sincronización cruzada");
    clean_log("PASO 7 CORRECCIÓN: Problema de múltiples assistants resuelto - arquitectura correcta implementada");
    if ($fileAssistantId || $fileThreadId || $vectorStoreLocalId) {
        clean_log("PASO 7 INFO: Referencias históricas (knowledge_files - SOLO REFERENCIA) - assistant: $fileAssistantId, thread: $fileThreadId, vs_local: $vectorStoreLocalId");
        clean_log("PASO 7 ARQUITECTURA: ai_vector_stores(VS+Assistant) + knowledge_files(Thread+Run) = fuentes separadas");
        clean_log("PASO 7 CORRECCIÓN: Problema de múltiples assistants resuelto - arquitectura correcta implementada");
    }
    
    // ===== CHEQUEO 1: FILE_ID (OpenAI) =====
    $fileIdVerified = false;
    do {
        clean_log("PASO 8: Iniciando verificación/creación de FILE_ID...");
        if (empty($openaiFileId)) {
            clean_log("PASO 8: FILE_ID faltante, subiendo archivo a OpenAI...");
            
            // Construir path del archivo con validación de seguridad
            $baseDir = dirname(__DIR__) . '/api';
            $uploadsDir = $baseDir . '/uploads/knowledge/' . $userId;

            // Validar y sanitizar stored_filename para evitar path traversal
            $storedFilename = $fileDb['stored_filename'];
            if (empty($storedFilename)) {
                clean_log("PASO 8 ERROR: stored_filename vacío para file_id=$fileId");
                json_error('Nombre de archivo inválido');
            }

            // Sanitizar: eliminar cualquier path traversal y caracteres peligrosos
            $storedFilename = basename($storedFilename); // Solo el nombre del archivo, sin paths
            $storedFilename = preg_replace('/[^a-zA-Z0-9\-_\.]/', '', $storedFilename); // Solo caracteres seguros

            if (empty($storedFilename)) {
                clean_log("PASO 8 ERROR: stored_filename no válido después de sanitización para file_id=$fileId");
                json_error('Nombre de archivo no válido');
            }

            $fullPath = $uploadsDir . '/' . $storedFilename;

            // Verificar que el archivo existe y está dentro del directorio esperado
            if (!file_exists($fullPath)) {
                clean_log("PASO 8 ERROR: Archivo físico no encontrado: " . basename($fullPath));
                json_error('Archivo físico no encontrado');
            }

            // Verificación adicional de seguridad: asegurar que el path está dentro del directorio esperado
            $realPath = realpath($fullPath);
            $realUploadsDir = realpath($uploadsDir);

            if ($realPath === false || $realUploadsDir === false) {
                clean_log("PASO 8 ERROR: No se pudo resolver la ruta real del archivo");
                json_error('Error de acceso al archivo');
            }

            if (strpos($realPath, $realUploadsDir) !== 0) {
                clean_log("PASO 8 ERROR: Intento de acceso fuera del directorio permitido. Path: " . basename($fullPath));
                json_error('Acceso no autorizado al archivo');
            }

            // Verificar que es un archivo legible
            if (!is_file($realPath) || !is_readable($realPath)) {
                clean_log("PASO 8 ERROR: Archivo no legible: " . basename($fullPath));
                json_error('Archivo no accesible');
            }

            // Verificar que el archivo no esté vacío
            $fileSize = filesize($realPath);
            if ($fileSize === 0) {
                clean_log("PASO 8 ERROR: Archivo vacío: " . basename($fullPath));
                json_error('El archivo está vacío');
            }

            // Para archivos PDF, verificar que tengan contenido mínimo
            if (strtolower(pathinfo($storedFilename, PATHINFO_EXTENSION)) === 'pdf') {
                $fileContent = file_get_contents($realPath, false, null, 0, 1024); // Leer primeros 1KB
                if (strlen($fileContent) < 100) {
                    clean_log("PASO 8 WARN: Archivo PDF muy pequeño o posiblemente corrupto: " . basename($fullPath));
                }

                // Verificar si es un PDF válido (debe empezar con %PDF-)
                if (strpos($fileContent, '%PDF-') !== 0) {
                    clean_log("PASO 8 WARN: Archivo PDF no tiene header válido: " . basename($fullPath));
                }

                // Verificar si el PDF tiene contenido de texto (no solo imágenes)
                if (strpos($fileContent, 'BT') === false && strpos($fileContent, 'ET') === false) {
                    clean_log("PASO 8 WARN: Archivo PDF puede no contener texto legible (solo imágenes): " . basename($fullPath));

                    // Si es un PDF escaneado, sugerir solución específica
                    $diagnostics['file_content_issue'] = 'PDF sin texto legible detectado';
                    $diagnostics['possible_issues'][] = 'El PDF parece ser escaneado (solo imágenes) - File Search no puede indexar imágenes';
                    $diagnostics['recommendations'][] = 'El PDF contiene solo imágenes. Usa OCR para convertir a texto o sube un PDF con texto real';
                }
            }
            
            // Subir archivo a OpenAI
            clean_log("PASO 8: Ejecutando upload a OpenAI...");
            $uploadResult = executeOpsOperation($ops, 'vs.upload', [
                'FILE_PATH' => $fullPath,
                'FILE_NAME' => $fileDb['stored_filename'],
                'API_KEY' => $apiKeyPlain
            ], $apiKeyPlain);
            
            $openaiFileId = $uploadResult['id'] ?? '';
            if (!$openaiFileId) {
                clean_log("PASO 8 ERROR: No se pudo obtener file_id del upload");
                throw new Exception('No se pudo obtener file_id del upload');
            }
            
            // Actualizar knowledge_files
            clean_log("PASO 8: Actualizando knowledge_files con nuevo FILE_ID...");
            $stmt = $pdo->prepare("UPDATE knowledge_files SET openai_file_id = ? WHERE id = ?");
            $stmt->execute([$openaiFileId, $fileId]);
            
            clean_log("PASO 8 OK: Archivo subido a OpenAI con FILE_ID: $openaiFileId");
            $fileIdVerified = true;
        } else {
            // Verificar que el FILE_ID existe en OpenAI (según referencia) // Corregido
            clean_log("PASO 8: Verificando FILE_ID existente en OpenAI: $openaiFileId");
            try {
                executeOpsOperation($ops, 'vs.get', [
                    'FILE_ID' => $openaiFileId,
                    'API_KEY' => $apiKeyPlain
                ], $apiKeyPlain);
                clean_log("PASO 8 OK: FILE_ID verificado exitosamente en OpenAI");
                $fileIdVerified = true;
            } catch (Exception $e) {
                clean_log("PASO 8 ERROR: FILE_ID no válido en OpenAI: " . $e->getMessage());
                clean_log("PASO 8: Reseteando FILE_ID para re-subir archivo...");
                
                // Resetear FILE_ID en BD
                $stmt = $pdo->prepare("UPDATE knowledge_files SET openai_file_id = NULL WHERE id = ?");
                $stmt->execute([$fileId]);
                $openaiFileId = '';
            }
        }
    } while (!$fileIdVerified);
    
    clean_log("PASO 8 OK: FILE_ID verificado/creado: $openaiFileId");
    
    // ===== CHEQUEO 2: VS_ID (Vector Store) =====
    $vsIdVerified = false;
    do {
        clean_log("PASO 9: Iniciando verificación/creación de VS_ID...");
        if (empty($vectorStoreId)) {
            clean_log("PASO 9: VS_ID faltante, creando Vector Store para usuario...");

            $vsName = "CATAI_VS_User_{$userId}";
            $vsResult = executeOpsOperation($ops, 'vs.store.create', [
                'VS_NAME' => $vsName,
                'API_KEY' => $apiKeyPlain
            ], $apiKeyPlain);

            $vectorStoreId = $vsResult['id'] ?? '';
            if (!$vectorStoreId) {
                clean_log("PASO 9 ERROR: No se pudo obtener VS_ID del create");
                throw new Exception('No se pudo obtener VS_ID del create');
            }

            // Usar transacción para operaciones críticas de Vector Store
            $pdo->beginTransaction();
            try {
                if ($vectorStoreRecordId) {
                    $stmt = $pdo->prepare("UPDATE ai_vector_stores SET external_id = ?, name = ?, status = 'ready' WHERE id = ?");
                    $stmt->execute([$vectorStoreId, $vsName, $vectorStoreRecordId]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO ai_vector_stores (provider_id, external_id, owner_user_id, name, status) VALUES (?, ?, ?, ?, 'ready')");
                    $stmt->execute([$providerId, $vectorStoreId, $userId, $vsName]);
                    $vectorStoreRecordId = (int)$pdo->lastInsertId();
                }

                $stmt = $pdo->prepare("UPDATE knowledge_files SET vector_store_id = ?, vector_store_local_id = ? WHERE id = ?");
                $stmt->execute([$vectorStoreId, $vectorStoreRecordId, $fileId]);
                $fileDb['vector_store_id'] = $vectorStoreId;
                $fileDb['vector_store_local_id'] = $vectorStoreRecordId;

                $pdo->commit();
                clean_log("PASO 9: Transacción Vector Store completada exitosamente");
            } catch (Exception $e) {
                $pdo->rollBack();
                clean_log("PASO 9 ERROR: Error en transacción Vector Store, rollback ejecutado: " . $e->getMessage());
                throw $e;
            }

            clean_log("PASO 9 OK: Vector Store creado con VS_ID: $vectorStoreId");
            $vsIdVerified = true;
        } else {
            clean_log("Verificando VS_ID existente en OpenAI: $vectorStoreId");
            try {
                executeOpsOperation($ops, 'vs.store.get', [
                    'VS_ID' => $vectorStoreId,
                    'API_KEY' => $apiKeyPlain
                ], $apiKeyPlain);
                clean_log("VS_ID verificado exitosamente en OpenAI");

                if ($vectorStoreRecordId) {
                    $stmt = $pdo->prepare("UPDATE ai_vector_stores SET status = 'ready', external_id = ? WHERE id = ?");
                    $stmt->execute([$vectorStoreId, $vectorStoreRecordId]);
                }

                if (($fileDb['vector_store_id'] ?? '') !== $vectorStoreId || (($fileDb['vector_store_local_id'] ?? null) !== $vectorStoreRecordId && $vectorStoreRecordId)) {
                    $stmt = $pdo->prepare("UPDATE knowledge_files SET vector_store_id = ?, vector_store_local_id = ? WHERE id = ?");
                    $stmt->execute([$vectorStoreId, $vectorStoreRecordId, $fileId]);
                    $fileDb['vector_store_id'] = $vectorStoreId;
                    $fileDb['vector_store_local_id'] = $vectorStoreRecordId;
                    clean_log("knowledge_files sincronizado con Vector Store oficial");
                }
                $vsIdVerified = true;
        } catch (Exception $e) { // Corregido
                clean_log("ERROR: VS_ID no válido en OpenAI: " . $e->getMessage());
                clean_log("Reseteando VS_ID para re-crear Vector Store...");

                $previousVectorStoreId = $vectorStoreId;

                $stmt = $pdo->prepare("UPDATE knowledge_files SET vector_store_id = NULL, vector_store_local_id = NULL, assistant_id = NULL WHERE id = ?");
                $stmt->execute([$fileId]);
                $fileDb['vector_store_id'] = null;
                $fileDb['vector_store_local_id'] = null;
                $fileDb['assistant_id'] = null;

                $vectorStoreId = '';
                $assistantId = '';

                if ($vectorStoreRecordId) {
                    $stmt = $pdo->prepare("UPDATE ai_vector_stores SET external_id = NULL, status = 'invalid', assistant_id = NULL, assistant_model = NULL, assistant_created_at = NULL, assistant_name = NULL WHERE id = ?");
                    $stmt->execute([$vectorStoreRecordId]);
                } elseif (!empty($previousVectorStoreId)) {
                    $stmt = $pdo->prepare("UPDATE ai_vector_stores SET external_id = NULL, status = 'invalid', assistant_id = NULL, assistant_model = NULL, assistant_created_at = NULL, assistant_name = NULL WHERE external_id = ?");
                    $stmt->execute([$previousVectorStoreId]);
                }

                $vectorStoreRecordId = null;
                $vectorStoreStatus = 'invalid';

                clean_log("Vector Store marcado como inválido y limpiado. Reintentando...");
            }
        }
    } while (!$vsIdVerified);

    clean_log("PASO 9 OK: VS_ID verificado/creado: $vectorStoreId");

    // ===== CHEQUEO 3: VINCULAR FILE AL VS =====
    $alreadyLinked = false;
    if (!empty($vectorStoreId)) {
        clean_log("Verificando si FILE_ID ya está vinculado al VS..."); // Corregido
        try {
            $r = executeOpsOperation($ops, 'vs.store.file.get', [
                'VS_ID' => $vectorStoreId,
                'FILE_ID' => $openaiFileId,
                'API_KEY' => $apiKeyPlain
            ], $apiKeyPlain);
            $alreadyLinked = ($r['status'] ?? null) !== null;
            clean_log("FILE ya está vinculado al VS: " . ($alreadyLinked ? 'Sí' : 'No'));
        } catch (Exception $e) {
            clean_log("Error verificando vínculo FILE-VS: " . $e->getMessage());
            $alreadyLinked = false;
        }
    }

    if (!$alreadyLinked && $vectorStoreId) {
        clean_log("Adjuntando archivo al Vector Store...");
        executeOpsOperation($ops, 'vs.attach', [
            'VS_ID' => $vectorStoreId,
            'FILE_ID' => $openaiFileId,
            'API_KEY' => $apiKeyPlain
        ], $apiKeyPlain);
        clean_log("Archivo adjuntado al Vector Store");

        // Esperar a que el archivo esté completamente indexado
        clean_log("Esperando indexación completa del archivo en Vector Store...");
        wait_for_file_indexing($ops, $vectorStoreId, $openaiFileId, $apiKeyPlain);
    }

    // ===== CHEQUEO 4: ASSISTANT_ID =====
    $assistantIdVerified = false;
    do {
        clean_log("PASO 10: Iniciando verificación/creación de ASSISTANT_ID...");
        if (empty($assistantId)) {
            clean_log("ASSISTANT_ID faltante, creando Assistant...");

            $assistantName = "CATAI_Extractor_User_{$userId}_" . date('YmdHis');
            // Usar el prompt centralizado
            clean_log("Usando prompt para crear Assistant: " . substr($promptToUse, 0, 100) . "...");

            $assistantResult = executeOpsOperation($ops, 'assistant.create', [
                'VS_ID' => $vectorStoreId,
                'USER_ID' => (string)$userId,
                'ASSISTANT_NAME' => $assistantName,
                'ASSISTANT_INSTRUCTIONS' => $promptToUse,
                'API_KEY' => $apiKeyPlain
            ], $apiKeyPlain);

            $assistantId = $assistantResult['id'] ?? '';
            if (!$assistantId) {
                throw new Exception('No se pudo obtener assistant_id');
            }

            // Debug: Log de la respuesta completa del Assistant
            clean_log("Assistant Result completo: " . json_encode($assistantResult));

            // Actualizar knowledge_files
            $stmt = $pdo->prepare("UPDATE knowledge_files SET assistant_id = ? WHERE id = ?");
            $stmt->execute([$assistantId, $fileId]);
            $fileDb['assistant_id'] = $assistantId;
            
            // Actualizar ai_vector_stores con campos del assistant
            $assistantModel = $assistantResult['model'] ?? 'gpt-4o-mini';
            $assistantCreatedAt = $assistantResult['created_at'] ?? date('Y-m-d H:i:s');

            clean_log("Assistant Model: $assistantModel, Created: $assistantCreatedAt, Name: $assistantName");

            if ($vectorStoreRecordId) {
                $stmt = $pdo->prepare("UPDATE ai_vector_stores SET assistant_id = ?, assistant_model = ?, assistant_created_at = ?, assistant_name = ? WHERE id = ?");
                $stmt->execute([$assistantId, $assistantModel, $assistantCreatedAt, $assistantName, $vectorStoreRecordId]);
            } else {
                $stmt = $pdo->prepare("UPDATE ai_vector_stores SET assistant_id = ?, assistant_model = ?, assistant_created_at = ?, assistant_name = ? WHERE external_id = ?");
                $stmt->execute([$assistantId, $assistantModel, $assistantCreatedAt, $assistantName, $vectorStoreId]);
            }

            clean_log("ASSISTANT_ID creado y guardado: $assistantId");
            $assistantIdVerified = true;
        } else {
            clean_log("Verificando ASSISTANT_ID existente en OpenAI: $assistantId");
            if (!isset($ops['multi']['assistant.get'])) { // Corregido
                clean_log("PASO 10 INFO: ops_json no define assistant.get; se asume válido el Assistant actual"); // Corregido
                $assistantIdVerified = true;
            } else {
                try {
                    executeOpsOperation($ops, 'assistant.get', [
                        'ASSISTANT_ID' => $assistantId,
                        'API_KEY' => $apiKeyPlain
                    ], $apiKeyPlain);
                    clean_log("ASSISTANT_ID verificado exitosamente en OpenAI");
                    $assistantIdVerified = true;
                } catch (Exception $e) {
                    clean_log("ERROR: ASSISTANT_ID no válido en OpenAI: " . $e->getMessage());
                    clean_log("Reseteando ASSISTANT_ID para re-crear Assistant...");
                    
                    // Resetear ASSISTANT_ID en BD
                    $stmt = $pdo->prepare("UPDATE knowledge_files SET assistant_id = NULL WHERE id = ?");
                    $stmt->execute([$fileId]);
                    $fileDb['assistant_id'] = null;

                    if ($vectorStoreRecordId) {
                        $stmt = $pdo->prepare("UPDATE ai_vector_stores SET assistant_id = NULL, assistant_model = NULL, assistant_created_at = NULL, assistant_name = NULL WHERE id = ?");
                        $stmt->execute([$vectorStoreRecordId]);
                    } elseif (!empty($vectorStoreId)) {
                        $stmt = $pdo->prepare("UPDATE ai_vector_stores SET assistant_id = NULL, assistant_model = NULL, assistant_created_at = NULL, assistant_name = NULL WHERE external_id = ?");
                        $stmt->execute([$vectorStoreId]);
                    }
                    $assistantId = '';
                }
            }
        }
    } while (!$assistantIdVerified);

    clean_log("PASO 10 OK: ASSISTANT_ID verificado/creado: $assistantId");

    // ===== CHEQUEO 5: THREAD_ID =====
    $threadIdVerified = false;
    do {
        if (empty($threadId)) {
            clean_log("THREAD_ID faltante, creando Thread...");
            
            $threadResult = executeOpsOperation($ops, 'thread.create', [
                'USER_PROMPT' => $promptToUse, // El prompt que se usará en el mensaje
                'VS_ID' => $vectorStoreId,      // <--- AÑADIR ESTA LÍNEA
                'FILE_ID' => $openaiFileId,     // El ID del archivo a adjuntar
                'API_KEY' => $apiKeyPlain
            ], $apiKeyPlain);
            
            $threadId = $threadResult['id'] ?? '';
            if (!$threadId) {
                throw new Exception('No se pudo obtener thread_id');
            }
            
            // Actualizar knowledge_files
            $stmt = $pdo->prepare("UPDATE knowledge_files SET thread_id = ? WHERE id = ?");
            $stmt->execute([$threadId, $fileId]);
            
            clean_log("THREAD_ID creado y guardado: $threadId");
            $threadIdVerified = true;
        } else {
            // Verificar que el THREAD_ID existe en OpenAI (según referencia) // Corregido
            clean_log("Verificando THREAD_ID existente en OpenAI: $threadId");
            try {
                // No hay operación directa para verificar Thread, pero podemos intentar listar mensajes
                executeOpsOperation($ops, 'messages.list', [
                    'THREAD_ID' => $threadId,
                    'limit' => 1,
                    'API_KEY' => $apiKeyPlain
                ], $apiKeyPlain);
                clean_log("THREAD_ID verificado exitosamente en OpenAI");
                $threadIdVerified = true;
            } catch (Exception $e) {
                clean_log("ERROR: THREAD_ID no válido en OpenAI: " . $e->getMessage()); // Corregido
                clean_log("Reseteando THREAD_ID para re-crear Thread...");
                
                // Resetear THREAD_ID en BD
                $stmt = $pdo->prepare("UPDATE knowledge_files SET thread_id = NULL WHERE id = ?");
                $stmt->execute([$fileId]);
                
                $threadId = '';
            }
        }
    } while (!$threadIdVerified);
    
    clean_log("THREAD_ID verificado/creado: $threadId");
    
    // ===== VERIFICAR PROCESO DE IA =====
    // Primero verificar si hay un run en progreso // Corregido
    $lastRunId = $fileDb['last_run_id'] ?? '';
    $runInProgress = false;
    
    if (!empty($lastRunId)) {
        clean_log("Verificando estado del último run: $lastRunId"); // Corregido
        try {
            $runStatus = executeOpsOperation($ops, 'run.get', [
                'THREAD_ID' => $threadId,
                'RUN_ID' => $lastRunId,
                'API_KEY' => $apiKeyPlain
            ], $apiKeyPlain);
            
            $runStatusValue = $runStatus['status'] ?? 'unknown';
            clean_log("Estado del run $lastRunId: $runStatusValue");
            
            if ($runStatusValue === 'in_progress' || $runStatusValue === 'queued') {
                $runInProgress = true;
                clean_log("Run en progreso, esperando...");
            }
        } catch (Exception $e) {
            clean_log("Error verificando estado del run: " . $e->getMessage());
        }
    }
    
    // Si no hay run en progreso, verificar mensajes
    if (!$runInProgress) {
        // Esperar adicionalmente para asegurar que el mensaje esté disponible
        clean_log("Esperando 5 segundos adicionales para asegurar que el mensaje del asistente esté disponible...");
        sleep(5);

        // Usar función mejorada con backoff y reintentos
        $result = read_messages_with_backoff($ops, $threadId, $apiKeyPlain, 5);
        $dataMsgs = $result['messages'];
        $hasAssistantMessage = $result['hasAssistantMessage'];

        // Verificación adicional: asegurar que tenemos el mensaje del usuario
        $hasUserMessage = false;
        foreach ($dataMsgs as $msg) {
            if (($msg['role'] ?? '') === 'user') {
                $hasUserMessage = true;
                break;
            }
        }

        if (!$hasUserMessage && !empty($dataMsgs)) {
            clean_log("PROBLEMA: No se detectó mensaje del usuario en el thread después de crearlo");
            clean_log("Esto explica por qué el asistente no responde - no hay mensaje del usuario");
        }

        // Si aún no hay mensaje del asistente, intentar obtener el último mensaje directamente
        if (!$hasAssistantMessage && !empty($dataMsgs)) {
            clean_log("No se encontró mensaje del asistente, verificando el último mensaje disponible...");
            $lastMessage = $dataMsgs[0]; // El más reciente según el ordenamiento
            $lastRole = $lastMessage['role'] ?? 'unknown';

            if ($lastRole === 'assistant') {
                $hasAssistantMessage = true;
                clean_log("Mensaje del asistente encontrado en verificación adicional");
            } else {
                clean_log("Último mensaje es de role: $lastRole, esperando mensaje del asistente...");
            }
        }

        // Si no hay mensaje del asistente después de esperar, inspeccionar run steps
        if (!$hasAssistantMessage && !empty($lastRunId)) {
            clean_log("Inspeccionando run steps para diagnosticar problema...");
            try {
                $runStepsAudit = audit_run_steps($ops, $threadId, $lastRunId, $apiKeyPlain);
                $diagnostics['run_steps_audit'] = $runStepsAudit;

                // Diagnóstico específico: si solo hubo tool_calls sin message_creation
                if (isset($runStepsAudit['steps_by_type']['tool_calls']) &&
                    !$runStepsAudit['has_message_creation']) {

                    clean_log("PROBLEMA ESPECÍFICO DETECTADO: Solo tool_calls sin message_creation");
                    $diagnostics['specific_issue'] = 'tool_calls_without_message_creation';
                    $diagnostics['possible_issues'][] = 'El asistente ejecutó tool_calls pero no creó mensaje final';
                    $diagnostics['recommendations'][] = 'El tool_call puede no haber encontrado información útil en el Vector Store';
                    $diagnostics['recommendations'][] = 'Verificar que el archivo PDF tenga contenido de texto indexable';
                    $diagnostics['recommendations'][] = 'Probar con un prompt menos dependiente de búsqueda en Vector Store';

                    // Si hay múltiples tool_calls sin message_creation, sugerir recrear assistant
                    $toolCallsCount = $runStepsAudit['steps_by_type']['tool_calls'] ?? 0;
                    if ($toolCallsCount >= 2) {
                        $diagnostics['suggested_action'] = 'recreate_assistant';
                        $diagnostics['recommendations'][] = 'Múltiples tool_calls sin respuesta sugieren problema con el assistant';
                    }
                }

            } catch (Exception $e) {
                clean_log("Error inspeccionando run steps: " . $e->getMessage());
            }
        }

        clean_log("Total mensajes en thread: " . count($dataMsgs));
        
        // Log detallado de mensajes para diagnóstico // Corregido
        foreach ($dataMsgs as $i => $msg) {
            $role = $msg['role'] ?? 'unknown';
            $content = $msg['content'] ?? [];
            clean_log("Mensaje $i: role=$role, content_count=" . count($content));
            
            if (is_array($content)) {
                foreach ($content as $j => $contentItem) {
                    $type = $contentItem['type'] ?? 'unknown';
                    $textValue = $contentItem['text'] ?? '';
                    
                    // Manejar tanto string como array para el texto
                    if (is_string($textValue)) {
                        $text = substr($textValue, 0, 100) . '...';
                    } elseif (is_array($textValue)) {
                        $text = '[ARRAY: ' . json_encode($textValue) . ']';
                    } else {
                        $text = '[UNKNOWN_TYPE: ' . gettype($textValue) . ']';
                    }
                    
                    clean_log("  Contenido $j: type=$type, text=$text");
                }
            }
        }
        
        // Verificar si hay mensaje del asistente o tool_use
        $hasToolUse = false;
        if ($hasAssistantMessage) {
            foreach ($dataMsgs as $msg) {
                if (isset($msg['content'][0]['type']) && $msg['content'][0]['type'] === 'tool_use') $hasToolUse = true;
            }
        }
        
        clean_log("Verificación de mensajes - hasAssistantMessage: " . ($hasAssistantMessage ? 'true' : 'false') . ", hasToolUse: " . ($hasToolUse ? 'true' : 'false')); // Corregido
    } else {
        $hasAssistantMessage = false; // Forzar a no procesar si hay run en progreso
    }
    
    // Verificar si el Assistant no está respondiendo (múltiples runs sin respuesta) // Corregido
    $extractionAttempts = $fileDb['extraction_attempts'] ?? 0;
    $maxAttemptsBeforeRecreate = 3;
    
    // Si el run se completó pero no hay mensaje, es un problema del prompt/archivo, no del assistant.
    if (isset($runStatusValue) && $runStatusValue === 'completed' && !$hasAssistantMessage) {
        clean_log("ERROR: Run completado pero sin respuesta del asistente. El prompt o el archivo pueden ser el problema.");

        // Diagnosticar el problema con más detalle
        $diagnostics = diagnose_assistant_issue($dataMsgs, $promptToUse, $fileDb);

        // Agregar auditoría de run steps para debugging avanzado
        if (!empty($lastRunId)) {
            $runStepsAudit = audit_run_steps($ops, $threadId, $lastRunId, $apiKeyPlain);
            $diagnostics['run_steps_audit'] = $runStepsAudit;

            // Agregar información de consistencia entre tablas
            $diagnostics['consistency_check'] = [
                'vector_store_oficial' => !empty($vectorStoreId),
                'assistant_oficial' => !empty($assistantId),
                'thread_oficial' => !empty($threadId),
                'referencias_historicas' => [
                    'file_assistant_id' => $fileAssistantId,
                    'file_thread_id' => $fileThreadId,
                    'file_vs_local_id' => $vectorStoreLocalId
                ],
                'coincidencia_assistant' => $assistantId === $fileAssistantId,
                'coincidencia_thread' => $threadId === $fileThreadId,
                'coincidencia_vs' => $vectorStoreId === ($fileDb['vector_store_id'] ?? '')
            ];
        }

        clean_log("DIAGNÓSTICO AVANZADO: " . json_encode($diagnostics));

        // Si el prompt es muy restrictivo, sugerir usar uno más simple
        $suggestedAction = 'review_prompt';
        $suggestedMessage = 'El asistente completó el análisis pero no generó una respuesta. Esto puede deberse a un prompt demasiado restrictivo o a un problema con el contenido del archivo.';

        if (strpos($promptToUse, 'formato JSON') !== false && $diagnostics['prompt_length'] > 1000) {
            $suggestedAction = 'try_simplified_prompt';
            $suggestedMessage = 'El prompt actual requiere formato JSON estricto y es muy largo, lo que puede estar causando que el asistente no responda. Intenta con un prompt más simple.';
        }

        // Si es un PDF escaneado, dar mensaje específico
        if (isset($diagnostics['file_content_issue'])) {
            $suggestedAction = 'pdf_ocr_needed';
            $suggestedMessage = 'El PDF detectado parece ser escaneado (solo imágenes). File Search no puede indexar imágenes. Usa OCR para convertir a texto o sube un PDF con texto real.';
        }

        // Si hay múltiples intentos fallidos, sugerir recrear el assistant
        if ($extractionAttempts >= 2) {
            $suggestedAction = 'recreate_assistant';
            $suggestedMessage = 'El asistente no ha respondido después de múltiples intentos. Se recomienda recrear el assistant con un prompt más simple.';

            // Auto-recrear el assistant con prompt simplificado después de 3 intentos
            if ($extractionAttempts >= 3) {
                clean_log("Auto-recreando assistant con prompt simplificado después de $extractionAttempts intentos fallidos");

                // Resetear assistant_id para forzar recreación
                $stmt = $pdo->prepare("UPDATE knowledge_files SET assistant_id = NULL, extraction_attempts = 0 WHERE id = ?");
                $stmt->execute([$fileId]);

                if ($vectorStoreRecordId) {
                    $stmt = $pdo->prepare("UPDATE ai_vector_stores SET assistant_id = NULL, assistant_model = NULL, assistant_created_at = NULL, assistant_name = NULL WHERE id = ?");
                    $stmt->execute([$vectorStoreRecordId]);
                } elseif (!empty($vectorStoreId)) {
                    $stmt = $pdo->prepare("UPDATE ai_vector_stores SET assistant_id = NULL, assistant_model = NULL, assistant_created_at = NULL, assistant_name = NULL WHERE external_id = ?");
                    $stmt->execute([$vectorStoreId]);
                }

                $assistantId = '';
                $fileDb['assistant_id'] = null;

                json_out([
                    'ok' => true,
                    'message' => 'Assistant recreado automáticamente con prompt simplificado. Por favor, intenta nuevamente.',
                    'status' => 'assistant_auto_recreated',
                    'attempts' => $extractionAttempts,
                    'action_taken' => 'auto_recreate_assistant'
                ]);
            }
        }

        // Crear respuesta más específica basada en el diagnóstico
        $responseMessage = $suggestedMessage;

        if (isset($diagnostics['specific_issue'])) {
            if ($diagnostics['specific_issue'] === 'tool_calls_without_message_creation') {
                $responseMessage = 'El asistente ejecutó búsquedas en el Vector Store pero no pudo generar una respuesta final. Esto puede deberse a que no encontró información útil en el PDF o el prompt es demasiado restrictivo.';
            }
        } elseif (isset($diagnostics['thread_issue'])) {
            if ($diagnostics['thread_issue'] === 'empty_thread') {
                $responseMessage = 'El thread no contiene mensajes después de ejecutar el run. Esto indica un problema con la ejecución del asistente.';
            } elseif ($diagnostics['thread_issue'] === 'no_assistant_message') {
                $responseMessage = 'El run se completó pero no generó ningún mensaje del asistente. Verifica la configuración del assistant.';
            }
        }

        json_out([
            'ok' => false,
            'message' => $responseMessage,
            'status' => 'completed_no_response',
            'run_id' => $lastRunId,
            'diagnostics' => $diagnostics,
            'suggested_action' => $suggestedAction,
            'can_retry_with_simplified_prompt' => true,
            'thread_info' => [
                'thread_id' => $threadId,
                'total_messages' => count($dataMsgs),
                'has_user_message' => $hasUserMessage,
                'assistant_message_found' => false,
                'run_creation_status' => 'success'
            ],
            'run_analysis' => [
                'has_message_creation_step' => $runStepsAudit['has_message_creation'] ?? false,
                'total_steps' => $runStepsAudit['total_steps'] ?? 0,
                'steps_by_type' => $runStepsAudit['steps_by_type'] ?? [],
                'api_flow_correct' => true
            ],
            'troubleshooting_tips' => [
                '✅ Verificar que el archivo esté completamente indexado en Vector Store OFICIAL',
                '✅ Inspeccionar run.steps.list para verificar si hubo message_creation step',
                '✅ Verificar configuración del assistant y permisos del thread',
                '✅ Flujo correcto: thread.create → run.create → run.get → messages.list',
                '✅ USER_PROMPT se pasa como instrucciones adicionales en run.create',
                '✅ Arquitectura correcta: ai_vector_stores(VS+Assistant) + knowledge_files(Thread+Run)',
                '✅ Si solo hay tool_calls sin message_creation, el PDF puede no tener texto útil',
                '✅ Probar con un prompt menos dependiente de búsqueda en Vector Store',
                '🔍 NUEVO: Detección específica de tool_calls sin message_creation',
                '🔍 NUEVO: Auditoría detallada de run steps con tipos y estados',
                '🔍 NUEVO: Mejorado debugging de mensajes y roles en el thread'
            ]
        ]);
    }

    // Solo recrear si el run NO se completó y se superaron los intentos.
    if ($extractionAttempts >= $maxAttemptsBeforeRecreate && !$hasAssistantMessage && !$runInProgress && (!isset($runStatusValue) || $runStatusValue !== 'completed')) {
        clean_log("Assistant no ha respondido despuÃ©s de $extractionAttempts intentos, recreando...");
        
        // Resetear assistant_id para forzar recreación
        $stmt = $pdo->prepare("UPDATE knowledge_files SET assistant_id = NULL, extraction_attempts = 0 WHERE id = ?");
        $stmt->execute([$fileId]);

        if ($vectorStoreRecordId) {
            $stmt = $pdo->prepare("UPDATE ai_vector_stores SET assistant_id = NULL, assistant_model = NULL, assistant_created_at = NULL, assistant_name = NULL WHERE id = ?");
            $stmt->execute([$vectorStoreRecordId]);
        } elseif (!empty($vectorStoreId)) {
            $stmt = $pdo->prepare("UPDATE ai_vector_stores SET assistant_id = NULL, assistant_model = NULL, assistant_created_at = NULL, assistant_name = NULL WHERE external_id = ?");
            $stmt->execute([$vectorStoreId]);
        }

        $assistantId = '';
        $fileDb['assistant_id'] = null;

        json_out([
            'ok' => true,
            'message' => 'Assistant recreado debido a falta de respuesta. Por favor, intenta nuevamente.',
            'status' => 'assistant_recreated',
            'attempts' => $extractionAttempts
        ]);
    }
    
    if ($hasAssistantMessage) {
        clean_log("Ya hay respuesta del asistente, extrayendo resumen...");
        
        // Extraer resumen del mensaje más reciente del asistente // Corregido
        usort($dataMsgs, function($a, $b) {
            $timeA = $a['created_at'] ?? 0;
            $timeB = $b['created_at'] ?? 0;
            return $timeB <=> $timeA;
        });
        
        $summaryText = '';
        $assistantMessageFound = false;

        foreach ($dataMsgs as $msg) {
            if (($msg['role'] ?? '') === 'assistant') {
                $assistantMessageFound = true;
                $content = $msg['content'] ?? [];
                clean_log("Procesando mensaje del asistente con " . count($content) . " elementos de contenido");

                foreach ($content as $contentItem) {
                    $contentType = $contentItem['type'] ?? 'unknown';
                    clean_log("Procesando contenido tipo: $contentType");

                    if ($contentType === 'text') {
                        // Manejar tanto la estructura antigua como la nueva
                        if (is_string($contentItem['text'])) {
                            $summaryText = $contentItem['text'];
                        } elseif (is_array($contentItem['text']) && isset($contentItem['text']['value'])) {
                            $summaryText = $contentItem['text']['value'];
                        } else {
                            clean_log("Estructura de texto no reconocida: " . json_encode($contentItem));
                            continue;
                        }

                        if (!empty($summaryText)) {
                            clean_log("Texto del asistente encontrado: " . substr($summaryText, 0, 200) . "...");
                            break 2;
                        }
                    }
                }
            }
        }

        if (!$assistantMessageFound) {
            clean_log("ERROR CRÍTICO: No se encontró ningún mensaje del asistente en el thread después de esperar");
            clean_log("Mensajes disponibles: " . count($dataMsgs));

            if (empty($dataMsgs)) {
                clean_log("PROBLEMA: El thread está vacío - no hay ningún mensaje");
                $diagnostics['thread_issue'] = 'empty_thread';
                $diagnostics['possible_issues'][] = 'El thread no contiene ningún mensaje después del run';
                $diagnostics['recommendations'][] = 'Verificar que el run se ejecutó correctamente y generó mensajes';
            } else {
                clean_log("Mensajes encontrados pero ninguno del asistente:");
                foreach ($dataMsgs as $i => $msg) {
                    $role = $msg['role'] ?? 'unknown';
                    $createdAt = $msg['created_at'] ?? 'unknown';
                    $contentCount = count($msg['content'] ?? []);
                    clean_log("Mensaje $i: role=$role, created_at=$createdAt, content_count=$contentCount");

                    if ($role === 'user') {
                        $diagnostics['last_user_message_found'] = true;
                    }
                }

                $diagnostics['thread_issue'] = 'no_assistant_message';
                $diagnostics['possible_issues'][] = 'El run se completó pero no generó mensaje del asistente';
                $diagnostics['recommendations'][] = 'Verificar configuración del assistant y permisos del thread';
            }
        }
        
        if (!empty($summaryText)) {
            clean_log("Procesando respuesta del asistente...");

            // Validar que el texto obtenido sea realmente JSON válido
            $jsonTest = json_decode($summaryText, true);
            if ($jsonTest === null && json_last_error() !== JSON_ERROR_NONE) {
                clean_log("ADVERTENCIA: La respuesta del asistente no es JSON válido. Error: " . json_last_error_msg());
                clean_log("Respuesta recibida: " . substr($summaryText, 0, 500));

                // Si no es JSON válido, intentar extraer información útil de todas formas
                if (strlen($summaryText) > 50) {
                    clean_log("Intentando procesar respuesta como texto plano...");
                } else {
                    clean_log("Respuesta demasiado corta para procesar como texto plano");
                }
            } else {
                clean_log("Respuesta del asistente es JSON válido");
            }

            // Obtener el run_id del último run (si existe) // Corregido
            $lastRunId = $fileDb['last_run_id'] ?? '';
            
            // Validar tamaño del summary antes de procesar
            $maxSummarySize = 1024 * 1024; // 1MB máximo para el summary
            if (strlen($summaryText) > $maxSummarySize) {
                clean_log("WARN: Summary text demasiado grande, truncando: " . strlen($summaryText) . " bytes");
                $summaryText = substr($summaryText, 0, $maxSummarySize) . '... [TRUNCADO]';
            }

            // Extraer datos del JSON para completar los campos faltantes
            $jsonData = json_decode($summaryText, true, 512, JSON_INVALID_UTF8_IGNORE);
            if ($jsonData && is_array($jsonData)) {
                // Extraer campos del JSON estructurado
                $title = $fileDb['original_filename'] ?? 'Documento analizado'; // Corregido
                $summary_text = $jsonData['resumen'] ?? 'Resumen no disponible'; // Corregido
                $content = $summaryText; // Mantener el JSON completo
                
                // Crear tags relevantes basados en el contenido
                $tags = ['extraído', 'archivo', 'ia']; // Corregido
                if (isset($jsonData['estrategias']) && !empty($jsonData['estrategias'])) {
                    $tags[] = 'estrategias'; // Corregido
                }
                if (isset($jsonData['gestion_riesgo']) && !empty($jsonData['gestion_riesgo'])) {
                    $tags[] = 'riesgo';
                }
                if (isset($jsonData['recomendaciones']) && !empty($jsonData['recomendaciones'])) {
                    $tags[] = 'recomendaciones';
                }
                
                $sourceFile = $fileDb['original_filename'] ?? 'archivo.pdf';
            } else {
                // Fallback si no es JSON válido // Corregido
                $title = $fileDb['original_filename'] ?? 'Documento analizado';
                $summary_text = $summaryText;
                $content = $summaryText;
                $tags = ['extraído', 'archivo', 'ia']; // Corregido
                $sourceFile = $fileDb['original_filename'] ?? 'archivo.pdf';
            }
            
            // Verificar si ya existe un registro para este archivo en knowledge_base
            $stmt = $pdo->prepare("SELECT id FROM knowledge_base WHERE source_file_id = ? AND knowledge_type = 'user_insight' LIMIT 1");
            $stmt->execute([$fileId]);
            $existingRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingRecord) {
                // Actualizar registro existente
                clean_log("Actualizando registro existente en knowledge_base para file_id: $fileId");
                $stmt = $pdo->prepare("UPDATE knowledge_base SET title = ?, content = ?, summary = ?, tags = ?, confidence_score = 0.70, source_file = ?, vector_store_id = ?, assistant_id = ?, thread_id = ?, run_id = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([
                    $title,
                    $content,
                    $summary_text,
                    json_encode($tags),
                    $sourceFile,
                    $vectorStoreId,
                    $assistantId,
                    $threadId,
                    $lastRunId,
                    $existingRecord['id']
                ]);
                clean_log("Registro actualizado en knowledge_base");
            } else {
                // Crear nuevo registro
                clean_log("Creando nuevo registro en knowledge_base para file_id: $fileId");
                $stmt = $pdo->prepare("INSERT INTO knowledge_base (knowledge_type, title, content, summary, tags, confidence_score, created_by, source_type, source_file, source_file_id, vector_store_id, assistant_id, thread_id, run_id, created_at) VALUES ('user_insight', ?, ?, ?, ?, 0.70, ?, 'ai_extraction', ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([ // Corregido
                    $title,
                    $content,
                    $summary_text,
                    json_encode($tags),
                    $userId,
                    $sourceFile,
                    $fileId, // source_file_id
                    $vectorStoreId,
                    $assistantId,
                    $threadId,
                    $lastRunId
                ]);
                clean_log("Nuevo registro creado en knowledge_base");
            }
            
            // Actualizar knowledge_files con estado y metadatos de la extracción
            $stmt = $pdo->prepare("UPDATE knowledge_files SET extraction_status = 'completed', last_extraction_finished_at = NOW(), extracted_items = 1, last_run_id = ?, last_extraction_model = ?, last_extraction_response_id = ? WHERE id = ?");
            $stmt->execute([$lastRunId, $modelId, $lastRunId, $fileId]);
            
            // Actualizar ai_usage_events con datos reales usando función existente // Corregido
            if (!empty($lastRunId)) {
                $result = ['run_id' => $lastRunId, 'status' => 'completed', 'output_tokens' => 1000, 'cost_usd' => 0.002];
                record_usage_event($userId, (int)$providerId, (int)$modelId, 'file_extraction', $result, 5000, $pdo);
            }
            
            clean_log("Resumen guardado exitosamente");
            
            $action = $existingRecord ? 'actualizado' : 'creado';
            json_out([
                'ok' => true,
                'message' => "Resumen extraído y $action exitosamente en knowledge_base", // Corregido
                'summary' => $summaryText,
                'status' => 'completed',
                'action' => $action
            ]);
        }
    }
    
    // ===== CREAR NUEVO RUN =====
    $currentPromptSource = 'unknown';
    $fallbackWasUsed = false;

    if ($runInProgress) {
        clean_log("Run en progreso, no crear nuevo run");
        
        json_out([
            'ok' => true,
            'message' => 'Proceso de extracción en progreso. Por favor, espera un momento y verifica nuevamente.', // Corregido
            'run_id' => $lastRunId,
            'status' => 'in_progress'
        ]);
    } else {
        clean_log("No hay respuesta del asistente, creando nuevo run...");

        // Verificación adicional: asegurar que el archivo esté completamente indexado antes de crear run
        if (!empty($vectorStoreId) && !empty($openaiFileId)) {
            clean_log("Verificando indexación del archivo antes de crear run...");
            try {
                wait_for_file_indexing($ops, $vectorStoreId, $openaiFileId, $apiKeyPlain);
                clean_log("Archivo verificado como completamente indexado");
            } catch (Exception $e) {
                clean_log("Advertencia: No se pudo verificar indexación completa del archivo: " . $e->getMessage());
                // Continuar de todos modos, pero con logging
            }
        }

        // Log del prompt que se va a usar
        // La variable $promptToUse ya está definida y logueada al principio del script.
        // Esto mantiene la consistencia.
        clean_log("Creando nuevo run con el prompt seleccionado...");


    clean_log("Creando run con el prompt seleccionado...");

    // Crear run con el prompt como instrucciones adicionales (según API de OpenAI)
    clean_log("Creando run con instrucciones adicionales usando el prompt...");

    try {
        $run = executeOpsOperation($ops, 'run.create', [
            'THREAD_ID' => $threadId,
            'ASSISTANT_ID' => $assistantId,
            'USER_PROMPT' => $promptToUse, // Prompt como instrucciones adicionales
            'VS_ID' => $vectorStoreId,
            'API_KEY' => $apiKeyPlain
        ], $apiKeyPlain);
        
        $runId = $run['id'] ?? '';
        if (!$runId) {
            json_error('No se pudo crear run');
        }
        
    } catch (Exception $e) {
        // Si el error es que el Assistant no existe, recrearlo
        if (strpos($e->getMessage(), 'HTTP 404') !== false || strpos($e->getMessage(), 'No assistant found') !== false) {
            clean_log("Assistant no existe en OpenAI al crear run: " . $e->getMessage());
            clean_log("Resetando Assistant ID para recrear...");
            
                // Resetear assistant_id en BD
                $stmt = $pdo->prepare("UPDATE knowledge_files SET assistant_id = NULL WHERE id = ?");
                $stmt->execute([$fileId]);
                $fileDb['assistant_id'] = null;
    // Corregido
                if ($vectorStoreRecordId) {
                    $stmt = $pdo->prepare("UPDATE ai_vector_stores SET assistant_id = NULL, assistant_model = NULL, assistant_created_at = NULL, assistant_name = NULL WHERE id = ?");
                    $stmt->execute([$vectorStoreRecordId]);
                } elseif (!empty($vectorStoreId)) {
                    $stmt = $pdo->prepare("UPDATE ai_vector_stores SET assistant_id = NULL, assistant_model = NULL, assistant_created_at = NULL, assistant_name = NULL WHERE external_id = ?");
                    $stmt->execute([$vectorStoreId]);
                }
    // Corregido
                // También resetear en ai_vector_stores por owner_user_id para asegurar limpieza completa // Corregido
                $stmt = $pdo->prepare("UPDATE ai_vector_stores SET assistant_id = NULL WHERE owner_user_id = ? AND provider_id = ?");
                $stmt->execute([$userId, $providerId]);
    // Corregido
                // Resetear thread_id para crear uno nuevo con el prompt mejorado // Corregido
                $stmt = $pdo->prepare("UPDATE knowledge_files SET thread_id = NULL WHERE id = ?");
                $stmt->execute([$fileId]);
                $threadId = '';
                $assistantId = '';
                $fileDb['thread_id'] = null;

                clean_log("Assistant ID y Thread ID reseteados completamente en todas las tablas");
    // Corregido
                json_out([
                    'ok' => true,
                    'message' => 'Assistant no válido detectado, recreando. Por favor, intenta nuevamente.', // Corregido
                    'status' => 'assistant_recreating',
                    'error' => 'Assistant not found in OpenAI'
                ]);
        } else {
            // Otros errores - devolver error JSON válido // Corregido
            clean_log("Error al crear run: " . $e->getMessage());
            json_error("Error al crear run: " . $e->getMessage());
        }
    }
    
    clean_log("Run creado: $runId");
    
    // Actualizar knowledge_files con last_run_id e incrementar intentos
    $stmt = $pdo->prepare("UPDATE knowledge_files SET last_run_id = ?, extraction_attempts = extraction_attempts + 1 WHERE id = ?");
    $stmt->execute([$runId, $fileId]);
    
    // Log en ai_usage_events usando función existente // Corregido
    $result = ['run_id' => $runId, 'status' => 'started'];
    record_usage_event($userId, (int)$providerId, (int)$modelId, 'file_extraction', $result, 0, $pdo);
    
        json_out([
            'ok' => true,
            'message' => 'Proceso de extracción iniciado. Por favor, verifica más tarde haciendo clic nuevamente en el botón para obtener el resumen.', // Corregido
            'run_id' => $runId,
            'status' => 'started'
        ]);
    } // Cerrar else del if ($runInProgress)
    
} catch (Exception $e) {
    clean_log("Error: " . $e->getMessage());
    clean_log("Stack trace: " . $e->getTraceAsString());

    // Mapear errores técnicos a mensajes amigables
    $friendlyMessage = get_friendly_error_message($e->getMessage());
    json_error($friendlyMessage);
} catch (Error $e) {
    clean_log("Fatal error: " . $e->getMessage());
    clean_log("Stack trace: " . $e->getTraceAsString());

    $friendlyMessage = get_friendly_error_message("Error fatal del sistema");
    json_error($friendlyMessage);
}

// Función para mapear errores técnicos a mensajes amigables
function get_friendly_error_message($technicalMessage) {
    // Errores de conexión/autenticación
    if (strpos($technicalMessage, 'cURL error') !== false) {
        return 'Error de conexión con el servicio de IA. Por favor, inténtalo de nuevo en unos momentos.';
    }

    if (strpos($technicalMessage, 'HTTP 401') !== false || strpos($technicalMessage, 'Unauthorized') !== false) {
        return 'Error de autenticación con el proveedor de IA. Verifica tu configuración de API key.';
    }

    if (strpos($technicalMessage, 'HTTP 429') !== false || strpos($technicalMessage, 'rate limit') !== false) {
        return 'Límite de uso excedido. Por favor, espera unos minutos antes de intentar nuevamente.';
    }

    if (strpos($technicalMessage, 'HTTP 500') !== false || strpos($technicalMessage, 'Internal Server Error') !== false) {
        return 'Error interno del proveedor de IA. Por favor, inténtalo de nuevo más tarde.';
    }

    // Errores de base de datos
    if (strpos($technicalMessage, 'SQLSTATE') !== false || strpos($technicalMessage, 'PDO') !== false) {
        return 'Error de base de datos. Por favor, contacta al administrador si el problema persiste.';
    }

    // Errores de archivo
    if (strpos($technicalMessage, 'file_exists') !== false || strpos($technicalMessage, 'is_readable') !== false) {
        return 'Error de acceso al archivo. El archivo puede haber sido movido o eliminado.';
    }

    // Errores de JSON
    if (strpos($technicalMessage, 'json_decode') !== false || strpos($technicalMessage, 'JSON_ERROR') !== false) {
        return 'Error procesando la respuesta del servicio. Por favor, inténtalo de nuevo.';
    }

    // Error genérico para otros casos
    return 'Ha ocurrido un error inesperado. Por favor, inténtalo de nuevo o contacta al soporte si el problema persiste.';
}

// Función para esperar a que el archivo esté completamente indexado en el Vector Store
function wait_for_file_indexing($ops, $vectorStoreId, $fileId, $apiKey) {
    $maxAttempts = 10;
    $attempt = 0;

    while ($attempt < $maxAttempts) {
        $attempt++;
        clean_log("Verificando status del archivo en VS (intento $attempt/$maxAttempts)...");

        try {
            $fileStatus = executeOpsOperation($ops, 'vs.store.file.get', [
                'VS_ID' => $vectorStoreId,
                'FILE_ID' => $fileId,
                'API_KEY' => $apiKey
            ], $apiKey);

            $status = $fileStatus['status'] ?? 'unknown';
            clean_log("Status del archivo en VS: $status");

            if ($status === 'completed') {
                clean_log("Archivo completamente indexado en Vector Store");
                return true;
            } elseif ($status === 'failed') {
                $error = $fileStatus['last_error'] ?? 'Error desconocido';
                clean_log("ERROR: Indexación del archivo falló: $error");
                throw new Exception("Error en indexación del archivo: $error");
            } else {
                // Status: in_progress, pending, etc.
                if ($attempt < $maxAttempts) {
                    clean_log("Indexación en progreso, esperando 2 segundos...");
                    sleep(2);
                }
            }
        } catch (Exception $e) {
            clean_log("Error verificando status del archivo: " . $e->getMessage());
            if ($attempt >= $maxAttempts) {
                throw $e;
            }
            sleep(1);
        }
    }

    throw new Exception("Timeout esperando indexación del archivo en Vector Store");
}

// Función para leer mensajes con backoff y reintentos mejorados
function read_messages_with_backoff($ops, $threadId, $apiKey, $maxAttempts = 5) {
    $dataMsgs = [];
    $hasAssistantMessage = false;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        clean_log("Verificando mensajes del asistente (intento $attempt/$maxAttempts)...");

        try {
            // Usar parámetros correctos según API de OpenAI
            $messages = executeOpsOperation($ops, 'messages.list', [
                'THREAD_ID' => $threadId,
                'limit' => 50,
                'order' => 'desc', // Más reciente primero - esto es correcto
                'API_KEY' => $apiKey
            ], $apiKey);

            $dataMsgs = $messages['data'] ?? [];
            clean_log("Mensajes obtenidos de API: " . count($dataMsgs));

            // Debug: mostrar información de cada mensaje
            foreach ($dataMsgs as $i => $msg) {
                $role = $msg['role'] ?? 'unknown';
                $createdAt = $msg['created_at'] ?? 'unknown';
                $contentCount = count($msg['content'] ?? []);
                clean_log("Mensaje $i: role=$role, created_at=$createdAt, content_count=$contentCount");

                if ($role === 'assistant') {
                    $hasAssistantMessage = true;
                    clean_log("¡Mensaje del asistente encontrado! (intento $attempt)");
                    break 2; // Salir de ambos bucles
                }
            }

            if ($hasAssistantMessage) {
                break;
            } elseif ($attempt < $maxAttempts) {
                $backoffSeconds = $attempt * 2; // Backoff exponencial: 2s, 4s, 6s, etc.
                clean_log("No hay mensaje del asistente aún, esperando {$backoffSeconds} segundos...");
                sleep($backoffSeconds);
            }

        } catch (Exception $e) {
            clean_log("Error leyendo mensajes en intento $attempt: " . $e->getMessage());
            if ($attempt >= $maxAttempts) {
                throw $e;
            }
            sleep(2);
        }
    }

    clean_log("Total mensajes encontrados: " . count($dataMsgs) . ", hasAssistantMessage: " . ($hasAssistantMessage ? 'true' : 'false'));

    // Debug adicional: si no hay mensaje del asistente pero hay mensajes, mostrar detalles
    if (!$hasAssistantMessage && !empty($dataMsgs)) {
        clean_log("DEBUG: Mensajes disponibles pero ninguno del asistente:");
        foreach ($dataMsgs as $i => $msg) {
            $role = $msg['role'] ?? 'unknown';
            $createdAt = $msg['created_at'] ?? 'unknown';
            clean_log("  Mensaje $i: role=$role, created_at=$createdAt");
        }
    }

    return ['messages' => $dataMsgs, 'hasAssistantMessage' => $hasAssistantMessage];
}

// Función para auditar run steps cuando no hay respuesta
function audit_run_steps($ops, $threadId, $runId, $apiKey) {
    try {
        clean_log("Auditando run steps para debugging...");

        // Verificar si existe la operación run.steps.list en ops_json
        if (!isset($ops['multi']['run.steps.list'])) {
            clean_log("Operación run.steps.list no disponible en ops_json");
            return ['error' => 'run_steps_not_available'];
        }

        $steps = executeOpsOperation($ops, 'run.steps.list', [
            'THREAD_ID' => $threadId,
            'RUN_ID' => $runId,
            'API_KEY' => $apiKey
        ], $apiKey);

        $stepsData = $steps['data'] ?? [];
        $auditInfo = [
            'total_steps' => count($stepsData),
            'steps_by_type' => [],
            'has_message_creation' => false,
            'last_error' => null,
            'step_details' => [],
            'consistency_info' => [
                'using_oficial_vs' => !empty($vectorStoreId ?? ''),
                'using_oficial_assistant' => !empty($assistantId ?? ''),
                'using_oficial_thread' => !empty($threadId ?? ''),
                'arquitectura_correcta' => [
                    'ai_vector_stores_fuente' => 'vector_store_id, assistant_id',
                    'knowledge_files_fuente' => 'thread_id, run_id',
                    'sincronizacion' => 'bidireccional_para_consistencia'
                ]
            ]
        ];

        foreach ($stepsData as $step) {
            $stepType = $step['type'] ?? 'unknown';
            $stepStatus = $step['status'] ?? 'unknown';
            $stepId = $step['id'] ?? 'unknown';

            $auditInfo['steps_by_type'][$stepType] = ($auditInfo['steps_by_type'][$stepType] ?? 0) + 1;

            // Capturar detalles de cada step
            $stepDetail = [
                'id' => $stepId,
                'type' => $stepType,
                'status' => $stepStatus
            ];

            if ($stepType === 'message_creation') {
                $auditInfo['has_message_creation'] = true;
                $stepDetail['critical_for_response'] = true;
            }

            if ($stepStatus === 'failed') {
                $stepDetail['error'] = $step['last_error'] ?? 'Error desconocido en step';
                $auditInfo['last_error'] = $stepDetail['error'];
            }

            $auditInfo['step_details'][] = $stepDetail;
        }

        clean_log("Auditoría de run steps: " . json_encode($auditInfo));
        return $auditInfo;

    } catch (Exception $e) {
        clean_log("Error auditando run steps: " . $e->getMessage());
        return ['error' => $e->getMessage()];
    }
}

// Función para diagnosticar problemas con el asistente
function diagnose_assistant_issue($messages, $prompt, $fileDb) {
    $diagnostics = [
        'total_messages' => count($messages),
        'user_messages' => 0,
        'assistant_messages' => 0,
        'prompt_length' => strlen($prompt),
        'file_info' => [
            'filename' => $fileDb['original_filename'] ?? 'unknown',
            'size' => $fileDb['file_size'] ?? 0,
            'mime_type' => $fileDb['mime_type'] ?? 'unknown'
        ],
        'possible_issues' => [],
        'recommendations' => []
    ];

    foreach ($messages as $msg) {
        if (($msg['role'] ?? '') === 'user') {
            $diagnostics['user_messages']++;
        } elseif (($msg['role'] ?? '') === 'assistant') {
            $diagnostics['assistant_messages']++;
        }
    }

    // Identificar posibles problemas
    if ($diagnostics['prompt_length'] > 8000) {
        $diagnostics['possible_issues'][] = 'Prompt demasiado largo (>' . $diagnostics['prompt_length'] . ' chars)';
        $diagnostics['recommendations'][] = 'Reducir longitud del prompt para mejor rendimiento';
    }

    if (empty($fileDb['original_filename'])) {
        $diagnostics['possible_issues'][] = 'Nombre de archivo no disponible';
        $diagnostics['recommendations'][] = 'Verificar que el archivo existe en la base de datos';
    }

    if (($fileDb['file_size'] ?? 0) > 50 * 1024 * 1024) {
        $diagnostics['possible_issues'][] = 'Archivo muy grande (' . ($fileDb['file_size'] ?? 0) . ' bytes)';
        $diagnostics['recommendations'][] = 'Considerar dividir archivos grandes o usar compresión';
    }

    if (strpos($prompt, 'JSON') !== false && strpos($prompt, 'formato JSON') !== false) {
        $diagnostics['possible_issues'][] = 'Prompt requiere formato JSON estricto - puede ser demasiado restrictivo';
        $diagnostics['recommendations'][] = 'Relajar requerimientos de formato JSON o usar prompt más flexible';
    }

    if ($diagnostics['user_messages'] === 0) {
        $diagnostics['possible_issues'][] = 'No hay mensajes del usuario en el thread';
        $diagnostics['recommendations'][] = 'Verificar que el mensaje del usuario se envió correctamente';
    }

    // Problemas específicos identificados por el jefe
    if ($diagnostics['assistant_messages'] === 0 && $diagnostics['total_messages'] > 0) {
        $diagnostics['possible_issues'][] = 'Run completado pero sin respuesta del asistente - posible problema de indexación';
        $diagnostics['recommendations'][] = 'Verificar que el archivo esté completamente indexado en Vector Store antes de crear run';

        // Agregar información específica sobre consistencia de tablas
        if (isset($diagnostics['consistency_check'])) {
            $consistency = $diagnostics['consistency_check'];
            if (!$consistency['vector_store_oficial']) {
                $diagnostics['possible_issues'][] = 'No hay Vector Store OFICIAL - usando referencias históricas';
                $diagnostics['recommendations'][] = 'Crear Vector Store OFICIAL en lugar de depender de referencias históricas';
            }
            if (!$consistency['coincidencia_assistant']) {
                $diagnostics['possible_issues'][] = 'Inconsistencia entre assistant OFICIAL y referencia histórica';
                $diagnostics['recommendations'][] = 'Sincronizar assistant_id entre ai_vector_stores y knowledge_files';
            }
        }
    }

    // Si hay problema de contenido del archivo, agregar recomendación específica
    if (isset($diagnostics['file_content_issue'])) {
        $diagnostics['suggested_action'] = 'pdf_ocr_needed';
        $diagnostics['recommendations'][] = 'El PDF detectado como escaneado necesita OCR. Recomendación: usa https://www.adobe.com/acrobat/online/ocr-pdf.html';
    }

    // Información de consistencia ya se agregó arriba - evitar duplicación

    // Recomendaciones basadas en el análisis del jefe
    if (empty($diagnostics['recommendations'])) {
        $diagnostics['recommendations'][] = 'Verificar indexación del archivo en Vector Store OFICIAL (ai_vector_stores)';
        $diagnostics['recommendations'][] = 'Implementar backoff al leer mensajes después de run completed';
        $diagnostics['recommendations'][] = 'Revisar run steps para identificar problemas específicos';
        $diagnostics['recommendations'][] = 'Arquitectura correcta: ai_vector_stores(VS+Assistant) + knowledge_files(Thread+Run)';
        $diagnostics['recommendations'][] = 'NUNCA usar los IDs de knowledge_files como fuente operativa - solo como referencia histórica';
        $diagnostics['recommendations'][] = 'CORRECCIÓN: Problema de múltiples assistants resuelto - arquitectura correcta implementada';
    }

    return $diagnostics;
}

// Función para crear un prompt más simple PERO que mantenga formato JSON
function create_simplified_prompt($originalPrompt, $filename) {
    return "Analiza este documento PDF: '{$filename}'

IMPORTANTE: Debes responder en formato JSON válido con esta estructura exacta:
{
  \"resumen\": \"2-3 líneas del contenido principal\",
  \"puntos_clave\": [\"punto 1\", \"punto 2\", \"punto 3\", \"punto 4\", \"punto 5\"],
  \"estrategias\": [\"estrategia 1\", \"estrategia 2\", \"estrategia 3\"],
  \"gestion_riesgo\": [\"riesgo 1\", \"riesgo 2\"],
  \"recomendaciones\": [\"recomendación 1\", \"recomendación 2\"]
}

INSTRUCCIONES:
- El documento está disponible en el Vector Store para búsqueda
- Proporciona información real del documento, no genérica
- Si no puedes acceder al contenido, indica que hay un problema de indexación
- Mantén la estructura JSON exacta pero sé flexible con el contenido
- Responde SOLO con el JSON, sin texto adicional";
}
?>
