<?php
// /catai/api/get_data_providers_safe.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
  $u = require_user();
  $userId = (int)($u['id'] ?? 0);
  if ($userId <= 0) json_out(['error'=>'invalid-user'], 401);

  $pdo = db();
  
  // Obtener todos los proveedores de datos habilitados
  $stmt = $pdo->prepare('
    SELECT id, slug, label, category, auth_type, base_url, docs_url, rate_limit_per_min, is_enabled, created_at, url_request, config_json
    FROM data_providers 
    WHERE is_enabled = 1 
    ORDER BY label ASC
  ');
  $stmt->execute();
  $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
  
  // Transformar para el frontend
  $formattedProviders = [];
  foreach ($providers as $provider) {
    $formattedProviders[] = [
      'id' => (int)$provider['id'], // Usar el ID real de la DB como 'id'
      'db_id' => (int)$provider['id'], // Mantener db_id para compatibilidad
      'slug' => $provider['slug'],
      'label' => $provider['label'],
      'name' => $provider['label'],
      'category' => $provider['category'],
      'auth_type' => $provider['auth_type'],
      'base_url' => $provider['base_url'],
      'docs_url' => $provider['docs_url'],
      'url_request' => $provider['url_request'],
      'config_json' => $provider['config_json'],
      'rate_limit_per_min' => $provider['rate_limit_per_min'] ? (int)$provider['rate_limit_per_min'] : null,
      'description' => $provider['label'] . ' - ' . ucfirst($provider['category'])
    ];
  }
  
  json_out([
    'ok' => true,
    'providers' => $formattedProviders,
    'count' => count($formattedProviders)
  ]);
  
} catch (Exception $e) {
  error_log("Error en get_data_providers_safe.php: " . $e->getMessage());
  json_out(['error' => 'server-error', 'message' => 'Error interno del servidor'], 500);
}
