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
    
    // Obtener settings del usuario
    $settingsStmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ? LIMIT 1");
    $settingsStmt->execute([$userId]);
    $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $aiProviderId = (int)($settings['default_provider_id'] ?? 0);
    
    if ($aiProviderId <= 0) {
        json_out([
            'ok' => false,
            'error' => 'ai-provider-not-configured',
            'message' => 'No hay proveedor de IA configurado'
        ], 400);
    }
    
    // Obtener API Key
    $keyStmt = $pdo->prepare("SELECT api_key_enc, last4 FROM user_ai_api_keys WHERE user_id = ? AND provider_id = ? AND (status IS NULL OR status = 'active') ORDER BY id DESC LIMIT 1");
    $keyStmt->execute([$userId, $aiProviderId]);
    $keyRow = $keyStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$keyRow || empty($keyRow['api_key_enc'])) {
        json_out([
            'ok' => false,
            'error' => 'ai-key-missing',
            'message' => 'No hay API key configurada'
        ], 400);
    }
    
    // Desencriptar API key
    try {
        $apiKeyPlain = catai_decrypt($keyRow['api_key_enc']);
        error_log("API Key desencriptada exitosamente, longitud: " . strlen($apiKeyPlain));
    } catch (Throwable $e) {
        error_log("Error desencriptando API key: " . $e->getMessage());
        json_out([
            'ok' => false,
            'error' => 'api-key-decrypt-failed',
            'message' => 'No se pudo desencriptar la API key',
            'detail' => $e->getMessage()
        ], 500);
    }
    
    // Probar la API key con una llamada simple
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.openai.com/v1/models',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $apiKeyPlain"
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FAILONERROR => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    $result = [
        'ok' => true,
        'api_key_info' => [
            'last4' => $keyRow['last4'] ?? 'unknown',
            'length' => strlen($apiKeyPlain),
            'prefix' => substr($apiKeyPlain, 0, 10),
            'suffix' => substr($apiKeyPlain, -5)
        ],
        'test_result' => [
            'http_code' => $httpCode,
            'curl_error' => $error,
            'response_preview' => $response ? substr($response, 0, 200) : 'No response'
        ]
    ];
    
    error_log("Test API Key result: " . json_encode($result));
    json_out($result);
    
} catch (Throwable $e) {
    json_out([
        'ok' => false,
        'error' => 'internal-error',
        'detail' => $e->getMessage()
    ], 500);
}
