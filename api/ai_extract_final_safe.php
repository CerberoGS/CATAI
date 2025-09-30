<?php
// /api/ai_extract_final_safe.php
declare(strict_types=1);

// Log simple para verificar que el archivo se carga
error_log("=== AI_EXTRACT_FINAL_SAFE.PHP ARCHIVO CARGADO ===");

// Cargar archivos básicos necesarios
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

error_log("=== ARCHIVOS BÁSICOS CARGADOS ===");

// Establecer headers JSON inmediatamente
json_header();

// Cargar archivos opcionales solo si existen
if (file_exists(__DIR__ . '/crypto.php')) {
    require_once __DIR__ . '/crypto.php';
    error_log("=== CRYPTO.PHP CARGADO ===");
}

if (file_exists(__DIR__ . '/Crypto_safe.php')) {
    require_once __DIR__ . '/Crypto_safe.php';
    error_log("=== CRYPTO_SAFE.PHP CARGADO ===");
}

if (file_exists(__DIR__ . '/run_op_safe.php')) {
    require_once __DIR__ . '/run_op_safe.php';
    error_log("=== RUN_OP_SAFE.PHP CARGADO ===");
}

/**
 * Asegura que el archivo esté en OpenAI y devuelve el file_id válido
 */
function ensureOpenAIFileId(PDO $pdo, array $kf, array $user, string $filePath): string {
    // Usar datos ya guardados en la BD (más eficiente)
    $mime = $kf['mime_type'];                    // Ya está en la BD
    $origName = $kf['original_filename'];        // Ya está en la BD
    $fileSize = $kf['file_size'];                // Ya está en la BD

    if (!$filePath || !is_readable($filePath)) {
        json_error('Archivo no encontrado o no legible en el servidor.');
    }

    $openaiKey = get_api_key_for($user['id'], 'openai');
    if (!$openaiKey) {
        json_error('OpenAI API key no configurada para este usuario.');
    }

    $id = $kf['openai_file_id'] ?? null;
    $needs = false;

    // Helper HTTP
    $curl = static function(string $url, array $opts) {
        $ch = curl_init();
        curl_setopt_array($ch, $opts + [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 300,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $body];
    };

    // Verificar existente
    if ($id) {
        [$code, $body] = $curl("https://api.openai.com/v1/files/{$id}", [
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$openaiKey}"],
        ]);

        if ($code === 200) {
            $info = json_decode($body, true) ?: [];
            $status = $info['status'] ?? null;
            $purpose = $info['purpose'] ?? null;
            $bytes = $info['bytes'] ?? null;

            // Si el archivo existe pero hay algo raro, marcamos re-upload
            if (in_array($status, ['error','deleted'], true)
             || $purpose !== 'assistants'
             || ($bytes !== null && (int)$bytes !== (int)$fileSize)) {
                $needs = true;
            } else {
                // OK: persistimos metadata útil
                $stmt = $pdo->prepare('UPDATE knowledge_files
                                     SET openai_file_verified_at=NOW()
                                     WHERE id=?');
                $stmt->execute([$kf['id']]);
                return $id;
            }
        } else {
            // 404/410 u otros → re-subir
            $needs = true;
        }
    } else {
        $needs = true;
    }

    // Subir/re-subir
    if ($needs) {
        $postFields = [
            'file' => new CURLFile($filePath, $mime, $origName),
            'purpose' => 'assistants',
        ];
        [$code, $body] = $curl('https://api.openai.com/v1/files', [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$openaiKey}"],
            CURLOPT_POSTFIELDS => $postFields,
        ]);

        if ($code !== 200) {
            // Intenta extraer mensaje claro y persiste para debug
            $msg = $body;
            if ($j = json_decode($body, true)) {
                $msg = $j['error']['message'] ?? $body;
            }
            $stmt = $pdo->prepare('UPDATE knowledge_files SET error_message=?, updated_at=NOW() WHERE id=?');
            $stmt->execute([substr($msg, 0, 500), $kf['id']]);
            json_error('Error subiendo archivo a OpenAI: ' . $msg);
        }

        $info = json_decode($body, true) ?: [];
        $idNew = $info['id'] ?? null;
        if (!$idNew) {
            json_error('Respuesta inválida de OpenAI al subir el archivo.');
        }

        // Persistir SIEMPRE el nuevo ID y metadata
        $stmt = $pdo->prepare('UPDATE knowledge_files
                             SET openai_file_id=?, upload_status=?, openai_file_verified_at=NOW(), error_message=NULL
                             WHERE id=?');
        $stmt->execute([$idNew, 'processed', $kf['id']]);

        return $idNew;
    }

    return $id;
}

/**
 * Función para ejecutar operaciones usando ops_json
 */
function executeOperationDirect(PDO $pdo, array $user, int $providerId, string $operation, array $params): array {
    $userId = (int)$user['id'];
    
    // Debug logs
    error_log("executeOperationDirect INICIO:");
    error_log("- providerId: $providerId");
    error_log("- operation: $operation");
    error_log("- params: " . json_encode($params, JSON_PRETTY_PRINT));
    
    // 1. Obtener proveedor de la BD con ops_json
    $stmt = $pdo->prepare('SELECT id, slug, name, base_url, ops_json FROM ai_providers WHERE id = ? AND status = "active"');
    $stmt->execute([$providerId]);
    $provider = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$provider) {
        throw new Exception("Proveedor con ID $providerId no encontrado");
    }
    
    error_log("- provider encontrado: " . $provider['name']);
    error_log("- ops_json length: " . strlen($provider['ops_json']));
    
    // 2. Parsear ops_json
    $opsJson = json_decode($provider['ops_json'], true);
    if (!$opsJson || !isset($opsJson['multi'])) {
        throw new Exception("Proveedor no tiene ops_json válido");
    }
    
    error_log("- ops_json parseado correctamente");
    error_log("- operaciones disponibles: " . implode(', ', array_keys($opsJson['multi'])));
    
    // 3. Verificar que la operación existe
    if (!isset($opsJson['multi'][$operation])) {
        throw new Exception("Operación '$operation' no encontrada en ops_json");
    }
    
    // 4. Obtener API key
    $apiKey = get_api_key_for($userId, $provider['slug']);
    if (!$apiKey) {
        throw new Exception("API key no encontrada para proveedor " . $provider['slug']);
    }
    
    error_log("- API key obtenida: " . substr($apiKey, 0, 10) . "...");
    
    // 5. Ejecutar operación usando ops_json
    $operationConfig = $opsJson['multi'][$operation];
    
    if (isset($operationConfig['pipeline'])) {
        error_log("- Ejecutando pipeline...");
        // Es un pipeline - ejecutar secuencialmente
        $result = executePipeline($provider, $apiKey, $operationConfig, $params, $userId, $pdo);
    } else {
        error_log("- Ejecutando operación simple...");
        // Es una operación simple
        $result = executeSimpleOperation($provider, $apiKey, $operationConfig, $params);
    }
    
    error_log("- Operación completada exitosamente");
    
    return [
        'ok' => true,
        'result' => $result
    ];
}

/**
 * Ejecutar pipeline de operaciones
 */
function executePipeline($provider, $apiKey, $pipelineConfig, $params, $userId, $pdo): array {
    $context = $params; // Contexto inicial
    
    // PASO PREVIO: Crear assistant si no se proporciona ASSISTANT_ID
    if (!isset($context['ASSISTANT_ID']) || empty($context['ASSISTANT_ID'])) {
        writeLog("Creando assistant para el pipeline...");
        $assistantResult = executeSimpleOperation($provider, $apiKey, getOperationConfig($provider, 'assistant.create'), [
            'VS_ID' => $context['VS_ID']
        ]);
        $context['ASSISTANT_ID'] = $assistantResult['id'];
        writeLog("Assistant creado con ID: " . $context['ASSISTANT_ID']);
    }
    
    foreach ($pipelineConfig['pipeline'] as $step) {
        $stepOperation = $step['use'];
        $saveAs = $step['save_as'] ?? null;
        $stepParams = array_merge($context, $step['vars'] ?? []);
        
        // Reemplazar variables en los parámetros
        $stepParams = replaceVariables($stepParams, $context);
        
        // Obtener configuración de la operación
        $operationConfig = getOperationConfig($provider, $stepOperation);
        
        // Ejecutar operación
        $stepResult = executeSimpleOperation($provider, $apiKey, $operationConfig, $stepParams);
        
        // Guardar resultado en contexto si se especifica
        if ($saveAs) {
            $context[$saveAs] = $stepResult;
        }
        
        // Extraer valor específico si se especifica
        if (isset($step['extract_path'])) {
            $extractedValue = extractValue($stepResult, $step['extract_path']);
            if ($extractedValue !== null) {
                $context[$saveAs] = $extractedValue;
            }
        }
        
        // MANEJO ESPECIAL PARA RUN.CREATE: Esperar que se complete
        if ($stepOperation === 'run.create' && isset($stepResult['id'])) {
            writeLog("Run creado con ID: " . $stepResult['id'] . ". Esperando completado...");
            $runId = $stepResult['id'];
            $threadId = $stepParams['THREAD_ID'];
            
            // Polling para esperar que el run se complete
            $maxAttempts = 30; // 30 intentos máximo (30 segundos)
            $attempt = 0;
            
            do {
                $attempt++;
                writeLog("Polling run (intento $attempt/$maxAttempts)...");
                
                sleep(1); // Esperar 1 segundo
                
                $runStatusResult = executeSimpleOperation($provider, $apiKey, getOperationConfig($provider, 'run.get'), [
                    'THREAD_ID' => $threadId,
                    'RUN_ID' => $runId
                ]);
                
                $status = $runStatusResult['status'] ?? 'unknown';
                writeLog("Status del run: $status");
                
                if ($status === 'completed') {
                    writeLog("Run completado exitosamente");
                    break;
                } elseif ($status === 'failed' || $status === 'cancelled' || $status === 'expired') {
                    writeLog("Run falló con status: $status");
                    throw new Exception("Run falló con status: $status");
                }
                
            } while ($attempt < $maxAttempts);
            
            if ($attempt >= $maxAttempts) {
                writeLog("Timeout esperando que el run se complete");
                throw new Exception("Timeout esperando que el run se complete");
            }
        }
    }
    
    // Devolver el resultado final
    return $context['msgs']['data'][0]['content'][0]['text']['value'] ?? 'No se pudo extraer el resultado';
}

/**
 * Ejecutar operación simple
 */
function executeSimpleOperation($provider, $apiKey, $operationConfig, $params): array {
    $method = $operationConfig['method'] ?? 'GET';
    $url = replaceVariables($operationConfig['url_override'], $params);
    $headers = [];
    
    // Debug logs
    writeLog("executeSimpleOperation DEBUG:");
    writeLog("- Method: $method");
    writeLog("- URL: $url");
    writeLog("- Params: " . json_encode($params, JSON_PRETTY_PRINT));
    
    // Construir headers (añadir Accept: application/json si no está)
    $headers = [];
    $hasAccept = false;
    $hasContentType = false;

    if (isset($operationConfig['headers'])) {
        foreach ($operationConfig['headers'] as $header) {
            $headerName = $header['name'];
            $headerValue = replaceVariables($header['value'], $params);
            if ($headerName === 'Authorization' && strpos($headerValue, '{{API_KEY}}') !== false) {
                $headerValue = str_replace('{{API_KEY}}', $apiKey, $headerValue);
            }
            if (strcasecmp($headerName, 'Accept') === 0) $hasAccept = true;
            if (strcasecmp($headerName, 'Content-Type') === 0) $hasContentType = true;
            $headers[] = "$headerName: $headerValue";
        }
    }
    if (!$hasAccept) $headers[] = "Accept: application/json";

    // Preparar body
    $body = null;
    $isJsonBody = false;
    if (isset($operationConfig['body'])) {
        $body = replaceVariables($operationConfig['body'], $params);
        // Si el cuerpo parece JSON, marcamos bandera
        $trim = ltrim($body);
        $isJsonBody = ($trim !== '' && ($trim[0] === '{' || $trim[0] === '['));
        if ($isJsonBody && !$hasContentType) {
            $headers[] = "Content-Type: application/json";
        }
        writeLog("- Body: $body");
    }

    // Ejecutar llamada HTTP
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 120,
    ]);

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response   = curl_exec($ch);
    $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType= curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error      = curl_error($ch);
    curl_close($ch);

    // Debug
    writeLog("executeSimpleOperation RESPONSE:");
    writeLog("- HTTP Code: $httpCode");
    writeLog("- Content-Type: " . ($contentType ?: 'N/A'));
    writeLog("- Error: " . ($error ?: 'None'));
    writeLog("- Response: " . substr((string)$response, 0, 500) . ((is_string($response) && strlen($response) > 500) ? '...' : ''));

    if ($error) {
        throw new Exception("Error cURL: $error");
    }
    if ($httpCode !== 200) {
        throw new Exception("Error HTTP $httpCode: " . (string)$response);
    }

    // Si no hay cuerpo, devuelve estructura mínima en vez de reventar
    if ($response === '' || $response === null) {
        return [
            'ok'          => true,
            'note'        => 'empty-body',
            'http_code'   => $httpCode,
            'content_type'=> $contentType,
            'raw'         => ''
        ];
    }

    // Intentar parsear JSON si el Content-Type sugiere JSON
    $looksJson = $contentType && stripos($contentType, 'json') !== false;
    $decoded = @json_decode($response, true);

    if ($decoded !== null && $decoded !== false) {
        return $decoded;
    }

    // Si no parece JSON o no decodifica, devolver raw sin lanzar excepción
    return [
        'ok'          => true,
        'note'        => 'non-json',
        'http_code'   => $httpCode,
        'content_type'=> $contentType,
        'raw'         => (string)$response
    ];
}

/**
 * Reemplazar variables en string o array
 */
function replaceVariables($template, $context) {
    if (is_string($template)) {
        foreach ($context as $key => $value) {
            $template = str_replace("{{$key}}", $value, $template);
        }
        return $template;
    } elseif (is_array($template)) {
        $result = [];
        foreach ($template as $key => $value) {
            $result[$key] = replaceVariables($value, $context);
        }
        return $result;
    }
    return $template;
}

/**
 * Obtener configuración de operación
 */
function getOperationConfig($provider, $operation): array {
    $opsJson = json_decode($provider['ops_json'], true);
    return $opsJson['multi'][$operation] ?? [];
}

/**
 * Extraer valor usando path
 */
function extractValue($data, $path): ?string {
    $keys = explode('.', $path);
    $current = $data;
    
    foreach ($keys as $key) {
        if (is_array($current) && isset($current[$key])) {
            $current = $current[$key];
        } else {
            return null;
        }
    }
    
    return is_string($current) ? $current : json_encode($current);
}

/**
 * Función genérica para extraer contenido usando runOp y ops_json
 */
function extractWithGenericAI(PDO $pdo, array $user, array $kf, string $filePath, string $prompt): array {
    $userId = (int)$user['id'];
    
    // 1. Obtener configuración del usuario
    $stmt = $pdo->prepare('SELECT ai_provider, ai_model, default_provider_id, default_model_id FROM user_settings WHERE user_id = ?');
    $stmt->execute([$userId]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $aiProvider = $settings['ai_provider'] ?? 'openai';
    $aiModel = $settings['ai_model'] ?? 'gpt-4o-mini';
    $defaultProviderId = $settings['default_provider_id'] ?? null;
    $defaultModelId = $settings['default_model_id'] ?? null;
    
    // 2. Obtener proveedor de la BD usando default_provider_id o fallback a slug
    if ($defaultProviderId) {
        $stmt = $pdo->prepare('SELECT id, slug, ops_json FROM ai_providers WHERE id = ? AND status = "active"');
        $stmt->execute([$defaultProviderId]);
        $provider = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // Fallback al método anterior
        $stmt = $pdo->prepare('SELECT id, slug, ops_json FROM ai_providers WHERE slug = ? AND status = "active"');
        $stmt->execute([$aiProvider]);
        $provider = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$provider) {
        throw new Exception("Proveedor de IA '$aiProvider' no encontrado o inactivo");
    }
    
    // Debug logs
    error_log("Provider encontrado:");
    error_log("- ID: " . $provider['id']);
    error_log("- Slug: " . $provider['slug']);
    error_log("- Has ops_json: " . (!empty($provider['ops_json']) ? 'YES' : 'NO'));
    
    // 3. Verificar que tiene ops_json
    $opsJson = json_decode($provider['ops_json'], true);
    if (!$opsJson || !isset($opsJson['multi'])) {
        throw new Exception("Proveedor '$aiProvider' no tiene configuración ops_json válida");
    }
    
    // 4. Buscar operación de File Search/Análisis
    $analysisOps = [];
    foreach ($opsJson['multi'] as $opName => $opConfig) {
        // Buscar operaciones de análisis que usen VS o File Search
        if (strpos($opName, 'summarize') !== false || 
            strpos($opName, 'extract') !== false || 
            strpos($opName, 'analyze') !== false ||
            (isset($opConfig['required_fields']) && in_array('FILE_ID', $opConfig['required_fields']))) {
            $analysisOps[$opName] = $opConfig;
        }
    }
    
    if (empty($analysisOps)) {
        throw new Exception("Proveedor '$aiProvider' no tiene operaciones de análisis configuradas");
    }
    
    // 5. Priorizar operaciones de VS/File Search
    $preferredOps = ['vs.summarize_from_vs', 'extract.knowledge_from_vs', 'vs.summary'];
    $opName = null;
    $opConfig = null;
    
    foreach ($preferredOps as $prefOp) {
        if (isset($analysisOps[$prefOp])) {
            $opName = $prefOp;
            $opConfig = $analysisOps[$prefOp];
            break;
        }
    }
    
    // Si no se encuentra una preferida, usar la primera disponible
    if (!$opName) {
        $opName = array_key_first($analysisOps);
        $opConfig = $analysisOps[$opName];
    }
    
    error_log("=== EXTRACCIÓN GENÉRICA ===");
    error_log("Proveedor: $aiProvider");
    error_log("Modelo: $aiModel");
    error_log("Operación: $opName");
    error_log("Configuración: " . json_encode($opConfig, JSON_PRETTY_PRINT));
    
    // 6. Preparar parámetros según la operación
    $params = [
        'PROMPT' => $prompt,
        'MODEL' => $aiModel
    ];
    
    // Si es operación de VS, agregar file_id
    if (isset($opConfig['required_fields']) && in_array('FILE_ID', $opConfig['required_fields'])) {
        $fileId = $kf['openai_file_id'] ?? null;
        if (!$fileId) {
            error_log("Archivo no tiene file_id de OpenAI, subiendo...");
            // Asegurar que el archivo esté en OpenAI
            $fileId = ensureOpenAIFileId($pdo, $kf, $user, $filePath);
        }
        $params['FILE_ID'] = $fileId;
        error_log("Usando FILE_ID: " . $fileId);
    }
    
    // 7. Ejecutar operación genérica
    error_log("Ejecutando runOp con:");
    error_log("- provider_id: " . $provider['id']);
    error_log("- provider_id type: " . gettype($provider['id']));
    error_log("- op: " . $opName);
    error_log("- op type: " . gettype($opName));
    error_log("- params: " . json_encode($params, JSON_PRETTY_PRINT));
    
    try {
        $result = executeOperationDirect($pdo, $user, $provider['id'], $opName, $params);
        
        if (!$result['ok']) {
            throw new Exception("Error en operación genérica: " . ($result['error'] ?? 'Error desconocido'));
        }
    } catch (Exception $e) {
        error_log("ERROR en extractWithGenericAI: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        throw $e;
    }
    
    return [
        'success' => true,
        'provider' => $aiProvider,
        'model' => $aiModel,
        'operation' => $opName,
        'result' => $result['data'] ?? $result,
        'message' => "Extracción exitosa usando sistema genérico"
    ];
}

try {
    // 1) Autenticación
    $user = require_user();
    $userId = (int)($user['id'] ?? 0);
    
    if ($userId <= 0) {
        json_out(['error' => 'invalid-user'], 401);
    }
    
    // 2) Obtener input
    $input = json_input();
    $fileId = (int)($input['file_id'] ?? 0);
    $useGeneric = (bool)($input['use_generic'] ?? false); // Nuevo parámetro para modo genérico
    
    if ($fileId <= 0) {
        json_out(['error' => 'file_id-required'], 400);
    }
    
    // 3) Obtener conexión a la base de datos
    $pdo = db();
    
    // 4) Obtener información del archivo
    $stmt = $pdo->prepare('SELECT * FROM knowledge_files WHERE id = ? AND user_id = ?');
    $stmt->execute([$fileId, $userId]);
    $kf = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$kf) {
        json_out(['error' => 'file-not-found'], 404);
    }
    
    // 4) Obtener configuración del usuario
    $stmt = $pdo->prepare('SELECT ai_provider, ai_model, default_provider_id, default_model_id FROM user_settings WHERE user_id = ?');
    $stmt->execute([$userId]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $aiProvider = $settings['ai_provider'] ?? 'openai';
    $aiModel = $settings['ai_model'] ?? 'gpt-4o-mini';
    $defaultProviderId = $settings['default_provider_id'] ?? null;
    $defaultModelId = $settings['default_model_id'] ?? null;
    
    // 5) Obtener API key usando la función estándar
    $apiKey = get_api_key_for($userId, $aiProvider);
    if (!$apiKey) {
        json_out(['error' => 'api-key-not-found', 'provider' => $aiProvider], 400);
    }
    
    // 6) Construir path del archivo
    $filePath = __DIR__ . '/uploads/knowledge/' . $userId . '/' . $kf['stored_filename'];
    
    if (!file_exists($filePath)) {
        json_out(['error' => 'file-not-found-physically', 'path' => $filePath], 404);
    }
    
    // 7) Usar datos ya guardados en la BD (más eficiente)
    $fileType = $kf['file_type'];        // Ya está en la BD
    $mimeType = $kf['mime_type'];        // Ya está en la BD
    $originalName = $kf['original_filename']; // Ya está en la BD
    $fileSize = $kf['file_size'];        // Ya está en la BD
    
    // 8) Leer contenido del archivo solo si es necesario (para otros IA o fallback)
    $fileContent = null;
    if ($aiProvider !== 'openai' || $fileType === 'image') {
        $fileContent = file_get_contents($filePath);
        $fileContent = substr($fileContent, 0, 3000); // Solo primeros 3000 caracteres
        $fileContent = preg_replace('/[^\x20-\x7E]/', '', $fileContent); // Solo ASCII
    }
    
    // 9) Variables para tracking de archivos (se asignan en cada case según necesidad)
    $openaiFileId = null;
    $fileAction = 'none';
    
    // 10) Hacer consulta a la IA según el proveedor configurado
    $startTime = microtime(true);
    $ch = curl_init();
    
    // Obtener prompt personalizado del usuario o usar predeterminado
    $stmt = $pdo->prepare('SELECT ai_prompt_ext_conten_file FROM user_settings WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $userSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $customPrompt = $userSettings['ai_prompt_ext_conten_file'] ?? $CONFIG['AI_PROMPT_EXTRACT_DEFAULT'];
    
    // Construir prompt solo si es necesario (para otros IA o fallback)
    $prompt = null;
    if ($fileContent) {
        $prompt = $customPrompt . "\n\nCONTENIDO DEL DOCUMENTO:\n" . $fileContent;
    }
    
    // MODO GENÉRICO: Usar runOp y ops_json siguiendo el flujo completo
    if ($useGeneric) {
        error_log("=== ACTIVANDO MODO GENÉRICO ===");
        
        // PASO 1: Verificar/crear file_id (igual que modo tradicional)
        $originalFileId = $kf['openai_file_id'] ?? null;
        $openaiFileId = ensureOpenAIFileId($pdo, $kf, $user, $filePath);
        $fileAction = $originalFileId ? 'using_existing_file' : 'uploaded_new_file';
        
        error_log("File ID: $openaiFileId, Action: $fileAction");
        
        // Obtener proveedor de la BD para runOp usando default_provider_id o fallback a slug
        if ($defaultProviderId) {
            $stmt = $pdo->prepare('SELECT id, slug, ops_json FROM ai_providers WHERE id = ? AND status = "active"');
            $stmt->execute([$defaultProviderId]);
            $provider = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            // Fallback al método anterior
            $stmt = $pdo->prepare('SELECT id, slug, ops_json FROM ai_providers WHERE slug = ? AND status = "active"');
            $stmt->execute([$aiProvider]);
            $provider = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        if (!$provider) {
            throw new Exception("Proveedor de IA '$aiProvider' no encontrado o inactivo");
        }
        
        // PASO 2: Obtener/crear Vector Store del usuario
        $stmt = $pdo->prepare("
            SELECT avs.id, avs.external_id, avs.name, avs.status
            FROM ai_vector_stores avs
            WHERE avs.owner_user_id = ? AND avs.status = 'ready'
            ORDER BY avs.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $vs = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$vs) {
            error_log("No hay Vector Store, creando uno...");
            // Crear Vector Store usando operación directa (por ahora usar función existente)
            $createVSResult = run_vector_store_operation($provider, $apiKey, null, [
                'USER_ID' => $userId,
                'NAME' => 'CATAI_VS_User_' . $userId . '_' . date('YmdHis')
            ], $userId, $pdo);
            
            if (!$createVSResult['ok']) {
                throw new Exception("Error creando Vector Store: " . ($createVSResult['error'] ?? 'Error desconocido'));
            }
            
            $vs = [
                'external_id' => $createVSResult['data']['vs_id'],
                'name' => $createVSResult['data']['vs_name'],
                'status' => 'ready'
            ];
        }
        
        error_log("Vector Store: " . $vs['external_id']);
        
        // PASO 3: Adjuntar archivo al Vector Store si no está
        $attachResult = executeOperationDirect($pdo, $user, $provider['id'], 'vs.attach', [
            'VS_ID' => $vs['external_id'],
            'FILE_ID' => $openaiFileId
        ]);
        
        if (!$attachResult['ok']) {
            error_log("Error adjuntando archivo al VS: " . ($attachResult['error'] ?? 'Error desconocido'));
            // Continuar aunque falle el attach
        } else {
            error_log("Archivo adjuntado exitosamente al VS");
        }
        
        // PASO 4: Ejecutar análisis usando File Search
        $analysisResult = executeOperationDirect($pdo, $user, $provider['id'], 'vs.summarize_from_vs', [
            'VS_ID' => $vs['external_id'],
            'PROMPT' => $customPrompt,
            'ASSISTANT_ID' => '' // Se creará automáticamente en el pipeline
        ]);
        
        if (!$analysisResult['ok']) {
            throw new Exception("Error en análisis: " . ($analysisResult['error'] ?? 'Error desconocido'));
        }
        
        // PASO 5: Procesar respuesta y guardar
        $aiResponse = $analysisResult['data']['summary'] ?? $analysisResult['data'];
        
        // Guardar en knowledge_base
        $stmt = $pdo->prepare('
            INSERT INTO knowledge_base (knowledge_type, title, content, summary, tags, confidence_score, created_by, source_file, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ');
        
        $summaryData = [
            'resumen' => $aiResponse,
            'provider' => $aiProvider,
            'model' => $aiModel,
            'vs_id' => $vs['external_id'],
            'file_id' => $openaiFileId,
            'mode' => 'generic'
        ];
        
        $stmt->execute([
            'user_insight',
            $kf['original_filename'] . ' - Análisis IA Genérico (' . strtoupper($aiProvider) . ')',
            json_encode($summaryData),
            $aiResponse,
            json_encode(['ai_extraction', 'generic', $aiProvider]),
            0.8,
            $userId,
            $kf['original_filename'],
            1
        ]);
        
        $knowledgeId = $pdo->lastInsertId();
        
        // Actualizar knowledge_files
        $stmt = $pdo->prepare('UPDATE knowledge_files SET extraction_status = ?, extracted_items = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute(['completed', 1, $kf['id']]);
        
        json_out([
            'ok' => true,
            'mode' => 'generic',
            'timestamp' => date('Y-m-d H:i:s'),
            'usuario' => ['id' => $userId, 'email' => $user['email']],
            'archivo' => [
                'id' => $kf['id'], 
                'nombre' => $kf['original_filename'],
                'openai_file_id' => $openaiFileId,
                'file_action' => $fileAction
            ],
            'ia' => ['provider' => $aiProvider, 'modelo' => $aiModel],
            'vector_store' => [
                'id' => $vs['external_id'],
                'name' => $vs['name']
            ],
            'resultado' => ['resumen' => $aiResponse],
            'guardado' => ['knowledge_id' => $knowledgeId, 'status' => 'GUARDADO EXITOSAMENTE CON MODO GENÉRICO']
        ]);
        
        return; // Salir del endpoint
    }
    
    // MODO TRADICIONAL: Consultas hardcodeadas por proveedor
    switch (strtolower($aiProvider)) {
        case 'gemini':
            $payload = [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => $prompt
                            ]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.3,
                    'maxOutputTokens' => 800
                ]
            ];
            
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $apiKey,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json'
                ],
                CURLOPT_POSTFIELDS => json_encode($payload)
            ]);
            break;
            
        case 'openai':
            // TODOS los archivos necesitan file_id (texto e imágenes)
            $originalFileId = $kf['openai_file_id'] ?? null;
            $openaiFileId = ensureOpenAIFileId($pdo, $kf, $user, $filePath);
            $fileAction = $originalFileId ? 'using_existing_file' : 'uploaded_new_file';
            
            // Determinar si es imagen para la consulta
            $imageTypes = ['image', 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'tiff', 'ico'];
            $isImage = in_array($fileType, $imageTypes);
            
            // Construir payload base (común para ambas rutas)
            $payload = [
                'model' => $aiModel,
                'temperature' => 0.3,
                'max_output_tokens' => 800,
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'knowledge_card',
                        'schema' => [
                            'type' => 'object',
                            'required' => ['resumen', 'puntos_clave', 'estrategias', 'gestion_riesgo', 'recomendaciones'],
                            'properties' => [
                                'resumen' => ['type' => 'string', 'description' => 'Resumen ejecutivo en 2-3 líneas'],
                                'puntos_clave' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '5-8 conceptos clave'],
                                'estrategias' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '3-5 estrategias de trading'],
                                'gestion_riesgo' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '2-3 puntos de gestión de riesgo'],
                                'recomendaciones' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '2-3 recomendaciones prácticas']
                            ],
                            'additionalProperties' => false
                        ]
                    ]
                ]
            ];
            
            // Construir input según tipo de archivo (SIEMPRE con file_id)
            if ($isImage) {
                // RUTA 1: Imágenes con file_id + OCR
                $payload['model'] = 'gpt-4-vision-preview'; // Cambiar modelo para imágenes
                $payload['input'] = [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'input_file',
                                'file_id' => $openaiFileId
                            ],
                            [
                                'type' => 'input_text',
                                'text' => $customPrompt . "\n\nAnaliza esta imagen y extrae el contenido de texto usando OCR:"
                            ]
                        ]
                    ]
                ];
            } else {
                // RUTA 2: Archivos de texto con file_id
                $payload['input'] = [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'input_file',
                                'file_id' => $openaiFileId
                            ],
                            [
                                'type' => 'input_text',
                                'text' => $customPrompt
                            ]
                        ]
                    ]
                ];
            }
            
            // Determinar endpoint según tipo de archivo
            $endpoint = $isImage 
                ? 'https://api.openai.com/v1/chat/completions'  // Para imágenes con OCR
                : 'https://api.openai.com/v1/responses';        // Para archivos de texto
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $endpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json'
                ],
                CURLOPT_POSTFIELDS => json_encode($payload)
            ]);
            break;
            
        case 'claude':
            $payload = [
                'model' => $aiModel,
                'max_tokens' => 800,
                'temperature' => 0.3,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ]
            ];
            
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://api.anthropic.com/v1/messages',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'x-api-key: ' . $apiKey,
                    'Content-Type: application/json',
                    'anthropic-version: 2023-06-01'
                ],
                CURLOPT_POSTFIELDS => json_encode($payload)
            ]);
            break;
            
        case 'xai':
            $payload = [
                'model' => $aiModel,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'max_tokens' => 800,
                'temperature' => 0.3
            ];
            
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://api.x.ai/v1/chat/completions',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json'
                ],
                CURLOPT_POSTFIELDS => json_encode($payload)
            ]);
            break;
            
        case 'deepseek':
            $payload = [
                'model' => $aiModel,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'max_tokens' => 800,
                'temperature' => 0.3
            ];
            
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://api.deepseek.com/v1/chat/completions',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json'
                ],
                CURLOPT_POSTFIELDS => json_encode($payload)
            ]);
            break;
            
        default:
            json_out(['error' => 'unsupported-ai-provider', 'provider' => $aiProvider], 400);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $endTime = microtime(true);
    $latency = round(($endTime - $startTime) * 1000, 2);
    
    curl_close($ch);
    
    if ($curlError) {
        json_out(['error' => 'curl-error', 'detail' => $curlError], 500);
    }
    
    if ($httpCode !== 200) {
        json_out(['error' => 'ai-api-error', 'http_code' => $httpCode, 'response' => substr($response, 0, 500)], 500);
    }
    
    // 10) Procesar respuesta de la IA según el proveedor
    $responseData = json_decode($response, true);
    
    switch (strtolower($aiProvider)) {
        case 'gemini':
            if (!isset($responseData['candidates']) || empty($responseData['candidates'])) {
                json_out(['error' => 'gemini-no-content'], 500);
            }
            $aiResponse = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '';
            break;
            
        case 'openai':
            // Para OpenAI /v1/responses, la estructura es diferente
            if (!isset($responseData['output']) || empty($responseData['output'])) {
                json_out(['error' => 'openai-no-content'], 500);
            }
            $output = $responseData['output'][0];
            if (!isset($output['content']) || empty($output['content'])) {
                json_out(['error' => 'openai-empty-content'], 500);
            }
            $content = $output['content'][0];
            $aiResponse = $content['text'] ?? '';
            break;
            
        case 'xai':
        case 'deepseek':
            if (!isset($responseData['choices']) || empty($responseData['choices'])) {
                json_out(['error' => 'openai-no-content'], 500);
            }
            $aiResponse = $responseData['choices'][0]['message']['content'] ?? '';
            break;
            
        case 'claude':
            if (!isset($responseData['content']) || empty($responseData['content'])) {
                json_out(['error' => 'claude-no-content'], 500);
            }
            $aiResponse = $responseData['content'][0]['text'] ?? '';
            break;
            
        default:
            json_out(['error' => 'unsupported-ai-provider-response'], 500);
    }
    
    if (empty($aiResponse)) {
        json_out(['error' => 'empty-ai-response'], 500);
    }
    
    // 11) Guardar en base de datos
    $summaryData = [
        'resumen' => $aiResponse,
        'puntos_clave' => ['Análisis completado'],
        'estrategias' => ['Revisar contenido para estrategias'],
        'gestion_riesgo' => ['Evaluar riesgos basados en análisis'],
        'recomendaciones' => ['Seguir recomendaciones del análisis']
    ];
    
    $knowledgeData = [
        'knowledge_type' => 'user_insight',
        'title' => $kf['original_filename'] . ' - Análisis IA (' . strtoupper($aiProvider) . ')',
        'content' => json_encode($summaryData),
        'summary' => $aiResponse,
        'tags' => json_encode(['ai_extraction', 'trading', $aiProvider]),
        'confidence_score' => 0.8,
        'created_by' => $userId,
        'source_file' => $kf['original_filename'],
        'is_active' => 1
    ];
    
    $stmt = $pdo->prepare('
        INSERT INTO knowledge_base (knowledge_type, title, content, summary, tags, confidence_score, created_by, source_file, is_active, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ');
    
    $stmt->execute([
        $knowledgeData['knowledge_type'],
        $knowledgeData['title'],
        $knowledgeData['content'],
        $knowledgeData['summary'],
        $knowledgeData['tags'],
        $knowledgeData['confidence_score'],
        $knowledgeData['created_by'],
        $knowledgeData['source_file'],
        $knowledgeData['is_active']
    ]);
    
    $knowledgeId = $pdo->lastInsertId();
    
    // 12) Actualizar knowledge_files
    $stmt = $pdo->prepare('
        UPDATE knowledge_files 
        SET extraction_status = ?, extracted_items = ?, updated_at = NOW()
        WHERE id = ?
    ');
    $stmt->execute(['completed', 1, $kf['id']]);
    
    // 13) Respuesta exitosa
    json_out([
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'usuario' => [
            'id' => $userId,
            'email' => $user['email']
        ],
        'archivo' => [
            'id' => $kf['id'],
            'nombre' => $originalName,                    // Usar datos de BD
            'tipo' => $fileType,                          // Usar datos de BD
            'mime_type' => $mimeType,                     // Usar datos de BD
            'tamaño_mb' => round($fileSize / 1024 / 1024, 2), // Usar datos de BD
            'openai_file_id' => $openaiFileId,
            'file_action' => $fileAction
        ],
        'ia' => [
            'provider' => $aiProvider,
            'modelo' => $aiModel
        ],
        'prompt_used' => !empty($userSettings['ai_prompt_ext_conten_file']),
        'consulta' => [
            'latencia_ms' => $latency,
            'http_code' => $httpCode,
            'caracteres_procesados' => $fileContent ? strlen($fileContent) : 0,
            'usando_file_id' => !empty($openaiFileId)
        ],
        'resultado' => [
            'resumen' => $aiResponse,
            'puntos_clave' => ['Análisis completado'],
            'estrategias' => ['Revisar contenido para estrategias'],
            'gestion_riesgo' => ['Evaluar riesgos basados en análisis'],
            'recomendaciones' => ['Seguir recomendaciones del análisis']
        ],
        'guardado' => [
            'knowledge_id' => $knowledgeId,
            'status' => 'GUARDADO EXITOSAMENTE'
        ]
    ], 200);
    
} catch (Throwable $e) {
    json_out(['error' => 'extraction-failed', 'detail' => $e->getMessage()], 500);
}

// ============================================================================
// FUNCIONES CONSOLIDADAS DE run_op_safe.php
// ============================================================================

/**
 * Operación vs.upload: Subir archivo a proveedor de IA
 */
function run_upload_operation($provider, $api_key, $project_id, $params, $user_id, $pdo) {
    $file_id = $params['file_id'] ?? null;
    if (!$file_id) {
        throw new Exception('file_id es requerido para vs.upload');
    }

    // 1. Obtener información del archivo desde la DB
    $fileStmt = $pdo->prepare("
        SELECT * FROM knowledge_files 
        WHERE id = ? AND user_id = ?
    ");
    $fileStmt->execute([$file_id, $user_id]);
    $file = $fileStmt->fetch();

    if (!$file) {
        throw new Exception('Archivo no encontrado en la base de datos');
    }

    // 2. Verificar si ya tiene file_id (ya está en la IA)
    if (!empty($file['openai_file_id'])) {
        return [
            'file_id' => $file['openai_file_id'],
            'status' => 'already_uploaded',
            'message' => 'Archivo ya está en la IA'
        ];
    }

    // 3. Determinar tipo de archivo desde la columna de la DB
    $file_type = $file['file_type'] ?? 'unknown';
    $stored_filename = $file['stored_filename'];
    
    // 4. Construir path del archivo físico
    $file_path = __DIR__ . '/uploads/knowledge/' . $user_id . '/' . $stored_filename;
    
    error_log("=== UPLOAD DEBUG ===");
    error_log("File ID: $file_id");
    error_log("File type from DB: $file_type");
    error_log("Stored filename: $stored_filename");
    error_log("Original filename: " . $file['original_filename']);
    error_log("MIME type from DB: " . $file['mime_type']);
    error_log("File path: $file_path");
    error_log("File exists: " . (file_exists($file_path) ? 'YES' : 'NO'));
    error_log("File size: " . filesize($file_path) . " bytes");
    
    if (!file_exists($file_path)) {
        throw new Exception("Archivo físico no encontrado: $file_path");
    }

    // 5. Determinar endpoint según proveedor y tipo de archivo
    $base_url = $provider['base_url'];

    // Para OpenAI
    if (strpos($provider['name'], 'OpenAI') !== false) {
        $upload_url = $base_url . '/v1/files';

        // 6. Preparar archivo para upload con MIME type correcto
        $mime_type = $file['mime_type'];
        if ($file_type === 'pdf' && $mime_type !== 'application/pdf') {
            $mime_type = 'application/pdf';
            error_log("Corrected MIME type to: $mime_type");
        }
        
        // Usar el nombre original del archivo para evitar problemas de extensión
        $upload_filename = $file['original_filename'];
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $upload_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $api_key
            ],
            CURLOPT_POSTFIELDS => [
                'file' => new CURLFile($file_path, $mime_type, $upload_filename),
                'purpose' => 'assistants'
            ]
        ]);
        
        error_log("Upload details:");
        error_log("- Upload filename: $upload_filename");
        error_log("- MIME type: $mime_type");
        error_log("- Purpose: assistants");

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        error_log("Response HTTP Code: $http_code");
        error_log("Response Body: " . substr($response, 0, 500));
        error_log("cURL Error: " . ($error ?: 'None'));

        if ($error) {
            throw new Exception("Error cURL: $error");
        }

        if ($http_code !== 200) {
            throw new Exception("Error HTTP $http_code: $response");
        }

        $result = json_decode($response, true);
        $external_file_id = $result['id'] ?? null;

        if (!$external_file_id) {
            throw new Exception('No se obtuvo file_id del proveedor');
        }

        // 6. Actualizar knowledge_files con toda la información
        $updateStmt = $pdo->prepare("
            UPDATE knowledge_files 
            SET openai_file_id = ?, 
                upload_status = 'processed',
                vector_provider = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([$external_file_id, $provider['slug'], $file_id]);
        
        error_log("=== UPLOAD SUCCESS ===");
        error_log("File uploaded to OpenAI with ID: $external_file_id");
        error_log("Updated knowledge_files record for file_id: $file_id");

        return [
            'file_id' => $external_file_id,
            'status' => 'uploaded',
            'bytes' => $result['bytes'] ?? null
        ];
    }

    throw new Exception('Proveedor no soportado para upload');
}

/**
 * Operación vs.create_or_get: Crear/obtener vector store
 */
function run_vector_store_operation($provider, $api_key, $project_id, $params, $user_id, $pdo) {
    // 1. Buscar vector store existente para el usuario
    $vsStmt = $pdo->prepare("
        SELECT * FROM ai_vector_stores 
        WHERE owner_user_id = ? AND provider_id = ? AND status = 'ready'
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $vsStmt->execute([$user_id, $provider['id']]);
    $existing_vs = $vsStmt->fetch();

    if ($existing_vs) {
        error_log("=== VS REUSE ===");
        error_log("Reusing existing vector store: " . $existing_vs['external_id']);
        
        return [
            'vector_store_id' => $existing_vs['external_id'],
            'local_id' => $existing_vs['id'],
            'status' => 'reused',
            'doc_count' => $existing_vs['doc_count'],
            'name' => $existing_vs['name']
        ];
    }

    // 2. Crear nuevo vector store si no existe uno listo
    $base_url = $provider['base_url'];
    $vs_name = "CATAI_VS_User_{$user_id}_" . date('YmdHis');
    
    error_log("=== VS CREATE ===");
    error_log("Creating new vector store: $vs_name");

    if (strpos($provider['name'], 'OpenAI') !== false) {
        $vs_url = $base_url . '/vector_stores';
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $vs_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'name' => $vs_name,
                'file_ids' => []
            ])
        ]);

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($error) {
            throw new Exception("Error cURL: $error");
        }

        if ($http_code !== 200) {
            throw new Exception("Error HTTP $http_code: $response");
        }

        $result = json_decode($response, true);
        $external_vs_id = $result['id'] ?? null;

        if (!$external_vs_id) {
            throw new Exception('No se obtuvo vector_store_id del proveedor');
        }

        // 3. Guardar en ai_vector_stores
        $vsInsertStmt = $pdo->prepare("
            INSERT INTO ai_vector_stores (
                external_id, owner_user_id, provider_id, name, 
                status, doc_count, created_at, updated_at
            ) VALUES (?, ?, ?, ?, 'ready', 0, NOW(), NOW())
        ");
        $vsInsertStmt->execute([$external_vs_id, $user_id, $provider['id'], $vs_name]);

        error_log("=== VS CREATE SUCCESS ===");
        error_log("Vector store created with ID: $external_vs_id");
        error_log("Saved to ai_vector_stores table");

        return [
            'vector_store_id' => $external_vs_id,
            'local_id' => $pdo->lastInsertId(),
            'status' => 'created',
            'doc_count' => 0,
            'name' => $vs_name
        ];
    }

    throw new Exception('Proveedor no soportado para vector stores');
}


/**
 * Operación vs.analyze: Analizar contenido usando vector store
 */
function run_analyze_operation($provider, $api_key, $project_id, $params, $user_id, $pdo) {
    $vector_store_id = $params['vector_store_id'] ?? null;
    $prompt = $params['prompt'] ?? null;
    
    if (!$vector_store_id || !$prompt) {
        throw new Exception('vector_store_id y prompt son requeridos para vs.analyze');
    }

    $base_url = $provider['base_url'];

    if (strpos($provider['name'], 'OpenAI') !== false) {
        // 1. Crear thread con mensaje y vector store
        $thread_url = $base_url . '/threads';
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $thread_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'tool_resources' => [
                    'file_search' => [
                        'vector_store_ids' => [$vector_store_id]
                    ]
                ]
            ])
        ]);

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($error) {
            throw new Exception("Error cURL: $error");
        }

        if ($http_code !== 200) {
            throw new Exception("Error HTTP $http_code: $response");
        }

        $result = json_decode($response, true);
        $thread_id = $result['id'] ?? null;

        if (!$thread_id) {
            throw new Exception('No se obtuvo thread_id del proveedor');
        }

        // 2. Crear assistant con file search
        $assistant_url = $base_url . '/assistants';
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $assistant_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => 'gpt-4o-mini',
                'instructions' => 'Eres un analista. Usa File Search y limita tus respuestas SOLO a lo que hay en el Vector Store adjunto.',
                'tools' => [
                    ['type' => 'file_search']
                ],
                'tool_resources' => [
                    'file_search' => [
                        'vector_store_ids' => [$vector_store_id]
                    ]
                ]
            ])
        ]);

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($error) {
            throw new Exception("Error cURL: $error");
        }

        if ($http_code !== 200) {
            throw new Exception("Error HTTP $http_code: $response");
        }

        $result = json_decode($response, true);
        $assistant_id = $result['id'] ?? null;

        if (!$assistant_id) {
            throw new Exception('No se obtuvo assistant_id del proveedor');
        }

        // 3. Ejecutar run
        $run_url = $base_url . "/threads/$thread_id/runs";
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $run_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'assistant_id' => $assistant_id,
                'tool_resources' => [
                    'file_search' => [
                        'vector_store_ids' => [$vector_store_id]
                    ]
                ]
            ])
        ]);

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($error) {
            throw new Exception("Error cURL: $error");
        }

        if ($http_code !== 200) {
            throw new Exception("Error HTTP $http_code: $response");
        }

        $result = json_decode($response, true);
        $run_id = $result['id'] ?? null;

        if (!$run_id) {
            throw new Exception('No se obtuvo run_id del proveedor');
        }

        // 4. Esperar a que termine el run (simplificado - en producción usar polling)
        sleep(2);

        // 5. Obtener mensajes del thread
        $messages_url = $base_url . "/threads/$thread_id/messages";
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $messages_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $api_key
            ]
        ]);

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($error) {
            throw new Exception("Error cURL: $error");
        }

        if ($http_code !== 200) {
            throw new Exception("Error HTTP $http_code: $response");
        }

        $result = json_decode($response, true);
        $messages = $result['data'] ?? [];
        
        // Buscar el mensaje del assistant
        $assistant_message = null;
        foreach ($messages as $message) {
            if ($message['role'] === 'assistant') {
                $assistant_message = $message;
                break;
            }
        }

        if (!$assistant_message) {
            throw new Exception('No se encontró respuesta del assistant');
        }

        $content = $assistant_message['content'][0]['text']['value'] ?? '';

        return [
            'analysis' => $content,
            'thread_id' => $thread_id,
            'assistant_id' => $assistant_id,
            'run_id' => $run_id
        ];
    }

    throw new Exception('Proveedor no soportado para análisis');
}

/**
 * Registrar evento de uso
 */
function record_usage_event($user_id, $provider_id, $model_name, $request_kind, $result, $latency_ms, $pdo) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO ai_usage_events (
                user_id, provider_id, model_name, request_kind, 
                success, latency_ms, cost_usd, tokens_input, tokens_output, 
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $success = isset($result['ok']) ? $result['ok'] : true;
        $cost_usd = $result['cost_usd'] ?? 0.0;
        $tokens_input = $result['tokens_input'] ?? 0;
        $tokens_output = $result['tokens_output'] ?? 0;
        
        $stmt->execute([
            $user_id, $provider_id, $model_name, $request_kind,
            $success, $latency_ms, $cost_usd, $tokens_input, $tokens_output
        ]);
        
        error_log("=== USAGE EVENT RECORDED ===");
        error_log("User: $user_id, Provider: $provider_id, Model: $model_name");
        error_log("Request: $request_kind, Success: " . ($success ? 'YES' : 'NO'));
        error_log("Latency: {$latency_ms}ms, Cost: \${$cost_usd}");
        
    } catch (Exception $e) {
        error_log("Error recording usage event: " . $e->getMessage());
        // No fallar la operación principal por un error de logging
    }
}

// Función para escribir logs en archivo
function writeLog($message) {
    $logFile = __DIR__ . '/logs/ai_extract_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message" . PHP_EOL;
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

// Endpoint principal
writeLog("=== AI_EXTRACT_FINAL_SAFE.PHP INICIADO ===");
writeLog("REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
writeLog("REQUEST_URI: " . $_SERVER['REQUEST_URI']);
writeLog("=== LLEGANDO AL ENDPOINT PRINCIPAL ===");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        writeLog("=== PROCESANDO POST REQUEST ===");
        
        // Los archivos ya están cargados al inicio del archivo
        writeLog("=== ARCHIVOS YA CARGADOS AL INICIO ===");
        
        $user = require_user();
        writeLog("=== USUARIO AUTENTICADO: " . $user['email'] . " ===");
        
        // LÍNEA DONDE SE CARGA EL JSON
        writeLog("=== CARGANDO JSON DEL INPUT ===");
        
        // Obtener el contenido raw primero
        $rawInput = file_get_contents('php://input');
        writeLog("=== RAW INPUT: " . $rawInput . " ===");
        writeLog("=== RAW INPUT LENGTH: " . strlen($rawInput) . " ===");
        
        // Decodificar JSON
        $input = json_decode($rawInput, true);
        writeLog("=== INPUT DECODIFICADO: " . json_encode($input) . " ===");
        
        // Verificar si el JSON se cargó correctamente
        if (json_last_error() !== JSON_ERROR_NONE) {
            writeLog("=== ERROR JSON: " . json_last_error_msg() . " ===");
            writeLog("=== RAW INPUT QUE CAUSÓ ERROR: " . $rawInput . " ===");
            json_error('JSON inválido: ' . json_last_error_msg());
            exit;
        }
        
        if (!$input || !isset($input['file_id'])) {
            writeLog("=== ERROR: file_id no encontrado ===");
            json_error('file_id es requerido');
            exit;
        }
        
        $fileId = (int)$input['file_id'];
        $customPrompt = $input['prompt'] ?? null;
        $useGeneric = $input['use_generic'] ?? false;
        
        writeLog("=== INICIANDO EXTRACCIÓN PARA FILE_ID: $fileId ===");
        writeLog("=== USE_GENERIC: " . ($useGeneric ? 'true' : 'false') . " ===");
        
        // Si use_generic es true, ejecutar extracción real
        if ($useGeneric) {
            writeLog("=== EJECUTANDO EXTRACCIÓN REAL CON IA ===");
            
            try {
                // Cargar archivo desde la base de datos
                $stmt = $pdo->prepare("SELECT * FROM knowledge_files WHERE id = ? AND user_id = ?");
                $stmt->execute([$fileId, $user['id']]);
                $file = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$file) {
                    throw new Exception("Archivo no encontrado");
                }
                
                writeLog("=== ARCHIVO ENCONTRADO: " . $file['filename'] . " ===");
                
                // Ejecutar extracción real
                $extractionResult = extractFromFile($fileId, $user['id'], $pdo, $customPrompt);
                
                writeLog("=== EXTRACCIÓN COMPLETADA ===");
                writeLog("=== RESULTADO: " . json_encode($extractionResult) . " ===");
                
                json_out($extractionResult);
                return;
                
            } catch (Exception $e) {
                writeLog("=== ERROR EN EXTRACCIÓN REAL: " . $e->getMessage() . " ===");
                json_error($e->getMessage());
                return;
            }
        }
        
        // Por defecto, devolver una respuesta simple para verificar que funciona
        $result = [
            'success' => true,
            'message' => 'Endpoint funcionando correctamente',
            'file_id' => $fileId,
            'user_id' => $user['id'],
            'custom_prompt' => $customPrompt,
            'use_generic' => $useGeneric,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        writeLog("=== EXTRACCIÓN COMPLETADA ===");
        writeLog("=== RESULTADO: " . json_encode($result) . " ===");
        
        writeLog("=== LLAMANDO json_out() ===");
        json_out($result);
        writeLog("=== json_out() COMPLETADO ===");
        
    } catch (Exception $e) {
        writeLog("=== ERROR EN AI_EXTRACT_FINAL_SAFE.PHP ===");
        writeLog("Error: " . $e->getMessage());
        writeLog("Stack trace: " . $e->getTraceAsString());
        json_error($e->getMessage());
    }
} else {
    writeLog("=== ERROR: MÉTODO NO PERMITIDO ===");
    json_error('Método no permitido');
}
