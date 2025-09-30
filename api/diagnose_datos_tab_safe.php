<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
    $u = require_user();
    
    $diagnosis = [];
    
    // 1. Verificar si existen claves en user_data_api_keys
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COUNT(*) as count FROM user_data_api_keys WHERE user_id = ? AND status = "active"');
    $stmt->execute([$u['id']]);
    $keyCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    $diagnosis['user_keys_count'] = $keyCount;
    
    // 2. Obtener claves del usuario
    $stmt = $pdo->prepare('
        SELECT udak.*, dp.label as provider_name 
        FROM user_data_api_keys udak 
        LEFT JOIN data_providers dp ON udak.provider_id = dp.id 
        WHERE udak.user_id = ? AND udak.status = "active"
    ');
    $stmt->execute([$u['id']]);
    $userKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $diagnosis['user_keys'] = $userKeys;
    
    // 3. Verificar proveedores disponibles
    $stmt = $pdo->query('SELECT id, slug, label FROM data_providers WHERE is_enabled = 1');
    $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $diagnosis['available_providers'] = $providers;
    
    // 4. Verificar endpoint get_user_data_keys_safe.php
    $diagnosis['endpoint_test'] = 'get_user_data_keys_safe.php exists: ' . (file_exists(__DIR__ . '/get_user_data_keys_safe.php') ? 'YES' : 'NO');
    
    json_out(['ok' => true, 'diagnosis' => $diagnosis]);
    
} catch (Throwable $e) {
    error_log("Error en diagnose_datos_tab_safe.php: " . $e->getMessage());
    json_out(['error' => 'server-error', 'message' => $e->getMessage()], 500);
}
