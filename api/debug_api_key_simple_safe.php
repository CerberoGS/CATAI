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
    
    // Obtener API Key
    $keyStmt = $pdo->prepare("SELECT api_key_enc, last4 FROM user_ai_api_keys WHERE user_id = ? AND provider_id = ? AND (status IS NULL OR status = 'active') ORDER BY id DESC LIMIT 1");
    $keyStmt->execute([$userId, $aiProviderId]);
    $keyRow = $keyStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$keyRow || empty($keyRow['api_key_enc'])) {
        json_out([
            'ok' => false,
            'error' => 'ai-key-missing',
            'message' => 'No hay API key configurada',
            'debug' => [
                'user_id' => $userId,
                'provider_id' => $aiProviderId,
                'query_result' => $keyRow
            ]
        ], 400);
    }
    
    // Desencriptar API key
    $apiKeyPlain = catai_decrypt($keyRow['api_key_enc']);
    
    // Verificar que la API key tiene el formato correcto
    $isValidFormat = (strpos($apiKeyPlain, 'sk-') === 0 && strlen($apiKeyPlain) > 20);
    
    json_out([
        'ok' => true,
        'debug' => [
            'user_id' => $userId,
            'provider_id' => $aiProviderId,
            'last4' => $keyRow['last4'] ?? 'unknown',
            'api_key_length' => strlen($apiKeyPlain),
            'api_key_prefix' => substr($apiKeyPlain, 0, 10),
            'api_key_suffix' => substr($apiKeyPlain, -5),
            'is_valid_format' => $isValidFormat,
            'starts_with_sk' => strpos($apiKeyPlain, 'sk-') === 0
        ]
    ]);
    
} catch (Throwable $e) {
    json_out([
        'ok' => false,
        'error' => 'internal-error',
        'detail' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], 500);
}
