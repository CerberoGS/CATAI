<?php
// /catai/api/setup_data_providers_test_configs_safe.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
  $u = require_user();
  $userId = (int)($u['id'] ?? 0);
  if ($userId <= 0) json_out(['error'=>'invalid-user'], 401);

  $pdo = db();
  
  // Configuraciones de prueba para proveedores de datos financieros
  $testConfigs = [
    'alphavantage' => [
      'test' => [
        'method' => 'GET',
        'url_override' => 'https://www.alphavantage.co/query?function=GLOBAL_QUOTE&symbol=IBM&apikey={{API_KEY}}',
        'expected_status' => 200,
        'ok_json_path' => 'Global Quote',
        'ok_json_expected' => 'object'
      ]
    ],
    'finnhub' => [
      'test' => [
        'method' => 'GET',
        'url_override' => 'https://finnhub.io/api/v1/quote?symbol=AAPL&token={{API_KEY}}',
        'expected_status' => 200,
        'ok_json_path' => 'c',
        'ok_json_expected' => 'number'
      ]
    ],
    'polygon' => [
      'test' => [
        'method' => 'GET',
        'headers' => [
          ['name' => 'Authorization', 'value' => 'Bearer {{API_KEY}}']
        ],
        'url_override' => 'https://api.polygon.io/v3/reference/tickers?limit=1',
        'expected_status' => 200,
        'ok_json_path' => 'results',
        'ok_json_expected' => 'array'
      ]
    ],
    'tiingo' => [
      'test' => [
        'method' => 'GET',
        'headers' => [
          ['name' => 'Authorization', 'value' => 'Token {{API_KEY}}']
        ],
        'url_override' => 'https://api.tiingo.com/tiingo/daily/AAPL/prices?startDate=2024-01-01&endDate=2024-01-02',
        'expected_status' => 200,
        'ok_json_path' => '0.date',
        'ok_json_expected' => 'string'
      ]
    ],
    'iex-cloud' => [
      'test' => [
        'method' => 'GET',
        'url_override' => 'https://cloud.iexapis.com/stable/stock/AAPL/quote?token={{API_KEY}}',
        'expected_status' => 200,
        'ok_json_path' => 'symbol',
        'ok_json_expected' => 'string'
      ]
    ],
    'twelve-data' => [
      'test' => [
        'method' => 'GET',
        'url_override' => 'https://api.twelvedata.com/time_series?symbol=AAPL&interval=1day&outputsize=1&apikey={{API_KEY}}',
        'expected_status' => 200,
        'ok_json_path' => 'values',
        'ok_json_expected' => 'array'
      ]
    ]
  ];
  
  $results = [];
  $updated = 0;
  $errors = [];
  
  foreach ($testConfigs as $slug => $config) {
    try {
      // Buscar el proveedor por slug
      $stmt = $pdo->prepare('SELECT id, slug, label FROM data_providers WHERE slug = ?');
      $stmt->execute([$slug]);
      $provider = $stmt->fetch(PDO::FETCH_ASSOC);
      
      if (!$provider) {
        $errors[] = "Proveedor '$slug' no encontrado en la base de datos";
        continue;
      }
      
      // Obtener config_json actual
      $stmt = $pdo->prepare('SELECT config_json FROM data_providers WHERE id = ?');
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
      $stmt = $pdo->prepare('UPDATE data_providers SET config_json = ? WHERE id = ?');
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
    'message' => "Configuraciones de prueba agregadas para $updated proveedores de datos",
    'results' => $results,
    'updated_count' => $updated,
    'errors' => $errors,
    'total_configs' => count($testConfigs)
  ]);
  
} catch (Exception $e) {
  error_log("Error en setup_data_providers_test_configs_safe.php: " . $e->getMessage());
  json_out(['error' => 'server-error', 'message' => 'Error interno del servidor: ' . $e->getMessage()], 500);
}
