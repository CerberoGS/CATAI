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
    
    $pdo = db();
    $results = [];
    
    // Test 1: Verificar estructura de tabla user_ai_api_keys
    $start = microtime(true);
    $stmt = $pdo->query("SHOW COLUMNS FROM user_ai_api_keys");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $results['table_structure'] = [
        'time' => round((microtime(true) - $start) * 1000, 2) . 'ms',
        'columns' => count($columns),
        'has_environment' => in_array('environment', array_column($columns, 'Field'))
    ];
    
    // Test 2: Verificar índices
    $start = microtime(true);
    $stmt = $pdo->query("SHOW INDEX FROM user_ai_api_keys");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $results['indexes'] = [
        'time' => round((microtime(true) - $start) * 1000, 2) . 'ms',
        'count' => count($indexes),
        'has_unique_key' => count(array_filter($indexes, fn($i) => $i['Non_unique'] == 0)) > 0
    ];
    
    // Test 3: Verificar claves existentes
    $start = microtime(true);
    $stmt = $pdo->prepare('SELECT COUNT(*) as count FROM user_ai_api_keys WHERE user_id = ? AND status = "active"');
    $stmt->execute([$userId]);
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    $results['user_keys'] = [
        'time' => round((microtime(true) - $start) * 1000, 2) . 'ms',
        'count' => (int)$count['count']
    ];
    
    // Test 4: Verificar proveedores disponibles
    $start = microtime(true);
    $stmt = $pdo->prepare('SELECT COUNT(*) as count FROM ai_providers WHERE is_enabled = 1');
    $stmt->execute();
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    $results['available_providers'] = [
        'time' => round((microtime(true) - $start) * 1000, 2) . 'ms',
        'count' => (int)$count['count']
    ];
    
    // Test 5: Simular cifrado (sin guardar)
    $start = microtime(true);
    $testKey = 'test_key_' . time();
    $encryptedKey = catai_encrypt($testKey);
    $results['encryption'] = [
        'time' => round((microtime(true) - $start) * 1000, 2) . 'ms',
        'success' => !empty($encryptedKey)
    ];
    
    // Test 6: Verificar consulta de inserción (preparar statement)
    $start = microtime(true);
    $stmt = $pdo->prepare('INSERT INTO user_ai_api_keys
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
    $results['query_preparation'] = [
        'time' => round((microtime(true) - $start) * 1000, 2) . 'ms',
        'success' => $stmt !== false
    ];
    
    json_out([
        'ok' => true,
        'performance_tests' => $results,
        'total_time' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2) . 'ms'
    ]);
    
} catch (Throwable $e) {
    error_log("Error en test_ai_performance_safe.php: " . $e->getMessage());
    json_out(['error' => 'server-error', 'message' => $e->getMessage()], 500);
}
