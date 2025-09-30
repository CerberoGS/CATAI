<?php declare(strict_types=1);

/**
 * /bolsa/api/run_op_safe.php
 * 
 * Función genérica runOp() para operaciones declarativas de IA.
 * Soporta 3 tipos de operaciones:
 * 1. vs.upload - Subir archivo a proveedor de IA
 * 2. vs.create_or_get - Crear/obtener vector store
 * 3. vs.analyze - Analizar contenido usando vector store
 * 
 * Actualiza múltiples tablas durante el proceso:
 * - knowledge_files (file_id, vector_store_id, costos, tokens)
 * - ai_vector_stores (control por usuario)
 * - ai_vector_documents (asociación archivo-VS)
 * - ai_usage_events (costes y métricas)
 * - ai_learning_metrics (métricas del usuario)
 * - knowledge_base (contenido extraído)
 */

require_once 'helpers.php';
require_once 'db.php';

try {
    $user = require_user();
    $user_id = $user['user_id'] ?? $user['id'] ?? null;
    
    if (!$user_id) {
        json_error('user_id_missing', 400, 'User ID not found in token');
    }

    // Validación de método
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Método no permitido', 405);
    }

    // Obtener datos de entrada
    $input = read_json_body();
    if (!$input) {
        json_error('JSON inválido', 400);
    }

    $provider_id = $input['provider_id'] ?? null;
    $op = $input['op'] ?? null;
    $params = $input['params'] ?? [];

    if (!$provider_id || !$op) {
        json_error('provider_id y op son requeridos', 400);
    }

    // Logging para debugging
    error_log("=== RUN_OP DEBUG ===");
    error_log("User ID: $user_id");
    error_log("Provider ID: $provider_id");
    error_log("Operation: $op");
    error_log("Params: " . json_encode($params));

    // Obtener configuración del proveedor
    $pdo = db();
    $providerStmt = $pdo->prepare("
        SELECT p.*, m.api_name, m.pricing_input_usd, m.pricing_output_usd, m.supports_file_search
        FROM ai_providers p 
        LEFT JOIN ai_models m ON p.id = m.provider_id AND m.is_enabled = 1
        WHERE p.id = ? AND p.is_enabled = 1
    ");
    $providerStmt->execute([$provider_id]);
    $provider = $providerStmt->fetch();

    if (!$provider) {
        json_error('Proveedor no encontrado o deshabilitado', 404);
    }

    // Obtener API key del usuario
    $keyStmt = $pdo->prepare("
        SELECT api_key_enc, label, project_id 
        FROM user_ai_api_keys 
        WHERE user_id = ? AND provider_id = ? AND status = 'active'
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $keyStmt->execute([$user_id, $provider_id]);
    $keyRow = $keyStmt->fetch();

    if (!$keyRow) {
        json_error('No se encontró API key válida para este proveedor', 404);
    }

    // Desencriptar API key
    if (!function_exists('catai_decrypt')) {
        require_once 'Crypto_safe.php';
    }
    $api_key = catai_decrypt($keyRow['api_key_enc']);
    $project_id = $keyRow['project_id'];

    // Ejecutar operación según el tipo
    $result = [];
    $start_time = microtime(true);

    try {
        switch ($op) {
            case 'vs.upload':
                $result = run_upload_operation($provider, $api_key, $project_id, $params, $user_id, $pdo);
                break;
                
            case 'vs.create_or_get':
                $result = run_vector_store_operation($provider, $api_key, $project_id, $params, $user_id, $pdo);
                break;
                
            case 'vs.analyze':
                $result = run_analyze_operation($provider, $api_key, $project_id, $params, $user_id, $pdo);
                break;
                
            default:
                json_error("Operación no soportada: $op", 400);
        }
    } catch (Throwable $e) {
        error_log("Error en operación $op: " . $e->getMessage());
        json_error("Error en operación: " . $e->getMessage(), 500);
    }

    $end_time = microtime(true);
    $latency_ms = round(($end_time - $start_time) * 1000);

    // Registrar evento de uso
    record_usage_event($user_id, $provider_id, $provider['api_name'] ?? null, $op, $result, $latency_ms, $pdo);

    json_out([
        'ok' => true,
        'op' => $op,
        'provider' => $provider['name'],
        'result' => $result,
        'latency_ms' => $latency_ms
    ]);

} catch (Throwable $e) {
    error_log("Error en run_op_safe.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    json_error('Error interno del servidor', 500);
}

/**
 * Operación vs.upload: Subir archivo a proveedor
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

        // 3. Insertar/actualizar en ai_vector_stores con información completa
        if ($existing_vs) {
            $updateStmt = $pdo->prepare("
                UPDATE ai_vector_stores 
                SET external_id = ?, 
                    status = 'ready', 
                    bytes_used = ?,
                    doc_count = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([
                $external_vs_id, 
                $result['bytes_used'] ?? 0,
                $result['file_counts']['total_files'] ?? 0,
                $existing_vs['id']
            ]);
            $local_id = $existing_vs['id'];
            
            error_log("=== VS UPDATE ===");
            error_log("Updated existing vector store: $external_vs_id");
        } else {
            $insertStmt = $pdo->prepare("
                INSERT INTO ai_vector_stores 
                (provider_id, project_id, external_id, owner_user_id, name, status, bytes_used, doc_count)
                VALUES (?, ?, ?, ?, ?, 'ready', ?, ?)
            ");
            $insertStmt->execute([
                $provider['id'], 
                $project_id, 
                $external_vs_id, 
                $user_id, 
                $vs_name,
                $result['bytes_used'] ?? 0,
                $result['file_counts']['total_files'] ?? 0
            ]);
            $local_id = $pdo->lastInsertId();
            
            error_log("=== VS CREATE SUCCESS ===");
            error_log("Created new vector store: $external_vs_id (local_id: $local_id)");
        }

        return [
            'vector_store_id' => $external_vs_id,
            'local_id' => $local_id,
            'status' => 'created',
            'name' => $vs_name
        ];
    }

    throw new Exception('Proveedor no soportado para vector store');
}

/**
 * Operación vs.analyze: Analizar contenido usando vector store
 */
function run_analyze_operation($provider, $api_key, $project_id, $params, $user_id, $pdo) {
    $file_id = $params['file_id'] ?? null;
    $vector_store_id = $params['vector_store_id'] ?? null;
    $prompt = $params['prompt'] ?? 'Extrae y analiza el contenido de este archivo';

    if (!$file_id || !$vector_store_id) {
        throw new Exception('file_id y vector_store_id son requeridos para vs.analyze');
    }

    // Obtener información del archivo y vector store
    $fileStmt = $pdo->prepare("
        SELECT kf.*, avs.external_id as vs_external_id
        FROM knowledge_files kf
        JOIN ai_vector_stores avs ON kf.vector_store_local_id = avs.id
        WHERE kf.id = ? AND kf.user_id = ? AND avs.owner_user_id = ?
    ");
    $fileStmt->execute([$file_id, $user_id, $user_id]);
    $file = $fileStmt->fetch();

    if (!$file) {
        throw new Exception('Archivo o vector store no encontrado');
    }

    // Actualizar estado de extracción
    $updateStmt = $pdo->prepare("
        UPDATE knowledge_files 
        SET extraction_status = 'in_progress', 
            last_extraction_started_at = NOW(),
            extraction_attempts = extraction_attempts + 1
        WHERE id = ?
    ");
    $updateStmt->execute([$file_id]);

    // Realizar análisis usando OpenAI Assistants API
    if (strpos($provider['name'], 'OpenAI') !== false) {
        $base_url = $provider['base_url'];
        
        // Crear assistant con file_search
        $assistant_url = $base_url . '/assistants';
        $assistant_data = [
            'model' => 'gpt-4o-mini',
            'name' => 'CATAI Content Extractor',
            'instructions' => 'Eres un experto analista de contenido. Extrae información relevante, patrones y insights del contenido proporcionado.',
            'tools' => [['type' => 'file_search']],
            'tool_resources' => [
                'file_search' => [
                    'vector_store_ids' => [$file['vs_external_id']]
                ]
            ]
        ];

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $assistant_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($assistant_data)
        ]);

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($http_code !== 200) {
            throw new Exception("Error creando assistant: $response");
        }

        $assistant_result = json_decode($response, true);
        $assistant_id = $assistant_result['id'];

        // Crear thread
        $thread_url = $base_url . '/threads';
        $thread_data = [
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ]
        ];

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $thread_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($thread_data)
        ]);

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($http_code !== 200) {
            throw new Exception("Error creando thread: $response");
        }

        $thread_result = json_decode($response, true);
        $thread_id = $thread_result['id'];

        // Ejecutar run
        $run_url = $base_url . "/threads/$thread_id/runs";
        $run_data = [
            'assistant_id' => $assistant_id
        ];

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $run_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($run_data)
        ]);

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($http_code !== 200) {
            throw new Exception("Error ejecutando run: $response");
        }

        $run_result = json_decode($response, true);
        $run_id = $run_result['id'];

        // Esperar a que termine el run (polling)
        $max_attempts = 30;
        $attempt = 0;
        do {
            sleep(2);
            $attempt++;
            
            $status_url = $base_url . "/threads/$thread_id/runs/$run_id";
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $status_url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $api_key
                ]
            ]);
            
            $response = curl_exec($curl);
            $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            
            if ($http_code !== 200) {
                throw new Exception("Error verificando status: $response");
            }
            
            $status_result = json_decode($response, true);
            $status = $status_result['status'];
            
            if ($status === 'completed') {
                break;
            } elseif ($status === 'failed') {
                throw new Exception('El análisis falló: ' . ($status_result['last_error']['message'] ?? 'Error desconocido'));
            }
            
        } while ($attempt < $max_attempts);

        if ($attempt >= $max_attempts) {
            throw new Exception('Timeout esperando análisis');
        }

        // Obtener mensajes del thread
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
        curl_close($curl);
        
        $messages_result = json_decode($response, true);
        $content = '';
        
        if (!empty($messages_result['data'])) {
            foreach ($messages_result['data'] as $message) {
                if ($message['role'] === 'assistant' && !empty($message['content'])) {
                    foreach ($message['content'] as $content_item) {
                        if ($content_item['type'] === 'text') {
                            $content .= $content_item['text']['value'] . "\n";
                        }
                    }
                }
            }
        }

        // Obtener información de uso (tokens y costos)
        $usage = $status_result['usage'] ?? [];
        $input_tokens = $usage['prompt_tokens'] ?? 0;
        $output_tokens = $usage['completion_tokens'] ?? 0;
        $total_tokens = $usage['total_tokens'] ?? 0;

        // Calcular costo (precios por defecto si no están en DB)
        $input_price = $provider['pricing_input_usd'] ?? 0.000003; // GPT-4o-mini
        $output_price = $provider['pricing_output_usd'] ?? 0.000015;
        $cost_usd = ($input_tokens * $input_price) + ($output_tokens * $output_price);

        // Actualizar knowledge_files con resultados
        $updateStmt = $pdo->prepare("
            UPDATE knowledge_files 
            SET extraction_status = 'completed',
                last_extraction_finished_at = NOW(),
                last_extraction_model = ?,
                last_extraction_response_id = ?,
                last_extraction_input_tokens = ?,
                last_extraction_output_tokens = ?,
                last_extraction_total_tokens = ?,
                last_extraction_cost_usd = ?,
                extracted_items = 1
            WHERE id = ?
        ");
        $updateStmt->execute([
            'gpt-4o-mini',
            $run_id,
            $input_tokens,
            $output_tokens,
            $total_tokens,
            $cost_usd,
            $file_id
        ]);

        // Guardar contenido en knowledge_base
        $kbStmt = $pdo->prepare("
            INSERT INTO knowledge_base 
            (knowledge_type, title, content, summary, source_type, source_file, created_by, confidence_score)
            VALUES ('user_insight', ?, ?, ?, 'ai_extraction', ?, ?, 0.85)
        ");
        $kbStmt->execute([
            'Análisis de ' . $file['original_filename'],
            $content,
            substr($content, 0, 500) . '...',
            $file['original_filename'],
            $user_id
        ]);

        // Actualizar métricas del usuario
        $metricsStmt = $pdo->prepare("
            INSERT INTO ai_learning_metrics (user_id, total_analyses, success_rate, accuracy_score)
            VALUES (?, 1, 100.00, 85.00)
            ON DUPLICATE KEY UPDATE 
                total_analyses = total_analyses + 1,
                accuracy_score = (accuracy_score + 85.00) / 2,
                last_updated = NOW()
        ");
        $metricsStmt->execute([$user_id]);

        // Limpiar recursos temporales
        $delete_assistant_url = $base_url . "/assistants/$assistant_id";
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $delete_assistant_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $api_key
            ]
        ]);
        curl_exec($curl);
        curl_close($curl);

        return [
            'content' => $content,
            'input_tokens' => $input_tokens,
            'output_tokens' => $output_tokens,
            'total_tokens' => $total_tokens,
            'cost_usd' => $cost_usd,
            'run_id' => $run_id,
            'status' => 'completed'
        ];
    }

    throw new Exception('Proveedor no soportado para análisis');
}

/**
 * Registrar evento de uso en ai_usage_events
 */
function record_usage_event($user_id, $provider_id, $model_name, $request_kind, $result, $latency_ms, $pdo) {
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
}
