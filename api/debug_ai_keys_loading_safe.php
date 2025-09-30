<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
    $u = require_user();
    $userId = (int)($u['id'] ?? 0);
    
    if ($userId <= 0) {
        json_out(['error'=>'invalid-user'], 401);
    }
    
    $pdo = db();
    $debug = [];
    
    // Debug 1: Verificar claves de usuario
    $stmt = $pdo->prepare('
        SELECT id, user_id, provider_id, label, last4, status, environment, origin, created_at, updated_at, error_count, last_used_at
        FROM user_ai_api_keys 
        WHERE user_id = ? AND origin = "byok" AND status = "active"
        ORDER BY created_at DESC
    ');
    $stmt->execute([$userId]);
    $userKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $debug['user_keys_raw'] = $userKeys;
    $debug['user_keys_count'] = count($userKeys);
    
    // Debug 2: Verificar proveedores disponibles
    $stmt = $pdo->prepare('SELECT id, slug, name, category, auth_type, base_url, docs_url, rate_limit_per_min, is_enabled FROM ai_providers WHERE is_enabled = 1 ORDER BY name ASC');
    $stmt->execute();
    $availableProviders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $debug['available_providers_raw'] = $availableProviders;
    $debug['available_providers_count'] = count($availableProviders);
    
    // Debug 3: Verificar proveedores específicos de las claves
    $providers = [];
    if (!empty($userKeys)) {
        $providerIds = array_column($userKeys, 'provider_id');
        if (!empty($providerIds)) {
            $placeholders = str_repeat('?,', count($providerIds) - 1) . '?';
            
            $stmt = $pdo->prepare("
                SELECT id, slug, name, category, auth_type, base_url, docs_url, rate_limit_per_min, is_enabled
                FROM ai_providers 
                WHERE id IN ($placeholders)
            ");
            $stmt->execute($providerIds);
            $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    
    $debug['key_providers_raw'] = $providers;
    $debug['key_providers_count'] = count($providers);
    
    // Debug 4: Simular la combinación de datos
    $keys = [];
    foreach ($userKeys as $key) {
        $provider = array_filter($providers, fn($p) => $p['id'] == $key['provider_id']);
        $provider = reset($provider);
        
        $keys[] = [
            'id' => (int)$key['id'],
            'provider_id' => (int)$key['provider_id'],
            'label' => $key['label'],
            'last4' => $key['last4'],
            'status' => $key['status'],
            'environment' => $key['environment'] ?? 'live',
            'origin' => $key['origin'],
            'error_count' => (int)$key['error_count'],
            'last_used_at' => $key['last_used_at'],
            'created_at' => $key['created_at'],
            'updated_at' => $key['updated_at'],
            'name' => $provider['name'] ?? 'Proveedor desconocido',
            'slug' => $provider['slug'] ?? '',
            'category' => $provider['category'] ?? 'ai',
            'auth_type' => $provider['auth_type'] ?? 'api_key',
            'base_url' => $provider['base_url'] ?? '',
            'provider_found' => !empty($provider),
            'provider_data' => $provider
        ];
    }
    
    $debug['final_keys'] = $keys;
    $debug['final_keys_count'] = count($keys);
    
    json_out([
        'ok' => true,
        'debug' => $debug,
        'summary' => [
            'user_keys' => count($userKeys),
            'available_providers' => count($availableProviders),
            'key_providers' => count($providers),
            'final_keys' => count($keys)
        ]
    ]);
    
} catch (Throwable $e) {
    error_log("Error en debug_ai_keys_loading_safe.php: " . $e->getMessage());
    json_out(['error' => 'server-error', 'message' => $e->getMessage()], 500);
}
