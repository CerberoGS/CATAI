<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
    $u = require_user();
    $pdo = db();
    
    $results = [];
    
    // Probar cada endpoint
    $endpoints = [
        'get_data_providers_safe.php',
        'get_ai_providers_safe.php', 
        'get_news_providers_safe.php',
        'get_trade_providers_safe.php'
    ];
    
    foreach ($endpoints as $endpoint) {
        try {
            // Simular llamada al endpoint
            ob_start();
            include __DIR__ . '/' . $endpoint;
            $output = ob_get_clean();
            
            $response = json_decode($output, true);
            $results[$endpoint] = [
                'status' => 'ok',
                'providers_count' => count($response['providers'] ?? []),
                'response' => $response
            ];
        } catch (Exception $e) {
            $results[$endpoint] = [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }
    
    // Probar endpoints de claves de usuario
    $keyEndpoints = [
        'get_user_data_keys_safe.php',
        'get_user_ai_keys_safe.php',
        'get_user_news_keys_safe.php', 
        'get_user_trade_keys_safe.php'
    ];
    
    foreach ($keyEndpoints as $endpoint) {
        try {
            ob_start();
            include __DIR__ . '/' . $endpoint;
            $output = ob_get_clean();
            
            $response = json_decode($output, true);
            $results[$endpoint] = [
                'status' => 'ok',
                'keys_count' => count($response['keys'] ?? []),
                'response' => $response
            ];
        } catch (Exception $e) {
            $results[$endpoint] = [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }
    
    json_out([
        'ok' => true,
        'user_id' => $u['id'],
        'test_results' => $results
    ]);
    
} catch (Throwable $e) {
    error_log("Error en test_tables_safe.php: " . $e->getMessage());
    json_out(['error' => 'server-error', 'message' => $e->getMessage()], 500);
}
