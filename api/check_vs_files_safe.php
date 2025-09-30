<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Crypto_safe.php';

json_header();

try {
    $user = require_user();
    $userId = $user['user_id'] ?? $user['id'] ?? null;
    
    if (!$userId) {
        json_error('user_id_missing', 400, 'User ID not found in token');
    }
    
    $pdo = db();
    
    // Obtener configuración del usuario
    $settingsStmt = $pdo->prepare('SELECT ai_provider, ai_model FROM user_settings WHERE user_id = ?');
    $settingsStmt->execute([$userId]);
    $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$settings) {
        json_out(['ok' => false, 'error' => 'user-settings-not-found'], 404);
        exit;
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
    
    // Obtener configuración de operaciones del proveedor
    $opsJson = $provider['ops_json'] ? json_decode($provider['ops_json'], true) : null;
    
    // Obtener Vector Stores del usuario
    $vsStmt = $pdo->prepare("
        SELECT * FROM ai_vector_stores 
        WHERE owner_user_id = ? AND status = 'ready'
        ORDER BY created_at DESC
    ");
    $vsStmt->execute([$userId]);
    $vectorStores = $vsStmt->fetchAll();
    
    $vsResults = [];
    
    foreach ($vectorStores as $vs) {
        $vsId = $vs['external_id'];
        
        // Usar vs.files del ops_json para listar archivos del VS
        $vsFilesConfig = $opsJson['multi']['vs.files'] ?? null;
        
        if (!$vsFilesConfig) {
            $vsResults[] = [
                'vs_id' => $vsId,
                'vs_name' => $vs['name'],
                'error' => 'vs_files_config_not_found',
                'detail' => 'vs.files operation not found in ops_json'
            ];
            continue;
        }
        
        // Construir URL usando la configuración del ops_json
        $url = str_replace('{{VS_ID}}', $vsId, $vsFilesConfig['url_override']);
        $url = str_replace('{{limit}}', '100', $url);
        $url = str_replace('{{after_qs}}', '', $url);
        
        // Preparar headers según la configuración
        $headers = [];
        foreach ($vsFilesConfig['headers'] as $header) {
            $value = str_replace('{{API_KEY}}', $apiKey, $header['value']);
            $headers[] = $header['name'] . ': ' . $value;
        }
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        
        if ($error) {
            $vsResults[] = [
                'vs_id' => $vsId,
                'vs_name' => $vs['name'],
                'error' => 'curl_error',
                'detail' => $error
            ];
            continue;
        }
        
        if ($httpCode !== 200) {
            $vsResults[] = [
                'vs_id' => $vsId,
                'vs_name' => $vs['name'],
                'error' => 'api_error',
                'http_code' => $httpCode,
                'response' => $response
            ];
            continue;
        }
        
        $vsData = json_decode($response, true);
        $vsFiles = $vsData['data'] ?? [];
        
        // Comparar con archivos en nuestra DB
        $dbFilesStmt = $pdo->prepare("
            SELECT id, original_filename, openai_file_id, vector_store_id
            FROM knowledge_files 
            WHERE user_id = ? AND vector_store_id = ?
        ");
        $dbFilesStmt->execute([$userId, $vsId]);
        $dbFiles = $dbFilesStmt->fetchAll();
        
        $vsResults[] = [
            'vs_id' => $vsId,
            'vs_name' => $vs['name'],
            'vs_files_count' => count($vsFiles),
            'db_files_count' => count($dbFiles),
            'vs_files' => array_map(function($file) {
                return [
                    'file_id' => $file['id'] ?? null,
                    'filename' => $file['filename'] ?? null,
                    'status' => $file['status'] ?? null,
                    'created_at' => $file['created_at'] ?? null
                ];
            }, $vsFiles),
            'db_files' => array_map(function($file) {
                return [
                    'id' => $file['id'],
                    'filename' => $file['original_filename'],
                    'openai_file_id' => $file['openai_file_id']
                ];
            }, $dbFiles)
        ];
    }
    
    json_out([
        'ok' => true,
        'vs_check_result' => [
            'total_vector_stores' => count($vectorStores),
            'vector_stores' => $vsResults
        ],
        'message' => "Verificación completada para " . count($vectorStores) . " Vector Stores"
    ]);
    
} catch (Throwable $e) {
    error_log("check_vs_files_safe.php error: " . $e->getMessage());
    json_error('Error interno del servidor', 500);
}
