<?php
// /catai/api/setup_trade_providers_test_configs_safe.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
  $u = require_user();
  $userId = (int)($u['id'] ?? 0);
  if ($userId <= 0) json_out(['error'=>'invalid-user'], 401);

  $pdo = db();
  
  // Configuraciones de prueba para proveedores de trading
  $testConfigs = [
    'coinbase-pro' => [
      'test' => [
        'method' => 'GET',
        'headers' => [
          ['name' => 'CB-ACCESS-KEY', 'value' => '{{API_KEY}}'],
          ['name' => 'CB-ACCESS-TIMESTAMP', 'value' => '{{TIMESTAMP}}'],
          ['name' => 'CB-ACCESS-PASSPHRASE', 'value' => '{{PASSPHRASE}}']
        ],
        'url_override' => 'https://api.pro.coinbase.com/accounts',
        'expected_status' => [200, 401, 403], // 401/403 también válidos para keys mal configuradas
        'success_regex' => 'id|error|unauthorized'
      ]
    ],
    'bitmex' => [
      'test' => [
        'method' => 'GET',
        'url_override' => 'https://www.bitmex.com/api/v1/user',
        'expected_status' => [200, 401, 403],
        'success_regex' => 'id|error|unauthorized'
      ]
    ],
    'binance' => [
      'test' => [
        'method' => 'GET',
        'url_override' => 'https://api.binance.com/api/v3/account',
        'expected_status' => [200, 401, 403],
        'success_regex' => 'balances|error|invalid'
      ]
    ],
    'kraken' => [
      'test' => [
        'method' => 'POST',
        'url_override' => 'https://api.kraken.com/0/private/Balance',
        'expected_status' => [200, 401, 403],
        'success_regex' => 'result|error'
      ]
    ],
    'alpaca' => [
      'test' => [
        'method' => 'GET',
        'headers' => [
          ['name' => 'APCA-API-KEY-ID', 'value' => '{{API_KEY}}'],
          ['name' => 'APCA-API-SECRET-KEY', 'value' => '{{SECRET_KEY}}']
        ],
        'url_override' => 'https://paper-api.alpaca.markets/v2/account',
        'expected_status' => [200, 401, 403],
        'success_regex' => 'id|error|unauthorized'
      ]
    ],
    'interactive-brokers' => [
      'test' => [
        'method' => 'GET',
        'url_override' => 'https://api.ibkr.com/v1/portal/portfolio/accounts',
        'expected_status' => [200, 401, 403],
        'success_regex' => 'accounts|error|unauthorized'
      ]
    ]
  ];
  
  $results = [];
  $updated = 0;
  $errors = [];
  
  foreach ($testConfigs as $slug => $config) {
    try {
      // Buscar el proveedor por slug
      $stmt = $pdo->prepare('SELECT id, slug, label FROM trade_providers WHERE slug = ?');
      $stmt->execute([$slug]);
      $provider = $stmt->fetch(PDO::FETCH_ASSOC);
      
      if (!$provider) {
        $errors[] = "Proveedor de trading '$slug' no encontrado en la base de datos";
        continue;
      }
      
      // Obtener config_json actual
      $stmt = $pdo->prepare('SELECT config_json FROM trade_providers WHERE id = ?');
      $stmt->execute([$provider['id']]);
      $currentConfig = $stmt->fetchColumn();
      
      $currentConfigArray = [];
      if ($currentConfig) {
        $decoded = json_decode($currentConfig, true);
        if (is_array($decoded)) {
          $currentConfigArray = $decoded;
        }
      }
      
      // Merge con la nueva configuración de prueba
      $currentConfigArray = array_merge($currentConfigArray, $config);
      $newConfigJson = json_encode($currentConfigArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      
      // Actualizar en la base de datos
      $stmt = $pdo->prepare('UPDATE trade_providers SET config_json = ? WHERE id = ?');
      $stmt->execute([$newConfigJson, $provider['id']]);
      
      $results[] = [
        'provider_id' => (int)$provider['id'],
        'provider_slug' => $provider['slug'],
        'provider_name' => $provider['label'],
        'test_config' => $config['test'],
        'updated' => true
      ];
      $updated++;
      
      error_log("✅ Configuración de prueba agregada para {$provider['label']} ({$provider['slug']})");
      
    } catch (Exception $e) {
      $errors[] = "Error configurando '$slug': " . $e->getMessage();
      error_log("❌ Error configurando prueba para '$slug': " . $e->getMessage());
    }
  }
  
  json_out([
    'ok' => true,
    'message' => "Configuraciones de prueba agregadas para $updated proveedores de trading",
    'results' => $results,
    'updated_count' => $updated,
    'errors' => $errors,
    'total_configs' => count($testConfigs)
  ]);
  
} catch (Exception $e) {
  error_log("Error en setup_trade_providers_test_configs_safe.php: " . $e->getMessage());
  json_out(['error' => 'server-error', 'message' => 'Error interno del servidor: ' . $e->getMessage()], 500);
}
