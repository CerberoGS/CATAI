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
    
    $pdo = db();
    
    // Obtener settings del usuario
    $settingsStmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ? LIMIT 1");
    $settingsStmt->execute([$userId]);
    $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $aiProviderId = (int)($settings['default_provider_id'] ?? 0);
    
    // Obtener API Key
    $keyStmt = $pdo->prepare("SELECT api_key_enc FROM user_ai_api_keys WHERE user_id = ? AND provider_id = ? AND (status IS NULL OR status = 'active') ORDER BY id DESC LIMIT 1");
    $keyStmt->execute([$userId, $aiProviderId]);
    $keyRow = $keyStmt->fetch(PDO::FETCH_ASSOC);
    
    $apiKeyPlain = catai_decrypt($keyRow['api_key_enc']);
    
    // Obtener ops_json del proveedor
    $provStmt = $pdo->prepare("SELECT id, slug, name, ops_json FROM ai_providers WHERE id = ? LIMIT 1");
    $provStmt->execute([$aiProviderId]);
    $providerRow = $provStmt->fetch(PDO::FETCH_ASSOC);
    
    $ops = json_decode($providerRow['ops_json'], true);
    $assistantCreate = $ops['multi']['assistant.create'];
    
    // Simular exactamente lo que hace executeSimpleOperationAI
    $params = [
        'VS_ID' => 'test_vs_id',
        'API_KEY' => $apiKeyPlain
    ];
    
    $headers = [];
    if (isset($assistantCreate['headers'])) {
        foreach ($assistantCreate['headers'] as $header) {
            $headerName = $header['name'];
            $originalValue = $header['value'];
            
            // Función replaceVariables
            $headerValue = $originalValue;
            foreach ($params as $key => $value) {
                $headerValue = str_replace("{{$key}}", $value, $headerValue);
            }
            
            $headers[] = "$headerName: $headerValue";
        }
    }
    
    json_out([
        'ok' => true,
        'debug' => [
            'api_key_length' => strlen($apiKeyPlain),
            'api_key_prefix' => substr($apiKeyPlain, 0, 15),
            'api_key_suffix' => substr($apiKeyPlain, -10),
            'headers_original' => $assistantCreate['headers'],
            'headers_processed' => $headers,
            'params' => array_keys($params)
        ]
    ]);
    
} catch (Throwable $e) {
    json_out([
        'ok' => false,
        'error' => 'internal-error',
        'detail' => $e->getMessage()
    ], 500);
}
