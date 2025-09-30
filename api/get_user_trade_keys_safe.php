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
    
    // Obtener claves de trading del usuario desde user_trade_api_keys (incluyendo el ID y environment)
    $stmt = $pdo->prepare('
        SELECT id, user_id, provider_id, label, last4, status, origin, environment, created_at, updated_at, error_count, last_used_at
        FROM user_trade_api_keys 
        WHERE user_id = ? AND origin = "byok" AND status = "active"
        ORDER BY created_at DESC
    ');
    $stmt->execute([$userId]);
    $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Agregar campos de compatibilidad
    foreach ($keys as &$key) {
        $key['hasKey'] = !empty($key['last4']);
        $key['environment'] = $key['environment'] ?? 'live'; // Leer de BD, fallback a 'live'
        $key['isDefault'] = false; // Por defecto
    }
    
    json_out(['ok' => true, 'keys' => $keys]);
    
} catch (Throwable $e) {
    error_log("Error en get_user_trade_keys_safe.php: " . $e->getMessage());
    json_out(['error' => 'server-error', 'message' => $e->getMessage()], 500);
}
