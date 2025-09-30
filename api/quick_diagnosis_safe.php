<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
    $u = require_user();
    $pdo = db();
    
    $results = [];
    
    // Verificar tablas de proveedores
    $providerTables = ['data_providers', 'ai_providers', 'news_providers', 'trade_providers'];
    foreach ($providerTables as $table) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
            $count = $stmt->fetch()['count'];
            $results[$table] = ['exists' => true, 'count' => $count];
        } catch (Exception $e) {
            $results[$table] = ['exists' => false, 'error' => $e->getMessage()];
        }
    }
    
    // Verificar tablas de claves de usuario
    $keyTables = ['user_data_api_keys', 'user_ai_api_keys', 'user_news_api_keys', 'user_trade_api_keys'];
    foreach ($keyTables as $table) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
            $count = $stmt->fetch()['count'];
            $results[$table] = ['exists' => true, 'count' => $count];
        } catch (Exception $e) {
            $results[$table] = ['exists' => false, 'error' => $e->getMessage()];
        }
    }
    
    // Verificar claves del usuario actual
    $userId = $u['id'];
    $userKeys = [];
    foreach ($keyTables as $table) {
        if ($results[$table]['exists']) {
            try {
                $stmt = $pdo->prepare("SELECT provider_id, last4, status FROM $table WHERE user_id = ? AND status = 'active'");
                $stmt->execute([$userId]);
                $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $userKeys[$table] = $keys;
            } catch (Exception $e) {
                $userKeys[$table] = ['error' => $e->getMessage()];
            }
        }
    }
    
    json_out([
        'ok' => true,
        'user_id' => $userId,
        'tables' => $results,
        'user_keys' => $userKeys
    ]);
    
} catch (Throwable $e) {
    error_log("Error en quick_diagnosis_safe.php: " . $e->getMessage());
    json_out(['error' => 'server-error', 'message' => $e->getMessage()], 500);
}
