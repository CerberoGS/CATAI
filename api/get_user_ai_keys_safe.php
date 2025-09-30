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
    
    // Obtener claves de IA del usuario desde user_ai_api_keys (estructura normalizada)
    $stmt = $pdo->prepare('
        SELECT id, user_id, provider_id, label, last4, status, environment, origin, created_at, updated_at, error_count, last_used_at
        FROM user_ai_api_keys 
        WHERE user_id = ? AND origin = "byok" AND status = "active"
        ORDER BY created_at DESC
    ');
    $stmt->execute([$userId]);
    $userKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener información de los proveedores
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
    
    // Combinar datos de claves con información de proveedores
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
            'base_url' => $provider['base_url'] ?? ''
        ];
    }
    
    json_out(['ok' => true, 'keys' => $keys]);
    
} catch (Throwable $e) {
    error_log("Error en get_user_ai_keys_safe.php: " . $e->getMessage());
    json_out(['error' => 'server-error', 'message' => $e->getMessage()], 500);
}
