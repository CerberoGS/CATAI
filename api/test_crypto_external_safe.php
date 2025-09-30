<?php
/**
 * Test del sistema de cifrado con archivo externo
 * Para pruebas en ia_test.html antes de producción
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Crypto_safe.php';

json_header();

try {
    // 1) Autenticación
    $user = require_user();
    $userId = (int)($user['id'] ?? 0);
    
    if ($userId <= 0) {
        json_out(['error' => 'invalid-user'], 401);
        exit;
    }

    // 2) Test de acceso al keyring
    $keyringPath = $CONFIG['KEYRING_PATH'] ?? '';
    $keyringAccessible = false;
    $keyringError = '';
    
    try {
        $keyringData = @file_get_contents($keyringPath);
        if ($keyringData !== false) {
            $keyringAccessible = true;
            $keyringJson = json_decode($keyringData, true);
        } else {
            $keyringError = 'No se pudo leer el archivo keyring';
        }
    } catch (Exception $e) {
        $keyringError = $e->getMessage();
    }

    // 3) Test de funciones de cifrado
    $cryptoWorking = false;
    $cryptoError = '';
    $testEncrypted = '';
    $testDecrypted = '';
    
    try {
        // Test de cifrado/descifrado
        $testText = 'Test de cifrado con archivo externo - ' . date('Y-m-d H:i:s');
        $testEncrypted = catai_encrypt($testText);
        $testDecrypted = catai_decrypt($testEncrypted);
        $cryptoWorking = ($testDecrypted === $testText);
        
        if (!$cryptoWorking) {
            $cryptoError = 'El texto descifrado no coincide con el original';
        }
    } catch (Exception $e) {
        $cryptoError = $e->getMessage();
    }

    // 4) Test de rotación de claves (si aplica)
    $rotationWorking = false;
    $rotationError = '';
    
    try {
        if ($cryptoWorking && !empty($testEncrypted)) {
            list($pt, $rewrapped, $newBlob) = catai_read_and_maybe_rewrap($testEncrypted);
            $rotationWorking = ($pt === $testText);
            
            if (!$rotationWorking) {
                $rotationError = 'Error en rotación de claves';
            }
        }
    } catch (Exception $e) {
        $rotationError = $e->getMessage();
    }

    // 5) Información del sistema
    $systemInfo = [
        'php_version' => PHP_VERSION,
        'sodium_available' => extension_loaded('sodium'),
        'hash_hkdf_available' => function_exists('hash_hkdf'),
        'keyring_path' => $keyringPath,
        'keyring_accessible' => $keyringAccessible,
        'keyring_error' => $keyringError,
        'crypto_working' => $cryptoWorking,
        'crypto_error' => $cryptoError,
        'rotation_working' => $rotationWorking,
        'rotation_error' => $rotationError
    ];

    // 6) Datos de prueba (sin exponer claves reales)
    $testData = [
        'test_text' => $testText ?? 'No disponible',
        'encrypted_length' => strlen($testEncrypted ?? ''),
        'decryption_success' => $cryptoWorking,
        'keyring_structure' => $keyringAccessible ? [
            'version' => $keyringJson['version'] ?? 'N/A',
            'has_active_kid' => !empty($keyringJson['active_kid'] ?? ''),
            'keys_count' => count($keyringJson['keys'] ?? []),
            'active_kid' => $keyringJson['active_kid'] ?? 'N/A',
            'active_key_status' => $keyringJson['keys'][$keyringJson['active_kid'] ?? '']['status'] ?? 'N/A',
            'active_key_alg' => $keyringJson['keys'][$keyringJson['active_kid'] ?? '']['alg'] ?? 'N/A'
        ] : null
    ];

    json_out([
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'user' => [
            'id' => $userId,
            'email' => $user['email'] ?? 'N/A'
        ],
        'system' => $systemInfo,
        'test_results' => $testData,
        'status' => [
            'keyring' => $keyringAccessible ? '✅ Accesible' : '❌ Error: ' . $keyringError,
            'crypto' => $cryptoWorking ? '✅ Funcionando' : '❌ Error: ' . $cryptoError,
            'rotation' => $rotationWorking ? '✅ Funcionando' : '⚠️ ' . $rotationError
        ]
    ]);

} catch (Exception $e) {
    json_error('Error en test de cifrado: ' . $e->getMessage());
}
