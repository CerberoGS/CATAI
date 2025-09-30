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
    
    error_log("Settings: " . json_encode($settings));
    
    if (!$settings) {
        error_log("ERROR: User settings not found");
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
    
    error_log("Provider: " . json_encode($provider));
    
    if (!$provider || empty($provider['api_key_enc'])) {
        error_log("ERROR: AI provider not found or no API key");
        json_out(['ok' => false, 'error' => 'ai-provider-not-found'], 404);
        exit;
    }
    
    // Desencriptar API key
    $apiKey = catai_decrypt($provider['api_key_enc']);
    
    // Obtener Vector Store del usuario
    $vsStmt = $pdo->prepare("
        SELECT * FROM ai_vector_stores 
        WHERE owner_user_id = ? AND status = 'ready'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $vsStmt->execute([$userId]);
    $vs = $vsStmt->fetch();
    
    if (!$vs) {
        json_out(['ok' => false, 'error' => 'no-vector-store-found'], 404);
        exit;
    }
    
    $vsId = $vs['external_id'];
    
    // Probar conectividad básica con Vector Store
    $testUrl = $provider['base_url'] . '/v1/vector_stores/' . $vsId;
    
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $testUrl,
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
        json_out([
            'ok' => false,
            'error' => 'curl_error',
            'detail' => $error,
            'vs_id' => $vsId,
            'test_url' => $testUrl
        ], 500);
        exit;
    }
    
    if ($httpCode !== 200) {
        json_out([
            'ok' => false,
            'error' => 'vs_access_error',
            'http_code' => $httpCode,
            'response' => $response,
            'vs_id' => $vsId,
            'test_url' => $testUrl
        ], $httpCode);
        exit;
    }
    
    $vsData = json_decode($response, true);
    
    error_log("=== TEST VS CONNECTIVITY SUCCESS ===");
    error_log("VS ID: $vsId, Status: " . ($vsData['status'] ?? 'unknown'));
    
    json_out([
        'ok' => true,
        'vs_info' => [
            'vs_id' => $vsId,
            'vs_name' => $vs['name'],
            'local_id' => $vs['id'],
            'status' => $vsData['status'] ?? 'unknown',
            'file_counts' => $vsData['file_counts'] ?? null,
            'bytes_used' => $vsData['bytes_used'] ?? null
        ],
        'message' => 'Conectividad con Vector Store exitosa'
    ]);
    
} catch (Throwable $e) {
    error_log("test_vs_connectivity_safe.php error: " . $e->getMessage());
    json_error('Error interno del servidor', 500);
}
