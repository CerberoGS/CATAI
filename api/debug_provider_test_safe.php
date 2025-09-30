<?php
// /catai/api/debug_provider_test_safe.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
  $u = require_user();
  $userId = (int)($u['id'] ?? 0);
  if ($userId <= 0) json_out(['error'=>'invalid-user'], 401);

  $in = json_input(true) ?: [];
  $providerType = $in['provider_type'] ?? 'ai';
  $providerId = (int)($in['provider_id'] ?? 1);
  
  error_log("🔍 debug_provider_test_safe.php - Provider Type: $providerType, Provider ID: $providerId");

  $pdo = db();
  
  // Determinar tabla según tipo
  $tableMap = [
    'data' => 'data_providers',
    'ai' => 'ai_providers', 
    'trade' => 'trade_providers',
    'news' => 'news_providers'
  ];
  
  $providerTable = $tableMap[$providerType] ?? 'ai_providers';
  
  // Obtener información del proveedor
  $stmt = $pdo->prepare("
    SELECT id, slug, name, category, auth_type, base_url, docs_url, 
           rate_limit_per_min, is_enabled, url_request, config_json
    FROM $providerTable 
    WHERE id = ?
  ");
  $stmt->execute([$providerId]);
  $providerRow = $stmt->fetch(PDO::FETCH_ASSOC);
  
  if (!$providerRow) {
    json_out(['error' => 'provider-not-found', 'message' => 'Proveedor no encontrado'], 404);
  }
  
  // Parsear configuración
  $cfg = json_decode($providerRow['config_json'] ?? '{}', true);
  $testConfig = $cfg['test'] ?? [];
  
  // Simular lo que hace testProviderKey
  $url = $testConfig['url_override'] ?? ($providerRow['url_request'] ?? '');
  
  json_out([
    'ok' => true,
    'debug' => [
      'provider_row' => [
        'id' => $providerRow['id'],
        'name' => $providerRow['name'],
        'slug' => $providerRow['slug'],
        'url_request' => $providerRow['url_request'],
        'config_json_raw' => $providerRow['config_json']
      ],
      'parsed_config' => $cfg,
      'test_config' => $testConfig,
      'url_construction' => [
        'url_override' => $testConfig['url_override'] ?? 'NOT_SET',
        'url_request' => $providerRow['url_request'] ?? 'NOT_SET',
        'final_url' => $url,
        'url_empty' => empty($url)
      ],
      'test_config_keys' => array_keys($testConfig),
      'has_url_override' => isset($testConfig['url_override']),
      'has_url_request' => !empty($providerRow['url_request'])
    ]
  ]);
  
} catch (Exception $e) {
  error_log("Error en debug_provider_test_safe.php: " . $e->getMessage());
  json_out(['error' => 'server-error', 'message' => $e->getMessage()], 500);
}
