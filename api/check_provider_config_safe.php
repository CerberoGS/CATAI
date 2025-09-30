<?php
// /catai/api/check_provider_config_safe.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
  $u = require_user();
  $userId = (int)($u['id'] ?? 0);
  if ($userId <= 0) json_out(['error'=>'invalid-user'], 401);

  $in = json_input(true) ?: [];
  $providerType = $in['provider_type'] ?? '';
  $providerId = (int)($in['provider_id'] ?? 0);
  
  error_log("🔍 check_provider_config_safe.php - Provider Type: $providerType, Provider ID: $providerId");
  
  if (empty($providerType) || $providerId <= 0) {
    json_out(['error' => 'invalid-params', 'message' => 'provider_type y provider_id son requeridos'], 400);
  }

  $pdo = db();
  
  // Determinar tabla según tipo
  $tableMap = [
    'data' => 'data_providers',
    'ai' => 'ai_providers', 
    'trade' => 'trade_providers',
    'news' => 'news_providers'
  ];
  
  if (!isset($tableMap[$providerType])) {
    json_out(['error' => 'invalid-provider-type', 'message' => 'Tipo de proveedor no válido'], 400);
  }
  
  $providerTable = $tableMap[$providerType];
  
  // Obtener configuración del proveedor
  $stmt = $pdo->prepare("
    SELECT id, slug, name, url_request, config_json, base_url, auth_type
    FROM $providerTable 
    WHERE id = ?
  ");
  
  $stmt->execute([$providerId]);
  $provider = $stmt->fetch(PDO::FETCH_ASSOC);
  
  if (!$provider) {
    json_out(['error' => 'provider-not-found', 'message' => 'Proveedor no encontrado'], 404);
  }
  
  $config = json_decode($provider['config_json'] ?? '{}', true);
  $testConfig = $config['test'] ?? [];
  
  error_log("🔍 Proveedor encontrado: " . $provider['name']);
  error_log("🔍 URL Request: " . ($provider['url_request'] ?? 'NO CONFIGURADA'));
  error_log("🔍 Base URL: " . ($provider['base_url'] ?? 'NO CONFIGURADA'));
  error_log("🔍 Auth Type: " . ($provider['auth_type'] ?? 'NO CONFIGURADA'));
  error_log("🔍 Config JSON: " . json_encode($config));
  error_log("🔍 Test Config: " . json_encode($testConfig));
  
  json_out([
    'ok' => true,
    'provider' => [
      'id' => $provider['id'],
      'slug' => $provider['slug'],
      'name' => $provider['name'],
      'url_request' => $provider['url_request'],
      'base_url' => $provider['base_url'],
      'auth_type' => $provider['auth_type']
    ],
    'config_json' => $config,
    'test_config' => $testConfig,
    'has_test_config' => !empty($testConfig),
    'has_url_request' => !empty($provider['url_request']),
    'debug' => [
      'provider_table' => $providerTable,
      'config_keys' => array_keys($config),
      'test_config_keys' => array_keys($testConfig)
    ]
  ]);
  
} catch (Exception $e) {
  error_log("Error en check_provider_config_safe.php: " . $e->getMessage());
  json_out(['error' => 'server-error', 'message' => $e->getMessage()], 500);
}
