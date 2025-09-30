<?php
// /catai/api/setup_test_configs_safe.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
  $u = require_user();
  $userId = (int)($u['id'] ?? 0);
  if ($userId <= 0) json_out(['error'=>'invalid-user'], 401);

  $pdo = db();
  
  // Configuraciones de prueba para diferentes proveedores
  $testConfigs = [
    // OpenAI
    [
      'table' => 'ai_providers',
      'slug' => 'openai',
      'test_config' => [
        'method' => 'GET',
        'headers' => [
          ['name' => 'Authorization', 'value' => 'Bearer {{API_KEY}}']
        ],
        'expected_status' => 200,
        'ok_json_path' => 'object',
        'ok_json_expected' => 'list'
      ]
    ],
    
    // Polygon.io
    [
      'table' => 'data_providers', 
      'slug' => 'polygon',
      'test_config' => [
        'method' => 'GET',
        'query' => [
          ['name' => 'apikey', 'value' => '{{API_KEY}}']
        ],
        'expected_status' => 200,
        'ok_json_path' => 'status',
        'ok_json_expected' => 'OK'
      ]
    ],
    
    // Alpha Vantage
    [
      'table' => 'data_providers',
      'slug' => 'alpha_vantage', 
      'test_config' => [
        'method' => 'GET',
        'query' => [
          ['name' => 'function', 'value' => 'TIME_SERIES_INTRADAY'],
          ['name' => 'symbol', 'value' => 'IBM'],
          ['name' => 'interval', 'value' => '5min'],
          ['name' => 'apikey', 'value' => '{{API_KEY}}']
        ],
        'expected_status' => 200,
        'ok_json_path' => 'Meta Data.2. Symbol',
        'ok_json_expected' => 'IBM'
      ]
    ],
    
    // News API
    [
      'table' => 'news_providers',
      'slug' => 'newsapi',
      'test_config' => [
        'method' => 'GET',
        'query' => [
          ['name' => 'apiKey', 'value' => '{{API_KEY}}'],
          ['name' => 'q', 'value' => 'technology'],
          ['name' => 'pageSize', 'value' => '1']
        ],
        'expected_status' => 200,
        'ok_json_path' => 'status',
        'ok_json_expected' => 'ok'
      ]
    ],
    
    // IEX Cloud
    [
      'table' => 'data_providers',
      'slug' => 'iex_cloud',
      'test_config' => [
        'method' => 'GET',
        'query' => [
          ['name' => 'token', 'value' => '{{API_KEY}}']
        ],
        'expected_status' => 200,
        'success_regex' => 'AAPL|MSFT|GOOGL'
      ]
    ]
  ];
  
  $results = [];
  
  foreach ($testConfigs as $config) {
    $table = $config['table'];
    $slug = $config['slug'];
    $testConfig = $config['test_config'];
    
    error_log("🔧 Configurando prueba para: $table.$slug");
    
    // Verificar si el proveedor existe
    $stmt = $pdo->prepare("SELECT id, name, config_json FROM $table WHERE slug = ?");
    $stmt->execute([$slug]);
    $provider = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$provider) {
      error_log("⚠️ Proveedor no encontrado: $table.$slug");
      $results[] = [
        'table' => $table,
        'slug' => $slug,
        'status' => 'not_found',
        'message' => 'Proveedor no encontrado'
      ];
      continue;
    }
    
    // Obtener config_json actual
    $currentConfig = json_decode($provider['config_json'] ?? '{}', true);
    
    // Agregar configuración de prueba
    $currentConfig['test'] = $testConfig;
    
    // Actualizar en BD
    $stmt = $pdo->prepare("UPDATE $table SET config_json = ? WHERE id = ?");
    $stmt->execute([json_encode($currentConfig), $provider['id']]);
    
    error_log("✅ Configuración de prueba agregada para: {$provider['name']}");
    
    $results[] = [
      'table' => $table,
      'slug' => $slug,
      'name' => $provider['name'],
      'status' => 'success',
      'message' => 'Configuración de prueba agregada',
      'test_config' => $testConfig
    ];
  }
  
  json_out([
    'ok' => true,
    'message' => 'Configuraciones de prueba configuradas',
    'results' => $results
  ]);
  
} catch (Exception $e) {
  error_log("Error en setup_test_configs_safe.php: " . $e->getMessage());
  json_out(['error' => 'server-error', 'message' => 'Error interno del servidor'], 500);
}
