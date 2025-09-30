<?php
// /bolsa/api/test_ai_extract_simple_safe.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Crypto_safe.php';

json_header();

try {
    // 1) Autenticación
    $user = require_user();
    $userId = $user['id'];
    
    // 2) Obtener parámetros
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $fileId = (int)($input['file_id'] ?? 0);
    
    if (!$fileId) {
        json_out(['error' => 'file-id-required'], 400);
    }
    
    $pdo = db();
    
    // 3) Obtener información del archivo
    $fileStmt = $pdo->prepare("
        SELECT * FROM knowledge_files 
        WHERE id = ? AND user_id = ?
    ");
    $fileStmt->execute([$fileId, $userId]);
    $file = $fileStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$file) {
        json_out(['error' => 'file-not-found'], 404);
    }
    
    // 4) VALIDACIÓN DE DESINCRONIZACIÓN
    $syncStatus = [
        'has_file_id' => !empty($file['openai_file_id']),
        'extraction_completed' => $file['extraction_status'] === 'completed',
        'is_synced' => !empty($file['openai_file_id']),
        'needs_upload' => empty($file['openai_file_id']),
        'sync_issue' => $file['extraction_status'] === 'completed' && empty($file['openai_file_id'])
    ];
    
    error_log("=== SYNC VALIDATION ===");
    error_log("File ID: $fileId");
    error_log("Has file_id: " . ($syncStatus['has_file_id'] ? 'YES' : 'NO'));
    error_log("Extraction completed: " . ($syncStatus['extraction_completed'] ? 'YES' : 'NO'));
    error_log("Sync issue: " . ($syncStatus['sync_issue'] ? 'YES' : 'NO'));
    
    // 5) Obtener configuración del usuario
    $settingsStmt = $pdo->prepare('SELECT ai_provider, ai_model, ai_prompt_ext_conten_file FROM user_settings WHERE user_id = ?');
    $settingsStmt->execute([$userId]);
    $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$settings) {
        json_out(['error' => 'user-settings-not-found'], 404);
    }
    
    $aiProvider = $settings['ai_provider'] ?? 'auto';
    $aiModel = $settings['ai_model'] ?? '';
    $customPrompt = $settings['ai_prompt_ext_conten_file'] ?? null;
    
    // 6) Obtener configuración del proveedor de IA
    $providerStmt = $pdo->prepare("
        SELECT p.*, k.api_key_enc, k.project_id 
        FROM ai_providers p
        LEFT JOIN user_ai_api_keys k ON p.id = k.provider_id AND k.user_id = ? AND k.status = 'active'
        WHERE p.slug = ? AND p.is_enabled = 1
        ORDER BY k.created_at DESC
        LIMIT 1
    ");
    $providerStmt->execute([$userId, $aiProvider]);
    $provider = $providerStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$provider) {
        json_out(['error' => 'ai-provider-not-found'], 404);
    }
    
    if (empty($provider['api_key_enc'])) {
        json_out(['error' => 'api-key-not-found'], 404);
    }
    
    // 7) Desencriptar API key
    $apiKey = catai_decrypt($provider['api_key_enc']);
    
    // 8) Obtener configuración de operaciones del proveedor
    $opsJson = $provider['ops_json'] ? json_decode($provider['ops_json'], true) : null;
    
    error_log("=== PROVIDER CONFIG ===");
    error_log("Provider: " . $provider['name']);
    error_log("API Key length: " . strlen($apiKey));
    error_log("Has ops_json: " . ($opsJson ? 'YES' : 'NO'));
    
    // 9) Buscar operación de upload en ops_json
    $uploadConfig = null;
    if ($opsJson && isset($opsJson['multi']['vs.upload'])) {
        $uploadConfig = $opsJson['multi']['vs.upload'];
        error_log("Found upload config in ops_json");
    }
    
    // 10) IMPLEMENTAR FLUJO COMPLETO: UPLOAD + VECTOR STORE + EXTRACCIÓN
    $uploadResult = null;
    $vectorStoreResult = null;
    $extractionResult = null;
    
    if ($syncStatus['needs_upload']) {
        error_log("=== UPLOADING FILE TO AI ===");
        
        // Construir path del archivo físico
        $filePath = __DIR__ . '/uploads/knowledge/' . $userId . '/' . $file['stored_filename'];
        
        if (!file_exists($filePath)) {
            json_out(['error' => 'physical-file-not-found', 'path' => $filePath], 404);
        }
        
        // Determinar MIME type
        $mimeType = $file['mime_type'] ?: 'application/pdf';
        
        // FORZAR EXTENSIÓN MINÚSCULA para evitar error "Invalid extension PDF"
        $fileExtension = strtolower(pathinfo($file['stored_filename'], PATHINFO_EXTENSION));
        $safeFilename = pathinfo($file['stored_filename'], PATHINFO_FILENAME) . '.' . $fileExtension;
        
        error_log("Upload details:");
        error_log("- Original filename: " . $file['original_filename']);
        error_log("- Stored filename: " . $file['stored_filename']);
        error_log("- Safe filename: " . $safeFilename);
        error_log("- File path: " . $filePath);
        error_log("- MIME type: " . $mimeType);
        
        // Configurar cURL para OpenAI Files API
        $uploadUrl = $provider['base_url'] . '/v1/files';
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $uploadUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey
                // NO incluir Content-Type - cURL lo genera automáticamente con boundary
            ],
            CURLOPT_POSTFIELDS => [
                'purpose' => 'assistants',
                'file' => new CURLFile($filePath, $mimeType, $safeFilename)
            ]
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);
        
        error_log("Upload response HTTP Code: " . $httpCode);
        error_log("Upload response: " . substr($response, 0, 500));
        error_log("cURL Error: " . ($curlError ?: 'None'));
        
        if ($curlError) {
            $uploadResult = ['error' => 'curl_error', 'detail' => $curlError];
        } elseif ($httpCode !== 200) {
            $uploadResult = ['error' => 'upload_failed', 'http_code' => $httpCode, 'response' => $response];
        } else {
            $uploadData = json_decode($response, true);
            $openaiFileId = $uploadData['id'] ?? null;
            
            if ($openaiFileId) {
                // Calcular costos aproximados para upload (OpenAI no cobra por upload, solo por uso)
                $bytes = $uploadData['bytes'] ?? 0;
                $uploadCost = 0.00; // OpenAI no cobra por upload de archivos
                $uploadTokens = 0; // No hay tokens en upload
                
                // Actualizar knowledge_files con el file_id de OpenAI y información de costos
                $updateStmt = $pdo->prepare("
                    UPDATE knowledge_files 
                    SET openai_file_id = ?, 
                        upload_status = 'processed',
                        vector_provider = ?,
                        last_extraction_cost_usd = ?,
                        last_extraction_total_tokens = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([$openaiFileId, $provider['slug'], $uploadCost, $uploadTokens, $fileId]);
                
                // Registrar evento de uso en ai_usage_events
                $usageStmt = $pdo->prepare("
                    INSERT INTO ai_usage_events 
                    (user_id, provider_id, operation_type, input_tokens, output_tokens, total_tokens, cost_usd, metadata)
                    VALUES (?, ?, 'file_upload', ?, ?, ?, ?, ?)
                ");
                $usageMetadata = json_encode([
                    'file_id' => $fileId,
                    'openai_file_id' => $openaiFileId,
                    'filename' => $file['original_filename'],
                    'file_size' => $bytes,
                    'provider' => $provider['name']
                ]);
                $usageStmt->execute([
                    $userId, 
                    $provider['id'], 
                    $uploadTokens, 
                    $uploadTokens, 
                    $uploadTokens, 
                    $uploadCost, 
                    $usageMetadata
                ]);
                
                $uploadResult = [
                    'success' => true,
                    'openai_file_id' => $openaiFileId,
                    'bytes' => $bytes,
                    'cost_usd' => $uploadCost,
                    'tokens' => $uploadTokens,
                    'message' => 'Archivo subido exitosamente a OpenAI'
                ];
                
                error_log("=== UPLOAD SUCCESS ===");
                error_log("OpenAI File ID: " . $openaiFileId);
                error_log("Bytes: " . $bytes);
                error_log("Cost: $" . $uploadCost);
                error_log("Tokens: " . $uploadTokens);
                
                // Actualizar el objeto $file para la respuesta
                $file['openai_file_id'] = $openaiFileId;
                $file['upload_status'] = 'processed';
                $file['last_extraction_cost_usd'] = $uploadCost;
                $file['last_extraction_total_tokens'] = $uploadTokens;
                
            } else {
                $uploadResult = ['error' => 'no_file_id', 'response' => $response];
            }
        }
    }
    
    // 11) CREAR/OBTENER VECTOR STORE (CRÍTICO PARA AISLAMIENTO DE USUARIOS)
    // Siempre verificar/crear VS, independientemente del estado de upload
    if ($syncStatus['has_file_id'] || ($uploadResult && $uploadResult['success'])) {
        error_log("=== CREATING/GETTING VECTOR STORE ===");
        
        // Buscar Vector Store existente para el usuario
        $vsStmt = $pdo->prepare("
            SELECT * FROM ai_vector_stores 
            WHERE owner_user_id = ? AND provider_id = ? AND status = 'ready'
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $vsStmt->execute([$userId, $provider['id']]);
        $existingVS = $vsStmt->fetch();
        
        if ($existingVS) {
            // Reutilizar VS existente
            $vectorStoreResult = [
                'success' => true,
                'vector_store_id' => $existingVS['external_id'],
                'local_id' => $existingVS['id'],
                'status' => 'reused',
                'message' => 'Vector Store existente reutilizado'
            ];
            
            error_log("=== VS REUSE ===");
            error_log("Reusing existing vector store: " . $existingVS['external_id']);
            
        } else {
            // Crear nuevo Vector Store
            $vsName = "CATAI_VS_User_{$userId}_" . date('YmdHis');
            $uploadUrl = $provider['base_url'] . '/v1/vector_stores';
            
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $uploadUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                    'OpenAI-Beta: assistants=v2'
                ],
                CURLOPT_POSTFIELDS => json_encode(['name' => $vsName])
            ]);
            
            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);
            curl_close($curl);
            
            if ($error) {
                $vectorStoreResult = ['error' => 'curl_error', 'detail' => $error];
            } elseif ($httpCode !== 200) {
                $vectorStoreResult = ['error' => 'vs_create_failed', 'http_code' => $httpCode, 'response' => $response];
            } else {
                $vsData = json_decode($response, true);
                $externalVSId = $vsData['id'] ?? null;
                
                if ($externalVSId) {
                    // Insertar en ai_vector_stores
                    $insertStmt = $pdo->prepare("
                        INSERT INTO ai_vector_stores 
                        (provider_id, project_id, external_id, owner_user_id, name, status, bytes_used, doc_count)
                        VALUES (?, ?, ?, ?, ?, 'ready', ?, ?)
                    ");
                    $insertStmt->execute([
                        $provider['id'], 
                        $project_id, 
                        $externalVSId, 
                        $userId, 
                        $vsName,
                        $vsData['bytes_used'] ?? 0,
                        $vsData['file_counts']['total_files'] ?? 0
                    ]);
                    $localVSId = $pdo->lastInsertId();
                    
                    $vectorStoreResult = [
                        'success' => true,
                        'vector_store_id' => $externalVSId,
                        'local_id' => $localVSId,
                        'status' => 'created',
                        'name' => $vsName,
                        'message' => 'Vector Store creado exitosamente'
                    ];
                    
                    error_log("=== VS CREATE SUCCESS ===");
                    error_log("Created new vector store: $externalVSId (local_id: $localVSId)");
                } else {
                    $vectorStoreResult = ['error' => 'no_vs_id', 'response' => $response];
                }
            }
        }
        
        // 12) ADJUNTAR ARCHIVO AL VECTOR STORE
        $attachResult = null;
        if ($vectorStoreResult && $vectorStoreResult['success']) {
            $vsId = $vectorStoreResult['vector_store_id'];
            $localVSId = $vectorStoreResult['local_id'];
            $openaiFileId = $file['openai_file_id'];
            
            if ($openaiFileId) {
                error_log("=== ATTACHING FILE TO VECTOR STORE ===");
                error_log("VS ID: $vsId, File ID: $openaiFileId");
                
                // Adjuntar archivo al Vector Store usando vs.attach
                $attachUrl = $provider['base_url'] . '/v1/vector_stores/' . $vsId . '/files';
                
                $curl = curl_init();
                curl_setopt_array($curl, [
                    CURLOPT_URL => $attachUrl,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $apiKey,
                        'Content-Type: application/json'
                    ],
                    CURLOPT_POSTFIELDS => json_encode(['file_id' => $openaiFileId])
                ]);
                
                $response = curl_exec($curl);
                $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                $error = curl_error($curl);
                curl_close($curl);
                
                error_log("Attach Response HTTP Code: $httpCode");
                error_log("Attach Response: " . substr($response, 0, 500));
                
                if ($error) {
                    $attachResult = ['error' => 'curl_error', 'detail' => $error];
                } elseif ($httpCode !== 200) {
                    $attachResult = ['error' => 'attach_failed', 'http_code' => $httpCode, 'response' => $response];
                } else {
                    $attachData = json_decode($response, true);
                    $attachResult = [
                        'success' => true,
                        'message' => 'Archivo adjuntado al Vector Store exitosamente',
                        'data' => $attachData
                    ];
                    
                    error_log("=== FILE ATTACHED TO VS ===");
                    error_log("File $openaiFileId attached to VS $vsId");
                    
                    // Actualizar conteo de documentos en ai_vector_stores
                    $updateVSStmt = $pdo->prepare("
                        UPDATE ai_vector_stores 
                        SET doc_count = doc_count + 1,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $updateVSStmt->execute([$localVSId]);
                    
                    error_log("=== VS DOC COUNT UPDATED ===");
                    error_log("Updated doc_count for VS local_id: $localVSId");
                }
            }
            
            // Actualizar knowledge_files con vector store
            $updateFileStmt = $pdo->prepare("
                UPDATE knowledge_files 
                SET vector_store_id = ?,
                    vector_store_local_id = ?,
                    vector_status = 'indexed',
                    vector_last_indexed_at = NOW(),
                    extraction_status = 'completed',
                    updated_at = NOW()
                WHERE id = ? AND user_id = ?
            ");
            $updateFileStmt->execute([$vsId, $localVSId, $fileId, $userId]);
            
            error_log("=== FILE UPDATED WITH VS ===");
            error_log("Updated knowledge_files with vector store: $vsId");
            
            // Actualizar el objeto $file para la respuesta
            $file['vector_store_id'] = $vsId;
            $file['vector_store_local_id'] = $localVSId;
            $file['extraction_status'] = 'completed';
        }
    }
    
    // 13) Respuesta con información completa
    json_out([
        'ok' => true,
        'test' => 'ai-extract-generic',
        'user' => [
            'id' => $userId,
            'email' => $user['email']
        ],
        'file' => [
            'id' => $fileId,
            'original_filename' => $file['original_filename'],
            'stored_filename' => $file['stored_filename'],
            'file_type' => $file['file_type'],
            'file_size' => $file['file_size'],
            'upload_status' => $file['upload_status'],
            'extraction_status' => $file['extraction_status'],
            'openai_file_id' => $file['openai_file_id']
        ],
        'sync_status' => $syncStatus,
        'settings' => [
            'ai_provider' => $aiProvider,
            'ai_model' => $aiModel,
            'has_custom_prompt' => !empty($customPrompt),
            'prompt_length' => strlen($customPrompt ?? ''),
        ],
        'provider' => [
            'id' => $provider['id'],
            'name' => $provider['name'],
            'slug' => $provider['slug'],
            'base_url' => $provider['base_url'],
            'has_api_key' => !empty($apiKey),
            'has_upload_config' => !empty($uploadConfig),
            'upload_config' => $uploadConfig
        ],
        'upload_result' => $uploadResult,
        'vector_store_result' => $vectorStoreResult,
        'attach_result' => $attachResult,
        'next_steps' => $syncStatus['needs_upload'] ? [
            'action' => ($uploadResult && $vectorStoreResult) ? 'complete_flow_success' : 'flow_incomplete',
            'description' => ($uploadResult && $vectorStoreResult) ? 'Archivo subido y Vector Store configurado' : 'Error en el flujo',
            'sync_issue' => $syncStatus['sync_issue']
        ] : [
            'action' => 'ready_for_extraction',
            'description' => 'Archivo ya está en la IA, listo para extracción'
        ],
        'message' => ($uploadResult && $vectorStoreResult) ? 'Flujo completo exitoso: Archivo + Vector Store' : 'Análisis genérico completado exitosamente'
    ]);
    
} catch (Throwable $e) {
    error_log("test_ai_extract_simple_safe.php error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    json_out(['error' => 'test-failed', 'detail' => $e->getMessage()], 500);
}
