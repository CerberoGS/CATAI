<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Crypto_safe.php';

json_header();

try {
    $user = require_user();
    $userId = (int)($user['id'] ?? 0);
    
    if ($userId <= 0) {
        json_error('invalid-user', 401);
    }
    
    $pdo = db();
    
    // Obtener configuración del usuario
    $settingsStmt = $pdo->prepare('SELECT ai_provider, ai_model FROM user_settings WHERE user_id = ?');
    $settingsStmt->execute([$userId]);
    $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$settings) {
        json_error('user-settings-not-found', 404);
    }
    
    $aiProvider = $settings['ai_provider'] ?? 'auto';
    
    // Obtener configuración del proveedor de IA
    $providerStmt = $pdo->prepare("
        SELECT p.*, k.api_key_enc 
        FROM ai_providers p
        LEFT JOIN user_ai_api_keys k ON p.id = k.provider_id AND k.user_id = ? AND k.status = 'active'
        WHERE p.slug = ? AND p.is_enabled = 1
        ORDER BY k.created_at DESC
        LIMIT 1
    ");
    $providerStmt->execute([$userId, $aiProvider]);
    $provider = $providerStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$provider || empty($provider['api_key_enc'])) {
        json_out(['ok' => false, 'error' => 'ai-provider-not-found'], 404);
        exit;
    }
    
    // Desencriptar API key
    $apiKey = catai_decrypt($provider['api_key_enc']);
    
    // Listar archivos de OpenAI usando vs.list
    $listUrl = $provider['base_url'] . '/v1/files?limit=1000&order=desc';
    
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $listUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey
        ]
    ]);
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    
    if ($error) {
        json_out(['ok' => false, 'error' => 'curl-error', 'detail' => $error], 500);
        exit;
    }
    
    if ($httpCode !== 200) {
        json_out(['ok' => false, 'error' => 'openai-error', 'http_code' => $httpCode, 'response' => $response], $httpCode);
        exit;
    }
    
    $openaiData = json_decode($response, true);
    $openaiFiles = $openaiData['data'] ?? [];
    
    // Procesar archivos de OpenAI
    $syncedFiles = [];
    $newFiles = 0;
    $updatedFiles = 0;
    
    foreach ($openaiFiles as $openaiFile) {
        $openaiFileId = $openaiFile['id'] ?? null;
        $filename = $openaiFile['filename'] ?? '';
        $bytes = $openaiFile['bytes'] ?? 0;
        $createdAt = $openaiFile['created_at'] ?? null;
        
        if (!$openaiFileId) continue;
        
        // Buscar archivo en nuestra DB
        $fileStmt = $pdo->prepare("
            SELECT * FROM knowledge_files 
            WHERE openai_file_id = ? AND user_id = ?
        ");
        $fileStmt->execute([$openaiFileId, $userId]);
        $existingFile = $fileStmt->fetch();
        
        if ($existingFile) {
            // Actualizar archivo existente
            $updateStmt = $pdo->prepare("
                UPDATE knowledge_files 
                SET original_filename = ?,
                    file_size = ?,
                    upload_status = 'processed',
                    updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$filename, $bytes, $existingFile['id']]);
            $updatedFiles++;
            
            $syncedFiles[] = [
                'id' => $existingFile['id'],
                'openai_file_id' => $openaiFileId,
                'filename' => $filename,
                'file_size' => $bytes,
                'status' => 'updated'
            ];
        } else {
            // Crear nuevo archivo en DB
            $insertStmt = $pdo->prepare("
                INSERT INTO knowledge_files 
                (user_id, original_filename, stored_filename, openai_file_id, file_type, file_size, mime_type, upload_status, extraction_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'processed', 'pending')
            ");
            
            $fileExtension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $storedFilename = $openaiFileId . '_' . $filename;
            $mimeType = 'application/' . $fileExtension;
            
            $insertStmt->execute([
                $userId,
                $filename,
                $storedFilename,
                $openaiFileId,
                $fileExtension,
                $bytes,
                $mimeType
            ]);
            
            $newFiles++;
            $newFileId = $pdo->lastInsertId();
            
            $syncedFiles[] = [
                'id' => $newFileId,
                'openai_file_id' => $openaiFileId,
                'filename' => $filename,
                'file_size' => $bytes,
                'status' => 'created'
            ];
        }
    }
    
    json_out([
        'ok' => true,
        'sync_result' => [
            'total_openai_files' => count($openaiFiles),
            'synced_files' => count($syncedFiles),
            'new_files' => $newFiles,
            'updated_files' => $updatedFiles,
            'files' => $syncedFiles
        ],
        'message' => "Sincronización completada: {$newFiles} nuevos, {$updatedFiles} actualizados"
    ]);
    
} catch (Throwable $e) {
    error_log("sync_openai_files_safe.php error: " . $e->getMessage());
    json_out(['ok' => false, 'error' => 'server_error', 'detail' => $e->getMessage()], 500);
}
