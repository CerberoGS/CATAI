<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

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
    
    // Obtener proveedor
    $provStmt = $pdo->prepare("SELECT id, slug, name, ops_json FROM ai_providers WHERE id = ? LIMIT 1");
    $provStmt->execute([$aiProviderId]);
    $providerRow = $provStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$providerRow) {
        json_out([
            'ok' => false,
            'error' => 'provider-not-found',
            'ai_provider_id' => $aiProviderId
        ], 400);
    }
    
    $ops = json_decode($providerRow['ops_json'], true);
    
    json_out([
        'ok' => true,
        'provider' => [
            'id' => $providerRow['id'],
            'slug' => $providerRow['slug'],
            'name' => $providerRow['name']
        ],
        'ops_json' => $ops,
        'vs_get_config' => $ops['multi']['vs.get'] ?? null,
        'vs_files_config' => $ops['multi']['vs.files'] ?? null
    ]);
    
} catch (Throwable $e) {
    json_out([
        'ok' => false,
        'error' => 'internal-error',
        'detail' => $e->getMessage()
    ], 500);
}
