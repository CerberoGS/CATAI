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
        return;
    }
    
    // Datos de prueba - usar un ID que existe en data_providers
    $providerId = 6; // Alpha Vantage (según los logs)
    $apiKey = 'test_key_12345';
    $label = 'Test Key';
    $environment = 'test';
    
    error_log("=== TEST SAVE DATOS KEY ===");
    error_log("User ID: $userId");
    error_log("Provider ID: $providerId");
    error_log("API Key: $apiKey");
    
    $pdo = db();
    
    // Verificar que el proveedor existe
    $stmt = $pdo->prepare('SELECT id, slug, label FROM data_providers WHERE id = ? AND is_enabled = 1');
    $stmt->execute([$providerId]);
    $provider = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$provider) {
        error_log("ERROR: Proveedor no encontrado");
        json_out(['error' => 'provider-not-found', 'message' => 'Proveedor no encontrado'], 404);
        return;
    }
    
    error_log("Proveedor encontrado: " . json_encode($provider));
    
    // Cifrar la clave
    $encryptedKey = catai_encrypt($apiKey);
    $keyFingerprint = hash('sha256', $apiKey);
    $last4 = substr($apiKey, -4);
    
    error_log("Clave cifrada exitosamente");
    error_log("Last4: $last4");
    
    // Verificar si ya existe
    $checkStmt = $pdo->prepare('SELECT id FROM user_data_api_keys WHERE user_id = ? AND provider_id = ? AND origin = "byok"');
    $checkStmt->execute([$userId, $providerId]);
    $existing = $checkStmt->fetch();
    
    if ($existing) {
        error_log("Actualizando clave existente");
        $updateStmt = $pdo->prepare('UPDATE user_data_api_keys
                                    SET api_key_enc = ?, key_ciphertext = ?, key_fingerprint = ?, last4 = ?, label = ?, environment = ?, status = "active", error_count = 0, updated_at = NOW()
                                    WHERE user_id = ? AND provider_id = ? AND origin = "byok"');
        $updateStmt->execute([$encryptedKey, $encryptedKey, $keyFingerprint, $last4, $label, $environment, $userId, $providerId]);
        $result = 'updated';
        error_log("Clave actualizada exitosamente");
    } else {
        error_log("Insertando nueva clave");
        $insertStmt = $pdo->prepare('INSERT INTO user_data_api_keys
                                    (user_id, provider_id, label, origin, api_key_enc, key_ciphertext, key_fingerprint, last4, environment, status, created_at, updated_at)
                                    VALUES (?, ?, ?, "byok", ?, ?, ?, ?, ?, "active", NOW(), NOW())');
        $insertStmt->execute([$userId, $providerId, $label, $encryptedKey, $encryptedKey, $keyFingerprint, $last4, $environment]);
        $result = 'created';
        error_log("Clave insertada exitosamente");
    }
    
    // Verificar que se guardó
    $verifyStmt = $pdo->prepare('SELECT id, provider_id, last4, status FROM user_data_api_keys WHERE user_id = ? AND provider_id = ?');
    $verifyStmt->execute([$userId, $providerId]);
    $saved = $verifyStmt->fetch(PDO::FETCH_ASSOC);
    
    error_log("Verificación - Clave guardada: " . json_encode($saved));
    
    json_out([
        'ok' => true, 
        'action' => $result,
        'test' => true,
        'provider' => $provider,
        'saved_key' => $saved
    ]);
    
} catch (Throwable $e) {
    error_log("ERROR en test_save_datos_key_safe.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    json_out(['error' => 'server-error', 'message' => $e->getMessage()], 500);
}
