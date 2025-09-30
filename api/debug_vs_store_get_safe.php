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
    
    // Obtener ops_json del proveedor
    $provStmt = $pdo->prepare("SELECT id, slug, name, ops_json FROM ai_providers WHERE id = ? LIMIT 1");
    $provStmt->execute([$aiProviderId]);
    $providerRow = $provStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$providerRow) {
        json_out([
            'ok' => false,
            'error' => 'provider-not-found',
            'debug' => [
                'user_id' => $userId,
                'ai_provider_id' => $aiProviderId
            ]
        ], 404);
    }
    
    $ops = json_decode($providerRow['ops_json'], true);
    if (!is_array($ops)) {
        json_out([
            'ok' => false,
            'error' => 'ops-json-invalid',
            'debug' => [
                'provider_id' => $providerRow['id'],
                'ops_json_raw' => $providerRow['ops_json']
            ]
        ], 400);
    }
    
    $vsStoreGet = $ops['multi']['vs.store.get'] ?? null;
    
    json_out([
        'ok' => true,
        'provider' => [
            'id' => $providerRow['id'],
            'slug' => $providerRow['slug'],
            'name' => $providerRow['name']
        ],
        'vs_store_get_config' => $vsStoreGet,
        'vs_store_get_exists' => isset($ops['multi']['vs.store.get']),
        'available_operations' => array_keys($ops['multi'] ?? [])
    ]);
    
} catch (Throwable $e) {
    json_out([
        'ok' => false,
        'error' => 'internal-error',
        'detail' => $e->getMessage()
    ], 500);
}
