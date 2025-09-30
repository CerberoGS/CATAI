<?php
// /api/ai_extract_file_vs_clean.php - Versión limpia usando solo ops_json
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Crypto_safe.php';

// Siempre devolver JSON
json_header();

// Helper de logging
function clean_log(string $msg): void {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $file = $logDir . '/ai_extract_clean.log';
    @file_put_contents($file, '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
}

// Función para ejecutar operaciones del ops_json
function executeOpsOperation(array $ops, string $operationName, array $params, string $apiKey): array {
    if (!isset($ops['multi'][$operationName])) {
        throw new Exception("Operación '$operationName' no encontrada en ops_json");
    }
    
    $op = $ops['multi'][$operationName];
    $method = $op['method'] ?? 'GET';
    $url = $op['url_override'] ?? '';
    $headers = [];
    $body = null;
    
    // Reemplazar variables en URL (usar llaves dobles {{VAR}})
    foreach ($params as $key => $value) {
        $placeholder = '{{' . $key . '}}';
        if (strpos($url, $placeholder) !== false) {
            $url = str_replace($placeholder, (string)$value, $url);
        }
    }
    
    // Procesar headers
    if (isset($op['headers']) && is_array($op['headers'])) {
        foreach ($op['headers'] as $header) {
            if (is_array($header) && isset($header['name'], $header['value'])) {
                $headerValue = $header['value'];
                // Reemplazar variables en headers
                foreach ($params as $key => $value) {
                    $placeholder = '{{' . $key . '}}';
                    if (strpos($headerValue, $placeholder) !== false) {
                        $headerValue = str_replace($placeholder, (string)$value, $headerValue);
                    }
                }
                $headers[] = $header['name'] . ': ' . $headerValue;
            }
        }
    }
    
    // Procesar body
    if (isset($op['body'])) {
        $body = $op['body'];
        // Reemplazar variables en body
        foreach ($params as $key => $value) {
            $placeholder = '{{' . $key . '}}';
            if (strpos($body, $placeholder) !== false) {
                $body = str_replace($placeholder, (string)$value, $body);
            }
        }
    }
    
    // Procesar multipart
    if (isset($op['body_type']) && $op['body_type'] === 'multipart' && isset($op['multipart'])) {
        $body = [];
        foreach ($op['multipart'] as $field) {
            $fieldName = $field['name'];
            $fieldType = $field['type'];
            $fieldValue = $field['value'];
            
            // Reemplazar variables en fieldValue
            foreach ($params as $key => $value) {
                $placeholder = '{{' . $key . '}}';
                if (strpos($fieldValue, $placeholder) !== false) {
                    $fieldValue = str_replace($placeholder, (string)$value, $fieldValue);
                }
            }
            
            if ($fieldType === 'file') {
                // Para archivos, usar CURLFile con nombre personalizado
                if (file_exists($fieldValue)) {
                    $curlFile = new CURLFile($fieldValue);
                    // Si hay un nombre personalizado, usarlo
                    if (isset($params['FILE_NAME']) && $fieldName === 'file') {
                        $curlFile->setPostFilename($params['FILE_NAME']);
                    }
                    $body[$fieldName] = $curlFile;
                } else {
                    throw new Exception("Archivo no encontrado: $fieldValue");
                }
            } else {
                // Para texto normal
                $body[$fieldName] = $fieldValue;
            }
        }
    }
    
    // Asegurar headers requeridos
    $hasAuth = false;
    $hasBeta = false;
    $hasContentType = false;
    foreach ($headers as $header) {
        if (strpos($header, 'Authorization:') === 0) $hasAuth = true;
        if (strpos($header, 'OpenAI-Beta:') === 0) $hasBeta = true;
        if (strpos($header, 'Content-Type:') === 0) $hasContentType = true;
    }
    
    if (!$hasAuth) {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }
    if (!$hasBeta) {
        $headers[] = 'OpenAI-Beta: assistants=v2';
    }
    
    // Para multipart, no agregar Content-Type (cURL lo maneja automáticamente)
    // Para JSON, asegurar Content-Type si no está presente
    if (!isset($op['body_type']) || $op['body_type'] !== 'multipart') {
        if (!$hasContentType && $body !== null && is_string($body)) {
            $headers[] = 'Content-Type: application/json';
        }
    }
    
    // Ejecutar cURL
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers
    ]);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body !== null) {
            // Si el body es un array (multipart), cURL lo manejará automáticamente
            // Si es string (JSON), se enviará como tal
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode >= 400) {
        throw new Exception("HTTP $httpCode: $response");
    }
    
    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Respuesta no es JSON válido: " . json_last_error_msg());
    }
    
    return $result;
}

try {
    clean_log('=== ai_extract_file_vs_clean.php INICIANDO ===');

    // 1) Autenticación
    $user = require_user();
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        json_out(['error' => 'invalid-user'], 401);
    }
    clean_log("Usuario autenticado: $userId");

    // 2) Entrada JSON
    $input = json_input();
    $fileId = (int)($input['file_id'] ?? 0);
    
    if ($fileId <= 0) {
        json_out(['error' => 'file-id-required'], 400);
    }
    clean_log("File ID recibido: $fileId");

    // 3) Obtener datos del archivo
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM knowledge_files WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$fileId, $userId]);
    $fileDb = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$fileDb) {
        json_out(['ok' => false, 'error' => 'file-not-found', 'message' => 'Archivo no encontrado'], 404);
    }
    clean_log("Archivo encontrado: " . $fileDb['original_filename']);

    // 4) Verificar que el archivo esté en el servidor
    $uploadsDir = __DIR__ . '/uploads/knowledge/' . $userId . '/';
    $stored = $fileDb['stored_filename'] ?? null;
    $fullPath = $stored ? ($uploadsDir . $stored) : null;
    $exists = $fullPath ? file_exists($fullPath) : false;

    if (!$exists) {
        json_out(['ok' => false, 'error' => 'file-not-on-server', 'message' => 'Archivo no está en el servidor'], 404);
    }
    clean_log("Archivo existe en servidor: $fullPath");

    // 5) Verificar file_id
    $openaiFileId = $fileDb['openai_file_id'] ?? '';
    clean_log("OpenAI File ID: " . ($openaiFileId ?: 'NO (se creará)'));

    // 6) Verificar vs_id
    $vectorStoreId = $fileDb['vector_store_id'] ?? '';
    clean_log("Vector Store ID: " . ($vectorStoreId ?: 'NO (se creará)'));

    // 7) Obtener configuración de IA del usuario
    $settingsStmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ? LIMIT 1");
    $settingsStmt->execute([$userId]);
    $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $aiProviderId = (int)($settings['default_provider_id'] ?? 0);
    if ($aiProviderId <= 0) {
        json_out(['ok' => false, 'error' => 'no-ai-provider', 'message' => 'No hay proveedor de IA configurado'], 400);
    }
    clean_log("AI Provider ID: $aiProviderId");

    // 8) Obtener ops_json del proveedor
    $provStmt = $pdo->prepare("SELECT id, slug, name, ops_json FROM ai_providers WHERE id = ? LIMIT 1");
    $provStmt->execute([$aiProviderId]);
    $providerRow = $provStmt->fetch(PDO::FETCH_ASSOC);

    if (!$providerRow || empty($providerRow['ops_json'])) {
        json_out(['ok' => false, 'error' => 'no-ops-json', 'message' => 'Proveedor sin ops_json'], 400);
    }

    $ops = json_decode($providerRow['ops_json'], true);
    if (!is_array($ops)) {
        json_out(['ok' => false, 'error' => 'invalid-ops-json', 'message' => 'ops_json inválido'], 400);
    }
    clean_log("Ops JSON cargado para provider: " . $providerRow['name']);

    // 9) Obtener API Key
    $keyStmt = $pdo->prepare("SELECT api_key_enc FROM user_ai_api_keys WHERE user_id = ? AND provider_id = ? AND (status IS NULL OR status = 'active') ORDER BY id DESC LIMIT 1");
    $keyStmt->execute([$userId, $aiProviderId]);
    $keyRow = $keyStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$keyRow || empty($keyRow['api_key_enc'])) {
        json_out(['ok' => false, 'error' => 'no-api-key', 'message' => 'No hay API key configurada'], 400);
    }

    $apiKeyPlain = catai_decrypt($keyRow['api_key_enc']);
    clean_log("API Key obtenida y desencriptada");

    // 10) Obtener IDs del archivo
    $assistantId = $fileDb['assistant_id'] ?? '';
    $threadId = $fileDb['thread_id'] ?? '';
    
    clean_log("Estado actual - File ID: $openaiFileId, VS ID: $vectorStoreId, Assistant ID: " . ($assistantId ?: 'NO') . ", Thread ID: " . ($threadId ?: 'NO'));

    // 10.1) Verificar si los IDs de la DB realmente existen en OpenAI
    if (!empty($vectorStoreId)) {
        try {
            clean_log("Verificando si Vector Store existe en OpenAI...");
            $vsCheckResult = executeOpsOperation($ops, 'vs.store.get', [
                'VS_ID' => $vectorStoreId,
                'API_KEY' => $apiKeyPlain
            ], $apiKeyPlain);
            clean_log("Vector Store existe en OpenAI: " . ($vsCheckResult['id'] ?? 'NO'));
        } catch (Exception $e) {
            clean_log("Vector Store NO existe en OpenAI: " . $e->getMessage());
            $vectorStoreId = ''; // Reset para crear uno nuevo
            // Limpiar también assistant_id y thread_id ya que dependen del VS
            $assistantId = '';
            $threadId = '';
            
            // Actualizar la DB para limpiar los IDs que no existen
            $cleanStmt = $pdo->prepare("UPDATE knowledge_files SET vector_store_id = NULL, assistant_id = NULL, thread_id = NULL WHERE id = ?");
            $cleanStmt->execute([$fileId]);
            clean_log("IDs limpiados en la DB ya que no existen en OpenAI");
        }
    }

    // 10.2) Verificar si el archivo existe en OpenAI
    if (!empty($openaiFileId)) {
        try {
            clean_log("Verificando si archivo existe en OpenAI...");
            $fileCheckResult = executeOpsOperation($ops, 'vs.get', [
                'FILE_ID' => $openaiFileId,
                'API_KEY' => $apiKeyPlain
            ], $apiKeyPlain);
            clean_log("Archivo existe en OpenAI: " . ($fileCheckResult['id'] ?? 'NO'));
        } catch (Exception $e) {
            clean_log("Archivo NO existe en OpenAI: " . $e->getMessage());
            $openaiFileId = ''; // Reset para subir uno nuevo
            
            // Actualizar la DB para limpiar el file_id que no existe
            $cleanFileStmt = $pdo->prepare("UPDATE knowledge_files SET openai_file_id = NULL WHERE id = ?");
            $cleanFileStmt->execute([$fileId]);
            clean_log("openai_file_id limpiado en la DB ya que no existe en OpenAI");
        }
    }

    // 11) Obtener prompt del usuario o usar default de config
    $prompt = (string)($input['prompt'] ?? '');
    if (trim($prompt) === '') {
        if (!empty($settings['ai_prompt_ext_conten_file'])) {
            $prompt = (string)$settings['ai_prompt_ext_conten_file'];
            clean_log("Usando prompt personalizado del usuario");
        } else {
            global $CONFIG;
            $prompt = (string)($CONFIG['AI_PROMPT_EXTRACT_DEFAULT'] ?? 'Resume en 5 bullets la información más relevante.');
            clean_log("Usando prompt por defecto de config.php");
        }
    } else {
        clean_log("Usando prompt del request body");
    }
    clean_log("Prompt a usar: " . substr($prompt, 0, 100) . "...");


    // 13) Crear/verificar todos los IDs necesarios
    try {
        // 13.1) Verificar/crear OpenAI File ID si no existe
        if (empty($openaiFileId)) {
            clean_log("File ID faltante, subiendo archivo a OpenAI...");
            clean_log("Ruta completa del archivo: $fullPath");
            clean_log("Archivo existe físicamente: " . (file_exists($fullPath) ? 'SÍ' : 'NO'));
            
            // Usar solo el nombre del archivo, no el path completo
            $fileName = $fileDb['stored_filename'] ?? basename($fullPath);
            $uploadResult = executeOpsOperation($ops, 'vs.upload', [
                'FILE_PATH' => $fullPath,
                'FILE_NAME' => $fileName,
                'API_KEY' => $apiKeyPlain
            ], $apiKeyPlain);
            
            clean_log("Resultado del upload: " . json_encode($uploadResult));
            $openaiFileId = $uploadResult['id'] ?? '';
            if (!$openaiFileId) {
                throw new Exception('No se pudo obtener file_id del upload');
            }
            
            // Actualizar knowledge_files con el nuevo file_id
            $updateStmt = $pdo->prepare("UPDATE knowledge_files SET openai_file_id = ? WHERE id = ?");
            $updateStmt->execute([$openaiFileId, $fileId]);
            clean_log("File ID creado y guardado: $openaiFileId");
        } else {
            clean_log("File ID existente: $openaiFileId");
        }
        
        // 13.2) Verificar/crear Vector Store ID si no existe
        if (empty($vectorStoreId)) {
            clean_log("Vector Store ID faltante, creando VS...");
            $vsName = "CATAI_VS_User_{$userId}_" . date('YmdHis');
            $vsResult = executeOpsOperation($ops, 'vs.store.create', [
                'VS_NAME' => $vsName,
                'API_KEY' => $apiKeyPlain
            ], $apiKeyPlain);
            $vectorStoreId = $vsResult['id'] ?? '';
            if (!$vectorStoreId) {
                throw new Exception('No se pudo obtener vector_store_id');
            }
            
            // Guardar VS en ai_vector_stores
            $vsStmt = $pdo->prepare("INSERT INTO ai_vector_stores (owner_user_id, provider_id, external_id, name, status, created_at) VALUES (?, ?, ?, ?, 'ready', NOW())");
            $vsStmt->execute([$userId, $aiProviderId, $vectorStoreId, $vsName]);
            
            // Actualizar knowledge_files con el nuevo vector_store_id
            $updateStmt = $pdo->prepare("UPDATE knowledge_files SET vector_store_id = ? WHERE id = ?");
            $updateStmt->execute([$vectorStoreId, $fileId]);
            clean_log("Vector Store creado y guardado: $vectorStoreId");
        } else {
            clean_log("Vector Store ID existente: $vectorStoreId");
            // Verificar que el VS existe en OpenAI (ya se hizo arriba, pero por seguridad)
            try {
                $vsCheckResult = executeOpsOperation($ops, 'vs.store.get', [
                    'VS_ID' => $vectorStoreId,
                    'API_KEY' => $apiKeyPlain
                ], $apiKeyPlain);
                clean_log("Vector Store verificado en OpenAI: " . ($vsCheckResult['id'] ?? 'NO'));
            } catch (Exception $e) {
                clean_log("Vector Store NO existe en OpenAI, recreando: " . $e->getMessage());
                // Recrear VS si no existe
                $vsName = "CATAI_VS_User_{$userId}_" . date('YmdHis');
                $vsResult = executeOpsOperation($ops, 'vs.store.create', [
                    'VS_NAME' => $vsName,
                    'API_KEY' => $apiKeyPlain
                ], $apiKeyPlain);
                $vectorStoreId = $vsResult['id'] ?? '';
                
                // Actualizar DB
                $updateStmt = $pdo->prepare("UPDATE knowledge_files SET vector_store_id = ? WHERE id = ?");
                $updateStmt->execute([$vectorStoreId, $fileId]);
                clean_log("Vector Store recreado: $vectorStoreId");
            }
        }
        
        // 13.3) Adjuntar archivo al Vector Store si no está adjunto
        clean_log("Verificando si archivo está adjunto al VS...");
        $attachResult = executeOpsOperation($ops, 'vs.attach', [
            'VS_ID' => $vectorStoreId,
            'FILE_ID' => $openaiFileId,
            'API_KEY' => $apiKeyPlain
        ], $apiKeyPlain);
        clean_log("Archivo adjuntado al Vector Store exitosamente");
        
        // 13.3.1) Verificar que el archivo esté indexado en el VS
        clean_log("Verificando que el archivo esté indexado en el Vector Store...");
        $maxRetries = 10;
        $retryCount = 0;
        $fileIndexed = false;
        
        while ($retryCount < $maxRetries && !$fileIndexed) {
            $fileStatusResult = executeOpsOperation($ops, 'vs.store.file.get', [
                'VS_ID' => $vectorStoreId,
                'FILE_ID' => $openaiFileId,
                'API_KEY' => $apiKeyPlain
            ], $apiKeyPlain);
            
            $fileStatus = $fileStatusResult['status'] ?? '';
            clean_log("Estado del archivo en VS (intento " . ($retryCount + 1) . "): $fileStatus");
            
            if ($fileStatus === 'completed') {
                $fileIndexed = true;
                clean_log("Archivo indexado correctamente en el Vector Store");
            } else {
                $retryCount++;
                if ($retryCount < $maxRetries) {
                    sleep(2); // Esperar 2 segundos antes del siguiente intento
                }
            }
        }
        
        if (!$fileIndexed) {
            throw new Exception("El archivo no se indexó correctamente en el Vector Store después de $maxRetries intentos");
        }
        
        // 13.4) Verificar/crear Assistant ID si no existe
        if (empty($assistantId)) {
            clean_log("Assistant ID faltante, creando Assistant...");
            $assistantName = "CATAI_Extractor_User_{$userId}_" . date('YmdHis');
            // Usar el prompt del usuario o el por defecto
            $assistantInstructions = $prompt ?: $CONFIG['AI_PROMPT_EXTRACT_DEFAULT'];
            
            $assistantResult = executeOpsOperation($ops, 'assistant.create', [
                'VS_ID' => $vectorStoreId,
                'USER_ID' => (string)$userId,
                'ASSISTANT_NAME' => $assistantName,
                'ASSISTANT_INSTRUCTIONS' => $assistantInstructions,
                'API_KEY' => $apiKeyPlain
            ], $apiKeyPlain);
            $assistantId = $assistantResult['id'] ?? '';
            if (!$assistantId) {
                throw new Exception('No se pudo obtener assistant_id');
            }
            
            // Actualizar knowledge_files y ai_vector_stores con assistant_id
            $updateStmt = $pdo->prepare("UPDATE knowledge_files SET assistant_id = ? WHERE id = ?");
            $updateStmt->execute([$assistantId, $fileId]);
            
            $updateVsStmt = $pdo->prepare("UPDATE ai_vector_stores SET assistant_id = ? WHERE external_id = ?");
            $updateVsStmt->execute([$assistantId, $vectorStoreId]);
            
            clean_log("Assistant creado y guardado: $assistantId");
            clean_log("Assistant ID creado empieza con 'asst_': " . (strpos($assistantId, 'asst_') === 0 ? 'SÍ' : 'NO'));
        } else {
            clean_log("Assistant ID existente: $assistantId");
            clean_log("Assistant ID existente empieza con 'asst_': " . (strpos($assistantId, 'asst_') === 0 ? 'SÍ' : 'NO'));
            
            // Actualizar Assistant con Vector Store
            $assistantUpdateResult = executeOpsOperation($ops, 'assistant.update_vs', [
                'ASSISTANT_ID' => $assistantId,
                'VS_ID' => $vectorStoreId,
                'API_KEY' => $apiKeyPlain
            ], $apiKeyPlain);
            clean_log("Assistant actualizado con Vector Store");
        }
        
        // 13.5) Verificar/crear Thread ID si no existe
        if (empty($threadId)) {
            clean_log("Thread ID faltante, creando Thread...");
            $threadResult = executeOpsOperation($ops, 'thread.create', [
                'USER_PROMPT' => $prompt,
                'VS_ID' => $vectorStoreId,
                'API_KEY' => $apiKeyPlain
            ], $apiKeyPlain);
            $threadId = $threadResult['id'] ?? '';
            if (!$threadId) {
                throw new Exception('No se pudo obtener thread_id');
            }
            
            // Actualizar knowledge_files con thread_id
            $updateStmt = $pdo->prepare("UPDATE knowledge_files SET thread_id = ? WHERE id = ?");
            $updateStmt->execute([$threadId, $fileId]);
            
            clean_log("Thread creado y guardado: $threadId");
        } else {
            clean_log("Thread ID existente, reutilizando: $threadId");
        }
        
        // 13.6) Ejecutar flujo de extracción
        $t0 = microtime(true);
        $runId = '';
        $summaryText = '';
        $usage = [];
        
        clean_log("Creando run con assistant_id: $assistantId, thread_id: $threadId");
        clean_log("Verificando tipos - assistant_id empieza con 'asst_': " . (strpos($assistantId, 'asst_') === 0 ? 'SÍ' : 'NO'));
        clean_log("Verificando tipos - thread_id empieza con 'thread_': " . (strpos($threadId, 'thread_') === 0 ? 'SÍ' : 'NO'));
        
        // Validar que assistant_id sea correcto
        if (strpos($assistantId, 'asst_') !== 0) {
            throw new Exception("Assistant ID inválido: $assistantId (debe empezar con 'asst_')");
        }
        
        // Validar que thread_id sea correcto
        if (strpos($threadId, 'thread_') !== 0) {
            throw new Exception("Thread ID inválido: $threadId (debe empezar con 'thread_')");
        }
        
        $run = executeOpsOperation($ops, 'run.create', [
            'THREAD_ID' => $threadId,
            'ASSISTANT_ID' => $assistantId,
            'USER_PROMPT' => $prompt,
            'VS_ID' => $vectorStoreId,
            'API_KEY' => $apiKeyPlain
        ], $apiKeyPlain);
        
        $runId = $run['id'] ?? '';
        if (!$runId) {
            throw new Exception('No se obtuvo run_id del run.create');
        }
        clean_log("Run creado: $runId");
        
        // 13.2) Polling run.get hasta completed con espera inteligente
        $attempts = 0;
        $maxAttempts = 120; // 120 intentos máximo (2 minutos)
        $status = '';
        $waitTime = 1; // Empezar con 1 segundo
        
        do {
            $attempts++;
            clean_log("Esperando $waitTime segundos antes del intento $attempts...");
            sleep($waitTime);
            
            $runStatus = executeOpsOperation($ops, 'run.get', [
                'THREAD_ID' => $threadId,
                'RUN_ID' => $runId,
                'API_KEY' => $apiKeyPlain
            ], $apiKeyPlain);
            
            $status = $runStatus['status'] ?? '';
            if (isset($runStatus['usage'])) {
                $usage = $runStatus['usage'];
            }
            
            clean_log("Run status (intento $attempts/$maxAttempts): $status");
            
            if (in_array($status, ['failed', 'cancelled', 'expired'], true)) {
                throw new Exception("Run falló con status: $status");
            }
            
            // Si está en progreso, aumentar gradualmente el tiempo de espera
            if ($status === 'in_progress' || $status === 'queued') {
                if ($attempts < 10) {
                    $waitTime = 2; // Primeros 10 intentos: 2 segundos
                } elseif ($attempts < 30) {
                    $waitTime = 3; // Siguientes 20 intentos: 3 segundos  
                } else {
                    $waitTime = 5; // Después: 5 segundos
                }
            }
            
        } while ($status !== 'completed' && $attempts < $maxAttempts);
        
        if ($status !== 'completed') {
            throw new Exception("Run timeout después de $maxAttempts intentos (status final: $status)");
        }
        
        clean_log("Run completado exitosamente");
        
        // 13.3) Obtener mensajes y extraer resumen del asistente más reciente
        $messages = executeOpsOperation($ops, 'messages.list', [
            'THREAD_ID' => $threadId,
            'limit' => 50,
            'API_KEY' => $apiKeyPlain
        ], $apiKeyPlain);
        
        $dataMsgs = $messages['data'] ?? [];
        clean_log("Total mensajes obtenidos: " . count($dataMsgs));
        
        // Log de todos los mensajes para debugging
        foreach ($dataMsgs as $i => $msg) {
            clean_log("Mensaje $i - Role: " . ($msg['role'] ?? 'NO') . ", Created: " . ($msg['created_at'] ?? 'NO'));
        }
        
        // Ordenar por created_at desc y tomar el primer mensaje del asistente
        usort($dataMsgs, function($a, $b) {
            $timeA = $a['created_at'] ?? 0;
            $timeB = $b['created_at'] ?? 0;
            return $timeB <=> $timeA; // Descendente
        });
        
        // Buscar el primer mensaje del asistente
        foreach ($dataMsgs as $msg) {
            if (($msg['role'] ?? '') === 'assistant') {
                $content = $msg['content'] ?? [];
                clean_log("Mensaje del asistente encontrado, contenido: " . json_encode($content));
                
                foreach ($content as $contentItem) {
                    if ($contentItem['type'] === 'text') {
                        $summaryText = $contentItem['text']['value'] ?? '';
                        clean_log("Texto encontrado: " . substr($summaryText, 0, 200) . "...");
                        if (!empty($summaryText)) {
                            break 2; // Salir de ambos loops
                        }
                    } elseif ($contentItem['type'] === 'tool_use') {
                        // Manejar respuesta de tool (File Search)
                        $toolOutput = $contentItem['tool_use'] ?? [];
                        clean_log("Tool use encontrado: " . json_encode($toolOutput));
                    }
                }
            }
        }
        
        if (empty($summaryText)) {
            // Log detallado de todos los mensajes para debugging
            clean_log("ERROR: No se encontró respuesta del asistente. Mensajes disponibles:");
            foreach ($dataMsgs as $i => $msg) {
                clean_log("Mensaje $i: " . json_encode($msg));
            }
            throw new Exception('No se obtuvo respuesta del asistente - revisar logs para detalles');
        }
        
        clean_log("Resumen obtenido: " . substr($summaryText, 0, 100) . "...");
        
    } catch (Exception $e) {
        clean_log("Error en extracción: " . $e->getMessage());
        json_out([
            'ok' => false,
            'error' => 'extraction-failed',
            'message' => $e->getMessage(),
            'data' => [
                'user_id' => $userId,
                'file_id' => $fileId,
                'assistant_id' => $assistantId,
                'thread_id' => $threadId,
                'run_id' => $runId,
                'status' => $status ?? 'unknown'
            ]
        ], 500);
        return;
    }

    // 14) Calcular métricas
    $t1 = microtime(true);
    $latencyMs = (int)round(($t1 - $t0) * 1000);
    $tokensInput = (int)($usage['prompt_tokens'] ?? ($usage['input_tokens'] ?? 0));
    $tokensOutput = (int)($usage['completion_tokens'] ?? ($usage['output_tokens'] ?? 0));
    $tokensTotal = (int)($usage['total_tokens'] ?? ($tokensInput + $tokensOutput));

    // 15) Persistir en base de datos
    try {
        // Insertar en knowledge_base
        $title = $fileDb['original_filename'] . ' - Análisis IA';
        $kbStmt = $pdo->prepare("
            INSERT INTO knowledge_base (
                knowledge_type, title, content, summary, tags, confidence_score, 
                created_by, source_type, source_file
            ) VALUES (
                'user_insight', ?, ?, ?, ?, 0.70, 
                ?, 'ai_extraction', ?
            )
        ");
        $tagsJson = json_encode(['extraído', 'archivo', 'ia']);
        $shortSummary = mb_substr($summaryText, 0, 300);
        $kbStmt->execute([
            $title, $summaryText, $shortSummary, $tagsJson, $userId, 
            $fileDb['original_filename']
        ]);
        $knowledgeId = (int)$pdo->lastInsertId();
        
        // Actualizar knowledge_files
        $updateStmt = $pdo->prepare("
            UPDATE knowledge_files 
            SET extraction_status = 'completed',
                last_extraction_started_at = NOW(),
                last_extraction_finished_at = NOW(),
                last_extraction_model = ?,
                last_extraction_response_id = ?,
                last_extraction_input_tokens = ?,
                last_extraction_output_tokens = ?,
                last_extraction_total_tokens = ?,
                last_extraction_cost_usd = 0.0
            WHERE id = ? AND user_id = ?
        ");
        $aiModelId = (int)($settings['default_model_id'] ?? 0);
        $updateStmt->execute([
            $aiModelId, $runId, $tokensInput, $tokensOutput, $tokensTotal, 
            $fileId, $userId
        ]);
        
        clean_log("Datos persistidos en BD: knowledge_id=$knowledgeId");
        
    } catch (Exception $e) {
        clean_log("Error persistiendo en BD: " . $e->getMessage());
        // No fallar por error de BD, continuar
    }

    // 16) Respuesta final exitosa
    json_out([
        'ok' => true,
        'message' => 'Extracción completada usando ops_json - Todos los IDs alineados',
        'data' => [
            'user_id' => $userId,
            'file_id' => $fileId,
            'file_name' => $fileDb['original_filename'],
            'ai_provider' => $providerRow['name'],
            'ids' => [
                'openai_file_id' => $openaiFileId,
                'vector_store_id' => $vectorStoreId,
                'assistant_id' => $assistantId,
                'thread_id' => $threadId,
                'run_id' => $runId
            ],
            'summary' => $summaryText,
            'knowledge_id' => $knowledgeId ?? null,
            'metrics' => [
                'latency_ms' => $latencyMs,
                'tokens_input' => $tokensInput,
                'tokens_output' => $tokensOutput,
                'tokens_total' => $tokensTotal,
                'cost_usd' => 0.0
            ]
        ]
    ]);

} catch (Throwable $e) {
    clean_log('ERROR: ' . $e->getMessage());
    json_out(['error' => 'internal-error', 'detail' => $e->getMessage()], 500);
}
