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
    
    // Debug: Verificar valores de ambiente en todas las tablas de claves
    $tables = ['user_data_api_keys', 'user_ai_api_keys', 'user_news_api_keys', 'user_trade_api_keys'];
    $results = [];
    
    foreach ($tables as $table) {
        $stmt = $pdo->prepare("
            SELECT id, provider_id, label, environment, status, created_at, updated_at
            FROM $table 
            WHERE user_id = ? AND status = 'active'
            ORDER BY created_at DESC
        ");
        $stmt->execute([$userId]);
        $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $results[$table] = [
            'count' => count($keys),
            'keys' => $keys
        ];
    }
    
    // Debug: Verificar estructura de columnas
    $columnInfo = [];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW COLUMNS FROM $table LIKE 'environment'");
        $columnInfo[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    json_out([
        'ok' => true,
        'user_id' => $userId,
        'environment_data' => $results,
        'column_structure' => $columnInfo,
        'summary' => [
            'total_keys' => array_sum(array_column($results, 'count')),
            'tables_checked' => count($tables)
        ]
    ]);
    
} catch (Throwable $e) {
    error_log("Error en debug_environment_values_safe.php: " . $e->getMessage());
    json_out(['error' => 'server-error', 'message' => $e->getMessage()], 500);
}
