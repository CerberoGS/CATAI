<?php
// /api/ai_extract_file_vs.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Crypto_safe.php';

// Siempre devolver JSON
json_header();

// Helper mínimo de logging a archivo propio de esta ruta
function aievs_log(string $msg): void {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $file = $logDir . '/ai_extract_file_vs.log';
    @file_put_contents($file, '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
}

// Función para ejecutar operaciones simples de IA
function executeSimpleOperationAI(array $providerConfig, string $apiKey, array $operationConfig, array $params, array &$debugOps): array {
    $method = $operationConfig['method'] ?? 'GET';
    $url = replaceVariables($operationConfig['url_override'], $params);
    $headers = [];

    // Procesar headers del ops_json
    $hasAuthorization = false;
    $hasOpenAIBeta = false;
    $hasContentType = false;
    
    if (isset($operationConfig['headers'])) {
        foreach ($operationConfig['headers'] as $header) {
            $headerName = $header['name'];
            $originalValue = $header['value'];
            $headerValue = replaceVariables($originalValue, $params);
            
            // Debug logging para headers
            if (strpos($originalValue, '{{API_KEY}}') !== false) {
                aievs_log("Header $headerName: '$originalValue' -> '$headerValue' (API_KEY length: " . strlen($params['API_KEY'] ?? '') . ")");
                aievs_log("Params disponibles: " . json_encode(array_keys($params)));
            }
            
            if (strcasecmp($headerName, 'Authorization') === 0) {
                $hasAuthorization = true;
            } elseif (strcasecmp($headerName, 'OpenAI-Beta') === 0) {
                $hasOpenAIBeta = true;
            } elseif (strcasecmp($headerName, 'Content-Type') === 0) {
                $hasContentType = true;
            }
            
            $headers[] = "$headerName: $headerValue";
        }
    }
    
    // Añadir headers faltantes solo si no están en ops_json
    if (!$hasAuthorization) {
        $headers[] = "Authorization: Bearer $apiKey";
    }
    if (!$hasOpenAIBeta) {
        $headers[] = "OpenAI-Beta: assistants=v2";
    }

    $body = null;
    if (isset($operationConfig['body'])) {
        $body = replaceVariables($operationConfig['body'], $params);
        $trim = ltrim($body);
        $isJsonBody = ($trim !== '' && ($trim[0] === '{' || $trim[0] === '['));
        if ($isJsonBody && !$hasContentType) {
            $headers[] = "Content-Type: application/json";
        }
    }

    // Debug de la operación antes de ejecutar
    $debugOps[] = [
        'op' => $operationConfig['name'] ?? 'unknown',
        'method' => $method,
        'url' => $url,
        'headers' => $headers,
        'body' => $body
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_FAILONERROR    => false, // No fallar en HTTP errors para leer el body
    ]);

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    } elseif (strtoupper($method) === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }

    $response   = curl_exec($ch);
    $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType= curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error      = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new Exception("Error cURL: $error");
    }
    if ($httpCode !== 200) {
        // Intentar decodificar el error de OpenAI si es JSON
        $errorDetail = $response;
        $decodedError = @json_decode($response, true);
        if ($decodedError && isset($decodedError['error']['message'])) {
            $errorDetail = $decodedError['error']['message'];
        }
        throw new Exception("http-$httpCode: $errorDetail");
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

    $decoded = @json_decode($response, true);
    if ($decoded !== null && $decoded !== false) {
        return $decoded;
    }

    return [
        'ok'          => true,
        'note'        => 'non-json',
        'http_code'   => $httpCode,
        'content_type'=> $contentType,
        'raw'         => (string)$response
    ];
}

// Función para reemplazar variables en templates
function replaceVariables($template, $context) {
    if (is_string($template)) {
        $original = $template;
        foreach ($context as $key => $value) {
            if (strpos($template, "{{$key}}") !== false) {
                aievs_log("Reemplazando {{$key}} con valor de longitud: " . strlen($value));
                $template = str_replace("{{$key}}", $value, $template);
            }
        }
        if ($original !== $template) {
            aievs_log("Template cambiado: '$original' -> '$template'");
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

try {
    aievs_log('=== ai_extract_file_vs.php INIT ===');

    // 1) Autenticación
    $user = require_user();
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        json_out(['error' => 'invalid-user'], 401);
    }
    aievs_log("user=$userId");

    // 2) Entrada JSON estándar de la app
    aievs_log("Leyendo entrada JSON...");
    $input = json_input();
    aievs_log("Input recibido: " . json_encode($input));
    
    $fileId = (int)($input['file_id'] ?? 0);
    $vsId = (string)($input['vector_store_id'] ?? '');
    $prompt = (string)($input['prompt'] ?? '');
    $mode   = (string)($input['mode'] ?? '');
    
    aievs_log("Parámetros extraídos: fileId=$fileId, vsId=$vsId, mode=$mode");

    if ($fileId <= 0) {
        json_out(['error' => 'file-id-required'], 400);
    }

    // 3) Paso 1: obtener registro del archivo del usuario
    aievs_log("Conectando a base de datos...");
    $pdo = db();
    aievs_log("Conexión a BD establecida");
    
    // Función helper para reconectar si es necesario
    $ensureConnection = function() use (&$pdo) {
        try {
            $pdo->query("SELECT 1");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'MySQL server has gone away') !== false) {
                aievs_log("Reconectando PDO por timeout...");
                $pdo = db();
            } else {
                throw $e;
            }
        }
    };
    
    $ensureConnection();
    aievs_log("Buscando archivo en BD: fileId=$fileId, userId=$userId");
    $stmt = $pdo->prepare("SELECT * FROM knowledge_files WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$fileId, $userId]);
    $fileDb = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    aievs_log("Archivo encontrado: " . ($fileDb ? "SÍ" : "NO"));

    if (!$fileDb) {
        json_out(['ok' => false, 'error' => 'file-not-found', 'message' => 'Archivo no pertenece al usuario o no existe'], 404);
    }

    // 4) Construir ruta física relativa, sin rutas absolutas internas
    $uploadsDir = __DIR__ . '/uploads/knowledge/' . $userId . '/';
    $stored = $fileDb['stored_filename'] ?? ($fileDb['filename'] ?? null);
    $fullPath = $stored ? ($uploadsDir . $stored) : null;
    $exists = $fullPath ? file_exists($fullPath) : false;
    $sizeBytes = ($exists && is_file($fullPath)) ? (int)filesize($fullPath) : 0;

    // 5) Obtener settings del usuario (IA y prompt)
    // Leer settings completos
    $ensureConnection();
    $settingsStmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ? LIMIT 1");
    $settingsStmt->execute([$userId]);
    $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $aiProviderId = (int)($settings['default_provider_id'] ?? 0);
    $aiModelId = (int)($settings['default_model_id'] ?? 0);

    // Resolver prompt efectivo: prioridad body > prompt usuario > config default
    $promptSource = 'body';
    if (trim($prompt) === '') {
        if (!empty($settings['ai_prompt_ext_conten_file'])) {
            $prompt = (string)$settings['ai_prompt_ext_conten_file'];
            $promptSource = 'user_settings';
        } else {
            global $CONFIG;
            $prompt = (string)($CONFIG['AI_PROMPT_EXTRACT_DEFAULT'] ?? 'Resume en 5 bullets la información más relevante.');
            $promptSource = 'config_default';
        }
    }

    if ($aiProviderId <= 0) {
        json_out([
            'ok' => false,
            'error' => 'ai-provider-not-configured',
            'message' => 'No hay proveedor de IA configurado en user_settings (default_provider_id). Configúralo en la app.',
            'hint' => 'settings_get_safe.php → ai_provider/ai_model o sección de configuración de IA'
        ], 400);
    }

    // 6) Cargar ops_json del proveedor seleccionado por ID oficial
    $ensureConnection();
    $provStmt = $pdo->prepare("SELECT id, slug, name, ops_json FROM ai_providers WHERE id = ? LIMIT 1");
    $provStmt->execute([$aiProviderId]);
    $providerRow = $provStmt->fetch(PDO::FETCH_ASSOC);

    if (!$providerRow || empty($providerRow['ops_json'])) {
        json_out([
            'ok' => false,
            'error' => 'ops-json-missing',
            'message' => 'Proveedor sin ops_json configurado',
            'ai_provider_id' => $aiProviderId
        ], 400);
    }

    $ops = json_decode($providerRow['ops_json'], true);
    if (!is_array($ops)) {
        json_out([
            'ok' => false,
            'error' => 'ops-json-invalid',
            'message' => 'ops_json no es JSON válido',
            'ai_provider_id' => $aiProviderId
        ], 400);
    }

    // 7) Obtener API Key de IA del usuario para este provider
    $ensureConnection();
    $keyStmt = $pdo->prepare("SELECT api_key_enc FROM user_ai_api_keys WHERE user_id = ? AND provider_id = ? AND (status IS NULL OR status = 'active') ORDER BY id DESC LIMIT 1");
    $keyStmt->execute([$userId, $aiProviderId]);
    $keyRow = $keyStmt->fetch(PDO::FETCH_ASSOC);
    if (!$keyRow || empty($keyRow['api_key_enc'])) {
        json_out([
            'ok' => false,
            'error' => 'ai-key-missing',
            'message' => 'No hay API key configurada para este proveedor de IA. Configúrala en la app.'
        ], 400);
    }

    try {
        $apiKeyPlain = catai_decrypt($keyRow['api_key_enc']);
        aievs_log("API Key desencriptada: " . substr($apiKeyPlain, 0, 10) . "..." . substr($apiKeyPlain, -5) . " (longitud: " . strlen($apiKeyPlain) . ")");
    } catch (Throwable $e) {
        aievs_log("Error desencriptando API key: " . $e->getMessage());
        json_out([
            'ok' => false,
            'error' => 'ai-key-decrypt-failed',
            'message' => 'No se pudo descifrar la API key del usuario',
            'detail' => $e->getMessage()
        ], 500);
    }

    // 8) Preparar parámetros para pipeline de resumen desde VS
    $vectorStoreId = $fileDb['vector_store_id'] ?: $vsId;
    $pipelineKey = 'vs.summarize_from_vs';
    $hasPipeline = isset($ops['multi'][$pipelineKey]);

    // 8.0) Verificación básica - el archivo ya está procesado
    if (!$vectorStoreId) {
        json_out([
            'ok' => false,
            'error' => 'no-vector-store',
            'message' => 'El archivo no tiene Vector Store asignado. Adjúntalo primero.'
        ], 400);
    }
    
    aievs_log("Iniciando extracción para VS: $vectorStoreId, File: " . $fileDb['openai_file_id']);


    // Helpers internos para ejecutar operaciones del ops_json
    $httpCall = function(string $method, string $url, array $headers, ?string $body) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err) throw new Exception('curl-error: ' . $err);
        if ($code < 200 || $code >= 300) throw new Exception('http-' . $code . ': ' . (string)$resp);
        $decoded = json_decode((string)$resp, true);
        return is_array($decoded) ? $decoded : ['raw' => (string)$resp, 'http_code' => $code];
    };

    $replaceVars = function($template, array $vars) {
        if (is_string($template)) {
            foreach ($vars as $k => $v) {
                if (is_array($v)) continue;
                $template = str_replace('{{' . $k . '}}', (string)$v, $template);
            }
            return $template;
        } elseif (is_array($template)) {
            $out = [];
            foreach ($template as $k => $v) $out[$k] = $replaceVars($v, $vars);
            return $out;
        }
        return $template;
    };

    $debugOps = [];

    $buildOp = function(string $opName, array $vars) use ($ops, $apiKeyPlain, $replaceVars) {
        if (!isset($ops['multi'][$opName])) throw new Exception('op-not-found: ' . $opName);
        $cfg = $ops['multi'][$opName];
        $method = $cfg['method'] ?? 'GET';
        $url = $replaceVars($cfg['url_override'] ?? '', $vars);
        $hdrs = [];
        foreach (($cfg['headers'] ?? []) as $h) {
            $name = $h['name'];
            $val  = $replaceVars($h['value'], $vars);
            $val  = str_replace('{{API_KEY}}', $apiKeyPlain, $val);
            $hdrs[] = $name . ': ' . $val;
        }
        $body = null;
        if (isset($cfg['body'])) {
            $body = $replaceVars($cfg['body'], $vars);
        }
        // Garantizar headers necesarios para Assistants v2
        $hasBeta = false; $hasContentType = false; $hasAuth = false;
        foreach ($hdrs as $h) {
            $hn = strtolower(strtok($h, ':'));
            if ($hn === 'openai-beta') $hasBeta = true;
            if ($hn === 'content-type') $hasContentType = true;
            if ($hn === 'authorization') $hasAuth = true;
        }
        if (!$hasBeta) $hdrs[] = 'OpenAI-Beta: assistants=v2';
        if ($body !== null && !$hasContentType) $hdrs[] = 'Content-Type: application/json';
        if (!$hasAuth) $hdrs[] = 'Authorization: Bearer ' . $apiKeyPlain;
        return [ 'op' => $opName, 'method' => $method, 'url' => $url, 'headers' => $hdrs, 'body' => $body ];
    };

    $runOp = function(string $opName, array $vars) use ($httpCall, $buildOp, &$debugOps) {
        $built = $buildOp($opName, $vars);
        $debugOps[] = $built;
        return $httpCall($built['method'], $built['url'], $built['headers'], $built['body']);
    };

    // DRY RUN: devuelve las operaciones resueltas sin ejecutar IA
    if ($mode === 'dry_run') {
        $opsPreview = [
            $buildOp('assistant.create', [ 'VS_ID' => $vectorStoreId ]),
            $buildOp('thread.create', [ 'USER_PROMPT' => $prompt, 'VS_ID' => $vectorStoreId ]),
            $buildOp('run.create', [ 'THREAD_ID' => 'thread_id_here', 'ASSISTANT_ID' => 'assistant_id_here' ]),
            $buildOp('run.get', [ 'THREAD_ID' => 'thread_id_here', 'RUN_ID' => 'run_id_here' ]),
            $buildOp('messages.list', [ 'THREAD_ID' => 'thread_id_here', 'limit' => 50 ])
        ];
        json_out([
            'ok' => true,
            'mode' => 'dry_run',
            'input' => [ 'file_id' => $fileId, 'vector_store_id' => $vsId, 'prompt' => $prompt ],
            'vector_store' => [ 'id' => $vectorStoreId ],
            'ops_preview' => $opsPreview
        ]);
        return;
    }

    // Ejecutar extracción simplificada
    $t0 = microtime(true);
    $assistantId = $fileDb['assistant_id'] ?? '';
    $threadId = $fileDb['thread_id'] ?? '';
    $runId = '';
    $summaryText = '';
    $usage = [];

    // 1) Crear assistant si no existe
    if (!$assistantId) {
        aievs_log("Creando nuevo Assistant para VS: $vectorStoreId");
        $assistantCreate = executeSimpleOperationAI($providerRow, $apiKeyPlain, $ops['multi']['assistant.create'], [
            'VS_ID' => $vectorStoreId,
            'API_KEY' => $apiKeyPlain
        ], $debugOps);
        $assistantId = $assistantCreate['id'] ?? '';
        if (!$assistantId) throw new Exception('assistant-id-missing');

        // Actualizar knowledge_files con assistant_id
        $ensureConnection();
        $updateStmt = $pdo->prepare("UPDATE knowledge_files SET assistant_id = ? WHERE id = ?");
        $updateStmt->execute([$assistantId, $fileId]);
        
        aievs_log("Assistant creado y guardado: $assistantId");
    } else {
        aievs_log("Reutilizando Assistant existente: $assistantId");
    }

    // 2) Crear thread si no existe
    if (!$threadId) {
        aievs_log("Creando nuevo Thread para archivo: $fileId");
        $thread = executeSimpleOperationAI($providerRow, $apiKeyPlain, $ops['multi']['thread.create'], [
            'USER_PROMPT' => $prompt,
            'VS_ID' => $vectorStoreId,
            'API_KEY' => $apiKeyPlain
        ], $debugOps);
        $threadId = $thread['id'] ?? '';
        if (!$threadId) throw new Exception('thread-id-missing');
        
        // Actualizar knowledge_files con thread_id
        $threadUpdateStmt = $pdo->prepare("UPDATE knowledge_files SET thread_id = ? WHERE id = ?");
        $threadUpdateStmt->execute([$threadId, $fileId]);
        
        aievs_log("Thread creado y guardado: $threadId");
    } else {
        aievs_log("Reutilizando Thread existente: $threadId");
    }

    // 3) Crear run
    $run = executeSimpleOperationAI($providerRow, $apiKeyPlain, $ops['multi']['run.create'], [
        'THREAD_ID' => $threadId,
        'ASSISTANT_ID' => $assistantId,
        'API_KEY' => $apiKeyPlain
    ], $debugOps);
    $runId = $run['id'] ?? '';
    if (!$runId) throw new Exception('run-id-missing');

    // 4) Polling run.get hasta completed
    $attempts = 0; $max = 30; $status = '';
    do {
        $attempts++;
        usleep(900000); // 0.9s
        $st = executeSimpleOperationAI($providerRow, $apiKeyPlain, $ops['multi']['run.get'], [
            'THREAD_ID' => $threadId,
            'RUN_ID' => $runId,
            'API_KEY' => $apiKeyPlain
        ], $debugOps);
        $status = $st['status'] ?? '';
        if (isset($st['usage'])) { $usage = $st['usage']; }
        if (in_array($status, ['failed','cancelled','expired'], true)) {
            throw new Exception('run-status: ' . $status);
        }
    } while ($status !== 'completed' && $attempts < $max);
    if ($status !== 'completed') {
        throw new Exception('run-timeout');
    }

    // 5) Obtener mensajes
    $msgs = executeSimpleOperationAI($providerRow, $apiKeyPlain, $ops['multi']['messages.list'], [
        'THREAD_ID' => $threadId,
        'limit' => 50,
        'API_KEY' => $apiKeyPlain
    ], $debugOps);
    $dataMsgs = $msgs['data'] ?? [];
    foreach ($dataMsgs as $m) {
        if (($m['role'] ?? '') === 'assistant') {
            $summaryText = $m['content'][0]['text']['value'] ?? '';
            if ($summaryText !== '') break;
        }
    }

    $t1 = microtime(true);
    $latencyMs = (int)round(($t1 - $t0) * 1000);

    $tokensInput  = (int)($usage['prompt_tokens'] ?? ($usage['input_tokens'] ?? 0));
    $tokensOutput = (int)($usage['completion_tokens'] ?? ($usage['output_tokens'] ?? 0));
    $tokensTotal  = (int)($usage['total_tokens'] ?? ($tokensInput + $tokensOutput));

    // Reabrir conexión PDO si se ha cerrado (MySQL server has gone away)
    $ensureConnection();

    // Persistencia en DB (usando columnas existentes)
    $title = ($fileDb['original_filename'] ?? 'Archivo') . ' - Análisis IA (OPENAI)';
    $kbStmt = $pdo->prepare("
        INSERT INTO knowledge_base (
            knowledge_type, title, content, summary, tags, confidence_score, 
            created_by, source_type, source_file
        ) VALUES (
            'user_insight', ?, ?, ?, ?, 0.70, 
            ?, 'ai_extraction', ?
        )
    ");
    $tagsJson = json_encode(['extraído','archivo']);
    $shortSummary = mb_substr($summaryText, 0, 300);
    $kbStmt->execute([
        $title, $summaryText, $shortSummary, $tagsJson, $userId, 
        ($fileDb['original_filename'] ?? null)
    ]);
    $knowledgeId = (int)$pdo->lastInsertId();

    // Actualizar knowledge_files con estado completed y métricas (usando columnas existentes)
    $completedStmt = $pdo->prepare("
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
    $completedStmt->execute([
        $aiModelId, $runId, $tokensInput, $tokensOutput, $tokensTotal, 
        $fileId, $userId
    ]);


    json_out([
        'ok' => true,
        'message' => 'Resumen generado desde Vector Store',
        'user' => [ 'id' => $userId, 'email' => $user['email'] ?? null ],
        'input' => [ 'file_id' => $fileId, 'vector_store_id' => $vsId, 'prompt' => $prompt ],
        'file_db' => $fileDb,
        'ai' => [
            'provider_id' => (int)$providerRow['id'],
            'assistant_id' => $assistantId,
            'thread_id' => $threadId,
            'run_id' => $runId,
        ],
        'vector_store' => [ 'id' => $vectorStoreId ],
        'summary' => $summaryText,
        'saved' => [ 'knowledge_id' => $knowledgeId ],
        'metrics' => [
            'latency_ms' => $latencyMs,
            'tokens_input' => $tokensInput,
            'tokens_output' => $tokensOutput,
            'tokens_total' => $tokensTotal,
            'cost_usd' => 0.0
        ],
        'debug' => [ 'ops_used' => $debugOps ],
    ]);

} catch (Throwable $e) {
    aievs_log('ERROR: ' . $e->getMessage());
    aievs_log('ERROR Stack trace: ' . $e->getTraceAsString());
    json_out(['error' => 'internal-error', 'detail' => $e->getMessage()], 500);
}
?>

