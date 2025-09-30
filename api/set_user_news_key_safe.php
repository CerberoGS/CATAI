<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Crypto_safe.php';

json_header();

try {
    $u = require_user();
    $userId = (int)($u['id'] ?? 0);
    
    if ($userId <= 0) {
        json_out(['error'=>'invalid-user'], 401);
    }
    
    $in = json_input(true) ?: [];
    $providerId = (int)($in['provider_id'] ?? 0);
    $apiKey = trim((string)($in['api_key'] ?? ''));
    $label = trim((string)($in['label'] ?? ''));
    $environment = trim((string)($in['environment'] ?? 'live'));
    
    if ($providerId <= 0 || $apiKey === '') {
        json_out(['error' => 'invalid-input', 'message' => 'ID de proveedor y API Key son requeridos'], 400);
    }
    
    $pdo = db();
    
    // Verificar que el proveedor existe y está activo (estructura normalizada)
    $stmt = $pdo->prepare('SELECT id, slug, name FROM news_providers WHERE id = ? AND is_enabled = 1');
    $stmt->execute([$providerId]);
    $provider = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$provider) {
        json_out(['error' => 'provider-not-found', 'message' => 'Proveedor de noticias no encontrado o deshabilitado'], 404);
    }
    
    // Cifrar con el sistema moderno (una sola vez)
    $encryptedKey = catai_encrypt($apiKey);
    $keyFingerprint = hash('sha256', $apiKey);
    $last4 = substr($apiKey, -4);
    
    // Usar INSERT ... ON DUPLICATE KEY UPDATE para mayor eficiencia
    $stmt = $pdo->prepare('INSERT INTO user_news_api_keys
                          (user_id, provider_id, label, origin, api_key_enc, key_ciphertext, key_fingerprint, last4, environment, status, created_at, updated_at)
                          VALUES (?, ?, ?, "byok", ?, ?, ?, ?, ?, "active", NOW(), NOW())
                          ON DUPLICATE KEY UPDATE
                          api_key_enc = VALUES(api_key_enc),
                          key_ciphertext = VALUES(key_ciphertext),
                          key_fingerprint = VALUES(key_fingerprint),
                          last4 = VALUES(last4),
                          label = VALUES(label),
                          environment = VALUES(environment),
                          status = "active",
                          error_count = 0,
                          updated_at = NOW()');
    
    $stmt->execute([$userId, $providerId, $label, $encryptedKey, $encryptedKey, $keyFingerprint, $last4, $environment]);
    
    // Determinar acción basada en affected rows
    $action = ($stmt->rowCount() === 2) ? 'created' : 'updated';
    
    json_out(['ok' => true, 'action' => $action]);
    
} catch (Throwable $e) {
    error_log("Error en set_user_news_key_safe.php: " . $e->getMessage());
    json_out(['error' => 'server-error', 'message' => 'Error interno del servidor'], 500);
}
