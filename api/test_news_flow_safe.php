<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
    $u = require_user();
    $userId = (int)($u['id'] ?? 0);
    
    $pdo = db();
    
    // Verificar claves existentes
    $stmt = $pdo->prepare('SELECT id, provider_id, label, last4, status FROM user_news_api_keys WHERE user_id = ? AND status = "active"');
    $stmt->execute([$userId]);
    $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Verificar proveedores disponibles
    $stmt = $pdo->prepare('SELECT id, name, is_enabled FROM news_providers WHERE is_enabled = 1');
    $stmt->execute();
    $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    json_out([
        'ok' => true,
        'user_id' => $userId,
        'existing_keys' => $keys,
        'available_providers' => $providers,
        'keys_count' => count($keys),
        'providers_count' => count($providers)
    ]);
    
} catch (Throwable $e) {
    error_log("Error en test_news_flow_safe.php: " . $e->getMessage());
    json_out(['error' => 'server-error', 'message' => $e->getMessage()], 500);
}
