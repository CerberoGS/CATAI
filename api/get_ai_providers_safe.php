<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
    $u = require_user();
    
    $pdo = db();
    
    // Obtener proveedores de IA habilitados desde ai_providers (estructura normalizada)
    $stmt = $pdo->query('
        SELECT id, slug, name, category, auth_type, base_url, docs_url, rate_limit_per_min, is_enabled, created_at, url_request, config_json
        FROM ai_providers 
        WHERE is_enabled = 1
        ORDER BY name ASC
    ');
    
    $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Transformar para el frontend (estructura normalizada)
    $formattedProviders = [];
    foreach ($providers as $provider) {
        $formattedProviders[] = [
            'id' => (int)$provider['id'], // Usar el ID real de la DB como 'id'
            'db_id' => (int)$provider['id'], // Mantener db_id para compatibilidad
            'slug' => $provider['slug'],
            'label' => $provider['name'],
            'name' => $provider['name'],
            'category' => $provider['category'] ?? 'ai',
            'auth_type' => $provider['auth_type'] ?? 'api_key',
            'base_url' => $provider['base_url'],
            'docs_url' => $provider['docs_url'],
            'url_request' => $provider['url_request'],
            'config_json' => $provider['config_json'],
            'rate_limit_per_min' => $provider['rate_limit_per_min'] ? (int)$provider['rate_limit_per_min'] : null,
            'description' => $provider['name'] . ' - ' . ucfirst($provider['category'] ?? 'ai')
        ];
    }
    
    json_out([
        'ok' => true,
        'providers' => $formattedProviders,
        'count' => count($formattedProviders)
    ]);
    
} catch (Throwable $e) {
    error_log("Error en get_ai_providers_safe.php: " . $e->getMessage());
    json_out(['error' => 'server-error', 'message' => $e->getMessage()], 500);
}
