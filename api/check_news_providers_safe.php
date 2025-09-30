<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
    $u = require_user();
    $pdo = db();
    
    // Verificar si la tabla existe
    $stmt = $pdo->query("SHOW TABLES LIKE 'news_providers'");
    $tableExists = $stmt->rowCount() > 0;
    
    if (!$tableExists) {
        json_out([
            'ok' => false, 
            'message' => 'Tabla news_providers no existe',
            'table_exists' => false
        ]);
        return;
    }
    
    // Contar total de registros
    $stmt = $pdo->query('SELECT COUNT(*) as total FROM news_providers');
    $totalCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Contar registros habilitados
    $stmt = $pdo->query('SELECT COUNT(*) as enabled FROM news_providers WHERE status = "enabled"');
    $enabledCount = $stmt->fetch(PDO::FETCH_ASSOC)['enabled'];
    
    // Obtener todos los registros
    $stmt = $pdo->query('SELECT id, slug, name, status FROM news_providers ORDER BY name');
    $allProviders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener solo los habilitados (como hace el endpoint real)
    $stmt = $pdo->query('SELECT id, slug, name, status FROM news_providers WHERE status = "enabled" ORDER BY name');
    $enabledProviders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    json_out([
        'ok' => true,
        'table_exists' => true,
        'total_count' => (int)$totalCount,
        'enabled_count' => (int)$enabledCount,
        'all_providers' => $allProviders,
        'enabled_providers' => $enabledProviders
    ]);
    
} catch (Throwable $e) {
    error_log("Error en check_news_providers_safe.php: " . $e->getMessage());
    json_out(['error' => 'server-error', 'message' => $e->getMessage()], 500);
}
