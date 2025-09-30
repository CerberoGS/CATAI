<?php
// /api/test_api_key_decryption_safe.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
    $user = require_user();
    $userId = (int)$user['id'];
    
    if ($userId <= 0) {
        json_error('invalid-user', 401);
    }
    
    $pdo = db();
    
    // 1) Obtener configuración del usuario
    $stmt = $pdo->prepare('SELECT ai_provider, ai_model FROM user_settings WHERE user_id = ?');
    $stmt->execute([$userId]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$settings) {
        json_error('Configuración de usuario no encontrada');
    }
    
    $aiProvider = $settings['ai_provider'] ?? 'auto';
    $aiModel = $settings['ai_model'] ?? 'gpt-4o';
    
    // 2) Probar descifrado de API key
    $apiKey = get_api_key_for($userId, $aiProvider);
    
    // 3) Verificar que la clave se descifró correctamente
    $keyInfo = [
        'user_id' => $userId,
        'provider' => $aiProvider,
        'model' => $aiModel,
        'api_key_available' => !empty($apiKey),
        'api_key_length' => $apiKey ? strlen($apiKey) : 0,
        'api_key_preview' => $apiKey ? substr($apiKey, 0, 8) . '...' . substr($apiKey, -4) : null,
        'api_key_starts_with' => $apiKey ? substr($apiKey, 0, 10) : null
    ];
    
    // 4) Probar con otros proveedores también
    $allProviders = ['openai', 'gemini', 'claude', 'xai', 'deepseek'];
    $providerTests = [];
    
    foreach ($allProviders as $provider) {
        $key = get_api_key_for($userId, $provider);
        $providerTests[$provider] = [
            'available' => !empty($key),
            'length' => $key ? strlen($key) : 0,
            'preview' => $key ? substr($key, 0, 8) . '...' . substr($key, -4) : null
        ];
    }
    
    json_out([
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'user_info' => [
            'id' => $userId,
            'email' => $user['email'] ?? 'N/A'
        ],
        'ai_configuration' => [
            'provider' => $aiProvider,
            'model' => $aiModel
        ],
        'api_key_test' => $keyInfo,
        'all_providers_test' => $providerTests,
        'message' => 'Test de descifrado de API keys completado'
    ]);
    
} catch (Throwable $e) {
    json_error('Test failed: ' . $e->getMessage(), 500);
}
