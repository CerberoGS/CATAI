<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
    $u = require_user();
    $pdo = db();
    
    $diagnosis = [];
    
    // Verificar tablas de proveedores
    $tables = [
        'data_providers' => 'get_data_providers_safe.php',
        'ai_providers' => 'get_ai_providers_safe.php', 
        'news_providers' => 'get_news_providers_safe.php',
        'trade_providers' => 'get_trade_providers_safe.php'
    ];
    
    foreach ($tables as $table => $endpoint) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            $exists = $stmt->fetch() !== false;
            
            if ($exists) {
                $countStmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
                $count = $countStmt->fetch()['count'];
                $diagnosis[$table] = ['exists' => true, 'count' => $count, 'status' => 'ok'];
            } else {
                $diagnosis[$table] = ['exists' => false, 'count' => 0, 'status' => 'missing'];
            }
        } catch (Exception $e) {
            $diagnosis[$table] = ['exists' => false, 'count' => 0, 'status' => 'error', 'error' => $e->getMessage()];
        }
    }
    
    // Verificar tablas de claves de usuario
    $keyTables = [
        'user_data_api_keys' => 'get_user_data_keys_safe.php',
        'user_ai_api_keys' => 'get_user_ai_keys_safe.php',
        'user_news_api_keys' => 'get_user_news_keys_safe.php', 
        'user_trade_api_keys' => 'get_user_trade_keys_safe.php'
    ];
    
    foreach ($keyTables as $table => $endpoint) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            $exists = $stmt->fetch() !== false;
            
            if ($exists) {
                $countStmt = $pdo->query("SELECT COUNT(*) as count FROM $table WHERE user_id = ? AND status = 'active'");
                $countStmt->execute([$u['id']]);
                $count = $countStmt->fetch()['count'];
                $diagnosis[$table] = ['exists' => true, 'count' => $count, 'status' => 'ok'];
            } else {
                $diagnosis[$table] = ['exists' => false, 'count' => 0, 'status' => 'missing'];
            }
        } catch (Exception $e) {
            $diagnosis[$table] = ['exists' => false, 'count' => 0, 'status' => 'error', 'error' => $e->getMessage()];
        }
    }
    
    // Verificar endpoints
    $endpoints = [
        'get_data_providers_safe.php',
        'get_ai_providers_safe.php',
        'get_news_providers_safe.php',
        'get_trade_providers_safe.php',
        'get_user_data_keys_safe.php',
        'get_user_ai_keys_safe.php',
        'get_user_news_keys_safe.php',
        'get_user_trade_keys_safe.php'
    ];
    
    $endpointStatus = [];
    foreach ($endpoints as $endpoint) {
        $endpointStatus[$endpoint] = file_exists(__DIR__ . '/' . $endpoint);
    }
    
    json_out([
        'ok' => true,
        'user_id' => $u['id'],
        'tables' => $diagnosis,
        'endpoints' => $endpointStatus,
        'recommendations' => [
            'missing_tables' => array_keys(array_filter($diagnosis, fn($t) => $t['status'] === 'missing')),
            'missing_endpoints' => array_keys(array_filter($endpointStatus, fn($e) => !$e))
        ]
    ]);
    
} catch (Throwable $e) {
    error_log("Error en diagnose_tables_safe.php: " . $e->getMessage());
    json_out(['error' => 'server-error', 'message' => $e->getMessage()], 500);
}
