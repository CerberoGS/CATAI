<?php
// /catai/api/debug_get_user_providers_with_keys_safe.php
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
  
  error_log("🐛 DEBUG get_user_providers_with_keys_safe.php - User ID: $userId, Provider Type: '$providerType'");
  error_log("🐛 DEBUG Input recibido: " . json_encode($in));
  
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
    error_log("🐛 DEBUG - Tipo de proveedor no válido: $providerType");
    json_out(['error' => 'invalid-provider-type', 'message' => 'Tipo de proveedor no válido'], 400);
  }
  
  $providerTable = $tableMap[$providerType]['providers'];
  $userTable = $tableMap[$providerType]['user_keys'];
  
  error_log("🐛 DEBUG - Tablas: $providerTable -> $userTable");
  
  // Verificar que las tablas existen
  $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
  $stmt->execute([$providerTable]);
  $providerTableExists = $stmt->fetch() !== false;
  
  $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
  $stmt->execute([$userTable]);
  $userTableExists = $stmt->fetch() !== false;
  
  error_log("🐛 DEBUG - Tabla $providerTable existe: " . ($providerTableExists ? 'SÍ' : 'NO'));
  error_log("🐛 DEBUG - Tabla $userTable existe: " . ($userTableExists ? 'SÍ' : 'NO'));
  
  if (!$providerTableExists) {
    json_out(['error' => 'table-not-found', 'message' => "Tabla $providerTable no existe"], 500);
  }
  
  if (!$userTableExists) {
    json_out(['error' => 'table-not-found', 'message' => "Tabla $userTable no existe"], 500);
  }
  
  // Verificar estructura de las tablas
  $stmt = $pdo->prepare("DESCRIBE $providerTable");
  $stmt->execute();
  $providerTableStructure = $stmt->fetchAll(PDO::FETCH_ASSOC);
  
  $stmt = $pdo->prepare("DESCRIBE $userTable");
  $stmt->execute();
  $userTableStructure = $stmt->fetchAll(PDO::FETCH_ASSOC);
  
  error_log("🐛 DEBUG - Estructura de $providerTable: " . json_encode(array_column($providerTableStructure, 'Field')));
  error_log("🐛 DEBUG - Estructura de $userTable: " . json_encode(array_column($userTableStructure, 'Field')));
  
  // Verificar si hay registros en las tablas
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM $providerTable");
  $stmt->execute();
  $providerCount = $stmt->fetchColumn();
  
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM $userTable WHERE user_id = ?");
  $stmt->execute([$userId]);
  $userKeyCount = $stmt->fetchColumn();
  
  error_log("🐛 DEBUG - Proveedores en $providerTable: $providerCount");
  error_log("🐛 DEBUG - Claves del usuario en $userTable: $userKeyCount");
  
  // Intentar la consulta principal
  try {
    $stmt = $pdo->prepare("
      SELECT 
        p.id,
        p.slug,
        p.name,
        p.category,
        p.auth_type,
        p.base_url,
        p.docs_url,
        p.rate_limit_per_min,
        p.url_request,
        p.config_json,
        uk.id as user_key_id,
        uk.label as user_key_label,
        uk.last4,
        uk.environment,
        uk.status as key_status,
        uk.created_at as key_created_at,
        uk.last_used_at
      FROM $providerTable p
      INNER JOIN $userTable uk ON p.id = uk.provider_id
      WHERE uk.user_id = ? 
        AND uk.origin = 'byok' 
        AND uk.status = 'active'
        AND p.is_enabled = 1
      ORDER BY p.name ASC
    ");
    
    $stmt->execute([$userId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("🐛 DEBUG - Consulta exitosa, resultados: " . count($results));
    
    // Formatear respuesta
    $providers = [];
    foreach ($results as $row) {
      $providers[] = [
        'id' => (int)$row['id'],
        'slug' => $row['slug'],
        'name' => $row['name'],
        'category' => $row['category'],
        'auth_type' => $row['auth_type'],
        'base_url' => $row['base_url'],
        'docs_url' => $row['docs_url'],
        'url_request' => $row['url_request'],
        'config_json' => $row['config_json'],
        'rate_limit_per_min' => $row['rate_limit_per_min'] ? (int)$row['rate_limit_per_min'] : null,
        
        // Información de la clave del usuario
        'user_key_id' => (int)$row['user_key_id'],
        'user_key_label' => $row['user_key_label'],
        'last4' => $row['last4'],
        'environment' => $row['environment'],
        'key_status' => $row['key_status'],
        'key_created_at' => $row['key_created_at'],
        'last_used_at' => $row['last_used_at'],
        
        // Información para la prueba
        'has_test_config' => !empty($row['config_json']) && 
                            json_decode($row['config_json'], true)['test'] ?? false
      ];
    }
    
    json_out([
      'ok' => true,
      'provider_type' => $providerType,
      'providers' => $providers,
      'count' => count($providers),
      'debug' => [
        'provider_table' => $providerTable,
        'user_table' => $userTable,
        'provider_table_exists' => $providerTableExists,
        'user_table_exists' => $userTableExists,
        'provider_count' => $providerCount,
        'user_key_count' => $userKeyCount,
        'provider_table_structure' => array_column($providerTableStructure, 'Field'),
        'user_table_structure' => array_column($userTableStructure, 'Field')
      ],
      'message' => count($providers) > 0 ? 
        "Se encontraron " . count($providers) . " proveedores con claves configuradas" :
        "No tienes claves configuradas para este tipo de proveedor"
    ]);
    
  } catch (Exception $e) {
    error_log("🐛 DEBUG - Error en consulta principal: " . $e->getMessage());
    json_out([
      'error' => 'query-failed', 
      'message' => 'Error en consulta principal: ' . $e->getMessage(),
      'debug' => [
        'provider_table' => $providerTable,
        'user_table' => $userTable,
        'sql_error' => $e->getMessage()
      ]
    ], 500);
  }
  
} catch (Exception $e) {
  error_log("🐛 DEBUG - Error general: " . $e->getMessage());
  error_log("🐛 DEBUG - Stack trace: " . $e->getTraceAsString());
  json_out(['error' => 'server-error', 'message' => 'Error interno del servidor: ' . $e->getMessage()], 500);
}
