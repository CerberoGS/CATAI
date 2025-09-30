<?php
// /catai/api/get_user_providers_with_keys_safe.php
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
  
  error_log("🔍 get_user_providers_with_keys_safe.php - User ID: $userId, Provider Type: '$providerType'");
  error_log("🔍 Input recibido: " . json_encode($in));
  error_log("🔍 Raw input: " . file_get_contents('php://input'));
  
  if (empty($providerType)) {
    json_out(['error' => 'invalid-params', 'message' => 'provider_type es requerido'], 400);
  }

  $pdo = db();
  
  // Mapeo de tablas según tipo
  $tableMap = [
    'data' => ['providers' => 'data_providers', 'user_keys' => 'user_data_api_keys'],
    'ai' => ['providers' => 'ai_providers', 'user_keys' => 'user_ai_api_keys'],
    'trade' => ['providers' => 'trade_providers', 'user_keys' => 'user_trade_api_keys'],
    'news' => ['providers' => 'news_providers', 'user_keys' => 'user_news_api_keys']
  ];
  
  if (!isset($tableMap[$providerType])) {
    json_out(['error' => 'invalid-provider-type', 'message' => 'Tipo de proveedor no válido'], 400);
  }
  
  $providerTable = $tableMap[$providerType]['providers'];
  $userTable = $tableMap[$providerType]['user_keys'];
  
  error_log("📊 Tablas: $providerTable -> $userTable");
  
  // Obtener claves del usuario (mismo enfoque que endpoints individuales)
  $stmt = $pdo->prepare("
    SELECT id, user_id, provider_id, label, last4, status, environment, origin, created_at, updated_at, error_count, last_used_at
    FROM $userTable 
    WHERE user_id = ? AND origin = 'byok' AND status = 'active'
    ORDER BY created_at DESC
  ");
  $stmt->execute([$userId]);
  $userKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
  
  // Obtener información de los proveedores
  $providers = [];
  $results = [];
  if (!empty($userKeys)) {
    $providerIds = array_column($userKeys, 'provider_id');
    if (!empty($providerIds)) {
      $placeholders = str_repeat('?,', count($providerIds) - 1) . '?';
      
      $stmt = $pdo->prepare("
        SELECT id, slug, name, category, auth_type, base_url, docs_url, rate_limit_per_min, is_enabled, url_request, config_json
        FROM $providerTable 
        WHERE id IN ($placeholders) AND is_enabled = 1
      ");
      $stmt->execute($providerIds);
      $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Combinar datos de claves con información de proveedores
    foreach ($userKeys as $key) {
      $provider = array_filter($providers, fn($p) => $p['id'] == $key['provider_id']);
      $provider = reset($provider);
      
      if ($provider) {
        $results[] = [
          'id' => (int)$provider['id'],
          'slug' => $provider['slug'],
          'name' => $provider['name'],
          'category' => $provider['category'],
          'auth_type' => $provider['auth_type'],
          'base_url' => $provider['base_url'],
          'docs_url' => $provider['docs_url'],
          'rate_limit_per_min' => $provider['rate_limit_per_min'] ? (int)$provider['rate_limit_per_min'] : null,
          'url_request' => $provider['url_request'],
          'config_json' => $provider['config_json'],
          
          // Información de la clave del usuario
          'user_key_id' => (int)$key['id'],
          'user_key_label' => $key['label'],
          'last4' => $key['last4'],
          'environment' => $key['environment'] ?? 'live',
          'key_status' => $key['status'],
          'key_created_at' => $key['created_at'],
          'last_used_at' => $key['last_used_at'],
          
          // Información para la prueba
          'has_test_config' => !empty($provider['config_json']) && 
                              json_decode($provider['config_json'], true)['test'] ?? false
        ];
      }
    }
  }
  
  error_log("🔍 Proveedores con claves encontrados: " . count($results));
  
  // Los resultados ya están formateados arriba
  $providers = $results;
  
  json_out([
    'ok' => true,
    'provider_type' => $providerType,
    'providers' => $providers,
    'count' => count($providers),
    'message' => count($providers) > 0 ? 
      "Se encontraron " . count($providers) . " proveedores con claves configuradas" :
      "No tienes claves configuradas para este tipo de proveedor"
  ]);
  
} catch (Exception $e) {
  error_log("❌ Error en get_user_providers_with_keys_safe.php: " . $e->getMessage());
  error_log("❌ Stack trace: " . $e->getTraceAsString());
  json_out(['error' => 'server-error', 'message' => 'Error interno del servidor: ' . $e->getMessage()], 500);
}
