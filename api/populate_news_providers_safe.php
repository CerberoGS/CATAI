<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
    $u = require_user();
    $pdo = db();
    
    // Insertar proveedores de noticias de ejemplo
    $providers = [
        ['slug' => 'newsapi', 'name' => 'NewsAPI', 'base_url' => 'https://newsapi.org/v2/'],
        ['slug' => 'financialmodelingprep', 'name' => 'Financial Modeling Prep', 'base_url' => 'https://financialmodelingprep.com/api/v3/'],
        ['slug' => 'benzinga', 'name' => 'Benzinga News', 'base_url' => 'https://api.benzinga.com/api/v2/'],
        ['slug' => 'marketwatch', 'name' => 'MarketWatch', 'base_url' => 'https://www.marketwatch.com/api/'],
        ['slug' => 'bloomberg', 'name' => 'Bloomberg API', 'base_url' => 'https://api.bloomberg.com/'],
        ['slug' => 'reuters', 'name' => 'Reuters', 'base_url' => 'https://api.reuters.com/'],
        ['slug' => 'yahoo-finance', 'name' => 'Yahoo Finance News', 'base_url' => 'https://query1.finance.yahoo.com/v1/'],
        ['slug' => 'alpha-vantage-news', 'name' => 'Alpha Vantage News', 'base_url' => 'https://www.alphavantage.co/query']
    ];
    
    $inserted = 0;
    $updated = 0;
    
    foreach ($providers as $provider) {
        // Verificar si ya existe
        $stmt = $pdo->prepare('SELECT id FROM news_providers WHERE slug = ?');
        $stmt->execute([$provider['slug']]);
        
        if ($stmt->rowCount() > 0) {
            // Actualizar si existe
            $stmt = $pdo->prepare('UPDATE news_providers SET name = ?, base_url = ?, status = "enabled" WHERE slug = ?');
            $stmt->execute([$provider['name'], $provider['base_url'], $provider['slug']]);
            $updated++;
        } else {
            // Insertar si no existe
            $stmt = $pdo->prepare('INSERT INTO news_providers (slug, name, status, base_url) VALUES (?, ?, "enabled", ?)');
            $stmt->execute([$provider['slug'], $provider['name'], $provider['base_url']]);
            $inserted++;
        }
    }
    
    json_out([
        'ok' => true,
        'message' => "Proveedores de noticias configurados: $inserted insertados, $updated actualizados",
        'inserted' => $inserted,
        'updated' => $updated,
        'total' => count($providers)
    ]);
    
} catch (Throwable $e) {
    error_log("Error en populate_news_providers_safe.php: " . $e->getMessage());
    json_out(['error' => 'server-error', 'message' => $e->getMessage()], 500);
}
