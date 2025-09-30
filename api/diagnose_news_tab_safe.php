<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
    $u = require_user();
    $pdo = db();
    
    // Verificar si la tabla news_providers existe y tiene datos
    $stmt = $pdo->query("SHOW TABLES LIKE 'news_providers'");
    $tableExists = $stmt->rowCount() > 0;
    
    $providersCount = 0;
    $providers = [];
    if ($tableExists) {
        $stmt = $pdo->query('SELECT COUNT(*) as count FROM news_providers WHERE status = "enabled"');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $providersCount = (int)$result['count'];
        
        $stmt = $pdo->query('SELECT id, slug, name, status FROM news_providers ORDER BY name');
        $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Verificar si la tabla user_news_api_keys existe
    $stmt = $pdo->query("SHOW TABLES LIKE 'user_news_api_keys'");
    $keysTableExists = $stmt->rowCount() > 0;
    
    $userKeysCount = 0;
    $userKeys = [];
    if ($keysTableExists) {
        $stmt = $pdo->prepare('SELECT COUNT(*) as count FROM user_news_api_keys WHERE user_id = ? AND status = "active"');
        $stmt->execute([$u['id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $userKeysCount = (int)$result['count'];
        
        $stmt = $pdo->prepare('SELECT id, provider_id, label, last4, status FROM user_news_api_keys WHERE user_id = ? AND status = "active"');
        $stmt->execute([$u['id']]);
        $userKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    json_out([
        'ok' => true,
        'diagnosis' => [
            'user_id' => $u['id'],
            'table_news_providers_exists' => $tableExists,
            'table_user_news_api_keys_exists' => $keysTableExists,
            'providers_count' => $providersCount,
            'providers' => $providers,
            'user_keys_count' => $userKeysCount,
            'user_keys' => $userKeys
        ]
    ]);
    
} catch (Throwable $e) {
    error_log("Error en diagnose_news_tab_safe.php: " . $e->getMessage());
    json_out(['error' => 'server-error', 'message' => $e->getMessage()], 500);
}
