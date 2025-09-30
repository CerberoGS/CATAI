<?php
declare(strict_types=1);

// Función para logging limpio en archivo específico
function clean_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message" . PHP_EOL;
    
    // Crear directorio logs si no existe
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    // Escribir en archivo específico
    $logFile = $logDir . '/ai_extract_debug_' . date('Y-m-d') . '.log';
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

// Función para registrar eventos de uso (copiada de run_op_safe.php)
function record_usage_event($user_id, $provider_id, $model_name, $request_kind, $result, $latency_ms, $pdo) {
    try {
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
             input_tokens, output_tokens, billed_input_usd, billed_output_usd, 
             http_status, error_code, error_message, meta)
            VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $insertStmt->execute([
            $user_id,
            $provider_id,
            $request_kind,
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
    } catch (Exception $e) {
        clean_log("Error en record_usage_event: " . $e->getMessage());
        // No lanzar excepción para no interrumpir el flujo principal
    }
}

// Función para ejecutar operaciones del ops_json
function executeOpsOperation($ops, $operation, $params, $apiKey) {
    if (!isset($ops['multi'][$operation])) {
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
            foreach ($params as $key => $value) {
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
                    if (file_exists($fieldValue)) {
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
    
    // Ejecutar cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    // No agregar Content-Type header para multipart (cURL lo maneja automáticamente)
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
    
    if ($error) {
        throw new Exception("cURL error: $error");
    }
    
    if ($httpCode !== ($op['expected_status'] ?? 200)) {
        throw new Exception("HTTP $httpCode: $response");
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Respuesta no es JSON válido: " . json_last_error_msg());
    }
    
    return $data;
}

// ===== DIAGNÓSTICO PASO A PASO =====
clean_log("=== INICIANDO DIAGNÓSTICO PASO A PASO ===");

try {
    // PASO 1: Verificar que las funciones básicas funcionan
    clean_log("PASO 1: Funciones básicas OK");
    
    // PASO 2: Incluir archivos
    clean_log("PASO 2: Incluyendo archivos...");
    require_once 'config.php';
    clean_log("PASO 2a: config.php incluido OK");
    
    require_once 'db.php';
    clean_log("PASO 2b: db.php incluido OK");
    
    require_once 'helpers.php';
    clean_log("PASO 2c: helpers.php incluido OK");
    
    require_once 'Crypto_safe.php';
    clean_log("PASO 2d: Crypto_safe.php incluido OK");
    
    // PASO 3: Conectar a base de datos
    clean_log("PASO 3: Conectando a base de datos...");
    $pdo = db();
    clean_log("PASO 3: Conexión a BD OK");
    
    // PASO 4: Autenticación
    clean_log("PASO 4: Obteniendo usuario...");
    $user = require_user();
    $userId = $user['id'];
    clean_log("PASO 4: Usuario autenticado: ID=$userId, email=" . $user['email']);
    
    // PASO 5: Obtener input
    clean_log("PASO 5: Obteniendo input...");
    $input = json_input();
    $fileId = (int)($input['file_id'] ?? 0);
    clean_log("PASO 5: Input recibido: " . json_encode($input));
    clean_log("PASO 5: File ID: $fileId");
    
    if (!$fileId) {
        clean_log("ERROR: file_id requerido");
        json_error('file_id requerido');
    }
    
    // PASO 6: Consultar archivo en BD
    clean_log("PASO 6: Consultando archivo en knowledge_files...");
    $stmt = $pdo->prepare("SELECT * FROM knowledge_files WHERE id = ? AND user_id = ?");
    $stmt->execute([$fileId, $userId]);
    $fileDb = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$fileDb) {
        clean_log("ERROR: Archivo no encontrado para file_id=$fileId, user_id=$userId");
        json_error('Archivo no encontrado');
    }
    
    clean_log("PASO 6: Archivo encontrado: " . $fileDb['original_filename']);
    
    // PASO 7: Obtener configuración de usuario
    clean_log("PASO 7: Obteniendo configuración de usuario...");
    $stmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ?");
    $stmt->execute([$userId]);
    $userSettings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    clean_log("PASO 7: Configuración de usuario obtenida");
    
    // PASO 8: Obtener proveedor OpenAI
    clean_log("PASO 8: Obteniendo proveedor OpenAI...");
    $stmt = $pdo->prepare("SELECT * FROM ai_providers WHERE slug = 'openai'");
    $stmt->execute();
    $provider = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$provider) {
        clean_log("ERROR: Proveedor OpenAI no encontrado");
        json_error('Proveedor OpenAI no encontrado');
    }
    
    $providerId = $provider['id'];
    clean_log("PASO 8: Proveedor OpenAI encontrado: ID=$providerId");
    
    // PASO 9: Obtener API key
    clean_log("PASO 9: Obteniendo API key...");
    $stmt = $pdo->prepare("SELECT * FROM user_ai_api_keys WHERE user_id = ? AND provider_id = ? AND status = 'active'");
    $stmt->execute([$userId, $providerId]);
    $apiKeyRow = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$apiKeyRow) {
        clean_log("ERROR: API key no encontrada para usuario $userId, proveedor $providerId");
        json_error('API key no encontrada');
    }
    
    // Descifrar API key
    $apiKeyPlain = decrypt_api_key($apiKeyRow['api_key_enc']);
    if (!$apiKeyPlain) {
        clean_log("ERROR: No se pudo descifrar API key");
        json_error('Error al descifrar API key');
    }
    
    clean_log("PASO 9: API key obtenida y descifrada");
    
    // PASO 10: Obtener ops_json
    clean_log("PASO 10: Obteniendo ops_json...");
    $ops = json_decode($provider['ops_json'], true);
    if (!$ops) {
        clean_log("ERROR: ops_json no válido");
        json_error('Configuración de proveedor no válida');
    }
    
    clean_log("PASO 10: ops_json obtenido OK");
    
    // PASO 11: Obtener IDs existentes
    clean_log("PASO 11: Obteniendo IDs existentes...");
    $openaiFileId = $fileDb['openai_file_id'] ?? '';
    $vectorStoreId = $fileDb['vector_store_id'] ?? '';
    $assistantId = $fileDb['assistant_id'] ?? '';
    $threadId = $fileDb['thread_id'] ?? '';
    
    clean_log("PASO 11: IDs existentes - FILE_ID: $openaiFileId, VS_ID: $vectorStoreId, ASSISTANT_ID: $assistantId, THREAD_ID: $threadId");
    
    clean_log("=== DIAGNÓSTICO COMPLETADO EXITOSAMENTE ===");
    
    json_out([
        'ok' => true,
        'message' => 'Diagnóstico completado exitosamente',
        'user_id' => $userId,
        'file_id' => $fileId,
        'file_name' => $fileDb['original_filename'],
        'provider_id' => $providerId,
        'existing_ids' => [
            'openai_file_id' => $openaiFileId,
            'vector_store_id' => $vectorStoreId,
            'assistant_id' => $assistantId,
            'thread_id' => $threadId
        ]
    ]);
    
} catch (Exception $e) {
    clean_log("ERROR en diagnóstico: " . $e->getMessage());
    clean_log("Stack trace: " . $e->getTraceAsString());
    json_error("Error en diagnóstico: " . $e->getMessage());
} catch (Error $e) {
    clean_log("FATAL ERROR en diagnóstico: " . $e->getMessage());
    clean_log("Stack trace: " . $e->getTraceAsString());
    json_error("Error fatal en diagnóstico: " . $e->getMessage());
}
?>
